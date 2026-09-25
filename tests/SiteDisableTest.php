<?php

namespace ofc\tests;

use ofc\Site;

class SiteDisableTest extends RadTestCase
{
    /** @test */
    public function weCanDisableTheFileEditor()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["editor"],
        ]);

        $this->assertTrue(defined('DISALLOW_FILE_EDIT'));
        $this->assertTrue(DISALLOW_FILE_EDIT);
    }

    /** @test */
    public function weCanDisableRevisions()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["revisions"],
        ]);

        $revisions = apply_filters('wp_revisions_to_keep', 10, null);
        $this->assertEquals(0, $revisions);
    }

    /** @test */
    public function weCanDisableGutenberg()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["gutenberg"],
        ]);

        $result = apply_filters('use_block_editor_for_post', true);
        $this->assertFalse($result);
    }

    /** @test */
    public function weCanDisableEmojis()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["emojis"],
        ]);

        $this->assertFalse(has_action('wp_head', 'print_emoji_detection_script'));
        $this->assertFalse(has_action('wp_print_styles', 'print_emoji_styles'));
    }

    /** @test */
    public function weCanDisableMetaGenerator()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["meta-generator"],
        ]);

        $this->assertFalse(has_action('wp_head', 'wp_generator'));
        $this->assertEquals('', apply_filters('the_generator', 'some-generator-string'));
    }

    /** @test */
    public function weCanDisablePatterns()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["patterns"],
        ]);

        $this->assertGreaterThan(0, has_action("admin_init"));
    }

    /** @test */
    public function weCanDisableTheCustomizer()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["customizer"],
        ]);

        do_action("init");
        // customize capability should be mapped to "nope"
        $caps = apply_filters('map_meta_cap', [], 'customize', 1, []);
        $this->assertContains('nope', $caps);
    }

    /** @test */
    public function weCanDisableMultipleThingsAtOnce()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["revisions", "gutenberg", "emojis"],
        ]);

        $this->assertEquals(0, apply_filters('wp_revisions_to_keep', 10, null));
        $this->assertFalse(apply_filters('use_block_editor_for_post', true));
        $this->assertFalse(has_action('wp_head', 'print_emoji_detection_script'));
    }

    /** @test */
    public function disablingGutenbergDequeuesBlockStyles()
    {
        // enqueue the styles first so we can check they get dequeued
        wp_enqueue_style('wp-block-library');
        wp_enqueue_style('wp-block-library-theme');
        wp_enqueue_style('global-styles');

        Site::getInstance([
            "handlebars" => false,
            "disable" => ["gutenberg"],
        ]);

        do_action('wp_enqueue_scripts');

        $this->assertFalse(wp_style_is('wp-block-library', 'enqueued'));
        $this->assertFalse(wp_style_is('wp-block-library-theme', 'enqueued'));
        $this->assertFalse(wp_style_is('global-styles', 'enqueued'));
    }

    /** @test */
    public function disablingPatternsFiresAdminInit()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["patterns"],
        ]);

        // verify admin_init hook was registered for submenu cleanup
        $this->assertGreaterThan(0, has_action("admin_init"));
    }
}
