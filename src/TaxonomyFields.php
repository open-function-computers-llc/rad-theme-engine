<?php

namespace ofc;

use Symfony\Component\Yaml\Yaml;

/**
 * Taxonomy term fields driven by companion-YAML files.
 *
 * Two discovery locations:
 *
 *   1. taxonomy-<slug>.yml at the theme root. If the theme also has a
 *      matching taxonomy-<slug>.php for the archive template, the two files
 *      sit next to each other and pair naturally with WordPress's template
 *      hierarchy — but the PHP template is optional. A theme using WP's
 *      default taxonomy archive gets its fields purely from the yml.
 *
 *   2. taxonomies/<slug>.yml inside a folder at the theme root. This is a
 *      tidy option for themes that prefer to keep field declarations grouped
 *      by concern rather than scattered as root-level files. Mirrors the
 *      `post-types/<type>.yml` convention in {@see CompanionFields}.
 *
 * If both exist for the same slug, the root-level companion (1) wins — same
 * precedence rule as `single-<cpt>.yml` beating `post-types/<cpt>.yml`.
 *
 * Values are stored as term meta keyed `rad_<field name>` (same shape as
 * CompanionFields' `rad_<field name>` post meta), so media / repeater /
 * flexible serialise identically and can share the sanitiser / resolver.
 */
class TaxonomyFields
{
    public const META_PREFIX = 'rad_';
    public const NONCE_FIELD = 'rad_taxonomy_nonce';
    public const NONCE_ACTION = 'rad_taxonomy_';

    /**
     * @var string[] the taxonomy slugs discovered this request (populated in
     *               boot()); used by the admin_enqueue_scripts guard so we
     *               don't have to re-parse the yml files on every request.
     */
    private static $discoveredSlugs = [];

    /**
     * Discover companion ymls and wire up the add / edit / save hooks.
     */
    public static function boot(): void
    {
        $groups = self::discover();

        if (empty($groups)) {
            return;
        }

        self::$discoveredSlugs = array_keys($groups);

        foreach ($groups as $slug => $meta) {
            add_action("{$slug}_add_form_fields", function ($taxonomy) use ($slug, $meta) {
                echo self::renderAddForm($slug, $meta);
            });

            add_action("{$slug}_edit_form_fields", function ($term, $taxonomy) use ($slug, $meta) {
                echo self::renderEditForm($slug, $meta, $term);
            }, 10, 2);

            add_action("created_{$slug}", function ($term_id, $tt_id, $taxonomy) use ($slug, $meta) {
                self::save($term_id, $slug, $meta);
            }, 10, 3);

            add_action("edited_{$slug}", function ($term_id, $tt_id, $taxonomy) use ($slug, $meta) {
                self::save($term_id, $slug, $meta);
            }, 10, 3);
        }

        // TinyMCE / media-manager assets have to be pulled in explicitly on
        // taxonomy add/edit screens — WP only auto-loads them on post-edit
        // screens, so without this the "Choose Media" button on a term field
        // renders but wp.media isn't defined and clicks silently no-op.
        add_action('admin_enqueue_scripts', function () use ($groups) {
            if (!function_exists('get_current_screen')) {
                return;
            }
            $screen = get_current_screen();
            if (!$screen || empty($screen->taxonomy) || !isset($groups[$screen->taxonomy])) {
                return;
            }
            $fields = $groups[$screen->taxonomy]['fields'];
            if (CompanionFields::needsWysiwyg($fields)) {
                wp_enqueue_editor();
            }
            if (CompanionFields::needsMedia($fields)) {
                wp_enqueue_media();
            }
        });
    }

    /**
     * Discover every taxonomy-<slug>.yml at the theme root and every
     * taxonomies/<slug>.yml inside the folder, in that precedence order.
     *
     * The taxonomy-<slug>.php template file is optional and orthogonal —
     * its presence changes how WordPress *renders* the archive, not how we
     * attach fields to the admin term screens. A theme that uses WP's
     * default taxonomy archive still gets term fields purely from the yml.
     *
     * @return array<string, array{title: string, slug: string, fields: array}>
     */
    public static function discover(): array
    {
        $themeDir = get_template_directory();
        $groups = [];

        // 1) Root-level companion yml: taxonomy-<slug>.yml. If the theme also
        //    has taxonomy-<slug>.php next to it, great — that's just WP's
        //    template hierarchy at work.
        foreach (glob($themeDir . '/taxonomy-*.yml') ?: [] as $yml) {
            $base = basename($yml, '.yml');
            $slug = substr($base, strlen('taxonomy-'));
            if ($slug === '' || $slug === false) {
                continue;
            }
            $group = self::groupFromYml($yml, $slug);
            if ($group) {
                $groups[$slug] = $group;
            }
        }

        // 2) Standalone folder: taxonomies/<slug>.yml. Root-level companion
        //    wins on collision so a theme can "graduate" a file from the
        //    folder to sit next to its taxonomy template.
        foreach (glob($themeDir . '/taxonomies/*.yml') ?: [] as $yml) {
            $slug = basename($yml, '.yml');
            if (isset($groups[$slug])) {
                continue;
            }
            $group = self::groupFromYml($yml, $slug);
            if ($group) {
                $groups[$slug] = $group;
            }
        }

        return $groups;
    }

