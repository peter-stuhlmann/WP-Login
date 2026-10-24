<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Http\Request;

/**
 * Language of the login screen.
 *
 * Order: explicit choice (?wp_lang=…, remembered in core's `wp_lang` cookie, exactly like
 * wp-login.php) → current Polylang language → the site/user default from determine_locale().
 */
final class Language
{
    /** Native names for the switcher; unknown locales fall back to their code. */
    private const NATIVE_NAMES = [
        'en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'de_DE' => 'Deutsch', 'de_DE_formal' => 'Deutsch (Sie)',
        'de_AT' => 'Deutsch (Österreich)', 'de_CH' => 'Deutsch (Schweiz)', 'de_CH_informal' => 'Deutsch (Schweiz, Du)',
        'fr_FR' => 'Français', 'es_ES' => 'Español', 'it_IT' => 'Italiano', 'nl_NL' => 'Nederlands', 'pl_PL' => 'Polski',
        'pt_PT' => 'Português', 'pt_BR' => 'Português do Brasil', 'sv_SE' => 'Svenska', 'da_DK' => 'Dansk', 'nb_NO' => 'Norsk bokmål',
        'fi' => 'Suomi', 'cs_CZ' => 'Čeština', 'tr_TR' => 'Türkçe', 'ru_RU' => 'Русский', 'uk' => 'Українська', 'el' => 'Ελληνικά',
        'ja' => '日本語', 'zh_CN' => '简体中文', 'ko_KR' => '한국어', 'ar' => 'العربية', 'he_IL' => 'עִבְרִית',
    ];

    /**
     * Locale to use for this request, or null to keep WordPress' choice.
     */
    public static function fromRequest(Request $request): ?string
    {
        $available = array_keys(self::available());
        foreach ([$request->query('wp_lang'), (string) $request->cookie('wp_lang')] as $candidate) {
            if ($candidate !== '' && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }
        if (function_exists('pll_current_language')) {
            $polylang = pll_current_language('locale');
            if (is_string($polylang) && $polylang !== '') {
                return $polylang;
            }
        }
        return null;
    }

    /**
     * Installed languages (always including English) with their native names.
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        $locales = array_unique(array_merge(['en_US'], get_available_languages()));
        $cached = get_site_transient('available_translations'); // Filled by core when browsing language settings.
        $names = [];
        foreach ($locales as $locale) {
            $names[$locale] = self::NATIVE_NAMES[$locale]
                ?? (is_array($cached) && isset($cached[$locale]['native_name']) ? (string) $cached[$locale]['native_name'] : $locale);
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /**
     * Two-letter label for the compact switcher button, e.g. "DE".
     */
    public static function shortLabel(string $locale): string
    {
        return strtoupper(substr($locale, 0, 2));
    }

    /**
     * BCP 47 tag for hreflang/lang attributes ("de_DE_formal" → "de-DE").
     */
    public static function bcp47(string $locale): string
    {
        $parts = explode('_', $locale);
        return $parts[0] . (isset($parts[1]) && strlen($parts[1]) === 2 ? '-' . $parts[1] : '');
    }
}
