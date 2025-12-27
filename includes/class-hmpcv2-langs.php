<?php

if (!defined('ABSPATH')) exit;

class HMPCv2_Langs {
    protected static $current_language = '';

    /**
     * Supported languages WHITELIST.
     * All allowed language codes must be present here to prevent arbitrary input.
     */
    public static function whitelist() {
        return array(
            // Default + common
            'tr' => 'Turkish',
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'cs' => 'Czech',
            'sk' => 'Slovak',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'el' => 'Greek',
            'sv' => 'Swedish',
            'no' => 'Norwegian',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'is' => 'Icelandic',
            'et' => 'Estonian',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'sl' => 'Slovenian',
            'hr' => 'Croatian',
            'sr' => 'Serbian',
            'bs' => 'Bosnian',
            'mk' => 'Macedonian',
            'sq' => 'Albanian',
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'be' => 'Belarusian',
            'ga' => 'Irish',
            'cy' => 'Welsh',
            'eu' => 'Basque',
            'ca' => 'Catalan',
            'gl' => 'Galician',
            'mt' => 'Maltese',
            'lb' => 'Luxembourgish',

            // Turkey neighbors + region
            'ka' => 'Georgian',
            'hy' => 'Armenian',
            'az' => 'Azerbaijani',
            'ar' => 'Arabic',
            'fa' => 'Persian',
            'ku' => 'Kurdish',

            // Chinese
            'zh' => 'Chinese',
        );
    }

    public static function is_allowed($code) {
        $code = strtolower(trim((string) $code));
        $wl = self::whitelist();
        return isset($wl[$code]);
    }

    public static function sanitize_lang_code($code, $fallback = '') {
        $code = strtolower(trim((string) $code));
        if ($code === '') return $fallback;
        return self::is_allowed($code) ? $code : $fallback;
    }

    public static function enabled_langs() {
        $enabled = HMPCv2_Options::get('enabled_langs', array('tr', 'en'));
        if (!is_array($enabled) || empty($enabled)) $enabled = array('tr', 'en');

        $out = array();
        foreach ($enabled as $c) {
            $c = self::sanitize_lang_code($c);
            if ($c && !in_array($c, $out, true)) $out[] = $c;
        }

        if (empty($out)) {
            $out = array('tr', 'en');
        }

        // Always ensure default is included
        $default = self::default_lang();
        if ($default && !in_array($default, $out, true)) array_unshift($out, $default);

        return $out;
    }

    public static function default_lang() {
        $def = HMPCv2_Options::get('default_lang', 'tr');
        $def = self::sanitize_lang_code($def, 'tr');
        if (!$def) $def = 'tr';
        return $def;
    }

    public static function get_default() {
        return self::default_lang();
    }

    public static function get_languages() {
        $enabled = self::enabled_langs();
        $labels = HMPCv2_Options::get('lang_labels', array());

        $out = array();
        foreach ($enabled as $code) {
            $label = isset($labels[$code]) ? wp_strip_all_tags($labels[$code]) : '';
            $out[] = array(
                'code' => $code,
                'label' => $label ? $label : self::label($code),
            );
        }

        return $out;
    }

    public static function get_codes() {
        $codes = array();
        foreach (self::get_languages() as $lang) {
            $codes[] = $lang['code'];
        }
        return $codes;
    }

    public static function find_language($code) {
        foreach (self::get_languages() as $lang) {
            if ($lang['code'] === $code) return $lang;
        }
        return null;
    }

    public static function set_current_language($code) {
        $code = self::sanitize_lang_code($code, self::default_lang());
        if (!$code) {
            $code = self::default_lang();
        }
        self::$current_language = $code;
    }

    public static function get_current_language() {
        if (!self::$current_language) {
            self::$current_language = self::default_lang();
        }
        return self::$current_language;
    }

    public static function label($code) {
        $wl = self::whitelist();
        $code = strtolower(trim((string) $code));
        return isset($wl[$code]) ? $wl[$code] : strtoupper($code);
    }

    public static function render_dropdown($name = 'lang', $selected = '', $attrs = array()) {
        $enabled = self::enabled_langs();
        if (!is_array($enabled) || empty($enabled)) $enabled = array(self::default_lang());

        $attr_str = '';
        if (is_array($attrs)) {
            foreach ($attrs as $k => $v) {
                if ($v === null) continue;
                $attr_str .= ' ' . esc_attr($k) . '="' . esc_attr((string)$v) . '"';
            }
        }

        echo '<select name="' . esc_attr($name) . '"' . $attr_str . '>';
        foreach ($enabled as $code) {
            $label = self::label($code);
            echo '<option value="' . esc_attr($code) . '" ' . selected($selected, $code, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
}

