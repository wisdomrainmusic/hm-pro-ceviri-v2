<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Admin_Translations {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);

        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('wp_ajax_hmpcv2_search_posts', array(__CLASS__, 'ajax_search_posts'));
        add_action('wp_ajax_hmpcv2_create_translation', array(__CLASS__, 'ajax_create_translation'));
    }

    public static function menu() {
        add_submenu_page(
            'options-general.php',
            'HMPC v2 Translations',
            'HMPC v2 Translations',
            'manage_options',
            'hmpcv2-translations',
            array(__CLASS__, 'render_page')
        );
    }

    public static function enqueue($hook) {
        if ($hook !== 'settings_page_hmpcv2-translations') {
            // also needed on post edit screens for metabox autocomplete
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || empty($screen->base) || $screen->base !== 'post') return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-autocomplete');

        wp_register_script(
            'hmpcv2-admin',
            HMPCV2_URL . 'assets/admin.js',
            array('jquery', 'jquery-ui-autocomplete'),
            HMPCV2_VERSION,
            true
        );

        wp_localize_script('hmpcv2-admin', 'HMPCv2Admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hmpcv2_admin_nonce'),
        ));

        wp_enqueue_script('hmpcv2-admin');

        wp_register_style('hmpcv2-admin-inline', false, array(), HMPCV2_VERSION);
        wp_enqueue_style('hmpcv2-admin-inline');
        wp_add_inline_style('hmpcv2-admin-inline', '
            .hmpcv2-pill{display:inline-block;padding:2px 8px;border:1px solid rgba(0,0,0,.15);border-radius:999px;font-size:12px;margin-right:6px}
            .hmpcv2-pill.ok{font-weight:600}
            .hmpcv2-pill.miss{opacity:.7}
            .hmpcv2-table td{vertical-align:top}
            .hmpcv2-actions button{margin-right:6px}
        ');
    }

    public static function ajax_search_posts() {
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $q = isset($_POST['q']) ? sanitize_text_field((string)$_POST['q']) : '';
        $lang = isset($_POST['lang']) ? sanitize_text_field((string)$_POST['lang']) : '';

        $lang = HMPCv2_Langs::sanitize_lang_code($lang, HMPCv2_Langs::default_lang());

        $args = array(
            'post_type' => 'any',
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => 20,
            's' => $q,
            'no_found_rows' => true,
        );

        $posts = get_posts($args);

        $out = array();
        foreach ($posts as $p) {
            $out[] = array(
                'id' => (int)$p->ID,
                'label' => get_the_title($p->ID) . ' (#' . (int)$p->ID . ')',
                'value' => get_the_title($p->ID) . ' (#' . (int)$p->ID . ')',
            );
        }

        wp_send_json_success(array('items' => $out));
    }

    public static function ajax_create_translation() {
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field((string)$_POST['target_lang']) : '';

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $target_lang = HMPCv2_Langs::sanitize_lang_code($target_lang, $default);
        if (!in_array($target_lang, $enabled, true)) wp_send_json_error(array('message' => 'bad_lang'), 400);

        $source = get_post($source_id);
        if (!$source) wp_send_json_error(array('message' => 'bad_source'), 400);

        $group = HMPCv2_Translations::get_group($source_id);
        if ($group === '') {
            $group = HMPCv2_Translations::generate_group_id();
            HMPCv2_Translations::set_group($source_id, $group);
            // keep existing lang if present; else default
            $src_lang = HMPCv2_Translations::get_lang($source_id);
            if (!$src_lang) HMPCv2_Translations::set_lang($source_id, $default);
        }

        // Create a draft translation post (empty content) for manual editing
        $new_id = wp_insert_post(array(
            'post_type' => $source->post_type,
            'post_status' => 'draft',
            'post_title' => $source->post_title . ' [' . strtoupper($target_lang) . ']',
            'post_content' => '',
            'post_excerpt' => '',
        ), true);

        if (is_wp_error($new_id) || !$new_id) wp_send_json_error(array('message' => 'create_failed'), 500);

        HMPCv2_Translations::set_group($new_id, $group);
        HMPCv2_Translations::set_lang($new_id, $target_lang);

        $edit = get_edit_post_link($new_id, '');
        wp_send_json_success(array(
            'new_id' => (int)$new_id,
            'edit_url' => $edit ? $edit : '',
            'label' => get_the_title($new_id) . ' (#' . (int)$new_id . ')',
        ));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        // Get posts that belong to groups
        $q = new WP_Query(array(
            'post_type' => 'any',
            'post_status' => array('publish', 'draft', 'private'),
            'fields' => 'ids',
            'posts_per_page' => 500,
            'no_found_rows' => true,
            'meta_query' => array(
                array('key' => HMPCv2_Translations::META_GROUP, 'compare' => 'EXISTS'),
            ),
        ));

        $groups = array(); // group_id => [lang => post_id]
        if (!empty($q->posts)) {
            foreach ($q->posts as $pid) {
                $pid = (int)$pid;
                $g = HMPCv2_Translations::get_group($pid);
                $l = HMPCv2_Translations::get_lang($pid);
                if (!$g || !$l) continue;
                if (!in_array($l, $enabled, true)) continue;
                if (!isset($groups[$g])) $groups[$g] = array();
                $groups[$g][$l] = $pid;
            }
        }

        // Unassigned content (no group yet) — show quick list so user can start grouping
        $un = new WP_Query(array(
            'post_type' => 'any',
            'post_status' => array('publish', 'draft', 'private'),
            'fields' => 'ids',
            'posts_per_page' => 30,
            'no_found_rows' => true,
            'meta_query' => array(
                array('key' => HMPCv2_Translations::META_GROUP, 'compare' => 'NOT EXISTS'),
            ),
        ));

        echo '<div class="wrap">';
        echo '<h1>HMPC v2 Translations</h1>';
        echo '<p>Wix-style mode: see what is translated, what is missing, and create missing translations with one click.</p>';

        echo '<h2>Translation Groups</h2>';

        if (empty($groups)) {
            echo '<p>No groups yet. Open a page/post and use the Translations box to start linking, or create missing drafts from here once groups exist.</p>';
        } else {
            echo '<table class="widefat striped hmpcv2-table">';
            echo '<thead><tr>';
            echo '<th>Group</th>';
            echo '<th>Base (default)</th>';
            echo '<th>Status</th>';
            echo '<th>Actions</th>';
            echo '</tr></thead><tbody>';

            foreach ($groups as $gid => $map) {
                $base_id = isset($map[$default]) ? (int)$map[$default] : (int)reset($map);
                $base_title = $base_id ? get_the_title($base_id) : '(no title)';
                $base_edit = $base_id ? get_edit_post_link($base_id, '') : '';

                // Status pills
                $missing = array();
                $pills = '';
                foreach ($enabled as $code) {
                    if (!empty($map[$code])) {
                        $pills .= '<span class="hmpcv2-pill ok">' . esc_html(strtoupper($code)) . '</span>';
                    } else {
                        $pills .= '<span class="hmpcv2-pill miss">' . esc_html(strtoupper($code)) . '</span>';
                        $missing[] = $code;
                    }
                }

                echo '<tr>';
                echo '<td><code>' . esc_html($gid) . '</code></td>';
                echo '<td>';
                if ($base_edit) {
                    echo '<a href="' . esc_url($base_edit) . '"><strong>' . esc_html($base_title) . '</strong></a>';
                    echo '<div style="font-size:12px;color:#666;">#' . (int)$base_id . '</div>';
                } else {
                    echo '<strong>' . esc_html($base_title) . '</strong>';
                }
                echo '</td>';

                echo '<td>' . $pills . '</td>';

                echo '<td class="hmpcv2-actions">';
                if (!empty($missing)) {
                    foreach ($missing as $code) {
                        echo '<button type="button" class="button button-small hmpcv2-create-missing"
                            data-source="' . esc_attr($base_id) . '"
                            data-lang="' . esc_attr($code) . '">Create ' . esc_html(strtoupper($code)) . '</button>';
                    }
                } else {
                    echo '<span style="color:#2a7; font-weight:600;">Complete</span>';
                }
                echo '</td>';

                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<hr style="margin:18px 0;">';
        echo '<h2>Unassigned Content (start here)</h2>';
        echo '<p>These pages/posts have no translation group yet. Open one to start linking translations.</p>';

        if (empty($un->posts)) {
            echo '<p>Nothing unassigned found.</p>';
        } else {
            echo '<ul style="margin-left:18px;">';
            foreach ($un->posts as $pid) {
                $pid = (int)$pid;
                $t = get_the_title($pid);
                $e = get_edit_post_link($pid, '');
                echo '<li><a href="' . esc_url($e) . '">' . esc_html($t ? $t : '(no title)') . '</a> <span style="color:#666;">(#' . (int)$pid . ')</span></li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }
}
