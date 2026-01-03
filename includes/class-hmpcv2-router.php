<?php

if (!defined('ABSPATH')) exit;

class HMPCv2_Router {
    const QUERY_VAR_LANG = 'hmpcv2_lang';
    const QUERY_VAR_PATH = 'hmpcv2_path';
    const QV_LANG = 'hmpcv2_lang';
    const QV_PATH = 'hmpcv2_path';

    /**
     * Check if main query is available (get_query_var() safe).
     * WP can call home_url() before WP_Query is initialized (e.g. during plugins_loaded).
     */
    private static function is_query_ready() {
        return isset($GLOBALS['wp_query']) && is_object($GLOBALS['wp_query']);
    }

    /**
     * Early-boot language detection without WP_Query.
     * Uses cookie first, then URL prefix (/en/, /de/, etc.), then default.
     */
    private static function detect_lang_early() {
        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        if (!is_array($enabled) || empty($enabled)) {
            return $default;
        }

        // 1) URL prefix MUST win.
        $uri_lang = self::detect_lang_from_request_uri();
        if ($uri_lang !== '' && in_array($uri_lang, $enabled, true)) {
            return $uri_lang;
        }

        // If default language is NOT prefixed, any non-prefixed URL should be treated as default.
        // This prevents a stale cookie (e.g. 'en') from blocking a switch back to TR.
        // (When prefix_default_lang=true, default may be prefixed and this rule should not apply.)
        if (!HMPCv2_Options::get('prefix_default_lang', false)) {
            return $default;
        }

        // 2) Cookie fallback (only when default language is prefixed)
        if (!empty($_COOKIE['hmpcv2_lang'])) {
            $cookie_lang = HMPCv2_Langs::sanitize_lang_code((string) $_COOKIE['hmpcv2_lang'], '');
            if ($cookie_lang !== '' && in_array($cookie_lang, $enabled, true)) {
                return $cookie_lang;
            }
        }

        return $default;
    }

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

