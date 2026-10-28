<?php

namespace ofc;

use Symfony\Component\Yaml\Yaml;

/**
 * Companion YAML field system.
 *
 * For any WordPress page template `<name>.php` that has a sibling `<name>.yml`
 * in the theme directory, the `fields:` block of that yml file is turned into
 * an admin meta box (shown only when the matching template is selected) so
 * editors can populate the values. Values are stored as post meta keyed
 * `rad_<field name>`.
 */
class CompanionFields
{
    /**
     * Field types that are rendered natively as form inputs.
     * Anything else renders a visible placeholder (see $UNIMPLEMENTED for
     * types recognised by the format but not yet implemented), so unknown
     * types are surfaced instead of silently dropped.
     */
    public const SUPPORTED = [
        'text', 'email', 'number', 'tel', 'url', 'password',
        'date', 'datetime-local', 'time', 'week', 'month',
        'textarea', 'checkbox', 'select', 'media', 'repeater', 'flexible', 'wysiwyg',
    ];

    /**
     * Media output types a `media` field can be resolved to on the frontend.
     */
    public const MEDIA_OUTPUTS = ['id', 'url', 'alt', 'html', 'array'];

    /**
     * Field types recognised by the format but not yet rendered.
     */
    public const UNIMPLEMENTED = ['file', 'flex'];

    /**
     * Errors surfaced while resolving yml `imports:` this request.
     */
    private static $importErrors = [];

    /**
     * Whether the shared admin stylesheet has been emitted this request. The
     * first rendered field prints it; every later call returns nothing.
     */
    private static $adminStylesPrinted = false;

    /**
     * Wire up discovery, the meta box, and the save handler.
     */
    public static function boot(): void
    {
        $groups = self::discover();

        if (empty($groups)) {
            return;
        }

        add_action('add_meta_boxes', function () use ($groups) {
            $post = get_post();

            if (!$post) {
                return;
            }

            // Only render on a post edit screen (pages, posts, or any CPT that
            // has a companion yml).
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || $screen->post_type === 'revision') {
                return;
            }

            $currentTemplate = get_post_meta($post->ID, '_wp_page_template', true);

            foreach ($groups as $template => $meta) {
                if (!self::groupAppliesTo($meta, $post, $currentTemplate)) {
                    continue;
                }

                $boxId = 'rad_companion_' . self::slug($template);

                $values = [];
                foreach ($meta['fields'] as $field) {
                    $raw = get_post_meta($post->ID, 'rad_' . $field['name'], true);
                    // Media and repeater fields are stored serialized; convert to
                    // JSON for the admin inputs (which expect JSON strings).
                    if ($field['type'] === 'media' || $field['type'] === 'repeater') {
                        $decoded = self::unserializeValue($raw);
                        $values[$field['name']] = is_array($decoded) ? json_encode($decoded) : '';
                    } else {
                        $values[$field['name']] = $raw;
                    }
                }

                // Render into the main editor area (a "post box"), not the
                // sidebar. Use the current post type (page, post, or the CPT)
                // so WordPress registers the box under the exact screen that
                // do_meta_boxes() reads for this edit screen.
                add_meta_box(
                    $boxId,
                    'Custom Fields',
                    function () use ($boxId, $template, $meta, $values) {
                        echo self::renderBox($boxId, $template, $meta['fields'], $values);
                    },
                    $screen->post_type,
                    'normal',
                    'default'
                );

                if (!empty($meta['hidden'])) {
                    self::registerHiddenEditors($meta['hidden']);
                }
            }
        });

        // Enqueue the TinyMCE editor assets only when a visible field set uses a
        // wysiwyg sub-field, and only on the post edit screen where editors live.
        add_action('admin_enqueue_scripts', function () use ($groups) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || $screen->post_type === 'revision') {
                return;
            }

