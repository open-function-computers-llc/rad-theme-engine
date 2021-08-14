<?php

namespace ofc;

class BetterWordpress
{
    public static function setup()
    {
        self::say("Now setting up your new WordPress theme.");
        $text = self::ask("Press any key to continue");

        self::say("You typed $text");
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
