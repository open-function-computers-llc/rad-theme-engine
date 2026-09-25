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

                // ACF flexible fields carry `acf_fc_layout`; the CompanionFields
                // flexible type carries `_layout` (the human layout name, which we
                // slug into the template file name).
                if (isset($g["acf_fc_layout"])) {
                    $layout = $g["acf_fc_layout"];
                } elseif (isset($g["_layout"])) {
                    $layout = CompanionFields::layoutSlug($g["_layout"]);
                } else {
                    $output .= "Sorry, one of your items is missing a layout key (`acf_fc_layout` or `_layout`):<br /><br />".print_r($g, true);
                    continue;
                }

                $output .= site()->render(site()->getFlexFilePrefix().$layout, $g);
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

    public static function pagination()
    {
        return function ($template, $context, $args, $source) {
            return site()->renderTemplate(<<<HTML
                <nav class="pagination-links">
                    <ul>
                        {{#if older }}
                            <li><a href="{{ older }}">Next</a></li>
                        {{/if}}
                        {{#if newer }}
                            <li><a href="{{ newer }}">Previous</a></li>
                        {{/if}}
                    </ul>
                </nav>
            HTML, site()->getPaginationLinks());
        };
    }

    public static function acfOption()
    {
        return function ($template, $context, $args, $source) {
            return get_field($args, "options");
        };
    }

    /**
     * Resolve a companion-YAML site option in a template, mirroring acfOption.
     *
     *   {{#radOption primary_phone}}                    -> single configured page
     *   {{#radOption site-settings primary_phone}}      -> explicit page + field
     *   {{#radOption site-settings}}                    -> whole page as a map
     *
     * The first arg is the options page name (as named in config `options-pages`),
     * the second (optional) is the field name.
     */
    public static function radOption()
    {
        return function ($template, $context, $args, $source) {
            $parts = preg_split('/\s+/', trim((string) $args), 2);
            $first = $parts[0] ?? '';

            if ($first === '') {
                return '';
            }

            // Two args: page + field.
            if (count($parts) === 2) {
                $value = site()->getOption($parts[0], $parts[1]);
                return is_string($value) ? $value : json_encode($value);
            }

            // Single arg: could be "page field" was not given, so treat the one
            // token as either a field on the (single) configured page, or a page
            // name (returns the whole page). We can't know both, so resolve as a
            // field first against every configured page, then as a whole page.
            $resolved = site()->resolveRadOptionToken($first);
            return is_string($resolved) ? $resolved : json_encode($resolved);
        };
    }

    public static function count()
    {
        return function ($template, $context, $args, $source) {
            return count($context->get($args));
        };
    }

    public static function queryCount()
    {
        global $wp_query;
        return function ($template, $context, $args, $source) use ($wp_query) {
            return $wp_query->found_posts;
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