            foreach ($groups as $meta) {
                if (self::needsWysiwyg($meta['fields'])) {
                    wp_enqueue_editor();
                    break;
                }
            }
        });

        add_action('save_post', function ($post_id, $post) use ($groups) {
            self::save($post_id, $post, $groups);
        }, 10, 2);

        // Resolve `rad.<media>-JSON` placeholders in post content into the
        // output type requested in the companion yml (url / id / alt / ...).
        add_filter('the_content', [self::class, 'handleRadMediaFilter'], 99);
    }

    /**
     * Hide editor areas (content / excerpt / title) on the post edit screen for
     * the current template. The content area is the main TinyMCE editor.
     *
     * @param string[] $areas
     */
    private static function registerHiddenEditors(array $areas): void
    {
        $map = [
            'content' => 'postdivrich',
            'excerpt' => 'postexcerpt',
            'title' => 'titlediv',
        ];

        $selectors = [];
        foreach ($areas as $area) {
            if (isset($map[$area])) {
                $selectors[] = '#' . $map[$area];
            }
        }

        if (empty($selectors)) {
            return;
        }

        $css = implode(', ', $selectors) . '{display:none !important;}';

        add_action('admin_head-post.php', function () use ($css) {
            echo '<style>' . $css . '</style>';
        });

        // Also hide the "Permalink" slug box that lives inside #titlediv when the
        // title area is hidden, so nothing dangles.
        if (in_array('title', $areas, true)) {
            add_action('admin_head-post.php', function () {
                echo '<style>#edit-slug-box{display:none !important;}</style>';
            });
        }
    }

    /**
     * Find every companion template that has a yml file and parse its fields.
     *
     * Two kinds of companion templates are discovered:
     *   - page templates (the selectable "Template" on a page) — keyed by their
     *     filename, with `post_type` null;
     *   - single / archive templates for custom post types
     *     (`single-<type>.php`, `archive-<type>.php`) — keyed by their filename,
     *     with `post_type` set so the meta box can show when editing that CPT.
     *
     * @return array<string, array{title: string, template: string, post_type: ?string, fields: array}>
     */
    public static function discover(): array
    {
        $groups = [];

        // 1) Selectable page templates.
        $theme = wp_get_theme();
        foreach ($theme->get_page_templates() as $filename => $title) {
            $group = self::discoverGroup($filename, (string) $title, null);
            if ($group) {
                $groups[$filename] = $group;
            }
        }

        // 2) Single / archive templates for custom post types. WordPress picks
        //    `single-<post_type>.php` automatically for that post type, so no
        //    "Template Name" header or page-template meta is involved.
        $themeDir = get_template_directory();
        $cptTemplateRegex = '/^(single|archive)-([a-z0-9_-]+)\.php$/';

        foreach (scandir($themeDir) as $file) {
            if (!preg_match($cptTemplateRegex, $file, $m)) {
                continue;
            }

            $postType = $m[2];
            $group = self::discoverGroup($file, ucfirst($postType) . ' (fields)', $postType);
            if ($group) {
                $groups[$file] = $group;
            }
        }

        // 3) Standalone field files for custom post types
        //    (`post-types/<post_type>.yml`). These attach fields to a CPT without
        //    needing a single / archive template. A single / archive companion
        //    yml for the same post type takes precedence, so only one box (and
        //    one nonce) is ever rendered for a post type.
        $claimedTypes = array_filter(array_column($groups, 'post_type'));

        foreach (glob($themeDir . '/post-types/*.yml') ?: [] as $yml) {
            $postType = basename($yml, '.yml');
            if (in_array($postType, $claimedTypes, true)) {
                continue;
            }

            // keyed without the extension so the box id / nonce slug has no dot
            $key = 'post-types/' . $postType;
            $group = self::groupFromYml($yml, $key, ucfirst($postType) . ' (fields)', $postType);
            if ($group) {
                $groups[$key] = $group;
            }
        }

        return $groups;
    }

    /**
     * Build a companion group from a theme template file's sibling yml. Returns
     * null when there is no usable yml (no file, no `fields:` block, or empty).
     *
     * @param string      $filename  the template file name (e.g. "tpl-flexible.php")
     * @param string      $title     a human title for the group
     * @param string|null $postType  the CPT this template belongs to (null for pages)
     * @return array|null
     */
    private static function discoverGroup(string $filename, string $title, ?string $postType): ?array
    {
        $base = get_theme_file_path($filename);
        $yml = substr($base, 0, -strlen('.php')) . '.yml';

        return self::groupFromYml($yml, $filename, $title, $postType);
    }

    /**
     * Build a companion group from a yml file path. Returns null when there is
     * no usable yml (no file, no `fields:` block, or empty).
     *
     * @param string      $yml       absolute path to the yml file
     * @param string      $key       the group key (template file name, or `post-types/<type>`)
     * @param string      $title     a human title for the group
     * @param string|null $postType  the CPT this group belongs to (null for pages)
     * @return array|null
     */
    private static function groupFromYml(string $yml, string $key, string $title, ?string $postType): ?array
    {
        if (!file_exists($yml)) {
            return null;
        }

        $raw = Yaml::parseFile($yml);

        if (!is_array($raw) || empty($raw['fields']) || !is_array($raw['fields'])) {
            return null;
        }

        // Top-level `imports:` shares slice layouts with every flexible field in
        // this file (see resolveLayoutImports).
        $errBefore = count(self::$importErrors);
        $importedLayouts = self::resolveLayoutImports(isset($raw['imports']) ? $raw['imports'] : null);
        $importErrors = array_slice(self::$importErrors, $errBefore);

        $fields = [];
        foreach ($raw['fields'] as $name => $def) {
            $fields[] = self::normalizeField($name, $def, $importedLayouts, $importErrors);
        }

        if (empty($fields)) {
            return null;
        }

        return [
            'title' => (string) $title,
            'template' => $key,
            'post_type' => $postType,
            'fields' => $fields,
            'hidden' => self::normalizeHidden(isset($raw['hidden']) ? $raw['hidden'] : null),
        ];
    }

    /**
     * Normalize the top-level `hidden:` key from a companion yml into a list of
     * editor areas to hide on the post edit screen. Accepts a scalar, comma
     * separated string, or array. Supported areas: content, excerpt, title.
     *
     * @return string[]
     */
    public static function normalizeHidden($hidden): array
    {
        if ($hidden === null || $hidden === '') {
            return [];
        }

        $items = is_array($hidden) ? $hidden : array_map('trim', explode(',', (string) $hidden));

        $areas = [];
        foreach ($items as $item) {
            $item = strtolower(trim((string) $item));
            if ($item !== '' && !in_array($item, $areas, true)) {
                $areas[] = $item;
            }
        }

        return $areas;
    }

    /**
     * Normalize a single field definition from the yml `fields:` block.
     *
     * @param array $importedLayouts raw layout definitions inherited from the
     *                               file's top-level `imports:` (flexible only)
     * @param array $importErrors    messages from resolving those imports
     */
    public static function normalizeField($name, $def, array $importedLayouts = [], array $importErrors = []): array
    {
        $name = (string) $name;

        // Bare scalar definition (e.g. `headline: text`).
        if (!is_array($def)) {
            $type = (string) $def;
            $def = [];
        }

        $type = isset($def['type']) ? (string) $def['type'] : $type;

        $label = isset($def['label'])
            ? (string) $def['label']
            : ucwords(str_replace(['-', '_'], ' ', $name));

        $field = [
            'name' => $name,
            'type' => $type,
            'label' => $label,
        ];

        foreach (['default', 'placeholder', 'options', 'required', 'hint'] as $key) {
            if (isset($def[$key])) {
                $field[$key] = $def[$key];
            }
        }

        // For repeater fields, `add button text:` sets the add button label
        // verbatim (e.g. "Add Feature"); unset means the plain "Add Row"
        // fallback (see renderRepeaterField).
        if (isset($def['add button text']) && trim((string) $def['add button text']) !== '') {
            $field['add button text'] = (string) $def['add button text'];
        }

        // For media fields, `output` controls how the stored attachment is
        // resolved on the frontend (id / url / alt / html / array).
        if (isset($def['output'])) {
            $field['output'] = (string) $def['output'];
        }

        // For select fields, a plain list of strings is promoted to a
        // self-keyed map (key = value = text) so the stored value is the option
        // label itself. An explicit key/value map is left as-is.
        if ($type === 'select' && isset($field['options']) && is_array($field['options'])) {
            $field['options'] = self::normalizeSelectOptions($field['options']);
        }

        // For repeater fields, `fields:` declares the sub-fields each row holds.
        // Each sub-field is normalized exactly like a top-level field (so nested
        // media keeps its `output`).
        if ($type === 'repeater' && isset($def['fields']) && is_array($def['fields'])) {
            $subs = [];
            foreach ($def['fields'] as $subName => $subDef) {
                $subs[] = self::normalizeField($subName, $subDef);
            }
            $field['fields'] = $subs;
        }

        // For flexible fields, `layouts:` declares the named blocks the editor
        // can add. Each layout has a `name`, optional `message` (informational,
        // no fields) and a `fields:` map of sub-fields. Layouts can also come
        // from shared slice files via the file's top-level `imports:`; imported
        // layouts load first and a local layout with the same name overrides it
        // in place. With top-level imports in place, a flexible field needs no
        // layout description below it unless it adds/overrides layouts of its
        // own.
        if ($type === 'flexible') {
            // `imports:` is only valid at the top of a yml file (see
            // groupFromYml). Inside a field definition it is a mistake, so
            // surface it rather than silently honouring it.
            if (isset($def['imports'])) {
                $importErrors[] = $name . ': imports: is only allowed at the top of the yml file, not inside a field.';
            }

            $merged = self::mergeLayoutLists(
                $importedLayouts,
                isset($def['layouts']) && is_array($def['layouts']) ? $def['layouts'] : []
            );

            $layouts = [];
            foreach ($merged as $layout) {
                if (!is_array($layout) || empty($layout['name'])) {
                    continue;
                }
                $layout = self::normalizeFlexibleLayout((string) $layout['name'], $layout);
                $layouts[$layout['name']] = $layout;
            }
            $field['layouts'] = $layouts;

            if ($importErrors) {
                $field['import_errors'] = array_values(array_unique($importErrors));
            }
        }

        return $field;
    }

    /**
     * Resolve a yml `imports:` value (a path or list of paths, relative to the
     * theme root) into a merged, ordered list of raw layout definitions.
     *
     * Each imported file may itself declare `layouts:` and nested `imports:`
     * (recursion is cycle-guarded). Imported files must live inside the theme
     * directory. Failures are recorded in $importErrors (rendered as an admin
     * notice) rather than silently dropping slices.
     *
     * @param  string|array|null $imports
     * @param  array             $seen   absolute paths already in this chain
     * @return array list of raw layout definitions
     */
    private static function resolveLayoutImports($imports, array $seen = []): array
    {
        if ($imports === null || $imports === '') {
            return [];
        }
        if (is_string($imports)) {
            $imports = [$imports];
        }
        if (!is_array($imports)) {
            self::$importErrors[] = 'imports: must be a file path or a list of file paths.';
            return [];
        }

        $themeReal = realpath(get_template_directory());
        if ($themeReal === false) {
            return [];
        }

        $layouts = [];
        foreach ($imports as $import) {
            $import = trim((string) $import);
            if ($import === '') {
                continue;
            }

            $path = realpath(get_template_directory() . '/' . ltrim($import, '/'));
            if ($path === false || !is_file($path) || strpos($path, $themeReal . DIRECTORY_SEPARATOR) !== 0) {
                self::$importErrors[] = 'slice import not found (or outside the theme): ' . $import;
                continue;
            }
            if (in_array($path, $seen, true)) {
                continue;
            }
            $seen[] = $path;

            $raw = Yaml::parseFile($path);
            if (!is_array($raw)) {
                self::$importErrors[] = 'slice import is not readable YAML: ' . $import;
                continue;
            }

            if (isset($raw['imports'])) {
                $layouts = self::mergeLayoutLists($layouts, self::resolveLayoutImports($raw['imports'], $seen));
            }
            $fileLayouts = (isset($raw['layouts']) && is_array($raw['layouts'])) ? $raw['layouts'] : [];
            $layouts = self::mergeLayoutLists($layouts, $fileLayouts);
        }

        return $layouts;
    }

    /**
     * Merge raw layout lists by layout name: a later definition replaces an
     * earlier one with the same name in place (keeping its position); unseen
     * names are appended in order. Layouts may arrive as a list (each carrying
     * `name:`) or as a yml map keyed by layout name; the key names a layout
     * that does not declare its own `name:`.
     */
    private static function mergeLayoutLists(array $base, array $overlay): array
    {
        $keyed = [];
        foreach ([$base, $overlay] as $list) {
            foreach ($list as $key => $layout) {
                if (!is_array($layout)) {
                    continue;
                }
                $name = isset($layout['name']) ? (string) $layout['name'] : (string) $key;
                if ($name === '') {
                    continue;
                }
                $layout['name'] = $name;
                $keyed[$name] = $layout;
            }
        }
        return array_values($keyed);
    }

    /**
     * Normalise a select field's `options` so the stored value is meaningful.
     *
     * A plain list of strings (`[Red, Black, White]`) is promoted to a
     * self-keyed map (`Red => Red`, ...) so the option's `value` equals its
     * label and that label is what gets saved to the DB. An explicit key/value
     * map is returned unchanged.
     *
     * @param array $options raw options from the yml
     * @return array normalised value => text map
     */
    private static function normalizeSelectOptions(array $options): array
    {
        // A plain (0..n) list of scalars -> self-keyed by value.
        $isList = array_keys($options) === range(0, count($options) - 1);

        if ($isList) {
            $selfKeyed = [];
            foreach ($options as $text) {
                $key = (string) $text;
                $selfKeyed[$key] = $key;
            }
            return $selfKeyed;
        }

        return $options;
    }

    /**
     * Normalise a single flexible layout definition.
     *
     * A layout may carry an informational `message` either at the layout level
     * (`message:`) or as the sole bare-scalar entry under `fields:`
     * (`fields: { message: "..." }`). The latter form is promoted to the
     * layout's message so the block can render it with no sub-fields.
     */
    private static function normalizeFlexibleLayout(string $name, array $def): array
    {
        $layout = [
            'name' => $name,
            'label' => $name,
            'fields' => [],
        ];

        if (isset($def['label'])) {
            $layout['label'] = (string) $def['label'];
        }

        if (isset($def['message'])) {
            $layout['message'] = (string) $def['message'];
        }

        // `description:` is short helper text shown in the slice picker modal
        // alongside the layout's title and thumbnail.
        if (isset($def['description'])) {
            $layout['description'] = (string) $def['description'];
        }

        // `thumbnail:` previews the slice in the picker modal. A bare filename
        // resolves inside assets/; a value with a slash is relative to the
        // theme root (e.g. `assets/thumbnails/hero` for a dedicated folder).
        // The extension may be omitted (see layoutThumbnailUrl).
        if (isset($def['thumbnail']) && trim((string) $def['thumbnail']) !== '') {
            $url = self::layoutThumbnailUrl((string) $def['thumbnail']);
            if ($url !== null) {
                $layout['thumbnail'] = $url;
            }
        }

        if (isset($def['fields']) && is_array($def['fields'])) {
            foreach ($def['fields'] as $subName => $subDef) {
                // A bare `message:` scalar under fields: is layout info, not a field.
                if ($subName === 'message' && !is_array($subDef)) {
                    if (!isset($layout['message'])) {
                        $layout['message'] = (string) $subDef;
                    }
                    continue;
                }
                $layout['fields'][] = self::normalizeField($subName, $subDef);
            }
        }

        return $layout;
    }

    /**
     * Resolve a layout's `thumbnail:` value to a theme URL.
     *
     * A bare filename (no slash) is looked up inside `assets/`; a value
     * containing a slash is treated as relative to the theme root (so a
     * dedicated folder is explicit: `assets/thumbnails/hero`). The
     * extension may be omitted, in which case common image extensions are
     * tried in order (png first, so dropping in a real PNG replaces a shipped
     * .svg placeholder with no yml change). Returns null when no file exists,
     * which renders a placeholder tile in the picker.
     */
    private static function layoutThumbnailUrl(string $thumbnail): ?string
    {
        $relative = ltrim(trim($thumbnail), '/');
        if ($relative === '') {
            return null;
        }

        if (strpos($relative, '/') === false) {
            $relative = 'assets/' . $relative;
        }

        $candidates = [$relative];
        if (!preg_match('/\.(png|jpe?g|webp|svg|gif)$/i', $relative)) {
            foreach (['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'] as $ext) {
                $candidates[] = $relative . '.' . $ext;
            }
        }

        $themeDir = get_template_directory();
        foreach ($candidates as $candidate) {
            if (file_exists($themeDir . '/' . $candidate)) {
                return get_template_directory_uri() . '/' . $candidate;
            }
        }

        return null;
    }

    /**
     * Find a flexible layout definition by name.
     *
     * @return array|null
     */
    private static function findFlexibleLayout(array $field, string $layoutName): ?array
    {
        $layouts = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : [];
        return isset($layouts[$layoutName]) ? $layouts[$layoutName] : null;
    }

    /**
     * Whether any field (including repeater / flexible sub-fields) is a wysiwyg
     * editor. Used to decide whether to enqueue the TinyMCE editor assets.
     *
     * @param array $fields
     * @return bool
     */
    public static function needsWysiwyg(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'wysiwyg') {
                return true;
            }
            if (isset($field['fields']) && self::needsWysiwyg($field['fields'])) {
                return true;
            }
            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (self::needsWysiwyg($layout['fields'] ?? [])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Determine whether the saved page template matches a discovered template file.
     */
    public static function templateMatches(?string $currentTemplate, string $template): bool
    {
        // Pages default to the theme's template when no explicit choice is saved.
        if ($currentTemplate === '' || $currentTemplate === 'default') {
            $currentTemplate = wp_get_theme()->get_template();
        }

        return $currentTemplate === $template;
    }

    /**
     * Whether a discovered companion group applies to a given post. A group
     * matches when either its page template matches the post's saved template,
     * or it is bound to a custom post type that the post belongs to.
     *
     * @param array             $group           the discovered group (needs `template` + `post_type`)
     * @param \WP_Post|object   $post            the post being edited
     * @param string|null       $currentTemplate the saved `_wp_page_template` value
     * @return bool
     */
    public static function groupAppliesTo(array $group, $post, ?string $currentTemplate = null): bool
    {
        $postType = $group['post_type'] ?? null;

        // CPT-backed group: match on the post's type.
        if ($postType !== null && !empty($post->post_type) && $post->post_type === $postType) {
            return true;
        }

        // Page-template-backed group: match on the saved template.
        if ($currentTemplate === null) {
            $currentTemplate = get_post_meta($post->ID, '_wp_page_template', true);
        }

        return self::templateMatches($currentTemplate, $group['template']);
    }

    /**
     * Render the full meta box contents (nonce + fields).
     *
     * @param array<string, mixed> $values current post meta values keyed by field name
     */
    public static function renderBox(string $boxId, string $template, array $fields, array $values = []): string
    {
        $fieldsHtml = [];
        foreach ($fields as $field) {
            $fieldsHtml[] = self::renderField($field, $values[$field['name']] ?? '');
        }

        return FieldTemplates::render('box', [
            'nonce' => wp_nonce_field('rad_companion_' . self::slug($template), 'rad_companion_nonce', true, false),
            'fields' => implode('', $fieldsHtml),
        ]);
    }

    /**
     * Global wp.media picker script. Emitted by every media field (and
     * self-guarded via a window flag) so the picker works anywhere fields
     * render — post metaboxes and options pages alike — while the document
     * level bindings are only registered once per page.
     */
    private static function mediaScript(): string
    {
        return CompanionAssets::script('media.js');
    }

    /**
     * Render a single field, wrapped in a per-field container so the shared
     * stylesheet can manage field spacing/typography (see adminStyles()).
     */
    public static function renderField(array $field, $value = ''): string
    {
        $type = isset($field['type']) ? $field['type'] : 'text';
        return FieldTemplates::render('field', [
            'typeClass' => self::dashedSlug($type),
            'styles' => self::adminStyles(),
            'inner' => self::renderFieldInner($field, $value),
        ]);
    }

    /**
     * The companion fields admin stylesheet, emitted once per page. Covers the
     * generic field chrome (labels, spacing), media previews, repeaters,
     * flexible blocks and the slice picker modal.
     */
    private static function adminStyles(): string
    {
        if (self::$adminStylesPrinted) {
            return '';
        }
        self::$adminStylesPrinted = true;

        return '<style>'
            . CompanionAssets::file('companion-fields.css')
            . CompanionAssets::file('flexible-picker.css')
            . '</style>';
    }

    /**
     * Render a single field's control(s). Unknown / unimplemented types render
     * a visible placeholder instead of being silently dropped.
     */
    private static function renderFieldInner(array $field, $value = ''): string
    {
        $type = $field['type'];
        $id = 'rad_' . $field['name'];
        $required = !empty($field['required']);
        $value = (string) $value;

        // When no value is set, fall back to the field's declared `default` so
        // freshly-added blocks (and empty stored values) render pre-filled.
        if ($value === '' && isset($field['default'])) {
            $value = is_scalar($field['default']) ? (string) $field['default'] : '';
        }

        // Each branch builds only the control markup; the label and hint are
        // wrapped around it by field-inner.hbs so .rad-companion-field can lay
        // them out as a labelled row (label column on the left, control and
        // hint on the right).
        switch ($type) {
            case 'textarea':
                $control = FieldTemplates::render('textarea', [
                    'id' => $id,
                    'name' => $id,
                    'required' => $required,
                    'value' => $value,
                ]);
                break;

            case 'select':
                $options = isset($field['options']) ? (array) $field['options'] : [];
                // When nothing is selected yet (no value and no `default`),
                // pre-select the first option.
                $firstOptionValue = '';
                if ($value === '' && $options) {
                    $firstKey = array_key_first($options);
                    $firstOptionValue = (string) $firstKey;
                }
                $opts = [];
                foreach ($options as $optionValue => $text) {
                    $optionValue = (string) $optionValue;
                    $opts[] = [
                        'value' => $optionValue,
                        'text' => (string) $text,
                        'selected' => ($value !== '' && $value === $optionValue)
                            || ($value === '' && $optionValue === $firstOptionValue),
                    ];
                }
                $control = FieldTemplates::render('select', [
                    'id' => $id,
                    'name' => $id,
                    'required' => $required,
                    'options' => $opts,
                ]);
                break;

            case 'checkbox':
                // The field label lives in the label column; its `for` still
                // toggles the box, so the control is just the (styled) box.
                $control = FieldTemplates::render('checkbox', [
                    'id' => $id,
                    'name' => $id,
                    'checked' => $value === '1' || $value === true || $value === 'true',
                    'required' => $required,
                ]);
                break;

            case 'media':
                $control = self::renderMediaField($field, $value, $id);
                break;

            case 'repeater':
                $control = self::renderRepeaterField($field, $value, $id);
                break;

            case 'flexible':
                $control = self::renderFlexibleField($field, $value, $id);
                break;

            case 'wysiwyg':
                $control = self::renderWysiwygField($field, $value, $id);
                break;

            // All single-line input types (text, email, number, url, ...)
            case 'text':
            case 'email':
            case 'number':
            case 'tel':
            case 'url':
            case 'password':
            case 'date':
            case 'datetime-local':
            case 'time':
            case 'week':
            case 'month':
                $control = FieldTemplates::render('input', [
                    'type' => $type,
                    'id' => $id,
                    'name' => $id,
                    'required' => $required,
                    'value' => $value,
                    'hasPlaceholder' => isset($field['placeholder']),
                    'placeholder' => isset($field['placeholder']) ? (string) $field['placeholder'] : '',
                ]);
                break;

            default:
                $control = FieldTemplates::render('unimplemented', ['type' => $type]);
                break;
        }

        return FieldTemplates::render('field-inner', [
            'required' => $required,
            'id' => $id,
            'label' => (string) $field['label'],
            'control' => $control,
            'hasHint' => isset($field['hint']),
            'hint' => isset($field['hint']) ? (string) $field['hint'] : '',
        ]);
    }

    /**
     * Render a media (image) field. The attachment id is stored as JSON so the
     * frontend can resolve it to a url / alt / full array on demand.
     */
    private static function renderMediaField(array $field, $value, string $id): string
    {
        $current = self::mediaValueToArray($value);

        $currentId = $current['id'] ?? '';
        $currentUrl = $current['url'] ?? '';
        $currentAlt = $current['alt'] ?? '';

        // The admin input always carries a JSON {id,url,alt} string so the JS
        // picker/hydrate can parse it. The DB may hold a serialized value; this
        // normalises either shape into a stable JSON payload.
        $inputValue = json_encode([
            'id' => (int) $currentId,
            'url' => (string) $currentUrl,
            'alt' => (string) $currentAlt,
        ]);

        $preview = $currentUrl !== ''
            ? '<img class="rad-companion-media-img" src="' . esc_url($currentUrl) . '" alt="' . esc_attr($currentAlt) . '" />'
            : '<p class="description">No media selected.</p>';

        return FieldTemplates::render('media', [
            'fieldName' => $field['name'],
            'id' => $id,
            'name' => $id,
            'json' => $inputValue,
            'currentId' => $currentId,
            'preview' => $preview,
            'script' => self::mediaScript(),
        ]);
    }

    /**
     * Render a WYSIWYG field using the default WordPress TinyMCE editor.
     *
     * The editor's textarea is named `rad_<name>` so the save handler reads its
     * HTML content directly. A unique editor id is derived from the field id so
     * multiple wysiwyg fields (including nested ones in rows) coexist without
     * colliding.
     */
    private static function renderWysiwygField(array $field, $value, string $id): string
    {
        // Unique editor id derived from the (already unique) input id. The wp_editor
        // instance id must be alphanumeric without brackets, so map the scoped
        // input id (e.g. rad_block-0[black_content]) to a safe editor token.
        $editorId = 'rad_wysiwyg_' . self::dashedSlug(str_replace('rad_', '', $id));

        // wp_editor() echoes its markup and returns nothing, so capture it with an
        // output buffer. This keeps the editor HTML inside the string we return.
        ob_start();
        wp_editor(
            (string) $value,
            $editorId,
            [
                'textarea_name' => $id,
                'media_buttons' => true,
                'default_editor' => 'tinymce',
                'editor_height' => 300,
                'teeny' => false,
            ]
        );
        $editor = ob_get_clean();

        return FieldTemplates::render('wysiwyg', [
            'id' => 'rad-companion-wysiwyg-' . self::dashedSlug($id),
            'editor' => $editor,
            'script' => self::wysiwygScript(),
        ]);
    }

    /**
     * Shared script that flushes TinyMCE editor content into its backing textarea
     * right before the post form submits, so top-level wysiwyg fields submit their
     * current HTML. Emitted once per wysiwyg field (idempotent via a flag).
     */
    private static function wysiwygScript(): string
    {
        return CompanionAssets::script('wysiwyg.js');
    }

    /**
     * Render a repeater field: a list of rows (each holding the sub-fields),
     * an "Add Row" button, per-row remove, and drag-and-drop reordering. The
     * hidden `rad_<name>` input holds the serialized JSON array submitted on save.
     */
    private static function renderRepeaterField(array $field, $value, string $id): string
    {
        $subFields = isset($field['fields']) ? $field['fields'] : [];
        // The add button text is explicit: `add button text:` in the yml, or a
        // plain "Add Row" fallback. No label singularisation magic. The field's
        // label (data-item-label) is reused as-is in the remove confirm.
        $addText = isset($field['add button text']) && trim((string) $field['add button text']) !== ''
            ? (string) $field['add button text']
            : 'Add Row';
        $itemLabel = (string) ($field['label'] ?? '');

        // Decode stored rows; each sub-value is keyed by sub-field name. The DB
        // stores these serialized; normalise any media sub-field into an array so
        // the row inputs can render JSON the JS picker expects.
        $rows = [];
        $decoded = self::unserializeValue($value);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = [];
                foreach ($subFields as $sub) {
                    $subValue = $row[$sub['name']] ?? '';
                    if ($sub['type'] === 'media') {
                        $normalized[$sub['name']] = self::mediaValueToArray($subValue);
                    } else {
                        $normalized[$sub['name']] = $subValue;
                    }
                }
                $rows[] = $normalized;
            }
        }

        // ACF renders repeaters as a table: a header row of sub-field labels,
        // then one grid row per row, columns aligned to the header. The column
        // template (gutter | N sub-field cells | actions) is identical on the
        // head and every row; it is inlined as a style because the column
        // count varies per repeater and CSS cannot repeat() on a variable.
        $count = count($subFields);
        // Columns never shrink below 120px: past that the table keeps its
        // width and scrolls horizontally (see .rad-companion-repeater-table).
        // The shared min-width keeps the head and every row on identical
        // track widths so their columns stay aligned while scrolling.
        $colsStyle = $count > 0
            ? ' style="grid-template-columns:48px repeat(' . (int) $count . ', minmax(160px,1fr)) 64px;'
            . 'min-width:' . (112 + 160 * (int) $count) . 'px"'
            : '';

        $head = '';
        if ($count > 0) {
            $headCells = [];
            foreach ($subFields as $sub) {
                $headCells[] = ['label' => (string) $sub['label']];
            }
            $head = FieldTemplates::render('repeater-head', [
                'colsAttr' => $colsStyle,
                'cells' => $headCells,
            ]);
        }

        // Sub-field input names render as rad_<rowToken>[<sub>]. Using a short
        // row token (blank / row-N) avoids a double rad_ prefix and keeps the
        // JS name-matching regex simple.
        $blankRow = self::renderRepeaterRow($subFields, [], 'blank', 1, $colsStyle);

        $existingRows = [];
        foreach ($rows as $i => $row) {
            $existingRows[] = self::renderRepeaterRow($subFields, $row, 'row-' . $i, $i + 1, $colsStyle);
        }

        // The top-level hidden input carries the normalised rows as JSON so the
        // JS sync() can rebuild it; the DB stays serialized (see sanitizeValue).
        $inputValue = json_encode($rows);

        return FieldTemplates::render('repeater', [
            'fieldName' => $field['name'],
            'itemLabel' => $itemLabel,
            'id' => $id,
            'name' => $id,
            'json' => $inputValue,
            'blankRow' => $blankRow,
            'head' => $head,
            'rows' => implode('', $existingRows),
            'addText' => $addText,
            'script' => self::repeaterScript(),
        ]);
    }

    /**
     * Render a flexible field: a list of blocks, each tied to a named layout
     * from the yml `layouts:` map. A layout selector at the bottom lets the
     * editor add a block of any layout; each block is a draggable, removable box
     * holding that layout's sub-fields. Storage is the same shape as a repeater
     * (serialized array), except each row carries a `_layout` key so blocks keep
     * their identity.
     */
    private static function renderFlexibleField(array $field, $value, string $id): string
    {
        $layouts = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : [];

        // Decode stored blocks; normalise media sub-fields into arrays so the
        // block inputs can render JSON the JS picker expects.
        $blocks = [];
        $decoded = self::unserializeValue($value);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $layoutName = (string) ($row['_layout'] ?? '');
                $layout = self::findFlexibleLayout($field, $layoutName);
                if (!$layout) {
                    continue;
                }
                $normalized = ['_layout' => $layoutName];
                foreach ($layout['fields'] as $sub) {
                    $subValue = $row[$sub['name']] ?? '';
                    $normalized[$sub['name']] = ($sub['type'] === 'media')
                        ? self::mediaValueToArray($subValue)
                        : $subValue;
                }
                $blocks[] = $normalized;
            }
        }

        // One hidden template per layout so the JS can clone a fresh block.
        $templates = [];
        foreach ($layouts as $layoutName => $layout) {
            $safe = self::dashedSlug($layoutName);
            $templates[] = FieldTemplates::render('flexible-template', [
                'fieldName' => $field['name'],
                'safe' => $safe,
                'block' => self::renderFlexibleBlock($layout, [], 'blank-' . $safe),
            ]);
        }

        $existing = [];
        foreach ($blocks as $i => $block) {
            $layout = self::findFlexibleLayout($field, (string) $block['_layout']);
            if (!$layout) {
                continue;
            }
            $existing[] = self::renderFlexibleBlock($layout, $block, 'block-' . $i);
        }

        // Top-level hidden input carries the normalised blocks as JSON for the
        // JS sync(); the DB stays serialized (see sanitizeValue).
        $inputValue = json_encode($blocks);

        // Surface broken `imports:` in the field itself, so a misnamed slice
        // file is visible to whoever is editing rather than silently missing.
        $notices = '';
        if (!empty($field['import_errors'])) {
            $errors = [];
            foreach ($field['import_errors'] as $error) {
                $errors[] = ['message' => (string) $error];
            }
            $notices = FieldTemplates::render('flexible-notice', ['errors' => $errors]);
        }

        // The "collapse all / expand all" control rendered above and below the
        // blocks so it's reachable without scrolling. Its label is kept in
        // sync by the script based on whether any block is currently expanded.
        $collapseAll = function (string $placement) {
            return FieldTemplates::render('flexible-bulk', ['placement' => $placement]);
        };

        return FieldTemplates::render('flexible', [
            'fieldName' => $field['name'],
            'id' => $id,
            'name' => $id,
            'json' => $inputValue,
            'notices' => $notices,
            'templates' => implode('', $templates),
            'bulkTop' => $collapseAll('top'),
            'bulkBottom' => $collapseAll('bottom'),
            'blocks' => implode('', $existing),
            'selector' => FieldTemplates::render('flexible-add', [
                'modal' => self::flexiblePickerModal($field, $layouts),
            ]),
            'script' => self::flexibleScript(),
        ]);
    }

    /**
     * Build the "Add Slice" picker modal for a flexible field: a dialog with a
     * search box and a grid of cards — one per layout, each showing its
     * thumbnail (`thumbnail:`), label and description (`description:`). The
     * markup is emitted hidden inside the field wrapper; the script moves it to
     * <body> the first time it is opened so fixed positioning escapes any
     * transformed/overflowed ancestors.
     */
    private static function flexiblePickerModal(array $field, array $layouts): string
    {
        $cards = [];
        foreach ($layouts as $layoutName => $layout) {
            $thumbnail = isset($layout['thumbnail']) ? (string) $layout['thumbnail'] : '';
            $description = isset($layout['description']) ? (string) $layout['description'] : '';
            $search = strtolower($layoutName . ' ' . ($layout['label'] ?? '') . ' ' . $description);

            $media = $thumbnail !== ''
                ? '<img class="rad-flexible-card-img" src="' . esc_url($thumbnail) . '" alt="" loading="lazy" />'
                : '<span class="rad-flexible-card-img--empty" aria-hidden="true"></span>';

            $cards[] = FieldTemplates::render('flexible-card', [
                'layoutName' => $layoutName,
                'search' => $search,
                'media' => $media,
                'label' => (string) $layout['label'],
                'description' => $description,
            ]);
        }

        return FieldTemplates::render('flexible-modal', [
            'fieldName' => $field['name'],
            'cards' => implode('', $cards),
        ]);
    }

    /**
     * Render a single flexible block (a layout's sub-fields in a box). Sub-field
     * input names are scoped under `rad_<blockKey>[<sub>]` so the value can be
     * rebuilt from the DOM; the block's layout is carried in a hidden input.
     *
     * @param array $layout  the layout definition (name, label, fields)
     * @param array $values  current values keyed by sub-field name (+ _layout)
     * @param string $blockKey unique per-block token (e.g. "block-0")
     */
    private static function renderFlexibleBlock(array $layout, array $values, string $blockKey): string
    {
        $fieldsHtml = [];
        foreach ($layout['fields'] as $sub) {
            $val = $values[$sub['name']] ?? '';
            if ($sub['type'] === 'media') {
                $media = self::mediaValueToArray($val);
                $val = json_encode([
                    'id' => (int) ($media['id'] ?? 0),
                    'url' => (string) ($media['url'] ?? ''),
                    'alt' => (string) ($media['alt'] ?? ''),
                ]);
            } elseif (is_array($val)) {
                $val = json_encode($val);
            }
            $scoped = self::scopeFieldName($sub, $blockKey);
            $fieldsHtml[] = self::renderField($scoped, (string) $val);
        }

        // Informational layouts (no fields) show their message instead.
        $body = !empty($layout['message']) && empty($layout['fields'])
            ? FieldTemplates::render('flexible-message', ['message' => (string) $layout['message']])
            : implode('', $fieldsHtml);

        return FieldTemplates::render('flexible-block', [
            'layoutName' => $layout['name'],
            'label' => (string) $layout['label'],
            'blockKey' => $blockKey,
            'body' => $body,
        ]);
    }

    /**
     * Shared, self-initializing script for every flexible field. Uses
     * document-level delegation so it works for blocks added later.
     */
    private static function flexibleScript(): string
    {
        return CompanionAssets::script('rekey.js') . CompanionAssets::script('flexible.js');
    }

    /**
     * Shared, self-initializing script for every repeater on the page. Uses
     * document-level delegation so it works for rows added later.
     */
    private static function repeaterScript(): string
    {
        return CompanionAssets::script('rekey.js') . CompanionAssets::script('repeater.js');
    }

    /**
     * Shared helper for re-keying a cloned repeater row / flexible block and
     * re-initialising any WYSIWYG editors it contains.
     *
     * When a new row/block is appended from a `<template>`, its inputs keep the
     * template's key (e.g. `blank` / `blank-<layout>`). This:
     *   1. rewrites every input/textarea `name` + `id` to a fresh unique key so
     *      names stay unique and sync() reads the right sub-fields, and
     *   2. re-targets + inits each TinyMCE / Quicktags editor (which WordPress
     *      only auto-initialises once, for editors present at page load).
     *
     * Exposes window.radRekeyRow($row, oldKey, newKey).
     */
    private static function rekeyScript(): string
    {
        return CompanionAssets::script('rekey.js');
    }

    /**
     * Render a single repeater row. Sub-fields are rendered via renderField with
     * their input names scoped under `rad_<name>[<rowKey>][<sub>]` so the JSON
     * value can be rebuilt from the DOM.
     *
     * @param string $rowKey unique per-row token (e.g. "rad_repeater_0")
     * @param int    $number  1-based row number shown in the gutter
     * @param string $colsStyle grid-template-columns style, shared with the head
     */
    private static function renderRepeaterRow(array $subFields, array $rowValues, string $rowKey, int $number = 1, string $colsStyle = ''): string
    {
        $cells = [];
        foreach ($subFields as $sub) {
            $val = $rowValues[$sub['name']] ?? '';
            // Media sub-fields are arrays (from normalisation). Render them as a
            // JSON {id,url,alt} string so the row's media picker/hydrate can
            // parse it, matching the top-level media fields.
            if ($sub['type'] === 'media') {
                $media = self::mediaValueToArray($val);
                $val = json_encode([
                    'id' => (int) ($media['id'] ?? 0),
                    'url' => (string) ($media['url'] ?? ''),
                    'alt' => (string) ($media['alt'] ?? ''),
                ]);
            } elseif (is_array($val)) {
                $val = json_encode($val);
            }
            $cells[] = FieldTemplates::render('repeater-cell', [
                'class' => 'rad-companion-repeater-cell rad-companion-repeater-cell--' . self::dashedSlug($sub['name']),
                'html' => self::renderField(self::scopeFieldName($sub, $rowKey), (string) $val),
            ]);
        }

        return FieldTemplates::render('repeater-row', [
            'colsAttr' => $colsStyle,
            'number' => $number,
            'cells' => implode('', $cells),
        ]);
    }

    /**
     * Copy a sub-field, renaming it so its inputs nest under the repeater row.
     * The final input name (after renderField prefixes rad_) is
     * `rad_<rowKey>[<sub>]`, e.g. `rad_row-0[title]`.
     */
    private static function scopeFieldName(array $sub, string $rowKey): array
    {
        $scoped = $sub;
        $scoped['name'] = $rowKey . '[' . $sub['name'] . ']';
        return $scoped;
    }

    /**
     * Persist field values on save.
     */
    public static function save(int $post_id, $post, array $groups): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (isset($_GET['preview']) || (isset($_POST['post_type']) && $post->post_type === 'revision')) {
            return;
        }

        $template = get_post_meta($post_id, '_wp_page_template', true);

        foreach ($groups as $filename => $meta) {
            if (!self::groupAppliesTo($meta, $post, $template)) {
                continue;
            }

            $nonce = isset($_POST['rad_companion_nonce']) ? sanitize_text_field(wp_unslash($_POST['rad_companion_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'rad_companion_' . self::slug($filename))) {
                return;
            }

            foreach ($meta['fields'] as $field) {
                $input = 'rad_' . $field['name'];

                if (!isset($_POST[$input])) {
                    // Checkbox that was unchecked has no key; store empty.
                    if ($field['type'] === 'checkbox') {
                        update_post_meta($post_id, $input, '');
                    }
                    continue;
                }

                $value = wp_unslash($_POST[$input]);
                $value = self::sanitizeValue($value, $field['type'], $field);
                update_post_meta($post_id, $input, $value);
            }
        }
    }

    /**
     * Sanitize a raw posted value for a given field type. Used for both
     * top-level fields and repeater sub-fields.
     *
     * @param mixed $value
     * @param string $type
     * @param array|null $field the full field definition (needed for repeater sub-fields)
     * @return mixed
     */
    public static function sanitizeValue($value, string $type, ?array $field = null)
    {
        switch ($type) {
            case 'repeater':
                $rows = json_decode((string) $value, true);
                $subs = (isset($field['fields']) && is_array($field['fields'])) ? $field['fields'] : [];
                $clean = [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $cleanRow = [];
                        foreach ($subs as $sub) {
                            $cleanRow[$sub['name']] = self::sanitizeValue($row[$sub['name']] ?? '', $sub['type'], $sub);
                        }
                        $clean[] = $cleanRow;
                    }
                }
                return serialize($clean);

            case 'flexible':
                $rows = json_decode((string) $value, true);
                $clean = [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $layoutName = sanitize_text_field((string) ($row['_layout'] ?? ''));
                        $layout = self::findFlexibleLayout($field, $layoutName);
                        if (!$layout) {
                            continue;
                        }
                        $cleanRow = ['_layout' => $layoutName];
                        foreach ($layout['fields'] as $sub) {
                            $cleanRow[$sub['name']] = self::sanitizeValue($row[$sub['name']] ?? '', $sub['type'], $sub);
                        }
                        $clean[] = $cleanRow;
                    }
                }
                return serialize($clean);

            case 'media':
                // The field posts a JSON string of {id, url, alt}. Keep only the
                // id (source of truth) plus a url/alt fallback so output can still
                // be produced if the attachment is missing.
                $decoded = json_decode((string) $value, true);
                if (is_array($decoded) && !empty($decoded['id'])) {
                    return serialize([
                        'id' => (int) $decoded['id'],
                        'url' => (string) ($decoded['url'] ?? ''),
                        'alt' => (string) ($decoded['alt'] ?? ''),
                    ]);
                }
                return serialize(['id' => 0, 'url' => '', 'alt' => '']);

            case 'checkbox':
                return $value === '1' ? '1' : '';

            case 'number':
                return is_numeric($value) ? (string) $value : '';

            case 'email':
                return is_email($value) ? $value : '';

            case 'url':
                return (string) esc_url_raw($value);

            case 'wysiwyg':
                // Rich HTML authored in TinyMCE. Strip disallowed tags/attrs.
                return (string) wp_kses_post($value);

            default:
                return (string) wp_kses_post($value);
        }
    }

    /**
     * Replace `{{ rad.<name>-JSON }}` placeholders in post content with the
     * resolved media output requested in the companion yml.
     *
     * @param string $content
     * @return string
     */
    public static function handleRadMediaFilter($content)
    {
        if (!is_string($content) || strpos($content, 'rad.') === false) {
            return $content;
        }

        $re = '/\{\{\s*rad\.([a-zA-Z0-9_\-]+)\-JSON\s*\}\}/';

        return (string) preg_replace_callback($re, function ($m) {
            return self::resolveMedia($m[1]);
        }, $content);
    }

    /**
     * Resolve a media field (by name) for the current post into the output
     * type declared in its companion yml.
     *
     * @return string
     */
    public static function resolveMedia(string $name): string
    {
        $post = get_post();
        if (!$post) {
            return '';
        }

        $resolved = self::resolveField($name, $post);

        return is_string($resolved) ? $resolved : '';
    }

    /**
     * Run a stored wysiwyg (rich-text) value through the `the_content` filter so
     * blank lines become paragraphs (`wpautop`), shortcodes are processed, and
     * any other content filters apply — matching how the main post body renders.
     * Empty values pass through untouched so we don't wrap blanks in `<p></p>`.
     *
     * @param string $value the raw stored HTML
     * @return string
     */
    private static function filterWysiwyg($value)
    {
        if ($value === null || $value === '' || trim((string) $value) === '') {
            return (string) $value;
        }

        return apply_filters('the_content', (string) $value);
    }

    /**
     * Convert the line breaks a person typed into a plain textarea field into
     * HTML `<br>` tags so they carry through to the frontend. Empty values pass
     * through untouched so we don't emit a stray `<br>`.
     *
     * @param string $value the raw stored textarea text
     * @return string
     */
    private static function formatTextarea($value)
    {
        if ($value === null || $value === '' || trim((string) $value) === '') {
            return (string) $value;
        }

        return nl2br((string) $value);
    }

    /**
     * Resolve a single stored sub-field value by its type. This is the shared
     * workhorse for turning raw stored values into what a template should see,
     * used by both the repeater and flexible resolvers (and it recurses so a
     * repeater nested inside a flexible layout — or a repeater inside a
     * repeater — is decoded rather than left as a serialized string).
     *
     *   - media    -> resolved media output (url/id/alt/html/array)
     *   - wysiwyg  -> the_content filter applied
     *   - textarea -> nl2br applied
     *   - repeater -> array of resolved rows (recursive)
     *   - other    -> the raw value
     *
     * @param array     $sub  the normalised sub-field definition (needs `type`)
     * @param mixed     $subValue the raw stored value
     * @param \WP_Post|object|null $post reserved for post-scoped media resolution
     * @return mixed
     */
    private static function resolveSubValue(array $sub, $subValue, $post = null)
    {
        $type = $sub['type'] ?? '';

        switch ($type) {
            case 'media':
                return self::resolveMediaValue($sub, $subValue);

            case 'wysiwyg':
                return self::filterWysiwyg($subValue);

            case 'textarea':
                return self::formatTextarea($subValue);

            case 'repeater':
                return self::resolveRepeaterRows($sub, $subValue, $post);

            default:
                return $subValue;
        }
    }

    /**
     * Decode a stored repeater value into an array of resolved rows so a
     * template can `{{#each}}` over it. Media / wysiwyg / textarea / nested
     * repeater sub-fields are each resolved by their own type.
     *
     * @param array     $field the repeater field definition (needs `fields`)
     * @param mixed     $raw   the raw stored value (serialized for repeaters)
     * @param \WP_Post|object|null $post reserved for post-scoped media resolution
     * @return array
     */
    private static function resolveRepeaterRows(array $field, $raw, $post = null): array
    {
        $rows = [];
        $decoded = self::unserializeValue($raw);

        if (!is_array($decoded)) {
            return $rows;
        }

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cleanRow = [];
            foreach ($field['fields'] as $sub) {
                $subValue = $row[$sub['name']] ?? '';
                $cleanRow[$sub['name']] = self::resolveSubValue($sub, $subValue, $post);
            }
            $rows[] = $cleanRow;
        }

        return $rows;
    }

    /**
     * Resolve a companion field (any type) for a post into the value a template
     * should see:
     *   - media    -> the resolved media output (url/id/alt/html/array)
     *   - repeater -> an array of row objects, each media sub-field resolved by
     *                its own yml `output`
     *   - other    -> the raw post meta value
     *
     * @param \WP_Post|object $post
     * @return mixed
     */
    public static function resolveField(string $name, $post)
    {
        $groups = self::discover();

        $field = null;
        foreach ($groups as $meta) {
            foreach ($meta['fields'] as $f) {
                if ($f['name'] === $name) {
                    $field = $f;
                    break 2;
                }
            }
        }

        $raw = get_post_meta($post->ID, 'rad_' . $name, true);

        if (!$field) {
            return $raw;
        }

        if ($field['type'] === 'media') {
            return self::resolveMediaFor($name, $post, $field);
        }

        if ($field['type'] === 'repeater') {
            return self::resolveRepeaterRows($field, $raw, $post);
        }

        if ($field['type'] === 'flexible') {
            $rows = [];
            $decoded = self::unserializeValue($raw);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $layoutName = (string) ($row['_layout'] ?? '');
                    $layout = self::findFlexibleLayout($field, $layoutName);
                    if (!$layout) {
                        continue;
                    }
                    $cleanRow = ['_layout' => $layoutName];
                    foreach ($layout['fields'] as $sub) {
                        $subValue = $row[$sub['name']] ?? '';
                        $cleanRow[$sub['name']] = self::resolveSubValue($sub, $subValue, $post);
                    }
                    $rows[] = $cleanRow;
                }
            }
            return $rows;
        }

        if ($field['type'] === 'wysiwyg') {
            return self::filterWysiwyg($raw);
        }

        if ($field['type'] === 'textarea') {
            return self::formatTextarea($raw);
        }

        return $raw;
    }

    /**
     * Resolve a single stored field value (any type) into the value a template
     * should see. This is the post-agnostic core of {@see resolveField()} and is
     * shared by every value source that stores companion fields (post meta, site
     * options, ...).
     *
     *   - media    -> the resolved media output (url/id/alt/html/array)
     *   - repeater -> an array of row objects, media sub-fields resolved
     *   - flexible -> an array of block objects, media sub-fields resolved
     *   - other    -> the raw value
     *
     * @param array $field the normalised field definition (needs `type` + sub-fields)
     * @param mixed $raw   the raw stored value (serialized for media/repeater/flexible)
     * @return mixed
     */
    public static function resolveValue(array $field, $raw)
    {
        $type = $field['type'] ?? '';

        if ($type === 'media') {
            return self::resolveMediaValue($field, $raw);
        }

        if ($type === 'repeater') {
            return self::resolveRepeaterRows($field, $raw);
        }

        if ($type === 'flexible') {
            $rows = [];
            $decoded = self::unserializeValue($raw);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $layoutName = (string) ($row['_layout'] ?? '');
                    $layout = self::findFlexibleLayout($field, $layoutName);
                    if (!$layout) {
                        continue;
                    }
                    $cleanRow = ['_layout' => $layoutName];
                    foreach ($layout['fields'] as $sub) {
                        $subValue = $row[$sub['name']] ?? '';
                        $cleanRow[$sub['name']] = self::resolveSubValue($sub, $subValue);
                    }
                    $rows[] = $cleanRow;
                }
            }
            return $rows;
        }

        if ($type === 'wysiwyg') {
            return self::filterWysiwyg($raw);
        }

        if ($type === 'textarea') {
            return self::formatTextarea($raw);
        }

        return $raw;
    }

    /**
     * Resolve a single stored media value to its yml `output`. Post-agnostic:
     * works for any source (post meta, site options) as long as the stored value
     * is the serialized/JSON media shape produced by {@see sanitizeValue()}.
     *
     * @return string
     */
    public static function resolveMediaValue(array $field, $raw): string
    {
        if ($raw === '' || $raw === null) {
            return '';
        }

        $decoded = self::unserializeValue($raw);
        $id = is_array($decoded) ? (int) ($decoded['id'] ?? 0) : 0;

        if ($id <= 0) {
            return '';
        }

        return self::mediaToOutput($id, $decoded, $field['output'] ?? 'url');
    }

    /**
     * Resolve a media field to its yml `output` for a given post.
     *
     * @return string
     */
    private static function resolveMediaFor(string $name, $post, array $field): string
    {
        $raw = get_post_meta($post->ID, 'rad_' . $name, true);
        if ($raw === '' || $raw === null) {
            return '';
        }

        $decoded = self::unserializeValue($raw);
        $id = is_array($decoded) ? (int) ($decoded['id'] ?? 0) : 0;

        if ($id <= 0) {
            return '';
        }

        return self::mediaToOutput($id, $decoded, $field['output'] ?? 'url');
    }

    /**
     * Unserialize a stored companion field value. Falls back to json_decode for
     * legacy JSON-stored values (before the serialize migration).
     *
     * @return mixed
     */
    /**
     * Coerce a media field value (serialized, JSON string, or array) into a
     * plain {id,url,alt} array for admin rendering. Returns an empty array when
     * the value holds no attachment.
     *
     * @return array
     */
    private static function mediaValueToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = self::unserializeValue($value);

        if (!is_array($decoded)) {
            return [];
        }

        return [
            'id' => (int) ($decoded['id'] ?? 0),
            'url' => (string) ($decoded['url'] ?? ''),
            'alt' => (string) ($decoded['alt'] ?? ''),
        ];
    }

    /**
     * Unserialize a stored companion field value. Falls back to json_decode for
     * legacy JSON-stored values (before the serialize migration).
     *
     * @return mixed
     */
    public static function unserializeValue($raw)
    {
        // Unwrap PHP-serialised layers (the DB may hold single- or double-
        // serialised values) until we reach a plain, non-serialised value.
        $value = $raw;
        $guard = 0;
        while (is_string($value) && $guard++ < 5) {
            $candidate = @unserialize($value);
            if ($candidate === false) {
                break;
            }
            $value = $candidate;
        }

        if (is_array($value)) {
            return $value;
        }

        // Legacy format: JSON
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Turn a stored attachment id into the requested output type.
     *
     * @param array|null $decoded the saved JSON (may carry url/alt fallbacks)
     * @return string
     */
    private static function mediaToOutput(int $id, ?array $decoded, string $output): string
    {
        $meta = wp_get_attachment_metadata($id) ?: [];
        $url = $meta['url'] ?? wp_get_attachment_url($id);
        $alt = $meta['alt'] ?? ($decoded['alt'] ?? '');
        $src = wp_get_attachment_image_src($id, 'full');
        $width = (int) ($src[2] ?? ($meta['width'] ?? 0));
        $height = (int) ($src[3] ?? ($meta['height'] ?? 0));

        switch ($output) {
            case 'id':
                return (string) $id;

            case 'alt':
                return (string) $alt;

            case 'html':
                return wp_get_attachment_image($id, 'full', false, ['alt' => $alt]);

            case 'array':
                return json_encode([
                    'id' => $id,
                    'url' => (string) $url,
                    'alt' => (string) $alt,
                    'width' => $width,
                    'height' => $height,
                ]);

            case 'url':
            default:
                return (string) $url;
        }
    }

    private static function slug(string $value): string
    {
        return str_replace(['/', '.php'], '', strtolower($value));
    }

    /**
     * Convert an arbitrary string (e.g. a layout name) into a lowercase,
     * dash-separated token safe for use in element ids and JS lookups.
     */
    private static function dashedSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim($value, '-');
    }

    /**
     * Slug a flexible layout name into the template file name token used by the
     * `flex` helper (e.g. "Black and Red Content" -> "black-and-red-content").
     *
     * @param string $layoutName the layout name as declared in the companion yml
     * @return string
     */
    public static function layoutSlug(string $layoutName): string
    {
        return self::dashedSlug($layoutName);
    }
}
