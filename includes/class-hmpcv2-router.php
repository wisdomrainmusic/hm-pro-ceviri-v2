<?php

if (!defined('ABSPATH')) exit;

class HMPCv2_Router {
    const QUERY_VAR_LANG = 'hmpcv2_lang';
    const QUERY_VAR_PATH = 'hmpcv2_path';
    const QV_LANG = 'hmpcv2_lang';
    const QV_PATH = 'hmpcv2_path';

    public static function init() {
        add_filter('query_vars', array(__CLASS__, 'register_query_vars'));
        add_action('init', array(__CLASS__, 'register_rewrite_rules'), 5);
        add_action('init', array(__CLASS__, 'register_rewrites'), 5);
        add_action('parse_request', array(__CLASS__, 'parse_request_lang_prefixed'), 0);
        add_filter('request', array(__CLASS__, 'map_lang_prefixed_request'), 1);

        // IMPORTANT: Router behavior must be FRONTEND-only
        if (is_admin()) {
            return;
        }

        add_action('parse_request', array(__CLASS__, 'parse_request_lang'), 1);
        add_action('template_redirect', array(__CLASS__, 'canonical_redirect_default_prefix'), 1);

        // Remember language in cookie (optional) - frontend only
        add_action('init', array(__CLASS__, 'maybe_set_lang_cookie'), 20);
    }

    public static function add_query_vars($vars) {
        return self::register_query_vars($vars);
    }

    public static function register_query_vars($vars) {
        $vars[] = self::QV_LANG;
        $vars[] = self::QV_PATH;
        $vars[] = 'hmpc_path';
        return $vars;
    }

    public static function register_rewrite_rules() {
        add_rewrite_tag('%' . self::QV_LANG . '%', '([^/]+)');
        add_rewrite_tag('%' . self::QV_PATH . '%', '(.+)');

        $default = HMPCv2_Langs::get_default();
        $prefix_default = HMPCv2_Options::get('prefix_default_lang', false);

        foreach (HMPCv2_Langs::get_codes() as $code) {
            if (!$prefix_default && $code === $default) {
                continue;
            }

            add_rewrite_rule('^' . $code . '/?$', 'index.php?' . self::QV_LANG . '=' . $code, 'top');
            add_rewrite_rule('^' . $code . '/(.+)?$', 'index.php?' . self::QV_LANG . '=' . $code . '&' . self::QV_PATH . '=$matches[1]', 'top');
        }
    }

    public static function register_rewrites() {
        $langs = HMPCv2_Langs::enabled_langs();
        if (!is_array($langs) || empty($langs)) return;

        $lang_regex = implode('|', array_map('preg_quote', $langs));

        add_rewrite_tag('%' . self::QV_LANG . '%', '(' . $lang_regex . ')');
        add_rewrite_tag('%' . self::QV_PATH . '%', '(.+)');

        // Catch-all prefixed route: /en/... , /de/... , /fr/...
        add_rewrite_rule(
            '^(' . $lang_regex . ')/(.*)?$',
            'index.php?' . self::QV_LANG . '=$matches[1]&' . self::QV_PATH . '=$matches[2]',
            'top'
        );
    }

    public static function parse_request_lang($wp) {
        if (is_admin()) return $wp;

        $lang = isset($wp->query_vars[self::QV_LANG]) ? $wp->query_vars[self::QV_LANG] : '';
        $path = isset($wp->query_vars[self::QV_PATH]) ? $wp->query_vars[self::QV_PATH] : '';

        if (!$lang) {
            list($lang, $path) = self::detect_from_request();
        }

        $lang = HMPCv2_Langs::sanitize_lang_code($lang, '');

        if (!$lang && HMPCv2_Options::get('cookie_remember', true)) {
            $cookie_lang = isset($_COOKIE['hmpcv2_lang']) ? $_COOKIE['hmpcv2_lang'] : '';
            $cookie_lang = HMPCv2_Langs::sanitize_lang_code($cookie_lang, '');

            if ($cookie_lang && HMPCv2_Options::is_language_allowed($cookie_lang, HMPCv2_Options::get('enabled_langs'))) {
                $lang = $cookie_lang;
            }
        }

        if (!$lang) {
            $lang = HMPCv2_Langs::get_default();
        }

        HMPCv2_Langs::set_current_language($lang);

        if ($path) {
            $wp->query_vars['pagename'] = $path;
        }
    }

