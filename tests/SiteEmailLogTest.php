<?php

namespace ofc\tests;

use ofc\Site;

class SiteEmailLogTest extends RadTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // clean up any log entries between tests
        $posts = get_posts([
            'post_type' => 'rad-email-log',
            'numberposts' => -1,
            'post_status' => 'any',
        ]);
        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
        }
    }

    /** @test */
    public function emailLoggingRegistersTheCustomPostType()
    {
        Site::getInstance([
            "handlebars" => false,
            "email" => [
                "log" => true,
            ],
        ]);

        do_action('init');

        $this->assertTrue(post_type_exists('rad-email-log'));
    }

    /** @test */
    public function theEmailLogCptIsNotPublic()
    {
        Site::getInstance([
            "handlebars" => false,
            "email" => [
                "log" => true,
            ],
        ]);

        do_action('init');

        $postType = get_post_type_object('rad-email-log');
        $this->assertFalse($postType->public);
        $this->assertFalse($postType->show_in_menu);
        $this->assertFalse($postType->show_in_nav_menus);
    }

    /** @test */
    public function outgoingEmailsAreStoredAsCustomPostTypes()
    {
        Site::getInstance([
            "handlebars" => false,
            "email" => [
                "log" => true,
            ],
        ]);

        do_action('init');
        wp_mail('test@example.com', 'Test Subject', 'Test body content');

        $posts = get_posts([
            'post_type' => 'rad-email-log',
            'numberposts' => 1,
            'post_status' => 'publish',
        ]);

        $this->assertCount(1, $posts);
        $this->assertEquals('Test Subject', $posts[0]->post_title);
        $this->assertEquals('Test body content', $posts[0]->post_content);
    }

    /** @test */
    public function loggedEmailsStoreTheRecipientInPostMeta()
    {
        Site::getInstance([
            "handlebars" => false,
            "email" => [
                "log" => true,
            ],
        ]);

        do_action('init');
        wp_mail('recipient@example.com', 'Meta Test', 'Body');

        $posts = get_posts([
            'post_type' => 'rad-email-log',
            'numberposts' => 1,
            'post_status' => 'publish',
        ]);

        $toAddress = get_post_meta($posts[0]->ID, 'to_address', true);
        $this->assertEquals('recipient@example.com', $toAddress);
    }

    /** @test */
    public function loggedEmailsStoreHeadersInPostMeta()
    {
        Site::getInstance([
            "handlebars" => false,
            "email" => [
                "log" => true,
            ],
        ]);

        do_action('init');
        wp_mail('test@example.com', 'Header Test', 'Body', ['Content-Type: text/html']);

        $posts = get_posts([
            'post_type' => 'rad-email-log',
            'numberposts' => 1,
            'post_status' => 'publish',
        ]);

        $headers = get_post_meta($posts[0]->ID, 'headers', true);
        $this->assertStringContainsString('Content-Type: text/html', $headers);
    }

    /** @test */
    public function emailLoggingRegistersAnAdminMenuPage()
    {
        Site::getInstance([
            "handlebars" => false,
            "email" => [
                "log" => true,
            ],
        ]);

        do_action('admin_menu');

        global $menu;
        $menuSlugs = array_column($menu ?? [], 2);
        $this->assertContains('rad-email-log', $menuSlugs);
    }

    /** @test */
    public function emailLoggingDoesNotFireWhenLogIsFalse()
    {
        Site::getInstance([
            "handlebars" => false,
            "email" => [
                "log" => false,
            ],
        ]);

        do_action('init');

        $this->assertFalse(post_type_exists('rad-email-log'));
    }

    /** @test */
    public function emailLoggingDoesNotFireWhenEmailKeyIsMissing()
    {
        Site::getInstance([
            "handlebars" => false,
        ]);

        do_action('init');

        $this->assertFalse(post_type_exists('rad-email-log'));
    }
}
