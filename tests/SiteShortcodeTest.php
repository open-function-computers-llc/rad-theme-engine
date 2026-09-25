<?php

namespace ofc\tests;

use ofc\Site;
use WP_UnitTestCase;

class SiteShortcodeTest extends RadTestCase
{
    /** @test */
    public function weCanRegisterAShortcodeViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "shortcodes" => [
                "hello" => fn() => "Hello World",
            ],
        ]);

        $this->assertTrue(shortcode_exists('hello'));
    }

    /** @test */
    public function aRegisteredShortcodeReturnsTheCorrectOutput()
    {
        Site::getInstance([
            "handlebars" => false,
            "shortcodes" => [
                "greeting" => fn() => "Hey there!",
            ],
        ]);

        $output = do_shortcode('[greeting]');
        $this->assertEquals('Hey there!', $output);
    }

    /** @test */
    public function weCanRegisterMultipleShortcodesViaConfig()
    {
        Site::getInstance([
            "handlebars" => false,
            "shortcodes" => [
                "foo" => fn() => "foo output",
                "bar" => fn() => "bar output",
            ],
        ]);

        $this->assertTrue(shortcode_exists('foo'));
        $this->assertTrue(shortcode_exists('bar'));
    }

    /** @test */
    public function missingShortcodesConfigDoesNotThrow()
    {
        Site::getInstance([
            "handlebars" => false,
        ]);

        $this->assertFalse(shortcode_exists('undefined-shortcode'));
    }

    /** @test */
    public function nonArrayShortcodesConfigDoesNotThrow()
    {
        Site::getInstance([
            "handlebars" => false,
            "shortcodes" => "not-an-array",
        ]);

        $this->assertFalse(shortcode_exists('not-an-array'));
    }
}
