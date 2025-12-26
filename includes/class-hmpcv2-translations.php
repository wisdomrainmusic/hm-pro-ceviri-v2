<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Translations {

    const META_GROUP = '_hmpcv2_group';
    const META_LANG  = '_hmpcv2_lang';

    public static function init_admin() {
        add_action('add_meta_boxes', array(__CLASS__, 'add_metabox'));
        add_action('save_post', array(__CLASS__, 'save_metabox'), 10, 2);
    }

    public static function add_metabox() {
        $post_types = get_post_types(array('public' => true), 'names');
        if (!is_array($post_types)) $post_types = array('page', 'post');

        // Products are handled by HMPCv2_Woo single-product language fields
        if (($k = array_search('product', $post_types, true)) !== false) {
            unset($post_types[$k]);
        }

        foreach ($post_types as $pt) {
            add_meta_box(
                'hmpcv2_translations',
                'HMPC v2 Translations',
                array(__CLASS__, 'render_metabox'),
                $pt,
                'side',
                'default'
            );
        }
    }

    public static function render_metabox($post) {
        if (!$post || empty($post->ID)) return;

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $group = self::get_group($post->ID);
        $lang  = self::get_lang($post->ID);

        if (!$lang) {
            $lang = HMPCv2_Router::current_lang();
            if (!in_array($lang, $enabled, true)) $lang = $default;
        }

        wp_nonce_field('hmpcv2_translations_save', 'hmpcv2_translations_nonce');

        echo '<p style="margin-top:0;">Wix-style: link equivalents per language. No IDs — search & select.</p>';

        echo '<p><strong>This content language</strong><br />';
        echo '<select style="width:100%;" name="hmpcv2_lang">';
        foreach ($enabled as $code) {
            echo '<option value="' . esc_attr($code) . '"' . selected($lang, $code, false) . '>' . esc_html(strtoupper($code) . ' — ' . HMPCv2_Langs::label($code)) . '</option>';
        }
        echo '</select></p>';

        // Ensure group exists or will be created on save
        echo '<input type="hidden" name="hmpcv2_group" value="' . esc_attr($group) . '" />';

        $existing_map = $group ? self::get_group_map($group, $enabled) : array();

        echo '<hr style="margin:10px 0;">';
        echo '<p style="margin:0 0 8px;"><strong>Linked translations</strong></p>';

        foreach ($enabled as $code) {
            $pid = isset($existing_map[$code]) ? (int)$existing_map[$code] : 0;
            $title = $pid ? get_the_title($pid) : '';
            $label = strtoupper($code);

            echo '<div style="margin:0 0 10px;">';
            echo '<label style="display:block; font-size:12px; margin-bottom:3px;">' . esc_html($label) . '</label>';

            echo '<input type="text"
            class="hmpcv2-post-search"
            data-lang="' . esc_attr($code) . '"
            placeholder="Search and select…"
            style="width:100%;"
            value="' . esc_attr($title ? ($title . ' (#' . $pid . ')') : '') . '" />';

            echo '<input type="hidden" name="hmpcv2_linked[' . esc_attr($code) . ']" value="' . esc_attr($pid ?: '') . '" />';

            if ($pid) {
                $edit = get_edit_post_link($pid, '');
                if ($edit) {
                    echo '<div style="margin-top:4px; font-size:12px;">';
                    echo '<a href="' . esc_url($edit) . '">Edit</a>';
                    echo '</div>';
                }
            } else {
                echo '<div style="margin-top:4px; font-size:12px; color:#666;">Not linked</div>';
            }

            echo '</div>';
        }

        echo '<p style="margin:8px 0 0; font-size:12px; color:#666;">Save updates links for the whole group.</p>';
    }

    public static function save_metabox($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || empty($post_id)) return;

        // Verify nonce
        $nonce = isset($_POST['hmpcv2_translations_nonce']) ? (string)$_POST['hmpcv2_translations_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_translations_save')) return;

        // Capability
        if (!current_user_can('edit_post', $post_id)) return;

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $group = isset($_POST['hmpcv2_group']) ? sanitize_text_field((string)$_POST['hmpcv2_group']) : '';
        $lang  = isset($_POST['hmpcv2_lang']) ? sanitize_text_field((string)$_POST['hmpcv2_lang']) : '';

        $lang = HMPCv2_Langs::sanitize_lang_code($lang, $default);
        if (!in_array($lang, $enabled, true)) $lang = $default;

        if ($group === '') {
            $group = self::generate_group_id();
        }

        // Save current post meta
        self::set_group($post_id, $group);
        self::set_lang($post_id, $lang);

        // Linked IDs
        $linked = isset($_POST['hmpcv2_linked']) && is_array($_POST['hmpcv2_linked']) ? $_POST['hmpcv2_linked'] : array();

        // Ensure current post is in the mapping too (prefer explicit if user typed it)
        $linked[$lang] = (int)$post_id; // Always force current post into its own language slot

        foreach ($enabled as $code) {
            $pid = isset($linked[$code]) ? (int)$linked[$code] : 0;
            if ($pid < 1) continue;

            // Validate post exists and is editable-ish
            $p = get_post($pid);
            if (!$p || empty($p->ID)) continue;

            self::set_group($pid, $group);
            self::set_lang($pid, $code);
        }
    }

    public static function generate_group_id() {
        // Short, readable unique key
        // Example: g_20251226_8f3a1c2d
        $rand = substr(wp_generate_password(12, false, false), 0, 8);
        return 'g_' . gmdate('Ymd_His') . '_' . strtolower($rand);
    }

    public static function get_group($post_id) {
        $g = get_post_meta((int)$post_id, self::META_GROUP, true);
        return is_string($g) ? trim($g) : '';
    }

    public static function set_group($post_id, $group) {
        $group = is_string($group) ? trim($group) : '';
        if ($group === '') return false;
        return update_post_meta((int)$post_id, self::META_GROUP, $group);
    }

    public static function get_lang($post_id) {
        $l = get_post_meta((int)$post_id, self::META_LANG, true);
        $l = is_string($l) ? strtolower(trim($l)) : '';
        return $l;
    }

    public static function set_lang($post_id, $lang) {
        $lang = is_string($lang) ? strtolower(trim($lang)) : '';
        if ($lang === '') return false;
        return update_post_meta((int)$post_id, self::META_LANG, $lang);
    }

    public static function find_post_by_group_lang($group, $lang) {
        $group = is_string($group) ? trim($group) : '';
        $lang  = is_string($lang) ? strtolower(trim($lang)) : '';
        if ($group === '' || $lang === '') return 0;

        $q = new WP_Query(array(
            'post_type' => 'any',
            'post_status' => array('publish', 'private', 'draft'),
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'meta_query' => array(
                array('key' => self::META_GROUP, 'value' => $group, 'compare' => '='),
                array('key' => self::META_LANG,  'value' => $lang,  'compare' => '='),
            ),
        ));

        if (!empty($q->posts[0])) return (int)$q->posts[0];
        return 0;
    }

    public static function get_group_map($group, $enabled_langs = array()) {
        $group = is_string($group) ? trim($group) : '';
        if ($group === '') return array();

        if (!is_array($enabled_langs) || empty($enabled_langs)) {
            $enabled_langs = HMPCv2_Langs::enabled_langs();
        }

        $q = new WP_Query(array(
            'post_type' => 'any',
            'post_status' => array('publish', 'private', 'draft'),
            'fields' => 'ids',
            'posts_per_page' => 200,
            'no_found_rows' => true,
            'meta_query' => array(
                array('key' => self::META_GROUP, 'value' => $group, 'compare' => '='),
            ),
        ));

        $map = array();
        if (!empty($q->posts)) {
            foreach ($q->posts as $pid) {
                $pid = (int)$pid;
                $l = self::get_lang($pid);
                if (!$l) continue;
                if (!in_array($l, $enabled_langs, true)) continue;
                $map[$l] = $pid;
            }
        }

        return $map;
    }
}
