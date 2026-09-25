<?php

namespace ofc\tests;

use ofc\Site;

class SiteManifestTest extends RadTestCase
{
    private string $distDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->distDir = get_template_directory() . '/dist';
        if (!is_dir($this->distDir)) {
            mkdir($this->distDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->distDir . '/*'));
        rmdir($this->distDir);
        parent::tearDown();
    }

    /** @test */
    public function itDoesNothingWhenManifestFileDoesNotExist()
    {
        Site::getInstance(["handlebars" => false]);
        do_action('wp_enqueue_scripts');

        $this->assertFalse(wp_script_is('app.js', 'registered'));
        $this->assertFalse(wp_style_is('app.css', 'registered'));
    }

    /** @test */
    public function itEnqueuesJavascriptFilesFromManifest()
    {
        file_put_contents($this->distDir . '/mix-manifest.json', json_encode([
            "/app.js" => "/app.js?id=1234",
        ]));

        Site::getInstance(["handlebars" => false]);
        do_action('wp_enqueue_scripts');

        $this->assertTrue(wp_script_is('app.js', 'registered'));
    }

    /** @test */
    public function itEnqueuesCssFilesFromManifest()
    {
        file_put_contents($this->distDir . '/mix-manifest.json', json_encode([
            "/app.css" => "/app.css?id=1234",
        ]));

        Site::getInstance(["handlebars" => false]);
        do_action('wp_enqueue_scripts');

        $this->assertTrue(wp_style_is('app.css', 'registered'));
    }

    /** @test */
    public function itHandlesMultipleFilesInManifest()
    {
        file_put_contents($this->distDir . '/mix-manifest.json', json_encode([
            "/app.js" => "/app.js?id=1234",
            "/app.css" => "/app.css?id=1234",
            "/vendor.js" => "/vendor.js?id=5678",
        ]));

        Site::getInstance(["handlebars" => false]);
        do_action('wp_enqueue_scripts');

        $this->assertTrue(wp_script_is('app.js', 'registered'));
        $this->assertTrue(wp_style_is('app.css', 'registered'));
        $this->assertTrue(wp_script_is('vendor.js', 'registered'));
    }

    /** @test */
    public function itInlinesInlineCssViaWpHead()
    {
        file_put_contents($this->distDir . '/mix-manifest.json', json_encode([
            "/inline.css" => "/inline.css?id=1234",
        ]));
        file_put_contents($this->distDir . '/inline.css', 'body { margin: 0; }');

        Site::getInstance(["handlebars" => false]);

        ob_start();
        do_action('wp_head');
        $output = ob_get_clean();

        $this->assertStringContainsString('<style>', $output);
        $this->assertStringContainsString('body { margin: 0; }', $output);
    }
}
