<?php
/**
 * Plugin Name: HM Pro Çeviri v2 (Manual Multi-Language)
 * Description: Wix-style manual multi-language framework (URL-based language prefix + admin settings + language switcher). No auto-translate.
 * Version: 2.0.0
 * Author: HM
 */

if (!defined('ABSPATH')) exit;

define('HMPCV2_VERSION', '2.0.0');
define('HMPCV2_PATH', plugin_dir_path(__FILE__));
define('HMPCV2_URL', plugin_dir_url(__FILE__));
if (!defined('HMPC_DEBUG')) {
    define('HMPC_DEBUG', false);
}

require_once HMPCV2_PATH . 'includes/class-hmpcv2-options.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-langs.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-router.php';

require_once HMPCV2_PATH . 'includes/class-hmpcv2-translations.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-resolver.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-term-translations.php';
require_once HMPCV2_PATH . 'includes/core/class-hmpc-language.php';

require_once HMPCV2_PATH . 'includes/menu/class-hmpc-menu-translator.php';
require_once HMPCV2_PATH . 'includes/menu/class-hmpc-menu-admin-fields.php';

require_once HMPCV2_PATH . 'includes/class-hmpcv2-admin.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-admin-translations.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-switcher.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-woo.php';
require_once HMPCV2_PATH . 'includes/class-hmpcv2-email-verification.php';

final class HMPCv2_Plugin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'boot'), 5);
    }

    public function boot() {
        HMPCv2_Options::maybe_init_defaults();

        HMPCv2_Router::init();
        HMPCv2_Woo::init();
        HMPCv2_Switcher::init();
        HMPCv2_Term_Translations::init();
        HMPC_Menu_Translator::init();
        HMPCv2_Email_Verification::init();

        if (!is_admin()) {
            add_filter('redirect_canonical', function($redirect_url, $requested_url) {
                if (empty($requested_url)) return $redirect_url;

                $path = (string) parse_url($requested_url, PHP_URL_PATH);
                $path = '/' . ltrim($path, '/');
                $first = strtolower((string) strtok(trim($path, '/'), '/'));

                $enabled = HMPCv2_Langs::enabled_langs();
                if ($first && in_array($first, $enabled, true)) {
                    // keep prefixed URLs as-is
                    return false;
                }
                return $redirect_url;
            }, 10, 2);
        }

        // Admin
        if (is_admin()) {
            HMPCv2_Admin::init();
            HMPCv2_Admin_Translations::init();
            HMPCv2_Translations::init_admin();
            HMPC_Menu_Admin_Fields::init();
        }
    }

    public function activate() {
        HMPCv2_Options::maybe_init_defaults();
        update_option('hmpcv2_debug_enabled', 0, false);
        HMPCv2_Router::register_rewrite_rules();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }
}

HMPCv2_Plugin::instance();
