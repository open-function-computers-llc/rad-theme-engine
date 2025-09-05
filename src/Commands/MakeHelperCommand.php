<?php

namespace ofc\Commands;

class MakeHelperCommand
{
    public static function run(array $args): void
    {
        if (!isset($args[0]) || $args[0] === '--help') {
            echo self::getHelp().PHP_EOL;
            exit(!isset($args[0]) || $args[0] === '--help'? 0 : 1);
        }

        // Optional flag to update the config.php file to include the new helper
        $updateConfigFlag = false;
        if ($args[0] === '--update-config' || $args[0] === '-u') {
            $updateConfigFlag = true;
            $args = array_slice($args, 1);
        }

        $helperName = preg_replace('/[^A-Za-z]/', '', $args[0]);

        $templateRoot = getcwd();
        $helpersDir = $templateRoot . DIRECTORY_SEPARATOR . 'helpers';
        $filePath = $helpersDir . DIRECTORY_SEPARATOR . "{$helperName}Helper.php";

        if (!is_dir($helpersDir) && !mkdir($helpersDir, 0775, true)) {
            fwrite(STDERR, "Error: Could not create directory: $helpersDir\n");
            exit(1);
        }

        if (file_exists($filePath)) {
            fwrite(STDERR, "Error: Helper already exists at $filePath\n");
            exit(1);
        }

        $stub = <<<PHP
        <?php
        
        namespace Helpers;
        
        class {$helperName}Helper
        {

            /**
             * Your helper callback.
             */
            public static function callback()
            {
                return function (\$template, \$context, \$args, \$source) {
                    //
                    return '';
                };
            }
        }

        PHP;

        if (file_put_contents($filePath, $stub) === false) {
            fwrite(STDERR, "Error: Unable to write file: $filePath\n");
            exit(1);
        }

        // Experimental: update the config.php file to include the new helper
        // TODO: Maybe there's a library that could do this in a better way
        $configFile = $templateRoot . DIRECTORY_SEPARATOR . 'config.php';
        if ($updateConfigFlag && file_exists($configFile)) {
            $configContents = file_get_contents($configFile);
            $configRegex = '/\A(\X+(?:"handlebars"|\'handlebars\') +=> +\[\X+(?:"additional-helpers"|\'additional-helpers\') +=> +\[[^\]]*)()(\]\X+)$/';
            
            // Splits the config file into before and after exactly where the new helper should be inserted
            preg_match_all($configRegex, $configContents, $matches, PREG_SET_ORDER, 0);
            
            if (count($matches[0]) == 4) {
                // Put it all back together
                $configContents = $matches[0][1] . PHP_EOL . '            "' . $helperName . '" => \\Helpers\\' . $helperName . 'Helper::callback(),' . PHP_EOL . '        ' . $matches[0][3];
                file_put_contents($configFile, $configContents);
            }
        }

        echo "Helper created: $filePath".PHP_EOL;
    }

    public static function getHelp(): string
    {
        return <<<HELP
Usage:
  rad make:helper [options] <helperName>

Options:
  -u, --update-config
    (Experimental) Update the config.php file to include the new helper.

Description:
  Creates a new Handlebars Helper class inside your current WordPress theme:
  {theme}/helpers/<helperName>Helper.php

Docs:
  https://rad-theme-engine.ofco.cloud/docs/guides/helpers/

Example:
  rad make:helper TaxonomyButtons
HELP;
    }
}
