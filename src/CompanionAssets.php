<?php

namespace ofc;

/**
 * Admin JS/CSS for the companion field editors.
 *
 * The stylesheets and scripts live in real files under src/assets/ (rather
 * than as concatenated strings inside PHP) so each language can be read and
 * reviewed on its own. Files are read once per request and emitted inline in
 * the metabox markup by the renderers.
 */
class CompanionAssets
{
    /**
     * @var array<string, string> raw file contents keyed by asset name
     */
    private static $cache = [];

    /**
     * Raw contents of an asset file (no <style>/<script> wrapper). Returns an
     * empty string when the asset is missing so a broken install degrades to
     * an unstyled editor rather than a fatal.
     */
    public static function file(string $name): string
    {
        if (!isset(self::$cache[$name])) {
            $path = __DIR__ . '/assets/' . $name;
            self::$cache[$name] = is_readable($path) ? (string) file_get_contents($path) : '';
        }

        return self::$cache[$name];
    }

    /**
     * An asset wrapped in a <style> tag (empty string when the asset is empty).
     */
    public static function style(string $name): string
    {
        $css = self::file($name);

        return $css === '' ? '' : '<style>' . $css . '</style>';
    }

    /**
     * An asset wrapped in a <script> tag (empty string when the asset is empty).
     */
    public static function script(string $name): string
    {
        $js = self::file($name);

        return $js === '' ? '' : '<script>' . $js . '</script>';
    }
}
