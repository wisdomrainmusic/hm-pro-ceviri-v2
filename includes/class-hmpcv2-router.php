<?php

if (!defined('ABSPATH')) exit;

class HMPCv2_Router {
    const QUERY_VAR_LANG = 'hmpcv2_lang';
    const QUERY_VAR_PATH = 'hmpcv2_path';
    const QV_LANG = 'hmpcv2_lang';
    const QV_PATH = 'hmpcv2_path';

    private static function is_product_path($path) {
        $p = trim((string) $path, "/ \t\n\r\0\x0B");
        return (strpos($p, 'urun/') === 0);
    }

    public static function rescue_404_prefixed_product() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // Only act when WP thinks it's 404.
        if (!is_404()) {
            return;
        }

        $lang = get_query_var(self::QV_LANG);
        $path = get_query_var(self::QV_PATH);
        if (!$lang || !$path) {
            return;
        }

        if (!self::is_product_path($path)) {
            return;
        }

        $slug = trim(substr($path, strlen('urun/')), "/");
        if ($slug === '') {
            return;
        }

        // Find product by slug (post_name).
        $product = get_page_by_path($slug, OBJECT, 'product');
        if (!$product || empty($product->ID)) {
            return;
        }

        // Redirect to the real product permalink (prevents theme error-page).
        $target = get_permalink($product->ID);
        if (!$target) {
            return;
        }

