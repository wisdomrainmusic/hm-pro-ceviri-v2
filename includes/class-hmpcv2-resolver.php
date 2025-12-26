<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Resolver {

    public static function resolve_translation_post_id($source_post_id, $target_lang) {
        $source_post_id = (int)$source_post_id;
        $target_lang = HMPCv2_Langs::sanitize_lang_code($target_lang, HMPCv2_Langs::default_lang());

        if ($source_post_id < 1) return 0;

        $group = HMPCv2_Translations::get_group($source_post_id);
        if ($group === '') return 0;

        $target_id = HMPCv2_Translations::find_post_by_group_lang($group, $target_lang);
        return (int)$target_id;
    }

    public static function permalink_with_lang($post_id, $lang) {
        $post_id = (int)$post_id;
        if ($post_id < 1) return home_url('/');

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $lang = HMPCv2_Langs::sanitize_lang_code($lang, $default);
        if (!in_array($lang, $enabled, true)) $lang = $default;

        $prefix_default = HMPCv2_Router::prefix_default_lang();

        $base = get_permalink($post_id);
        if (!$base) $base = home_url('/');

        $path  = (string) parse_url($base, PHP_URL_PATH);
        $query = (string) parse_url($base, PHP_URL_QUERY);

        $path = '/' . ltrim($path, '/');

        // 1) Strip any existing language prefix from path to avoid /en/en/...
        $trim = trim($path, '/');
        $parts = ($trim === '') ? array() : explode('/', $trim);
        if (!empty($parts)) {
            $maybe = strtolower((string)$parts[0]);
            if (HMPCv2_Langs::is_allowed($maybe) && in_array($maybe, $enabled, true)) {
                array_shift($parts);
                $path = '/' . implode('/', $parts);
                if ($path === '//') $path = '/';
                if ($path === '') $path = '/';
            }
        }

        // 2) Plain permalink safety: if path is just "/" but query exists (e.g., ?page_id=123)
        //    Use /en/?page_id=123 or /?page_id=123 depending on target language.
        $is_plain_like = ($path === '/' && $query !== '');

        if ($lang === $default && !$prefix_default) {
            $url = $path;
        } else {
            if ($path === '/' || $path === '') {
                $url = '/' . $lang . '/';
            } else {
                $url = '/' . $lang . '/' . ltrim($path, '/');
            }
        }

        // Ensure single trailing slash for pretty paths (avoid messing query-only urls)
        if (!$is_plain_like) {
            if (substr($url, -1) !== '/') $url .= '/';
        }

        if ($query !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }

        return $url;
    }

    public static function switch_url_for_current_context($target_lang) {
        $target_lang = HMPCv2_Langs::sanitize_lang_code($target_lang, HMPCv2_Langs::default_lang());

        // If viewing a singular post/page, try to map to translation in same group
        if (is_singular()) {
            $source_id = get_queried_object_id();
            $target_id = self::resolve_translation_post_id($source_id, $target_lang);

            if ($target_id > 0) {
                return self::permalink_with_lang($target_id, $target_lang);
            }

            // Fallback: stay on same content but just switch prefix (best-effort)
            return self::permalink_with_lang($source_id, $target_lang);
        }

        // Non-singular (archives/search/etc): best-effort prefix approach based on current request path
        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();
        $prefix_default = HMPCv2_Router::prefix_default_lang();

        $req = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($req, PHP_URL_PATH);
        $path = '/' . ltrim((string)$path, '/');

        $trim = trim($path, '/');
        $parts = ($trim === '') ? array() : explode('/', $trim);

        // Remove existing lang prefix if present
        if (!empty($parts)) {
            $maybe = strtolower((string)$parts[0]);
            if (HMPCv2_Langs::is_allowed($maybe) && in_array($maybe, $enabled, true)) {
                array_shift($parts);
            }
        }

        $rest = '/' . implode('/', $parts);
        if ($rest === '//') $rest = '/';
        if ($rest === '') $rest = '/';

        $query = parse_url($req, PHP_URL_QUERY);
        $query_suffix = $query ? ('?' . $query) : '';

        if ($target_lang === $default && !$prefix_default) {
            return $rest . $query_suffix;
        }

        return '/' . $target_lang . ($rest === '/' ? '/' : $rest) . $query_suffix;
    }
}
