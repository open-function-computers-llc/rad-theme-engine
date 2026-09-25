<?php

namespace ofc\tests;

use ofc\Site;

class SiteEnableTest extends RadTestCase
{
    /** @test */
    public function weCanEnablePostThumbnails()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["post-thumbnails"],
        ]);

        $this->assertTrue(current_theme_supports('post-thumbnails'));
    }

    /** @test */
    public function weCanEnableMenus()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["menus"],
        ]);

        $this->assertTrue(current_theme_supports('menus'));
    }

    /** @test */
    public function weCanEnableExcerpts()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["excerpts"],
        ]);

        do_action('init');

        $this->assertTrue(post_type_supports('page', 'excerpt'));
        $this->assertTrue(post_type_supports('post', 'excerpt'));
    }

    /** @test */
    public function excerptAliasAlsoWorks()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["excerpt"],
        ]);

        do_action('init');

        $this->assertTrue(post_type_supports('page', 'excerpt'));
        $this->assertTrue(post_type_supports('post', 'excerpt'));
    }

    /** @test */
    public function weCanEnableStyleselect()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["styleselect"],
        ]);

        $buttons = apply_filters('mce_buttons_2', ['bold', 'italic']);
        $this->assertEquals('styleselect', $buttons[0]);
    }

    /** @test */
    public function weCanEnableSvgUploads()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["svg"],
        ]);

        $mimes = apply_filters('upload_mimes', []);
        $this->assertArrayHasKey('svg', $mimes);
        $this->assertEquals('image/svg+xml', $mimes['svg']);
    }

    /** @test */
    public function weCanEnableWoocommerce()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["woocommerce"],
        ]);

        $this->assertTrue(current_theme_supports('woocommerce'));
        $this->assertTrue(current_theme_supports('wc-product-gallery-zoom'));
        $this->assertTrue(current_theme_supports('wc-product-gallery-lightbox'));
        $this->assertTrue(current_theme_supports('wc-product-gallery-slider'));
    }

    /** @test */
    public function weCanEnableMultipleFeaturesAtOnce()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["post-thumbnails", "menus", "svg"],
        ]);

        $this->assertTrue(current_theme_supports('post-thumbnails'));
        $this->assertTrue(current_theme_supports('menus'));
        $mimes = apply_filters('upload_mimes', []);
        $this->assertArrayHasKey('svg', $mimes);
    }

    /** @test */
    public function svgFilesAreSanitizedOnUpload()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["svg"],
        ]);

        $maliciousSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><circle cx="50" cy="50" r="40"/></svg>';
        $tmpFile = tempnam(sys_get_temp_dir(), 'svg_test_');
        file_put_contents($tmpFile, $maliciousSvg);

        $file = [
            'name' => 'test.svg',
            'type' => 'image/svg+xml',
            'tmp_name' => $tmpFile,
            'error' => 0,
            'size' => strlen($maliciousSvg),
        ];

        $result = apply_filters('wp_handle_upload_prefilter', $file);
        $sanitizedContents = file_get_contents($tmpFile);

        unlink($tmpFile);

        $this->assertStringNotContainsString('<script>', $sanitizedContents);
        $this->assertStringContainsString('<circle', $sanitizedContents);
        $this->assertArrayNotHasKey('error', array_filter($result));
    }

    /** @test */
    public function nonSvgFilesAreNotAffectedBySvgSanitization()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["svg"],
        ]);

        $file = [
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '/tmp/fakejpeg',
            'error' => 0,
            'size' => 1234,
        ];

        $result = apply_filters('wp_handle_upload_prefilter', $file);
        $this->assertEquals($file, $result);
    }

    /** @test */
    public function invalidSvgFilesAreRejectedWithAnErrorMessage()
    {
        Site::getInstance([
            "handlebars" => false,
            "enable" => ["svg"],
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'svg_test_');
        file_put_contents($tmpFile, 'this is not valid svg or xml at all <<<>>>');

        $file = [
            'name' => 'bad.svg',
            'type' => 'image/svg+xml',
            'tmp_name' => $tmpFile,
            'error' => 0,
            'size' => 100,
        ];

        $result = apply_filters('wp_handle_upload_prefilter', $file);

        unlink($tmpFile);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('sanitized', $result['error']);
    }
}
