<?php

$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    echo "Missing required environment variable: WP_TESTS_DIR";
    exit(1);
}
$_wp_tests_config = getenv('WP_TESTS_CONFIG');
if (!$_wp_tests_config) {
    echo "Missing required environment variable: WP_TESTS_CONFIG";
    exit(1);
}

if (!file_exists($_tests_dir . '/tests/phpunit/includes/functions.php')) {
    echo "Could not find WordPress test suite at {$_tests_dir}\n";
    exit(1);
}

// Give access to tests_add_filter() function
require_once $_tests_dir . '/tests/phpunit/includes/functions.php';

// Load the composer autoloader for rad-theme-engine
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load polyfills required by WP test suite
require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Start up the WP testing environment
require $_tests_dir . '/tests/phpunit/includes/bootstrap.php';

// Load up the base test case file
require_once __DIR__ . '/RadTestCase.php';
