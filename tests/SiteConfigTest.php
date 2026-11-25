<?php

namespace ofc\tests;

use ofc\Site;
use WP_UnitTestCase;

class SiteConfigTest extends RadTestCase
{
    /** @test */
    public function weCanModifyTheExcerptLenghtViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "excerpt-length" => 55,
        ]);

        $length = apply_filters('excerpt_length', 999);
        $this->assertEquals(55, $length);
    }

    /** @test */
    public function weCanRegisterACustomPostTypeViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "custom-post-types" => [
                ["slug" => "book"],
            ],
        ]);

        do_action('init');

        $this->assertTrue(post_type_exists('book'));
    }

    /** @test */
    public function taxonomiesDefaultToNonHierarchicalWhenNoOptionsAreSupplied()
    {
        Site::getInstance([
            "handlebars" => false,
            "custom-post-types" => [
                [
                    "slug" => "legacy_book",
                    "taxonomies" => "genre",
                ],
            ],
        ]);

        do_action('init');

        $tax = get_taxonomy('genre');
        $this->assertNotFalse($tax, 'Legacy string-form taxonomies should still register.');
        $this->assertFalse($tax->hierarchical);
    }

    /** @test */
    public function listFormTaxonomiesStillRegisterAndDefaultToNonHierarchical()
    {
        Site::getInstance([
            "handlebars" => false,
            "custom-post-types" => [
                [
                    "slug" => "list_book",
                    "taxonomies" => ["list_genre", "list_format"],
                ],
            ],
        ]);

        do_action('init');

        foreach (['list_genre', 'list_format'] as $tax) {
            $obj = get_taxonomy($tax);
            $this->assertNotFalse($obj, "$tax should register from the list form.");
            $this->assertFalse($obj->hierarchical);
        }
    }

    /** @test */
    public function hierarchicalTaxonomyCanBeRequestedViaTheMapForm()
    {
        Site::getInstance([
            "handlebars" => false,
            "custom-post-types" => [
                [
                    "slug" => "hier_book",
                    "taxonomies" => [
                        "hier_genre" => ['hierarchical' => true],
                    ],
                ],
            ],
        ]);

        do_action('init');

        $tax = get_taxonomy('hier_genre');
        $this->assertNotFalse($tax);
        $this->assertTrue($tax->hierarchical, 'hierarchical => true should reach register_taxonomy.');
    }

    /** @test */
    public function extraTaxonomyOptionsAreForwardedToRegisterTaxonomy()
    {
        Site::getInstance([
            "handlebars" => false,
            "custom-post-types" => [
                [
                    "slug" => "extra_book",
                    "taxonomies" => [
                        "extra_genre" => [
                            'hierarchical' => true,
                            'show_in_rest' => true,
                            'show_admin_column' => true,
                        ],
                    ],
                ],
            ],
        ]);

        do_action('init');

        $tax = get_taxonomy('extra_genre');
        $this->assertNotFalse($tax);
        $this->assertTrue($tax->hierarchical);
        $this->assertTrue($tax->show_in_rest, 'show_in_rest should pass through from the yml options.');
        $this->assertTrue($tax->show_admin_column, 'show_admin_column should pass through from the yml options.');
    }

    /** @test */
    public function weCanRegisterMenuLocationsViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "menu-locations" => [
                "main-nav" => "Main Navigation",
            ],
        ]);

        do_action('init');

        $locations = get_registered_nav_menus();
        $this->assertArrayHasKey('main-nav', $locations);
        $this->assertEquals('Main Navigation', $locations['main-nav']);
    }

    /** @test */
    public function weCanSetAGuestBodyClassViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "guest-class" => "not-logged-in",
        ]);

        $classes = apply_filters('body_class', []);
        $this->assertContains('not-logged-in', $classes);
    }

    /** @test */
    public function weCanSetTheExcerptMoreTextViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "excerpt-more-text" => "... keep reading",
        ]);

        $more = apply_filters('excerpt_more', '');
        $this->assertEquals('... keep reading', $more);
    }

    /** @test */
    public function weCanDisableRevisionsViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["revisions"],
        ]);

        $revisions = apply_filters('wp_revisions_to_keep', 10, null);
        $this->assertEquals(0, $revisions);
    }

    /** @test */
    public function weCanDisableGutenbergViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["gutenberg"],
        ]);

        $result = apply_filters('use_block_editor_for_post', true);
        $this->assertFalse($result);
    }

    /** @test */
    public function weCanDisableEmojisViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["emojis"],
        ]);

        $this->assertFalse(has_action('wp_head', 'print_emoji_detection_script'));
        $this->assertFalse(has_action('wp_print_styles', 'print_emoji_styles'));
    }
}
