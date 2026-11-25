<?php

namespace ofc\tests;

use ofc\CompanionAssets;
use ofc\CompanionFields;
use ofc\FieldTemplates;
use ReflectionClass;
use ReflectionMethod;

class CompanionFieldsTest extends RadTestCase
{
    /**
     * @var string[] theme files created by a test; removed on teardown
     */
    private $themeFiles = [];

    /**
     * @var string[] theme directories created by a test; removed when empty
     */
    private $themeDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStaticState();
    }

    protected function tearDown(): void
    {
        foreach ($this->themeFiles as $file) {
            @unlink($file);
        }
        $this->themeFiles = [];
        foreach (array_reverse($this->themeDirs) as $dir) {
            @rmdir($dir);
        }
        $this->themeDirs = [];
        $this->resetStaticState();
        parent::tearDown();
    }

    private function resetStaticState(): void
    {
        $reflection = new ReflectionClass(CompanionFields::class);

        $defaults = [
            'importErrors' => [],
            'adminStylesPrinted' => false,
            'renderedEditors' => [],
            'previewConfigPrinted' => false,
            'seededGroups' => [],
            'previewStylesPrinted' => false,
            'rowSeq' => 0,
            'blockSeq' => 0,
        ];

        foreach ($defaults as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    /**
     * Write a file inside the active theme (cleaned up on teardown).
     */
    private function themeFile(string $relative, string $contents): string
    {
        $path = get_template_directory() . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            // Record the dirs that do not exist yet (shallowest first) so
            // teardown can remove them deepest-first.
            $missing = [];
            $probe = $dir;
            while (!is_dir($probe) && $probe !== get_template_directory()) {
                array_unshift($missing, $probe);
                $probe = dirname($probe);
            }
            $this->themeDirs = array_merge($this->themeDirs, $missing);
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
        $this->themeFiles[] = $path;

        return $path;
    }

    /** @test */
    public function fieldLevelImportsAreRejected()
    {
        $field = CompanionFields::normalizeField('content', [
            'type' => 'flexible',
            'imports' => 'slices/base.yml',
        ]);

        $this->assertNotEmpty($field['import_errors']);
        $this->assertStringContainsString('only allowed at the top of the yml file', $field['import_errors'][0]);
    }

    /** @test */
    public function topLevelImportsFeedEveryFlexibleFieldAndSameNamesOverride()
    {
        $this->themeFile('rad-test-slices.yml', "layouts:\n  hero:\n    label: Hero\n    fields:\n      heading: text\n  cta:\n    label: CTA\n");
        $group = $this->themeFile('rad-test-group.yml', "imports: rad-test-slices.yml\nfields:\n  content: flexible\n  extra:\n    type: flexible\n    layouts:\n      cta:\n        label: Local CTA\n");

        $method = new ReflectionMethod(CompanionFields::class, 'groupFromYml');
        $method->setAccessible(true);
        $group = $method->invoke(null, $group, 'rad-test-group', 'Test', null);

        $this->assertNotNull($group);
        [$content, $extra] = $group['fields'];

        // A bare `content: flexible` needs no layouts/description below it.
        $this->assertSame(['hero', 'cta'], array_keys($content['layouts']));
        $this->assertSame('Hero', $content['layouts']['hero']['label']);

        // The local layout replaces the imported one in place.
        $this->assertSame(['hero', 'cta'], array_keys($extra['layouts']));
        $this->assertSame('Local CTA', $extra['layouts']['cta']['label']);
    }

    /** @test */
    public function brokenTopLevelImportsSurfaceInlineOnTheField()
    {
        $group = $this->themeFile('rad-test-group.yml', "imports: rad-test-nope.yml\nfields:\n  content: flexible\n");

        $method = new ReflectionMethod(CompanionFields::class, 'groupFromYml');
        $method->setAccessible(true);
        $group = $method->invoke(null, $group, 'rad-test-group', 'Test', null);

        $this->assertStringContainsString('rad-test-nope.yml', $group['fields'][0]['import_errors'][0]);
    }

    /** @test */
    public function bareThumbnailDefaultsToTheAssetsFolder()
    {
        $this->themeFile('assets/rad-thumb-test.png', 'x');

        $field = CompanionFields::normalizeField('content', ['type' => 'flexible'], [
            ['name' => 'hero', 'label' => 'Hero', 'thumbnail' => 'rad-thumb-test'],
        ]);

        $this->assertSame(
            get_template_directory_uri() . '/assets/rad-thumb-test.png',
            $field['layouts']['hero']['thumbnail']
        );
    }

    /** @test */
    public function thumbnailsWithASlashResolveRelativeToTheThemeRoot()
    {
        $this->themeFile('assets/thumbnails/rad-thumb-test2.png', 'x');

        $field = CompanionFields::normalizeField('content', ['type' => 'flexible'], [
            ['name' => 'hero', 'label' => 'Hero', 'thumbnail' => 'assets/thumbnails/rad-thumb-test2'],
            ['name' => 'cta', 'label' => 'CTA', 'thumbnail' => 'rad-thumb-test2'],
        ]);

        // Explicit folder path resolves as written …
        $this->assertSame(
            get_template_directory_uri() . '/assets/thumbnails/rad-thumb-test2.png',
            $field['layouts']['hero']['thumbnail']
        );
        // … while the bare name only looks inside assets/ (no thumbnails/).
        $this->assertArrayNotHasKey('thumbnail', $field['layouts']['cta']);
    }

    /** @test */
    public function repeaterAddButtonTextIsExplicitWithAnAddRowFallback()
    {
        $field = CompanionFields::normalizeField('features', [
            'type' => 'repeater',
            'label' => 'Features',
            'add button text' => 'Add Feature',
            'fields' => ['title' => 'text'],
        ]);
        $this->assertSame('Add Feature', $field['add button text']);

        $html = CompanionFields::renderField($field, serialize([['title' => 'One']]));
        $this->assertStringContainsString('+ Add Feature</a>', $html);
        $this->assertStringContainsString('data-item-label="Features"', $html);
        $this->assertStringNotContainsString('+ Add Features</a>', $html);
        $this->assertStringNotContainsString('+ Add Row</a>', $html);

        // No explicit key → the plain fallback (no singularisation magic).
        $plain = CompanionFields::normalizeField('team', [
            'type' => 'repeater',
            'label' => 'Team Members',
            'fields' => ['title' => 'text'],
        ]);
        $html = CompanionFields::renderField($plain, serialize([['title' => 'Ada']]));
        $this->assertStringContainsString('+ Add Row</a>', $html);
    }

    /** @test */
    public function renderedFieldsEscapeLabelsValuesAndHints()
    {
        $field = CompanionFields::normalizeField('headline', [
            'type' => 'text',
            'label' => 'My <Head>',
            'hint' => 'Say "hi"',
        ]);

        $html = CompanionFields::renderField($field, '<script>alert(1)</script>');

        $this->assertStringContainsString('My &lt;Head&gt;', $html);
        $this->assertStringContainsString('Say &quot;hi&quot;', $html);
        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    /** @test */
    public function adminStylesArePrintedOncePerRequest()
    {
        $field = CompanionFields::normalizeField('a', 'text');

        $first = CompanionFields::renderField($field, '1');
        $second = CompanionFields::renderField($field, '2');

        $this->assertStringContainsString('<style>', $first);
        $this->assertStringNotContainsString('<style>', $second);
    }

    /** @test */
    public function flexibleFieldsRenderBlocksAndTheSlicePicker()
    {
        $this->themeFile('assets/rad-thumb-test.png', 'x');

        $field = CompanionFields::normalizeField('content', 'flexible', [
            ['name' => 'hero', 'label' => 'Hero', 'description' => 'Top banner', 'thumbnail' => 'rad-thumb-test', 'fields' => ['heading' => ['type' => 'text']]],
        ]);

        $html = CompanionFields::renderField($field, serialize([['_layout' => 'hero', 'heading' => 'Hello']]));

        $this->assertStringContainsString('id="rad-flexible-content"', $html);
        $this->assertStringContainsString('data-layout="hero"', $html);
        $this->assertStringContainsString('name="rad_block-0[_layout]" value="hero"', $html);
        $this->assertStringContainsString('rad-flexible-template-content-hero', $html);
        $this->assertStringContainsString('rad-flexible-card-desc">Top banner</span>', $html);
        $this->assertStringContainsString('src="' . get_template_directory_uri() . '/assets/rad-thumb-test.png"', $html);
        // The flexible + rekey scripts ride along with the field.
        $this->assertStringContainsString('__radFlexibleBound', $html);
    }

    /** @test */
    public function collapseStatePersistenceShipsWithTheFlexibleScript()
    {
        $script = CompanionAssets::file('flexible.js');

        $this->assertStringContainsString('persistState', $script);
        $this->assertStringContainsString('applyState', $script);
        $this->assertStringContainsString('localStorage', $script);
        $this->assertStringContainsString('rad-flex-collapse:', $script);
    }

    /** @test */
    public function assetsAreServedFromRealFiles()
    {
        $this->assertStringContainsString('.rad-flexible-block', CompanionAssets::file('companion-fields.css'));
        $this->assertStringContainsString('.rad-flexible-modal', CompanionAssets::file('flexible-picker.css'));
        $this->assertStringStartsWith('<script>', CompanionAssets::script('rekey.js'));
        $this->assertStringEndsWith('</script>', CompanionAssets::script('rekey.js'));
        $this->assertSame('', CompanionAssets::file('definitely-missing.js'));
    }

    /** @test */
    public function templatesEscapeDataByDefault()
    {
        $html = FieldTemplates::render('input', [
            'type' => 'text',
            'id' => 'rad_x',
            'name' => 'rad_x',
            'required' => true,
            'value' => '"q"<tag>',
            'hasPlaceholder' => false,
            'placeholder' => '',
        ]);

        $this->assertStringContainsString('<input type="text" id="rad_x" name="rad_x" required value="&quot;q&quot;&lt;tag&gt;" />', $html);
    }

    /** @test */
    public function unimplementedTypesRenderAPlaceholder()
    {
        $field = CompanionFields::normalizeField('attachment', 'file');
        $html = CompanionFields::renderField($field);

        $this->assertStringContainsString('file fields are not implemented yet.', $html);
    }

    /** @test */
    public function unknownTypesRenderAPlaceholderInsteadOfATextInput()
    {
        $field = CompanionFields::normalizeField('swatch', ['type' => 'color', 'label' => 'Swatch']);
        $html = CompanionFields::renderField($field);

        $this->assertStringContainsString('color fields are not implemented yet.', $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    /** @test */
    public function sidebarFieldsAreParsedIntoTheirOwnListAlongsideRegularFields()
    {
        $path = $this->themeFile(
            'rad-test-sidebar-group.yml',
            "fields:\n  content: text\nsidebar_fields:\n  featured_document: media\n  menu_paragraph: textarea\n"
        );

        $method = new ReflectionMethod(CompanionFields::class, 'groupFromYml');
        $method->setAccessible(true);
        $group = $method->invoke(null, $path, 'rad-test-sidebar-group', 'Test', null);

        $this->assertNotNull($group);
        $this->assertSame(['content'], array_column($group['fields'], 'name'));
        $this->assertSame(
            ['featured_document', 'menu_paragraph'],
            array_column($group['sidebar_fields'], 'name')
        );
        $this->assertSame('media', $group['sidebar_fields'][0]['type']);
        $this->assertSame('textarea', $group['sidebar_fields'][1]['type']);
        // Labels are derived from the field name the same way as regular fields.
        $this->assertSame('Featured Document', $group['sidebar_fields'][0]['label']);
    }

    /** @test */
    public function sidebarOnlyYmlStillProducesAGroupWithNoMainFields()
    {
        $path = $this->themeFile(
            'rad-test-sidebar-only.yml',
            "sidebar_fields:\n  featured_document: media\n"
        );

        $method = new ReflectionMethod(CompanionFields::class, 'groupFromYml');
        $method->setAccessible(true);
        $group = $method->invoke(null, $path, 'rad-test-sidebar-only', 'Test', null);

        $this->assertNotNull($group);
        $this->assertSame([], $group['fields']);
        $this->assertSame(['featured_document'], array_column($group['sidebar_fields'], 'name'));
    }

    /** @test */
    public function ymlWithNeitherFieldsNorSidebarFieldsProducesNoGroup()
    {
        $path = $this->themeFile('rad-test-no-fields.yml', "hidden: content\n");

        $method = new ReflectionMethod(CompanionFields::class, 'groupFromYml');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, $path, 'rad-test-no-fields', 'Test', null));
    }

    /** @test */
    public function sidebarFieldsRenderThroughTheSameFieldRendererAsRegularFields()
    {
        $sidebar = [
            CompanionFields::normalizeField('menu_paragraph', 'textarea'),
        ];

        $html = CompanionFields::renderBox('rad_companion_tpl-flexible_sidebar', 'tpl-flexible.php', $sidebar, [
            'menu_paragraph' => 'Hello sidebar',
        ]);

        // Same box chrome + same field markup as the main-column box.
        $this->assertStringContainsString('rad-companion-fields', $html);
        $this->assertStringContainsString('name="rad_menu_paragraph"', $html);
        $this->assertStringContainsString('Hello sidebar', $html);
        $this->assertStringContainsString('rad_companion_nonce', $html);
    }

    /** @test */
    public function mediaFiletypesDefaultsToImageAndAcceptsNamedCategories()
    {
        // Bare `media` (no filetypes:) stays on the existing image picker.
        $bare = CompanionFields::normalizeField('hero', 'media');
        $this->assertSame('image', $bare['filetypes'] ?? 'image');

        foreach (['image', 'videos', 'documents', 'all'] as $kind) {
            $field = CompanionFields::normalizeField('doc', ['type' => 'media', 'filetypes' => $kind]);
            $this->assertSame($kind, $field['filetypes']);
        }

        // Unknown values fall back to image so a typo never opens the whole library.
        $typo = CompanionFields::normalizeField('doc', ['type' => 'media', 'filetypes' => 'pdfs']);
        $this->assertSame('image', $typo['filetypes']);
    }

    /** @test */
    public function mediaFieldHtmlCarriesFiletypesAndAttachmentTypeAttributes()
    {
        $field = CompanionFields::normalizeField('featured_document', [
            'type' => 'media',
            'filetypes' => 'documents',
        ]);

        $html = CompanionFields::renderField($field, serialize(['id' => 0, 'url' => '', 'alt' => '']));

        $this->assertStringContainsString('data-filetypes="documents"', $html);
        // No stored attachment -> unknown type (server doesn't render a tile/img).
        $this->assertStringContainsString('data-attachment-type="unknown"', $html);
        $this->assertStringContainsString('No media selected.', $html);
    }

    /**
     * renderField() inlines the companion stylesheet + media.js, which embed the
     * class names of EVERY preview variant as string literals. Strip those asset
     * blocks so a media preview test can assert on the server-rendered markup
     * (and its absence of the other variants) without the inlined JS/CSS
     * defeating the assertStringNotContainsString checks.
     */
    private function fieldMarkupWithoutAssets(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
        return $html;
    }

    /** @test */
    public function storedNonImageMediaRendersFilenameTileInsteadOfBrokenImg()
    {
        $field = CompanionFields::normalizeField('featured_document', [
            'type' => 'media',
            'filetypes' => 'documents',
            'output' => 'url',
        ]);

        $stored = serialize([
            'id' => 0,
            'url' => 'https://example.com/wp-content/uploads/2026/11/menu.pdf',
            'alt' => '',
        ]);

        $html = $this->fieldMarkupWithoutAssets(CompanionFields::renderField($field, $stored));

        // The wrapper is tagged as an application/* attachment …
        $this->assertStringContainsString('data-attachment-type="application"', $html);
        // … and with no generated thumbnail the PDF previews in the browser's
        // own viewer, captioned with its filename — never a broken <img>.
        $this->assertStringContainsString('<figure class="rad-companion-media-doc">', $html);
        $this->assertStringContainsString('<iframe src="https://example.com/wp-content/uploads/2026/11/menu.pdf#toolbar=0', $html);
        $this->assertStringContainsString('menu.pdf</figcaption>', $html);
        $this->assertStringNotContainsString('<img class="rad-companion-media-img"', $html);
    }

    /** @test */
    public function storedNonPdfDocumentRendersFilenameTile()
    {
        $field = CompanionFields::normalizeField('handbook', [
            'type' => 'media',
            'filetypes' => 'documents',
        ]);

        $stored = serialize([
            'id' => 0,
            'url' => 'https://example.com/wp-content/uploads/2026/11/handbook.docx',
            'alt' => '',
        ]);

        $html = $this->fieldMarkupWithoutAssets(CompanionFields::renderField($field, $stored));

        $this->assertStringContainsString('<div class="rad-companion-media-file">', $html);
        $this->assertStringContainsString('handbook.docx', $html);
        $this->assertStringNotContainsString('rad-companion-media-doc', $html);
    }

    /** @test */
    public function storedImageStillRendersImgTag()
    {
        $field = CompanionFields::normalizeField('hero', 'media');

        $stored = serialize([
            'id' => 0,
            'url' => 'https://example.com/wp-content/uploads/2026/11/hero.jpg',
            'alt' => 'Hello',
        ]);

        $html = $this->fieldMarkupWithoutAssets(CompanionFields::renderField($field, $stored));

        $this->assertStringContainsString('data-attachment-type="image"', $html);
        $this->assertStringContainsString('<img class="rad-companion-media-img"', $html);
        $this->assertStringNotContainsString('<div class="rad-companion-media-file">', $html);
    }

    /** @test */
    public function objectOutputReturnsAttachmentArrayFromWpPrepareAttachmentForJs()
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachmentId = self::factory()->attachment->create([
            'post_mime_type' => 'application/pdf',
            'post_title' => 'Menu',
            'guid' => 'https://example.com/wp-content/uploads/menu.pdf',
        ]);

        $field = CompanionFields::normalizeField('featured_document', [
            'type' => 'media',
            'filetypes' => 'documents',
            'output' => 'object',
        ]);

        $resolved = CompanionFields::resolveMediaValue($field, serialize([
            'id' => $attachmentId,
            'url' => 'https://example.com/wp-content/uploads/menu.pdf',
            'alt' => '',
        ]));

        $this->assertIsArray($resolved);
        $this->assertArrayHasKey('type', $resolved);
        $this->assertSame('application', $resolved['type']);
        $this->assertSame('pdf', $resolved['subtype']);
        $this->assertSame($attachmentId, (int) $resolved['id']);
    }

    /** @test */
    public function objectOutputWithMissingAttachmentDegradesToEmptyArray()
    {
        $field = CompanionFields::normalizeField('featured_document', [
            'type' => 'media',
            'output' => 'object',
        ]);

        // id 0 short-circuits before wp_prepare_attachment_for_js is called.
        $this->assertSame('', CompanionFields::resolveMediaValue($field, serialize(['id' => 0, 'url' => '', 'alt' => ''])));
    }

    /** @test */
    public function mediaOutputsIncludesObject()
    {
        $this->assertContains('object', CompanionFields::MEDIA_OUTPUTS);
        // The original outputs are still supported unchanged.
        foreach (['id', 'url', 'alt', 'html', 'array'] as $prior) {
            $this->assertContains($prior, CompanionFields::MEDIA_OUTPUTS);
        }
    }

    /** @test */
    public function repeaterLayoutDefaultsToTableAndAcceptsStacked()
    {
        $subs = ['a' => 'text', 'b' => 'text'];

        $plain = CompanionFields::normalizeField('paper_details', ['type' => 'repeater', 'fields' => $subs]);
        $this->assertSame('table', $plain['layout'] ?? 'table');

        $stacked = CompanionFields::normalizeField('paper_details', ['type' => 'repeater', 'layout' => 'stacked', 'fields' => $subs]);
        $this->assertSame('stacked', $stacked['layout']);

        // Unknown values fall back to table so a typo doesn't quietly flip
        // a horizontal repeater into stacked (or vice-versa).
        $typo = CompanionFields::normalizeField('paper_details', ['type' => 'repeater', 'layout' => 'vertical', 'fields' => $subs]);
        $this->assertSame('table', $typo['layout']);
    }

    /** @test */
    public function stackedRepeaterDropsTheHeadAndInlineGridStyle()
    {
        $field = CompanionFields::normalizeField('paper_details', [
            'type' => 'repeater',
            'label' => 'Paper Details',
            'layout' => 'stacked',
            'fields' => ['title' => 'text', 'weight' => 'text'],
        ]);

        $html = CompanionFields::renderField($field, serialize([['title' => 'T1', 'weight' => '100']]));

        // Wrapper carries the stacked modifier so the CSS vertical rules apply.
        $this->assertStringContainsString('rad-companion-repeater--stacked', $html);
        // No head element rendered. (Match the actual HTML element, not just the
        // class name — the class string appears in the inline admin stylesheet
        // too, which is emitted once per request alongside the first field.)
        $this->assertStringNotContainsString('<div class="rad-companion-repeater-head"', $html);
        // The per-row inline style attribute is not emitted either; stacked
        // layout lets CSS drive the row as a 48px|1fr|64px grid.
        $this->assertStringNotContainsString('style="grid-template-columns:', $html);
        // Rows still exist so the JS add/remove/reorder/JSON-sync code path
        // stays intact (nothing about the DOM shape changes).
        $this->assertStringContainsString('rad-companion-repeater-row', $html);
        $this->assertStringContainsString('rad-companion-repeater-remove', $html);
    }

    /** @test */
    public function repeaterRowKeysAreUniqueAcrossRepeatersOnThePage()
    {
        // Two repeaters with the same sub-field name: their rows used to both
        // be keyed row-0, giving duplicate wysiwyg editor ids (the second
        // editor never initialised) and shared radio group names.
        $field = CompanionFields::normalizeField('cards', [
            'type' => 'repeater',
            'fields' => ['title' => 'text'],
        ]);
        $value = serialize([['title' => 'A']]);

        preg_match('/name="rad_(row-\d+)\[title\]"/', CompanionFields::renderField($field, $value), $first);
        preg_match('/name="rad_(row-\d+)\[title\]"/', CompanionFields::renderField($field, $value), $second);

        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
        $this->assertNotSame($first[1], $second[1]);
    }

    /** @test */
    public function tableLayoutRepeaterStillRendersHeadAndGridStyle()
    {
        $field = CompanionFields::normalizeField('features', [
            'type' => 'repeater',
            'label' => 'Features',
            'fields' => ['title' => 'text', 'icon' => 'media'],
        ]);

        $html = CompanionFields::renderField($field, serialize([['title' => 'T1', 'icon' => '']]));

        $this->assertStringContainsString('rad-companion-repeater--table', $html);
        $this->assertStringContainsString('<div class="rad-companion-repeater-head"', $html);
        // Table layout lays out a 48px gutter + one minmax() track per sub-field
        // (equal 1fr here) + a 64px action column — see repeaterColumnSize().
        $this->assertStringContainsString(
            'style="grid-template-columns:48px minmax(160px,1fr) minmax(160px,1fr) 64px;',
            $html
        );
    }

    // ------------------------------------------------------------------
    // needsWysiwyg / needsMedia (drive admin_enqueue_scripts)
    // ------------------------------------------------------------------

    /** @test */
    public function needsMediaDetectsFlatMediaField()
    {
        $fields = [CompanionFields::normalizeField('hero', 'media')];
        $this->assertTrue(CompanionFields::needsMedia($fields));
    }

    /** @test */
    public function needsMediaIsFalseWhenNoMediaPresent()
    {
        $fields = [
            CompanionFields::normalizeField('title', 'text'),
            CompanionFields::normalizeField('body', 'textarea'),
        ];
        $this->assertFalse(CompanionFields::needsMedia($fields));
    }

    /** @test */
    public function needsMediaDescendsIntoRepeaterSubFields()
    {
        $fields = [CompanionFields::normalizeField('gallery', [
            'type' => 'repeater',
            'fields' => [
                'caption' => 'text',
                'image' => 'media',
            ],
        ])];
        $this->assertTrue(CompanionFields::needsMedia($fields));
    }

    /** @test */
    public function needsMediaDescendsIntoFlexibleLayoutFields()
    {
        $fields = [CompanionFields::normalizeField('content', [
            'type' => 'flexible',
            'layouts' => [
                'hero' => ['fields' => ['background' => 'media']],
                'cta' => ['fields' => ['button_label' => 'text']],
            ],
        ])];
        $this->assertTrue(CompanionFields::needsMedia($fields));
    }

    /** @test */
    public function needsWysiwygStillDetectsOnlyWysiwygs()
    {
        $fields = [
            CompanionFields::normalizeField('hero', 'media'),
            CompanionFields::normalizeField('body', 'textarea'),
        ];
        $this->assertFalse(CompanionFields::needsWysiwyg($fields));

        $fields[] = CompanionFields::normalizeField('rich', 'wysiwyg');
        $this->assertTrue(CompanionFields::needsWysiwyg($fields));
    }

    /** @test */
    public function previewScopesIdsWithoutChangingSubmittedFieldNames()
    {
        $fields = [
            CompanionFields::normalizeField('header_image', 'text'),
            CompanionFields::normalizeField('main_title', 'text'),
        ];

        $html = CompanionFields::renderBox(
            'rad_companion_tpl_flexible',
            'tpl-flexible.php',
            $fields,
            ['header_image' => 'Stored value'],
            'tpl-flexible',
            false
        );

        $this->assertStringContainsString('name="rad_header_image"', $html);
        $this->assertStringContainsString('id="rad_header_image__tpl-flexible"', $html);
        $this->assertStringContainsString('value="Stored value"', $html);
        $this->assertStringNotContainsString('name="rad_header_image__tpl-flexible"', $html);
        $this->assertStringNotContainsString('name="rad_companion_nonce"', $html);
    }

    /** @test */
    public function previewScopesCompositeFieldIdsAsSuffixesForExistingJsSelectors()
    {
        $fields = [
            CompanionFields::normalizeField('features', [
                'type' => 'repeater',
                'fields' => ['title' => 'text'],
            ]),
            CompanionFields::normalizeField('content', [
                'type' => 'flexible',
                'layouts' => [
                    'hero' => ['fields' => ['heading' => 'text']],
                ],
            ]),
        ];

        $html = CompanionFields::renderBox(
            'rad_companion_tpl_flexible',
            'tpl-flexible.php',
            $fields,
            [],
            'tpl-flexible',
            false
        );

        $this->assertStringContainsString('id="rad-companion-repeater-features__tpl-flexible"', $html);
        $this->assertStringContainsString('id="rad-companion-row-template-features__tpl-flexible"', $html);
        $this->assertStringContainsString('id="rad-flexible-content__tpl-flexible"', $html);
        $this->assertStringContainsString('id="rad-flexible-template-content__tpl-flexible-hero"', $html);
        // With no stored rows/blocks the only sub-field inputs are the blank
        // templates (token `blank` / `blank-<layout>`); the JS rekeys those to
        // `row-N` / `block-N` when one is added client-side. Names stay
        // un-namespaced (`rad_blank[...]`, not `rad_blank__tpl-...[...]`).
        $this->assertMatchesRegularExpression('/name="rad_blank\[title\]"/', $html);
        $this->assertMatchesRegularExpression('/name="rad_blank-hero\[heading\]"/', $html);
    }

    /** @test */
    public function previewContainerUsesOneSharedNonceForTheSelectedTemplate()
    {
        $postId = $this->factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Preview',
        ]);
        update_post_meta($postId, '_wp_page_template', 'tpl-flexible.php');

        $groups = [
            'tpl-flexible.php' => [
                'title' => 'Flexible',
                'template' => 'tpl-flexible.php',
                'post_type' => null,
                'fields' => [CompanionFields::normalizeField('header_image', 'text')],
                'sidebar_fields' => [],
                'hidden' => ['content'],
            ],
        ];

        $html = CompanionFields::renderPreviewContainer(get_post($postId), $groups, 'fields');

        $this->assertStringContainsString('data-rad-active="tpl-flexible.php"', $html);
        $this->assertStringContainsString('name="rad_companion_preview_template" value="tpl-flexible.php"', $html);
        $this->assertStringContainsString('name="rad_companion_preview_nonce"', $html);
        $this->assertStringNotContainsString('name="rad_companion_nonce"', $html);
        $this->assertStringContainsString('window.radCompanionPreview', $html);
        $this->assertStringContainsString('body[data-rad-active-template="tpl-flexible.php"] #postdivrich{display:none', $html);
    }
}
