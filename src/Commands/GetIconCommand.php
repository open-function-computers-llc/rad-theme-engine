<?php

namespace ofc\Commands;

use ofc\IconGetter;

class GetIconCommand
{
    public static function run(array $args): void
    {
        if (!isset($args[0]) || $args[0] === '--help') {
            echo self::getHelp().PHP_EOL;
            exit(!isset($args[0]) || $args[0] === '--help'? 0 : 1);
        }

        $icon = trim(strtolower($args[0]));

        IconGetter::get($icon);
    }

    public static function getHelp(): string
    {
        return <<<HELP
Usage: rad get-icon <icon-name>

Description:
  Download the SVG icon from https://icons.getbootstrap.com and place it in your theme assets folder.
  TODO: link to the docs page.

Example:
  rad get-icon phone
HELP;
    }
}
