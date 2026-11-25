<?php

namespace ofc\tests;

use ofc\CompanionFields;
use ofc\OptionPages;
use ReflectionClass;
use ReflectionMethod;

class OptionPagesTest extends RadTestCase
{
    /** @var string[] yml files created by a test; removed on teardown */
    private $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function optionsFile(string $name, string $contents): string
    {
        $dir = get_template_directory() . '/options';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '/' . $name . '.yml';
        file_put_contents($path, $contents);
        $this->created[] = $path;
        return $path;
    }

    /** @test */
    public function optionsPageReadsParentAndMenuLabelFromYml()
    {
        $this->optionsFile('rad-opt-menu', "parent: edit.php?post_type=paper\nadmin_link_text: Papers Options\nfields:\n  header: text\n");

        $groups = OptionPages::discover(['rad-opt-menu']);
        $meta = $groups['rad-opt-menu'];

        $this->assertSame('edit.php?post_type=paper', $meta['parent']);
        $this->assertSame('Papers Options', $meta['menu_title']);
        // With only `admin_link_text:` set the page H1 mirrors the sidebar
        // label (the common case), rather than falling through to the
        // humanised filename.
        $this->assertSame('Papers Options', $meta['title']);
    }

    /** @test */
    public function titleAndAdminLinkTextDivergeOnlyWhenBothAreSet()
    {
        $this->optionsFile('rad-opt-split', "title: Full Config Page\nadmin_link_text: Short\nfields:\n  header: text\n");

        $meta = OptionPages::discover(['rad-opt-split'])['rad-opt-split'];
        $this->assertSame('Full Config Page', $meta['title']);
        $this->assertSame('Short', $meta['menu_title']);
    }

    /** @test */
    public function titleAloneDrivesBothH1AndSidebarLabel()
    {
        $this->optionsFile('rad-opt-titleonly', "title: Just A Title\nfields:\n  header: text\n");

        $meta = OptionPages::discover(['rad-opt-titleonly'])['rad-opt-titleonly'];
        $this->assertSame('Just A Title', $meta['title']);
        $this->assertSame('Just A Title', $meta['menu_title']);
    }

    /** @test */
    public function neitherTitleNorAdminLinkTextFallsBackToHumanisedFilename()
    {
        $this->optionsFile('rad-opt-neither', "fields:\n  header: text\n");

        $meta = OptionPages::discover(['rad-opt-neither'])['rad-opt-neither'];
        $this->assertSame('Rad Opt Neither', $meta['title']);
        $this->assertSame('Rad Opt Neither', $meta['menu_title']);
    }

    /** @test */
    public function cptParentAutoDefaultsMenuPositionJustAfterAddNew()
    {
        $this->optionsFile('rad-opt-cpt', "parent: edit.php?post_type=paper\nfields:\n  header: text\n");

        $groups = OptionPages::discover(['rad-opt-cpt']);
        // "Add New" is submenu slot 10 in wp-admin/menu.php; 11 slots us in right
        // after it (before taxonomies which start at 15).
        $this->assertSame(11, $groups['rad-opt-cpt']['menu_position']);
    }

    /** @test */
    public function explicitMenuPositionOverridesTheCptDefault()
    {
        $this->optionsFile('rad-opt-pos', "parent: edit.php?post_type=paper\nmenu_position: 13\nfields:\n  header: text\n");

        $groups = OptionPages::discover(['rad-opt-pos']);
        $this->assertSame(13, $groups['rad-opt-pos']['menu_position']);
    }

    /** @test */
    public function nonCptParentsLeaveMenuPositionUnsetSoWpOrdersNaturally()
    {
        $this->optionsFile('rad-opt-tools', "parent: tools.php\nfields:\n  header: text\n");

        $groups = OptionPages::discover(['rad-opt-tools']);
        $this->assertNull($groups['rad-opt-tools']['menu_position']);
    }

    /** @test */
    public function pageWithoutParentStaysToplevelWithNullParentKey()
    {
        $this->optionsFile('rad-opt-toplevel', "fields:\n  header: text\n");

        $groups = OptionPages::discover(['rad-opt-toplevel']);
        $this->assertNull($groups['rad-opt-toplevel']['parent']);
        // Menu label falls back to the page title.
        $this->assertSame($groups['rad-opt-toplevel']['title'], $groups['rad-opt-toplevel']['menu_title']);
    }

