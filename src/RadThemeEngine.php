<?php

namespace ofc;

class RadThemeEngine
{
    public static function wpHeader()
    {
        return function ($template, $context, $args, $source) {
            return self::getFromBuffer("wp_head");
        };
    }

    public static function wpTitle()
    {
        return function ($template, $context, $args, $source) {
            return wp_title('|', false, 'right') . get_bloginfo("name");
        };
    }

    public static function wpFooter()
    {
        return function ($template, $context, $args, $source) {
            return self::getFromBuffer("wp_footer");
        };
    }

    public static function bodyClasses()
    {
        return function ($template, $context, $args, $source) {
            return self::getFromBuffer("body_class");
        };
    }

    public static function jsonEncode()
    {
        return function ($template, $context, $args, $source) {
            return json_encode($context->get($args));
        };
    }

    public static function jsonAccess()
    {
        return function ($template, $context, $args, $source) {
            $parts = explode(".", $args);
            if (count($parts) != 2) {
                return "Invalid use of json-access";
            }
            $data = json_decode($context->get($parts[0]), true);
            return $data[$parts[1]];
        };
    }

    public static function processFlex()
    {
        return function ($template, $context, $args, $source) {
            $output = "";
            die(var_dump($context->get($args)));
            $groups = json_decode($context->get($args));
            foreach ($groups as $g) {
                $data = [];
                foreach ($g->fields as $f) {
                    $data[$f->name] = $f->value;
                }
                $output .= site()->render($g->tpl, $data);
            }
            return $output;
        };
    }

    public static function nl2br()
    {
        return function ($template, $context, $args, $source) {
            return nl2br($context->get($args));
        };
    }

    private static function getFromBuffer($func)
    {
        ob_start();
        $func();
        $output = ob_get_clean();
        return $output;
    }
}
