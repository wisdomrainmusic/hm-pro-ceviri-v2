<?php

if (!defined('ABSPATH')) exit;

final class HMPCv2_Options {
    const OPT_KEY = 'hmpcv2_settings';

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
        );
    }

    public static function maybe_init_defaults() {
        $val = get_option(self::OPT_KEY, null);
        if (!is_array($val)) {
            add_option(self::OPT_KEY, self::defaults());
            return;
        }

        $merged = wp_parse_args($val, self::defaults());
        update_option(self::OPT_KEY, $merged, false);
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
}
