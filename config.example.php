<?php

return [
    /**
     * excerpt-length
     * how many words should the wordpress excerpt be
     */
    "excerpt-length" => 100,

    /**
     * guest-class
     * if you want wordpress to automatically append a class to the body_class
     * list when users are not authenticated, put that class name here. it
     * defaults to "guest"
     *
     * to disable, set this to null
     */
    "guest-class" => "null",

    /**
     * menu-locations
     * register your individual menu locations here
     */
    "menu-locations" => [
        "main-nav" => "Main Navigation",
        "footer-nav" => "Footer Navigation",
    ],

    /**
     * custom-post-types
     * here is where you can define your custom post types easily
     *
     * icons are powered by dashicons, choose one from here:
     * https://developer.wordpress.org/resource/dashicons
     *
     * additional options are enabled in the cpt options key
     * if you override "supports", be sure to include 'title' and 'editor' in
     * the list for standard wordpress functionality
     */
    "custom-post-types" => [
        [
            "slug" => "thing",
            "icon" => "dashicons-tide",
            "options" => [
                "supports" => ['title', 'editor', 'thumbnail', 'comments']
            ]
        ],
    ],

    /**
     * handlebars
     *
     * We use handlebars templating extensivly in this theme and code pattern.
     * You can adjust the defaults for many attributes here.
     *
     * Set this to `false` to disable handlebars functionality completely
     */
    "handlebars" => [
        /**
         * additional-helpers
         * if you need to register additional Handlebars Helpers, register them here
         *
         * the key is the name that you will use in your templates, and the value is
         * the callback function that is run on the template side
         */
        "additional-helpers" => [],

        /**
         * template-extension
         *
         * The default extention for your templates is .tpl
         * If you'd like to change that, set the vaule here, without the dot
         */
        // "template-extension" => "tpl",
    ],

    /**
     * enable
     * enable individual wordpress features here
     */
    "enable" => [
        "post-thumbnails",
        "menus",
    ],

    /**
     * disable
     * disable individual wordpress features here
     */
    "disable" => [
        "editor",
    ],
];
