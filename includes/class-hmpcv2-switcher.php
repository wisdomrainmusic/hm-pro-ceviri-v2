<?php

if (!defined('ABSPATH')) exit;

final class HMPCv2_Switcher {

    public static function init() {
        add_shortcode('hmpc_lang_switcher', array(__CLASS__, 'shortcode'));
        add_shortcode('hmpcv2_switcher', array(__CLASS__, 'shortcode')); // Backward compatibility
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'));
    }

    public static function styles() {
        // Minimal CSS (optional). Keep tiny for commit1.
        $css = '.hmpc-switcher{display:flex;gap:8px;flex-wrap:wrap}.hmpc-switcher a{padding:6px 10px;border:1px solid rgba(0,0,0,.15);border-radius:6px;text-decoration:none}.hmpc-switcher a.is-active{font-weight:600}';
        wp_register_style('hmpc-switcher-inline', false, array(), HMPCV2_VERSION);
        wp_enqueue_style('hmpc-switcher-inline');
        wp_add_inline_style('hmpc-switcher-inline', $css);
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_codes' => '1',
        ), $atts, 'hmpc_lang_switcher');

        $languages = HMPCv2_Langs::get_languages();
        if (empty($languages)) return '';

        $current = HMPCv2_Langs::get_current_language();

        // Determine current path without the language prefix to reuse for all targets.
        $path = HMPCv2_Router::get_current_path();
        $path = is_string($path) ? $path : '';

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $query = parse_url($request_uri, PHP_URL_QUERY);
        $query_suffix = $query ? ('?' . $query) : '';

        $html = '<div class="hmpc-switcher" role="navigation" aria-label="Language switcher">';

        foreach ($languages as $lang) {
            $code = $lang['code'];
            $is_active = ($code === $current);
            $label = ($atts['show_codes'] === '1') ? strtoupper($code) : $lang['label'];

            $url = HMPCv2_Router::build_url_for_lang($code, $path) . $query_suffix;

            $html .= sprintf(
                '<a class="%s" href="%s">%s</a>',
                $is_active ? 'is-active' : '',
                esc_url($url),
                esc_html($label)
            );
        }

        $html .= '</div>';

        return $html;
    }
}
