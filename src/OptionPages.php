<?php

namespace ofc;

use Symfony\Component\Yaml\Yaml;

/**
 * Site options pages driven by companion-YAML files.
 *
 * Each options page is declared in the theme's config.php under the
 * `options-pages` key (a list of names, or a single string). A name `foo` maps
 * to a yml file at `theme-root/options/foo.yml`. The yml's `fields:` block is
 * rendered as a standalone admin menu page (like ACF options pages) so editors
 * can populate site-wide settings. Values are stored as site options keyed
 * `rad_<page>_<field name>` (e.g. `rad_site-settings_primary_phone`).
 *
 * Field normalisation, rendering, sanitising and resolution are reused from
 * {@see CompanionFields} so the two systems stay behaviourally identical.
 */
class OptionPages
{
    /**
     * Option key prefix. A field on page `site-settings` is stored as the
     * option `rad_site-settings_<field>`.
     */
    public const OPTION_PREFIX = 'rad_';

    /**
     * Menu slug prefix for the admin pages.
     */
    public const MENU_SLUG = 'rad-options-';

    /**
     * Wire up admin menu page registration + the save handler.
     *
     * @param string[]|string $pages options page names (or a single name)
     */
    public static function boot($pages): void
    {
        $pages = is_array($pages) ? $pages : [$pages];
        $pages = array_values(array_filter(array_map('strval', $pages), fn ($p) => $p !== ''));

        if (empty($pages)) {
            return;
        }

        self::$pageNames = $pages;

        $groups = self::discover($pages);

        if (empty($groups)) {
            return;
        }

        add_action('admin_menu', function () use ($groups) {
            self::registerMenuPages($groups);
        });

        // Enqueue TinyMCE assets only on our own options screens.
        add_action('admin_enqueue_scripts', function ($hook) use ($groups) {
            if (!self::onOptionsScreen($hook, $groups)) {
                return;
            }
            foreach ($groups as $meta) {
                if (CompanionFields::needsWysiwyg($meta['fields'])) {
                    wp_enqueue_editor();
                    break;
                }
            }
        });

        // Save handler. Our form posts to the same page URL, so we catch it on
        // admin_init (the request carries rad_options_page + the nonce).
        add_action('admin_init', function () use ($groups) {
            self::save($groups);
        });
    }

    /**
     * Discover and normalise the requested options pages.
     *
     * @param string[] $pages names of options pages to load
     * @return array<string, array{title: string, slug: string, fields: array}>
     */
    public static function discover(array $pages): array
    {
        $groups = [];
        $dir = get_template_directory() . '/options';

        foreach ($pages as $name) {
            // The yml file name is the config name as-is (sanitized to block path
            // traversal); the slug (the canonical key) is used for option/menu keys.
            $fileName = self::safeFileName($name);
            $yml = rtrim($dir, '/') . '/' . $fileName . '.yml';
            $slug = self::slug($name);

            if (!file_exists($yml)) {
                self::adminNotice("Option page `$name` requested but `$yml` doesn't exist.");
                continue;
            }

            $raw = Yaml::parseFile($yml);

            if (!is_array($raw) || empty($raw['fields']) || !is_array($raw['fields'])) {
                self::adminNotice("Option page `$name` is missing a `fields:` block.");
                continue;
            }

            $fields = [];
            foreach ($raw['fields'] as $fieldName => $def) {
                $fields[] = CompanionFields::normalizeField($fieldName, $def);
            }

            if (empty($fields)) {
                continue;
            }

            $title = isset($raw['title']) && $raw['title'] !== ''
                ? (string) $raw['title']
                : ucwords(str_replace(['-', '_'], ' ', $name));

            $groups[$slug] = [
                'title' => $title,
                'slug' => $slug,
                'fields' => $fields,
            ];
        }

        return $groups;
    }

    /**
     * Register a standalone admin menu page per options page.
     */
    private static function registerMenuPages(array $groups): void
    {
        $i = 0;
        foreach ($groups as $slug => $meta) {
            $pageSlug = self::MENU_SLUG . $slug;
            $position = 60 + $i; // start just below Settings (60)

            add_menu_page(
                $meta['title'],
                $meta['title'],
                'manage_options',
                $pageSlug,
                function () use ($slug) {
                    echo self::renderPage($slug);
                },
                'dashicons-admin-generic',
                $position
            );

            $i++;
        }
    }

    /**
     * Whether the current admin screen hook is one of our options pages.
     */
    private static function onOptionsScreen(string $hook, array $groups): bool
    {
        return in_array($hook, array_map(fn ($s) => 'toplevel_page_' . self::MENU_SLUG . $s, array_keys($groups)), true);
    }

