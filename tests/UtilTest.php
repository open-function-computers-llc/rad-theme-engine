<?php

namespace ofc\tests;

use ofc\Util;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase
{
    /** @test */
    public function weCanSlugifyAString()
    {
        $cases = [
            "This is a string" => "this-is-a-string",
            "String with nÓn ASCII chars & stuff!" => "string-with-non-ascii-chars-and-stuff",
            "" => "n-a", // here's the oddball
        ];

        foreach ($cases as $input => $expected) {
            $this->assertEquals($expected, Util::slugify($input));
        }
    }

    /** @test */
    public function weCanSnakifyAString()
    {
        $cases = [
            "This is a string" => "this_is_a_string",
            "String with nÓn ASCII chars & stuff!" => "string_with_non_ascii_chars_and_stuff",
            "" => "n_a",
        ];

        foreach ($cases as $input => $expected) {
            $this->assertEquals($expected, Util::snakeify($input));
        }
    }
}
