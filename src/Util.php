<?php

namespace ofc;

class Util
{
    /**
     * slugify
     * modified from https://lucidar.me/en/web-dev/how-to-slugify-a-string-in-php/
     *
     * @param string $str
     * @return string
     */
    public static function slugify(string $str) : string
    {
        $text = strip_tags($str);
        $text = str_replace(" & ", " and ", $text);
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        setlocale(LC_ALL, 'en_US.utf8');
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim(strtolower($text), '-');
        $text = preg_replace('~-+~', '-', $text);
        if (empty($text)) {
            return 'n-a';
        }
        return $text;
    }

    /**
     * snakify
     * basically the same as slugify but with _ instead of -
     *
     * @param string $string
     * @return string
     */
    public static function snakeify(string $string) :string
    {
        return str_replace("-", "_", self::slugify($string));
    }

    public static function processFieldGroup($fieldGroup)
    {
        add_action('admin_head-post.php', function () use ($fieldGroup) {
            global $post;
            if ($fieldGroup) {
                echo site()->renderTemplate(FieldHTML::metaBoxToggler(["WYSIWYG"]), [
                    "group-name" => self::slugify($fieldGroup[0]),
                    "for" => "page",
                    "hidden" => ["WYSIWYG"],
                ]);
            }
        });

        add_action('add_meta_boxes', function () use ($fieldGroup) {
            if (count($fieldGroup) < 1) {
                return;
            }

            $name = $fieldGroup[0];
            array_shift($fieldGroup);
            foreach ($fieldGroup as $group) {
                // conditionally show/hide the box
                $slugName = self::slugify($name);

                add_meta_box(
                    $slugName,
                    $name,
                    function ($post) use ($fieldGroup, $group) {
                        foreach ($fieldGroup as $field) {
                            // sanatize media fields
                            if ($field["type"] === "image") {
                                if (!isset($field["store"])) {
                                    $field["store"] = "json";
                                }
                            }

                            echo site()->renderTemplate(FieldHTML::template($field["type"], get_post_meta($post->ID, "rad_".$field['name'], true), $post->ID, $field), [
                                "value" => get_post_meta($post->ID, "rad_".$field['name'], true),
                                "id" => $post->ID,
                                "field" => $field,
                            ])."<hr />";
                        }
                    },
                    'page',
                    'advanced',
                );
            }
        });

        add_action('save_post', function ($post_id) use ($fieldGroup) {
            if (!isset($fieldGroup['fields'])) {
                return;
            }
            foreach ($fieldGroup['fields'] as $field) {
                if (!isset($_POST['rad_'.$field['name']])) {
                    continue;
                }
                update_post_meta($post_id, 'rad_'.$field['name'], wp_kses_post($_POST['rad_'.$field['name']]));
            }
        });
    }
}
