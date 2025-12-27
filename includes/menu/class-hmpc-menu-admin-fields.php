<?php
if (!defined('ABSPATH')) exit;

final class HMPC_Menu_Admin_Fields {
    public static function init() {
        if (!is_admin()) return;

        add_action('wp_nav_menu_item_custom_fields', array(__CLASS__, 'render_fields'), 10, 4);
        add_action('wp_update_nav_menu_item', array(__CLASS__, 'save_fields'), 10, 3);
    }

    public static function render_fields($item_id, $item, $depth, $args) {
        $languages = HMPC_Language::enabled_langs();
        $default = HMPC_Language::default_lang();

        if (empty($languages)) return;

        echo '<div class="field-hmpc-menu-labels description-wide">';
        echo '<span class="description">' . esc_html__('HMPC Menu Labels (per language)', 'hmpc') . '</span>';

        foreach ($languages as $lang) {
            $meta_key = '_hmpc_menu_label_' . $lang;
            $value = get_post_meta($item_id, $meta_key, true);

            echo '<p class="description">';
            printf(
                '<label for="edit-menu-item-hmpc-label-%1$s-%2$d">%3$s<br /><input type="text" id="edit-menu-item-hmpc-label-%1$s-%2$d" class="widefat code edit-menu-item-hmpc-label" name="hmpc_menu_labels[%2$d][%1$s]" value="%4$s" placeholder="%5$s" /></label>',
                esc_attr($lang),
                (int) $item_id,
                esc_html(sprintf(__('Label (%s)', 'hmpc'), strtoupper($lang))),
                esc_attr($value),
                esc_attr($lang === $default ? __('Default menu label', 'hmpc') : __('Leave empty to fallback to default label', 'hmpc'))
            );
            echo '</p>';
        }

        echo '</div>';
    }

    public static function save_fields($menu_id, $menu_item_db_id, $args) {
        if (!isset($_POST['hmpc_menu_labels']) || !is_array($_POST['hmpc_menu_labels'])) {
            return;
        }

        $labels = $_POST['hmpc_menu_labels'];
        if (!isset($labels[$menu_item_db_id]) || !is_array($labels[$menu_item_db_id])) {
            return;
        }

        $languages = HMPC_Language::enabled_langs();

        foreach ($languages as $lang) {
            $raw = isset($labels[$menu_item_db_id][$lang]) ? $labels[$menu_item_db_id][$lang] : '';
            $value = sanitize_text_field($raw);
            $meta_key = '_hmpc_menu_label_' . $lang;

            if ($value === '') {
                delete_post_meta($menu_item_db_id, $meta_key);
            } else {
                update_post_meta($menu_item_db_id, $meta_key, $value);
            }
        }
    }
}
