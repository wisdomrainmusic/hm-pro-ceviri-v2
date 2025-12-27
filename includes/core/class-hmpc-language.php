<?php
if (!defined('ABSPATH')) exit;

final class HMPC_Language {
    public static function current() {
        $lang = HMPCv2_Langs::sanitize_lang_code(HMPCv2_Langs::get_current_language(), '');

        if (!$lang) {
            $lang = HMPCv2_Langs::sanitize_lang_code(HMPCv2_Router::current_lang(), '');
        }

        if (!$lang) {
            $lang = HMPCv2_Langs::default_lang();
        }

        $enabled = HMPCv2_Langs::enabled_langs();
        if (!in_array($lang, $enabled, true)) {
            $lang = HMPCv2_Langs::default_lang();
        }

        return $lang;
    }

    public static function default_lang() {
        return HMPCv2_Langs::default_lang();
    }

    public static function enabled_langs() {
        return HMPCv2_Langs::enabled_langs();
    }

    public static function sanitize($code, $fallback = '') {
        return HMPCv2_Langs::sanitize_lang_code($code, $fallback);
    }

    public static function prefix_default_lang() {
        return HMPCv2_Router::prefix_default_lang();
    }
}
