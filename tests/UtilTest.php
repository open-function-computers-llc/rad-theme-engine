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
        ];

        foreach ($cases as $input => $expected) {
            $this->assertEquals($expected, Util::slugify($input));
        }
    }
}
