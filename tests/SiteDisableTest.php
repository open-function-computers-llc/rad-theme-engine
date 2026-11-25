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

    /** @test */
    public function weCanDisableFonts()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["fonts"],
        ]);

        $this->assertGreaterThan(0, has_action("admin_init"));
    }

    /** @test */
    public function disablingFontsRemovesTheAppearanceFontsSubmenu()
    {
        global $submenu;
        $backup = isset($submenu['themes.php']) ? $submenu['themes.php'] : [];

        // Mirror wp-admin/menu.php's Font Library registration so we can see
        // the disable hook actually strip it (see wp-admin/menu.php in core).
        $submenu['themes.php'][9] = array('Fonts', 'edit_theme_options', 'font-library.php');

        // Isolate the fonts-disable behaviour. Firing the global admin_init also
        // runs core's own callbacks which, outside a real wp-admin request, call
        // header() and _doing_it_wrong('wp_add_privacy_policy_content', …) — both
        // of which the CLI test harness turns into an error. Drop every
        // registered admin_init callback, then boot Site (which re-adds only the
        // fonts removal on admin_init) and fire it so just our closure runs.
        // WP_UnitTestCase backs up/restores $wp_filter, so the wipe is contained.
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        remove_all_actions('admin_init');

        Site::getInstance([
            "handlebars" => false,
            "disable" => ["fonts"],
        ]);

        do_action('admin_init');

        $slugs = array_column(isset($submenu['themes.php']) ? $submenu['themes.php'] : [], 2);
        $this->assertNotContains('font-library.php', $slugs);

        $submenu['themes.php'] = $backup;
    }

    /** @test */
    public function weCanDisableComments()
    {
        Site::getInstance([
            "handlebars" => false,
            "disable" => ["comments"],
        ]);

        // Comments_open should be forced closed.
        $this->assertFalse(apply_filters('comments_open', true, 1));
        $this->assertFalse(apply_filters('pings_open', true, 1));
    }

    /** @test */
    public function disablingCommentsStripsCommentSupportFromEveryRegisteredPostType()
    {
        // Register a CPT in the SAME bootstrap call as the disable so the init
        // hook has a non-core post type to strip support off of (Site is a
        // singleton — a second getInstance() would be a no-op).
        Site::getInstance([
            "handlebars" => false,
            "custom-post-types" => [
                ["slug" => "comment_test_cpt"],
            ],
            "disable" => ["comments"],
        ]);

        do_action('init');

        foreach (['post', 'page', 'comment_test_cpt'] as $type) {
            $this->assertFalse(
                post_type_supports($type, 'comments'),
                "$type should not declare comment support after disable=comments."
            );
        }
    }

    /** @test */
    public function disablingCommentsRemovesTheCommentsAdminMenuEntry()
    {
        global $menu;
        $backup = isset($menu) ? $menu : [];

        // Mirror what wp-admin/menu.php installs so we can see the disable hook
        // actually pull it back out (see wp-admin/menu.php in core).
        $menu[25] = array('Comments', 'edit_posts', 'edit-comments.php');

        Site::getInstance([
            "handlebars" => false,
            "disable" => ["comments"],
        ]);

        do_action('admin_menu');

        $slugs = array_column($menu, 2);
        $this->assertNotContains('edit-comments.php', $slugs);

        $menu = $backup;
    }
}