    /** @test */
    public function hookSuffixMatchesWpConventionForToplevelAndSubmenu()
    {
        $method = new ReflectionMethod(OptionPages::class, 'hookFor');
        $method->setAccessible(true);

        $this->assertSame(
            'toplevel_page_rad-options-site-settings',
            $method->invoke(null, 'site-settings', null)
        );

        $this->assertSame(
            'edit_page_rad-options-papers-cpt',
            $method->invoke(null, 'papers-cpt', 'edit.php?post_type=paper')
        );
    }

    /** @test */
    public function positionSubmenuEntryRekeysAndSortsIntoTheRightSlot()
    {
        global $submenu;
        $backup = isset($submenu['edit.php?post_type=paper']) ? $submenu['edit.php?post_type=paper'] : null;

        // Mirror what wp-admin/menu.php installs for a CPT (5=All, 10=Add New,
        // 15+=taxonomies) plus what add_submenu_page appends for us at the end.
        $submenu['edit.php?post_type=paper'] = [
            5  => ['All Papers',      'edit_posts',    'edit.php?post_type=paper'],
            10 => ['Add New',         'create_posts',  'post-new.php?post_type=paper'],
            15 => ['Paper Categories','manage_terms',  'edit-tags.php?taxonomy=paper_category&post_type=paper'],
            20 => ['Papers Options',  'manage_options','rad-options-papers-cpt'],
        ];

        $method = new ReflectionMethod(OptionPages::class, 'positionSubmenuEntry');
        $method->setAccessible(true);
        $method->invoke(null, 'edit.php?post_type=paper', 'rad-options-papers-cpt', 11);

        $keys = array_keys($submenu['edit.php?post_type=paper']);
        $this->assertSame([5, 10, 11, 15], $keys, 'Entries should be sorted with ours at 11');
        $this->assertSame('rad-options-papers-cpt', $submenu['edit.php?post_type=paper'][11][2]);
        // Our entry was pulled out of the auto-appended slot (no duplicate left behind).
        $slugs = array_column($submenu['edit.php?post_type=paper'], 2);
        $this->assertSame(1, array_count_values($slugs)['rad-options-papers-cpt']);

        if ($backup === null) {
            unset($submenu['edit.php?post_type=paper']);
        } else {
            $submenu['edit.php?post_type=paper'] = $backup;
        }
    }

    // ------------------------------------------------------------------
    // Dotted sub-field access on radOption / getOption
    // ------------------------------------------------------------------

    /** @test */
    public function getOptionDescendsIntoStructuredFieldValue()
    {
        $this->optionsFile('rad-opt-sub', "fields:\n  guide:\n    type: media\n    output: object\n");
        OptionPages::boot(['rad-opt-sub']);

        $attachment = self::factory()->attachment->create(['post_title' => 'Guide PDF']);
        update_option(OptionPages::OPTION_PREFIX . 'rad-opt-sub_guide', serialize(['id' => $attachment]));

        $value = \ofc\Site::getInstance(['handlebars' => false])
            ->getOption('rad-opt-sub', 'guide.url');

        // A `media` + `output: object` field resolves to the prepared attachment
        // array; the dotted path descends into it and `.url` yields the
        // attachment's file URL (a url never carries the title). Proving the
        // descent reached the right sub-key is the point of the test.
        $this->assertIsString($value);
        $this->assertSame((string) wp_get_attachment_url($attachment), $value);
    }

    /** @test */
    public function resolveRadOptionTokenSupportsFieldDotSubkey()
    {
        $this->optionsFile('rad-opt-token', "fields:\n  guide:\n    type: media\n    output: object\n");
        OptionPages::boot(['rad-opt-token']);

        $attachment = self::factory()->attachment->create(['post_title' => 'Token PDF']);
        update_option(OptionPages::OPTION_PREFIX . 'rad-opt-token_guide', serialize(['id' => $attachment]));

        $site = \ofc\Site::getInstance(['handlebars' => false]);

        $resolved = $site->resolveRadOptionToken('guide.url');
        $this->assertIsString($resolved);
        // Dotted descent into the media object's `url` key returns the stored
        // attachment's file URL (a url never carries the title).
        $this->assertSame((string) wp_get_attachment_url($attachment), $resolved);
    }

