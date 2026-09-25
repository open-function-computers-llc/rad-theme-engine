<?php

namespace ofc\tests;

use ofc\Site;

class SiteAssetTest extends RadTestCase
{
    private string $assetsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assetsDir = get_template_directory() . '/assets';
        if (!is_dir($this->assetsDir)) {
            mkdir($this->assetsDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // clean up any test files we created
        array_map('unlink', glob($this->assetsDir . '/test-*'));
    }

    /** @test */
    public function getAssetURLReturnsCorrectURL()
    {
        file_put_contents($this->assetsDir . '/test-image.jpg', 'fake image content');

        $site = Site::getInstance(["handlebars" => false]);
        $url = $site->getAssetURL('test-image.jpg');

        $this->assertStringContainsString('/assets/test-image.jpg', $url);
        $this->assertNotEmpty($url);
    }

    /** @test */
    public function getAssetURLReturnsEmptyStringForMissingFile()
    {
        $site = Site::getInstance(["handlebars" => false]);
        $url = $site->getAssetURL('test-does-not-exist.jpg');

        $this->assertEquals('', $url);
    }

    /** @test */
    public function getAssetContentsReturnsFileContents()
    {
        $svgContent = '<svg><circle cx="50" cy="50" r="40"/></svg>';
        file_put_contents($this->assetsDir . '/test-icon.svg', $svgContent);

        $site = Site::getInstance(["handlebars" => false]);
        $contents = $site->getAssetContents('test-icon.svg');

        $this->assertEquals($svgContent, $contents);
    }

    /** @test */
    public function getAssetContentsReturnsErrorMessageForMissingFile()
    {
        $site = Site::getInstance(["handlebars" => false]);
        $contents = $site->getAssetContents('test-does-not-exist.svg');

        $this->assertStringContainsString("Asset doesn't exist", $contents);
    }
}
