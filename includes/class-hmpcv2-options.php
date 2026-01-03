<?php

if (!defined('ABSPATH')) exit;

final class HMPCv2_Options {
    const OPT_KEY = 'hmpcv2_settings';
    const TERM_OPT_KEY = 'hmpcv2_term_translations';
    const STYLE_OPT_KEY = 'hmpcv2_style_settings';

    public static function defaults() {
        return array(
            'default_lang' => 'tr',
            'enabled_langs' => array('tr', 'en'),
            'lang_labels' => array(
                'tr' => 'Türkçe',
                'en' => 'English',
            ),
            // Wix-style: default language has NO prefix (e.g., /about), others use /en/about
            'prefix_default_lang' => false,
            'cookie_remember' => true,
            'cookie_days' => 30,
            // Auto-switch visitor language by country (WooCommerce geolocation).
            // When enabled, first-time visitors (no prefix + no hmpcv2_lang cookie) will be
            // redirected to a matching language prefix (e.g. RO -> /ro/).
            'geo_autoswitch' => true,
            // legacy: style used to live here; kept for backwards compat/migration only
            'style' => array(
                'switcher_z' => 99999,
                'switcher_bg' => 'rgba(0,0,0,0.35)',
                'switcher_color' => '#ffffff',
                'force_on_hero' => 0,
            ),
        );
    }

    public static function maybe_init_defaults() {
        $val = get_option(self::OPT_KEY, null);
        if (!is_array($val)) {
            add_option(self::OPT_KEY, self::defaults());
            // also ensure style option exists
            add_option(self::STYLE_OPT_KEY, self::default_style());
            return;
        }

        $merged = wp_parse_args($val, self::defaults());
        // If the site has multiple enabled languages, default geo autoswitch to ON
        // (common multi-lang setup where visitors should land in their country language).
        if (empty($merged['geo_autoswitch']) && !empty($merged['enabled_langs']) && is_array($merged['enabled_langs']) && count($merged['enabled_langs']) > 2) {
            $merged['geo_autoswitch'] = true;
        }
        update_option(self::OPT_KEY, $merged, false);

        // ensure style option exists (and migrate once if needed)
        self::maybe_migrate_style($merged);
    }

    public static function default_style() {
        return array(
            'switcher_z' => 99999,
            'switcher_bg' => 'rgba(0,0,0,0.35)',
            'switcher_color' => '#ffffff',
            'force_on_hero' => 0,
        );
    }

    private static function sanitize_style($style) {
        if (!is_array($style)) $style = array();
        $out = array(
            'switcher_z' => isset($style['switcher_z']) ? (int) $style['switcher_z'] : 99999,
            // keep as string; do not over-sanitize CSS tokens
            'switcher_bg' => isset($style['switcher_bg']) ? trim((string) wp_unslash($style['switcher_bg'])) : 'rgba(0,0,0,0.35)',
            'switcher_color' => isset($style['switcher_color']) ? trim((string) wp_unslash($style['switcher_color'])) : '#ffffff',
            'force_on_hero' => !empty($style['force_on_hero']) ? 1 : 0,
        );
        if ($out['switcher_bg'] === '') $out['switcher_bg'] = 'rgba(0,0,0,0.35)';
        if ($out['switcher_color'] === '') $out['switcher_color'] = '#ffffff';
        return $out;
    }

    private static function maybe_migrate_style($merged_settings = null) {
        $style_opt = get_option(self::STYLE_OPT_KEY, null);
        if (is_array($style_opt) && !empty($style_opt)) {
            // already migrated/exists
            return;
        }

        if (!is_array($merged_settings)) {
            $merged_settings = get_option(self::OPT_KEY, self::defaults());
        }
        $legacy = (is_array($merged_settings) && isset($merged_settings['style']) && is_array($merged_settings['style']))
            ? $merged_settings['style']
            : self::default_style();

        add_option(self::STYLE_OPT_KEY, self::sanitize_style($legacy));
    }

    public static function get_style() {
        // ensure option exists + migrate from legacy if needed
        self::maybe_migrate_style();
        $style = get_option(self::STYLE_OPT_KEY, self::default_style());
        return self::sanitize_style($style);
    }

    public static function set_style($style) {
        $clean = self::sanitize_style($style);
        update_option(self::STYLE_OPT_KEY, $clean, false);
        return $clean;
    }

    public static function get_all() {
        $val = get_option(self::OPT_KEY, self::defaults());
        if (!is_array($val)) $val = array();

        $clean = wp_parse_args($val, self::defaults());
        $clean['enabled_langs'] = self::sanitize_enabled_langs($clean['enabled_langs']);
        $clean['lang_labels'] = self::sanitize_labels($clean['lang_labels'], $clean['enabled_langs']);
        $clean['default_lang'] = self::sanitize_code($clean['default_lang']);
        $clean['prefix_default_lang'] = (bool) $clean['prefix_default_lang'];
        $clean['cookie_remember'] = (bool) $clean['cookie_remember'];
        $clean['cookie_days'] = max(1, absint($clean['cookie_days']));
        $clean['geo_autoswitch'] = !empty($clean['geo_autoswitch']);

        // style is now sourced from dedicated option (stable)
        $clean['style'] = self::get_style();

        if (!self::is_language_allowed($clean['default_lang'], $clean['enabled_langs'])) {
            $clean['default_lang'] = $clean['enabled_langs'] ? self::get_first_language($clean['enabled_langs']) : 'tr';
        }

        return $clean;
    }

