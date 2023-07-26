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

The purpose of this package is to enable developers to use the familiar model-view-controller pattern in the creation of WordPress themes. This is accomplished by keeping HTML and PHP code as seperated as possible and adding convenient methods to organize data before it’s sent to the view controllers. Querying for posts, rendering menus, handling taxonomies and all the other essential parts of developing a WordPress theme are now easier than ever with the __RAD Theme Engine__.


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

And that's it! Read about advanced installations and asset bundling on [the docs](https://rad.ofco.cloud/).

## Example Projects
- [Shirt Store](https://github.com/open-function-computers-llc/better-wp-example-theme) – Demonstrates custom post types, taxonomies, handlebars, and more.

## Authors
- Kurtis Holsapple – [@lapubell](https://github.com/lapubell)
- Escher Wright-Dykhouse – [@escherwd](https://github.com/escherwd)

## License
Licensed under the MIT license, see [LICENSE](https://github.com/open-function-computers-llc/rad-theme-engine/blob/main/LICENSE)