    private static function detect_lang_from_request_uri() {
        if (empty($_SERVER['REQUEST_URI'])) return '';
        $uri = (string) $_SERVER['REQUEST_URI'];

        // strip query
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . ltrim($path, '/');

        $trim = trim($path, '/');
        if ($trim === '') return '';

        $parts = explode('/', $trim);
        $first = strtolower((string)($parts[0] ?? ''));

        $enabled = HMPCv2_Langs::enabled_langs();
        if ($first && in_array($first, $enabled, true)) return $first;

        return '';
    }

    public static function current_lang() {
        $default = HMPCv2_Langs::default_lang();
        $enabled = HMPCv2_Langs::enabled_langs();

        // 1) URL prefix wins (/en/..., /de/...)
        $uri_lang = self::detect_lang_from_request_uri();
        if ($uri_lang !== '' && in_array($uri_lang, $enabled, true)) {
            return $uri_lang;
        }

        // 2) Query var
        $qv = get_query_var(self::QV_LANG);
        if (is_string($qv) && $qv !== '') {
            $qv = strtolower($qv);
            if (in_array($qv, $enabled, true)) return $qv;
        }

        // 3) Cookie fallback
        if (!empty($_COOKIE['hmpcv2_lang'])) {
            $c = strtolower((string) $_COOKIE['hmpcv2_lang']);
            if (in_array($c, $enabled, true)) return $c;
        }

        return $default;
    }

    public static function map_lang_prefixed_request($query_vars) {
        if (empty($query_vars[self::QV_LANG]) || !isset($query_vars[self::QV_PATH])) {
            return $query_vars;
        }

        $lang = strtolower((string) $query_vars[self::QV_LANG]);
        $path = (string) $query_vars[self::QV_PATH];
        $path = trim($path, '/');

        // Guard: language must be enabled
        $enabled = HMPCv2_Langs::enabled_langs();
        if (!in_array($lang, $enabled, true)) {
            return $query_vars;
        }

        // Resolve the original (unprefixed) URL to a post ID
        $unpref_url = home_url('/' . $path . '/');
        $post_id = (int) url_to_postid($unpref_url);

        if ($post_id > 0) {
            $pt = get_post_type($post_id);
            if (!$pt) $pt = 'any';

            // Force main query to load that object
            $query_vars = array(
                'p' => $post_id,
                'post_type' => $pt,
                self::QV_LANG => $lang,
                self::QV_PATH => $path,
            );

            return $query_vars;
        }

        // If it's not a post, let WP handle (could be taxonomy, search, etc.)
        return $query_vars;
    }

    public static function prefix_default_lang() {
        return (bool) HMPCv2_Options::get('prefix_default_lang', false);
    }

    protected static function detect_from_request() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $request_uri = strtok($request_uri, '?');
        $request_uri = ltrim($request_uri, '/');

        if (empty($request_uri)) return array('', '');

        $segments = explode('/', $request_uri);
        $first = HMPCv2_Options::sanitize_code(array_shift($segments));

        if ($first && HMPCv2_Options::is_language_allowed($first, HMPCv2_Options::get('enabled_langs'))) {
            return array($first, implode('/', $segments));
        }

