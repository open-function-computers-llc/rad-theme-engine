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