    /** @test */
    public function resolveRadOptionTokenSupportsPageDotFieldDotSubkey()
    {
        $this->optionsFile('rad-opt-pageSub', "fields:\n  guide:\n    type: media\n    output: object\n");
        OptionPages::boot(['rad-opt-pageSub']);

        $attachment = self::factory()->attachment->create(['post_title' => 'PageSub PDF']);
        update_option(OptionPages::OPTION_PREFIX . 'rad-opt-pageSub_guide', serialize(['id' => $attachment]));

        $resolved = \ofc\Site::getInstance(['handlebars' => false])
            ->resolveRadOptionToken('rad-opt-pageSub.guide.url');

        $this->assertIsString($resolved);
        // Same as above, reached via the `page.field.subkey` form.
        $this->assertSame((string) wp_get_attachment_url($attachment), $resolved);
    }

    /** @test */
    public function missingSubkeyReturnsEmptyString()
    {
        $this->optionsFile('rad-opt-missingSub', "fields:\n  guide:\n    type: media\n    output: object\n");
        OptionPages::boot(['rad-opt-missingSub']);

        $attachment = self::factory()->attachment->create(['post_title' => 'Sub PDF']);
        update_option(OptionPages::OPTION_PREFIX . 'rad-opt-missingSub_guide', serialize(['id' => $attachment]));

        $value = \ofc\Site::getInstance(['handlebars' => false])
            ->getOption('rad-opt-missingSub', 'guide.not_a_real_key');

        $this->assertSame('', $value);
    }

    /** @test */
    public function undottedFieldNamesStillResolveAsBefore()
    {
        // Regression guard — the plain `field` form must still work after
        // adding dot support (explode('.', 'field') has count 1, no descend).
        $this->optionsFile('rad-opt_plain', "fields:\n  tagline: text\n");
        OptionPages::boot(['rad-opt_plain']);
        update_option(OptionPages::OPTION_PREFIX . 'rad-opt_plain_tagline', 'Hello');

        $value = \ofc\Site::getInstance(['handlebars' => false])
            ->getOption('rad-opt_plain', 'tagline');

        $this->assertSame('Hello', $value);
    }

    // ------------------------------------------------------------------
    // Postbox grouping (`section:`)
    // ------------------------------------------------------------------

    /** @test */
    public function fieldsAreFiledIntoAPostboxPerSection()
    {
        $fields = [
            CompanionFields::normalizeField('a', ['type' => 'text', 'section' => 'Landing Page']),
            CompanionFields::normalizeField('b', ['type' => 'text', 'section' => 'Contact Slice']),
            // A section named again later folds back into the box it opened.
            CompanionFields::normalizeField('c', ['type' => 'text', 'section' => 'Landing Page']),
            CompanionFields::normalizeField('d', 'text'),
        ];

        $boxes = OptionPages::boxes($fields);

        // Boxes follow the order their first field appears, and the unsectioned
        // leftovers only borrow a title because they sit beside named boxes.
        $this->assertSame(['Landing Page', 'Contact Slice', 'General'], array_column($boxes, 'title'));
        $this->assertSame(['a', 'c'], array_column($boxes[0]['fields'], 'name'));
        $this->assertSame(['b'], array_column($boxes[1]['fields'], 'name'));
        $this->assertSame(['d'], array_column($boxes[2]['fields'], 'name'));
    }

    /** @test */
    public function aPageWithoutSectionsRendersOneUntitledCard()
    {
        $boxes = OptionPages::boxes([
            CompanionFields::normalizeField('a', 'text'),
            CompanionFields::normalizeField('b', 'text'),
        ]);

        // No headers, so the box is a plain card rather than a panel.
        $this->assertCount(1, $boxes);
        $this->assertNull($boxes[0]['title']);
        $this->assertSame(['a', 'b'], array_column($boxes[0]['fields'], 'name'));
    }

