<?php

if (!defined('ABSPATH')) exit;

class HMPCv2_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function register_menu() {
        add_options_page(
            __('HM Pro Çeviri', 'hmpcv2'),
            __('HM Pro Çeviri', 'hmpcv2'),
            'manage_options',
            'hmpcv2-settings',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function register_settings() {
        register_setting('hmpcv2_options', HMPCv2_Options::OPT_KEY, array(__CLASS__, 'sanitize_options'));

        add_settings_section(
            'hmpcv2_main_section',
            __('Language Settings', 'hmpcv2'),
            '__return_false',
            'hmpcv2-settings'
        );

        add_settings_field(
            'hmpcv2_languages',
            __('Allowed languages', 'hmpcv2'),
            array(__CLASS__, 'render_languages_field'),
            'hmpcv2-settings',
            'hmpcv2_main_section'
        );

        add_settings_field(
            'hmpcv2_default_language',
            __('Default language', 'hmpcv2'),
            array(__CLASS__, 'render_default_language_field'),
            'hmpcv2-settings',
            'hmpcv2_main_section'
        );

        add_settings_field(
            'hmpcv2_prefix_default',
            __('Prefix default language in URLs', 'hmpcv2'),
            array(__CLASS__, 'render_prefix_default_field'),
            'hmpcv2-settings',
            'hmpcv2_main_section'
        );

        add_settings_field(
            'hmpcv2_cookie_remember',
            __('Remember visitor language with cookie', 'hmpcv2'),
            array(__CLASS__, 'render_cookie_field'),
            'hmpcv2-settings',
            'hmpcv2_main_section'
        );

        add_settings_field(
            'hmpcv2_geo_autoswitch',
            __('Auto-switch language by visitor country', 'hmpcv2'),
            array(__CLASS__, 'render_geo_autoswitch_field'),
            'hmpcv2-settings',
            'hmpcv2_main_section'
        );
    }

    public static function sanitize_options($input) {
        $defaults = HMPCv2_Options::defaults();
        $input = wp_parse_args(is_array($input) ? $input : array(), $defaults);

        $enabled = array();
        if (!empty($input['enabled_langs']) && is_array($input['enabled_langs'])) {
            foreach ($input['enabled_langs'] as $code) {
                $code = HMPCv2_Langs::sanitize_lang_code($code, '');
                if ($code && !in_array($code, $enabled, true)) {
                    $enabled[] = $code;
                }
            }
        }

        if (empty($enabled)) {
            $enabled = $defaults['enabled_langs'];
        }

        $enabled = HMPCv2_Options::sanitize_enabled_langs($enabled);

        $default_lang = HMPCv2_Langs::sanitize_lang_code($input['default_lang'], HMPCv2_Langs::default_lang());
        if (!HMPCv2_Options::is_language_allowed($default_lang, $enabled)) {
            $default_lang = HMPCv2_Options::get_first_language($enabled);
        }

        if ($default_lang && !in_array($default_lang, $enabled, true)) {
            array_unshift($enabled, $default_lang);
        }

        $labels = array();
        foreach ($enabled as $code) {
            $labels[$code] = HMPCv2_Langs::label($code);
        }

        $cookie_days = max(1, absint($input['cookie_days']));

        return array(
            'enabled_langs' => $enabled,
            'lang_labels' => $labels,
            'default_lang' => $default_lang,
            'prefix_default_lang' => !empty($input['prefix_default_lang']),
            'cookie_remember' => !empty($input['cookie_remember']),
            'cookie_days' => $cookie_days,
            'geo_autoswitch' => !empty($input['geo_autoswitch']),
        );
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('HM Pro Çeviri v2', 'hmpcv2'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('hmpcv2_options'); ?>
                <?php do_settings_sections('hmpcv2-settings'); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function render_languages_field() {
        $options = HMPCv2_Options::get_all();
        $whitelist = HMPCv2_Langs::whitelist();
        ?>
        <fieldset>
            <p><?php esc_html_e('Select the languages you want to enable. Only whitelisted codes are allowed.', 'hmpcv2'); ?></p>
            <?php foreach ($whitelist as $code => $label) : ?>
                <label style="display: block; margin: 4px 0;">
                    <input type="checkbox" name="<?php echo esc_attr(HMPCv2_Options::OPT_KEY); ?>[enabled_langs][]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $options['enabled_langs'], true)); ?> />
                    <?php echo esc_html($code . ' — ' . $label); ?>
                </label>
            <?php endforeach; ?>
            <p class="description"><?php esc_html_e('Default language will always remain enabled.', 'hmpcv2'); ?></p>
        </fieldset>
        <?php
    }

    public static function render_default_language_field() {
        $options = HMPCv2_Options::get_all();
        $enabled = HMPCv2_Langs::enabled_langs();
        $labels = $options['lang_labels'];
        ?>
        <select name="<?php echo esc_attr(HMPCv2_Options::OPT_KEY); ?>[default_lang]">
            <?php foreach ($enabled as $code) : ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($code, $options['default_lang']); ?>>
                    <?php echo esc_html(HMPCv2_Options::get_label($code, $labels) . ' (' . $code . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function render_prefix_default_field() {
        $options = HMPCv2_Options::get_all();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(HMPCv2_Options::OPT_KEY); ?>[prefix_default_lang]" value="1" <?php checked($options['prefix_default_lang']); ?> />
            <?php esc_html_e('If enabled, default language URLs also include the language prefix.', 'hmpcv2'); ?>
        </label>
        <?php
    }

    public static function render_cookie_field() {
        $options = HMPCv2_Options::get_all();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(HMPCv2_Options::OPT_KEY); ?>[cookie_remember]" value="1" <?php checked($options['cookie_remember']); ?> />
            <?php esc_html_e('Remember visitor language with a cookie', 'hmpcv2'); ?>
        </label>
        <p>
            <label>
                <?php esc_html_e('Cookie duration (days):', 'hmpcv2'); ?>
                <input type="number" min="1" name="<?php echo esc_attr(HMPCv2_Options::OPT_KEY); ?>[cookie_days]" value="<?php echo esc_attr($options['cookie_days']); ?>" />
            </label>
        </p>
        <?php
    }

    public static function render_geo_autoswitch_field() {
        $options = HMPCv2_Options::get_all();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(HMPCv2_Options::OPT_KEY); ?>[geo_autoswitch]" value="1" <?php checked(!empty($options['geo_autoswitch'])); ?> />
            <?php esc_html_e('If enabled, first-time visitors are redirected based on their country (WooCommerce geolocation). Example: Romania -> /ro/.', 'hmpcv2'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Only redirects when there is no language prefix and no existing language cookie yet. After redirect, the normal cookie system keeps the language.', 'hmpcv2'); ?>
        </p>
        <?php
    }

}
