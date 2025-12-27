<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Admin_Translations {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);

        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('wp_ajax_hmpcv2_search_posts', array(__CLASS__, 'ajax_search_posts'));
        add_action('wp_ajax_hmpcv2_search_content', array(__CLASS__, 'ajax_search_content'));
        add_action('wp_ajax_hmpcv2_create_translation', array(__CLASS__, 'ajax_create_translation'));
        add_action('wp_ajax_hmpcv2_create_group', array(__CLASS__, 'ajax_create_group'));
        add_action('wp_ajax_hmpcv2_search_terms', array(__CLASS__, 'ajax_search_terms'));
        add_action('wp_ajax_hmpcv2_save_term_translation', array(__CLASS__, 'ajax_save_term_translation'));
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
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_post_screen = $screen && !empty($screen->base) && $screen->base === 'post';
        $is_translations_screen = ($hook === 'settings_page_hmpcv2-translations');

        if (!$is_post_screen && !$is_translations_screen) return;

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

        if ($is_translations_screen) {
            $opts = HMPCv2_Options::get_all();
            $lang_labels = isset($opts['lang_labels']) && is_array($opts['lang_labels']) ? $opts['lang_labels'] : array();

            wp_register_script(
                'hmpcv2-admin-translations',
                HMPCV2_URL . 'assets/admin-translations.js',
                array('jquery', 'jquery-ui-autocomplete'),
                HMPCV2_VERSION,
                true
            );

            wp_localize_script('hmpcv2-admin-translations', 'HMPCv2Translations', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('hmpcv2_admin_nonce'),
                'default_lang' => HMPCv2_Langs::default_lang(),
                'enabled_langs' => HMPCv2_Langs::enabled_langs(),
                'lang_labels' => $lang_labels,
            ));

            wp_enqueue_script('hmpcv2-admin-translations');
        }

        wp_register_style('hmpcv2-admin-inline', false, array(), HMPCV2_VERSION);
        wp_enqueue_style('hmpcv2-admin-inline');
        wp_add_inline_style('hmpcv2-admin-inline', '
            .hmpcv2-pill{display:inline-block;padding:2px 8px;border:1px solid rgba(0,0,0,.15);border-radius:999px;font-size:12px;margin-right:6px}
            .hmpcv2-pill.ok{font-weight:600}
            .hmpcv2-pill.miss{opacity:.7}
            .hmpcv2-table td{vertical-align:top}
            .hmpcv2-actions .button{margin:2px 4px 2px 0}
            .hmpcv2-tabs .nav-tab{cursor:pointer}
            .hmpcv2-tab{display:none;margin-top:12px}
            .hmpcv2-tab.active{display:block}
            .hmpcv2-card{border:1px solid #dcdcde;padding:12px;border-radius:4px;margin-bottom:12px;background:#fff}
            .hmpcv2-flex{display:flex;gap:12px;align-items:flex-start}
            .hmpcv2-langs{margin-top:6px}
            .hmpcv2-small{font-size:12px;color:#666}
            .hmpcv2-term-block{border:1px solid #e2e2e2;padding:12px;border-radius:4px;margin:0 0 12px;background:#fff}
            .hmpcv2-term-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:8px}
            .hmpcv2-term-grid .field{display:flex;flex-direction:column}
            .hmpcv2-term-grid label{font-weight:600;margin-bottom:4px}
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

    public static function ajax_search_content() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $q = isset($_POST['q']) ? sanitize_text_field((string)$_POST['q']) : '';

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $posts = array();
        $seen = array();

        // Direct ID lookup
        $maybe_id = absint($q);
        if ($maybe_id) {
            $direct = get_post($maybe_id);
            if ($direct && $direct->ID) {
                $posts[] = $direct;
                $seen[$direct->ID] = true;
            }
        }

        // Slug / path lookup
        if ($q && preg_match('/^[a-z0-9-]+$/i', $q)) {
            $by_path = get_page_by_path($q, OBJECT, array('any'));
            if ($by_path && $by_path->ID && empty($seen[$by_path->ID])) {
                $posts[] = $by_path;
                $seen[$by_path->ID] = true;
            }
        }

        // General search
        $args = array(
            'post_type' => 'any',
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => 20,
            's' => $q,
            'no_found_rows' => true,
        );

        $found = get_posts($args);
        foreach ($found as $p) {
            if (isset($seen[$p->ID])) continue;
            $seen[$p->ID] = true;
            $posts[] = $p;
        }

        $out = array();
        foreach ($posts as $p) {
            $group = self::prepare_group_map((int)$p->ID, $enabled);
            $edit_urls = array();
            if (!empty($group['map'])) {
                foreach ($group['map'] as $code => $pid) {
                    $edit = get_edit_post_link((int)$pid, '');
                    if ($edit) $edit_urls[$code] = $edit;
                }
            }
            $out[] = array(
                'id' => (int)$p->ID,
                'title' => get_the_title($p->ID),
                'type' => get_post_type($p->ID),
                'status' => get_post_status($p->ID),
                'lang' => HMPCv2_Translations::get_lang((int)$p->ID) ?: $default,
                'group' => $group,
                'edit_url' => get_edit_post_link($p->ID, ''),
                'edit_urls' => $edit_urls,
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

        // Create a draft translation post as a DUPLICATE of the source (content + meta)
        $new_id = wp_insert_post(array(
            'post_type'      => $source->post_type,
            'post_status'    => 'draft',
            'post_title'     => $source->post_title . ' [' . strtoupper($target_lang) . ']',
            'post_content'   => (string) $source->post_content,
            'post_excerpt'   => (string) $source->post_excerpt,
            'post_author'    => (int) $source->post_author,
            'post_parent'    => (int) $source->post_parent,
            'menu_order'     => (int) $source->menu_order,
            'comment_status' => (string) $source->comment_status,
            'ping_status'    => (string) $source->ping_status,
        ), true);

        if (is_wp_error($new_id) || !$new_id) wp_send_json_error(array('message' => 'create_failed'), 500);

        // Copy featured image
        $thumb_id = get_post_thumbnail_id($source_id);
        if ($thumb_id) {
            set_post_thumbnail($new_id, $thumb_id);
        }

        // Copy ALL meta except HMPC + editor locks
        $all_meta = get_post_meta($source_id);
        $skip_keys = array(
            '_edit_lock',
            '_edit_last',
            'wp_old_slug',
        );
        foreach ($all_meta as $meta_key => $values) {
            if (in_array($meta_key, $skip_keys, true)) continue;
            if (strpos($meta_key, '_hmpcv2_') === 0) continue; // our own mapping meta

            // remove any existing key on target first to avoid merges
            delete_post_meta($new_id, $meta_key);

            foreach ((array) $values as $v) {
                // values from get_post_meta() are already unserialized in many cases,
                // but safe to pass as-is.
                add_post_meta($new_id, $meta_key, maybe_unserialize($v));
            }
        }

        // Copy taxonomies/terms (useful for products/pages with taxonomies)
        $taxes = get_object_taxonomies($source->post_type, 'names');
        if (!empty($taxes)) {
            foreach ($taxes as $tax) {
                $term_ids = wp_get_object_terms($source_id, $tax, array('fields' => 'ids'));
                if (!is_wp_error($term_ids)) {
                    wp_set_object_terms($new_id, $term_ids, $tax, false);
                }
            }
        }

        HMPCv2_Translations::set_group($new_id, $group);
        HMPCv2_Translations::set_lang($new_id, $target_lang);

        $edit = get_edit_post_link($new_id, '');
        $group = HMPCv2_Translations::get_group($new_id);
        $map = $group ? HMPCv2_Translations::get_group_map($group, $enabled) : array();
        wp_send_json_success(array(
            'new_id' => (int)$new_id,
            'edit_url' => $edit ? $edit : '',
            'label' => get_the_title($new_id) . ' (#' . (int)$new_id . ')',
            'group' => $group,
            'map' => $map,
        ));
    }

    public static function ajax_create_group() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $source = get_post($source_id);
        if (!$source) wp_send_json_error(array('message' => 'bad_source'), 400);

        $existing_group = HMPCv2_Translations::get_group($source_id);
        if ($existing_group === '') {
            $group = HMPCv2_Translations::generate_group_id();
            HMPCv2_Translations::set_group($source_id, $group);
            $lang = HMPCv2_Translations::get_lang($source_id);
            HMPCv2_Translations::set_lang($source_id, $lang ? $lang : $default);
        } else {
            $group = $existing_group;
        }

        $map = HMPCv2_Translations::get_group_map($group, $enabled);
        wp_send_json_success(array(
            'group' => $group,
            'map' => $map,
        ));
    }

    public static function ajax_search_terms() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $q = isset($_POST['q']) ? sanitize_text_field((string)$_POST['q']) : '';

        $taxonomies = array('category', 'post_tag');
        $public_taxes = get_taxonomies(array('public' => true), 'names');
        foreach ($public_taxes as $tax) {
            if (strpos($tax, 'product_') === 0 || strpos($tax, 'pa_') === 0) {
                $taxonomies[] = $tax;
            }
        }
        $taxonomies = array_unique($taxonomies);

        $args = array(
            'taxonomy' => $taxonomies,
            'hide_empty' => false,
            'number' => 20,
            'search' => $q,
        );

        $maybe_id = absint($q);
        if ($maybe_id) {
            $args['include'] = array($maybe_id);
        }

        $terms = get_terms($args);
        if (is_wp_error($terms)) wp_send_json_error(array('message' => 'term_error'), 500);

        $translations = HMPCv2_Options::get_term_translations();
        $out = array();
        foreach ($terms as $t) {
            $term_id = (int)$t->term_id;
            $tax = (string)$t->taxonomy;
            $out[] = array(
                'id' => $term_id,
                'name' => $t->name,
                'slug' => $t->slug,
                'description' => $t->description,
                'taxonomy' => $tax,
                'translations' => isset($translations[$term_id]) ? $translations[$term_id] : array(),
            );
        }

        wp_send_json_success(array('items' => $out));
    }

    public static function ajax_save_term_translation() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $term_id = isset($_POST['term_id']) ? (int)$_POST['term_id'] : 0;
        $lang = isset($_POST['lang']) ? HMPCv2_Langs::sanitize_lang_code((string)$_POST['lang'], '') : '';

        if (!$term_id || !$lang) wp_send_json_error(array('message' => 'bad_input'), 400);

        $name = isset($_POST['name']) ? sanitize_text_field((string)$_POST['name']) : '';
        $slug = isset($_POST['slug']) ? sanitize_title((string)$_POST['slug']) : '';
        $description = isset($_POST['description']) ? wp_kses_post((string)$_POST['description']) : '';

        $data = array(
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
        );

        HMPCv2_Options::save_term_translation($term_id, $lang, $data);

        wp_send_json_success(array('saved' => true, 'data' => $data));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $suggested = self::get_suggested_posts();

        echo '<div class="wrap">';
        echo '<h1>HMPC v2 Translations</h1>';
        echo '<p>Wix-style mode: search, suggest, and translate content and taxonomy terms without leaving the dashboard.</p>';

        echo '<div class="hmpcv2-tabs">';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a class="nav-tab nav-tab-active" data-tab="content">Content Search</a>';
        echo '<a class="nav-tab" data-tab="suggested">Suggested</a>';
        echo '<a class="nav-tab" data-tab="taxonomy">Taxonomy Search</a>';
        echo '</h2>';

        // Content search tab
        echo '<div id="hmpcv2-tab-content" class="hmpcv2-tab active">';
        echo '<p>Find posts, pages, or products by title, slug, or ID. Create or edit translation groups in place.</p>';
        echo '<form id="hmpcv2-content-form" style="margin:12px 0;">';
        echo '<input type="text" id="hmpcv2-content-q" placeholder="Search title, slug, or ID" style="width:320px;" /> ';
        echo '<button class="button button-primary" type="submit">Search</button>';
        echo '</form>';
        echo '<div id="hmpcv2-content-results"></div>';
        echo '</div>';

        // Suggested tab
        echo '<div id="hmpcv2-tab-suggested" class="hmpcv2-tab">';
        echo '<p>One-click setup for common pages (front page, posts page, WooCommerce core pages).</p>';
        if (empty($suggested)) {
            echo '<p>No suggested pages detected.</p>';
        } else {
            echo '<div class="hmpcv2-suggested-list">';
            foreach ($suggested as $item) {
                $group = self::prepare_group_map($item['id'], $enabled);
                $base_title = get_the_title($item['id']);
                $edit = get_edit_post_link($item['id'], '');
                echo '<div class="hmpcv2-card hmpcv2-suggested" data-post="' . esc_attr($item['id']) . '" data-group="' . esc_attr(isset($group['group']) ? $group['group'] : '') . '">';
                echo '<div class="hmpcv2-flex">';
                echo '<div style="flex:2;">';
                echo '<strong>' . esc_html($item['label']) . '</strong><br />';
                echo '<span class="hmpcv2-small">' . esc_html($base_title ? $base_title : '(no title)') . ' — #' . (int)$item['id'] . '</span>';
                if ($edit) {
                    echo '<div class="hmpcv2-small"><a href="' . esc_url($edit) . '">Edit source</a></div>';
                }
                echo '</div>';
                echo '<div class="hmpcv2-langs" data-map="' . esc_attr(wp_json_encode(isset($group['map']) ? $group['map'] : array())) . '">';
                echo self::render_lang_status($group ? $group['map'] : array(), $enabled);
                echo self::render_lang_actions($item['id'], $group, $enabled, $default);
                echo '</div>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Taxonomy tab
        echo '<div id="hmpcv2-tab-taxonomy" class="hmpcv2-tab">';
        echo '<p>Search taxonomy terms and save translated labels, slugs, and descriptions without creating duplicate terms.</p>';
        echo '<form id="hmpcv2-taxonomy-form" style="margin:12px 0;">';
        echo '<input type="text" id="hmpcv2-taxonomy-q" placeholder="Category, tag, product category, attribute…" style="width:320px;" /> ';
        echo '<button class="button" type="submit">Search</button>';
        echo '</form>';
        echo '<div id="hmpcv2-taxonomy-results"></div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private static function prepare_group_map($post_id, $enabled_langs) {
        $group = HMPCv2_Translations::get_group($post_id);
        $map = $group ? HMPCv2_Translations::get_group_map($group, $enabled_langs) : array();
        return array(
            'group' => $group,
            'map' => $map,
        );
    }

    private static function render_lang_status($map, $enabled) {
        $pills = '';
        foreach ($enabled as $code) {
            $has = !empty($map[$code]);
            $pills .= '<span class="hmpcv2-pill ' . ($has ? 'ok' : 'miss') . '">' . esc_html(strtoupper($code)) . '</span>';
        }
        return '<div>' . $pills . '</div>';
    }

    private static function render_lang_actions($source_id, $group, $enabled, $default) {
        $map = isset($group['map']) ? $group['map'] : array();
        $group_id = isset($group['group']) ? $group['group'] : '';
        $base_id = !empty($map[$default]) ? (int)$map[$default] : (int)$source_id;

        $out = '<div class="hmpcv2-actions" data-source="' . esc_attr($base_id) . '" data-group="' . esc_attr($group_id) . '" data-map="' . esc_attr(wp_json_encode($map)) . '">';

        if ($group_id === '') {
            $out .= '<button type="button" class="button button-small hmpcv2-create-group" data-source="' . esc_attr($source_id) . '">Create group (base ' . esc_html(strtoupper($default)) . ')</button>';
        }

        foreach ($enabled as $code) {
            if (!empty($map[$code])) {
                $edit = get_edit_post_link((int)$map[$code], '');
                if ($edit) {
                    $out .= '<a class="button button-small" href="' . esc_url($edit) . '">Edit ' . esc_html(strtoupper($code)) . '</a> ';
                }
            } else {
                $out .= '<button type="button" class="button button-small hmpcv2-create-translation" data-lang="' . esc_attr($code) . '" data-source="' . esc_attr($base_id) . '">Create ' . esc_html(strtoupper($code)) . '</button> ';
            }
        }

        $out .= '</div>';
        return $out;
    }

    private static function get_suggested_posts() {
        $suggested = array();

        $front = (int)get_option('page_on_front');
        if ($front > 0 && get_post($front)) {
            $suggested[] = array('id' => $front, 'label' => 'Front Page');
        }

        $posts_page = (int)get_option('page_for_posts');
        if ($posts_page > 0 && get_post($posts_page)) {
            $suggested[] = array('id' => $posts_page, 'label' => 'Posts Page');
        }

        $woo_pages = array(
            'Shop' => (int)get_option('woocommerce_shop_page_id'),
            'Cart' => (int)get_option('woocommerce_cart_page_id'),
            'Checkout' => (int)get_option('woocommerce_checkout_page_id'),
            'My Account' => (int)get_option('woocommerce_myaccount_page_id'),
        );

        foreach ($woo_pages as $label => $pid) {
            if ($pid > 0 && get_post($pid)) {
                $suggested[] = array('id' => $pid, 'label' => $label . ' Page');
            }
        }

        return $suggested;
    }
}