    /** @test */
    public function aBlankSectionDoesNotSplitItsBox()
    {
        $boxes = OptionPages::boxes([
            CompanionFields::normalizeField('a', ['type' => 'text', 'section' => ' Shared ']),
            CompanionFields::normalizeField('b', ['type' => 'text', 'section' => '']),
            CompanionFields::normalizeField('c', 'text'),
        ]);

        $this->assertSame(['Shared', 'General'], array_column($boxes, 'title'));
        $this->assertSame(['a'], array_column($boxes[0]['fields'], 'name'));
        $this->assertSame(['b', 'c'], array_column($boxes[1]['fields'], 'name'));
    }

    // ------------------------------------------------------------------
    // Screen rendering (post editor chrome)
    // ------------------------------------------------------------------

    /** @test */
    public function renderPageUsesThePostEditorPostboxShell()
    {
        $this->optionsFile('rad-opt-shell', implode("\n", [
            'fields:',
            '  heading:',
            '    type: text',
            '    label: "Heading"',
            '    section: "Header"',
            '  intro:',
            '    type: textarea',
            '    section: "Header"',
            '  phone: text',
        ]) . "\n");
        OptionPages::boot(['rad-opt-shell']);

        ob_start();
        $html = OptionPages::renderPage('rad-opt-shell');
        $echoed = ob_get_clean();

        // Everything comes back as a string (wp_nonce_field in particular
        // prints as well as returns unless echo is switched off).
        $this->assertSame('', trim($echoed));

        $this->assertStringContainsString('class="wrap rad-options-page" data-rad-options-page="rad-opt-shell"', $html);
        $this->assertStringContainsString('<h1 class="wp-heading-inline">Rad Opt Shell</h1>', $html);
        $this->assertStringContainsString('<hr class="wp-header-end" />', $html);
        $this->assertStringContainsString('id="post-body" class="metabox-holder columns-2"', $html);
        $this->assertStringContainsString('id="post-body-content"', $html);
        $this->assertStringContainsString('id="postbox-container-1" class="postbox-container"', $html);

        // Named boxes (including the "General" fallback) get the collapsible
        // chrome; the Save box's header is static, so only the two field boxes
        // are toggleable.
        $this->assertStringContainsString('<div class="postbox-header" data-rad-toggleable>', $html);
        $this->assertStringContainsString('<h2 class="hndle"><span>Header</span></h2>', $html);
        $this->assertStringContainsString('<h2 class="hndle"><span>General</span></h2>', $html);
        $this->assertStringContainsString('class="handlediv" aria-expanded="true"', $html);
        $this->assertSame(2, substr_count($html, 'data-rad-toggleable') - 1, 'one per field box (the script holds the third mention)');
        $this->assertSame(3, substr_count($html, 'class="postbox rad-options-box"') + substr_count($html, 'class="postbox rad-options-submit"'));
        $this->assertStringContainsString('name="rad_phone"', $html);
        $this->assertStringContainsString('name="rad_heading"', $html);

        // Page chrome ships inline the same way the field assets do.
        $this->assertStringContainsString('.rad-options-page .postbox', $html);
        $this->assertStringContainsString('rad-options-closed-boxes', $html);
    }

    /** @test */
    public function saveStampsThePageAndConfirmsItToTheAdmin()
    {
        $this->optionsFile('rad-opt-saved', "fields:\n  tagline: text\n");
        OptionPages::boot(['rad-opt-saved']);
        wp_set_current_user(1);

        $_POST = [
            'rad_options_page' => 'rad-opt-saved',
            'rad_options_nonce' => wp_create_nonce('rad_options_rad-opt-saved'),
            'rad_tagline' => 'Hello',
        ];

        OptionPages::save(OptionPages::discover(['rad-opt-saved']));

        unset($_POST);

        $this->assertSame('Hello', get_option(OptionPages::OPTION_PREFIX . 'rad-opt-saved_tagline'));
        $this->assertGreaterThan(0, (int) get_option(OptionPages::UPDATED_PREFIX . 'rad-opt-saved'));

        ob_start();
        do_action('admin_notices');
        $notices = ob_get_clean();

        $this->assertStringContainsString('Settings saved.', $notices);
        $this->assertStringContainsString('notice-success', $notices);
    }
}
