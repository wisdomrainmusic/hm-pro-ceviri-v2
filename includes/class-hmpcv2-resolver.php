<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Resolver {

    private static function raw_home_url(string $path = '/'): string {
        $home = (string) get_option('home');
        if ($home === '') {
            return home_url($path);
        }
        $home = rtrim($home, '/');
        $path = '/' . ltrim((string) $path, '/');
        return $home . $path;
    }

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
        $default = HMPCv2_Langs::default_lang();
        $enabled = HMPCv2_Langs::enabled_langs();
        $prefix_default = HMPCv2_Router::prefix_default_lang();

        $target_lang = HMPCv2_Langs::sanitize_lang_code($target_lang, $default);

        $current_id = (int) get_queried_object_id();
        if (is_front_page() && $current_id < 1) {
            $current_id = (int) get_option('page_on_front');
        }

        if ($current_id > 0) {
            $target_id = (int) self::resolve_translation_post_id($current_id, $target_lang);
            if ($target_id > 0) {
                $permalink = get_permalink($target_id);
                if ($permalink) {
                    return trailingslashit($permalink);
                }
            }
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $parts = wp_parse_url($uri);
        $path  = isset($parts['path']) ? (string) $parts['path'] : '/';
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

        $path = '/' . ltrim($path, '/');

        $trim = trim($path, '/');
        $segments = ($trim === '') ? array() : explode('/', $trim);
        if (!empty($segments)) {
            $maybe = strtolower((string) $segments[0]);
            if (HMPCv2_Langs::is_allowed($maybe) && in_array($maybe, $enabled, true)) {
                array_shift($segments);
            }
        }

        $base_path = '/' . implode('/', $segments);
        if ($base_path === '//') $base_path = '/';
        if ($base_path === '') $base_path = '/';

        $is_root = ($base_path === '/' || $base_path === '');

        if ($target_lang === $default) {
            if ($prefix_default) {
                $new_path = $is_root ? '/' . $default . '/' : '/' . $default . '/' . ltrim($base_path, '/');
            } else {
                $new_path = $is_root ? '/' : $base_path;
            }
        } else {
            $new_path = $is_root ? '/' . $target_lang . '/' : '/' . $target_lang . '/' . ltrim($base_path, '/');
        }

        $new_path = preg_replace('#/+#', '/', $new_path);

        if (substr($new_path, -1) !== '/') {
            $new_path .= '/';
        }

        /**
         * IMPORTANT: When switching to the default language while the default language
         * is NOT prefixed (e.g. TR lives at "/"), we MUST bypass home_url() because
         * HMPCv2_Router filters home_url() to keep the current language prefix.
         *
         * Symptom: From /en/... you can go to /de or /fr, but clicking TR appears to do
         * nothing because the generated unprefixed path is passed through home_url(),
         * which gets re-prefixed back to the current language (cookie/URI).
         */
        if ($target_lang === $default && !$prefix_default) {
            return self::raw_home_url($new_path) . $query;
        }

        return home_url($new_path) . $query;
    }
}
