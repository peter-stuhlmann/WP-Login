<?php

declare(strict_types=1);

namespace Portier\Design;

/**
 * Cleans custom CSS before it is stored and printed inside <style>.
 *
 * Only people with the `unfiltered_html` capability may save custom CSS (checked by the REST
 * endpoint) – this is a second line of defence that also protects against mistakes: no markup,
 * no way out of the <style> element, no remote imports, no script URLs, no legacy script hooks.
 */
final class CssSanitizer
{
    public const MAX_LENGTH = 20000;

    public static function clean(string $css): string
    {
        $css = str_replace("\0", '', $css);
        // No markup at all – this also removes any attempt to close the <style> element.
        $css = wp_strip_all_tags($css);
        $css = (string) preg_replace('#</?\s*style#i', '', $css);
        // No remote or nested stylesheets.
        $css = (string) preg_replace('/@import\b[^;]*;?/i', '', $css);
        // Legacy script hooks (old IE and Firefox).
        $css = (string) preg_replace('/expression\s*\(|behaviou?r\s*:|-moz-binding\s*:/i', '/* removed */(', $css);
        // url(): only https, relative URLs and raster/SVG data images.
        $css = (string) preg_replace_callback(
            '/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
            static function (array $match): string {
                $url = trim($match[2]);
                $safe = preg_match('#^(https://|/|\./|\.\./|[a-z0-9_-]+\.(?:png|jpe?g|gif|webp|avif|svg|woff2?)$)#i', $url)
                    || preg_match('#^data:image/(png|jpe?g|gif|webp|avif|svg\+xml)[;,]#i', $url);
                return $safe && ! preg_match('/javascript:|vbscript:/i', $url) ? $match[0] : 'url("")';
            },
            $css
        );
        return mb_substr(trim($css), 0, self::MAX_LENGTH);
    }
}
