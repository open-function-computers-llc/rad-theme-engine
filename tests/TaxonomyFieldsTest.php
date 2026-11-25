<?php

namespace ofc\tests;

use ofc\CompanionFields;
use ofc\Site;
use ofc\TaxonomyFields;
use ReflectionClass;
use ReflectionMethod;

class TaxonomyFieldsTest extends RadTestCase
{
    /** @var string[] */
    private $themeFiles = [];

    /** @var string[] */
    private $themeDirs = [];

    /** @var array<int, array{term_id: int, taxonomy: string}> */
    private $terms = [];

    /** @var int[] */
    private $postIds = [];

    /** @var string[] */
    private $taxonomies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStaticState();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        global $wp_taxonomies;
        foreach ($this->terms as $entry) {
            wp_delete_term($entry['term_id'], $entry['taxonomy']);
        }
        foreach ($this->postIds as $id) {
            wp_delete_post($id, true);
        }
        foreach ($this->taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                unregister_taxonomy($taxonomy);
                unset($wp_taxonomies[$taxonomy]);
            }
        }
        foreach ($this->themeFiles as $file) {
            @unlink($file);
        }
        $this->themeFiles = [];
        foreach (array_reverse($this->themeDirs) as $dir) {
            @rmdir($dir);
        }
        $this->themeDirs = [];
        $this->resetStaticState();
        $_POST = [];
        parent::tearDown();
    }

    private function resetStaticState(): void
    {
        $reflection = new ReflectionClass(TaxonomyFields::class);
        $prop = $reflection->getProperty('discoveredSlugs');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * Write a file inside the active theme (cleaned up on teardown).
     */
    private function themeFile(string $relative, string $contents): string
    {
        $path = get_template_directory() . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
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

    private function registerTaxonomy(string $slug, array $objectTypes = ['post']): void
    {
        if (taxonomy_exists($slug)) {
            return;
        }
        register_taxonomy($slug, $objectTypes, [
            'public' => true,
            'hierarchical' => true,
            'show_ui' => true,
        ]);
        $this->taxonomies[] = $slug;
    }

    private function makeTerm(string $taxonomy, string $name): int
    {
        $term = wp_insert_term($name, $taxonomy);
        $this->terms[] = ['term_id' => (int) $term['term_id'], 'taxonomy' => $taxonomy];
        return (int) $term['term_id'];
    }

    // ------------------------------------------------------------------
    // Discovery
    // ------------------------------------------------------------------

    /** @test */
    public function discoversFromTaxonomiesDirectory()
    {
        $this->themeFile('taxonomies/rad_folder_taxonomy.yml', "fields:\n  summary: textarea\n  tagline: text\n");

        $groups = TaxonomyFields::discover();

        $this->assertArrayHasKey('rad_folder_taxonomy', $groups);
        $this->assertSame('Rad Folder Taxonomy', $groups['rad_folder_taxonomy']['title']);
        $this->assertCount(2, $groups['rad_folder_taxonomy']['fields']);
        $this->assertSame('summary', $groups['rad_folder_taxonomy']['fields'][0]['name']);
        $this->assertSame('textarea', $groups['rad_folder_taxonomy']['fields'][0]['type']);
    }

    /** @test */
    public function discoversFromRootLevelCompanionYml()
    {
        $this->themeFile('taxonomy-rad_companion_taxonomy.php', "<?php // template\n");
        $this->themeFile('taxonomy-rad_companion_taxonomy.yml', "title: Custom label\nfields:\n  summary: textarea\n");

        $groups = TaxonomyFields::discover();

        $this->assertArrayHasKey('rad_companion_taxonomy', $groups);
        $this->assertSame('Custom label', $groups['rad_companion_taxonomy']['title']);
        $this->assertSame('summary', $groups['rad_companion_taxonomy']['fields'][0]['name']);
    }

    /** @test */
    public function rootLevelCompanionYmlWorksWithoutMatchingPhpTemplate()
    {
        // No taxonomy-rad_bare.php — the yml alone is enough. This is the
        // case for themes that let WordPress render the archive with the
        // default taxonomy template.
        $this->themeFile('taxonomy-rad_bare.yml', "fields:\n  tagline: text\n");

        $groups = TaxonomyFields::discover();

        $this->assertArrayHasKey('rad_bare', $groups);
        $this->assertSame('tagline', $groups['rad_bare']['fields'][0]['name']);
    }

    /** @test */
    public function rootLevelYmlWinsOverFolderForSameSlug()
    {
        $this->themeFile('taxonomies/rad_conflict.yml', "title: Folder version\nfields:\n  folder_only: text\n");
        $this->themeFile('taxonomy-rad_conflict.yml', "title: Root version\nfields:\n  root_only: text\n");

        $groups = TaxonomyFields::discover();

        $this->assertArrayHasKey('rad_conflict', $groups);
        $this->assertSame('Root version', $groups['rad_conflict']['title']);
        $this->assertSame('root_only', $groups['rad_conflict']['fields'][0]['name']);
    }

    /** @test */
    public function phpTemplateWithoutYmlIsIgnored()
    {
        // A stray taxonomy-<slug>.php with no yml still contributes no fields.
        $this->themeFile('taxonomy-rad_orphan.php', "<?php // template, but no yml\n");

        $groups = TaxonomyFields::discover();

        $this->assertArrayNotHasKey('rad_orphan', $groups);
    }

    /** @test */
    public function ymlWithoutFieldsIsIgnored()
    {
        $this->themeFile('taxonomies/rad_empty.yml', "title: nothing here\n");

        $groups = TaxonomyFields::discover();

        $this->assertArrayNotHasKey('rad_empty', $groups);
    }

    /** @test */
    public function ymlWithoutFieldsBlockIsIgnored()
    {
        // A yml that has title/etc. but no `fields:` block is not usable;
        // the file is skipped so it can't accidentally render an empty box.
        $this->themeFile('taxonomy-rad_nofields.yml', "title: no fields block\n");

        $groups = TaxonomyFields::discover();

        $this->assertArrayNotHasKey('rad_nofields', $groups);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /** @test */
    public function addFormIncludesNonceAndFieldMarkup()
    {
        $meta = ['fields' => [
            CompanionFields::normalizeField('tagline', 'text'),
        ]];

        $html = TaxonomyFields::renderAddForm('rad_render_test', $meta);

        $this->assertStringContainsString('name="' . TaxonomyFields::NONCE_FIELD . '"', $html);
        $this->assertStringContainsString('name="rad_tagline"', $html);
        // The add form is <div>-based (WP's add form is not a table).
        $this->assertStringNotContainsString('<tr class="form-field rad-companion-taxonomy-nonce">', $html);
    }

    /** @test */
    public function editFormIncludesNonceRowAndFieldMarkup()
    {
        $this->registerTaxonomy('rad_edit_render');
        $term_id = $this->makeTerm('rad_edit_render', 'Alpha');

        $meta = ['fields' => [
            CompanionFields::normalizeField('tagline', 'text'),
        ]];

        $html = TaxonomyFields::renderEditForm('rad_edit_render', $meta, (object) ['term_id' => $term_id]);

        // Edit form is table-based (WP's edit form is a table), so the nonce
        // has to sit inside a <tr>.
        $this->assertStringContainsString('<tr class="form-field rad-companion-taxonomy-nonce">', $html);
        $this->assertStringContainsString('name="' . TaxonomyFields::NONCE_FIELD . '"', $html);
        $this->assertStringContainsString('name="rad_tagline"', $html);
    }

    /** @test */
    public function editFormPrefillsFromTermMeta()
    {
        $this->registerTaxonomy('rad_prefill');
        $term_id = $this->makeTerm('rad_prefill', 'Alpha');
        update_term_meta($term_id, 'rad_tagline', 'Saved tagline');

        $meta = ['fields' => [
            CompanionFields::normalizeField('tagline', 'text'),
        ]];

        $html = TaxonomyFields::renderEditForm('rad_prefill', $meta, (object) ['term_id' => $term_id]);

        $this->assertStringContainsString('value="Saved tagline"', $html);
    }

    // ------------------------------------------------------------------
    // Save
    // ------------------------------------------------------------------

    /** @test */
    public function saveStoresScalarFieldsAsTermMeta()
    {
        $this->registerTaxonomy('rad_save');
        $term_id = $this->makeTerm('rad_save', 'Alpha');

        $meta = ['fields' => [
            CompanionFields::normalizeField('tagline', 'text'),
            CompanionFields::normalizeField('body', 'textarea'),
            CompanionFields::normalizeField('featured', 'checkbox'),
        ]];

        $_POST = [
            TaxonomyFields::NONCE_FIELD => wp_create_nonce(TaxonomyFields::NONCE_ACTION . 'rad_save'),
            'rad_tagline' => 'Hello',
            'rad_body' => "A paragraph.\n\nAnother.",
            // featured (checkbox) omitted: should store empty string
        ];

        TaxonomyFields::save($term_id, 'rad_save', $meta);

        $this->assertSame('Hello', get_term_meta($term_id, 'rad_tagline', true));
        $this->assertSame("A paragraph.\n\nAnother.", get_term_meta($term_id, 'rad_body', true));
        $this->assertSame('', get_term_meta($term_id, 'rad_featured', true));
    }

    /** @test */
    public function saveRejectsMissingNonce()
    {
        $this->registerTaxonomy('rad_save_noNonce');
        $term_id = $this->makeTerm('rad_save_noNonce', 'Alpha');

        $meta = ['fields' => [CompanionFields::normalizeField('tagline', 'text')]];

        $_POST = ['rad_tagline' => 'should not save'];
        TaxonomyFields::save($term_id, 'rad_save_noNonce', $meta);

        $this->assertSame('', get_term_meta($term_id, 'rad_tagline', true));
    }

    /** @test */
    public function saveRejectsWrongTaxonomyNonce()
    {
        $this->registerTaxonomy('rad_save_wrongNonce');
        $term_id = $this->makeTerm('rad_save_wrongNonce', 'Alpha');

        $meta = ['fields' => [CompanionFields::normalizeField('tagline', 'text')]];

        $_POST = [
            TaxonomyFields::NONCE_FIELD => wp_create_nonce('some_other_action'),
            'rad_tagline' => 'should not save',
        ];
        TaxonomyFields::save($term_id, 'rad_save_wrongNonce', $meta);

        $this->assertSame('', get_term_meta($term_id, 'rad_tagline', true));
    }

    /** @test */
    public function repeaterRoundtripsThroughSaveAndResolve()
    {
        $this->registerTaxonomy('rad_repeat_save');
        $term_id = $this->makeTerm('rad_repeat_save', 'Alpha');

        $field = CompanionFields::normalizeField('details', [
            'type' => 'repeater',
            'label' => 'Details',
            'fields' => ['key' => 'text', 'value' => 'text'],
        ]);
        $meta = ['fields' => [$field]];

        $_POST = [
            TaxonomyFields::NONCE_FIELD => wp_create_nonce(TaxonomyFields::NONCE_ACTION . 'rad_repeat_save'),
            'rad_details' => json_encode([
                ['key' => 'Author', 'value' => 'Grace Hopper'],
                ['key' => 'Year', 'value' => '1952'],
            ]),
        ];

        TaxonomyFields::save($term_id, 'rad_repeat_save', $meta);

        $raw = get_term_meta($term_id, 'rad_details', true);
        $this->assertSame(2, count(unserialize($raw)));

        $this->themeFile('taxonomies/rad_repeat_save.yml', "fields:\n  details:\n    type: repeater\n    fields:\n      key: text\n      value: text\n");

        $resolved = TaxonomyFields::resolveField('rad_repeat_save', 'details', $term_id);
        $this->assertSame([
            ['key' => 'Author', 'value' => 'Grace Hopper'],
            ['key' => 'Year', 'value' => '1952'],
        ], $resolved);
    }

    /** @test */
    public function resolveAllReturnsEveryFieldForTerm()
    {
        $this->registerTaxonomy('rad_resolve_all');
        $term_id = $this->makeTerm('rad_resolve_all', 'Alpha');
        update_term_meta($term_id, 'rad_tagline', 'One');
        update_term_meta($term_id, 'rad_summary', 'Two');

        $this->themeFile('taxonomies/rad_resolve_all.yml', "fields:\n  tagline: text\n  summary: textarea\n");

        $this->assertSame(
            ['tagline' => 'One', 'summary' => 'Two'],
            TaxonomyFields::resolveAll('rad_resolve_all', $term_id)
        );
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    /** @test */
    public function bootAttachesHooksForEveryDiscoveredTaxonomy()
    {
        // Folder pattern.
        $this->themeFile('taxonomies/rad_boot_folder.yml', "fields:\n  tagline: text\n");
        // Root-level companion yml, no PHP template required.
        $this->themeFile('taxonomy-rad_boot_root.yml', "fields:\n  tagline: text\n");

        TaxonomyFields::boot();

        foreach (['rad_boot_folder', 'rad_boot_root'] as $slug) {
            $this->assertContains($slug, TaxonomyFields::discovered());
            $this->assertNotFalse(has_action("{$slug}_add_form_fields"));
            $this->assertNotFalse(has_action("{$slug}_edit_form_fields"));
            $this->assertNotFalse(has_action("created_{$slug}"));
            $this->assertNotFalse(has_action("edited_{$slug}"));
        }
    }

    // ------------------------------------------------------------------
    // Site::getTerm integration
    // ------------------------------------------------------------------

    /** @test */
    public function siteGetTermResolvesRadFieldsViaCompanionYml()
    {
        $this->themeFile(
            'taxonomy-rad_getterm.yml',
            "fields:\n  flex_desc: text\n  flex_image:\n    type: media\n    output: url\n"
        );

        $this->registerTaxonomy('rad_getterm');
        $term_id = $this->makeTerm('rad_getterm', 'Alpha');
        update_term_meta($term_id, 'rad_flex_desc', 'A description');
        $attachment_id = $this->factory()->attachment->create(['post_title' => 'Hero']);
        update_term_meta($term_id, 'rad_flex_image', serialize(['id' => $attachment_id]));

        $site = Site::getInstance(['handlebars' => false]);

        $rows = $site->getTerm('rad_getterm', [
            'title',
            'rad.flex_desc',
            'rad.flex_image',
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Alpha', $row['title']);
        $this->assertSame('A description', $row['flex_desc']);
        // `media` + `output: url` resolves to the attachment's file URL (a url
        // never carries the title); verify getTerm resolved rad.flex_image to
        // the stored attachment's URL.
        $this->assertSame((string) wp_get_attachment_url($attachment_id), (string) $row['flex_image']);

        // The `rad.` prefix must NOT appear in the output keys — matching the
        // post-side behaviour of getFieldsForPost.
        $this->assertArrayNotHasKey('rad.flex_desc', $row);
        $this->assertArrayNotHasKey('rad_flex_desc', $row);
    }

    /** @test */
    public function siteGetTermPullsAttachedPostsWithSubFields()
    {
        $this->themeFile('taxonomy-rad_postjoin.yml', "fields:\n  tagline: text\n");
        $this->themeFile('single-rad_postjoin_paper.yml', "fields:\n  sc_grade: text\n");

        $site = Site::getInstance([
            'handlebars' => false,
            'custom-post-types' => [
                [
                    'slug' => 'rad_postjoin_paper',
                    'taxonomies' => ['rad_postjoin' => ['hierarchical' => true]],
                ],
            ],
        ]);
        do_action('init');

        $term_id = $this->makeTerm('rad_postjoin', 'Card Stock');
        $post_a = $this->factory()->post->create([
            'post_type' => 'rad_postjoin_paper',
            'post_title' => 'A Paper',
        ]);
        $post_b = $this->factory()->post->create([
            'post_type' => 'rad_postjoin_paper',
            'post_title' => 'B Paper',
        ]);
        $unrelated = $this->factory()->post->create([
            'post_type' => 'rad_postjoin_paper',
            'post_title' => 'Not In Term',
        ]);
        wp_set_object_terms($post_a, [$term_id], 'rad_postjoin');
        wp_set_object_terms($post_b, [$term_id], 'rad_postjoin');
        update_post_meta($post_a, 'rad_sc_grade', 'Year 3');
        update_post_meta($post_b, 'rad_sc_grade', 'Year 5');

        foreach ([$post_a, $post_b, $unrelated] as $id) {
            $this->postIds[] = $id;
        }

        $rows = $site->getTerm('rad_postjoin', [
            'title',
            'posts.title,url,rad.sc_grade',
        ]);

        $this->assertCount(1, $rows);
        $term = $rows[0];
        $this->assertSame('Card Stock', $term['title']);
        $this->assertArrayHasKey('posts', $term);
        $this->assertCount(2, $term['posts']);

        // Sorted by title ASC — A first, B second.
        $this->assertSame('A Paper', $term['posts'][0]['title']);
        $this->assertSame('B Paper', $term['posts'][1]['title']);
        $this->assertSame('Year 3', $term['posts'][0]['sc_grade']);
        $this->assertSame('Year 5', $term['posts'][1]['sc_grade']);
        $this->assertNotEmpty($term['posts'][0]['url']);
    }
}
