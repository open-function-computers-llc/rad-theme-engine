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
        'textarea', 'checkbox', 'select', 'radio', 'media', 'repeater', 'flexible', 'wysiwyg',
        'message',
    ];

    /**
     * Field types that render no form control, so they hold nothing to save
     * (see save()) and nothing to resolve on the frontend.
     */
    public const NON_INPUT_TYPES = ['message'];

    /**
     * Media output types a `media` field can be resolved to on the frontend.
     * - id/url/alt/html/array keep the historical shapes.
     * - `object` returns the full attachment as a `wp_prepare_attachment_for_js`
     *   style array (url, link, alt, title, mime, type, width/height, sizes...).
     */
    public const MEDIA_OUTPUTS = ['id', 'url', 'alt', 'html', 'array', 'object'];

    /**
     * Named file-type filters a `media` field's `filetypes:` key can select.
     * The value decides what shows up in the wp.media picker:
     *   image     -> library.type = 'image'        (default)
     *   videos    -> library.type = 'video'
     *   documents -> library.type = 'application'  (PDFs, Office, text, zip…)
     *   all       -> no type filter (everything the user can upload)
     * Unknown values fall back to `image` so a typo never accidentally opens
     * the picker to the entire library.
     */
    public const MEDIA_FILETYPES = ['image', 'videos', 'documents', 'all'];

    /**
     * Admin arrangements a `repeater` field's `layout:` key can select.
     * - table   (default) — grid of columns (one per sub-field) with a head row.
     * - stacked — each row shows its sub-fields full-width and labelled, one
     *             per line. Better when a row has many sub-fields or wide
     *             inputs (media, wysiwyg, …).
     * Unknown values fall back to `table`.
     */
    public const REPEATER_LAYOUTS = ['table', 'stacked'];

    /**
     * Page-wide counter for server-rendered repeater row keys (rad_row-<n>).
     * Keys must be unique across every repeater on the screen, not just within
     * one: wysiwyg editor ids and radio group names derive from them, and two
     * repeaters both using row-0 left the second editor uninitialised (no
     * toolbar, white text) and linked their radio groups. Digits only, to
     * match the row-\d+ name pattern in repeater.js.
     */
    private static int $rowSeq = 0;

    /**
     * Page-wide counter for server-rendered flexible block keys (rad_block-<n>),
     * with the same uniqueness rules as $rowSeq.
     */
    private static int $blockSeq = 0;

    /**
     * wp_editor instance ids created this request, in render order. The template
     * preview AJAX response uses this to ship just those editors' TinyMCE /
     * Quicktags init settings (see ajaxTemplateGroup()).
     *
     * @var string[]
     */
    private static array $renderedEditors = [];

    /**
     * Whether the template-preview screen config has been emitted this request.
     * Both preview columns print the loader script; only the first prints the
     * shared window.radCompanionPreview config.
     */
    private static bool $previewConfigPrinted = false;

    /**
     * Companion groups whose row / block counters have been seeded this request
     * (see seedGroupCounters()).
     *
     * @var array<string, bool>
     */
    private static array $seededGroups = [];

    /**
     * Whether the preview container's `hidden:` stylesheet has been emitted.
     */
    private static bool $previewStylesPrinted = false;

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

                if (!empty($meta['fields'])) {
                    $values = self::valuesForFields($meta['fields'], $post->ID);

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
                }

                // `sidebar_fields:` mirrors `fields:` into a second box in the
                // admin sidebar (right column). Same renderer, same storage —
                // only the WP context changes ("side" instead of "normal").
                if (!empty($meta['sidebar_fields'])) {
                    $sidebarBoxId = $boxId . '_sidebar';
                    $sidebarValues = self::valuesForFields($meta['sidebar_fields'], $post->ID);

                    add_meta_box(
                        $sidebarBoxId,
                        'Sidebar Fields',
                        function () use ($sidebarBoxId, $template, $meta, $sidebarValues) {
                            echo self::renderBox($sidebarBoxId, $template, $meta['sidebar_fields'], $sidebarValues);
                        },
                        $screen->post_type,
                        'side',
                        'default'
                    );
                }

                if (!empty($meta['hidden'])) {
                    self::registerHiddenEditors($meta['hidden']);
                }
            }
        });

        // Enqueue TinyMCE assets (only when a visible field set uses a wysiwyg
        // sub-field) and the media manager (whenever a media field is present).
        // WP only auto-loads these on post-edit screens with a content editor
        // — for CPTs that disable the editor (and for taxonomy / options
        // screens elsewhere) we have to pull them in explicitly.
        add_action('admin_enqueue_scripts', function () use ($groups) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || $screen->post_type === 'revision') {
                return;
            }

            $wysiwyg = false;
            $media = false;
            foreach ($groups as $meta) {
                $all = array_merge($meta['fields'], $meta['sidebar_fields'] ?? []);
                if (!$wysiwyg && self::needsWysiwyg($all)) {
                    $wysiwyg = true;
                }
                if (!$media && self::needsMedia($all)) {
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

        add_action('save_post', function ($post_id, $post) use ($groups) {
            self::save($post_id, $post, $groups);
        }, 10, 2);

        // Page screens swap companion groups live when the editor changes the
        // "Template" dropdown, instead of waiting for a save.
        self::bootTemplatePreview($groups);

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

    // ---------------------------------------------------------------------
    // Live template preview
    //
    // WordPress stores the page template in `_wp_page_template`, so the engine
    // can only know which companion group applies once the page has been saved.
    // On the page edit screen we instead render a single container box that
    // holds whichever group matches today, and template-preview.js swaps in the
    // group for a newly selected template the moment the editor picks it from
    // the "Template" dropdown — same shape as ACF's acf.screen model (watch
    // #page_template, fetch the groups that now apply, showEnable on the
    // matching postbox and hideDisable on the rest).
    // ---------------------------------------------------------------------

    /**
     * Wire the page-screen container box and its AJAX renderer onto admin.
     *
     * @param array $groups discovered companion groups (see discover())
     */
    private static function bootTemplatePreview(array $groups): void
    {
        // Priority 20: after the add_meta_boxes closure in boot(), so the
        // per-template boxes it registered can be replaced by the container.
        add_action('add_meta_boxes', function () use ($groups) {
            self::registerTemplatePreviewBoxes($groups);
        }, 20);

        add_action('wp_ajax_rad_companion_template_group', [self::class, 'ajaxTemplateGroup']);
    }

    /**
     * Replace the per-template companion boxes on a page (or any post type that
     * has page templates) with one container per admin column.
     *
     * @param array $groups discovered companion groups (see discover())
     */
    public static function registerTemplatePreviewBoxes(array $groups): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post' || empty($screen->post_type)) {
            return;
        }

        $templates = wp_get_theme()->get_page_templates(null, $screen->post_type);
        if (empty($templates)) {
            return;
        }

        $pageGroups = [];
        foreach ($groups as $filename => $meta) {
            // CPT-bound groups have no template dropdown to swap on.
            if (($meta['post_type'] ?? null) !== null) {
                continue;
            }
            if (!isset($templates[$filename])) {
                continue;
            }
            // The theme's "Template Name:" header — used in the confirm dialog when
            // the editor swaps away from a template they have typed into.
            $meta['label'] = (string) $templates[$filename];
            $pageGroups[$filename] = $meta;
        }

        if (empty($pageGroups) || !apply_filters('rad_companion_template_preview', true, $screen->post_type)) {
            return;
        }

        // The container holds the group for whichever template is selected, so
        // the boxes boot() registered for the saved template would only render
        // the same fields twice (and the duplicate input names would fight on
        // submit).
        foreach ($pageGroups as $filename => $meta) {
            $slug = self::slug($filename);
            remove_meta_box('rad_companion_' . $slug, $screen->post_type, 'normal');
            remove_meta_box('rad_companion_' . $slug . '_sidebar', $screen->post_type, 'side');
        }

        if (self::previewHasSet($pageGroups, 'fields')) {
            add_meta_box(
                'rad_companion_template_preview',
                'Custom Fields',
                function ($post) use ($pageGroups) {
                    echo self::renderPreviewContainer($post, $pageGroups, 'fields');
                },
                $screen->post_type,
                'normal',
                'default'
            );
        }

        if (self::previewHasSet($pageGroups, 'sidebar_fields')) {
            add_meta_box(
                'rad_companion_template_preview_sidebar',
                'Sidebar Fields',
                function ($post) use ($pageGroups) {
                    echo self::renderPreviewContainer($post, $pageGroups, 'sidebar_fields');
                },
                $screen->post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Whether any of these groups renders into the given column.
     *
     * @param array  $groups page-template companion groups
     * @param string $set    'fields' (normal) or 'sidebar_fields' (side)
     */
    private static function previewHasSet(array $groups, string $set): bool
    {
        foreach ($groups as $meta) {
            if (!empty($meta[$set])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render one preview column: the active group (server-side, so the page is
     * usable before any script runs), plus the shared nonce the save handler
     * accepts for whichever group ends up selected, plus the loader script.
     *
     * @param \WP_Post|int $post
     * @param array         $groups page-template companion groups
     * @param string        $set    'fields' (normal) or 'sidebar_fields' (side)
     */
    public static function renderPreviewContainer($post, array $groups, string $set = 'fields'): string
    {
        $post = get_post($post);
        $postId = $post ? (int) $post->ID : 0;
        $active = self::previewActiveTemplate($groups, $postId);

        $html = '<div class="rad-companion-preview" data-rad-set="' . esc_attr($set) . '"'
            . ' data-rad-active="' . esc_attr($active) . '">';

        if ($active !== '' && !empty($groups[$active][$set])) {
            $fields = $groups[$active][$set];
            self::seedGroupCounters($active);
            $values = self::valuesForFields($fields, $postId);

            $html .= '<div class="rad-companion-group" data-rad-template="' . esc_attr($active) . '">'
                . self::renderBox(
                    'rad_companion_' . self::slug($active),
                    $active,
                    $fields,
                    $values,
                    self::previewScope($active),
                    false
                )
                . '</div>';
        }

        // One shared nonce for the whole container: the save handler accepts it in
        // place of the per-template nonce, which cannot work here (the selected
        // template is not known when the form is written, and a second input of
        // the same name would silently win over the first).
        $html .= '<input type="hidden" name="rad_companion_preview_template"'
            . ' value="' . esc_attr($active) . '" />'
            . '<input type="hidden" name="rad_companion_preview_nonce"'
            . ' value="' . esc_attr(wp_create_nonce('rad_companion_preview_' . $postId)) . '" />';

        $html .= self::previewStyles($groups);
        $html .= self::previewScript($groups, $postId, $active);

        return $html . '</div>';
    }

    /**
     * The companion group whose template is saved on this post ('' when the page
     * still uses the theme default, which has no companion yml by definition).
     */
    private static function previewActiveTemplate(array $groups, int $postId): string
    {
        if ($postId <= 0) {
            return '';
        }

        $current = (string) get_post_meta($postId, '_wp_page_template', true);
        if ($current === '' || $current === 'default') {
            return '';
        }

        return isset($groups[$current]) ? $current : '';
    }

    /**
     * Id namespace for a group (see idSuffix()). Two templates that both declare
     * `header_image` can then coexist in the DOM once the editor has swapped
     * between them.
     */
    private static function previewScope(string $template): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', self::slug($template)) ?? '';
    }

    /**
     * Row / block key block for one group.
     *
     * Repeater rows and flexible blocks are keyed `rad_row-<n>` / `rad_block-<n>`
     * from a counter that restarts on every request, so a group rendered in an
     * AJAX request would otherwise reuse the keys of the group rendered with the
     * page: same input names means one shared radio group and duplicated ids.
     * Each group gets its own numeric block instead.
     */
    private static function groupSeqBase(string $template): int
    {
        return $template === '' ? 0 : (abs(crc32($template)) % 1000) * 100000;
    }

    /**
     * Start the row / block counters inside the given group's numeric block.
     */
    private static function seedGroupCounters(string $template): void
    {
        // Once per group per request: the two preview columns render different
        // sets of the same group, and a reseed would hand both columns the same
        // row / block keys.
        if (isset(self::$seededGroups[$template])) {
            return;
        }
        self::$seededGroups[$template] = true;

        $base = self::groupSeqBase($template);
        self::$rowSeq = $base;
        self::$blockSeq = $base;
    }

    /**
     * CSS that follows the selected template for the yml `hidden:` areas.
     *
     * The engine's own registerHiddenEditors() prints `#postdivrich{display:none
     * !important}` for the *saved* template only. Scoping our rules to
     * body[data-rad-active-template] (set by template-preview.js) both adds the
     * not-yet-saved templates and lets a switch back to a template that hides
     * nothing bring the editor back: the base rule and the per-template rules
     * share a specificity, and the per-template ones are emitted after it.
     *
     * @param array $groups page-template companion groups
     */
    private static function previewStyles(array $groups): string
    {
        if (self::$previewStylesPrinted) {
            return '';
        }

        $map = [
            'content' => '#postdivrich',
            'excerpt' => '#postexcerpt',
            'title' => '#titlediv',
        ];

        $all = [];
        foreach ($groups as $meta) {
            foreach ($meta['hidden'] ?? [] as $area) {
                if (isset($map[$area])) {
                    $all[] = $map[$area];
                }
            }
        }

        if (empty($all)) {
            return '';
        }

        self::$previewStylesPrinted = true;

        $base = 'body[data-rad-active-template] ' . implode(', body[data-rad-active-template] ', array_unique($all));
        $css = $base . '{display:block !important;}';

        foreach ($groups as $filename => $meta) {
            $selectors = [];
            foreach ($meta['hidden'] ?? [] as $area) {
                if (isset($map[$area])) {
                    $selectors[] = 'body[data-rad-active-template="' . $filename . '"] ' . $map[$area];
                }
            }
            if ($selectors) {
                $css .= implode(', ', $selectors) . '{display:none !important;}';
            }
        }

        // The title box also owns the slug box; keep them in step (same as
        // registerHiddenEditors()).
        foreach ($groups as $filename => $meta) {
            if (in_array('title', $meta['hidden'] ?? [], true)) {
                $css .= 'body[data-rad-active-template="' . $filename . '"] #edit-slug-box{display:none !important;}';
            }
        }

        return '<style>' . $css . '</style>';
    }

    /**
     * Screen config + the loader script. The config is emitted once per screen
     * (both columns share it); the script is self-guarding so a second copy is
     * inert.
     *
     * @param array  $groups page-template companion groups
     * @param int    $postId post (or auto-draft) being edited
     * @param string $active template filename saved on the post ('' for default)
     */
    private static function previewScript(array $groups, int $postId, string $active): string
    {
        $script = '';

        if (!self::$previewConfigPrinted) {
            self::$previewConfigPrinted = true;

            $templates = [];
            foreach ($groups as $filename => $meta) {
                $templates[$filename] = [
                    'scope' => self::previewScope($filename),
                    'label' => isset($meta['label']) ? (string) $meta['label'] : $filename,
                    'hidden' => $meta['hidden'] ?? [],
                    'sets' => [
                        'fields' => !empty($meta['fields']),
                        'sidebar_fields' => !empty($meta['sidebar_fields']),
                    ],
                ];
            }

            $script = '<script>window.radCompanionPreview = '
                . wp_json_encode([
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('rad_companion_preview_' . $postId),
                    'postId' => $postId,
                    'active' => $active,
                    'templates' => (object) $templates,
                ])
                . ';</script>';
        }

        return $script . CompanionAssets::script('template-preview.js');
    }

    /**
     * AJAX: render one companion group (both columns) for the template the editor
     * just selected, and return the TinyMCE / Quicktags settings of the wp_editor
     * instances it contains.
     *
     * Those settings are normally printed by WordPress at
     * admin_print_footer_scripts, which never fires during admin-ajax, so the
     * browser is handed the init objects instead and boots the editors itself
     * (see template-preview.js).
     */
    public static function ajaxTemplateGroup(): void
    {
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $template = isset($_POST['template']) ? (string) wp_unslash($_POST['template']) : '';
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if ($postId <= 0 || $template === '' || !wp_verify_nonce($nonce, 'rad_companion_preview_' . $postId)) {
            wp_send_json_error(['message' => 'This preview request is no longer valid. Reload the page and try again.']);
        }

        $post = get_post($postId);
        if (!$post || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => 'Sorry, you are not allowed to edit this page.']);
        }

        $groups = self::discover();
        if (!isset($groups[$template]) || ($groups[$template]['post_type'] ?? null) !== null) {
            wp_send_json_error(['message' => 'Unknown page template.']);
        }

        $meta = $groups[$template];
        $scope = self::previewScope($template);
        self::seedGroupCounters($template);
        self::$renderedEditors = [];

        $html = [];
        foreach (['fields', 'sidebar_fields'] as $set) {
            $fields = $meta[$set] ?? [];
            if (empty($fields)) {
                $html[$set] = '';
                continue;
            }
            $values = self::valuesForFields($fields, $postId);
            $html[$set] = self::renderBox(
                'rad_companion_' . self::slug($template),
                $template,
                $fields,
                $values,
                $scope,
                false
            );
        }

        wp_send_json_success([
            'html' => $html,
            'editors' => self::editorInitSettings(),
            'hidden' => $meta['hidden'] ?? [],
        ]);
    }

    /**
     * TinyMCE / Quicktags init objects for the wp_editor instances rendered this
     * request. WordPress keeps them in private statics on _WP_Editors and prints
     * them only from its footer hooks.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function editorInitSettings(): array
    {
        if (empty(self::$renderedEditors) || !class_exists('_WP_Editors')) {
            return [];
        }

        $props = (new \ReflectionClass('_WP_Editors'))->getStaticProperties();
        $mce = isset($props['mce_settings']) && is_array($props['mce_settings']) ? $props['mce_settings'] : [];
        $qt = isset($props['qt_settings']) && is_array($props['qt_settings']) ? $props['qt_settings'] : [];

        $out = [];
        foreach (array_unique(self::$renderedEditors) as $editorId) {
            $entry = [];
            if (isset($mce[$editorId]) && is_array($mce[$editorId])) {
                $entry['tinymce'] = self::jsonSafeInit($mce[$editorId]);
            }
            if (isset($qt[$editorId]) && is_array($qt[$editorId])) {
                $entry['quicktags'] = self::jsonSafeInit($qt[$editorId]);
            }
            if ($entry) {
                $out[$editorId] = $entry;
            }
        }

        return $out;
    }

    /**
     * Make a TinyMCE / Quicktags init array JSON-safe.
     *
     * WordPress prints some values unquoted (they are JavaScript literals: see
     * _WP_Editors::_parse_init(), e.g. external_plugins carries a JSON object as
     * a string). JSON-encoding those would hand TinyMCE a string where it wants
     * an object, so literal-looking strings are marked and revived client-side.
     *
     * @param array $init raw init array
     */
    private static function jsonSafeInit(array $init): array
    {
        $out = [];

        foreach ($init as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::jsonSafeInit($value);
                continue;
            }

            if (!is_string($value)) {
                $out[$key] = $value;
                continue;
            }

            $trimmed = trim($value);
            $isLiteral = $trimmed !== '' && (
                (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
                || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
                || preg_match('#^/.+/[a-z]*$#i', $trimmed) === 1
            );

            $out[$key] = $isLiteral ? ['@radLiteral' => $trimmed] : $value;
        }

        return $out;
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
     * @return array<string, array{title: string, template: string, post_type: ?string, fields: array, sidebar_fields: array}>
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
     * no usable yml (no file, no `fields:` or `sidebar_fields:` block, or both
     * empty).
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

        if (!is_array($raw)) {
            return null;
        }

        $fieldsRaw = (isset($raw['fields']) && is_array($raw['fields'])) ? $raw['fields'] : [];
        $sidebarRaw = (isset($raw['sidebar_fields']) && is_array($raw['sidebar_fields'])) ? $raw['sidebar_fields'] : [];

        if (empty($fieldsRaw) && empty($sidebarRaw)) {
            return null;
        }

        // Top-level `imports:` shares slice layouts with every flexible field in
        // this file (see resolveLayoutImports). Sidebar fields see the same
        // imports as regular fields so a sidebar flexible can use shared slices.
        $errBefore = count(self::$importErrors);
        $importedLayouts = self::resolveLayoutImports(isset($raw['imports']) ? $raw['imports'] : null);
        $importErrors = array_slice(self::$importErrors, $errBefore);

        $fields = [];
        foreach ($fieldsRaw as $name => $def) {
            $fields[] = self::normalizeField($name, $def, $importedLayouts, $importErrors);
        }

        // `sidebar_fields:` mirrors `fields:` but renders into a companion meta
        // box in the admin sidebar (right column) instead of the main editor
        // column. Definitions use the same shape (same types, same storage).
        $sidebarFields = [];
        foreach ($sidebarRaw as $name => $def) {
            $sidebarFields[] = self::normalizeField($name, $def, $importedLayouts, $importErrors);
        }

        if (empty($fields) && empty($sidebarFields)) {
            return null;
        }

        return [
            'title' => (string) $title,
            'template' => $key,
            'post_type' => $postType,
            'fields' => $fields,
            'sidebar_fields' => $sidebarFields,
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

        // `instructions:` sits between the label and the control (the shape WP
        // and ACF use for field guidance), `hint:` stays below the control, and
        // `message:` is the text a `message` field renders on its own.
        foreach (['default', 'placeholder', 'options', 'required', 'hint', 'instructions', 'message'] as $key) {
            if (isset($def[$key])) {
                $field[$key] = $def[$key];
            }
        }

        // `section:` names the postbox an options page should file this field
        // under (see OptionPages::boxes). Carried through here so the field
        // keeps the key to the renderer; ignored everywhere else.
        if (isset($def['section']) && trim((string) $def['section']) !== '') {
            $field['section'] = (string) $def['section'];
        }

        // For repeater fields, `add button text:` sets the add button label
        // verbatim (e.g. "Add Feature"); unset means the plain "Add Row"
        // fallback (see renderRepeaterField).
        if (isset($def['add button text']) && trim((string) $def['add button text']) !== '') {
            $field['add button text'] = (string) $def['add button text'];
        }

        // For media fields, `output` controls how the stored attachment is
        // resolved on the frontend (id / url / alt / html / array / object).
        if (isset($def['output'])) {
            $field['output'] = (string) $def['output'];
        }

        // For media fields, `filetypes` narrows what the wp.media picker shows.
        // Accepted: image (default), videos, documents, all. Anything else falls
        // back to image so a typo never silently opens the whole library.
        if ($type === 'media' && isset($def['filetypes'])) {
            $filetypes = strtolower(trim((string) $def['filetypes']));
            $field['filetypes'] = in_array($filetypes, self::MEDIA_FILETYPES, true)
                ? $filetypes
                : 'image';
        }

        // For select / radio fields, a plain list of strings is promoted to a
        // self-keyed map (key = value = text) so the stored value is the option
        // label itself. An explicit key/value map is left as-is.
        if (($type === 'select' || $type === 'radio') && isset($field['options']) && is_array($field['options'])) {
            $field['options'] = self::normalizeSelectOptions($field['options']);
        }

        // `show_if:` only shows this field while a sibling select / radio /
        // checkbox (in the same box, repeater row or flexible block) holds one
        // of the given values. See normalizeCondition() for accepted shapes.
        if (isset($def['show_if'])) {
            $condition = self::normalizeCondition($def['show_if']);
            if ($condition !== null) {
                $field['show_if'] = $condition;
            }
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

        // For repeater fields, `layout` picks the admin arrangement:
        //   table   (default) — grid of columns (one per sub-field) with a head
        //                       row; the horizontal table-style UI most people
        //                       expect from an ACF-like repeater.
        //   stacked — each row shows its sub-fields full-width and labelled,
        //                       one per line. Better when a row has many
        //                       sub-fields or wide inputs (media, wysiwyg, …).
        // Unknown values fall back to table so a typo doesn't quietly switch
        // a repeater to stacked.
        if ($type === 'repeater' && isset($def['layout'])) {
            $layout = strtolower(trim((string) $def['layout']));
            $field['layout'] = in_array($layout, self::REPEATER_LAYOUTS, true) ? $layout : 'table';
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
     * Normalise a `show_if:` condition into ['field' => name, 'values' => [..]].
     *
     * Accepted yml shapes:
     *   show_if: { field: cta_type, value: Link }        # one value
     *   show_if: { field: cta_type, value: [Link, File] } # any of several
     *   show_if: { has_cta: true }                       # shorthand
     *   show_if: has_cta                                 # checkbox is ticked
     *
     * Booleans map onto checkbox storage (true -> '1', false -> ''), so
     * `value: true` means "the checkbox is ticked". Returns null for anything
     * unusable so a malformed condition never hides a field.
     *
     * @param mixed $raw
     * @return array{field: string, values: string[]}|null
     */
    public static function normalizeCondition($raw): ?array
    {
        if (is_string($raw) && trim($raw) !== '') {
            return ['field' => trim($raw), 'values' => ['1']];
        }
        if (!is_array($raw) || empty($raw)) {
            return null;
        }

        if (isset($raw['field'])) {
            $name = trim((string) $raw['field']);
            $values = array_key_exists('value', $raw) ? $raw['value'] : (array_key_exists('values', $raw) ? $raw['values'] : true);
        } elseif (count($raw) === 1) {
            $name = trim((string) array_key_first($raw));
            $values = reset($raw);
        } else {
            return null;
        }

        if ($name === '') {
            return null;
        }

        $list = [];
        foreach ((is_array($values) ? $values : [$values]) as $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '';
            } elseif ($value === null) {
                $value = '';
            }
            $list[] = (string) $value;
        }

        return ['field' => $name, 'values' => array_values(array_unique($list))];
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
            $file = $themeDir . '/' . $candidate;
            if (!file_exists($file)) {
                continue;
            }
            $optimized = self::optimizedThumbnail($file);
            return $optimized !== null ? $optimized : get_template_directory_uri() . '/' . $candidate;
        }

        return null;
    }

    /**
     * Width picker thumbnails are downscaled to before serving. The picker
     * shows each card at ~350 CSS px (~700 device px on retina), so anything
     * wider than this buys nothing but decode time.
     */
    const THUMBNAIL_MAX_WIDTH = 1280;

    /**
     * Downscale/recompress a layout thumbnail for the picker.
     *
     * Any image wider than THUMBNAIL_MAX_WIDTH, or not already WebP, is
     * converted once through wp_get_image_editor() into a cached WebP under
     * wp-content/uploads/rad-flexible-thumbnails/, keyed by the source's
     * path/size/mtime so editing the file regenerates it. Keeps the modal to
     * a few hundred KB instead of making the browser decode and downsample
     * multi-megabyte originals (which shows as blurry previews while loading).
     * Returns null when the file is already fine, unreadable, or an SVG —
     * the original theme URL is served in all those cases.
     */
    private static function optimizedThumbnail(string $file): ?string
    {
        $info = @getimagesize($file);
        if ($info === false) {
            return null;
        }

        $isWebp = strtolower((string) (isset($info['mime']) ? $info['mime'] : '')) === 'image/webp';
        if ($isWebp && (int) $info[0] <= self::THUMBNAIL_MAX_WIDTH) {
            return null;
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return null;
        }

        $dir = $uploads['basedir'] . '/rad-flexible-thumbnails';
        $key = substr(md5($file . '|' . filesize($file) . '|' . filemtime($file) . '|' . self::THUMBNAIL_MAX_WIDTH), 0, 12);
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($file, PATHINFO_FILENAME));
        $name = $slug . '-' . $key . '.webp';
        $dest = $dir . '/' . $name;

        if (!file_exists($dest)) {
            $editor = wp_get_image_editor($file);
            if (is_wp_error($editor)) {
                return null;
            }
            $size = $editor->get_size();
            if ((int) $size['width'] > self::THUMBNAIL_MAX_WIDTH) {
                if (is_wp_error($editor->resize(self::THUMBNAIL_MAX_WIDTH, self::THUMBNAIL_MAX_WIDTH, false))) {
                    return null;
                }
            }
            if (!wp_mkdir_p($dir)) {
                return null;
            }
            if (is_wp_error($editor->save($dest, 'image/webp')) || !file_exists($dest)) {
                return null;
            }
        }

        return $uploads['baseurl'] . '/rad-flexible-thumbnails/' . rawurlencode($name);
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
     * Walk a normalised field tree (including repeater sub-fields and flexible
     * layout fields) and report whether any of them is a media field. Used to
     * decide whether to call wp_enqueue_media() on an admin screen — WP only
     * auto-loads the media manager on post edit screens, so options pages,
     * taxonomy term screens and any other custom admin page has to opt in
     * explicitly for the "Choose Media" button to work.
     *
     * @param array $fields
     * @return bool
     */
    public static function needsMedia(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'media') {
                return true;
            }
            if (isset($field['fields']) && self::needsMedia($field['fields'])) {
                return true;
            }
            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (self::needsMedia($layout['fields'] ?? [])) {
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
     * Read the current post meta values for a set of companion fields, keyed by
     * field name. Media and repeater values arrive from the DB serialized and
     * are re-encoded as JSON so the admin inputs (which expect JSON strings) can
     * hydrate directly.
     *
     * @param array $fields normalised field definitions
     * @return array<string, mixed>
     */
    private static function valuesForFields(array $fields, int $postId): array
    {
        $values = [];
        foreach ($fields as $field) {
            $raw = get_post_meta($postId, 'rad_' . $field['name'], true);
            if ($field['type'] === 'media' || $field['type'] === 'repeater') {
                $decoded = self::unserializeValue($raw);
                $values[$field['name']] = is_array($decoded) ? json_encode($decoded) : '';
            } else {
                $values[$field['name']] = $raw;
            }
        }
        return $values;
    }

    /**
     * Render the full meta box contents (nonce + fields).
     *
     * @param array<string, mixed> $values     current post meta values keyed by field name
     * @param string               $scope      id-only namespace (see previewScope())
     * @param bool                 $withNonce  emit the per-template nonce field. The
     *                                         page-preview container carries a single
     *                                         shared nonce instead, so groups rendered
     *                                         into it skip this (two `rad_companion_nonce`
     *                                         inputs would otherwise fight on submit).
     */
    public static function renderBox(
        string $boxId,
        string $template,
        array $fields,
        array $values = [],
        string $scope = '',
        bool $withNonce = true
    ): string {
        $fieldsHtml = [];
        foreach ($fields as $field) {
            $fieldsHtml[] = self::renderField($field, $values[$field['name']] ?? '', $scope);
        }

        return FieldTemplates::render('box', [
            'nonce' => $withNonce
                ? wp_nonce_field('rad_companion_' . self::slug($template), 'rad_companion_nonce', true, false)
                : '',
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
     *
     * @param string $scope id-only namespace, see previewScope(). It never touches
     *                      `name` attributes (the save handler still reads
     *                      `rad_<field name>`) or `data-rad-field` (so `show_if:`
     *                      conditions keep matching on the bare yml key).
     */
    public static function renderField(array $field, $value = '', string $scope = ''): string
    {
        $type = isset($field['type']) ? $field['type'] : 'text';
        $condition = isset($field['show_if']) && is_array($field['show_if']) ? $field['show_if'] : null;
        return FieldTemplates::render('field', [
            'typeClass' => self::dashedSlug($type),
            // Un-scoped name (the yml key) so `show_if:` can find its sibling
            // controller inside a repeater row / flexible block.
            'fieldKey' => (string) ($field['base_name'] ?? $field['name']),
            'hasCondition' => $condition !== null,
            'conditionField' => $condition ? $condition['field'] : '',
            'conditionValues' => $condition ? (string) json_encode($condition['values']) : '',
            'styles' => self::adminStyles(),
            'inner' => self::renderFieldInner($field, $value, $scope),
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
            . '</style>'
            . CompanionAssets::script('conditions.js');
    }

    /**
     * Render a single field's control(s). Unknown / unimplemented types render
     * a visible placeholder instead of being silently dropped.
     *
     * `$id` is the submitted name (`rad_<field>`, always unscoped) and `$domId`
     * is the same string with the optional preview namespace in front of it; only
     * the latter lands in `id`/`for` attributes.
     */
    private static function renderFieldInner(array $field, $value = '', string $scope = ''): string
    {
        $type = $field['type'];
        $id = 'rad_' . $field['name'];
        $domId = self::domId($id, $scope);
        $required = !empty($field['required']);
        $value = (string) $value;

        // When no value is set, fall back to the field's declared `default` so
        // freshly-added blocks (and empty stored values) render pre-filled.
        if ($value === '' && isset($field['default'])) {
            $value = is_scalar($field['default']) ? (string) $field['default'] : '';
        }

        // A `message` field is a note in the field list rather than a control,
        // so it skips the label/hint wrapper entirely. Its text is kses'd here
        // and printed unescaped by the template, which is what lets a note
        // carry a link or emphasis.
        if ($type === 'message') {
            return FieldTemplates::render('message', [
                'message' => wp_kses_post((string) ($field['message'] ?? '')),
            ]);
        }

        // Each branch builds only the control markup; the label and hint are
        // wrapped around it by field-inner.hbs so .rad-companion-field can lay
        // them out as a labelled row (label column on the left, control and
        // hint on the right).
        switch ($type) {
            case 'textarea':
                $control = FieldTemplates::render('textarea', [
                    'id' => $domId,
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
                    'id' => $domId,
                    'name' => $id,
                    'required' => $required,
                    'options' => $opts,
                ]);
                break;

            case 'radio':
                $options = isset($field['options']) ? (array) $field['options'] : [];
                // Like select: with no value (and no `default`) the first
                // option is pre-checked so the field always posts a value.
                if ($value === '' && $options) {
                    $value = (string) array_key_first($options);
                }
                $opts = [];
                $n = 0;
                foreach ($options as $optionValue => $text) {
                    $optionValue = (string) $optionValue;
                    $opts[] = [
                        // The first radio carries the field id so the label's
                        // `for` targets it; the rest get suffixed ids.
                        'id' => $n === 0 ? $domId : $domId . '-' . $n,
                        'name' => $id,
                        'value' => $optionValue,
                        'text' => (string) $text,
                        'checked' => $value === $optionValue,
                    ];
                    $n++;
                }
                $control = FieldTemplates::render('radio', [
                    'required' => $required,
                    'options' => $opts,
                ]);
                break;

            case 'checkbox':
                // The field label lives in the label column; its `for` still
                // toggles the box, so the control is just the (styled) box.
                $control = FieldTemplates::render('checkbox', [
                    'id' => $domId,
                    'name' => $id,
                    'checked' => $value === '1' || $value === true || $value === 'true',
                    'required' => $required,
                ]);
                break;

            case 'media':
                $control = self::renderMediaField($field, $value, $id, $scope);
                break;

            case 'repeater':
                $control = self::renderRepeaterField($field, $value, $id, $scope);
                break;

            case 'flexible':
                $control = self::renderFlexibleField($field, $value, $id, $scope);
                break;

            case 'wysiwyg':
                $control = self::renderWysiwygField($field, $value, $id, $scope);
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
                // `url` renders as a plain text input (with the URL keyboard on
                // mobile): the browser's type="url" check rejects relative
                // links (/contact), anchors (#form), mailto:/tel: and bare
                // domains, and blocks the whole post from saving. The value
                // is still cleaned on save (see sanitizeUrl).
                $control = FieldTemplates::render('input', [
                    'type' => $type === 'url' ? 'text' : $type,
                    'isUrl' => $type === 'url',
                    'id' => $domId,
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
            'id' => $domId,
            'label' => (string) $field['label'],
            'control' => $control,
            // kses'd here and printed unescaped, so instructions can carry a link
            'hasInstructions' => isset($field['instructions']),
            'instructions' => isset($field['instructions']) ? wp_kses_post((string) $field['instructions']) : '',
            'hasHint' => isset($field['hint']),
            'hint' => isset($field['hint']) ? (string) $field['hint'] : '',
        ]);
    }

    /**
     * Render a media (image) field. The attachment id is stored as JSON so the
     * frontend can resolve it to a url / alt / full array on demand.
     *
     * The picker's file-type filter (`filetypes:` in yml) and the stored
     * attachment's top-level MIME (image vs file) both ride along as data-*
     * attributes so media.js can (a) restrict the wp.media library query and
     * (b) draw a filename tile instead of a broken <img> for PDFs / videos /
     * other non-image attachments.
     */
    private static function renderMediaField(array $field, $value, string $id, string $scope = ''): string
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

        $filetypes = isset($field['filetypes']) && in_array($field['filetypes'], self::MEDIA_FILETYPES, true)
            ? (string) $field['filetypes']
            : 'image';

        $attachmentType = self::attachmentTopLevelType((int) $currentId, (string) $currentUrl);
        $mimeIcon = '';
        if ((int) $currentId > 0 && $attachmentType !== 'image' && $currentUrl !== '') {
            $iconUrl = wp_mime_type_icon((int) $currentId);
            if (is_string($iconUrl) && $iconUrl !== '') {
                $mimeIcon = $iconUrl;
            }
        }
        $previewUrl = $attachmentType !== 'image' ? self::documentPreviewUrl((int) $currentId) : '';
        $preview = self::mediaPreviewMarkup((int) $currentId, (string) $currentUrl, (string) $currentAlt, $attachmentType, $mimeIcon, $previewUrl);

        return FieldTemplates::render('media', [
            'fieldName' => $field['name'],
            'wrapId' => 'rad-companion-media-' . $field['name'] . self::idSuffix($scope),
            'id' => self::domId($id, $scope),
            'name' => $id,
            'json' => $inputValue,
            'currentId' => $currentId,
            'filetypes' => $filetypes,
            'attachmentType' => $attachmentType,
            'mimeIcon' => $mimeIcon,
            'previewUrl' => $previewUrl,
            'preview' => $preview,
            'script' => self::mediaScript(),
        ]);
    }

    /**
     * Thumbnail URL for a non-image attachment (e.g. the first-page image
     * WordPress generates for a PDF when Imagick + Ghostscript are available),
     * or '' when it has none.
     *
     * Read from wp_prepare_attachment_for_js()'s `sizes` — the same data the
     * wp.media picker hands media.js on select — so the preview looks the same
     * before and after a save. A theme that makes its own PDF thumbnails can
     * add them to that payload (`wp_prepare_attachment_for_js` filter) and
     * they'll show here and in the media library.
     */
    private static function documentPreviewUrl(int $id): string
    {
        if ($id <= 0 || get_post_type($id) !== 'attachment') {
            return '';
        }

        $att = wp_prepare_attachment_for_js($id);
        $sizes = is_array($att) && isset($att['sizes']) && is_array($att['sizes']) ? $att['sizes'] : [];
        foreach (['medium', 'thumbnail', 'full'] as $size) {
            if (!empty($sizes[$size]['url'])) {
                return (string) $sizes[$size]['url'];
            }
        }

        return '';
    }

    /**
     * Top-level MIME category for an attachment ('image', 'video',
     * 'application', 'audio', or 'unknown'). Falls back to sniffing the stored
     * URL extension when the attachment no longer exists so the admin doesn't
     * render a broken <img> for a deleted file.
     */
    private static function attachmentTopLevelType(int $id, string $url = ''): string
    {
        if ($id > 0) {
            $mime = get_post_mime_type($id);
            if (is_string($mime) && $mime !== '' && strpos($mime, '/') !== false) {
                return strtolower(substr($mime, 0, strpos($mime, '/')));
            }
        }

        if ($url !== '') {
            $ext = strtolower((string) pathinfo(wp_parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp'], true)) {
                return 'image';
            }
            if (in_array($ext, ['mp4', 'mov', 'webm', 'ogv', 'm4v'], true)) {
                return 'video';
            }
            if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true)) {
                return 'audio';
            }
            if ($ext !== '') {
                return 'application';
            }
        }

        return 'unknown';
    }

    /**
     * Preview markup for a media field's current value. Images get an <img>.
     * Other files get a document preview with the filename underneath: the
     * attachment's thumbnail when it has one, otherwise (PDFs only) the PDF
     * itself in the browser's built-in viewer. Anything else gets a filename
     * tile with the WP mime-type icon. Mirrors render() in media.js.
     *
     * @param string $mimeIcon   pre-resolved icon URL (see renderMediaField);
     *                           the JS re-uses the same value via data-mime-icon.
     * @param string $previewUrl thumbnail for a non-image file (documentPreviewUrl).
     */
    private static function mediaPreviewMarkup(int $id, string $url, string $alt, string $attachmentType, string $mimeIcon = '', string $previewUrl = ''): string
    {
        if ($url === '') {
            return '<p class="description">No media selected.</p>';
        }

        if ($attachmentType === 'image') {
            return '<img class="rad-companion-media-img" src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" />';
        }

        $isPdf = strtolower((string) pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) === 'pdf';
        if ($previewUrl !== '' || $isPdf) {
            $page = $previewUrl !== ''
                ? '<img src="' . esc_url($previewUrl) . '" alt="" />'
                : '<iframe src="' . esc_url($url) . '#toolbar=0&amp;navpanes=0&amp;scrollbar=0&amp;view=FitH" title="PDF preview" tabindex="-1" loading="lazy"></iframe>';
            $path = wp_parse_url($url, PHP_URL_PATH);
            $file = $path ? basename((string) $path) : $url;

            return '<figure class="rad-companion-media-doc">'
                . '<span class="rad-companion-media-doc-page">' . $page . '</span>'
                . '<figcaption class="rad-companion-media-file-name">' . esc_html($file) . '</figcaption>'
                . '</figure>';
        }

        $icon = '';
        if ($mimeIcon === '' && $id > 0) {
            $iconUrl = wp_mime_type_icon($id);
            if (is_string($iconUrl) && $iconUrl !== '') {
                $mimeIcon = $iconUrl;
            }
        }
        if ($mimeIcon !== '') {
            $icon = '<img class="rad-companion-media-file-icon" src="' . esc_url($mimeIcon) . '" alt="" aria-hidden="true" />';
        }

        $name = $id > 0 ? get_the_title($id) : '';
        if ($name === '' || $name === null) {
            $path = wp_parse_url($url, PHP_URL_PATH);
            $name = $path ? basename((string) $path) : $url;
        }

        return '<div class="rad-companion-media-file">'
            . $icon
            . '<span class="rad-companion-media-file-name">' . esc_html((string) $name) . '</span>'
            . '</div>';
    }

    /**
     * Render a WYSIWYG field using the default WordPress TinyMCE editor.
     *
     * The editor's textarea is named `rad_<name>` so the save handler reads its
     * HTML content directly. A unique editor id is derived from the field id so
     * multiple wysiwyg fields (including nested ones in rows) coexist without
     * colliding.
     */
    private static function renderWysiwygField(array $field, $value, string $id, string $scope = ''): string
    {
        // Unique editor id derived from the (already unique) input id. The wp_editor
        // instance id must be alphanumeric without brackets, so map the scoped
        // input id (e.g. rad_block-0[black_content]) to a safe editor token. The
        // preview namespace rides along so two templates that both declare an
        // `info_text` wysiwyg never register the same editor twice.
        $editorId = 'rad_wysiwyg_' . self::dashedSlug(str_replace('rad_', '', $id) . self::idSuffix($scope));

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

        // Remember the id so the template-preview AJAX response can hand the
        // editor's TinyMCE / Quicktags settings to the browser (they are normally
        // printed by WordPress at admin_print_footer_scripts, which never fires in
        // an admin-ajax request).
        self::$renderedEditors[] = $editorId;

        return FieldTemplates::render('wysiwyg', [
            'id' => 'rad-companion-wysiwyg-' . self::dashedSlug($id . self::idSuffix($scope)),
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
    private static function renderRepeaterField(array $field, $value, string $id, string $scope = ''): string
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
        // In `stacked` layout the head + inline grid style are dropped entirely
        // and CSS drives a vertical arrangement off the wrapper modifier class
        // (.rad-companion-repeater--stacked). Row/cell DOM is unchanged so all
        // the add/remove/reorder/JSON-sync JS keeps working.
        $count = count($subFields);
        $layout = isset($field['layout']) && in_array($field['layout'], self::REPEATER_LAYOUTS, true)
            ? (string) $field['layout']
            : 'table';
        $isStacked = $layout === 'stacked';

        // Columns never shrink below 120px: past that the table keeps its
        // width and scrolls horizontally (see .rad-companion-repeater-table).
        // The shared min-width keeps the head and every row on identical
        // track widths so their columns stay aligned while scrolling.
        // Wide editors get wider tracks so a wysiwyg's toolbar and editing
        // area aren't squeezed into a 160px column.
        $tracks = [];
        $minWidth = 112;
        foreach ($subFields as $sub) {
            [$min, $fr] = self::repeaterColumnSize((string) ($sub['type'] ?? 'text'));
            $tracks[] = 'minmax(' . $min . 'px,' . $fr . 'fr)';
            $minWidth += $min;
        }
        $colsStyle = (!$isStacked && $count > 0)
            ? ' style="grid-template-columns:48px ' . implode(' ', $tracks) . ' 64px;'
            . 'min-width:' . $minWidth . 'px"'
            : '';

        $head = '';
        if (!$isStacked && $count > 0) {
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
        $blankRow = self::renderRepeaterRow($subFields, [], 'blank', 1, $colsStyle, $scope);

        $existingRows = [];
        foreach ($rows as $i => $row) {
            $existingRows[] = self::renderRepeaterRow($subFields, $row, 'row-' . (self::$rowSeq++), $i + 1, $colsStyle, $scope);
        }

        // The top-level hidden input carries the normalised rows as JSON so the
        // JS sync() can rebuild it; the DB stays serialized (see sanitizeValue).
        $inputValue = json_encode($rows);

        return FieldTemplates::render('repeater', [
            'fieldName' => $field['name'],
            'itemLabel' => $itemLabel,
            'layout' => $layout,
            'wrapId' => 'rad-companion-repeater-' . $field['name'] . self::idSuffix($scope),
            'templateId' => 'rad-companion-row-template-' . $field['name'] . self::idSuffix($scope),
            'id' => self::domId($id, $scope),
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
     * Minimum width (px) and flex share (fr) of a table-repeater column for a
     * sub-field type.
     *
     * @return array{0: int, 1: int}
     */
    private static function repeaterColumnSize(string $type): array
    {
        switch ($type) {
            case 'wysiwyg':
                return [420, 3];
            case 'repeater':
            case 'flexible':
                return [360, 3];
            case 'textarea':
                return [240, 2];
            default:
                return [160, 1];
        }
    }

    /**
     * Render a flexible field: a list of blocks, each tied to a named layout
     * from the yml `layouts:` map. A layout selector at the bottom lets the
     * editor add a block of any layout; each block is a draggable, removable box
     * holding that layout's sub-fields. Storage is the same shape as a repeater
     * (serialized array), except each row carries a `_layout` key so blocks keep
     * their identity.
     */
    private static function renderFlexibleField(array $field, $value, string $id, string $scope = ''): string
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
                'templateId' => 'rad-flexible-template-' . $field['name'] . self::idSuffix($scope) . '-' . $safe,
                'safe' => $safe,
                'block' => self::renderFlexibleBlock($layout, [], 'blank-' . $safe, $scope),
            ]);
        }

        $existing = [];
        foreach ($blocks as $i => $block) {
            $layout = self::findFlexibleLayout($field, (string) $block['_layout']);
            if (!$layout) {
                continue;
            }
            $existing[] = self::renderFlexibleBlock($layout, $block, 'block-' . (self::$blockSeq++), $scope);
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
            'wrapId' => 'rad-flexible-' . $field['name'] . self::idSuffix($scope),
            'id' => self::domId($id, $scope),
            'name' => $id,
            'json' => $inputValue,
            'notices' => $notices,
            'templates' => implode('', $templates),
            'bulkTop' => $collapseAll('top'),
            'bulkBottom' => $collapseAll('bottom'),
            'blocks' => implode('', $existing),
            'selector' => FieldTemplates::render('flexible-add', [
                'modal' => self::flexiblePickerModal($field, $layouts, $scope),
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
    private static function flexiblePickerModal(array $field, array $layouts, string $scope = ''): string
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
            'modalId' => 'rad-flexible-modal-' . $field['name'] . self::idSuffix($scope),
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
    private static function renderFlexibleBlock(array $layout, array $values, string $blockKey, string $scope = ''): string
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
            $fieldsHtml[] = self::renderField($scoped, (string) $val, $scope);
        }

        // The layout message is a note for whoever edits the slice: it sits
        // above the fields, and is the whole body for informational layouts
        // that declare none. kses'd here and printed unescaped, so a note can
        // carry a link or emphasis.
        $message = !empty($layout['message'])
            ? FieldTemplates::render('flexible-message', ['message' => wp_kses_post((string) $layout['message'])])
            : '';

        $body = $message . implode('', $fieldsHtml);

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
    private static function renderRepeaterRow(array $subFields, array $rowValues, string $rowKey, int $number = 1, string $colsStyle = '', string $scope = ''): string
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
                'html' => self::renderField(self::scopeFieldName($sub, $rowKey), (string) $val, $scope),
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
        $scoped['base_name'] = $sub['base_name'] ?? $sub['name'];
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

        // The page template preview container posts one shared nonce plus the
        // template that was selected when the form was submitted. WordPress has
        // already written `_wp_page_template` by the time save_post fires, so this
        // normally agrees with $template; it is what lets a group whose fields were
        // rendered by AJAX (and so never emitted their own nonce) be saved.
        $previewTemplate = isset($_POST['rad_companion_preview_template'])
            ? (string) wp_unslash($_POST['rad_companion_preview_template'])
            : '';
        $previewNonce = isset($_POST['rad_companion_preview_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['rad_companion_preview_nonce']))
            : '';
        $previewVerified = $previewTemplate !== ''
            && wp_verify_nonce($previewNonce, 'rad_companion_preview_' . $post_id);

        foreach ($groups as $filename => $meta) {
            $fromPreview = $previewVerified
                && $filename === $previewTemplate
                && ($meta['post_type'] ?? null) === null;

            if (!$fromPreview && !self::groupAppliesTo($meta, $post, $template)) {
                continue;
            }

            if (!$fromPreview) {
                $nonce = isset($_POST['rad_companion_nonce']) ? sanitize_text_field(wp_unslash($_POST['rad_companion_nonce'])) : '';
                if (!wp_verify_nonce($nonce, 'rad_companion_' . self::slug($filename))) {
                    return;
                }
            }

            // Main-column fields and sidebar-column fields share one nonce and
            // one storage scheme (`rad_<name>` post meta), so both sets persist
            // through the same loop.
            $fieldsToSave = array_merge($meta['fields'], $meta['sidebar_fields'] ?? []);

            foreach ($fieldsToSave as $field) {
                // A note renders no control, so there is nothing posted for it
                // and no meta to write (or blank out).
                if (in_array($field['type'], self::NON_INPUT_TYPES, true)) {
                    continue;
                }

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
                return self::sanitizeUrl((string) $value);

            case 'wysiwyg':
                // Rich HTML authored in TinyMCE. Strip disallowed tags/attrs.
                return (string) wp_kses_post($value);

            default:
                return (string) wp_kses_post($value);
        }
    }

    /**
     * Lenient URL cleaning for `url` fields. Accepts absolute URLs, relative
     * paths (/about), anchors (#contact), query strings, mailto:/tel: links and
     * bare domains — `www.example.com` or `example.com/page` gain `https://`
     * rather than WordPress's default `http://`. Anything esc_url_raw rejects
     * (e.g. a javascript: URL) still comes back empty.
     */
    public static function sanitizeUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $hasScheme = (bool) preg_match('#^[a-z][a-z0-9+\-]*:#i', $value);
        $isRelative = strpos($value, '/') === 0 || strpos($value, '#') === 0 || strpos($value, '?') === 0;

        if (!$hasScheme && !$isRelative && preg_match('#^(www\.|[a-z0-9\-]+(\.[a-z0-9\-]+)*\.[a-z]{2,})([/?\#:]|$)#i', $value)) {
            $value = 'https://' . $value;
        }

        return (string) esc_url_raw($value);
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

        // Scalars pass through unchanged; arrays (e.g. `output: object`) are
        // JSON-encoded so the `{{ rad.<name>-JSON }}` filter can still inline
        // them into post content.
        if (is_string($resolved)) {
            return $resolved;
        }
        if (is_array($resolved)) {
            return (string) json_encode($resolved);
        }

        return '';
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
            $candidates = array_merge($meta['fields'], $meta['sidebar_fields'] ?? []);
            foreach ($candidates as $f) {
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
     * Scalars (id / url / alt / html / array-as-json) come back as strings;
     * `object` returns the attachment as an array (see mediaToOutput).
     *
     * @return mixed
     */
    public static function resolveMediaValue(array $field, $raw)
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
     * @return mixed
     */
    private static function resolveMediaFor(string $name, $post, array $field)
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
     * Scalars (`id`, `url`, `alt`, `html`, `array`) come back as strings to
     * preserve the historical return shape. `object` returns the attachment as
     * an array shaped like `wp_prepare_attachment_for_js()` (url, link, alt,
     * title, mime, type, subtype, width/height, sizes, icon, ...) so template
     * authors can traverse the whole attachment record.
     *
     * @param array|null $decoded the saved JSON (may carry url/alt fallbacks)
     * @return mixed
     */
    private static function mediaToOutput(int $id, ?array $decoded, string $output)
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

            case 'object':
                $prepared = wp_prepare_attachment_for_js($id);
                return is_array($prepared) ? $prepared : [];

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
     * Id namespace for a companion group rendered inside the page template
     * preview container (see registerTemplatePreviewBoxes()).
     *
     * The token is appended to ids (never prefixed) so the element ids the admin
     * scripts reverse-engineer keep working: media.js hydrates
     * `[id^=rad-companion-media-]`, and flexible.js / repeater.js strip a fixed
     * prefix off the wrapper id to find its `<template>` / modal. Appending also
     * keeps rekey.js's `rad_<rowKey>[` search intact for cloned repeater rows and
     * flexible blocks.
     */
    private static function idSuffix(string $scope): string
    {
        return $scope === '' ? '' : '__' . $scope;
    }

    /**
     * Namespaced DOM id for a field's submitted name (`rad_<field>`). The name
     * itself is never namespaced: the save handler reads `rad_<field>` verbatim.
     */
    private static function domId(string $id, string $scope): string
    {
        return $id . self::idSuffix($scope);
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