    /**
     * Render the full options page (form wrapper + nonce + fields + save button).
     */
    public static function renderPage(string $slug): string
    {
        $groups = self::discover(self::names());
        $meta = $groups[$slug] ?? null;

        if (!$meta) {
            return '<p>Options page not found.</p>';
        }

        // Current values keyed by field name. Media/repeater/flexible are stored
        // serialized; render media as the JSON the admin inputs expect.
        $values = [];
        foreach ($meta['fields'] as $field) {
            $raw = self::optionValue($slug, $field['name']);
            if (in_array($field['type'], ['media', 'repeater', 'flexible'], true)) {
                // These are stored serialized; renderField expects a JSON string
                // for media (the picker's shape) and a JSON array for
                // repeater/flexible (the sync() shape).
                $decoded = CompanionFields::unserializeValue($raw);
                $values[$field['name']] = is_array($decoded) ? json_encode($decoded) : '';
            } else {
                $values[$field['name']] = $raw;
            }
        }

        $html = '<div class="wrap rad-options-page">';
        $html .= '<h1>' . esc_html($meta['title']) . '</h1>';
        $html .= '<form method="post" action="">';
        $html .= wp_nonce_field('rad_options_' . $slug, 'rad_options_nonce');
        $html .= '<input type="hidden" name="rad_options_page" value="' . esc_attr($slug) . '" />';

        $html .= '<div class="rad-companion-fields">';
        foreach ($meta['fields'] as $field) {
            // Prefix the rendered input ids/names with the page slug so fields
            // from different pages don't collide on the same form.
            $html .= self::renderScopedField($slug, $field, $values[$field['name']] ?? '');
        }
        $html .= '</div>';

        $html .= '<p class="submit"><button type="submit" class="button button-primary">Save</button></p>';
        $html .= '</form></div>';

        return $html;
    }

    /**
     * Render a single field. Each options page is its own admin screen/form, so
     * field input names (`rad_<field.name>`) never collide across pages; the
     * page being saved is tracked via the hidden `rad_options_page` field.
     */
    private static function renderScopedField(string $slug, array $field, $value): string
    {
        return CompanionFields::renderField($field, $value);
    }

    /**
     * Handle saving a posted options form.
     */
    public static function save(array $groups): void
    {
        if (empty($_POST) || !isset($_POST['rad_options_page'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $slug = sanitize_text_field(wp_unslash($_POST['rad_options_page']));

        if (!isset($groups[$slug])) {
            return;
        }

        $meta = $groups[$slug];
        $nonce = isset($_POST['rad_options_nonce']) ? sanitize_text_field(wp_unslash($_POST['rad_options_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'rad_options_' . $slug)) {
            return;
        }

        foreach ($meta['fields'] as $field) {
            $optionName = self::OPTION_PREFIX . $slug . '_' . $field['name'];
            // The form posts inputs named `rad_<field.name>` (see
            // renderScopedField); the page is known from rad_options_page.
            $postKey = 'rad_' . $field['name'];

            if (!isset($_POST[$postKey])) {
                // Checkbox that was unchecked has no key; store empty.
                if ($field['type'] === 'checkbox') {
                    self::setOptionValue($optionName, '');
                }
                continue;
            }

            $value = wp_unslash($_POST[$postKey]);
            $value = CompanionFields::sanitizeValue($value, $field['type'], $field);
            self::setOptionValue($optionName, $value);
        }
    }

    /**
     * Resolve a field (by page + name) to the value a template should see.
     *
     * @param string $slug  the options page slug
     * @param string $name  the field name
     * @return mixed
     */
    public static function resolveField(string $slug, string $name)
    {
        $groups = self::discover(self::names());
        $meta = $groups[$slug] ?? null;

        $field = null;
        if ($meta) {
            foreach ($meta['fields'] as $f) {
                if ($f['name'] === $name) {
                    $field = $f;
                    break;
                }
            }
        }

        $raw = self::optionValue($slug, $name);

        if (!$field) {
            return $raw;
        }

        return CompanionFields::resolveValue($field, $raw);
    }

    /**
     * Resolve every field on a page into a name => resolved-value map.
     *
     * @return array
     */
    public static function resolveAll(string $slug): array
    {
        $groups = self::discover(self::names());
        $meta = $groups[$slug] ?? null;

        if (!$meta) {
            return [];
        }

        $out = [];
        foreach ($meta['fields'] as $field) {
            $out[$field['name']] = self::resolveField($slug, $field['name']);
        }
        return $out;
    }

    /**
     * Read the stored option value for a page field.
     */
    private static function optionValue(string $slug, string $name): string
    {
        return (string) get_option(self::OPTION_PREFIX . $slug . '_' . $name, '');
    }

    /**
     * Write the stored option value for a page field.
     */
    private static function setOptionValue(string $optionName, $value): void
    {
        update_option($optionName, $value);
    }

    /**
     * The names of the options pages requested in config (mirrors what Site
     * passed to boot()). Used by resolve* so it can re-discover on demand.
     *
     * @return string[]
     */
    public static function names(): array
    {
        return self::$pageNames;
    }

    /**
     * Normalize a raw name/token to its canonical options-page slug.
     */
    public static function slugFor(string $value): string
    {
        return self::slug($value);
    }

    /**
     * @var string[] names passed to boot()
     */
    private static $pageNames = [];

    /**
     * Turn a name into a safe slug (option/page/menu identifier). Preserves
     * hyphens so the config name is the canonical key (getOption("site-settings", ...)).
     */
    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-_]/', '', $value);
        return trim($value, '.-_');
    }

    /**
     * Sanitize a config name into a safe file name (keeps hyphens/underscores so
     * the yml file matches the config name; strips anything else to block path
     * traversal).
     */
    private static function safeFileName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
        return trim($value, '.-_');
    }

    /**
     * Surface a non-fatal problem to the admin (mirrors Site::adminError).
     */
    private static function adminNotice(string $message): void
    {
        $key = self::slug($message);
        add_action('admin_notices', function () use ($message, $key) {
            add_settings_error($key, '', "OFC Options Error: $message", 'error');
            settings_errors($key);
        });
    }
}
