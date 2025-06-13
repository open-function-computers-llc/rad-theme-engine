<?php

namespace ofc\Commands;

use ofc\IconGetter;

class GetIconCommand
{
    public static function run(array $args): void
    {
        if (count($args) !== 1) {
            fwrite(STDERR, "Usage: rad get-icon <icon-name>\n");
            var_dump($args);
            exit(1);
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
