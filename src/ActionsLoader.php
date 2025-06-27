<?php

namespace ofc;

use ReflectionClass;

class ActionsLoader
{
    public static function load()
    {
        $actions_dir = get_template_directory() . '/actions';

        if (!is_dir($actions_dir)) {
            return;
        }

        foreach (scandir($actions_dir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $file_path = $actions_dir . '/' . $file;
            require_once $file_path;

            // Guess class name from file (assuming PSR-4 mapping matches)
            // You can hardcode or use reflection if needed
            $class_name = self::getClassFromFile($file);

            if (!class_exists($class_name)) {
                continue;
            }

            $reflection = new ReflectionClass($class_name);

            if (!$reflection->isInstantiable()) {
                continue;
            }

            $required_properties = ['hookName', 'priority'];
            foreach ($required_properties as $prop) {
                if (!$reflection->hasProperty($prop)) {
                    continue 2;
                }
            }

            if (!$reflection->hasMethod('callback')) {
                continue;
            }

            $instance = new $class_name();

            if ($instance->wrapHookInInit()) {
                add_action("init", function () use ($instance) {
                    add_action($instance->getHookName(), [$instance, 'callback'], $instance->getPriority());
                }, $instance->getPriority());
                continue;
            }
            add_action($instance->getHookName(), [$instance, 'callback'], $instance->getPriority());
        }
    }

    private static function getClassFromFile($file)
    {
        $class_base = pathinfo($file, PATHINFO_FILENAME);
        return 'Actions\\' . $class_base;
    }
}