    public static function get($key, $fallback = null) {
        $all = self::get_all();
        return array_key_exists($key, $all) ? $all[$key] : $fallback;
    }

    public static function update($new_settings) {
        if (!is_array($new_settings)) return false;

        $clean = wp_parse_args($new_settings, self::defaults());
        $clean['enabled_langs'] = self::sanitize_enabled_langs($clean['enabled_langs']);
        $clean['lang_labels'] = self::sanitize_labels($clean['lang_labels'], $clean['enabled_langs']);
        $clean['default_lang'] = self::sanitize_code($clean['default_lang']);
        $clean['prefix_default_lang'] = (bool) $clean['prefix_default_lang'];
        $clean['cookie_remember'] = (bool) $clean['cookie_remember'];
        $clean['cookie_days'] = max(1, absint($clean['cookie_days']));
        $clean['geo_autoswitch'] = !empty($clean['geo_autoswitch']);

        // style
        $style = isset($clean['style']) && is_array($clean['style']) ? $clean['style'] : array();
        $clean['style'] = array(
            'switcher_z' => isset($style['switcher_z']) ? (int)$style['switcher_z'] : 99999,
            'switcher_bg' => isset($style['switcher_bg']) ? sanitize_text_field((string)$style['switcher_bg']) : 'rgba(0,0,0,0.35)',
            'switcher_color' => isset($style['switcher_color']) ? sanitize_text_field((string)$style['switcher_color']) : '#ffffff',
            'force_on_hero' => !empty($style['force_on_hero']) ? 1 : 0,
        );

        if (!self::is_language_allowed($clean['default_lang'], $clean['enabled_langs'])) {
            $clean['default_lang'] = $clean['enabled_langs'] ? self::get_first_language($clean['enabled_langs']) : 'tr';
        }

        return update_option(self::OPT_KEY, $clean, false);
    }

    public static function sanitize_enabled_langs($langs) {
        $clean = array();
        if (!is_array($langs)) return $clean;

        foreach ($langs as $lang) {
            $code = is_array($lang) && isset($lang['code']) ? $lang['code'] : $lang;
            $code = HMPCv2_Langs::sanitize_lang_code($code, '');
            if (!$code) continue;
            $clean[$code] = $code;
        }

        return array_values($clean);
    }

    public static function sanitize_labels($labels, $enabled_langs) {
        $clean = array();
        if (!is_array($labels)) $labels = array();

        foreach ($enabled_langs as $code) {
            $label = isset($labels[$code]) ? $labels[$code] : '';
            $label = wp_strip_all_tags($label);
            $clean[$code] = $label ? $label : HMPCv2_Langs::label($code);
        }

        return $clean;
    }

    public static function sanitize_code($code) {
        $code = strtolower(sanitize_key($code));
        $code = preg_replace('/[^a-z0-9_-]/', '', $code);
        return HMPCv2_Langs::sanitize_lang_code($code, '');
    }

    public static function is_language_allowed($code, $enabled_langs = null) {
        if ($enabled_langs === null) {
            $enabled_langs = self::get('enabled_langs', array());
        }

        $code = self::sanitize_code($code);
        return in_array($code, $enabled_langs, true);
    }

    public static function get_languages() {
        $opts = self::get_all();
        $langs = array();
        foreach ($opts['enabled_langs'] as $code) {
            $langs[] = array(
                'code' => $code,
                'label' => self::get_label($code, $opts['lang_labels']),
            );
        }
        return $langs;
    }

    public static function get_default_language() {
        return self::get('default_lang');
    }

    public static function get_first_language($languages) {
        if (empty($languages)) return '';
        $first = array_values($languages);
        return isset($first[0]) ? $first[0] : '';
    }

    public static function get_label($code, $labels = null) {
        if ($labels === null) {
            $labels = self::get('lang_labels', array());
        }
        return isset($labels[$code]) ? $labels[$code] : HMPCv2_Langs::label($code);
    }

    public static function get_term_translations() {
        $val = get_option(self::TERM_OPT_KEY, array());
        return is_array($val) ? $val : array();
    }

    public static function save_term_translation($term_id, $lang, $data) {
        $term_id = (int)$term_id;
        $lang = HMPCv2_Langs::sanitize_lang_code($lang, '');
        if ($term_id < 1 || $lang === '') return false;

        $existing = self::get_term_translations();
        if (!isset($existing[$term_id]) || !is_array($existing[$term_id])) {
            $existing[$term_id] = array();
        }

        $name = isset($data['name']) ? sanitize_text_field((string)$data['name']) : '';
        $slug = isset($data['slug']) ? sanitize_title((string)$data['slug']) : '';
        $description = isset($data['description']) ? wp_kses_post((string)$data['description']) : '';

        $existing[$term_id][$lang] = array(
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
        );

        return update_option(self::TERM_OPT_KEY, $existing, false);
    }
}
