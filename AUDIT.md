# RAD Theme Engine — Code Audit

## Architecture & Design

**Strengths:**
- **MVC-inspired separation** — The Handlebars templating approach genuinely separates view logic from PHP processing. The `Site::view()` / `Site::render()` pattern is clean.
- **Singleton pattern** on `Site` is well-implemented for the framework's core.
- **RadAction interface + loader** is a nice abstraction over WordPress hooks — makes it easy to organize callback logic into classes.
- **RadField builder methods** (`RadField::text()`, `RadField::image()`, etc.) provide a nice DSL for defining admin fields.
- **CLI tool (`rad`)** is a thoughtful touch for DX — `make:action` and `get-icon` commands save boilerplate.
- **Config-driven approach** is intuitive for developers and less technical clients.

**Concerns:**
- **`Site.php` is a massive god object** at 1587 lines. It handles CPT registration, taxonomy, menus, AJAX, shortcodes, assets, favicons, Handlebars, admin notices, post querying, pagination, field processing, etc. This single class should be broken into multiple focused classes.
- **`RadThemeEngine.php` returns closures as helpers** — this is a clever pattern but means every Handlebars helper is a dynamically generated closure. Hard to debug, hard to test, and the callback signature `($template, $context, $args, $source)` is non-obvious.

---

## Code Quality Issues

### Bug: `config.example.php` line 18
`"guest-class" => "null"` stores the *string* `"null"`, not the PHP `null` value. This will cause `!is_null($guestClass)` to always be true, meaning the guest class will always be added. Should be `"guest-class" => null`.

### Bug: `Util.php:92` — save_post hook uses stale `$fieldGroup`
The closure captures `$fieldGroup` from `processFieldGroup()`, but by the time `save_post` fires, that variable may have been mutated (line 61 does `array_shift($fieldGroup)`). The save hook will see the mutated array, and the check `!isset($fieldGroup['fields'])` will likely always fail since `array_shift` removed the first element. This means **custom fields likely never save**.

### Bug: `Site.php:664,667` — duplicate `adminError` call
When the Handlebars directory doesn't exist, `adminError()` is called twice. The first call returns early, but the second is unreachable dead code.

### Code smell: `Site.php:1458` — `eval()` is used to process field definitions
The line `eval("use ofc\RadField; return " . preg_replace('/=/', "", $fields[0], 1) . ";");` parses and executes arbitrary PHP from template files. This is a significant security risk if templates are ever edited by non-admin users, and it breaks static analysis tools.

### Code smell: `Site.php:527` — `wp_die()` in `admin_init`
The customizer disable code calls `wp_die()` inside a callback on `load-customize.php`. This will crash the customize screen entirely rather than gracefully preventing access.

### Code smell: `FieldHTML.php` — Inline JavaScript in PHP
The repeater, image, file, and WYSIWYG field generators output massive embedded `<script>` blocks with Vue.js and raw JavaScript. This makes:
- SEO and CSP headers problematic (inline scripts are blocked)
- Impossible to debug with dev tools
- No separation between PHP and JS concerns
- XSS vulnerabilities (template variables like `{{ value }}` are not escaped)

---

## Security Issues

1. **`eval()` on template content** (`Site.php:1458`) — arbitrary PHP execution from `.php` template files.
2. **No nonce verification** anywhere in the admin field saving logic (`Util.php:99`) — `$_POST` data is saved without nonce checks, making CSRF possible.
3. **`wp_kses_post` is used but not `sanitize_text_field`** — Rich text is allowed through, which could store unwanted HTML in meta fields.
4. **Inline JavaScript in output** — Template variables are interpolated into JS without escaping, enabling XSS if user input reaches templates.
5. **`directory` permissions `0775`** in `MakeActionCommand.php:22` — overly permissive.
6. **`$field["type"] === "image"` loose comparison** in `Util.php:72` — should be strict `===` but this is minor.

---

## Missing / Incomplete

