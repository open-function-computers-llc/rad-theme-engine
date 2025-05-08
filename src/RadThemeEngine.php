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

            if (!is_iterable($context->get($args))) {
                return "Sorry, the item you passed to the flex helper is not iterable.";
            }

            $groups = $context->get($args);
            foreach ($groups as $g) {
                if (!is_array($g)) {
                    $output .= "Sorry, one of your items is not compatible with the flex helper. Here is the details:<br /><br />".print_r($g, true);
                    continue;
                }

                if (!isset($g["acf_fc_layout"])) {
                    $output .= "Sorry, one of your items is missing the `acf_fc_layout` key:<br /><br />".print_r($g, true);
                    continue;
                }

                $output .= site()->render(site()->getFlexFilePrefix().$g["acf_fc_layout"], $g);
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

    public static function assetURL()
    {
        return function ($template, $context, $args, $source) {
            return site()->getAssetURL($args);
        };
    }

    public static function assetContents()
    {
        return function ($template, $context, $args, $source) {
            return site()->getAssetContents($args);
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
