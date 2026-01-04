<?php
if (!defined('ABSPATH')) exit;

/**
 * Email verification notice translations.
 *
 * Goal: translate the output of [hm_email_verification_notice] without
 * touching the existing signup flow/snippets.
 *
 * Storage: option hmpcv2_misc_dict
 *   - $misc[<lang>]['email_verification'][<key>] = <translation>
 *
 * Keys:
 *   - email_verify_notice
 *   - email_verify_button
 */
final class HMPCv2_Email_Verification {

    const BUCKET = 'email_verification';

    public static function init() {
        // Capture email from query for later resend clicks (cooldown pages may not keep it).
        if (method_exists(__CLASS__, 'capture_email_cookie')) {
            add_action('init', array(__CLASS__, 'capture_email_cookie'), 1);
        }
// Override shortcode output using dictionary values.
        add_filter('do_shortcode_tag', array(__CLASS__, 'filter_shortcode_output'), 10, 4);
    }

    public static function filter_shortcode_output($output, $tag, $attr, $m) {
        if ($tag !== 'hm_email_verification_notice') {
            return $output;
        }

        // Render our translated block.
        $lang = class_exists('HMPCv2_Router') ? HMPCv2_Router::current_lang() : 'tr';
        $notice = self::t($lang, 'email_verify_notice', 'Email verification is required to activate your account. Click the link sent to your inbox.');
        $btn = self::t($lang, 'email_verify_button', 'Resend verification email');

        // Use the current URL and add hm_resend_verify=1.
        $url = self::current_url();
        $url = add_query_arg('hm_resend_verify', '1', $url);

        // Preserve email if present (or derive from logged-in user) so the resend handler can work.
        $email = '';
        if (isset($_GET['email'])) {
            $email = sanitize_email(wp_unslash($_GET['email']));
        } elseif (isset($_COOKIE['hmpc_ev_email'])) {
            $email = sanitize_email(wp_unslash($_COOKIE['hmpc_ev_email']));
        } elseif (function_exists('is_user_logged_in') && is_user_logged_in()) {
            $u = wp_get_current_user();
            if ($u && !empty($u->user_email)) {
                $email = sanitize_email($u->user_email);
            }
        }
        if (!empty($email)) {
            $url = add_query_arg('email', $email, $url);
        }

        // Minimal markup; theme can style it.
        $html  = '<div class="hmpcv2-email-verify-notice">';
        $html .= '<div class="hmpcv2-email-verify-text">' . esc_html($notice) . '</div>';
        $html .= '<div class="hmpcv2-email-verify-actions">';
        $html .= '<a class="button" href="' . esc_url($url) . '">' . esc_html($btn) . '</a>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    
    /**
     * Capture email query param into a short-lived cookie so the resend button
     * can still work on cooldown/redirect states where the email parameter is removed.
     */
    public static function capture_email_cookie() {
        if (is_admin()) { return; }
        if (!isset($_GET['email'])) { return; }

        $email = sanitize_email(wp_unslash($_GET['email']));
        if (empty($email)) { return; }

        // 15 minutes is enough for verification/cooldown flows.
        $ttl = 15 * 60;
        // Respect HTTPS, send cookie on both HTTP/HTTPS if possible.
        $secure = is_ssl();
        $httponly = true;

        // Use COOKIEPATH/COOKIE_DOMAIN when available.
        $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        setcookie('hmpc_ev_email', $email, time() + $ttl, $path, $domain, $secure, $httponly);
        // Also update the superglobal so this request can immediately use it.
        $_COOKIE['hmpc_ev_email'] = $email;
    }


private static function current_url() {
        // Try WP helpers first.
        if (function_exists('home_url')) {
            $scheme = is_ssl() ? 'https' : 'http';
            $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
            $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            if ($host !== '' && $uri !== '') {
                return esc_url_raw($scheme . '://' . $host . $uri);
            }
        }

        return home_url('/');
    }

    /**
     * Translate a key for a given lang with EN fallback.
     * Never fallback to Turkish when a non-TR language is active.
     */
    public static function t($lang, $key, $default_en) {
        $lang = is_string($lang) ? strtolower($lang) : 'tr';
        $misc = get_option('hmpcv2_misc_dict', array());
        $misc = is_array($misc) ? $misc : array();

        $val = '';
        if (isset($misc[$lang][self::BUCKET][$key])) {
            $val = (string) $misc[$lang][self::BUCKET][$key];
        }

        if ($val !== '') {
            return $val;
        }

        // For non-TR langs: fallback to EN bucket, then default EN.
        if ($lang !== 'tr') {
            if (isset($misc['en'][self::BUCKET][$key]) && (string)$misc['en'][self::BUCKET][$key] !== '') {
                return (string) $misc['en'][self::BUCKET][$key];
            }
            return (string) $default_en;
        }

        // For TR: allow TR string to be empty, but still fallback to EN/default.
        if (isset($misc['en'][self::BUCKET][$key]) && (string)$misc['en'][self::BUCKET][$key] !== '') {
            return (string) $misc['en'][self::BUCKET][$key];
        }

        return (string) $default_en;
    }
}
