<?php

namespace ofc;

use Handlebars\Handlebars;

/**
 * Server-side rendering for the companion field admin UI.
 *
 * The admin markup lives in .hbs templates under src/templates/companion/
 * and is rendered with handlebars-php (already a package dependency), so
 * HTML no longer sits inside string concatenation in the PHP renderers —
 * those only prepare data arrays.
 *
 * `{{var}}` is HTML-escaped by handlebars. `{{{var}}}` is reserved for
 * fragments that were already sanitised on the PHP side (esc_url'd URLs,
 * nested rendered fields, wp_editor() output).
 */
class FieldTemplates
{
    /**
     * @var Handlebars|null shared engine instance (stateless once built)
     */
    private static $hb;

    /**
     * @var array<string, string> template sources keyed by template name
     */
    private static $sources = [];

    /**
     * Render src/templates/companion/<name>.hbs against the given data.
     * A missing template renders nothing rather than fataling.
     *
     * @param string $name template file name without the .hbs extension
     * @param array  $data values exposed to the template
     */
    public static function render(string $name, array $data = []): string
    {
        if (!isset(self::$sources[$name])) {
            $path = __DIR__ . '/templates/companion/' . $name . '.hbs';
            self::$sources[$name] = is_readable($path) ? trim((string) file_get_contents($path)) : '';
        }

        if (self::$hb === null) {
            self::$hb = new Handlebars();
        }

        // The bundled handlebars-php release triggers PHP 8.1+ deprecation
        // notices internally (trim(null) in its tokenizer, null offsets in
        // array_slice inside {{#each}}). Nothing on our side can avoid them
        // and the rendered output is unaffected, so E_DEPRECATED is masked
        // while the third-party renderer runs (admin pages render many
        // templates per request and would otherwise be flooded under
        // WP_DEBUG).
        $previous = error_reporting();
        error_reporting($previous & ~E_DEPRECATED);
        try {
            return self::$hb->render(self::$sources[$name], $data);
        } finally {
            error_reporting($previous);
        }
    }
}
