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
}