        return array('', $request_uri);
    }

    public static function get_current_path() {
        list($lang, $path) = self::detect_from_request();
        if ($lang && $path === '') return '';
        return $path;
    }

    public static function build_url_for_lang($lang_code, $path = '') {
        $lang_code = HMPCv2_Options::sanitize_code($lang_code);
        if (!HMPCv2_Options::is_language_allowed($lang_code, HMPCv2_Options::get('enabled_langs'))) {
            return home_url($path);
        }

        $path = ltrim($path, '/');
        $default_lang = HMPCv2_Langs::get_default();
        $prefix_default = HMPCv2_Options::get('prefix_default_lang', false);

        $prefix = ($lang_code === $default_lang && !$prefix_default) ? '' : $lang_code . '/';
        $url_path = $prefix . $path;

        return home_url($url_path);
    }

    public static function filter_home_url($url, $path, $orig_scheme, $blog_id) {
        $current = HMPCv2_Langs::get_current_language();
        $path = ltrim($path, '/');
        $prefix_default = HMPCv2_Options::get('prefix_default_lang', false);
        $default = HMPCv2_Langs::get_default();

        if (!$current || ($current === $default && !$prefix_default)) {
            return $url;
        }

        if (strpos($path, $current . '/') === 0 || $path === $current) {
            return $url;
        }

        $prefixed_path = $current . '/' . $path;
        return home_url($prefixed_path);
    }

    public static function canonical_redirect_default_prefix() {
        if (is_admin()) return;

        if (HMPCv2_Options::get('prefix_default_lang', false)) {
            return;
        }

        $default = HMPCv2_Langs::get_default();
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = trim((string) parse_url($request_uri, PHP_URL_PATH), '/');

        if ($path === '') return;

        $segments = explode('/', $path);
        $first = HMPCv2_Langs::sanitize_lang_code($segments[0], '');

        if ($first !== $default) return;

        array_shift($segments);
        $new_path = '/' . implode('/', $segments);
        if ($new_path === '//') $new_path = '/';

        $query = parse_url($request_uri, PHP_URL_QUERY);
        $target = $new_path ? $new_path : '/';
        if ($query) {
            $target .= '?' . $query;
        }

        if ($target !== $request_uri) {
            wp_redirect($target, 301);
            exit;
        }
    }

    public static function maybe_set_lang_cookie() {
        if (is_admin()) return;

        if (!HMPCv2_Options::get('cookie_remember', true)) {
            return;
        }

        $lang = HMPCv2_Langs::get_current_language();
        if (!$lang) return;

        $days = (int) HMPCv2_Options::get('cookie_days', 30);
        $days = $days > 0 ? $days : 30;
        $expire = time() + ($days * DAY_IN_SECONDS);

        $current = isset($_COOKIE['hmpcv2_lang']) ? strtolower((string) $_COOKIE['hmpcv2_lang']) : '';
        if ($current === $lang) return;

        setcookie('hmpcv2_lang', $lang, $expire, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE['hmpcv2_lang'] = $lang;
    }

    public static function parse_request_lang_prefixed($wp) {
        if (is_admin()) return;
        if (empty($wp) || !isset($wp->request)) return;

        $req = trim((string) $wp->request, '/');
        if ($req === '') return;

        $parts = explode('/', $req);
        if (count($parts) < 3) return;

        $lang = strtolower((string) $parts[0]);
        $enabled = HMPCv2_Langs::enabled_langs();
        if (!in_array($lang, $enabled, true)) return;

        if ((string) $parts[1] !== 'urun') return;

        $slug = implode('/', array_slice($parts, 2));
        $slug = trim($slug, '/');
        if ($slug === '') return;

        $unpref = home_url('/urun/' . $slug . '/');
        $post_id = (int) url_to_postid($unpref);
        if ($post_id < 1) return;

        $wp->query_vars = array(
            'post_type' => 'product',
            'p' => $post_id,
            self::QV_LANG => $lang,
        );

        $wp->matched_rule  = 'hmpcv2_parse_request';
        $wp->matched_query = 'post_type=product&p=' . $post_id;

        set_query_var(self::QV_LANG, $lang);
        $_GET[self::QV_LANG] = $lang;
    }
}
