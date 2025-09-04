<?php

namespace ofc\Commands;

use ofc\IconGetter;

class GetIconCommand
{

    private const source_urls = [
      // Bootstrap Icons - https://icons.getbootstrap.com
      "bootstrap" => "https://icons.getbootstrap.com/assets/icons/",

      // Hero Icons - https://heroicons.com
      "heroicons_16_solid" => "https://raw.githubusercontent.com/tailwindlabs/heroicons/refs/heads/master/src/16/solid/",
      "heroicons_20_solid" => "https://raw.githubusercontent.com/tailwindlabs/heroicons/refs/heads/master/src/20/solid/",
      "heroicons_24_solid" => "https://raw.githubusercontent.com/tailwindlabs/heroicons/refs/heads/master/src/24/solid/",
      "heroicons_24_outline" => "https://raw.githubusercontent.com/tailwindlabs/heroicons/refs/heads/master/src/24/outline/",

      // Lucide Icons - https://lucide.dev/icons/
      "lucide" => "https://raw.githubusercontent.com/lucide-icons/lucide/refs/heads/main/icons/",

      // Material Icons - https://fonts.google.com/icons
      "material" => "https://api.iconify.design/material-symbols:",
      "material_round" => ["https://api.iconify.design/material-symbols:", "-rounded.svg"],
      "material_outline" => ["https://api.iconify.design/material-symbols:", "-outline.svg"],
      "material_sharp" => ["https://api.iconify.design/material-symbols:", "-sharp.svg"],
      "material_round_outline" => ["https://api.iconify.design/material-symbols:", "-outline-rounded.svg"],

      // Google Noto Emojis - https://icones.js.org/collection/noto
      "noto" => "https://api.iconify.design/noto:",

      // Flags - https://flagpack.xyz/docs/flag-index/
      "flag" => "https://api.iconify.design/flagpack:",
    ];

    public static function run(array $args, string $source = self::source_urls["bootstrap"]): void
    {
        if (!isset($args[0]) || $args[0] === '--help') {
            echo self::getHelp().PHP_EOL;
            exit(!isset($args[0]) || $args[0] === '--help'? 0 : 1);
        }

        $source_options = [
          "-bootstrap" => self::source_urls["bootstrap"],
          "-bs" => self::source_urls["bootstrap"],
          "-heroicons" => self::source_urls["heroicons_20_solid"],
          "-hi" => self::source_urls["heroicons_20_solid"],
          "-hi-16" => self::source_urls["heroicons_16_solid"],
          "-hi-24" => self::source_urls["heroicons_24_solid"],
          "-hi-outline" => self::source_urls["heroicons_24_outline"],
          "-lucide" => self::source_urls["lucide"],
          "-li" => self::source_urls["lucide"],
          "-material" => self::source_urls["material"],
          "-md" => self::source_urls["material"],
          "-md-round" => self::source_urls["material_round"],
          "-md-outline" => self::source_urls["material_outline"],
          "-md-sharp" => self::source_urls["material_sharp"],
          "-md-round-outline" => self::source_urls["material_round_outline"],
          "-emoji" => self::source_urls["noto"],
          "-e" => self::source_urls["noto"],
          "-flag" => self::source_urls["flag"],
          "-f" => self::source_urls["flag"],
        ];

        if ($args[0] && isset($source_options[$args[0]])) {
          $source = $source_options[$args[0]];
          self::run(array_slice($args, 1), $source);
          return;
        }

        $icon = trim(strtolower($args[0]));

        IconGetter::get($icon, $source);
    }

    public static function getHelp(): string
    {
        return <<<HELP
Usage: rad get:icon [source] <icon-name>

Source:
  -bootstrap, -bs     Boostrap Icons (Default) - https://icons.getbootstrap.com
  -heroicons, -hi     Hero Icons 20px - https://heroicons.com
    -hi-16              Hero Icons 16px
    -hi-24              Hero Icons 24px
    -hi-outline         Hero Icons 24px Outline
  -material, -md      Material Icons - https://fonts.google.com/icons
    -md-round           Material Icons Rounded
    -md-outline         Material Icons Outlined
    -md-sharp           Material Icons Sharp
    -md-round-outline   Material Icons Round Outlined
  -lucide, -li        Lucide Icons - https://lucide.dev/icons/
  -emoji, -e          Google Noto Emojis - https://icones.js.org/collection/noto
  -flag, -f           Flagpack Flags (Alpha-2 Code) - https://flagpack.xyz/docs/flag-index/

Description:
  Download the SVG icon from the specified source and place it in your theme assets folder.
  TODO: link to the docs page.

Example:
  rad get:icon phone
  rad get:icon -hi phone
  rad get:icon -flag uk
  rad get:icon -emoji thumbs-up
HELP;
    }
}
