<?php

namespace ofc\Commands;

use function get_template_directory;

class MakeActionCommand
{
    public static function run(array $args): void
    {
        if (!isset($args[0]) || $args[0] === '--help') {
            echo self::getHelp().PHP_EOL;
            exit(!isset($args[0]) || $args[0] === '--help'? 0 : 1);
        }

        $actionName = preg_replace('/[^A-Za-z]/', '', $args[0]);

        $templateRoot = getcwd();
        $actionsDir = $templateRoot . DIRECTORY_SEPARATOR . 'actions';
        $filePath = $actionsDir . DIRECTORY_SEPARATOR . "{$actionName}Action.php";

        if (!is_dir($actionsDir) && !mkdir($actionsDir, 0775, true)) {
            fwrite(STDERR, "Error: Could not create directory: $actionsDir\n");
            exit(1);
        }

        if (file_exists($filePath)) {
            fwrite(STDERR, "Error: Action already exists at $filePath\n");
            exit(1);
        }

        $stub = <<<PHP
        <?php

        namespace Actions;

        use ofc\\RadAction;

        class {$actionName}Action extends RadAction
        {
            /**
             * @var string WordPress hook to attach to.
             */
            protected string \$hookName = '';

            /**
             * @var int Hook priority.
             */
            protected int \$priority = 10;

            /**
             * Your action callback.
             */
            public function callback()
            {
                //
            }
        }

        PHP;

        if (file_put_contents($filePath, $stub) === false) {
            fwrite(STDERR, "Error: Unable to write file: $filePath\n");
            exit(1);
        }

        echo "Action created: $filePath".PHP_EOL;
    }

    public static function getHelp(): string
    {
        return <<<HELP
Usage:
  rad make:action <ActionName>

Description:
  Creates a new Action class inside your current WordPress theme:
  {theme}/actions/<ActionName>Action.php
  TODO: link to the docs page.

Example:
  rad make:action DoThisOnInit
HELP;
    }
}
