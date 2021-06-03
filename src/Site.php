<?php

namespace ofc;

use PostTypes\PostType;
use Handlebars\Handlebars;
use Handlebars\Loader\FilesystemLoader;
use PostTypes\Taxonomy;
use WP_Post;

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

        // add mix static assets
        $this->includeManifestFiles();

        // register ACF options pages
        $this->addOptionsPages();
    }

    private function includeManifestFiles()
    {
        $manifestFile = get_template_directory() . "/dist/mix-manifest.json";
        if (!file_exists($manifestFile)) {
            return;
        }

        $items = json_decode(file_get_contents($manifestFile), true);
        foreach ($items as $file => $version) {
            $extension = end(explode(".", $file));
            $tag = $this->stringify($file);
            if ($extension === "js") {
                add_action('wp_enqueue_scripts', function () use ($file, $version, $tag) {
                    wp_register_script($tag, get_template_directory_uri() . '/dist/' . $version, [], '', false);
                    wp_enqueue_script($tag);
                });
                continue;
            }

            if ($extension === "css") {
                add_action('wp_enqueue_scripts', function () use ($version, $tag) {
                    wp_enqueue_style($tag, get_template_directory_uri() . '/dist/' . $version);
                });
                continue;
            }
        }
    }

    private function registerCPTs()
    {
        if (!array_key_exists('custom-post-types', $this->config)) {
            return;
        }

        $cptTaxonomies = [];
        foreach ($this->config['custom-post-types'] as $cpt) {
            if (!isset($cpt['slug'])) {
                dd($cpt);
            }
            $options = $cpt['options'] ?? [];
            $newCpt = new PostType($cpt['slug'], $options);

            if (isset($cpt['icon'])) {
                $newCpt->icon($cpt['icon']);
            }

            if (isset($cpt["taxonomies"])) {
                foreach ($cpt["taxonomies"] as $customTaxonomy) {
                    $cptTaxonomies[] = $customTaxonomy;
                    $newCpt->taxonomy($customTaxonomy);
                }
            }
            $newCpt->register();
        }

        if (count($cptTaxonomies)) {
            foreach ($cptTaxonomies as $customTaxonomy) {
                $tax = new Taxonomy($customTaxonomy);
                $tax->options([
                    'hierarchical' => false,
                ]);
                $tax->register();
            }
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

    private function addOptionsPages()
    {
        if (!array_key_exists('options-pages', $this->config)) {
            return;
        }

        if (!function_exists("acf_add_options_page")) {
            $this->adminError("You can't register options pages without the Pro version of Advanced Custom Fields.");
        }

        $i = 100;
        foreach ($this->config["options-pages"] as $pageTitle) {
            acf_add_options_page([
                "page_title" => $pageTitle,
                "menu_title" => $pageTitle,
                "menu_slug" => $this->stringify($pageTitle),
                'capability' => 'edit_posts',
                "position" => $i,
            ]);
            $i++;
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
        if (!file_exists($partialsDir) && !is_dir($partialsDir)) {
            $this->adminError("Your Handlebars directory is not setup correctly or doesn't exist.");
            return;
        }
        $partialsLoader = new FilesystemLoader($partialsDir, ["extension" => $fileExtension]);

        $this->hb = new Handlebars([
            "loader" => $partialsLoader,
            "partials_loader" => $partialsLoader,
            "enableDataVariables" => true,
        ]);

        if (isset($this->config["handlebars"]["additional-helpers"])) {
            foreach ($this->config["handlebars"]["additional-helpers"] as $name => $callback) {
                $this->hb->addHelper($name, $callback);
            }
        }
    }

    public function view(string $fileName, array $data = []) : string
    {
        return $this->hb->render($fileName, $data);
    }

    public function getPost($idOrPost, $fields = [])
    {
        if (is_numeric($idOrPost)) {
            $p = get_post($idOrPost);
        } elseif ($idOrPost instanceof WP_Post) {
            $p = $idOrPost;
        } else {
            return [
                "error" => "You must pass an ID or WP_Post to this method"
            ];
        }
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

            // let's keep our sanity here...
            // the following keys are here for convienance. WP names things so weirdly!
            if ($key === "id") {
                $output[$key] = $p->ID;
                continue;
            }
            if ($key === "title" || $key === "name") {
                $output[$key] = $p->post_title;
                continue;
            }
            if ($key === "thumbnail") {
                $output[$key] = get_the_post_thumbnail_url($p->ID);
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
        foreach ($posts as $p) {
            $output[] = $this->getPost($p, $fields);
        }
        return $output;
    }

    public function getTerm($slug, $fields = [])
    {
        $args = [
            'taxonomy' => $slug,
            'hide_empty' => false,
        ];
        $results = get_terms($args);

        if ($fields == []) {
            return $results;
        }

        $output = [];
        foreach ($results as $term) {
            $append = [];
            foreach ($fields as $key) {
                $oldKey = $key;
                if ($key == "id") {
                    $key = "term_id";
                }
                if ($key == "title") {
                    $key = "name";
                }

                if ($oldKey != $key) {
                    $append[$oldKey] = $term->$key;
                    continue;
                }
                $append[$key] = $term->$key;
            }
            $output[] = $append;
        }
        return $output;
    }

    private function processArgs(array $args) : array
    {
        // wordpress default
        $args = array_merge(
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
            ],
            $args
        );

        if (isset($args["type"])) {
            $args["post_type"] = $args["type"];
        }

        if (isset($args["title"])) {
            $args["title"] = urldecode($args["title"]);
        }

        if (isset($args["s"])) {
            $args["s"] = urldecode($args["s"]);
        }

        // process custom taxonomy
        $taxQuery = [];
        foreach ($args as $paramKey => $paramValue) {
            if (substr($paramKey, 0, 4) === "tax.") {
                $tax = substr($paramKey, 4);
                $taxQuery[] = [
                    "taxonomy" => $tax,
                    "field" => "term_id",
                    "terms" => explode(",", $paramValue)
                ];
            }
        }
        if ($taxQuery != []) {
            $args['tax_query'] = $taxQuery;
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

    public function setPostMeta($postID, $data)
    {
        foreach ($data as $metaKey => $metaValue) {
            update_post_meta($postID, $metaKey, $metaValue);
        }
    }

    /**
     * add an error to the wordpress backend
     *
     * @param string $message
     */
    private function adminError(string $message)
    {
        $key = $this->stringify($message);
        add_action('admin_notices', function ($messages) use ($message, $key) {
                add_settings_error($key, '', "OFC Site Error: $message", 'error');
                settings_errors($key);
            return $messages;
        });
    }

    public function renderMenu($menuLocation)
    {
        return wp_nav_menu([
            "theme_location" => $menuLocation,
            "echo" => false,
        ]);
    }

    private function stringify(string $thing) : string
    {
        $bad = [" ", "/"];
        $good = ["_", ""];
        return strtolower(str_replace($bad, $good, $thing));
    }

    public function getAssetURL(string $filename) : string
    {
        if (!file_exists(get_template_directory()."/assets/$filename")) {
            $this->adminError("Requested asset $filename doesn't exist.");
            return "";
        }

        return get_template_directory_uri()."/assets/$filename";
    }
}
