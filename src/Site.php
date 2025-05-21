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
    public $cptSlugs = [];

    // singleton instance goes here
    private static ?Site $instance = null;

    public static function getInstance($config = []): Site
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
            self::$instance->bootstrap();
        }
        return self::$instance;
    }

    private function __construct(array $config)
    {
        if ($config == [] && file_exists(get_template_directory() . "/config.php")) {
            $config = include(get_template_directory() . "/config.php");
        }

        $this->config = $config;
    }

    private function bootstrap()
    {
        $this->checkfavicons();

        // wordpress added this style for us, but we don't want it
        add_action('wp_enqueue_scripts', function () {
            wp_dequeue_style('classic-theme-styles');
        }, 20);

        if (isset($this->config["excerpt-more-text"])) {
            add_filter('excerpt_more', fn ($more) => $this->config["excerpt-more-text"]);
        }

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

        // register custom hook callbacks
        $this->processHooks();

        // add custom shortcodes
        $this->registerShortcodes();

        // add mix static assets
        $this->includeManifestFiles();

        // register ACF options pages
        $this->addOptionsPages();

        // register custom fields that aren't through ACF
        $this->processCustomFields();

        // register any ajax callbacks
        $this->processAdminAJAX();
        $this->processGuestAJAX();
    }

    public function getFlexFilePrefix(): string
    {
        if (!isset($this->config["flex-file-prefix"])) {
            return "";
        }
        return $this->config["flex-file-prefix"]."_";
    }

    private function processGuestAJAX()
    {
        if (!isset($this->config["guest-ajax"])) {
            return;
        }
        if (!is_array($this->config["guest-ajax"])) {
            return;
        }
        foreach ($this->config["guest-ajax"] as $hook => $callback) {
            // guest ajax methos work both for guests and authed users
            add_action("wp_ajax_nopriv_$hook", function () use ($callback) {
                echo json_encode($callback());
                wp_die();
            });
            add_action("wp_ajax_$hook", function () use ($callback) {
                echo json_encode($callback());
                wp_die();
            });
        }
    }

    private function processAdminAJAX()
    {
        if (!isset($this->config["admin-ajax"])) {
            return;
        }
        if (!is_array($this->config["admin-ajax"])) {
            return;
        }
        foreach ($this->config["admin-ajax"] as $hook => $callback) {
            add_action("wp_ajax_$hook", function () use ($callback) {
                echo json_encode($callback());
                wp_die();
            });
        }
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
                add_action('wp_enqueue_scripts', function () use ($version, $tag) {
                    wp_register_script($tag, get_template_directory_uri() . '/dist' . $version, [], '', true);
                    wp_enqueue_script($tag);
                });
                continue;
            }

            if ($extension === "css" && $file != "/inline.css") {
                add_action('wp_enqueue_scripts', function () use ($version, $tag) {
                    wp_enqueue_style($tag, get_template_directory_uri() . '/dist' . $version);
                });
                continue;
            }

            if ($file == "/inline.css") {
                add_action('wp_head', function () use ($file) {
                    $css = file_get_contents(get_template_directory() . "/dist" . $file);
                    echo "<style>".$css."</style>";
                });
            }
        }
    }

    private function registerShortcodes()
    {
        if (!array_key_exists('shortcodes', $this->config)) {
            return;
        }

        if (!is_array($this->config["shortcodes"])) {
            return;
        }

        foreach ($this->config["shortcodes"] as $name => $callable) {
            add_shortcode($name, $callable);
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

            // for convenience access
            $this->cptSlugs[] = $cpt["slug"];

            $options = $cpt['options'] ?? [];
            if (isset($cpt["archive"]) && $cpt["archive"]) {
                $options["has_archive"] = Util::slugify($this->humanize($cpt["slug"], true));
            }
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
                if (is_string($cpt["options-pages"])) {
                    $cpt["options-pages"] = [$cpt["options-pages"]];
                }
                foreach ($cpt["options-pages"] as $optionsPage) {
                    if (is_string($optionsPage)) {
                        acf_add_options_page([
                            "page_title" => $optionsPage,
                            "menu_title" => $optionsPage,
                            "menu_slug" => $this->stringify($optionsPage),
                            'capability' => 'edit_posts',
                            "position" => 100,
                            "parent_slug" => "edit.php?post_type=" . $cpt["slug"]
                        ]);
                        continue;
                    }

                    if (is_array($optionsPage) && isset($optionsPage["name"]) && isset($optionsPage["parent_slug"])) {
                        acf_add_options_page([
                            "page_title" => $optionsPage["name"],
                            "menu_title" => $optionsPage["name"],
                            "menu_slug" => $this->stringify($optionsPage["name"]),
                            'capability' => 'edit_posts',
                            "position" => 100,
                            "parent_slug" => $optionsPage["parent_slug"],
                        ]);
                    }
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
            if ($key === "gutenberg") {
                add_filter('use_block_editor_for_post', '__return_false', 10);
                add_action('wp_enqueue_scripts', function () {
                    wp_dequeue_style('wp-block-library');
                    wp_dequeue_style('wp-block-library-theme');
                    wp_dequeue_style('wc-blocks-style'); // Remove WooCommerce block CSS
                    wp_dequeue_style('global-styles');
                }, 100);
                continue;
            }
            if ($key === "patterns") {
                remove_theme_support('core-block-patterns');
                add_action('admin_init', function () {
                    remove_submenu_page('themes.php', 'edit.php?post_type=wp_block');
                    remove_submenu_page('themes.php', 'site-editor.php?p=/pattern');
                    remove_submenu_page('themes.php', 'site-editor.php?p=/patterns');
                });
                continue;
            }
            if ($key === "meta-generator") {
                remove_action('wp_head', 'wp_generator');
                add_filter('the_generator', '__return_null');
                continue;
            }
            if ($key === "emojis") {
                remove_action('wp_head', 'print_emoji_detection_script', 7);
                remove_action('wp_print_styles', 'print_emoji_styles');
                continue;
            }
            if (substr($key, 0, 12) === "woocommerce." && class_exists('WooCommerce')) {
                $key = str_replace("woocommerce.", "", $key);

                if ($key === "breadcrumb") {
                    add_action('init', fn () => remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20));
                    continue;
                }
                if ($key === "sidebar") {
                    add_action('init', fn () => remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10));
                    continue;
                }
                if ($key === "result_count") {
                    add_action('init', fn () => remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20));
                    add_action('init', fn () => remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30));
                    continue;
                }
                if ($key === "page_title") {
                    add_action('init', fn () => add_filter('woocommerce_show_page_title', '__return_false'));
                    continue;
                }

                continue;
            }
            if ($key === "customizer") {
                add_action('init', function () {
                    add_filter('map_meta_cap', function ($capabilities = [], $c = '', $user_id = 0, $args = []) {
                        if ($c == 'customize') {
                            return ['nope'];
                        }
                        return $capabilities;
                    }, 10, 4);
                }, 10);

                add_action('admin_init', function () {
                    remove_action('plugins_loaded', '_wp_customize_include', 10);
                    remove_action('admin_enqueue_scripts', '_wp_customize_loader_settings', 11);

                    add_action('load-customize.php', function () {
                        wp_die("The customizer is disabled via your current theme configuration.");
                    });
                }, 10);
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
            if ($key === "svg") {
                add_filter('upload_mimes', function ($file_types) {
                    $file_types['svg'] = 'image/svg+xml';
                    return $file_types;
                });
                continue;
            }
            if ($key === "woocommerce") {
                add_theme_support('woocommerce');
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

        // change excerpt langth
        if (array_key_exists("excerpt-length", $this->config)) {
            add_filter("excerpt_length", fn () => (int) $this->config["excerpt-length"], 999);
        }
        if (array_key_exists("excerpt_length", $this->config)) {
            add_filter("excerpt_length", fn () => (int) $this->config["excerpt_length"], 999);
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
        add_action('init', function () use ($menus) {
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
            // check for child theme?
            $this->partialsDir = get_stylesheet_directory()."/".$this->templateDirectory;

            if (!file_exists($this->partialsDir) && !is_dir($this->partialsDir)) {
                $this->adminError("Your Handlebars directory is not setup correctly or doesn't exist.");
                return;
            }
            $this->adminError("Your Handlebars directory is not setup correctly or doesn't exist.");
            return;
        }
        $partialsLoader = new FilesystemLoader($this->partialsDir, ["extension" => $this->fileExtension]);

        $this->hb = new Handlebars([
            "loader" => $partialsLoader,
            "partials_loader" => $partialsLoader,
            "enableDataVariables" => true,
        ]);

        // built in helpers
        $helpers = [
            "wp-header" => \ofc\RadThemeEngine::wpHeader(),
            "wp-footer" => \ofc\RadThemeEngine::wpFooter(),
            "wp-title" => \ofc\RadThemeEngine::wpTitle(),
            "body-classes" => \ofc\RadThemeEngine::bodyClasses(),
            "json-encode" => \ofc\RadThemeEngine::jsonEncode(),
            "json-access" => \ofc\RadThemeEngine::jsonAccess(),
            "flex" => \ofc\RadThemeEngine::processFlex(),
            "nl2br" => \ofc\RadThemeEngine::nl2br(),
            "assetURL" => \ofc\RadThemeEngine::assetURL(),
            "assetUrl" => \ofc\RadThemeEngine::assetURL(),
            "assetContents" => \ofc\RadThemeEngine::assetContents(),
        ];
        foreach ($helpers as $name => $callback) {
            $this->hb->addHelper($name, $callback);
        }

        // additional helpers defined in the config file
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
    public function view(string $fileName, array $data = []) : string
    {
        $filePath = $this->partialsDir."/".$fileName.".".$this->fileExtension;
        if (!file_exists($filePath)) {
            if (isset($this->config["debug"]) && $this->config["debug"] === true) {
                return "<pre>View {$fileName}.{$this->fileExtension} does not exist.\nData:\n"
                    .print_r($data, true)
                    ."</pre>";
            }
            return "";
        }
        return $this->hb->render($fileName, $data);
    }

    /**
     * view
     * Invoke handlebar rendering engine for a given template file and data array
     *
     * @param string $fileName
     * @param array $data
     * @return string
     */
    public function renderTemplate(string $template, array $data = []) : string
    {
        $hb = new Handlebars();
        $hb->addHelper("json-encode", \ofc\RadThemeEngine::jsonEncode());
        return $hb->render($template, $data);
    }

    public function getCurrentPost($fields = [])
    {
        global $post;
        return $this->getPost($post, $fields);
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
                $output[$key] = apply_filters('the_content', get_the_content(null, false, $p->ID));
                continue;
            }

            // handle post excerpt
            if ($key === "excerpt") {
                $output[$key] = apply_filters("the_content", get_the_excerpt($p->ID));
                continue;
            }

            // handle adjacent posts
            if ($key === "nextPost") {
                $output[$key] = get_next_post();
                continue;
            }
            if ($key === "previousPost") {
                $output[$key] = get_previous_post();
                continue;
            }

            // handle adjacent post attributes
            if (substr($key, 0, 9) === "nextPost.") {
                $fields = explode(",", substr($key, 9));
                $output[substr($key, 0, 8)] = $this->getPost(get_next_post(), $fields);
                continue;
            }
            if (substr($key, 0, 13) === "previousPost.") {
                $fields = explode(",", substr($key, 13));
                $output[substr($key, 0, 12)] = $this->getPost(get_previous_post(), $fields);
                continue;
            }

            if (substr($key, 0, 7) === "parent.") {
                if ($p->post_parent == 0) {
                    $output[substr($key, 0, 6)] = [];
                    continue;
                }
                $fields = explode(",", substr($key, 7));
                $output[substr($key, 0, 6)] = $this->getPost($p->post_parent, $fields);
                continue;
            }

            // handle meta keys
            if (substr($key, 0, 5) === "meta.") {
                $output[substr($key, 5)] = get_post_meta($p->ID, substr($key, 5), true);
                continue;
            }

            // handle better wordpress fields
            if (substr($key, 0, 4) === "rad.") {
                if (substr($key, -5) === "-JSON") {
                    $key = substr($key, 0, strlen($key) - 5);
                    $output[substr($key, 4)] = json_decode(get_post_meta($p->ID, str_replace("rad.", "rad_", $key), true));
                    continue;
                }
                $output[substr($key, 4)] = get_post_meta($p->ID, str_replace("rad.", "rad_", $key), true);
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

                        if ($val === "url") {
                            $taxonomyDTO["url"] = get_term_link($tax);
                            continue;
                        }

                        if ($val === "permalink") {
                            $taxonomyDTO["permalink"] = get_term_link($tax);
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

            // woocommerce
            if (substr($key, 0, 12) === "woocommerce.") {
                $product = wc_get_product($p->ID);

                // reset key
                $key = str_replace("woocommerce.", "", $key);

                // check if we're diving into the attributes
                if (substr($key, 0, 10) === "attribute.") {
                    $key = str_replace("attribute.", "", $key);
                    $output[$key] = $product->get_attribute($key);
                    continue;
                }

                if ($key === "price") {
                    $output[$key] = $product->get_price();
                    continue;
                }
                if ($key === "attributes") {
                    $output[$key] = $product->get_attributes();
                    continue;
                }
                if ($key === "sku") {
                    $output[$key] = $product->get_sku();
                    continue;
                }
                if ($key === "cart_url" || $key === "cartUrl") {
                    $output[$key] = $product->add_to_cart_url();
                    continue;
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
            if (in_array($key, ["published", "publishedat", "publishedon", "published_at", "published_on"])) {
                $output[$key] = $p->post_date;
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

    public function menuArray($menuLocation)
    {
        $locations = get_nav_menu_locations();
        $output = [];
        if (!isset($locations[$menuLocation])) {
            return $output;
        }

        foreach (wp_get_nav_menu_items($locations[$menuLocation]) as $menuItem) {
            if ($menuItem->menu_item_parent === "0") {
                $output[$menuItem->ID] = [
                    "id" => $menuItem->ID,
                    "title" => $menuItem->title,
                    "url" => $menuItem->url,
                    "hasChildren" => false,
                    "children" => [],
                ];
                continue;
            }
            $output = $this->addMenuItemToChildren($output, $menuItem);
        }
        return $output;
    }

    private function addMenuItemToChildren(array $items, $childItem)
    {
        foreach ($items as $id => $menuItem) {
            if ($id == $childItem->menu_item_parent) {
                $items[$id]["hasChildren"] = true;
                $items[$id]["children"][$childItem->ID] = [
                    "id" => $childItem->ID,
                    "title" => $childItem->title,
                    "url" => $childItem->url,
                    "hasChildren" => false,
                    "children" => [],
                ];
                return $items;
            }
        }

        foreach ($items as $id => $parent) {
            foreach ($parent["children"] as $childID => $menuItem) {
                $items[$id]["children"] = $this->addMenuItemToChildren($parent["children"], $childItem);
            }
        }
        return $items;
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

    public function getAssetContents(string $filename) : string
    {
        if (!file_exists(get_template_directory()."/assets/$filename")) {
            return "Asset doesn't exist: ".get_template_directory()."/assets/$filename";
        }

        return file_get_contents(get_template_directory()."/assets/$filename");
    }

    private function generateLabels(string|array $slug) : array
    {
        if (is_array($slug)) {
            return $slug;
        }

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

    private function processCustomFields()
    {
        $templateFields = [];
        $tplFileContents;

        $theme = wp_get_theme();
        $templates = $theme->get_page_templates();
        foreach ($templates as $filename => $templateName) {
            $tplFileContents = file_get_contents(get_theme_file_path($filename));
            $fields = explode("\$fields", $tplFileContents);
            if (count($fields) < 2) {
                continue;
            }
            $fields = explode(";", $fields[1]);

            $fieldArray = eval("use ofc\RadField; return " . preg_replace('/=/', "", $fields[0], 1) . ";");
            $templateFields = [...$templateFields, ...$fieldArray];

            //add template name to first element of name so we can grab it in Util.php
            //it will be removed when processing each field
            array_unshift($templateFields, $templateName);
        }


        // some default admin ajax hooks for field processing
        $existingCPTSlugs = $this->cptSlugs;
        add_action('wp_ajax_rad_theme_engine_related', function () use ($existingCPTSlugs) {
            $args = [
                "type" => array_merge(["page", "post"], $existingCPTSlugs),
                "limit" => 5,
                "order" => "asc",
                "orderby" => "title",
                "s" => $_POST["q"] ?? "",
            ];

            $results = site()->getPosts($args, ["title", "id", "url"]);
            if (count($results) < 1) {
                $results = [
                    [
                        "title" => "No results",
                        "id" => 0,
                        "url" => "#"
                    ]
                ];
            }

            echo json_encode($results);
            wp_die();
        });

        Util::processFieldGroup($templateFields);
    }

    private function checkfavicons()
    {
        if (!is_dir(get_template_directory() . "/assets")) {
            return;
        }

        if (file_exists(get_template_directory() . "/assets/favicon.ico")) {
            add_action('wp_head', function () {
                echo "<link rel='icon' type='image/x-icon' href='".get_template_directory_uri()."/assets/favicon.ico'>".PHP_EOL;
            });
        }

        if (file_exists(get_template_directory() . "/assets/favicon-16x16.png")) {
            add_action('wp_head', function () {
                echo "<link rel='icon' type='image/png' href='".get_template_directory_uri()."/assets/favicon-16x16.png'>".PHP_EOL;
            });
        }

        if (file_exists(get_template_directory() . "/assets/favicon-32x32.png")) {
            add_action('wp_head', function () {
                echo "<link rel='icon' type='image/png' href='".get_template_directory_uri()."/assets/favicon-32x32.png'>".PHP_EOL;
            });
        }

        if (file_exists(get_template_directory() . "/assets/apple-touch-icon.png")) {
            add_action('wp_head', function () {
                echo "<link rel='apple-touch-icon' type='image/png' href='".get_template_directory_uri()."/assets/apple-touch-icon.png'>".PHP_EOL;
            });
        }

        if (file_exists(get_template_directory() . "/assets/site.webmanifest")) {
            add_action('wp_head', function () {
                echo "<link rel='manifest' href='".get_template_directory_uri()."/assets/site.webmanifest'>".PHP_EOL;
            });
        }
    }

    private function processHooks()
    {
        if (!isset($this->config["hooks"]) || !is_array($this->config["hooks"])) {
            return;
        }

        $defaultPriorities = [
            'woocommerce_before_main_content' => 5,
            'woocommerce_after_main_content' => 50,
        ];
        foreach ($this->config["hooks"] as $hookName => $callback) {
            $priority = $defaultPriorities[$hookName] ?? 99;
            add_action($hookName, $callback, $priority);
        }
    }

    /**
     * parseArgs
     * Used in conjuction with the handlebars helpers to grab all the different args
     *
     * @param string $args
     * @return array
     */
    public function parseArgs(string $args): array
    {
        $parsed = [];

        // Match key="value", key='value', or key=value
        preg_match_all('/(\w+)=(".*?"|\'.*?\'|\S+)/', $args, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];

            // Strip quotes if present
            $value = trim($value, "\"'");
            $parsed[$key] = $value;
        }

        return $parsed;
    }
}