    /**
     * WooCommerce endpoints (especially My Account) rely on specific WP query vars.
     * Our language-prefixed catch-all rewrite turns URLs like:
     *   /en/my-account/downloads/
     * into:
     *   index.php?hmpcv2_lang=en&hmpcv2_path=my-account/downloads
     * which bypasses Woo's native endpoint parsing. Result: the URL changes but
     * Woo renders the dashboard because the endpoint query var isn't set.
     *
     * This mapper reconstructs the expected query vars for Woo core pages under
     * a language prefix, starting with My Account.
     */
    private static function maybe_map_woo_core_endpoints(string $lang, string $path) {
        if (!class_exists('WooCommerce')) return null;
        if (!function_exists('wc_get_page_id')) return null;

        $path = trim($path, '/');
        if ($path === '') return null;

        $segments = explode('/', $path);
        $first = (string) ($segments[0] ?? '');
        if ($first === '') return null;

        // --- My Account ---
        $my_id = (int) wc_get_page_id('myaccount');
        if ($my_id > 0) {
            $my_slug = (string) get_post_field('post_name', $my_id);
            if ($my_slug !== '' && $first === $my_slug) {
                $qv = array(
                    'page_id'   => $my_id,
                    'pagename'  => $my_slug,
                    'post_type' => 'page',
                    self::QV_LANG => $lang,
                    self::QV_PATH => $path,
                );

                // Endpoint segment (e.g. downloads, orders, edit-address, view-order/123)
                $endpoint_seg = (string) ($segments[1] ?? '');
                if ($endpoint_seg !== '') {
                    $endpoint_seg = urldecode($endpoint_seg);

                    $wc_qv = array();
                    if (function_exists('WC') && WC() && isset(WC()->query) && method_exists(WC()->query, 'get_query_vars')) {
                        $wc_qv = (array) WC()->query->get_query_vars();
                    }

                    // Match against both keys and values to be future-proof.
                    $matched_qv = '';
                    if (isset($wc_qv[$endpoint_seg])) {
                        $matched_qv = (string) $wc_qv[$endpoint_seg];
                    } else {
                        foreach ($wc_qv as $ep => $qv_name) {
                            if ((string) $qv_name === $endpoint_seg) {
                                $matched_qv = (string) $qv_name;
                                break;
                            }
                        }
                    }

                    if ($matched_qv !== '') {
                        // Some endpoints have a value segment (e.g. view-order/123, edit-address/billing)
                        $value = (string) ($segments[2] ?? '');
                        $qv[$matched_qv] = ($value !== '') ? urldecode($value) : '';
                    }
                }

                return $qv;
            }
        }

        return null;
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
        add_action('pre_get_posts', array(__CLASS__, 'force_product_single_for_prefixed_urun'), -1);
        add_action('pre_get_posts', array(__CLASS__, 'force_front_page_for_lang_root'), 0);
        add_action('parse_query', array(__CLASS__, 'force_non_404_for_prefixed_product_query'), 0);
        add_action('wp', array(__CLASS__, 'ensure_non_404_for_resolved_objects'), 0);
        add_filter('body_class', array(__CLASS__, 'body_class_force_product'), 20);
        add_filter('template_include', array(__CLASS__, 'force_woocommerce_single_product_template'), 999);
        add_filter('template_include', array(__CLASS__, 'force_single_product_template_for_prefixed_product'), 99);

        // Sync the internal current language with the URL prefix as early as possible.
        // This prevents cases where the switcher detects "/en/" via REQUEST_URI, but
        // internal state still stays on default language (which breaks menu labels/URLs).
        add_action('init', array(__CLASS__, 'sync_current_language_from_request'), 0);

        /**
         * Link generation: keep current language prefix on permalinks.
         * Fixes Woo product navigation falling back to TR while browsing /en or /de.
         */
        add_filter('post_type_link', array(__CLASS__, 'filter_post_type_link_to_current_lang'), 20, 2);
        add_filter('post_link', array(__CLASS__, 'filter_post_link_to_current_lang'), 20, 3);
        add_filter('page_link', array(__CLASS__, 'filter_page_link_to_post_lang'), 20, 2);
        add_filter('term_link', array(__CLASS__, 'filter_term_link_to_current_lang'), 20, 3);
        /**
         * Woo URLs: keep checkout/cart in the current language (fixes Proceed to Checkout).
         */
        add_filter('woocommerce_get_checkout_url', array(__CLASS__, 'filter_woocommerce_url_to_current_lang'), 20, 1);
        add_filter('woocommerce_get_cart_url', array(__CLASS__, 'filter_woocommerce_url_to_current_lang'), 20, 1);
        // Woo special pages (myaccount) should stay in current language even if the page itself is TR
        add_filter('woocommerce_get_page_permalink', array(__CLASS__, 'filter_wc_page_permalink'), 20, 2);

        // IMPORTANT: Router behavior must be FRONTEND-only
        if (is_admin()) {
            return;
        }

        // Keep logo / home URLs inside current language prefix
        add_filter('get_custom_logo', array(__CLASS__, 'filter_custom_logo_href'), 20);
        add_filter('home_url', array(__CLASS__, 'filter_home_url_for_lang'), 20, 4);
        add_filter('woocommerce_get_endpoint_url', array(__CLASS__, 'filter_wc_endpoint_url'), 20, 4);

        // SAFETY NET: if /<lang>/urun/<slug> falls into 404, rescue it.
        add_action('template_redirect', array(__CLASS__, 'rescue_404_prefixed_product'), 0);
        add_action('template_redirect', array(__CLASS__, 'disable_canonical_on_prefixed_product'), 0);
        add_action('template_redirect', array(__CLASS__, 'redirect_bare_myaccount_to_lang'), 2);

        // Optional: redirect first-time visitors to a language based on country (WooCommerce geolocation).
        add_action('init', array(__CLASS__, 'maybe_geo_redirect'), 1);
        // (Runs before maybe_set_lang_cookie so default cookie doesn't block geo redirect.)

        add_action('parse_request', array(__CLASS__, 'parse_request_lang'), 1);
        add_action('template_redirect', array(__CLASS__, 'canonical_redirect_default_prefix'), 1);

        // Remember language in cookie (optional) - frontend only
        add_action('init', array(__CLASS__, 'maybe_set_lang_cookie'), 20);
    }

    /**
     * Ensure HMPCv2_Langs::$current_language follows the URL prefix.
     *
     * Why: some routing edge-cases (or stale rewrite rules) can result in
     * hmpcv2_lang query var not being present even when the visitor is on
     * a prefixed URL like "/en/". The switcher detects the prefix via
     * REQUEST_URI, but menu translation relies on HMPCv2_Langs::get_current_language().
     *
     * This keeps menus (labels + URLs) consistently prefixed.
     */
    public static function sync_current_language_from_request() {
        if (is_admin() || wp_doing_ajax()) return;
        $lang = self::current_lang();
        $lang = HMPCv2_Langs::sanitize_lang_code($lang, HMPCv2_Langs::default_lang());
        HMPCv2_Langs::set_current_language($lang);
    }

