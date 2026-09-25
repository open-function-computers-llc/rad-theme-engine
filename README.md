<p align="center">
<img src="/logo.png" alt="ofc-logo" style="max-width:500px;" />
</p>
A suite of utilities to deliver a faster and more consistent WordPress theme development experience.
<br><br>

[![Latest Stable Version](https://poser.pugx.org/open-function-computers-llc/rad-theme-engine/v/stable.svg)](https://packagist.org/packages/open-function-computers-llc/rad-theme-engine) [![Downloads](https://poser.pugx.org/open-function-computers-llc/rad-theme-engine/d/total.svg)](https://packagist.org/packages/open-function-computers-llc/rad-theme-engine)<br>
📦 &nbsp;[View on Packagist](https://packagist.org/packages/open-function-computers-llc/rad-theme-engine) <br>
📃 &nbsp;[Read the Docs](https://rad-theme-engine.ofco.cloud/)
<br>

## About

The purpose of this package is to enable developers to use the familiar model-view-controller pattern in the creation of WordPress themes. This is accomplished by keeping HTML and PHP code as seperated as possible and adding convenient methods to organize data before it's sent to the view controllers. Querying for posts, rendering menus, handling taxonomies and all the other essential parts of developing a WordPress theme are now easier than ever with the __RAD Theme Engine__.


## Quick Start
Inside of your site's `wp-content/themes` folder, run the following command to create a new __Rad Theme Engine__ project.

```
composer create-project open-function-computers-llc/wp-theme <theme-name>
```

Next, enter your new theme's folder and run `npm install` to get dependencies.
```
cd <theme-name>
npm install
```

And that's it! Read about advanced installations and asset bundling on [the docs](https://rad-theme-engine.ofco.cloud/).

## Local Development & Testing

This section is for contributors who want to work on `rad-theme-engine` itself with a full TDD setup.

### Prerequisites

- PHP >= 8.1
- Composer
- MariaDB or MySQL running locally
- [WP-CLI](https://wp-cli.org/) (`wp`)
- A local WordPress install (see below)

### 1. Clone the repos

Pick a working directory (e.g. `~/programming/php/ofco`) and clone both repos into it:

```bash
git clone https://github.com/open-function-computers-llc/rad-theme-engine.git
git clone https://github.com/open-function-computers-llc/wp-theme.git
```

### 2. Set up a local WordPress install

```bash
mkdir wordpress && cd wordpress
wp core download
wp config create --dbname=rad_tdd --dbuser=root --dbpass=YOUR_PASSWORD --dbhost=localhost
wp core install \
  --url=localhost:8080 \
  --title="RAD TDD" \
  --admin_user=admin \
  --admin_password=password \
  --admin_email=dev@localhost.com
```

Start the built-in PHP server to browse the site:

```bash
php -S localhost:8080
```

### 3. Create a dev theme with a symlinked package

From inside `wp-content/themes`, scaffold a new theme:

```bash
cd wordpress/wp-content/themes
composer create-project open-function-computers-llc/wp-theme dev-theme
```

Then update `dev-theme/composer.json` to add a `path` repository so Composer symlinks your local clone instead of downloading from Packagist:

```json
{
    "name": "open-function-computers-llc/wp-theme",
    "require": {
        "open-function-computers-llc/rad-theme-engine": "*"
    },
    "repositories": [
        {
            "type": "path",
            "url": "/path/to/your/rad-theme-engine",
            "options": {
                "symlink": true
            }
        }
    ],
    "autoload": {
        "psr-4": {
            "Helpers\\": "helpers/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": false
}
```

Run `composer update` and verify the symlink:

```bash
ls -la vendor/open-function-computers-llc/
# rad-theme-engine -> /path/to/your/rad-theme-engine
```

Activate the theme:

```bash
cd /path/to/wordpress
wp theme activate dev-theme
```

Any changes you make in `rad-theme-engine/src/` are now live immediately in the WordPress install — no reinstall required.

### 4. Set up the WordPress test suite

Clone `wordpress-develop` somewhere permanent on your machine:

```bash
git clone --depth=1 https://github.com/WordPress/wordpress-develop.git /path/to/wordpress-develop
```

Create a dedicated test database:

```bash
mysql -u root -p -e "CREATE DATABASE rad_tdd_tests;"
```

Copy and fill in the test config:

```bash
cp /path/to/wordpress-develop/wp-tests-config-sample.php \
   /path/to/wordpress-develop/wp-tests-config.php
```

Edit `wp-tests-config.php` with your database credentials and point `ABSPATH` at your WordPress install:

```php
define( 'DB_NAME', 'rad_tdd_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'your_password' );
define( 'DB_HOST', 'localhost' );

define( 'ABSPATH', '/path/to/wordpress/' );
```

### 5. Configure the test environment

Inside `rad-theme-engine`, copy `.env.example` to `.env` and fill in your local paths:

```bash
cp .env.example .env
```

```dotenv
WP_TESTS_DIR=/path/to/wordpress-develop
WP_TESTS_CONFIG=/path/to/wordpress-develop/wp-tests-config.php
```

This file is gitignored — it never gets committed.

### 6. Install dev dependencies and run the suite

```bash
cd rad-theme-engine
composer install
make test
```

You should see WordPress boot and all tests pass:

```
Installing...
Running as single site...
PHPUnit 9.6.23 by Sebastian Bergmann and contributors.

.....                                               5 / 5 (100%)

OK (5 tests, 13 assertions)
```

### Available make commands

| Command | Description |
|---|---|
| `make test` | Run the full test suite |
| `make coverage` | Run tests and generate an HTML coverage report in `./report` |
| `make watch` | Re-run tests automatically on every file save (requires `entr`) |

Install `entr` on Debian/Ubuntu with `sudo apt install entr`, or on macOS with `brew install entr`.

### Writing tests

Tests live in the `tests/` directory. Integration tests that need WordPress extend `WP_UnitTestCase`. The `Site` class is a singleton, so each test class that instantiates it must reset the instance in `tearDown`:

```php
<?php

namespace ofc\tests;

use ofc\Site;
use WP_UnitTestCase;

class MyFeatureTest extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        $reflection = new \ReflectionClass(Site::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    /** @test */
    public function myFeatureWorkAsExpected()
    {
        Site::getInstance([
            "handlebars" => false,
            // your config here
        ]);

        // your assertions here
    }
}
```

Pure unit tests that don't need WordPress (like testing utility methods) can extend `PHPUnit\Framework\TestCase` directly and will run faster.

## Example Projects
- [Shirt Store](https://github.com/open-function-computers-llc/rad-theme-engine-example-theme) – Demonstrates custom post types, taxonomies, handlebars, and more.

## Authors
- Kurtis Holsapple – [@lapubell](https://github.com/lapubell)
- Escher Wright-Dykhouse – [@escherwd](https://github.com/escherwd)
- Gabriel Johnson - [@gabriel-johnson](https://github.com/gabriel-johnson)

## License
Licensed under the MIT license, see [LICENSE](https://github.com/open-function-computers-llc/rad-theme-engine/blob/main/LICENSE)
