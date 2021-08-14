<?php

namespace ofc;

class BetterWordpress
{
    public static function setup()
    {
        self::say("Now setting up your new WordPress theme.");
        self::ask("Press any key to continue");

        $themeName = self::ask("What is the name of your new theme?");

        mkdir(Util::slugify($themeName));
    }

    private static function say(string $thing)
    {
        echo $thing.PHP_EOL;
    }

    private static function ask(string $thing) : string
    {
        echo $thing.PHP_EOL;
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        return trim($line);
    }
}