        wp_redirect($target, 301);
        exit;
    }

    private static function is_shop_path($path) {
        $p = trim((string) $path, "/ \t\n\r\0\x0B");
        return ($p === 'magaza' || $p === 'shop');
    }

    private static function force_shop_archive_query($query_vars) {
        // Force Woo "shop" archive behavior.
        // Important: remove single/page vars to prevent page template rendering.
        unset($query_vars['p'], $query_vars['page_id'], $query_vars['pagename'], $query_vars['name'], $query_vars['attachment']);
        $query_vars['post_type'] = 'product';
        // Keep paging if present.
        if (!isset($query_vars['paged']) && isset($query_vars['page'])) {
            $query_vars['paged'] = absint($query_vars['page']);
        }
        return $query_vars;
    }

    public static function init() {
        add_filter('query_vars', array(__CLASS__, 'register_query_vars'));
        add_action('init', array(__CLASS__, 'register_rewrite_rules'), 5);
        add_action('init', array(__CLASS__, 'register_rewrites'), 5);
        add_action('parse_request', array(__CLASS__, 'parse_request_lang_prefixed'), 0);
        add_filter('request', array(__CLASS__, 'map_lang_prefixed_request'), 1);
        add_action('pre_get_posts', array(__CLASS__, 'force_front_page_for_lang_root'), 0);
        add_action('wp', array(__CLASS__, 'ensure_non_404_for_resolved_objects'), 0);

        // IMPORTANT: Router behavior must be FRONTEND-only
        if (is_admin()) {
            return;
        }

        // SAFETY NET: if /<lang>/urun/<slug> falls into 404, rescue it.
        add_action('template_redirect', array(__CLASS__, 'rescue_404_prefixed_product'), 0);

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

        // Only act on language-prefixed requests.
        // Non-prefixed URLs must be handled by native WordPress routing.
        $has_prefix = !empty($wp->query_vars[self::QV_LANG]);

        $lang = isset($wp->query_vars[self::QV_LANG]) ? $wp->query_vars[self::QV_LANG] : '';
        $path = isset($wp->query_vars[self::QV_PATH]) ? $wp->query_vars[self::QV_PATH] : '';

        if (!$has_prefix) {
            HMPCv2_Langs::set_current_language(HMPCv2_Langs::get_default());
            return $wp;
        }

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

        // IMPORTANT:
        // Do NOT force pagename.
        // This breaks taxonomy archives (WooCommerce categories, tags, etc.)
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

        // SHOP ROUTE FIX:
        // /<lang>/magaza and /<lang>/shop must behave like Woo Shop archive, not a Page.
        if (self::is_shop_path($path)) {
            $query_vars = self::force_shop_archive_query($query_vars);
            return $query_vars;
        }

        // PRODUCT ROUTE FIX (HARD):
        // /<lang>/urun/<slug> must not rely on url_to_postid(). Resolve directly from DB.
        if (self::is_product_path($path)) {
            $slug = trim(substr($path, strlen('urun/')), '/');
            if ($slug !== '') {
                $slug = urldecode($slug);

                // Resolve product directly by slug (post_name)
                $product = get_page_by_path($slug, OBJECT, 'product');
                if ($product && !empty($product->ID)) {
                    $post_id = (int) $product->ID;

                    // If translation exists for requested language, force translated product ID
                    if (class_exists('HMPCv2_Resolver')) {
                        $translated_id = (int) HMPCv2_Resolver::resolve_translation_post_id($post_id, $lang);
                        if ($translated_id > 0) {
                            $post_id = $translated_id;
                        }
                    }

                    return array(
                        'post_type'   => 'product',
                        'p'           => $post_id,
                        self::QV_LANG => $lang,
                        self::QV_PATH => $path,
                    );
                }
            }
        }

        // Guard: language must be enabled
        $enabled = HMPCv2_Langs::enabled_langs();
        if (!in_array($lang, $enabled, true)) {
            return $query_vars;
        }

        // 1) Woo taxonomy archives must stay as archives (map BEFORE url_to_postid)
        $tax_qv = self::maybe_map_woo_taxonomy_path($path);
        if (is_array($tax_qv)) {
            $tax_qv[self::QV_LANG] = $lang;
            $tax_qv[self::QV_PATH] = $path;
            return $tax_qv;
        }

        // Resolve the original (unprefixed) URL to a post ID
        $unpref_url = home_url('/' . $path . '/');
        $post_id = (int) url_to_postid($unpref_url);

        if ($post_id > 0) {
            // 2) If translation exists for requested language, force the translated ID
            $translated_id = 0;
            if (class_exists('HMPCv2_Resolver')) {
                $translated_id = (int) HMPCv2_Resolver::resolve_translation_post_id($post_id, $lang);
            }
            if ($translated_id > 0) {
                $post_id = $translated_id;
            }

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

    /**
     * Astra (and some themes) switch to error layout if the main query remains in 404 state,
     * even when a valid object was resolved for prefixed routes.
     * If we have a valid resolved product under a language prefix, force proper flags and 200.
     */
    public static function ensure_non_404_for_resolved_objects() {
        global $wp_query;
        if (!is_object($wp_query)) return;

        $qid = get_queried_object_id();
        if ($qid > 0 && !empty($wp_query->is_404)) {
            $wp_query->is_404 = false;
            status_header(200);
        }

        if ($qid > 0 && (is_singular() || is_page() || is_single())) {
            if (!empty($wp_query->is_404)) {
                $wp_query->is_404 = false;
                status_header(200);
                if (function_exists('nocache_headers')) {
                    nocache_headers();
                }
            }
        }

        if (isset($wp_query->query_vars['post_type']) && $wp_query->query_vars['post_type'] === 'product') {
            if (!empty($wp_query->is_404) && (get_queried_object_id() > 0)) {
                $wp_query->is_404 = false;
                status_header(200);
                if (function_exists('nocache_headers')) {
                    nocache_headers();
                }
            }
        }
    }

    private static function maybe_map_woo_taxonomy_path($path) {
        $path = trim((string)$path, '/');
        if ($path === '') return null;

        $segs = explode('/', $path);
        $first = (string)($segs[0] ?? '');
        if ($first === '') return null;

        // Get Woo permalink bases (supports customized bases like "urun-kategori")
        $cat_base = '';
        $tag_base = '';

        if (function_exists('wc_get_permalink_structure')) {
            $s = (array) wc_get_permalink_structure();
            $cat_base = isset($s['category_base']) ? trim((string)$s['category_base'], '/') : '';
            $tag_base = isset($s['tag_base']) ? trim((string)$s['tag_base'], '/') : '';
        }

        if ($cat_base === '' || $tag_base === '') {
            $p = (array) get_option('woocommerce_permalinks', array());
            if ($cat_base === '' && !empty($p['category_base'])) $cat_base = trim((string)$p['category_base'], '/');
            if ($tag_base === '' && !empty($p['tag_base']))      $tag_base = trim((string)$p['tag_base'], '/');
        }

        if ($cat_base === '') $cat_base = 'product-category';
        if ($tag_base === '') $tag_base = 'product-tag';

        // /{lang}/{cat_base}/{term...}
        if ($first === $cat_base) {
            $term_path = implode('/', array_slice($segs, 1));
            $term_path = trim($term_path, '/');
            if ($term_path === '') return null;
            return array(
                'post_type'   => 'product',
                'product_cat' => $term_path,
            );
        }

        // /{lang}/{tag_base}/{term}
        if ($first === $tag_base) {
            $term = (string)($segs[1] ?? '');
            $term = trim($term, '/');
            if ($term === '') return null;
            return array(
                'post_type'   => 'product',
                'product_tag' => $term,
            );
        }

        return null;
    }

    public static function force_front_page_for_lang_root($q) {
        if (is_admin() || !$q->is_main_query()) return;

        $lang = $q->get(self::QV_LANG);
        if (!$lang) return;

        // Sadece "/en/" gibi dil köklerinde çalışsın: path yoksa root kabul ediyoruz
        $path = $q->get(self::QV_PATH);
        if (!empty($path)) return;

        // Eğer WP zaten belirli bir şeye gidiyorsa (page_id, p, pagename vs) karışma
        if ($q->get('page_id') || $q->get('p') || $q->get('pagename') || $q->get('post_type')) {
            return;
        }

        $front_id = (int) get_option('page_on_front');
        if ($front_id <= 0) return;

        $group = HMPCv2_Translations::get_group($front_id);
        if ($group) {
            $enabled = HMPCv2_Langs::enabled_langs();
            $map = HMPCv2_Translations::get_group_map($group, $enabled);
            if (!empty($map[$lang])) {
                $front_id = (int) $map[$lang];
            }
        }

        // /en/ => front page (target language equivalent if available)
        $q->set('page_id', $front_id);

        // Blog’a düşmesin
        $q->set('pagename', '');
        $q->set('name', '');
        $q->set('category_name', '');
        $q->set('tag', '');

        $q->is_home = false;
        $q->is_front_page = true;
        $q->is_page = true;
        $q->is_singular = true;
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

        // Avoid redirect/canonical clashes for forced shop archive routes.
        $path = get_query_var(self::QV_PATH);
        if (self::is_shop_path($path)) {
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

        $lang = isset($wp->query_vars[self::QV_LANG]) ? (string) $wp->query_vars[self::QV_LANG] : '';
        $path = isset($wp->query_vars[self::QV_PATH]) ? (string) $wp->query_vars[self::QV_PATH] : '';

        // === HMPCv2: Product slug fallback resolver (hard fix) ===
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path_only   = $request_uri ? wp_parse_url($request_uri, PHP_URL_PATH) : '';
        $path_only   = is_string($path_only) ? trim($path_only) : '';
        if ($path_only !== '' && preg_match('#^/([a-z]{2})/urun/([^/]+)/?$#i', $path_only, $m)) {
            $lang = strtolower($m[1]);
            $slug = sanitize_title($m[2]);

            // First try: your existing default-language url_to_postid strategy
            $try = '/urun/' . $slug . '/';
            $id  = url_to_postid($try);

            // Fallback: resolve directly from DB by product slug (post_name)
            if (!$id) {
                $p = get_page_by_path($slug, OBJECT, 'product');
                if ($p && !empty($p->ID)) {
                    $id = (int) $p->ID;
                }
            }

            // If resolved, force single product query vars
            if ($id && get_post_type($id) === 'product') {
                $wp->query_vars['post_type']   = 'product';
                $wp->query_vars['p']           = (int) $id;

                // Keep language context for switcher/resolver
                $wp->query_vars['hmpcv2_lang'] = $lang;
                $wp->query_vars['hmpcv2_path'] = 'urun/' . $slug;

                // Safety: clear conflicting vars that could push WP to page/singular mismatch
                unset($wp->query_vars['page_id'], $wp->query_vars['pagename'], $wp->query_vars['name']);
            }
        }
        // === end hard fix ===

        if ($lang && self::is_product_path($path)) {
            $slug = trim(substr($path, strlen('urun/')), '/');
            if ($slug !== '') {
                $unpref = '/urun/' . $slug . '/';
                $post_id = (int) url_to_postid($unpref);

                if ($post_id > 0 && get_post_type($post_id) === 'product') {
                    $wp->query_vars['post_type'] = 'product';
                    $wp->query_vars['p'] = $post_id;
                    $wp->query_vars[self::QV_LANG] = $lang;
                    $wp->query_vars[self::QV_PATH] = $path;
                } else {
                    $wp->query_vars[self::QV_LANG] = $lang;
                    $wp->query_vars[self::QV_PATH] = $path;
                }
            }
        }

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
        if ($post_id > 0) {
            // If translation exists for requested language, force translated product ID
            $translated_id = 0;
            if (class_exists('HMPCv2_Resolver')) {
                $translated_id = (int) HMPCv2_Resolver::resolve_translation_post_id($post_id, $lang);
            }
            if ($translated_id > 0) {
                $post_id = $translated_id;
            }

            $wp->query_vars = array(
                'post_type' => 'product',
                'p' => $post_id,
                self::QV_LANG => $lang,
                self::QV_PATH => implode('/', array_slice($parts, 1)),
            );

            $wp->matched_rule  = 'hmpcv2_parse_request';
            $wp->matched_query = 'post_type=product&p=' . $post_id;

            set_query_var(self::QV_LANG, $lang);
            $_GET[self::QV_LANG] = $lang;
        } else {
            $wp->query_vars[self::QV_LANG] = $lang;
            $wp->query_vars[self::QV_PATH] = implode('/', array_slice($parts, 1));
        }
    }
}
