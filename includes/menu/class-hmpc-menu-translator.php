<?php
if (!defined('ABSPATH')) exit;

final class HMPC_Menu_Translator {
    public static function init() {
        add_filter('wp_nav_menu_objects', array(__CLASS__, 'translate_menu_items'), 20, 2);
        add_filter('megamenu_nav_menu_objects', array(__CLASS__, 'translate_menu_items'), 20, 2);
    }

    public static function translate_menu_items($items, $args) {
        if (!is_array($items) || empty($items)) return $items;

        $lang = HMPC_Language::current();
        $default = HMPC_Language::default_lang();
        $enabled = HMPC_Language::enabled_langs();

        if (!in_array($lang, $enabled, true)) {
            $lang = $default;
        }

        foreach ($items as $item) {
            self::apply_label_translation($item, $lang, $default);
            self::apply_url_translation($item, $lang, $default);
        }

        return $items;
    }

    private static function apply_label_translation($item, $lang, $default) {
        $key = '_hmpc_menu_label_' . $lang;
        $label = get_post_meta($item->ID, $key, true);

        if (!$label && $lang !== $default) {
            $fallback_key = '_hmpc_menu_label_' . $default;
            $label = get_post_meta($item->ID, $fallback_key, true);
        }

        if ($label) {
            $item->title = $label;
        }
    }

    private static function apply_url_translation($item, $lang, $default) {
        $url = isset($item->url) ? (string) $item->url : '';

        if ($url === '' || strpos($url, '#') === 0) return;
        if (preg_match('~^(mailto:|tel:|javascript:)~i', $url)) return;

        $parts = wp_parse_url($url);
        if ($parts === false) return;

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (isset($parts['scheme']) && !in_array($parts['scheme'], array('http', 'https'), true)) {
            return;
        }

        if (isset($parts['host']) && $parts['host'] && $home_host && strtolower($parts['host']) !== strtolower($home_host)) {
            return;
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = $path === '' ? '/' : $path;

        $has_trailing = substr($path, -1) === '/';
        $path = self::strip_lang_prefix($path);

        $prefixed = self::prefix_path($path, $lang, $default);
        if ($has_trailing && substr($prefixed, -1) !== '/') {
            $prefixed .= '/';
        }

        $new_url = self::assemble_url($parts, $prefixed);
        $item->url = $new_url;
    }

    private static function strip_lang_prefix($path) {
        $path = '/' . ltrim((string) $path, '/');
        $trim = trim($path, '/');
        $parts = ($trim === '') ? array() : explode('/', $trim);

        if (!empty($parts)) {
            $maybe = strtolower((string) $parts[0]);
            if (in_array($maybe, HMPC_Language::enabled_langs(), true)) {
                array_shift($parts);
            }
        }

        $clean = '/' . implode('/', $parts);
        if ($clean === '//') $clean = '/';
        if ($clean === '') $clean = '/';

        return $clean;
    }

    private static function prefix_path($path, $lang, $default) {
        $path = '/' . ltrim((string) $path, '/');
        if ($path === '') $path = '/';

        $prefix_default = HMPC_Language::prefix_default_lang();

        if ($lang === $default && !$prefix_default) {
            return $path;
        }

        if ($path === '/' || $path === '') {
            return '/' . $lang . '/';
        }

        return '/' . $lang . '/' . ltrim($path, '/');
    }

    private static function assemble_url($parts, $path) {
        $url = '';

        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $url .= $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':' . $parts['pass'];
            }
            $url .= '@';
        }

        if (isset($parts['host'])) {
            $url .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $url .= $path;

        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}
