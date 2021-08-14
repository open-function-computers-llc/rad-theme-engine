<?php

namespace ofc;

class Util
{
    /**
     * slugify
     * modified from https://lucidar.me/en/web-dev/how-to-slugify-a-string-in-php/
     *
     * @param string $str
     * @return string
     */
    public static function slugify(string $str) : string
    {
        $text = strip_tags($str);
        $text = str_replace(" & ", " and ", $text);
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        setlocale(LC_ALL, 'en_US.utf8');
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim(strtolower($text), '-');
        $text = preg_replace('~-+~', '-', $text);
        if (empty($text)) {
            return 'n-a';
        }
        return $text;
    }
}
