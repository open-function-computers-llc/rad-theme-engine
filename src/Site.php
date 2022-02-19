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
    private $partialsDir;
    private $fileExtension;
    private $templateDirectory;

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

        // enable various things, starting with TinyMCE
        if (isset($this->config["tinyMCEAdditions"]) || isset($this->config["tiny-mce-additions"])) {
            $this->addEditorStyles();
        }
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

    private function addEditorStyles()
    {
        $formats = [];

        if (isset($this->config["tinyMCEAdditions"])) {
            $formats = $this->config["tinyMCEAdditions"];
        }

        if (isset($this->config["tiny-mce-additions"])) {
            $formats = $this->config["tiny-mce-additions"];
        }

        $defaults = [
            [
                'title' => 'Headings',
                'items' => [
                    [
                        'title' => 'Heading 1',
                        'format' => 'h1',
                    ],
                    [
                        'title' => 'Heading 2',
                        'format' => 'h2',
                    ],
                    [
                        'title' => 'Heading 3',
                        'format' => 'h3',
                    ],
                    [
                        'title' => 'Heading 4',
                        'format' => 'h4',
                    ],
                    [
                        'title' => 'Heading 5',
                        'format' => 'h5',
                    ],
                    [
                        'title' => 'Heading 6',
                        'format' => 'h6',
                    ],
                ],
            ],
            [
                'title' => 'Inline',
                'items' => [
                    [
                        'title' => 'Bold',
                        'format' => 'bold',
                        'icon' => 'bold',
                    ],
                    [
                        'title' => 'Italic',
                        'format' => 'italic',
                        'icon' => 'italic',
                    ],
                    [
                        'title' => 'Underline',
                        'format' => 'underline',
                        'icon' => 'underline',
                    ],
                    [
                        'title' => 'Strikethrough',
                        'format' => 'strikethrough',
                        'icon' => 'strikethrough',
                    ],
                    [
                        'title' => 'Superscript',
                        'format' => 'superscript',
                        'icon' => 'superscript',
                    ],
                    [
                        'title' => 'Subscript',
                        'format' => 'subscript',
                        'icon' => 'subscript',
                    ],
                    [
                        'title' => 'Code',
                        'format' => 'code',
                        'icon' => 'code',
                    ],
                ],
            ],
            [
                'title' => 'Blocks',
                'items' => [
                    [
                        'title' => 'Paragraph',
                        'format' => 'p',
                    ],
                    [
                        'title' => 'Blockquote',
                        'format' => 'blockquote',
                    ],
                    [
                        'title' => 'Div',
                        'format' => 'div',
                    ],
                    [
                        'title' => 'Pre',
                        'format' => 'pre',
                    ],
                ],
            ],
            [
                'title' => 'Alignment',
                'items' => [
                    [
                        'title' => 'Left',
                        'format' => 'alignleft',
                        'icon' => 'alignleft',
                    ],
                    [
                        'title' => 'Center',
                        'format' => 'aligncenter',
                        'icon' => 'aligncenter',
                    ],
                    [
                        'title' => 'Right',
                        'format' => 'alignright',
                        'icon' => 'alignright',
                    ],
                    [
                        'title' => 'Justify',
                        'format' => 'alignjustify',
                        'icon' => 'alignjustify',
                    ],
                ],
            ],
        ];

        add_filter('tiny_mce_before_init', function ($settings) use ($formats, $defaults) {
            $settings['style_formats'] = json_encode(array_merge($defaults, [
                [
                    "title" => "Custom",
                    "items" => $formats
                ]
            ]));
            return $settings;
        });
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
                $this->adminError("To register a new custom post type, you must set the `slug` key.");
                continue;
            }
            $options = $cpt['options'] ?? [];
            $names = $this->generateLabels($cpt['slug']);
            $newCpt = new PostType($cpt['slug'], $options, $names);

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

            // disable post type things here
            if (isset($cpt["disable"]) && is_array($cpt["disable"])) {
                foreach ($cpt["disable"] as $feature) {
                    if (strtolower($feature) === "yoast") {
                        add_action('add_meta_boxes', fn () => remove_meta_box('wpseo_meta', $cpt['slug'], 'normal'), 100);
                        continue;
                    }
                }
            }

            // set up any ACF CPT options pages here
            if (isset($cpt["options-pages"])) {
                if (!function_exists("acf_add_options_page")) {
                    $this->adminError("You can't register options pages without the Pro version of Advanced Custom Fields.");
                    continue;
                }
                foreach ($cpt["options-pages"] as $optionsPageTitle) {
                    acf_add_options_page([
                        "page_title" => $optionsPageTitle,
                        "menu_title" => $optionsPageTitle,
                        "menu_slug" => $this->stringify($optionsPageTitle),
                        'capability' => 'edit_posts',
                        "position" => 100,
                        "parent_slug" => "edit.php?post_type=" . $cpt["slug"]
                    ]);
                }
            }
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
                continue;
            }
            if ($key === "menus") {
                add_theme_support('menus');
                continue;
            }
            if ($key === "styleselect") {
                add_filter('mce_buttons_2', function ($buttons) {
                    return array_merge(['styleselect'], $buttons);
                });
                continue;
            }

            $this->adminError("Couldn't enable feature `$key`");
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

        $this->fileExtension = "tpl";
        if (is_array($this->config["handlebars"]) &&
            array_key_exists("template-extension", $this->config["handlebars"])
        ) {
            $this->fileExtension = $this->config["handlebars"]["template-extension"];
        }

        $this->templateDirectory = "tpl";
        if (is_array($this->config["handlebars"]) &&
            array_key_exists("template-directory", $this->config["handlebars"])
        ) {
            $this->templateDirectory = $this->config["handlebars"]["template-directory"];
        }

        $this->partialsDir = get_template_directory()."/".$this->templateDirectory;
        if (!file_exists($this->partialsDir) && !is_dir($this->partialsDir)) {
            $this->adminError("Your Handlebars directory is not setup correctly or doesn't exist.");
            return;
        }
        $partialsLoader = new FilesystemLoader($this->partialsDir, ["extension" => $this->fileExtension]);

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

    /**
     * render
     * See $this->view()
     *
     * @param string $fileName
     * @param array $data
     * @return string
     */
    public function render(string $fileName, array $data = []) : string
    {
        return $this->view($fileName, $data);
    }

    /**
     * view
     * Invoke handlebar rendering engine for a given template file and data array
     *
     * @param string $fileName
     * @param array $data
     * @return string
     */
    public function view(string $fileName, ?array $data = []) : string
    {
        if (is_null($data) && ($this->config["debug"] === true)) {
            return "<pre>The data for view {$fileName}.{$this->fileExtension} is null.</pre>";
        }

        $filePath = $this->partialsDir."/".$fileName.".".$this->fileExtension;
        if (!file_exists($filePath)) {
            if ($this->config["debug"] === true) {
                return "<pre>View {$fileName}.{$this->fileExtension} does not exist.\nData:\n"
                    .print_r($data, true)
                    ."</pre>";
            }
            return "";
        }
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

        if ($fields == []) {
            return [
                'post' => $p,
                'meta' => get_post_meta($p->ID),
            ];
        }

        $output = [];
        $categories = [];
        $taxonomies = [];
        foreach ($fields as $key) {
            // handle url/permalink
            if ($key === "url" || $key === "permalink") {
                $output[$key] = get_permalink($p->ID);
                continue;
            }

            // handle post content
            if ($key === "content") {
                $output["content"] = apply_filters('the_content', get_the_content($p->ID));
                continue;
            }

            // handle post excerpt
            if ($key === "excerpt") {
                $output["excerpt"] = get_the_excerpt($p->ID);
                continue;
            }

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
                    $categories = wp_get_post_categories($p->ID, ["fields" => "all"]);
                    $attrs = explode(",", substr($key, 11));

                    foreach ($categories as $cat) {
                        $dataToAppend = [];
                        foreach ($attrs as $key) {
                            $oldKey = $key;
                            if ($key == "id") {
                                $key = "term_id";
                            }
                            if (isset($cat->$key)) {
                                $dataToAppend[$oldKey] = $cat->$key;
                            }
                        }
                        $output["categories"][] = $dataToAppend;
                    }
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

                if (!is_array($taxonomies[$taxonomyKey])) {
                    continue;
                }

                foreach ($taxonomies[$taxonomyKey] as $tax) {
                    $taxonomyDTO = [];
                    foreach ($values as $val) {
                        if ($val === "id" || $val === "term_id") {
                            $taxonomyDTO["id"] = $tax->term_id;
                            continue;
                        }

                        if ($val === "link") {
                            $taxonomyDTO["link"] = get_term_link($tax);
                            continue;
                        }

                        if ($val === "name") {
                            $taxonomyDTO["name"] = $tax->name;
                            continue;
                        }

                        if ($val === "title") {
                            $taxonomyDTO["title"] = $tax->name;
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

    /**
     * getCurrentPosts
     * see getDefaultPosts
     *
     * @deprecated 1.0.15
     *
     * @param array $fields
     * @return array
     */
    public function getCurrentPosts($fields = []) : array
    {
        return $this->getDefaultPosts($fields);
    }

    /**
     * getDefaultPosts
     * get all the posts for the current page as defined by wordpress' weird rules for archive pages
     *
     * @param array $fields
     * @return array
     */
    public function getDefaultPosts($fields = []) : array
    {
        $output = [];
        if (!have_posts()) {
            return $output;
        }
        while (have_posts()) {
            the_post();
            global $post;
            $output[] = $this->getPost($post, $fields);
        }
        return $output;
    }

    public function getTerm($slug, $fields = [])
    {
        $args = [
            'taxonomy' => $slug,
            'hide_empty' => false,
            'suppress_filter' => true,
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

                if ($key === "url" || $key === "permalink") {
                    $append[$key] = get_term_link($term);
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
            if (!(substr($paramKey, 0, 4) === "tax." || substr($paramKey, 0, 9) === "taxonomy.")) {
                continue;
            }

            if (substr($paramKey, 0, 4) === "tax.") {
                $tax = substr($paramKey, 4);
            }

            if (substr($paramKey, 0, 9) === "taxonomy.") {
                $tax = substr($paramKey, 9);
            }

            $field = "slug";
            $values = explode(",", $paramValue);
            $allValuesAreNumeric = false;
            foreach ($values as $v) {
                if (is_numeric($v)) {
                    $allValuesAreNumeric = true;
                }
            }

            if ($allValuesAreNumeric) {
                $field = "term_id";
            }

            $taxQuery[] = [
                "taxonomy" => $tax,
                "field" => $field,
                "terms" => $values,
            ];
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

    private function generateLabels(string $slug) : array
    {
        return [
            'name' => $this->humanize($slug, true),
            'singular_name' => $this->humanize($slug, false),
            'menu_name' => $this->humanize($slug, true),
            'all_items' => "All " . $this->humanize($slug, true),
            'add_new' => "Add New {$this->humanize($slug, false)}",
            'add_new_item' => "Add New {$this->humanize($slug, false)}",
            'edit_item' => "Edit {$this->humanize($slug, false)}",
            'new_item' => "New {$this->humanize($slug, false)}",
            'view_item' => "View {$this->humanize($slug, false)}",
            'search_items' => "Search {$this->humanize($slug, true)}",
            'not_found' => "No {$this->humanize($slug, true)} found",
            'not_found_in_trash' => "No {$this->humanize($slug, true)} found in Trash",
            'parent_item_colon' => "Parent {$this->humanize($slug, false)}:",
        ];
    }

    private function humanize(string $word, bool $makePlural = false) : string
    {
        $humanized = ucwords(strtolower(str_replace(['-', '_'], ' ', $word)));

        if (!$makePlural) {
            return $humanized;
        }

        if (strtolower(substr($humanized, strlen($humanized)-1, 1)) == "s") {
            return $humanized . "es";
        }
        if (strtolower(substr($humanized, strlen($humanized)-1, 1)) == "y") {
            return substr($humanized, 0, strlen($humanized)-1) . "ies";
        }
        return $humanized . "s";
    }

    public function getPaginationLinks() : array
    {
        global $wp_query;

        $output = [
            "older" => false,
            "newer" => false,
            "totalPages" => 0,
            "currentPage" => 0,
        ];

        if (is_singular()) {
            return $output;
        }

        $max_num_pages = (int) $wp_query->max_num_pages;
        $paged = get_query_var('paged');
        if (($paged + 1) < $max_num_pages) {
            $output["older"] = next_posts(0, false);
        }

        if ($max_num_pages > 1 && $paged <= $max_num_pages && $paged > 0) {
            $output["newer"] = previous_posts(false);
        }

        $output["totalPages"] = $max_num_pages;
        $output["currentPage"] = $paged;

        return $output;
    }
}
