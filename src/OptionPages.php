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
     * Option key holding the timestamp of a page's last save, shown in the
     * Save box (kept out of the `rad_<page>_<field>` namespace so it can never
     * shadow a real field).
     */
    public const UPDATED_PREFIX = 'rad_options_updated_';

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

        // Enqueue TinyMCE / media-manager assets on our own options screens
        // when the yml actually uses those field types. WP doesn't do this
        // automatically for standalone admin pages the way it does for post
        // edit screens, so without this the "Choose Media" button renders
        // but wp.media isn't defined and the click handler silently no-ops.
        add_action('admin_enqueue_scripts', function ($hook) use ($groups) {
            if (!self::onOptionsScreen($hook, $groups)) {
                return;
            }
            $wysiwyg = false;
            $media = false;
            foreach ($groups as $meta) {
                $fields = $meta['fields'] ?? [];
                if (!$wysiwyg && CompanionFields::needsWysiwyg($fields)) {
                    $wysiwyg = true;
                }
                if (!$media && CompanionFields::needsMedia($fields)) {
                    $media = true;
                }
                if ($wysiwyg && $media) {
                    break;
                }
            }
            if ($wysiwyg) {
                wp_enqueue_editor();
            }
            if ($media) {
                wp_enqueue_media();
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
     * @return array<string, array{title: string, slug: string, fields: array, parent: ?string, menu_title: string, menu_position: ?int}>
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

            // Title / menu-label precedence — either key can drive both the
            // browser tab + <h1> and the sidebar link, and they diverge only
            // when both are supplied:
            //   title        <- `title:` yml -> `admin_link_text:` yml -> humanised filename
            //   menu_title   <- `admin_link_text:` yml -> title
            // So a single `admin_link_text: Papers Options` gives you "Papers
            // Options" as both the H1 and the sidebar label, which is the
            // common case. Supplying `title:` lets you split them.
            $humanName = ucwords(str_replace(['-', '_'], ' ', $name));
            $menuLabel = isset($raw['admin_link_text']) && $raw['admin_link_text'] !== ''
                ? (string) $raw['admin_link_text']
                : null;
            $explicitTitle = isset($raw['title']) && $raw['title'] !== ''
                ? (string) $raw['title']
                : null;

            $title = $explicitTitle ?? $menuLabel ?? $humanName;
            $menuTitle = $menuLabel ?? $title;

            // Optional placement / labelling keys (all omitted → existing
            // top-level behaviour):
            //   parent:          any slug recognised by add_submenu_page's
            //                    $parent_slug arg. Common values are
            //                    `edit.php?post_type=<cpt>` (hang under a CPT),
            //                    `options-general.php` (Settings), `themes.php`
            //                    (Appearance), etc.
            //   menu_position:   numeric sort key inside the parent submenu.
            //                    WordPress lays CPT submenus at
            //                    5=All, 10=Add New, 15+taxonomies — so 11..14
            //                    is free real estate for "right after Add New".
            //                    CPT parents default to 11; anything else is
            //                    left to WP's append order when unset.
            $parent = isset($raw['parent']) && $raw['parent'] !== ''
                ? (string) $raw['parent']
                : null;

            $menuPosition = null;
            if (isset($raw['menu_position'])) {
                $menuPosition = (int) $raw['menu_position'];
            } elseif ($parent !== null && preg_match('/^edit\.php\?post_type=/', $parent)) {
                // Land the item immediately after "Add New" on CPT parents so
                // it reads: All Papers / Add New / <our page> / <taxonomies>.
                $menuPosition = 11;
            }

            $groups[$slug] = [
                'title' => $title,
                'slug' => $slug,
                'fields' => $fields,
                'parent' => $parent,
                'menu_title' => $menuTitle,
                'menu_position' => $menuPosition,
            ];
        }

        return $groups;
    }

    /**
     * Register an admin menu page per options page.
     *
     * Without `parent:` the page becomes a top-level admin entry (existing
     * behaviour). With `parent:` it hangs under that parent as a submenu item,
     * using `admin_link_text:` as the sidebar label and (optionally)
     * `menu_position:` as the sort key — which is how we slot options pages
     * under CPTs "right after Add New".
     */
    private static function registerMenuPages(array $groups): void
    {
        $i = 0;
        foreach ($groups as $slug => $meta) {
            $pageSlug = self::MENU_SLUG . $slug;
            $render = function () use ($slug) {
                echo self::renderPage($slug);
            };

            if (!empty($meta['parent'])) {
                $parent = (string) $meta['parent'];
                add_submenu_page(
                    $parent,
                    $meta['title'],
                    $meta['menu_title'] ?? $meta['title'],
                    'manage_options',
                    $pageSlug,
                    $render
                );

                // add_submenu_page appends with an auto key (usually 20+),
                // which puts us after taxonomies. Re-key the entry to
                // menu_position and re-sort so it lands where the CPT parent
                // expects it (e.g. 11, right after "Add New").
                if (isset($meta['menu_position'])) {
                    self::positionSubmenuEntry($parent, $pageSlug, (int) $meta['menu_position']);
                }
            } else {
                $position = 60 + $i; // start just below Settings (60)

                add_menu_page(
                    $meta['title'],
                    $meta['menu_title'] ?? $meta['title'],
                    'manage_options',
                    $pageSlug,
                    $render,
                    'dashicons-admin-generic',
                    $position
                );
            }

            $i++;
        }
    }

    /**
     * Move our submenu entry (already added by add_submenu_page) to a specific
     * numeric key inside $submenu[$parent] and re-sort so it appears between
     * the sibling items WordPress installed there.
     */
    private static function positionSubmenuEntry(string $parent, string $pageSlug, int $position): void
    {
        global $submenu;

        if (!isset($submenu[$parent]) || !is_array($submenu[$parent])) {
            return;
        }

        $entry = null;
        foreach ($submenu[$parent] as $key => $row) {
            // WP's submenu row shape: [title, capability, slug, display-name?].
            if (isset($row[2]) && $row[2] === $pageSlug) {
                $entry = $row;
                unset($submenu[$parent][$key]);
                break;
            }
        }

        if ($entry === null) {
            return;
        }

        $submenu[$parent][$position] = $entry;
        ksort($submenu[$parent]);
    }

    /**
     * Whether the current admin screen hook is one of our options pages.
     *
     * WP names the hook by mirroring get_plugin_page_hookname():
     *   toplevel page            -> `toplevel_page_<pageSlug>`
     *   submenu under $parent    -> `<sanitize_title($parent)>_page_<pageSlug>`
     * (e.g. a page parented to `edit.php?post_type=paper` gets
     * `edit_page_rad-options-papers-cpt`).
     */
    private static function onOptionsScreen(string $hook, array $groups): bool
    {
        foreach ($groups as $slug => $meta) {
            if ($hook === self::hookFor($slug, $meta['parent'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compute the admin screen hook suffix WP will assign to one of our
     * options pages, given its slug and (optional) parent.
     */
    private static function hookFor(string $slug, ?string $parent): string
    {
        $pageSlug = self::MENU_SLUG . $slug;
        if ($parent === null || $parent === '') {
            return 'toplevel_page_' . $pageSlug;
        }
        // Mirror get_plugin_page_hookname(): the parent's menu slug (query
        // stripped, `.php` dropped) is the page-type prefix, so a page parented
        // to `edit.php?post_type=paper` gets `edit_page_<pageSlug>` — NOT the
        // query folded into a sanitized slug (which sanitize_title() produced,
        // e.g. `edit-phppost_typepaper_page_…`).
        $base = strtolower((string) $parent);
        $base = (string) strtok($base, '?');
        $base = preg_replace('/\.php$/', '', $base);
        return $base . '_page_' . $pageSlug;
    }

    /**
     * Split a page's fields into postboxes.
     *
     * A field's optional `section:` key is the title of the box it belongs to;
     * boxes appear in the order their first field is declared, and a section
     * mentioned again later folds back into the box it already opened. Fields
     * with no `section:` share a box — untitled (a plain card) when they are
     * the only group on the page, "General" when they sit beside named ones.
     *
     * @param array[] $fields normalized fields, in yml order
     * @return array<array{title: ?string, fields: array[]}>
     */
    public static function boxes(array $fields): array
    {
        $boxes = [];

        foreach ($fields as $field) {
            $section = isset($field['section']) ? trim((string) $field['section']) : '';

            if (!isset($boxes[$section])) {
                $boxes[$section] = ['title' => $section === '' ? null : $section, 'fields' => []];
            }

            $boxes[$section]['fields'][] = $field;
        }

        $boxes = array_values($boxes);

        if (count($boxes) > 1) {
            foreach ($boxes as $i => $box) {
                if ($box['title'] === null) {
                    $boxes[$i]['title'] = 'General';
                }
            }
        }

        return $boxes;
    }

    /**
     * Render the full options page (form wrapper + nonce + postboxes + save box).
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

        $html = '<div class="wrap rad-options-page" data-rad-options-page="' . esc_attr($slug) . '">';
        $html .= '<h1 class="wp-heading-inline">' . esc_html($meta['title']) . '</h1>';
        $html .= '<hr class="wp-header-end" />';
        $html .= self::pageStyles();
        $html .= '<form method="post" action="">';
        // echo: false — the field is returned for concatenation; left to the
        // default it would also print itself ahead of the wrapper below.
        $html .= wp_nonce_field('rad_options_' . $slug, 'rad_options_nonce', true, false);
        $html .= '<input type="hidden" name="rad_options_page" value="' . esc_attr($slug) . '" />';

        // The post editor's shell: a wide column of boxes beside a narrow
        // column holding the Save box (which has to sit inside the same form
        // as the fields it submits).
        $html .= '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';
        $html .= '<div id="post-body-content">';

        foreach (self::boxes($meta['fields']) as $i => $box) {
            $html .= self::renderBox($slug, $box, $values, $i);
        }

        $html .= '</div>';
        $html .= '<div id="postbox-container-1" class="postbox-container">';
        $html .= self::renderSubmitBox($slug);
        $html .= '</div>';
        $html .= '</div></div>';
        $html .= '</form>';
        $html .= CompanionAssets::script('options-page.js');
        $html .= '</div>';

        return $html;
    }

    /**
     * The options page stylesheet. Emitted inline (rather than enqueued) to
     * match how the field assets are delivered by CompanionFields::adminStyles.
     */
    private static function pageStyles(): string
    {
        $css = CompanionAssets::file('options-page.css');

        return $css === '' ? '' : '<style>' . $css . '</style>';
    }

    /**
     * Render one postbox: the WP postbox chrome (header + handle + .inside) so
     * the screen reads like the meta boxes on a post, with the fields inside.
     *
     * @param array{title: ?string, fields: array[]} $box
     */
    private static function renderBox(string $slug, array $box, array $values, int $index): string
    {
        $id = 'rad-options-box-' . $slug . '-' . $index;
        $title = $box['title'];

        $fields = '';
        foreach ($box['fields'] as $field) {
            // Prefix the rendered input ids/names with the page slug so fields
            // from different pages don't collide on the same form.
            $fields .= self::renderScopedField($slug, $field, $values[$field['name']] ?? '');
        }

        $html = '<div id="' . esc_attr($id) . '" class="postbox rad-options-box" data-rad-box-id="' . esc_attr($id) . '">';

        if ($title !== null && $title !== '') {
            $html .= '<div class="postbox-header" data-rad-toggleable>';
            $html .= '<h2 class="hndle"><span>' . esc_html($title) . '</span></h2>';
            $html .= '<div class="handle-actions">';
            $html .= '<button type="button" class="handlediv" aria-expanded="true" aria-controls="' . esc_attr($id . '-inside') . '">';
            $html .= '<span class="toggle-indicator" aria-hidden="true"></span>';
            $html .= '<span class="screen-reader-text">Toggle panel: ' . esc_html($title) . '</span>';
            $html .= '</button>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '<div class="inside" id="' . esc_attr($id . '-inside') . '">';
        $html .= '<div class="rad-companion-fields">' . $fields . '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * The side column's Save box (the options page stand-in for Publish).
     */
    private static function renderSubmitBox(string $slug): string
    {
        $saved = self::lastUpdated($slug);

        $html = '<div id="rad-options-submit-' . esc_attr($slug) . '" class="postbox rad-options-submit">';
        $html .= '<div class="postbox-header"><h2 class="hndle"><span>Save</span></h2></div>';
        $html .= '<div class="inside">';
        $html .= '<p class="rad-options-submit-actions"><button type="submit" class="button button-primary">Save Changes</button></p>';
        $html .= '<p class="description rad-options-updated">'
            . esc_html($saved === '' ? 'Nothing has been saved yet.' : 'Last saved ' . $saved)
            . '</p>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * When the page was last posted, formatted with the site's date/time
     * formats. Empty when it has never been saved.
     */
    private static function lastUpdated(string $slug): string
    {
        $timestamp = (int) get_option(self::UPDATED_PREFIX . $slug, 0);

        if ($timestamp <= 0) {
            return '';
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
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

        // Stamped for the "Last saved …" line in the page's Save box.
        self::setOptionValue(self::UPDATED_PREFIX . $slug, time());
        self::adminSaved('Settings saved.');
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

    /**
     * Confirm a save to the admin, in the green notice style core settings
     * pages use (save() runs on admin_init, before admin_notices fires).
     */
    private static function adminSaved(string $message): void
    {
        add_action('admin_notices', function () use ($message) {
            add_settings_error('rad_options_saved', 'rad-options-saved', $message, 'updated');
            settings_errors('rad_options_saved');
        });
    }
}