    /**
     * Auto-switch language by visitor country.
     *
     * Runs only for first-time visitors:
     * - no language prefix in the URL
     * - no existing hmpcv2_lang cookie
     * - feature enabled in settings
     *
     * WooCommerce must be available (WC_Geolocation).
     */
    public static function maybe_geo_redirect() {
        if (is_admin() || wp_doing_ajax()) return;
        if (!HMPCv2_Options::get('geo_autoswitch', true)) return;

        // Don't interfere with REST, cron, CLI, etc.
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON)) return;
        if (defined('WP_CLI') && WP_CLI) return;

        // If the request explicitly asks to switch language, do NOT apply geo autoswitch.
        // (This runs very early on init; cookie may not be set yet.)
        if (isset($_GET[self::QUERY_VAR_LANG])) return;

        // Already selected language (cookie) -> no redirect.
        if (!empty($_COOKIE['hmpcv2_lang'])) return;

        // One-shot guard to prevent repeated redirects when cookies are restricted.
        if (!empty($_COOKIE['hmpcv2_geo_done'])) return;

        // If URL already has a language prefix, skip.
        $uri_lang = self::detect_lang_from_request_uri();
        if (is_string($uri_lang) && $uri_lang !== '') return;
        // Determine visitor country.
        // 1) Prefer WooCommerce geolocation (visitor IP) - this matches your old working behavior.
        // 2) If WC isn't available or returns empty, fallback to Cloudflare's HTTP_CF_IPCOUNTRY.
        $country = '';
        if (class_exists('WC_Geolocation')) {
            $geo = WC_Geolocation::geolocate_ip('', true, true);
            if (is_array($geo) && !empty($geo['country'])) {
                $country = strtoupper((string) $geo['country']);
            }
        }

        if ($country === '' && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $country = strtoupper(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY']));
        }
        if ($country === '' || $country === 'EU') return;

        $enabled = HMPCv2_Langs::enabled_langs();
        if (!is_array($enabled) || empty($enabled)) return;

        // Map country -> language code. Filterable for custom setups.
        $map = apply_filters('hmpcv2_geo_country_map', array(
            'RO' => 'ro',
            'DE' => 'de',
            'FR' => 'fr',
            'ES' => 'es',
            'IT' => 'it',
            'PT' => 'pt',
            'NL' => 'nl',
            'PL' => 'pl',
            'CZ' => 'cs',
            'SK' => 'sk',
            'HU' => 'hu',
            'BG' => 'bg',
            'GR' => 'el',
            'SE' => 'sv',
            'NO' => 'no',
            'DK' => 'da',
            'FI' => 'fi',
            'IS' => 'is',
            'EE' => 'et',
            'LV' => 'lv',
            'LT' => 'lt',
            'SI' => 'sl',
            'HR' => 'hr',
            'RS' => 'sr',
            'BA' => 'bs',
            'MK' => 'mk',
            'AL' => 'sq',
            'UA' => 'uk',
            'RU' => 'ru',
            'BY' => 'be',
            'IE' => 'ga',
            'TR' => 'tr',
            'GE' => 'ka',
            'AM' => 'hy',
            'AZ' => 'az',
            'IR' => 'fa',
            'CN' => 'zh',
            'SA' => 'ar',
            'AE' => 'ar',
            'EG' => 'ar',
            'MA' => 'ar',
        ));

        $target = isset($map[$country]) ? (string) $map[$country] : '';
        $target = HMPCv2_Langs::sanitize_lang_code($target, '');

        // If no direct mapping exists, don't guess.
        if ($target === '') return;

        // Redirect only if the target language is enabled and differs from default.
        if (!in_array($target, $enabled, true)) return;

        // Build current URL and apply target prefix.
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $req_uri = wp_unslash($req_uri);
        $current_url = home_url($req_uri);
        $dest = self::apply_lang_to_url($current_url, $target);

        if (!is_string($dest) || $dest === '' || $dest === $current_url) return;

        // Mark geo redirect as done (1 day) to avoid repeated redirects if cookies are blocked.
        $cookie_days = 1;
        $secure = is_ssl();
        $expires = time() + (DAY_IN_SECONDS * $cookie_days);
        setcookie('hmpcv2_geo_done', '1', $expires, COOKIEPATH, COOKIE_DOMAIN, $secure, true);

        wp_redirect($dest, 302);
        exit;
    }

    /**
     * Only touch frontend links, and only for internal URLs.
     */
    private static function is_internal_url($url) {
        if (!is_string($url) || $url === '') return false;
        $home = wp_parse_url(home_url('/'));
        $u    = wp_parse_url($url);
        if (empty($u) || empty($home)) return false;

        // Relative URLs are internal by definition.
        if (empty($u['host'])) return true;

        $home_host = isset($home['host']) ? strtolower($home['host']) : '';
        $url_host  = isset($u['host']) ? strtolower($u['host']) : '';
        return ($home_host !== '' && $url_host === $home_host);
    }

    /**
     * Remove an existing language prefix from a path (/en/..., /de/..., etc.)
     */
    private static function strip_lang_prefix_from_path($path) {
        $path = is_string($path) ? $path : '/';
        $path = '/' . ltrim($path, '/');

        $trim = trim($path, '/');
        if ($trim === '') return '/';

        $segments = explode('/', $trim);
        $enabled  = HMPCv2_Langs::enabled_langs();
        $maybe    = strtolower((string) $segments[0]);

        if (HMPCv2_Langs::is_allowed($maybe) && in_array($maybe, $enabled, true)) {
            array_shift($segments);
        }

        $base = '/' . implode('/', $segments);
        $base = preg_replace('#/+#', '/', $base);
        return $base === '' ? '/' : $base;
    }

    /**
     * Apply the current language prefix to an internal URL.
     */
    public static function apply_current_lang_to_url($url) {
        if (is_admin()) return $url;
        if (!self::is_internal_url($url)) return $url;

        $current = HMPCv2_Langs::get_current_language();

        // AJAX context (Woo mini-cart fragments)
        if (wp_doing_ajax()) {
            if (empty($current) && !empty($_COOKIE['hmpcv2_lang'])) {
                $current = sanitize_key($_COOKIE['hmpcv2_lang']);
            }

            if (empty($current) && !empty($_SERVER['HTTP_REFERER'])) {
                $ref_path = wp_parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
                if ($ref_path) {
                    $segments = array_values(array_filter(explode('/', $ref_path)));
                    if (!empty($segments[0])) {
                        $current = sanitize_key($segments[0]);
                    }
                }
            }

            if (empty($current)) return $url;
        }

        return self::apply_lang_to_url($url, $current);
    }

    /**
     * Apply an explicit language prefix to an internal URL.
     */
    public static function apply_lang_to_url($url, $lang) {
        if (!self::is_internal_url($url)) return $url;

        $default = HMPCv2_Langs::default_lang();
        $enabled = HMPCv2_Langs::enabled_langs();

        $lang = HMPCv2_Langs::sanitize_lang_code($lang, $default);
        if (!in_array($lang, $enabled, true)) {
            return $url;
        }

        $parts = wp_parse_url($url);
        if (empty($parts)) return $url;

        $path     = isset($parts['path']) ? (string) $parts['path'] : '/';
        $query    = isset($parts['query']) ? ('?' . $parts['query']) : '';
        $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';

        // Strip any existing lang prefix first.
        $base_path = self::strip_lang_prefix_from_path($path);
        $is_root   = ($base_path === '/' || $base_path === '');

        $prefix_default = self::prefix_default_lang();

        // Build new path.
        if ($lang === $default && !$prefix_default) {
            $new_path = $is_root ? '/' : $base_path;
        } else {
            $new_path = $is_root
                ? '/' . $lang . '/'
                : '/' . $lang . '/' . ltrim($base_path, '/');
        }

        $new_path = preg_replace('#/+#', '/', $new_path);

        // Respect original trailing slash behavior as much as possible.
        // If original path ended with '/', keep it. Otherwise keep as-is.
        if (substr($path, -1) === '/' && substr($new_path, -1) !== '/') {
            $new_path .= '/';
        }

        // Rebuild absolute internal URL.
        $final = home_url($new_path) . $query . $fragment;
        return $final;
    }

    /**
     * Filter: post type permalinks (Woo products primarily).
     */
    public static function filter_post_type_link_to_current_lang($post_link, $post) {
        if (empty($post_link) || !is_object($post)) return $post_link;

        // Primary target: Woo products. (Safe to extend later.)
        if (isset($post->post_type) && $post->post_type === 'product') {
            return self::apply_current_lang_to_url($post_link);
        }

        return $post_link;
    }

    /**
     * Filter: standard post links (optional safety; won’t touch non-internal URLs).
     */
    public static function filter_post_link_to_current_lang($permalink, $post, $leavename) {
        if (empty($permalink)) return $permalink;
        // Keep behavior focused; if you want all posts/pages prefixed, expand here.
        return $permalink;
    }

    /**
     * Filter: page links should use the page's own language prefix.
     */
    public static function filter_page_link_to_post_lang($link, $post_id) {
        if (empty($link)) return $link;

        // Woo "My Account" link must follow CURRENT language (fixes header account icon jumping to TR)
        if (!is_admin() && class_exists('WooCommerce') && function_exists('wc_get_page_id')) {
            $my_id = (int) wc_get_page_id('myaccount');
            if ($my_id > 0 && (int) $post_id === $my_id) {
                $cur = self::current_lang();
                if (is_string($cur) && $cur !== '') {
                    return self::apply_lang_to_url($link, $cur);
                }
                return $link;
            }
        }

        if (!class_exists('HMPCv2_Translations')) {
            return $link;
        }

        $lang = HMPCv2_Translations::get_lang((int) $post_id);
        if (!is_string($lang) || $lang === '') {
            return $link;
        }

        return self::apply_lang_to_url($link, $lang);
    }

    /**
     * Woo permalink generator for core pages (wc_get_page_permalink()).
     * Ensure My Account stays in current language prefix.
     *
     * @param string $link
     * @param string $page
     * @return string
     */
    public static function filter_wc_page_permalink($link, $page) {
        if (empty($link) || is_admin()) return $link;
        if (!class_exists('WooCommerce')) return $link;

        $page = (string) $page;
        if ($page !== 'myaccount') {
            return $link;
        }

        $cur = self::current_lang();
        if (!is_string($cur) || $cur === '') return $link;

        return self::apply_lang_to_url($link, $cur);
    }

    /**
     * Filter: taxonomy term links (product categories/tags, etc.)
     */
    public static function filter_term_link_to_current_lang($termlink, $term, $taxonomy) {
        if (empty($termlink)) return $termlink;

        // Product taxonomies are the key ones for Woo navigation.
        if ($taxonomy === 'product_cat' || $taxonomy === 'product_tag') {
            return self::apply_current_lang_to_url($termlink);
        }

        return $termlink;
    }

    /**
     * Filter Woo-generated URLs (checkout/cart) to keep current language prefix.
     */
    public static function filter_woocommerce_url_to_current_lang($url) {
        if (empty($url) || !is_string($url)) return $url;
        return self::apply_current_lang_to_url($url);
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
            // If rewrite rules are stale/missing, WP may not populate hmpcv2_lang,
            // even though the request URI contains a valid prefix (e.g. /en/...).
            // In that case, keep language consistent with the URL.
            $uri_lang = self::detect_lang_from_request_uri();
            $enabled  = HMPCv2_Langs::enabled_langs();
            if ($uri_lang !== '' && in_array($uri_lang, $enabled, true)) {
                HMPCv2_Langs::set_current_language($uri_lang);
            } else {
                HMPCv2_Langs::set_current_language(HMPCv2_Langs::get_default());
            }
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

        // If the URL has NO prefix and default language is NOT prefixed,
        // we must treat the request as default language regardless of cookie.
        // This is the key to allowing users to return to TR.
        if ($uri_lang === '' && !HMPCv2_Options::get('prefix_default_lang', false)) {
            return $default;
        }

        if (!self::is_query_ready()) {
            return self::detect_lang_early();
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

        // PRODUCT ROUTE FIX (DETERMINISTIC):
        // /<lang>/urun/<slug> must resolve to product even if url_to_postid fails.
        if (self::is_product_path($path)) {
            $slug = trim(substr($path, strlen('urun/')), '/');
            if ($slug !== '') {
                $slug = urldecode($slug);

                // Try resolve by slug first (most stable)
                $product = get_page_by_path($slug, OBJECT, 'product');
                if ($product && !empty($product->ID)) {
                    $post_id = (int) $product->ID;

                    // If translation exists, map to translated product ID
                    if (class_exists('HMPCv2_Resolver')) {
                        $translated_id = (int) HMPCv2_Resolver::resolve_translation_post_id($post_id, $lang);
                        if ($translated_id > 0) $post_id = $translated_id;
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

        // Woo core endpoints (My Account tabs, etc.) must be mapped BEFORE url_to_postid().
        $woo_qv = self::maybe_map_woo_core_endpoints($lang, $path);
        if (is_array($woo_qv)) {
            return $woo_qv;
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

        $p  = (int) ($wp_query->get('p'));
        $pt = $wp_query->get('post_type');

        if ($p > 0 && ($pt === 'product' || (is_array($pt) && in_array('product', $pt, true)))) {
            $wp_query->is_404 = false;
            $wp_query->queried_object_id = $p;
            if (!headers_sent()) {
                status_header(200);
                if (function_exists('nocache_headers')) nocache_headers();
            }
            return;
        }

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

    public static function force_non_404_for_prefixed_product_query($q) {
        if (is_admin() || !$q instanceof WP_Query) return;
        if (!$q->is_main_query()) return;

        // Only for: /{lang}/urun/...
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = $uri ? (string) parse_url($uri, PHP_URL_PATH) : '';
        if (!$path) return;

        if (!preg_match('#^/([a-z]{2})/urun/#i', $path)) return;

        // If main query is already targeting a product via p + post_type, force flags NOW
        $p  = (int) $q->get('p');
        $pt = $q->get('post_type');

        if ($p > 0 && ($pt === 'product' || (is_array($pt) && in_array('product', $pt, true)))) {
            $q->is_404 = false;
            $q->is_singular = true;
            $q->is_single = true;
            $q->is_page = false;
            $q->is_archive = false;

            // Some themes rely on queried_object_id
            $q->queried_object_id = $p;

            // Also ensure proper status header early
            if (!headers_sent()) {
                status_header(200);
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

    public static function force_product_single_for_prefixed_urun($q) {
        if (is_admin() || !$q->is_main_query()) return;

        // Raw URI check (query_vars'a güvenmiyoruz)
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = $uri ? (string) parse_url($uri, PHP_URL_PATH) : '';
        if (!$path) return;

        // enabled langs
        $enabled = HMPCv2_Langs::enabled_langs();
        if (!is_array($enabled) || empty($enabled)) return;

        // Match: /en/urun/slug  (slug tek segment varsayımı; senin ürünlerde böyle)
        if (!preg_match('#^/([a-z]{2})/urun/([^/]+)/?$#i', $path, $m)) return;

        $lang = strtolower($m[1]);
        if (!in_array($lang, $enabled, true)) return;

        $slug = urldecode($m[2]);
        $slug = trim($slug);
        if ($slug === '') return;

        // Resolve product by slug from DB (most stable)
        $product = get_page_by_path($slug, OBJECT, 'product');
        if (!$product || empty($product->ID)) return;

        $post_id = (int) $product->ID;

        // If translation exists for requested language, map to translated product ID
        if (class_exists('HMPCv2_Resolver')) {
            $translated_id = (int) HMPCv2_Resolver::resolve_translation_post_id($post_id, $lang);
            if ($translated_id > 0) $post_id = $translated_id;
        }

        // Force main query
        $q->set('post_type', 'product');
        $q->set('p', $post_id);

        // Keep plugin context vars for switcher/resolver
        $q->set(self::QV_LANG, $lang);
        $q->set(self::QV_PATH, 'urun/' . $slug);

        // Prevent WP from treating it as page
        $q->set('page_id', '');
        $q->set('pagename', '');
        $q->set('name', '');

        // Flags: bazı temalar bunlara bakıyor
        $q->is_home = false;
        $q->is_front_page = false;
        $q->is_page = false;
        $q->is_single = true;
        $q->is_singular = true;
        // Force query flags so themes/Woo loaders treat it as a singular product.
        $q->is_404 = false;
        $q->is_archive = false;
        $q->is_post_type_archive = false;
        $q->is_attachment = false;
        $q->is_search = false;
        $q->is_feed = false;

        $q->queried_object_id = $post_id;
    }

    public static function prefix_default_lang() {
        return (bool) HMPCv2_Options::get('prefix_default_lang', false);
    }

    private static function build_lang_home_url(string $lang): string {
        $default = HMPCv2_Langs::default_lang();
        $prefix_default = self::prefix_default_lang();

        $lang = strtolower($lang);
        if ($lang === $default && !$prefix_default) {
            return home_url('/');
        }

        return home_url('/' . $lang . '/');
    }

    /**
     * Fix theme logo link (Custom Logo) so it doesn't drop /en/ prefix.
     */
    public static function filter_custom_logo_href($html) {
        if (is_admin() || !is_string($html) || $html === '') return $html;

        $lang = self::current_lang();
        $target = self::build_lang_home_url($lang);

        // Replace the first anchor href in the logo HTML
        $pattern = '#<a\s+[^>]*href=(["\'])(.*?)\1#i';
        $html = preg_replace_callback($pattern, function ($m) use ($target) {
            $quote = $m[1];
            return str_replace($m[0], preg_replace('#href=(["\'])(.*?)\1#i', 'href=' . $quote . esc_url($target) . $quote, $m[0]), $m[0]);
        }, $html, 1);

        // Fallback if callback didn't match (very rare)
        if (strpos($html, 'href=') !== false) {
            $html = preg_replace('#href=(["\'])(.*?)\1#i', 'href=$1' . esc_url($target) . '$1', $html, 1);
        }

        return $html;
    }

    /**
     * Conservative home_url() filter:
     * - Only affects calls requesting root ("/" or "")
     * - Keeps site inside current language prefix
     */
    public static function filter_home_url_for_lang($url, $path, $orig_scheme, $blog_id) {
        static $in_filter = false;
        if ($in_filter || is_admin() || wp_doing_ajax()) return $url;

        $path = (string) $path;
        $path_norm = trim($path);

        // Only rewrite "home root" URLs
        if ($path_norm !== '' && $path_norm !== '/') {
            return $url;
        }

        $in_filter = true;
        // If default language is unprefixed, never let cookies or geo state force a prefixed home URL for bare root requests.
        // This protects the homepage (/) from becoming sticky like /de due to persisted cookies or cached markup.
        $uri_lang = self::detect_lang_from_request_uri();
        if ($uri_lang === '' && !self::prefix_default_lang()) {
            $lang = HMPCv2_Langs::default_lang();
        } else {
            if (!self::is_query_ready()) {
                $lang = self::detect_lang_early();
            } else {
                $lang = self::current_lang();
            }
        }
        $target = self::build_lang_home_url($lang);
        $in_filter = false;

        // Preserve scheme if WP already provided a specific scheme
        if (is_string($orig_scheme) && $orig_scheme !== '') {
            $target = set_url_scheme($target, $orig_scheme);
        }

        return $target;
    }

    /**
     * Woo My Account "Dashboard" link (empty endpoint) uses wc_get_page_permalink('myaccount')
     * which is not language-prefixed in our setup. This forces it to stay inside /{lang}/.
     *
     * @param string $url
     * @param string $endpoint
     * @param mixed  $value
     * @param string $permalink
     * @return string
     */
    public static function filter_wc_endpoint_url($url, $endpoint, $value, $permalink) {
        if (is_admin() || !class_exists('WooCommerce') || !function_exists('wc_get_page_id')) {
            return $url;
        }

        // Only adjust dashboard (empty endpoint). Other endpoints already work.
        $endpoint = (string) $endpoint;
        if ($endpoint !== '') {
            return $url;
        }

        $lang = self::current_lang();
        $enabled = HMPCv2_Langs::enabled_langs();
        if (!in_array($lang, $enabled, true)) {
            return $url;
        }

        $my_id = (int) wc_get_page_id('myaccount');
        if ($my_id <= 0) {
            return $url;
        }

        $my_slug = (string) get_post_field('post_name', $my_id);
        if ($my_slug === '') {
            return $url;
        }

        // Build the language-prefixed dashboard URL.
        $default = HMPCv2_Langs::default_lang();
        $prefix_default = self::prefix_default_lang();

        $target = '';
        if ($lang === $default && !$prefix_default) {
            $target = home_url('/' . $my_slug . '/');
        } else {
            $target = home_url('/' . $lang . '/' . $my_slug . '/');
        }

        // Preserve query args (rare but safe)
        $q = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($q) && $q !== '') {
            parse_str($q, $args);
            if (is_array($args) && !empty($args)) {
                $target = add_query_arg($args, $target);
            }
        }

        return $target;
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

        if (headers_sent()) {
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

    /**
     * Safety-net for themes that hardcode "/my-account/" without language prefix.
     * If user is browsing /{lang}/... and clicks account icon, redirect bare my-account
     * to /{lang}/my-account/.
     */
    public static function redirect_bare_myaccount_to_lang() {
        if (is_admin() || wp_doing_ajax()) return;
        if (!function_exists('is_account_page') || !is_account_page()) return;

        if (isset($_GET['hmpc_noredirect']) && (string) $_GET['hmpc_noredirect'] === '1') {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = $uri ? (string) parse_url($uri, PHP_URL_PATH) : '';
        if ($path === '') return;

        // If already language-prefixed, do nothing.
        $trim = trim($path, '/');
        $first = $trim === '' ? '' : strtolower(strtok($trim, '/'));
        $enabled = HMPCv2_Langs::enabled_langs();
        if ($first && in_array($first, $enabled, true)) {
            return;
        }

        // Determine intended language from referer (best signal for hardcoded header links)
        $lang = '';
        $ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $ref_path = $ref ? (string) parse_url($ref, PHP_URL_PATH) : '';
        $ref_trim = $ref_path ? trim($ref_path, '/') : '';
        $ref_first = $ref_trim === '' ? '' : strtolower(strtok($ref_trim, '/'));

        if ($ref_first && in_array($ref_first, $enabled, true)) {
            $lang = $ref_first;
        } elseif (!empty($_COOKIE['hmpcv2_lang'])) {
            $cookie_lang = HMPCv2_Langs::sanitize_lang_code((string) $_COOKIE['hmpcv2_lang'], '');
            if ($cookie_lang !== '' && in_array($cookie_lang, $enabled, true)) {
                $lang = $cookie_lang;
            }
        } else {
            $lang = HMPCv2_Langs::sanitize_lang_code(self::current_lang(), '');
            if ($lang === '' || !in_array($lang, $enabled, true)) {
                $lang = '';
            }
        }

        if ($lang === '') {
            return;
        }

        $default = HMPCv2_Langs::default_lang();
        if ($lang === $default && !self::prefix_default_lang()) {
            return;
        }

        // Build target URL: insert /{lang}/ after site base (supports subdirectory installs)
        $target = self::apply_lang_to_url(home_url($path), $lang);
        if (!$target) return;

        $target = add_query_arg('hmpc_noredirect', '1', $target);

        wp_safe_redirect($target, 302);
        exit;
    }

    public static function disable_canonical_on_prefixed_product() {
        if (is_admin() || wp_doing_ajax()) return;

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = $uri ? (string) parse_url($uri, PHP_URL_PATH) : '';
        if (!$path) return;

        if (preg_match('#^/([a-z]{2})/urun/#i', $path)) {
            remove_action('template_redirect', 'redirect_canonical');
        }
    }

    public static function parse_request_lang_prefixed($wp) {
        if (is_admin()) return;
        if (empty($wp) || !isset($wp->request)) return;

        $lang = isset($wp->query_vars[self::QV_LANG]) ? (string) $wp->query_vars[self::QV_LANG] : '';
        $path = isset($wp->query_vars[self::QV_PATH]) ? (string) $wp->query_vars[self::QV_PATH] : '';

        // If this is a prefixed product route, do NOT do extra parsing here.
        // The request filter (map_lang_prefixed_request) is the single source of truth.
        if (!empty($lang) && self::is_product_path($path)) {
            return;
        }

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

    public static function body_class_force_product($classes) {
        if (is_admin() || wp_doing_ajax()) return $classes;

        if (empty($_SERVER['REQUEST_URI'])) return $classes;
        $path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = '/' . ltrim($path, '/');

        if (!preg_match('#^/([a-z]{2})/urun/#i', $path)) return $classes;

        $qid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        if ($qid > 0 && get_post_type($qid) === 'product') {
            // Ensure key Woo/Astra selectors exist
            $classes[] = 'woocommerce';
            $classes[] = 'woocommerce-page';
            $classes[] = 'single-product';
            $classes[] = 'single';
        }

        return array_values(array_unique($classes));
    }

    public static function force_woocommerce_single_product_template($template) {
        if (is_admin() || wp_doing_ajax()) return $template;

        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = $uri ? (string) parse_url($uri, PHP_URL_PATH) : '';
        if (!$path) return $template;

        // Only for /{lang}/urun/...
        if (!preg_match('#^/([a-z]{2})/urun/#i', $path)) {
            return $template;
        }

        $qid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        if ($qid <= 0 || get_post_type($qid) !== 'product') {
            return $template;
        }

        // Make sure Woo context is set
        if (function_exists('wc_setup_product_data')) {
            wc_setup_product_data($qid);
        }

        // 1) Theme override
        $theme_tpl = locate_template(array('woocommerce/single-product.php'));
        if (!empty($theme_tpl)) return $theme_tpl;

        // 2) Woo plugin template
        if (defined('WC_PLUGIN_FILE')) {
            $wc_tpl = trailingslashit(plugin_dir_path(WC_PLUGIN_FILE)) . 'templates/single-product.php';
            if (file_exists($wc_tpl)) return $wc_tpl;
        }

        return $template;
    }

    public static function force_single_product_template_for_prefixed_product($template) {
        if (is_admin() || wp_doing_ajax()) return $template;

        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = $uri ? (string) parse_url($uri, PHP_URL_PATH) : '';
        if ($path === '') return $template;

        // Only for: /{lang}/urun/...
        if (!preg_match('#^/([a-z]{2})/urun/#i', $path)) {
            return $template;
        }

        $qid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        if ($qid < 1 || get_post_type($qid) !== 'product') {
            return $template;
        }

        // 1) Prefer theme overrides
        $theme_tpl = locate_template(array('woocommerce/single-product.php'));
        if (!empty($theme_tpl)) return $theme_tpl;

        // 2) Fallback to WooCommerce plugin template
        if (defined('WC_PLUGIN_FILE')) {
            $wc_tpl = trailingslashit(plugin_dir_path(WC_PLUGIN_FILE)) . 'templates/single-product.php';
            if (file_exists($wc_tpl)) return $wc_tpl;
        }

        return $template;
    }
}