1. **No error handling in AJAX callbacks** — `Site.php:120,141` directly `echo json_encode($callback())` with no try/catch. If a callback throws, the response is malformed and `wp_die()` is unreachable.
2. **No input validation on `Site::getPost()`** — The method accepts arbitrary field key strings and does string prefix matching (`substr($key, 0, 5) === "meta."`). Malicious input could cause unexpected behavior.
3. **`IconGetter.php` uses `file_get_contents()`** without checking `allow_url_fopen` — will fail silently or with a warning on restricted servers.
4. **`IconGetter.php:2100`** — Error message says "Bootstrap icon name" but the comments in config.example.php reference Dashicons. The `$iconList` contains Bootstrap icons, creating confusion.
5. **`ActionsLoader.php:39-44`** — The `continue 2` inside the property check loop means it skips *all* checks once a missing property is found, rather than continuing to check the next property. It should be `continue` (not `continue 2`) or use a flag.
6. **`ActionsLoader.php:50`** — `new $class_name()` with no error handling. If the class can't be instantiated, a fatal error occurs.
7. **No composer autoload for `Commands\` namespace** — The commands are loaded manually via `bin/rad`, but `Actions\` namespace is also not in `composer.json` autoload. This is a fragile setup.
8. **`Site.php:1395` — `humanize()` is a terrible pluralizer** — `"news"` becomes `"newses"`, `"bus"` becomes `"buses"` (correct by accident), but many irregular plurals will be wrong. This affects CPT labels in the admin UI.

---

## WordPress Best Practices

1. **`get_template_directory()` used everywhere** — Doesn't work with child themes. Should use `get_stylesheet_directory()` for child theme support or mix both properly.
2. **`adminError()` outputs errors via `admin_notices`** — Error messages are stored in settings errors which is a misuse of that API. Better to use `add_action('admin_notices', fn() => esc_html_e('message', 'textdomain'))`.
3. **No WordPress hooks/filters for extension** — The framework doesn't expose many filter points. Most functionality is hardcoded into the config-driven bootstrap.
4. **`$fields == []` everywhere** — Loose comparison for arrays. Should use `$fields === []` or `empty($fields)`.
5. **`site()` function used throughout** — A global `site()` helper is referenced in `Util.php:47` and `IconGetter.php` but not defined in this package. Presumably it's defined in consuming themes.

---

## Testing

1. **Only 1 test** in the entire suite (`UtilTest.php`) — tests `slugify()` only. With 1587 lines in `Site.php` alone, this is ~0.1% test coverage.
2. **No integration tests** — The framework heavily depends on WordPress globals, so unit tests are hard, but at minimum the Handlebars rendering, field processing, and config parsing should have tests.
3. **No CI/CD** — No GitHub Actions, no linting, no static analysis configured.

---

## Performance

1. **`IconGetter.php` loads ~2100 icon names into memory** at class load time — this is a ~65KB array that's loaded on every request, even if no icon commands are used. If published as a package, this would significantly increase memory usage.
2. **`Site::processCustomFields()` scans all theme page templates and runs `eval()` on each** — this runs on every bootstrap, parsing PHP out of template files and evaluating it.
3. **Multiple `new Handlebars()` instantiations** — `renderTemplate()` creates a new Handlebars instance every time it's called (line 767). Should reuse the shared `$hb` instance.
4. **`json_encode()` on TinyMCE settings** (`Site.php:277`) — should be `wp_json_encode()` for WordPress compatibility.

---

## Dependency Issues

1. **`jjgrainger/posttypes ^2.1`** — an unmaintained-looking package for CPT/taxonomy registration. WordPress now has `register_post_type()` natively with plenty of features.
2. **`salesforce/handlebars-php ^2.3`** — this was a fork of the original Handlebars PHP and may have issues. The original package is now `bhollis/handlebars`.
3. **No version constraints on WordPress** — the code may not work on older or newer WP versions.
4. **PHP 8.1+ only** — reasonable, but the README doesn't mention this requirement.

---

## Documentation

1. **README is minimal** — no API docs, no examples of using `RadAction`, no explanation of the field system, no troubleshooting.
2. **Inline comments are sparse** — most methods lack docblocks (e.g., `Site::getPost()` at 233 lines with zero internal documentation).
3. **"TODO" markers in CLI commands** — the `GetIconCommand` and `MakeActionCommand` both have TODOs in their help text.
4. **Spelling errors throughout** — "sanatize" -> "sanitize", "extensivly" -> "extensively", "convienance" -> "convenience", "seperated" -> "separated", "dimentions" -> "dimensions".

---

## Summary

**What works well:** The MVC-inspired architecture, config-driven setup, Handlebars integration, and CLI tooling show thoughtful design for a WordPress theme framework. The field system and CPT registration are genuinely useful abstractions.

**What needs attention most:**
1. **Security** — The `eval()` usage and missing nonces are the highest priority fixes.
2. **`Site.php` god object** — Needs significant refactoring into smaller, testable classes.
3. **Inline JavaScript** — Should be extracted to external `.js` files with proper enqueuing.
4. **Test coverage** — At 1 test, the framework is essentially untested.
5. **Bug fix** — The `save_post` closure capturing a mutated variable means custom fields likely don't save properly.

The framework has a solid concept but would benefit from a serious code review pass, security audit, and refactoring before being used in production across multiple projects.
