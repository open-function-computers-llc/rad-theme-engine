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
     * Anything else is deferred (see $UNIMPLEMENTED) so unknown types are
     * surfaced instead of silently dropped.
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
        $cptTemplateRegex = '/^(single|archive)-([a-z0-9_]+)\.php$/';

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

        if (!file_exists($yml)) {
            return null;
        }

        $raw = Yaml::parseFile($yml);

        if (!is_array($raw) || empty($raw['fields']) || !is_array($raw['fields'])) {
            return null;
        }

        $fields = [];
        foreach ($raw['fields'] as $name => $def) {
            $fields[] = self::normalizeField($name, $def);
        }

        if (empty($fields)) {
            return null;
        }

        return [
            'title' => (string) $title,
            'template' => $filename,
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
     */
    public static function normalizeField($name, $def): array
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
        // no fields) and a `fields:` map of sub-fields.
        if ($type === 'flexible' && isset($def['layouts']) && is_array($def['layouts'])) {
            $layouts = [];
            foreach ($def['layouts'] as $layout) {
                if (!is_array($layout) || empty($layout['name'])) {
                    continue;
                }
                $layout = self::normalizeFlexibleLayout((string) $layout['name'], $layout);
                $layouts[$layout['name']] = $layout;
            }
            $field['layouts'] = $layouts;
        }

        return $field;
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
        $html = '<div class="rad-companion-fields">';
        $html .= wp_nonce_field('rad_companion_' . self::slug($template), 'rad_companion_nonce');

        foreach ($fields as $field) {
            $html .= self::renderField($field, $values[$field['name']] ?? '');
        }

        $html .= self::mediaScript();
        $html .= '</div>';

        return $html;
    }

    /**
     * Global wp.media picker for all media fields in a box. Defined once per
     * page so multiple media fields share one picker.
     */
    private static function mediaScript(): string
    {
        return <<<'JS'
<script>
(function () {
    function init() {
        if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
        var jQuery$ = jQuery;

        function render(el, id, url, alt) {
            var preview = el.find('.rad-companion-media-preview');
            preview.removeAttr('data-id').attr('data-id', id);
            if (url) {
                preview.html('<img class="rad-companion-media-img" src="' + url + '" alt="' + (alt || '') + '" />');
            } else {
                preview.html('<p class="description">No media selected.</p>');
            }
        }

        function frame(input, el) {
            var f = wp.media({
                title: 'Choose Media',
                button: { text: 'Use this media' },
                library: { type: 'image' },
                multiple: false,
                modal: true
            });
            f.on('select', function () {
                var att = f.state().get('selection').first().toJSON();
                input.val(JSON.stringify({ id: att.id, url: att.url, alt: att.alt || '' }));
                // Notify any enclosing repeater to re-sync its hidden JSON.
                input.trigger('change');
                render(el, att.id, att.url, att.alt || '');
            });
            return f;
        }

        // Hydrate previews from stored values.
        jQuery$('[id^=rad-companion-media-]').each(function () {
            var el = jQuery$(this);
            var input = el.find('input[type=hidden]');
            var cur = {};
            try { cur = JSON.parse(input.val() || '{}'); } catch (e) { cur = {}; }
            render(el, cur.id || '', cur.url || '', cur.alt || '');
        });

        // Event delegation: works even if this script is stripped from scope.
        jQuery$(document).on('click', 'a.rad-companion-choose', function (e) {
            e.preventDefault();
            var el = jQuery$(this).closest('.rad-companion-media');
            frame(el.find('input[type=hidden]'), el).open();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
JS;
    }

    /**
     * Render a single field. Unknown / unimplemented types render a visible
     * placeholder instead of being silently dropped.
     */
    public static function renderField(array $field, $value = ''): string
    {
        $type = $field['type'];
        $id = 'rad_' . $field['name'];
        $label = esc_html($field['label']);
        $labelTag = '<p class="form-label"><label for="' . $id . '">' . $label . '</label></p>';
        $required = !empty($field['required']);
        $req = $required ? ' required' : '';
        $hint = isset($field['hint']) ? '<p class="description">' . esc_html((string) $field['hint']) . '</p>' : '';
        $value = (string) $value;

        // When no value is set, fall back to the field's declared `default` so
        // freshly-added blocks (and empty stored values) render pre-filled.
        if ($value === '' && isset($field['default'])) {
            $value = is_scalar($field['default']) ? (string) $field['default'] : '';
        }

        switch ($type) {
            case 'textarea':
                return $labelTag
                    . '<textarea id="' . $id . '" name="' . $id . '"' . $req . ' class="large-text">'
                    . esc_textarea($value)
                    . '</textarea>' . $hint;

            case 'select':
                $options = isset($field['options']) ? (array) $field['options'] : [];
                // When nothing is selected yet (no value and no `default`),
                // pre-select the first option.
                $firstOptionValue = '';
                if ($value === '' && $options) {
                    $firstKey = array_key_first($options);
                    $firstOptionValue = (string) $firstKey;
                }
                $opts = '';
                foreach ($options as $optionValue => $text) {
                    $optionValue = (string) $optionValue;
                    $selected = ($value !== '' && $value === $optionValue)
                        || ($value === '' && $optionValue === $firstOptionValue);
                    $opts .= '<option value="' . esc_attr($optionValue) . '"'
                        . ($selected ? ' selected' : '')
                        . '>' . esc_html((string) $text) . '</option>';
                }
                return $labelTag
                    . '<select id="' . $id . '" name="' . $id . '"' . $req . '>' . $opts . '</select>' . $hint;

            case 'checkbox':
                $checked = $value === '1' || $value === true || $value === 'true';
                return '<p class="form-label">'
                    . '<label for="' . $id . '"><input type="checkbox" id="' . $id . '" name="' . $id
                    . '" value="1"' . ($checked ? ' checked' : '') . $req . ' /> ' . $label . '</label>'
                    . '</p>' . $hint;

            case 'media':
                return self::renderMediaField($field, $value, $label, $id, $labelTag, $hint);

            case 'repeater':
                return self::renderRepeaterField($field, $value, $label, $id, $labelTag, $hint);

            case 'flexible':
                return self::renderFlexibleField($field, $value, $label, $id, $labelTag, $hint);

            case 'wysiwyg':
                return self::renderWysiwygField($field, $value, $label, $id, $labelTag, $hint);

            default:
                if (self::unimplemented($type)) {
                    return $labelTag
                        . '<p class="description"><em>' . esc_html($type) . ' fields are not implemented yet.</em></p>'
                        . $hint;
                }

                // All single-line input types (text, email, number, url, ...)
                $typeAttr = in_array($type, self::SUPPORTED, true) ? $type : 'text';
                return $labelTag
                    . '<input type="' . esc_attr($typeAttr) . '" id="' . $id . '" name="' . $id
                    . '"' . $req . ' value="' . esc_attr($value) . '" />'
                    . $hint;
        }
    }

    /**
     * Render a media (image) field. The attachment id is stored as JSON so the
     * frontend can resolve it to a url / alt / full array on demand.
     */
    private static function renderMediaField(array $field, $value, string $label, string $id, string $labelTag, string $hint): string
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

        return $labelTag
            . '<div class="rad-companion-media" id="rad-companion-media-' . esc_attr($field['name']) . '">'
            . '<input type="hidden" id="' . $id . '" name="' . $id . '" value="' . esc_attr($inputValue) . '" />'
            . '<div class="rad-companion-media-preview" data-id="' . esc_attr($currentId) . '">'
            . ($currentUrl !== '' ? '<img class="rad-companion-media-img" src="' . esc_url($currentUrl) . '" alt="' . esc_attr($currentAlt) . '" />' : '<p class="description">No media selected.</p>')
            . '</div>'
            . '<a href="#" class="button rad-companion-choose" data-name="' . esc_attr($field['name']) . '">Choose Media</a>'
            . '<style>.rad-companion-media .description{margin:0.5em 0 0;}.rad-companion-media-preview{max-width:220px;max-height:160px;overflow:hidden;border:1px solid #dcdcde;border-radius:4px;}.rad-companion-media-img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;display:block;margin:0 auto;background:#f0f0f1;}.rad-companion-fields .form-label{font-weight:700;font-size:1.05em;color:#1d2327;margin:1.25em 0 0.4em;}.rad-companion-fields .form-label:first-child{margin-top:0;}</style>'
            . '</div>'
            . $hint;
    }

    /**
     * Render a WYSIWYG field using the default WordPress TinyMCE editor.
     *
     * The editor's textarea is named `rad_<name>` so the save handler reads its
     * HTML content directly. A unique editor id is derived from the field id so
     * multiple wysiwyg fields (including nested ones in rows) coexist without
     * colliding.
     */
    private static function renderWysiwygField(array $field, $value, string $label, string $id, string $labelTag, string $hint): string
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

        return $labelTag
            . '<div class="rad-companion-wysiwyg" id="rad-companion-wysiwyg-' . esc_attr(self::dashedSlug($id)) . '">'
            . $editor
            . self::wysiwygScript()
            . '<style>.rad-companion-wysiwyg .wp-editor-area{min-height:200px;}</style>'
            . '</div>'
            . $hint;
    }

    /**
     * Shared script that flushes TinyMCE editor content into its backing textarea
     * right before the post form submits, so top-level wysiwyg fields submit their
     * current HTML. Emitted once per wysiwyg field (idempotent via a flag).
     */
    private static function wysiwygScript(): string
    {
        return <<<'JS'
<script>
(function () {
    if (window.__radWysiwygBound) return;
    window.__radWysiwygBound = true;
    document.addEventListener('submit', function () {
        if (window.tinymce) {
            tinymce.triggerSave();
        }
    }, true);
})();
</script>
JS;
    }

    /**
     * Render a repeater field: a list of rows (each holding the sub-fields),
     * an "Add Row" button, per-row remove, and drag-and-drop reordering. The
     * hidden `rad_<name>` input holds the serialized JSON array submitted on save.
     */
    private static function renderRepeaterField(array $field, $value, string $label, string $id, string $labelTag, string $hint): string
    {
        $subFields = isset($field['fields']) ? $field['fields'] : [];

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

        // Sub-field input names render as rad_<rowToken>[<sub>]. Using a short
        // row token (blank / row-N) avoids a double rad_ prefix and keeps the
        // JS name-matching regex simple.
        $blankRow = self::renderRepeaterRow($subFields, [], 'blank');

        $existingRows = '';
        foreach ($rows as $i => $row) {
            $existingRows .= self::renderRepeaterRow($subFields, $row, 'row-' . $i);
        }

        // The top-level hidden input carries the normalised rows as JSON so the
        // JS sync() can rebuild it; the DB stays serialized (see sanitizeValue).
        $inputValue = json_encode($rows);

        return $labelTag
            . '<div class="rad-companion-repeater" id="rad-companion-repeater-' . esc_attr($field['name']) . '">'
            . '<input type="hidden" id="' . $id . '" name="' . $id . '" value="' . esc_attr($inputValue) . '" />'
            . '<template id="rad-companion-row-template-' . esc_attr($field['name']) . '">' . $blankRow . '</template>'
            . '<div class="rad-companion-repeater-rows">' . $existingRows . '</div>'
            . '<p class="rad-companion-repeater-add-wrap"><a href="#" class="button rad-companion-repeater-add">+ Add Row</a></p>'
            . self::repeaterScript()
            . '<style>.rad-companion-repeater-row{border:1px solid #dcdcde;padding:10px;margin:0 0 8px;border-radius:4px;position:relative;} .rad-companion-repeater-handle{cursor:grab;float:left;margin-right:8px;color:#666;} .rad-companion-repeater-row.dragging{opacity:.4;} .rad-companion-repeater-row.drag-over{border-style:dashed;} .rad-companion-repeater-add-wrap{margin:0;text-align:right;} .rad-companion-repeater-add-wrap .rad-companion-repeater-add{display:inline-block;margin-top:0;border-radius:0 0 4px 4px;} .rad-companion-repeater .rad-companion-repeater-remove{text-decoration:none;} .rad-companion-repeater .rad-companion-repeater-remove:hover,.rad-companion-repeater .rad-companion-repeater-remove:focus{text-decoration:none;}</style>'
            . '</div>'
            . $hint;
    }

    /**
     * Render a flexible field: a list of blocks, each tied to a named layout
     * from the yml `layouts:` map. A layout selector at the bottom lets the
     * editor add a block of any layout; each block is a draggable, removable box
     * holding that layout's sub-fields. Storage is the same shape as a repeater
     * (serialized array), except each row carries a `_layout` key so blocks keep
     * their identity.
     */
    private static function renderFlexibleField(array $field, $value, string $label, string $id, string $labelTag, string $hint): string
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
        $templates = '';
        foreach ($layouts as $layoutName => $layout) {
            $safe = self::dashedSlug($layoutName);
            $templates .= '<template id="rad-flexible-template-' . esc_attr($field['name']) . '-' . esc_attr($safe) . '">'
                . self::renderFlexibleBlock($layout, [], 'blank-' . $safe)
                . '</template>';
        }

        $existing = '';
        foreach ($blocks as $i => $block) {
            $layout = self::findFlexibleLayout($field, (string) $block['_layout']);
            if (!$layout) {
                continue;
            }
            $existing .= self::renderFlexibleBlock($layout, $block, 'block-' . $i);
        }

        // Top-level hidden input carries the normalised blocks as JSON for the
        // JS sync(); the DB stays serialized (see sanitizeValue).
        $inputValue = json_encode($blocks);

        $selector = '<div class="rad-flexible-add">'
            . '<label class="screen-reader-text" for="rad-flexible-add-' . esc_attr($field['name']) . '">Add a section</label> '
            . '<select id="rad-flexible-add-' . esc_attr($field['name']) . '" class="rad-flexible-add-select">'
            . '<option value="">Select a layout&hellip;</option>'
            . self::flexibleLayoutOptions($layouts)
            . '</select> '
            . '<a href="#" class="button rad-flexible-add-btn">Add</a>'
            . '</div>';

        // A "collapse all / expand all" control rendered above and below the
        // blocks so it's reachable without scrolling. Its label is kept in
        // sync by the script based on whether any block is currently expanded.
        $collapseAll = function (string $placement) use ($field) {
            return '<div class="rad-flexible-bulk rad-flexible-bulk--' . esc_attr($placement) . '">'
                . '<button type="button" class="button-link rad-flexible-collapse-all">'
                . '<span class="rad-flexible-collapse-all-icon" aria-hidden="true">&#x25BC;</span> '
                . '<span class="rad-flexible-collapse-all-label">Collapse all</span>'
                . '</button>'
                . '</div>';
        };

        return $labelTag
            . '<div class="rad-flexible" id="rad-flexible-' . esc_attr($field['name']) . '">'
            . '<input type="hidden" id="' . $id . '" name="' . $id . '" value="' . esc_attr($inputValue) . '" />'
            . $templates
            . $collapseAll('top')
            . '<div class="rad-flexible-blocks">' . $existing . '</div>'
            . $collapseAll('bottom')
            . $selector
            . self::flexibleScript()
            . '<style>.rad-flexible-block{border:1px solid #dcdcde;border-radius:4px;margin:0 0 8px;position:relative;background:#f6f7f7;}.rad-flexible-block-head{display:flex;align-items:center;padding:8px 10px;border-bottom:1px solid #dcdcde;background:#f0f0f1;border-radius:4px 4px 0 0;}.rad-flexible-block-handle{cursor:grab;margin-right:8px;color:#666;}.rad-flexible-block-title{font-weight:700;margin-right:auto;}.rad-flexible-head-actions{display:flex;align-items:center;gap:8px;margin-left:auto;}.rad-flexible-collapse{cursor:pointer;display:inline-flex;align-items:center;gap:4px;padding:0 4px;}.rad-flexible-collapse-icon{display:inline-block;transition:transform .15s ease;}.rad-flexible-block-body{padding:10px;}.rad-flexible-block.is-collapsed{border-radius:4px;}.rad-flexible-block.is-collapsed .rad-flexible-block-head{border-bottom:0;border-radius:4px;}.rad-flexible-block.is-collapsed .rad-flexible-block-body{display:none;}.rad-flexible-block.is-collapsed .rad-flexible-collapse-icon{transform:rotate(-90deg);}.rad-flexible-block.dragging{opacity:.4;}.rad-flexible-block.drag-over{border-style:dashed;}.rad-flexible-bulk{margin:0 0 8px;}.rad-flexible-bulk--top{float:right;margin-top:-25px;}.rad-flexible-bulk--bottom{margin:8px 0 0;}.rad-flexible-blocks{clear:both;position:relative;}.rad-flexible-drop-indicator{position:absolute;left:0;right:0;height:0;pointer-events:none;z-index:10;}.rad-flexible-drop-indicator::before{content:"";position:absolute;left:0;right:0;top:-2px;height:4px;border-radius:2px;background:#2271b1;box-shadow:0 0 0 1px rgba(34,113,177,.15);}.rad-flexible-drop-indicator::after{content:"";position:absolute;left:-4px;top:-5px;width:8px;height:8px;border-radius:50%;background:#2271b1;}.rad-flexible-collapse-all{cursor:pointer;display:inline-flex;align-items:center;gap:4px;padding:0 4px;}.rad-flexible-collapse-all-icon{display:inline-block;transition:transform .15s ease;}.rad-flexible-bulk.is-all-collapsed .rad-flexible-collapse-all-icon{transform:rotate(-90deg);}.rad-flexible-add{margin:0 0 10px;text-align:right;}.rad-flexible-add .rad-flexible-add-select{margin:0 6px;} .rad-flexible .rad-flexible-remove{text-decoration:none;} .rad-flexible .rad-flexible-remove:hover,.rad-flexible .rad-flexible-remove:focus{text-decoration:none;} .rad-flexible .rad-flexible-collapse{text-decoration:none;} .rad-flexible .rad-flexible-collapse:hover,.rad-flexible .rad-flexible-collapse:focus{text-decoration:none;} .rad-flexible .rad-flexible-collapse-all{text-decoration:none;} .rad-flexible .rad-flexible-collapse-all:hover,.rad-flexible .rad-flexible-collapse-all:focus{text-decoration:none;} .rad-flexible-drag-card{background:#fff;border:1px solid #2271b1;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.25);overflow:hidden;font-size:13px;color:#1d2327;}.rad-flexible-drag-card-title{font-weight:700;padding:8px 10px;background:#f0f0f1;border-bottom:1px solid #dcdcde;}.rad-flexible-drag-card-body{padding:14px 10px;color:#a7aaad;font-size:18px;line-height:1;} body.rad-flexible-dragging iframe{pointer-events:none;}</style>'
            . $hint;
    }

    /**
     * Build the <option> list for a flexible layout selector.
     */
    private static function flexibleLayoutOptions(array $layouts): string
    {
        $html = '';
        foreach ($layouts as $layoutName => $layout) {
            $html .= '<option value="' . esc_attr($layoutName) . '">' . esc_html($layout['label']) . '</option>';
        }
        return $html;
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
        $fieldsHtml = '';
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
            $fieldsHtml .= self::renderField($scoped, (string) $val);
        }

        // Informational layouts (no fields) show their message instead.
        $body = !empty($layout['message']) && empty($layout['fields'])
            ? '<p class="description rad-flexible-message">' . esc_html($layout['message']) . '</p>'
            : $fieldsHtml;

        return '<div class="rad-flexible-block" data-layout="' . esc_attr($layout['name']) . '">'
            . '<div class="rad-flexible-block-head">'
            . '<span class="rad-flexible-block-handle" draggable="true" title="Drag to reorder">&#x2807;</span>'
            . '<span class="rad-flexible-block-title">' . esc_html($layout['label']) . '</span>'
            . '<span class="rad-flexible-head-actions">'
            . '<button type="button" class="button-link rad-flexible-collapse" aria-expanded="true" title="Collapse">'
            . '<span class="rad-flexible-collapse-icon" aria-hidden="true">&#x25BC;</span>'
            . '<span class="rad-flexible-collapse-label">Collapse</span>'
            . '</button> '
            . '<a href="#" class="button-link button-link-delete rad-flexible-remove">Remove</a>'
            . '</span>'
            . '</div>'
            . '<div class="rad-flexible-block-body">'
            . '<input type="hidden" class="rad-flexible-layout" name="rad_' . esc_attr($blockKey) . '[_layout]" value="' . esc_attr($layout['name']) . '" />'
            . $body
            . '</div>'
            . '</div>';
    }

    /**
     * Shared, self-initializing script for every flexible field. Uses
     * document-level delegation so it works for blocks added later.
     */
    private static function flexibleScript(): string
    {
        return self::rekeyScript() . <<<'JS'
<script>
(function () {
    if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
    var $ = jQuery;

    // This script is emitted once per flexible field, and a page can contain
    // several. The handlers below are delegated on <document> and self-scope
    // via closest('.rad-flexible'), so they only need to be registered ONCE per
    // page. Guard the registration so a second flexible field does not re-bind
    // the same handlers (which would make each add/remove/drag fire twice).
    if (window.__radFlexibleBound) return;
    window.__radFlexibleBound = true;

    // Push the current TinyMCE editor content back into its backing textarea so
    // the values read from the DOM are current. No-op when TinyMCE isn't loaded.
    function flushWysiwyg() {
        if (window.tinymce) {
            tinymce.triggerSave();
        }
    }

    // Keep a single block's collapse button (label/aria) in sync with its
    // is-collapsed class.
    function setBlockCollapsed($block, collapsed) {
        $block.toggleClass('is-collapsed', collapsed);
        $block.find('.rad-flexible-collapse').each(function () {
            $(this)
                .attr('aria-expanded', collapsed ? 'false' : 'true')
                .attr('title', collapsed ? 'Expand' : 'Collapse');
            $(this).find('.rad-flexible-collapse-label').text(collapsed ? 'Expand' : 'Collapse');
        });
    }

    // Update the "collapse all / expand all" controls for a group: they say
    // "Collapse all" while any block is expanded and "Expand all" when every
    // block is collapsed (or there are none).
    function updateBulkState($flexible) {
        var $blocks = $flexible.find('.rad-flexible-blocks > .rad-flexible-block');
        var anyExpanded = $blocks.filter(function () { return !$(this).hasClass('is-collapsed'); }).length > 0;
        $flexible.find('.rad-flexible-bulk').toggleClass('is-all-collapsed', !anyExpanded);
        $flexible.find('.rad-flexible-collapse-all-label').text(anyExpanded ? 'Collapse all' : 'Expand all');
    }

    function readBlock($block) {
        flushWysiwyg();
        var o = {};
        o._layout = $block.find('input.rad-flexible-layout').val() || '';
        $block.find('input[type=hidden],input[type=text],input[type=email],input[type=number],input[type=url],input[type=password],input[type=date],input[type=datetime-local],input[type=time],input[type=week],input[type=month],input[type=checkbox],textarea,select').each(function () {
            // Input names render as rad_<blockKey>[<sub>], e.g. rad_block-0[title]
            var m = this.name.match(/^rad_(?:blank[-\w]+|block-\d+)\[([\w\-]+)\]$/);
            if (!m) return;
            // Checkboxes report their state via `.checked`, not `.value`.
            o[m[1]] = (this.type === 'checkbox') ? (this.checked ? this.value : '') : this.value;
        });
        return o;
    }

    function sync($flexible) {
        flushWysiwyg();
        var input = $flexible.find('> input[type=hidden]');
        var a = [];
        $flexible.find('.rad-flexible-blocks > .rad-flexible-block').each(function () {
            a.push(readBlock($(this)));
        });
        input.val(JSON.stringify(a));
    }

    function hydrate($flexible) {
        $flexible.find('.rad-companion-media').each(function () {
            var el = $(this);
            var input = el.find('input[type=hidden]');
            var cur = {};
            try { cur = JSON.parse(input.val() || '{}'); } catch (e) { cur = {}; }
            var preview = el.find('.rad-companion-media-preview');
            preview.removeAttr('data-id').attr('data-id', cur.id || '');
            preview.html(cur.url
                ? '<img class="rad-companion-media-img" src="' + cur.url + '" alt="' + (cur.alt || '') + '" />'
                : '<p class="description">No media selected.</p>');
        });
    }

    $(document).on('click', '.rad-flexible-add-btn', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.rad-flexible');
        var name = $wrap.attr('id').replace('rad-flexible-', '');
        var $sel = $wrap.find('.rad-flexible-add-select');
        var layout = $sel.val();
        if (!layout) return;
        var safe = layout.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        var template = document.getElementById('rad-flexible-template-' + name + '-' + safe);
        if (!template) return;
        var html = template.innerHTML;
        var $blocks = $wrap.find('.rad-flexible-blocks');
        $blocks.append($(html));
        // Re-key the freshly appended block (it inherited the template's
        // `blank-<layout>` key) to a unique `block-N` so input names stay unique
        // and any nested wysiwyg editor gets re-targeted + initialised.
        var $newBlock = $blocks.children().last();
        var newKey = 'block-' + $blocks.children().length;
        if (window.radRekeyRow) {
            window.radRekeyRow($newBlock, 'blank-' + safe, newKey);
        }
        hydrate($wrap);
        sync($wrap);
        updateBulkState($wrap);
        $sel.val('');
    });

    $(document).on('click', '.rad-flexible-remove', function (e) {
        e.preventDefault();
        var $block = $(this).closest('.rad-flexible-block');
        var $flexible = $block.closest('.rad-flexible');
        var title = $block.find('.rad-flexible-block-title').first().text() || 'this section';
        if (!window.confirm('Remove "' + title + '"? This cannot be undone.')) return;
        $block.remove();
        sync($flexible);
        updateBulkState($flexible);
    });

    // Toggle a block's collapsed state (hides the body so only the title bar
    // shows). Hidden inputs stay in the DOM, so sync() still reads their values.
    $(document).on('click', '.rad-flexible-collapse', function (e) {
        e.preventDefault();
        var $block = $(this).closest('.rad-flexible-block');
        setBlockCollapsed($block, !$block.hasClass('is-collapsed'));
        updateBulkState($block.closest('.rad-flexible'));
    });

    // Collapse (or expand) every block in the group at once. Collapses when any
    // block is still expanded, expands when all are already collapsed.
    $(document).on('click', '.rad-flexible-collapse-all', function (e) {
        e.preventDefault();
        var $flexible = $(this).closest('.rad-flexible');
        var $blocks = $flexible.find('.rad-flexible-blocks > .rad-flexible-block');
        var anyExpanded = $blocks.filter(function () { return !$(this).hasClass('is-collapsed'); }).length > 0;
        $blocks.each(function () { setBlockCollapsed($(this), anyExpanded); });
        updateBulkState($flexible);
    });

    $(document).ready(function () {
        $('.rad-flexible').each(function () {
            hydrate($(this));
            updateBulkState($(this));
        });
    });

    $(document).on('change input', '.rad-flexible-blocks input, .rad-flexible-blocks textarea, .rad-flexible-blocks select', function () {
        sync($(this).closest('.rad-flexible'));
    });

    // Native HTML5 drag-and-drop reordering, scoped so a flexible block only
    // interacts with other flexible blocks in the SAME flexible field. It never
    // accepts a drop from a repeater row nested inside it, and it is never
    // dropped into a repeater list. The active drag's type is tracked in a
    // closure variable because dataTransfer.getData() is unreadable during
    // dragover (a browser security restriction).
    //
    // Only the block's ⠿ handle is draggable (draggable="true" is set on the
    // handle, not the whole block), so a reorder can only be started by
    // dragging the handle. Dragging anywhere else — text, a field, the WYSIWYG
    // area, etc. — is a normal text-selection/scroll gesture and never kicks
    // off a block move.
    var flexDragType = null;

    // Build a lightweight off-screen "drag card" that represents the block
    // being moved (title + an ellipsis body). It is used as the custom drag
    // image so, while only the ⠿ handle initiates the drag, the drag ghost
    // shows the whole section — not just the tiny handle icon. Kept minimal
    // (no field inputs / WYSIWYG) to avoid cloning editor state.
    function makeFlexDragCard(title) {
        var card = document.createElement('div');
        card.className = 'rad-flexible-drag-card';
        card.innerHTML =
            '<div class="rad-flexible-drag-card-title"></div>' +
            '<div class="rad-flexible-drag-card-body">…</div>';
        card.querySelector('.rad-flexible-drag-card-title').textContent = title;
        // Fixed off-screen so the browser can rasterise it as the drag ghost
        // without the user seeing it sit in the page.
        card.style.position = 'fixed';
        card.style.left = '-9999px';
        card.style.top = '0';
        card.style.width = '320px';
        document.body.appendChild(card);
        return card;
    }

    // Decide where a drop lands relative to a hovered block: before it when the
    // cursor is in the block's upper half, after it in the lower half. This is
    // what the drop indicator visualises, so it drives both the indicator
    // position and the actual insert on drop.
    function getDropPlacement(block, clientY) {
        var rect = block.getBoundingClientRect();
        return clientY < rect.top + rect.height / 2 ? 'before' : 'after';
    }

    // Show a single reusable "drop here" line at the boundary of a hovered
    // block (before it, or after it). The indicator lives inside the
    // .rad-flexible-blocks container (which is position:relative) so offsetTop
    // is measured against it. It has pointer-events:none, so it never hijacks
    // the dragover events flowing to the blocks beneath it.
    function showDropIndicator($blocks, $block, placement) {
        var $ind = $blocks.children('.rad-flexible-drop-indicator');
        if (!$ind.length) {
            $ind = $('<div class="rad-flexible-drop-indicator"></div>');
            $blocks.append($ind);
        }
        var block = $block[0];
        var top = placement === 'after'
            ? block.offsetTop + block.offsetHeight
            : block.offsetTop;
        $ind.css({ top: top + 'px', display: 'block' });
    }

    function hideDropIndicator($blocks) {
        $blocks.children('.rad-flexible-drop-indicator').css('display', 'none');
    }

    $(document).on('dragstart', '.rad-flexible-block', function (e) {
        // Only proceed if the drag began on the block's own handle; otherwise
        // (e.g. a nested draggable that bubbles up) let the inner element own it.
        var handle = $(e.target).closest('.rad-flexible-block-handle', this)[0];
        if (!handle) {
            e.preventDefault();
            return;
        }
        this.classList.add('dragging');
        flexDragType = 'rad-flexible';
        e.originalEvent.dataTransfer.setData('text/plain', 'rad-flexible');
        flexDragClientY = e.clientY;
        flexScrollState = 'none';
        // While a block is being dragged, make every iframe (e.g. the TinyMCE
        // editor iframes) transparent to the drag. Otherwise, when the cursor
        // passes over another block's editor iframe, dragover events fire inside
        // that iframe's document and report clientY relative to the IFRAME (not
        // the window), corrupting the auto-scroll + drop-indicator math. With
        // pointer-events:none the drag stays in the parent document and the
        // coordinates are always window-relative.
        document.body.classList.add('rad-flexible-dragging');
        startFlexAutoScroll();
        // Show the whole section (title + ellipsis) as the drag ghost instead of
        // just the ⠿ handle. Cleaned up in the block's dragend handler.
        var title = this.querySelector('.rad-flexible-block-title');
        var card = makeFlexDragCard(title ? title.textContent.trim() : 'Section');
        this.__radDragCard = card;
        try {
            e.originalEvent.dataTransfer.setDragImage(card, 16, 16);
        } catch (err) {}
    });
    $(document).on('dragend', '.rad-flexible-block', function () {
        this.classList.remove('dragging');
        flexDragType = null;
        stopFlexAutoScroll();
        document.body.classList.remove('rad-flexible-dragging');
        // Clear any drop indicator still showing (e.g. the drag was cancelled
        // outside a valid target, so no drop/dragleave cleaned it up).
        $(this).closest('.rad-flexible').find('> .rad-flexible-blocks').each(function () {
            hideDropIndicator($(this));
        });
        // Remove the custom drag card created in dragstart.
        if (this.__radDragCard && this.__radDragCard.parentNode) {
            this.__radDragCard.parentNode.removeChild(this.__radDragCard);
        }
        delete this.__radDragCard;
    });

    // The scroll container the page content lives in. Walk up from a starting
    // element to the nearest ancestor that ACTUALLY scrolls (overflowY auto/
    // scroll AND scrollHeight > clientHeight). In the WP admin that is often
    // NOT #wpbody-content (which is overflow:visible); the document itself is
    // the scroller. Returns null when no inner element scrolls, meaning "use
    // the window/document". Needed because native HTML5 drag-and-drop does not
    // scroll the page while a drag is in flight, so on tall pages you cannot
    // reach a distant drop target without manual scrolling.
    function getScrollParent(el) {
        var node = el;
        while (node && node !== document.documentElement) {
            var overflow = getComputedStyle(node).overflowY;
            if ((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight) {
                return node;
            }
            node = node.parentElement;
        }
        return null;
    }

    // The WP admin bar sits at the very top of the viewport, so the cursor
    // cannot reach clientY 0 while dragging — it can only get just under the
    // bar. Pad the top trigger by the bar height plus a small amount so the
    // cursor (held just under the bar) still triggers scroll-up. Kept modest
    // (not a wide band) so the top zone doesn't overlap the middle of the
    // screen and fight the cursor's normal movement (which felt jittery).
    function getTopMargin() {
        var bar = parseInt(getComputedStyle(document.documentElement)
            .getPropertyValue('--wp-admin--admin-bar--height'), 10);
        if (isNaN(bar) || bar <= 0) bar = 32;
        return bar + 40;
    }

    // Auto-scroll the page while dragging so a distant drop target on a tall
    // page is reachable. Native HTML5 drag-and-drop neither scrolls with the
    // wheel (the drag session swallows wheel events) nor scrolls the page by
    // itself. While a flexible drag is active we track the cursor's Y (via the
    // document-level dragover, which fires everywhere during a native drag) and
    // run a requestAnimationFrame loop that scrolls the container when the
    // cursor is in an edge "enter zone". A hysteresis state machine prevents the
    // scroll from flapping up/down: entering an edge zone starts scrolling that
    // way, and only reaching the central "safe band" stops it — so moving the
    // cursor up to cancel a down-scroll settles in the middle and stops rather
    // than reversing into an up-scroll.
    var flexAutoScrollRAF = null;
    var flexDragClientY = 0;
    var flexScrollState = 'none'; // hysteresis state: 'none' | 'up' | 'down'
    var EDGE_MARGIN = 64;
    // Hysteresis: an edge zone you must enter to START scrolling, and a wide
    // middle "safe band" you must reach to STOP. Starting requires the cursor
    // in the edge zone; stopping requires it in the middle. Because the stop
    // band is far from the opposite edge's start zone, moving the cursor up to
    // cancel a down-scroll lands in the middle and stops — it does not flip
    // into an up-scroll. (Prevents the up/down/up/down flapping.)
    var ENTER_ZONE = 64;            // px from an edge to begin scrolling
    var SAFE_BAND_FRACTION = 0.35;  // central 35% of the viewport = stop band
    // Per-frame scroll distance at max proximity. Kept modest so auto-scroll
    // feels smooth and doesn't jerk the page; the speed ramp still eases in
    // near the edge. 12px/frame at 60fps ≈ 720px/sec, plenty to traverse a
    // tall page without feeling twitchy.
    var MAX_SCROLL_STEP = 12;

    function autoScrollFrame() {
        if (flexDragType !== 'rad-flexible') {
            flexAutoScrollRAF = null;
            return;
        }
        var dragging = document.querySelector('.rad-flexible-block.dragging');
        if (!dragging) return;
        var scroller = getScrollParent(dragging); // null => the window scrolls
        var y = flexDragClientY;
        // The "window" case (scroller === null) is the WP admin default: the
        // document scrolls. There the cursor is measured against the viewport
        // (clientY 0..innerHeight), and the top enter zone is padded by the
        // admin bar height so the cursor (which can't reach under the bar) can
        // still trigger scroll-up. For a real inner scroller, use its rect.
        var useWindow = scroller === null;
        var top = useWindow ? 0 : scroller.getBoundingClientRect().top;
        var bottom = useWindow ? window.innerHeight : scroller.getBoundingClientRect().bottom;
        // Asymmetric enter zones: the top one is padded by the admin bar
        // (the cursor can't reach clientY 0 under the bar); the bottom one
        // is the plain edge margin.
        var topEnter = useWindow ? getTopMargin() : ENTER_ZONE;
        var bottomEnter = EDGE_MARGIN;
        var scrollBy = function (dy) {
            if (useWindow) { window.scrollBy(0, dy); } else { scroller.scrollBy(0, dy); }
        };
        // Hysteresis state machine. Zones (in viewport-relative terms):
        //   top enter zone:    [top, top + topEnter]
        //   bottom enter zone: [bottom - bottomEnter, bottom]
        //   safe (stop) band:  the central SAFE_BAND_FRACTION of the viewport.
        // Entering an enter zone starts scrolling that direction. Reaching the
        // safe band stops it. Because the safe band is centered and far from
        // the opposite enter zone, a cursor moved up to cancel a down-scroll
        // settles in the band and stops — it does not reverse to up.
        var height = bottom - top;
        var safeTop = top + height * SAFE_BAND_FRACTION / 2;
        var safeBottom = bottom - height * SAFE_BAND_FRACTION / 2;
        var inTopEnter = (y - top < topEnter);
        var inBottomEnter = (bottom - y < bottomEnter);
        var inSafeBand = (y > safeTop && y < safeBottom);
        // Transition the state.
        if (flexScrollState === 'none') {
            if (inTopEnter) flexScrollState = 'up';
            else if (inBottomEnter) flexScrollState = 'down';
        } else if (flexScrollState === 'up') {
            if (inSafeBand) flexScrollState = 'none';
        } else if (flexScrollState === 'down') {
            if (inSafeBand) flexScrollState = 'none';
        }
        // Scroll according to the current state, with a speed ramp that eases
        // in near the active edge and reaches MAX right at it.
        if (flexScrollState === 'up') {
            // Ramp: closer to the top edge => faster (0..1). The ramp spans the
            // top enter zone; deeper into the top (y < top) clamps to 1.
            var tUp = 1 - (y - top) / Math.max(1, topEnter);
            scrollBy(-Math.round(MAX_SCROLL_STEP * Math.max(0, Math.min(1, tUp))));
        } else if (flexScrollState === 'down') {
            var tDn = 1 - (bottom - y) / Math.max(1, bottomEnter);
            scrollBy(Math.round(MAX_SCROLL_STEP * Math.max(0, Math.min(1, tDn))));
        }
        flexAutoScrollRAF = window.requestAnimationFrame(autoScrollFrame);
    }

    function startFlexAutoScroll() {
        if (flexAutoScrollRAF) return;
        flexAutoScrollRAF = window.requestAnimationFrame(autoScrollFrame);
    }

    function stopFlexAutoScroll() {
        if (flexAutoScrollRAF) {
            window.cancelAnimationFrame(flexAutoScrollRAF);
            flexAutoScrollRAF = null;
        }
    }

    // Keep the cursor's vertical position fresh for the auto-scroll loop. In
    // this environment mousemove does not fire during a native drag, so the
    // primary position source is the document-level dragover below (bound on
    // <document>, not just .rad-flexible-block, so it fires even when the
    // cursor is over the gaps between blocks, the page header, or the admin bar
    // area — if it were bound only to blocks, flexDragClientY would freeze as
    // soon as the cursor left a block and auto-scroll would stop responding to
    // direction changes). mousemove is kept as a harmless secondary source for
    // browsers where it does fire during a drag.
    $(document).on('mousemove', function (e) {
        if (flexDragType === 'rad-flexible') {
            flexDragClientY = e.clientY;
        }
    });
    $(document).on('dragover', function (e) {
        if (flexDragType !== 'rad-flexible') return;
        var ev = e.originalEvent;
        // Defense-in-depth (in addition to body.rad-flexible-dragging setting
        // pointer-events:none on iframes during a drag): if the event somehow
        // originated inside a nested document (e.g. a same-origin TinyMCE
        // iframe), its clientY is relative to THAT document, not the window,
        // and would corrupt the auto-scroll/drop math. Ignore it so the last
        // valid window-relative position is retained.
        var targetDoc = (ev.target && ev.target.ownerDocument) || null;
        if (targetDoc && targetDoc !== document) {
            return;
        }
        // clientY (viewport coords) matches the window-viewport math used by
        // the auto-scroll loop.
        flexDragClientY = ev.clientY;
    });
    $(document).on('dragover', '.rad-flexible-block', function (e) {
        // (Cursor position for auto-scroll is tracked by the document-level
        // dragover handler above, which fires everywhere during the drag.)
        // Only allow the drop (which requires preventDefault) while a flexible
        // block is the active drag, not a nested repeater row.
        if (flexDragType !== 'rad-flexible') return;
        var $flexible = $(this).closest('.rad-flexible');
        var $blocks = $flexible.find('> .rad-flexible-blocks');
        var $drag = $blocks.children('.rad-flexible-block.dragging');
        // The active drag lives in a different flexible field (or none in this
        // one): a drop here would be a no-op. Without preventDefault the browser
        // shows a "no-drop" cursor, which is the honest signal for this case.
        if (!$drag.length) return;
        // Hovering the block being dragged itself is not a useful drop target;
        // drop the indicator rather than hinting at a no-op move onto itself.
        if ($drag[0] === this) {
            this.classList.remove('drag-over');
            hideDropIndicator($blocks);
            return;
        }
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';
        this.classList.add('drag-over');
        showDropIndicator($blocks, $(this), getDropPlacement(this, e.originalEvent.clientY));
    });
    $(document).on('dragleave', '.rad-flexible-block', function () { this.classList.remove('drag-over'); });
    $(document).on('drop', '.rad-flexible-block', function (e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        if (flexDragType !== 'rad-flexible') return;
        var $flexible = $(this).closest('.rad-flexible');
        var $blocks = $flexible.find('> .rad-flexible-blocks');
        var $drag = $blocks.children('.rad-flexible-block.dragging');
        if ($drag.length && $drag[0] !== this) {
            // Land exactly where the drop indicator was pointing: before this
            // block when the cursor was in its upper half, after when in the
            // lower half.
            var placement = getDropPlacement(this, e.originalEvent.clientY);
            if (placement === 'after') {
                $(this).after($drag);
            } else {
                $(this).before($drag);
            }
            hideDropIndicator($blocks);
            // The DOM move corrupts any TinyMCE editors inside the moved block
            // (stale iframe selection -> 'setBaseAndExtent' error -> desynced
            // editor). Re-init them from their stored config so they come back
            // fully functional at the new position, as on a page reload.
            if (window.radReinitWysiwyg) window.radReinitWysiwyg($drag);
            sync($flexible);
        }
    });

    $(document).on('submit', 'form', function () {
        $(this).find('.rad-flexible').each(function () { sync($(this)); });
    });
})();
</script>
JS;
    }

    /**
     * Shared, self-initializing script for every repeater on the page. Uses
     * document-level delegation so it works for rows added later.
     */
    private static function repeaterScript(): string
    {
        return self::rekeyScript() . <<<'JS'
<script>
(function () {
    if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
    var $ = jQuery;

    // This script is emitted once per repeater field, and a page can contain
    // several (including inside flexible layouts). The event handlers below are
    // delegated on <document> and self-scope via closest('.rad-companion-
    // repeater'), so they only need to be registered ONCE per page. Guard the
    // registration so additional executions (one per extra repeater) do not
    // re-bind the same handlers — that was causing each add/remove click to fire
    // twice (e.g. adding 2 rows, or a second confirm dialog).
    if (window.__radRepeaterBound) return;
    window.__radRepeaterBound = true;

    // Push the current TinyMCE editor content back into its backing textarea so
    // the values read from the DOM are current. No-op when TinyMCE isn't loaded.
    function flushWysiwyg() {
        if (window.tinymce) {
            tinymce.triggerSave();
        }
    }

    function readRow($row) {
        flushWysiwyg();
        var o = {};
        $row.find('input[type=hidden],input[type=text],input[type=email],input[type=number],input[type=url],input[type=password],input[type=date],input[type=datetime-local],input[type=time],input[type=week],input[type=month],input[type=checkbox],textarea,select').each(function () {
            // Input names render as rad_<rowKey>[<sub>], e.g. rad_row-0[title]
            var m = this.name.match(/^rad_(?:blank|row-\d+)\[([\w\-]+)\]$/);
            if (!m) return;
            // Checkboxes report their state via `.checked`, not `.value`.
            o[m[1]] = (this.type === 'checkbox') ? (this.checked ? this.value : '') : this.value;
        });
        return o;
    }

    function sync($repeater) {
        flushWysiwyg();
        var input = $repeater.find('> input[type=hidden]');
        var a = [];
        $repeater.find('.rad-companion-repeater-rows > .rad-companion-repeater-row').each(function () {
            a.push(readRow($(this)));
        });
        input.val(JSON.stringify(a));
    }

    // The media picker updates a row's media input via .val(), which does NOT
    // fire change/input. Re-sync the repeater shortly after so the hidden JSON
    // picks up the freshly chosen media (deferred so the value is committed).
    function onMediaChange(e) {
        var $repeater = $(e.target).closest('.rad-companion-repeater');
        if (!$repeater.length) return;
        setTimeout(function () { sync($repeater); }, 0);
    }

    function hydrate($repeater) {
        // Re-run the media preview hydration for any media sub-fields in rows.
        $repeater.find('.rad-companion-media').each(function () {
            var el = $(this);
            var input = el.find('input[type=hidden]');
            var cur = {};
            try { cur = JSON.parse(input.val() || '{}'); } catch (e) { cur = {}; }
            var preview = el.find('.rad-companion-media-preview');
            preview.removeAttr('data-id').attr('data-id', cur.id || '');
            preview.html(cur.url
                ? '<img class="rad-companion-media-img" src="' + cur.url + '" alt="' + (cur.alt || '') + '" />'
                : '<p class="description">No media selected.</p>');
        });
    }

    $(document).on('click', 'a.rad-companion-repeater-add', function (e) {
        e.preventDefault();
        var $repeater = $(this).closest('.rad-companion-repeater');
        var template = document.getElementById($repeater.attr('id').replace('rad-companion-repeater-', 'rad-companion-row-template-'));
        var html = template ? template.innerHTML : '';
        var $rows = $repeater.find('.rad-companion-repeater-rows');
        $rows.append($(html));
        // Re-key the freshly appended row (it inherited the template's `blank`
        // key) to a unique `row-N` so input names stay unique and any nested
        // wysiwyg editor gets re-targeted + initialised.
        var $newRow = $rows.children().last();
        var newKey = 'row-' + $rows.children().length;
        if (window.radRekeyRow) {
            window.radRekeyRow($newRow, 'blank', newKey);
        }
        hydrate($repeater);
        sync($repeater);
    });

    $(document).on('click', 'a.rad-companion-repeater-remove', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.rad-companion-repeater-row');
        var $repeater = $row.closest('.rad-companion-repeater');
        var index = $repeater.find('.rad-companion-repeater-row').index($row) + 1;
        if (!window.confirm('Remove row ' + index + '? This cannot be undone.')) return;
        $row.remove();
        sync($repeater);
    });

    // Hydrate all repeaters on page load so media previews show correctly.
    $(document).ready(function () {
        $('.rad-companion-repeater').each(function () {
            hydrate($(this));
        });
    });

    // Keep the hidden JSON in sync with edits to any sub-field.
    $(document).on('change input', '.rad-companion-repeater-rows input, .rad-companion-repeater-rows textarea, .rad-companion-repeater-rows select', function () {
        sync($(this).closest('.rad-companion-repeater'));
    });

    // Native HTML5 drag-and-drop reordering, scoped so a repeater row only
    // interacts with other rows in the SAME repeater. It is never dropped into
    // the flexible list, and flexible blocks are never dropped into it. The
    // active drag's type is tracked in a closure variable because
    // dataTransfer.getData() is unreadable during dragover (a browser security
    // restriction).
    var repeaterDragType = null;

    $(document).on('dragstart', '.rad-companion-repeater-row', function (e) {
        // A row can live inside a flexible block; stop the event from bubbling
        // so the enclosing block's dragstart (which would make the whole block
        // draggable too) does not also fire.
        e.stopPropagation();
        this.classList.add('dragging');
        repeaterDragType = 'rad-repeater';
        e.originalEvent.dataTransfer.setData('text/plain', 'rad-repeater');
    });
    $(document).on('dragend', '.rad-companion-repeater-row', function (e) {
        e.stopPropagation();
        this.classList.remove('dragging');
        repeaterDragType = null;
    });
    $(document).on('dragover', '.rad-companion-repeater-row', function (e) {
        // Only allow the drop while a repeater row is the active drag.
        if (repeaterDragType !== 'rad-repeater') return;
        e.preventDefault();
        this.classList.add('drag-over');
    });
    $(document).on('dragleave', '.rad-companion-repeater-row', function () { this.classList.remove('drag-over'); });
    $(document).on('drop', '.rad-companion-repeater-row', function (e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        if (repeaterDragType !== 'rad-repeater') return;
        var $repeater = $(this).closest('.rad-companion-repeater');
        var $drag = $repeater.find('> .rad-companion-repeater-rows > .rad-companion-repeater-row.dragging');
        if ($drag.length && $drag[0] !== this) {
            $(this).before($drag);
            // Re-init any WYSIWYG editors in the moved row; see the flexible drop
            // handler for why (a DOM move corrupts the editors' iframe selection).
            if (window.radReinitWysiwyg) window.radReinitWysiwyg($drag);
            sync($repeater);
        }
    });

    // Final safety net: re-sync every repeater right before the form submits.
    $(document).on('submit', 'form', function () {
        $(this).find('.rad-companion-repeater').each(function () { sync($(this)); });
    });
})();
</script>
JS;
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
        return <<<'JS'
<script>
(function () {
    if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
    var $ = jQuery;

    // dashedSlug mirror of PHP CompanionFields::dashedSlug()
    function dashed(s) {
        return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    // Rewrite a wysiwyg editor's DOM ids + re-target its config to a new editor id.
    function rekeyEditor($wrap, oldEditorId, newEditorId) {
        // 1. Clone the TinyMCE + Quicktags settings registered for the template's
        //    editor id (they exist because wp_editor() ran server-side for the
        //    template), pointing at the new id.
        if (window.tinyMCEPreInit) {
            var mce = window.tinyMCEPreInit.mceInit[oldEditorId];
            var qt = window.tinyMCEPreInit.qtInit[oldEditorId];
            if (mce) {
                var newMce = Object.assign({}, mce);
                newMce.selector = '#' + newEditorId;
                window.tinyMCEPreInit.mceInit[newEditorId] = newMce;
            }
            if (qt) {
                var newQt = Object.assign({}, qt);
                newQt.id = newEditorId;
                window.tinyMCEPreInit.qtInit[newEditorId] = newQt;
            }
        }

        // 2. Rewrite every id / data-wp-editor-id from the old to the new editor
        //    id. The wrap's OWN id (wp-<id>-wrap) is NOT a descendant, so it must
        //    be rewritten explicitly in addition to $wrap.find('[id]'). If the
        //    wrap id stays stale, switchEditors() — which looks the wrap up via
        //    '#wp-<id>-wrap' — finds nothing and the Visual/Code tabs stop
        //    switching.
        if ($wrap.attr('id') && $wrap.attr('id').indexOf(oldEditorId) !== -1) {
            $wrap.attr('id', $wrap.attr('id').split(oldEditorId).join(newEditorId));
        }
        $wrap.find('[id]').each(function () {
            var id = this.id;
            if (id && id.indexOf(oldEditorId) !== -1) {
                this.id = id.split(oldEditorId).join(newEditorId);
            }
        });
        // The Visual/Code tab buttons carry data-wp-editor-id; switchEditors()
        // reads it to know which editor to toggle. Rekey it alongside the ids.
        $wrap.find('[data-wp-editor-id]').each(function () {
            this.setAttribute('data-wp-editor-id', newEditorId);
        });

        // 3. Init the new editor instances. Deferred to the next task so the
        //    appended DOM is committed + laid out before TinyMCE/Quicktags run
        //    (initialising them synchronously inside the click handler can leave
        //    the toolbars unrendered).
        setTimeout(function () {
            if (window.tinymce && window.tinyMCEPreInit && window.tinyMCEPreInit.mceInit[newEditorId]) {
                window.tinymce.init(window.tinyMCEPreInit.mceInit[newEditorId]);
            }
            if (window.quicktags && window.tinyMCEPreInit && window.tinyMCEPreInit.qtInit[newEditorId]) {
                window.quicktags(window.tinyMCEPreInit.qtInit[newEditorId]);
                // Quicktags normally renders its toolbar buttons via a deferred
                // DOM-ready callback, which does not reliably fire for editors
                // added to the DOM after page load. Force the button render now by
                // re-running the (public) _buttonsInit() with no arg — that
                // re-populates the toolbar for every QTags instance, including the
                // one we just created. Idempotent for the page-load editors.
                if (window.QTags && typeof window.QTags._buttonsInit === 'function') {
                    window.QTags._buttonsInit();
                }
            }
        }, 0);
    }

    // Re-key a cloned row/block: oldKey -> newKey across names/ids, and re-init
    // any wysiwyg editors it holds.
    window.radRekeyRow = function ($row, oldKey, newKey) {
        // 1. Rewrite input/textarea names + ids (rad_<key>[<sub>]).
        $row.find('[name], [id]').each(function () {
            var el = this; // jQuery's .each() calls with `this` = the DOM element;
                           // capture it so the inner forEach keeps the reference.
            ['name', 'id'].forEach(function (attr) {
                var v = el.getAttribute(attr);
                if (v && v.indexOf('rad_' + oldKey + '[') !== -1) {
                    el.setAttribute(attr, v.split('rad_' + oldKey + '[').join('rad_' + newKey + '['));
                }
            });
        });

        // 2. Re-key + re-init each wysiwyg editor in the row.
        var oldKeyDashed = dashed(oldKey);
        var newKeyDashed = dashed(newKey);
        $row.find('.wp-editor-wrap').each(function () {
            var $wrap = $(this);
            var oldEditorId = $wrap.attr('id').replace(/^wp-/, '').replace(/-wrap$/, '');
            if (!oldEditorId) return;
            var newEditorId = oldEditorId.split(oldKeyDashed).join(newKeyDashed);
            rekeyEditor($wrap, oldEditorId, newEditorId);
        });
    };

    // Shared by the flexible + repeater drop handlers. TinyMCE does not survive
    // its container being moved in the DOM: relocating the wrap leaves the
    // editor's iframe selection pointing at detached nodes, so the next
    // selection/focus restore throws ('setBaseAndExtent' on null) and the editor
    // desyncs (empty Visual tab, broken Code tab). After a drag-and-drop reorder
    // we therefore destroy each moved editor instance (flushing content to the
    // textarea first) and re-create it at the new position with a fresh iframe —
    // exactly what a page reload does. Safe when TinyMCE is absent.
    window.radReinitWysiwyg = function ($block) {
        if (!window.tinymce) return;
        $block.find('.wp-editor-wrap').each(function () {
            var editorId = $(this).attr('id').replace(/^wp-/, '').replace(/-wrap$/, '');
            if (!editorId) return;
            var existing = window.tinymce.get(editorId);
            if (existing) {
                try { existing.save(); } catch (e) {}
                try { existing.remove(); } catch (e) {}
            }
            var mceCfg = window.tinyMCEPreInit && window.tinyMCEPreInit.mceInit[editorId];
            if (mceCfg) {
                mceCfg.selector = '#' + editorId;
                window.tinymce.init(mceCfg);
            }
            var qtCfg = window.tinyMCEPreInit && window.tinyMCEPreInit.qtInit[editorId];
            var existingQt = window.QTags && window.QTags.getInstance(editorId);
            if (existingQt) { try { existingQt.remove(); } catch (e) {} }
            if (qtCfg && window.quicktags) {
                qtCfg.id = editorId;
                window.quicktags(qtCfg);
                if (window.QTags && typeof window.QTags._buttonsInit === 'function') {
                    window.QTags._buttonsInit();
                }
            }
        });
    };
})();
</script>
JS;
    }

    /**
     * Render a single repeater row. Sub-fields are rendered via renderField with
     * their input names scoped under `rad_<name>[<rowKey>][<sub>]` so the JSON
     * value can be rebuilt from the DOM.
     *
     * @param string $rowKey unique per-row token (e.g. "rad_repeater_0")
     */
    private static function renderRepeaterRow(array $subFields, array $rowValues, string $rowKey): string
    {
        $html = '<div class="rad-companion-repeater-row" draggable="true">'
            . '<span class="rad-companion-repeater-handle" title="Drag to reorder">&#x2807;</span>'
            . '<a href="#" class="button-link button-link-delete rad-companion-repeater-remove" style="float:right">Remove</a>';

        foreach ($subFields as $sub) {
            $val = $rowValues[$sub['name']] ?? '';
            // Media sub-fields are arrays (from normalisation). Render them as a
            // JSON {id,url,alt} string so the row's JS media picker/hydrate can
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
            $scoped = self::scopeFieldName($sub, $rowKey);
            $html .= self::renderField($scoped, (string) $val);
        }

        $html .= '</div>';

        return $html;
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
     * @return bool
     */
    private static function unimplemented(string $type): bool
    {
        return in_array($type, self::UNIMPLEMENTED, true);
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