    /**
     * Parse a yml into a normalised group. Returns null when there is no
     * usable file (missing, malformed, or no `fields:` block).
     */
    private static function groupFromYml(string $yml, string $slug): ?array
    {
        if (!file_exists($yml)) {
            return null;
        }

        $raw = Yaml::parseFile($yml);

        if (!is_array($raw) || empty($raw['fields']) || !is_array($raw['fields'])) {
            return null;
        }

        $fields = [];
        foreach ($raw['fields'] as $name => $def) {
            $fields[] = CompanionFields::normalizeField($name, $def);
        }

        if (empty($fields)) {
            return null;
        }

        $title = isset($raw['title']) && $raw['title'] !== ''
            ? (string) $raw['title']
            : ucwords(str_replace(['-', '_'], ' ', $slug));

        return [
            'title' => $title,
            'slug' => $slug,
            'fields' => $fields,
        ];
    }

    /**
     * Fields block rendered inside WP's "Add New <taxonomy>" form
     * ({taxonomy}_add_form_fields fires inside a <div>-based form).
     */
    public static function renderAddForm(string $slug, array $meta): string
    {
        $html = '<div class="form-field rad-companion-taxonomy-fields rad-companion-taxonomy-fields--add">';
        $html .= wp_nonce_field(self::NONCE_ACTION . $slug, self::NONCE_FIELD, true, false);
        $html .= self::renderFields($meta['fields'], 0);
        $html .= '</div>';
        return $html;
    }

    /**
     * Fields block rendered inside WP's Edit Term table
     * ({taxonomy}_edit_form_fields fires as a series of <tr> rows).
     */
    public static function renderEditForm(string $slug, array $meta, $term): string
    {
        $termId = is_object($term) ? (int) $term->term_id : (int) $term;

        $html = '<tr class="form-field rad-companion-taxonomy-nonce"><td colspan="2">';
        $html .= wp_nonce_field(self::NONCE_ACTION . $slug, self::NONCE_FIELD, true, false);
        $html .= '</td></tr>';

        $html .= '<tr class="form-field rad-companion-taxonomy-fields rad-companion-taxonomy-fields--edit"><th scope="row" valign="top"></th><td>';
        $html .= self::renderFields($meta['fields'], $termId);
        $html .= '</td></tr>';

        return $html;
    }

    /**
     * Render the actual fields. Media / repeater / flexible are stored
     * serialised and need JSON-encoded values for the admin inputs.
     */
    private static function renderFields(array $fields, int $termId): string
    {
        $html = '<div class="rad-companion-fields">';
        foreach ($fields as $field) {
            $raw = $termId > 0 ? get_term_meta($termId, self::META_PREFIX . $field['name'], true) : '';
            if (in_array($field['type'], ['media', 'repeater', 'flexible'], true)) {
                $decoded = CompanionFields::unserializeValue($raw);
                $value = is_array($decoded) ? json_encode($decoded) : '';
            } else {
                $value = $raw;
            }
            $html .= CompanionFields::renderField($field, $value);
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Persist the fields on term create / update.
     *
     * Both `created_<slug>` and `edited_<slug>` fire with (term_id, tt_id,
     * taxonomy); we just need the term_id + the yml we loaded at boot. The
     * nonce rides in our rendered hidden input so a stray submission without
     * it is silently ignored.
     */
    public static function save(int $term_id, string $slug, array $meta): void
    {
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION . $slug)) {
            return;
        }

        foreach ($meta['fields'] as $field) {
            $postKey = 'rad_' . $field['name'];
            $metaKey = self::META_PREFIX . $field['name'];

            if (!isset($_POST[$postKey])) {
                // Checkbox that was unchecked has no key; store empty.
                if ($field['type'] === 'checkbox') {
                    update_term_meta($term_id, $metaKey, '');
                }
                continue;
            }

            $value = wp_unslash($_POST[$postKey]);
            $value = CompanionFields::sanitizeValue($value, $field['type'], $field);
            update_term_meta($term_id, $metaKey, $value);
        }
    }

    /**
     * Resolve one field on a term to the value a template should see. Mirrors
     * CompanionFields::resolveField but reads term meta.
     *
     * @param \WP_Term|object|int $term
     * @return mixed
     */
    public static function resolveField(string $taxonomy, string $name, $term)
    {
        $groups = self::discover();
        $meta = $groups[$taxonomy] ?? null;

        $field = null;
        if ($meta) {
            foreach ($meta['fields'] as $f) {
                if ($f['name'] === $name) {
                    $field = $f;
                    break;
                }
            }
        }

        $termId = is_object($term) ? (int) $term->term_id : (int) $term;
        $raw = $termId > 0 ? get_term_meta($termId, self::META_PREFIX . $name, true) : '';

        if (!$field) {
            return $raw;
        }

        return CompanionFields::resolveValue($field, $raw);
    }

    /**
     * Resolve every field on a taxonomy's term into a name => value map, the
     * shape templates typically want.
     *
     * @param \WP_Term|object|int $term
     */
    public static function resolveAll(string $taxonomy, $term): array
    {
        $groups = self::discover();
        $meta = $groups[$taxonomy] ?? null;
        if (!$meta) {
            return [];
        }

        $out = [];
        foreach ($meta['fields'] as $field) {
            $out[$field['name']] = self::resolveField($taxonomy, $field['name'], $term);
        }
        return $out;
    }

    /**
     * The taxonomy slugs discovered in the current request. Exposed mainly so
     * Site / tests can inspect what got picked up.
     *
     * @return string[]
     */
    public static function discovered(): array
    {
        return self::$discoveredSlugs;
    }
}
