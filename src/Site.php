<?php

namespace ofc;

use PostTypes\PostType;
use Handlebars\Handlebars;
use Handlebars\Loader\FilesystemLoader;

class Site
{
    private $config;
    private $hb;

    public function __construct($config = [])
    {
        if ($config == [] && file_exists(TEMPLATEPATH . "/config.php")) {
            $config = include(TEMPLATEPATH . "/config.php");
        }

        $this->config = $config;
        $this->bootstrap();
    }

    private function bootstrap()
    {
        // disable various things
        if (isset($this->config["disable"])) {
            $this->disable($this->config["disable"]);
        }
        // enable various things
        if (isset($this->config["enable"])) {
            $this->enable($this->config["enable"]);
        }

        // adjust wordpress filters
        $this->processFilters();

        // create wordpress menu locations
        $this->registerMenuLocations();

        // initialize handlebars
        $this->setUpHandlebars();

        // register custom post types
        $this->registerCPTs();
    }

    private function registerCPTs()
    {
        if (!array_key_exists('custom-post-types', $this->config)) {
            return;
        }

        foreach ($this->config['custom-post-types'] as $cpt) {
            if (!isset($cpt['slug'])) {
                dd($cpt);
            }
            $options = $cpt['options'] ?? [];
            $newCpt = new PostType($cpt['slug'], $options);

            if (isset($cpt['icon'])) {
                $newCpt->icon($cpt['icon']);
            }
            $newCpt->register();
        }
    }

    private function disable(array $keys)
    {
        foreach ($keys as $key) {
            if ($key === "editor") {
                define('DISALLOW_FILE_EDIT', true);
                continue;
            }
        }
    }

    private function enable(array $keys)
    {
        foreach ($keys as $key) {
            if ($key === "post-thumbnails") {
                add_theme_support('post-thumbnails');
            }
            if ($key === "menus") {
                add_theme_support('menus');
            }
        }
    }

    private function processFilters()
    {
        // handle "guest" html body class
        $guestClass = "guest";
        if (array_key_exists("guest-class", $this->config)) {
            $guestClass = $this->config["guest-class"];
        }
        if (!is_null($guestClass)) {
            add_filter('body_class', function ($classes) use ($guestClass) {
                return in_array('logged-in', $classes) ? $classes : [$guestClass, ...$classes];
            });
        }
    }

    private function registerMenuLocations()
    {
        if (!array_key_exists("menu-locations", $this->config)) {
            return;
        }

        if (!is_array($this->config["menu-locations"])) {
            // TODO: show a helpful error message that notifies the developer this is set up wrong
            return;
        }

        $menus = $this->config["menu-locations"];
        add_action('after_setup_theme', function () use ($menus) {
            register_nav_menus($menus);
        });
    }

    private function setUpHandlebars()
    {
        if ($this->config["handlebars"] === false) {
            return;
        }

        $fileExtension = "tpl";
        if (is_array($this->config["handlebars"]) &&
            array_key_exists("template-extension", $this->config["handlebars"])
        ) {
            $fileExtension = $this->config["handlebars"]["template-extension"];
        }
        $partialsDir = get_template_directory()."/tpl";
        $partialsLoader = new FilesystemLoader($partialsDir, ["extension" => $fileExtension]);

        $this->hb = new Handlebars([
            "loader" => $partialsLoader,
            "partials_loader" => $partialsLoader,
            "enableDataVariables" => true,
        ]);

        foreach ($this->config["handlebars"]["additional-helpers"] as $name => $callback) {
            $this->hb->addHelper($name, $callback);
        }
    }

    public function view(string $fileName, array $data = []) : string
    {
        return $this->hb->render($fileName, $data);
    }

    public function getPost($id, $fields = [])
    {
        $p = get_post($id);
        $meta = get_post_meta($p->ID);

        if ($fields == []) {
            return [
                'post' => $p,
                'meta' => $meta,
            ];
        }

        $output = [];
        $categories = [];
        $taxonomies = [];
        foreach ($fields as $key) {
            // handle meta keys
            if (substr($key, 0, 5) === "meta.") {
                $output[substr($key, 5)] = get_post_meta($p->ID, substr($key, 5), true);
                continue;
            }

            if (substr($key, 0, 4) === "acf.") {
                $output[substr($key, 4)] = get_field(substr($key, 4), $p->ID);
                continue;
            }

            if (substr($key, 0, 11) === "categories.") {
                if (count($categories) == 0) {
                    $categories = get_taxonomies($p->ID);
                }
                continue;
            }

            if (substr($key, 0, 9) === "taxonomy.") {
                $taxonomyKey = $this->parseTaxKey($key);
                $values = explode(",", $this->parseTaxValues($key));
                if (!isset($taxonomies[$taxonomyKey])) {
                    $taxonomies[$taxonomyKey] = $this->getPostTaxonomy($p, $taxonomyKey);
                    $output[$taxonomyKey] = [];
                }

                foreach ($taxonomies[$taxonomyKey] as $tax) {
                    $taxonomyDTO = [];
                    foreach ($values as $val) {
                        if ($val === "id" || $val === "term_id") {
                            $taxonomyDTO["id"] = $tax->term_id;
                            continue;
                        }

                        if ($val === "name") {
                            $taxonomyDTO["name"] = $tax->name;
                            continue;
                        }

                        if ($val === "slug") {
                            $taxonomyDTO["slug"] = $tax->slug;
                            continue;
                        }

                        if ($val === "description") {
                            $taxonomyDTO["description"] = $tax->description;
                            continue;
                        }
                    }
                    $output[$taxonomyKey][] = $taxonomyDTO;
                }
                continue;
            }

            $output[$key] = $p->$key;
        }
        return $output;
    }

    public function getPosts($args = [], $fields = [])
    {
        $args = $this->processArgs($args);
        $posts = get_posts($args);

        if ($fields == []) {
            return [
                'posts' => $posts,
            ];
        }

        $output = [];
        $categories = [];

        foreach ($posts as $post) {
            foreach ($fields as $key) {
                if (substr($key, 0, 4) === "acf.") {
                    $addition[substr($key, 4)] = get_field(substr($key, 4), $post->ID);
                } elseif (substr($key, 0, 11) === "categories.") {
                    if (count($categories) == 0) {
                        $categories = wp_get_post_categories($post->ID);
                    }
                } else {
                    $addition[$key] = $post->$key;
                }
            }
            $output[] = $addition;
        }
        return $output;
    }

    private function processArgs(array $args) : array
    {
        // wordpress default
        $args = array_merge(
            $args,
            [
                'numberposts' => 5,
                'category' => 0,
                'orderby' => 'date',
                'order' => 'DESC',
                'include' => array(),
                'exclude' => array(),
                'meta_key' => '',
                'meta_value' => '',
                'post_type' => 'post',
                'suppress_filters' => true,
            ]
        );

        if (isset($args["type"])) {
            $args["post_type"] = $args["type"];
        }
        return $args;
    }

    private function parseTaxKey(string $paramater) : string
    {
        $parts = explode(".", $paramater);
        if (count($parts) != 3) {
            return $paramater;
        }
        return $parts[1];
    }

    private function parseTaxValues(string $paramater) : string
    {
        $parts = explode(".", $paramater);
        if (count($parts) != 3) {
            return $paramater;
        }
        return $parts[2];
    }

    public function getPostTaxonomy($post, $taxonomyKey)
    {
        return get_the_terms($post->ID, $taxonomyKey);
    }
}
