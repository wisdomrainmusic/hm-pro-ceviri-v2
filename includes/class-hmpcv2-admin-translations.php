<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Admin_Translations {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);

        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        self::register_ajax_hooks();
        add_action('wp_ajax_hmpcv2_search_posts', array(__CLASS__, 'ajax_search_posts'));
        add_action('wp_ajax_hmpcv2_search_content', array(__CLASS__, 'ajax_search_content'));
        add_action('wp_ajax_hmpcv2_create_translation', array(__CLASS__, 'ajax_create_translation'));
        add_action('wp_ajax_hmpcv2_create_group', array(__CLASS__, 'ajax_create_group'));
        add_action('wp_ajax_hmpcv2_search_terms', array(__CLASS__, 'ajax_search_terms'));
        add_action('wp_ajax_hmpcv2_save_term_translation', array(__CLASS__, 'ajax_save_term_translation'));
        add_action('wp_ajax_hmpcv2_complete_load', array(__CLASS__, 'ajax_complete_load'));
        add_action('wp_ajax_hmpcv2_complete_save', array(__CLASS__, 'ajax_complete_save'));
        add_action('wp_ajax_hmpcv2_style_save', array(__CLASS__, 'ajax_style_save'));
        add_action('wp_ajax_hmpcv2_list_pages', array(__CLASS__, 'ajax_list_pages'));
    }

    public static function register_ajax_hooks() {
        add_action('wp_ajax_hmpcv2_get_woo_page_title', array(__CLASS__, 'ajax_get_woo_page_title'));
        add_action('wp_ajax_hmpcv2_save_woo_page_title', array(__CLASS__, 'ajax_save_woo_page_title'));
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

        $is_post_screen = ($screen && !empty($screen->base) && $screen->base === 'post');

        // Hard-reliable check: ?page=hmpcv2-translations
        $page_param = isset($_GET['page']) ? sanitize_key((string)$_GET['page']) : '';
        $is_translations_screen = ($page_param === 'hmpcv2-translations');

        // Extra safety: also allow screen id match if available
        if (!$is_translations_screen && $screen && !empty($screen->id)) {
            $is_translations_screen = ((string)$screen->id === 'settings_page_hmpcv2-translations');
        }

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

    private static function hmpcv2_get_woo_core_page_ids(): array {
        $page_ids = array(
            (int) get_option('woocommerce_shop_page_id'),
            (int) get_option('woocommerce_cart_page_id'),
            (int) get_option('woocommerce_checkout_page_id'),
            (int) get_option('woocommerce_myaccount_page_id'),
        );

        $page_ids = array_values(array_unique(array_filter($page_ids, function($page_id) {
            return (int) $page_id > 0;
        })));

        return $page_ids;
    }

    private static function hmpcv2_is_woo_core_page_id(int $page_id): bool {
        return in_array($page_id, self::hmpcv2_get_woo_core_page_ids(), true);
    }

    private static function hmpcv2_sanitize_lang(string $lang): string {
        $lang = strtolower($lang);
        $lang = preg_replace('/[^a-z0-9_-]/', '', $lang);
        return $lang ? $lang : '';
    }

    public static function ajax_get_woo_page_title() {
        check_ajax_referer('hmpcv2_admin_nonce', 'nonce');
        if (!current_user_can('edit_pages')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $lang = isset($_POST['lang']) ? self::hmpcv2_sanitize_lang((string) $_POST['lang']) : '';

        if (!$page_id || !self::hmpcv2_is_woo_core_page_id($page_id)) {
            wp_send_json_error(array('message' => 'bad_page'), 400);
        }

        if ($lang === '') {
            wp_send_json_error(array('message' => 'bad_lang'), 400);
        }

        $meta_key = '_hmpcv2_title_' . $lang;
        $title = (string) get_post_meta($page_id, $meta_key, true);

        wp_send_json_success(array(
            'page_id' => $page_id,
            'lang' => $lang,
            'title' => $title,
        ));
    }

    public static function ajax_save_woo_page_title() {
        check_ajax_referer('hmpcv2_admin_nonce', 'nonce');
        if (!current_user_can('edit_pages')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $lang = isset($_POST['lang']) ? self::hmpcv2_sanitize_lang((string) $_POST['lang']) : '';
        $title = isset($_POST['title']) ? (string) $_POST['title'] : '';

        if (!$page_id || !self::hmpcv2_is_woo_core_page_id($page_id)) {
            wp_send_json_error(array('message' => 'bad_page'), 400);
        }

        if ($lang === '') {
            wp_send_json_error(array('message' => 'bad_lang'), 400);
        }

        $meta_key = '_hmpcv2_title_' . $lang;
        $title = trim(wp_strip_all_tags($title));

        if ($title === '') {
            delete_post_meta($page_id, $meta_key);
        } else {
            update_post_meta($page_id, $meta_key, $title);
        }

        wp_send_json_success(array('saved' => true));
    }

    public static function ajax_search_content() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $q = isset($_POST['q']) ? sanitize_text_field((string)$_POST['q']) : '';

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        // Public post types (exclude attachments) for broad search (pages incl. front page).
        $public_types = get_post_types(array('public' => true), 'names');
        if (isset($public_types['attachment'])) unset($public_types['attachment']);

        $posts = array();
        $seen = array();

        // Direct ID lookup (include any status except trash)
        $maybe_id = absint($q);
        if ($maybe_id) {
            $direct = get_post($maybe_id);
            if ($direct && $direct->ID && get_post_status($direct->ID) !== 'trash') {
                $posts[] = $direct;
                $seen[$direct->ID] = true;
            }
        }

        // If search is empty, include the Front Page as a guaranteed item.
        if ($q === '') {
            $front_id = (int)get_option('page_on_front');
            if ($front_id > 0 && empty($seen[$front_id])) {
                $front = get_post($front_id);
                if ($front && $front->ID && get_post_status($front->ID) !== 'trash') {
                    $posts[] = $front;
                    $seen[$front->ID] = true;
                }
            }
        }

        // Slug / path lookup
        if ($q && preg_match('/^[a-z0-9-]+$/i', $q)) {
            $by_path = get_page_by_path($q, OBJECT, $public_types);
            if ($by_path && $by_path->ID && empty($seen[$by_path->ID])) {
                $posts[] = $by_path;
                $seen[$by_path->ID] = true;
            }
        }

        // General search
        $args = array(
            'post_type' => $public_types,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future', 'inherit'),
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

    public static function ajax_list_pages() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);

        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);

        $page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        $only_grouped = isset($_POST['only_grouped']) ? absint($_POST['only_grouped']) : 0; // default OFF (show all pages)
        $per_page = 50;

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();

        $q = new WP_Query(array(
            'post_type' => 'page',
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => false,
            'fields' => 'ids',
        ));

        // Ensure the Front Page is always visible on the first page of results.
        if ($page === 1) {
            $front_id = (int)get_option('page_on_front');
            if ($front_id > 0) {
                $ids = is_array($q->posts) ? $q->posts : array();
                if (!in_array($front_id, $ids, true)) {
                    array_unshift($q->posts, $front_id);
                }
            }
        }

        $items = array();
        if ($q->have_posts()) {
            foreach ($q->posts as $pid) {
                $pid = (int)$pid;

                $title = get_the_title($pid);
                $status = get_post_status($pid);
                $src_lang = HMPCv2_Translations::get_lang($pid) ?: $default;

                $group = self::prepare_group_map($pid, $enabled);
                if ($only_grouped) {
                    $group_id = isset($group['group']) ? (string)$group['group'] : '';
                    $map = isset($group['map']) && is_array($group['map']) ? $group['map'] : array();

                    $mapped_count = 0;
                    foreach ($enabled as $code) {
                        if (!empty($map[$code])) $mapped_count++;
                    }

                    if ($group_id === '' || $mapped_count < 2) {
                        continue;
                    }
                }
                $edit_urls = array();

                if (!empty($group['map'])) {
                    foreach ($group['map'] as $code => $mapped_id) {
                        $edit = get_edit_post_link((int)$mapped_id, '');
                        if ($edit) $edit_urls[$code] = $edit;
                    }
                }

                $items[] = array(
                    'id' => $pid,
                    'title' => $title ? $title : '(no title)',
                    'status' => (string)$status,
                    'lang' => (string)$src_lang,
                    'group' => $group,
                    'edit_url' => get_edit_post_link($pid, ''),
                    'edit_urls' => $edit_urls,
                );
            }
        }

        $max_pages = (int)$q->max_num_pages;
        $has_more = ($page < $max_pages);

        wp_send_json_success(array(
            'items' => $items,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => $has_more,
            'max_pages' => $max_pages,
            'enabled_langs' => $enabled,
            'default_lang' => $default,
        ));
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

        $new_id = self::duplicate_as_translation($source_id, $target_lang, $group);
        if (!$new_id) wp_send_json_error(array('message' => 'create_failed'), 500);

        $target_el_data_after = get_post_meta($new_id, '_elementor_data', true);

        $edit = get_edit_post_link($new_id, '');
        $group = HMPCv2_Translations::get_group($new_id);
        $map = $group ? HMPCv2_Translations::get_group_map($group, $enabled) : array();
        wp_send_json_success(array(
            'new_id' => (int)$new_id,
            'edit_url' => $edit ? $edit : '',
            'label' => get_the_title($new_id) . ' (#' . (int)$new_id . ')',
            'group' => $group,
            'map' => $map,
            'elementor_data_source_len' => is_string($src_el_data_raw) ? strlen($src_el_data_raw) : 0,
            'elementor_data_target_len' => is_string($target_el_data_after) ? strlen($target_el_data_after) : 0,
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

    private static function must_admin() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);
        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'hmpcv2_admin_nonce')) wp_send_json_error(array('message' => 'bad_nonce'), 400);
    }

    public static function ajax_complete_load() {
        self::must_admin();

        $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        $lang = isset($_POST['lang']) ? (string)$_POST['lang'] : '';

        $enabled = HMPCv2_Langs::enabled_langs();
        $default = HMPCv2_Langs::default_lang();
        $lang = HMPCv2_Langs::sanitize_lang_code($lang, $default);
        if (!in_array($lang, $enabled, true)) $lang = $default;

        $source = get_post($source_id);
        if (!$source) wp_send_json_error(array('message' => 'bad_source'), 400);

        // Ensure group exists on source
        $group = HMPCv2_Translations::get_group($source_id);
        if ($group === '') {
            $group = HMPCv2_Translations::generate_group_id();
            HMPCv2_Translations::set_group($source_id, $group);
            $src_lang = HMPCv2_Translations::get_lang($source_id);
            if (!$src_lang) HMPCv2_Translations::set_lang($source_id, $default);
        }

        $map = HMPCv2_Translations::get_group_map($group, $enabled);
        $target_id = !empty($map[$lang]) ? (int)$map[$lang] : 0;

        // Create translation if missing and not the same as source lang
        if (!$target_id) {
            $src_lang = HMPCv2_Translations::get_lang($source_id) ?: $default;
            if ($lang === $src_lang) {
                $target_id = (int)$source_id;
            } else {
                $target_id = (int) self::duplicate_as_translation($source_id, $lang, $group);
            }
        }
        $target = get_post($target_id);
        if (!$target) wp_send_json_error(array('message' => 'target_missing'), 500);

        wp_send_json_success(array(
            'source_id' => (int)$source_id,
            'target_id' => (int)$target_id,
            'edit_url'  => get_edit_post_link((int)$target_id, ''),
            'title'     => (string)$target->post_title,
            'slug'      => (string)$target->post_name,
            'excerpt'   => (string)$target->post_excerpt,
            'content'   => (string)$target->post_content,
        ));
    }

    public static function ajax_complete_save() {
        self::must_admin();

        $target_id = isset($_POST['target_id']) ? (int)$_POST['target_id'] : 0;
        if (!$target_id || !get_post($target_id)) wp_send_json_error(array('message' => 'bad_target'), 400);

        $title = isset($_POST['title']) ? sanitize_text_field((string)$_POST['title']) : '';
        $slug  = isset($_POST['slug']) ? sanitize_title((string)$_POST['slug']) : '';
        $excerpt = isset($_POST['excerpt']) ? sanitize_textarea_field((string)$_POST['excerpt']) : '';
        $content = isset($_POST['content']) ? wp_kses_post((string)$_POST['content']) : '';

        $res = wp_update_post(array(
            'ID' => $target_id,
            'post_title' => $title,
            'post_name' => $slug,
            'post_excerpt' => $excerpt,
            'post_content' => $content,
        ), true);

        if (is_wp_error($res)) wp_send_json_error(array('message' => 'save_failed'), 500);

        clean_post_cache($target_id);

        wp_send_json_success(array(
            'saved' => true,
            'target_id' => $target_id,
            'edit_url' => get_edit_post_link($target_id, ''),
        ));
    }

    public static function ajax_style_save() {
        self::must_admin();

        $z = isset($_POST['switcher_z']) ? (int)$_POST['switcher_z'] : 99999;
        $bg = isset($_POST['switcher_bg']) ? sanitize_text_field((string)$_POST['switcher_bg']) : 'rgba(0,0,0,0.35)';
        $color = isset($_POST['switcher_color']) ? sanitize_text_field((string)$_POST['switcher_color']) : '#ffffff';
        $force = !empty($_POST['force_on_hero']) ? 1 : 0;

        $opts = HMPCv2_Options::get_all();
        $opts['style'] = array(
            'switcher_z' => $z,
            'switcher_bg' => $bg,
            'switcher_color' => $color,
            'force_on_hero' => $force,
        );

        HMPCv2_Options::update($opts);

        wp_send_json_success(array('saved' => true, 'style' => $opts['style']));
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
        echo '<a class="nav-tab" data-tab="complete">Complete Page</a>';
        echo '<a class="nav-tab" data-tab="style">Style</a>';
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
                $suggested_type = isset($item['type']) ? (string) $item['type'] : '';
                $woo_key = isset($item['woo_key']) ? (string) $item['woo_key'] : '';
                echo '<div class="hmpcv2-card hmpcv2-suggested" data-post="' . esc_attr($item['id']) . '" data-group="' . esc_attr(isset($group['group']) ? $group['group'] : '') . '" data-suggested-type="' . esc_attr($suggested_type) . '"' . ($woo_key ? ' data-woo-key="' . esc_attr($woo_key) . '"' : '') . '>';
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

        // Complete Page tab (Pages list)
        echo '<div id="hmpcv2-tab-complete" class="hmpcv2-tab">';
        echo '<p>Pages list: view translation groups and open/edit/create per language.</p>';

        echo '<div class="hmpcv2-card">';
        echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
        echo '<button class="button" type="button" id="hmpcv2-complete-refresh">Refresh list</button>';
        echo '<label style="display:inline-flex;gap:6px;align-items:center">';
        echo '<input type="checkbox" id="hmpcv2-complete-only-grouped" checked="checked" />';
        echo '<span class="hmpcv2-small">Only translated/grouped pages</span>';
        echo '</label>';
        echo '<span class="hmpcv2-small">Sorted by last modified. Showing 50 per page.</span>';
        echo '</div>';

        echo '<div id="hmpcv2-complete-list" style="margin-top:12px"></div>';

        echo '<div style="margin-top:12px">';
        echo '<button class="button" type="button" id="hmpcv2-complete-loadmore" style="display:none">Load more</button>';
        echo '<span id="hmpcv2-complete-loading" class="hmpcv2-small" style="display:none;margin-left:8px">Loading…</span>';
        echo '</div>';

        echo '</div>'; // card
        echo '</div>'; // tab

        // Style tab
        echo '<div id="hmpcv2-tab-style" class="hmpcv2-tab">';
        echo '<p>Fix language dropdown visibility on hero banners and control switcher look.</p>';
        echo '<p class="hmpcv2-small">Shortcodes: <code>[hmpc_lang_switcher show_codes="1"]</code> <code>[hmpc_lang_dropdown show_codes="0"]</code></p>';

        $all = HMPCv2_Options::get_all();
        $style = isset($all['style']) && is_array($all['style']) ? $all['style'] : array();

        $z = isset($style['switcher_z']) ? (int)$style['switcher_z'] : 99999;
        $bg = isset($style['switcher_bg']) ? (string)$style['switcher_bg'] : 'rgba(0,0,0,0.35)';
        $color = isset($style['switcher_color']) ? (string)$style['switcher_color'] : '#ffffff';
        $force = !empty($style['force_on_hero']) ? 1 : 0;

        echo '<div class="hmpcv2-card">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label>Switcher z-index</label></th><td><input type="number" id="hmpcv2-style-z" value="' . esc_attr($z) . '" /></td></tr>';
        echo '<tr><th scope="row"><label>Switcher background</label></th><td><input type="text" id="hmpcv2-style-bg" class="regular-text" value="' . esc_attr($bg) . '" /></td></tr>';
        echo '<tr><th scope="row"><label>Switcher text color</label></th><td><input type="text" id="hmpcv2-style-color" class="regular-text" value="' . esc_attr($color) . '" /></td></tr>';
        echo '<tr><th scope="row"><label>Force visible on hero</label></th><td><label><input type="checkbox" id="hmpcv2-style-force" ' . checked(1, $force, false) . ' /> Enable</label></td></tr>';
        echo '</table>';

        echo '<p><button class="button button-primary" type="button" id="hmpcv2-style-save">Save Style</button></p>';
        echo '<hr style="margin:16px 0;">';
        echo '<div class="hmpcv2-small">';
        echo '<strong>Shortcodes</strong><br>';
        echo '<code>[hmpc_lang_switcher]</code> — link buttons<br>';
        echo '<code>[hmpc_lang_dropdown]</code> — dropdown selector<br>';
        echo '<code>[hmpc_lang_dropdown show_codes="1"]</code> — dropdown with language codes';
        echo '</div>';
        echo '</div>';

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

    private static function ensure_translation_post($source_id, $target_lang, $enabled, $default) {
        $group = HMPCv2_Translations::get_group($source_id);

        if ($group === '') {
            $group = HMPCv2_Translations::generate_group_id();
            HMPCv2_Translations::set_group($source_id, $group);
            $src_lang = HMPCv2_Translations::get_lang($source_id);
            if (!$src_lang) HMPCv2_Translations::set_lang($source_id, $default);
        }

        $map = HMPCv2_Translations::get_group_map($group, $enabled);
        if (!empty($map[$target_lang])) return (int)$map[$target_lang];

        // yoksa: mevcut ajax_create_translation mantığını aynı class içinde tekrar kullan
        // en pratik: ajax_create_translation’daki kopyalama blokunu private metoda alıp burada çağır.
        return (int) self::duplicate_as_translation($source_id, $target_lang, $group);
    }

    private static function duplicate_as_translation($source_id, $target_lang, $group) {
        $source = get_post($source_id);
        if (!$source) return 0;

        $new_id = wp_insert_post(array(
            'post_type'      => $source->post_type,
            'post_status'    => $source->post_status ? (string) $source->post_status : 'draft',
            'post_title'     => $source->post_title . ' [' . strtoupper($target_lang) . ']',
            'post_content'   => (string) $source->post_content,
            'post_excerpt'   => (string) $source->post_excerpt,
            'post_author'    => (int) $source->post_author,
            'post_parent'    => (int) $source->post_parent,
            'menu_order'     => (int) $source->menu_order,
            'comment_status' => (string) $source->comment_status,
            'ping_status'    => (string) $source->ping_status,
        ), true);

        if (is_wp_error($new_id) || !$new_id) return 0;

        $thumb_id = get_post_thumbnail_id($source_id);
        if ($thumb_id) set_post_thumbnail($new_id, $thumb_id);

        $all_meta = get_post_meta($source_id);
        $skip_keys = array('_edit_lock','_edit_last','wp_old_slug');
        foreach ($all_meta as $meta_key => $values) {
            if (in_array($meta_key, $skip_keys, true)) continue;
            if (strpos($meta_key, '_hmpcv2_') === 0) continue;
            delete_post_meta($new_id, $meta_key);
            foreach ((array) $values as $v) add_post_meta($new_id, $meta_key, maybe_unserialize($v));
        }

        // Elementor meta (aynı)
        $src_el_data_raw = get_post_meta($source_id, '_elementor_data', true);
        $src_el_page_settings = get_post_meta($source_id, '_elementor_page_settings', true);
        $src_el_version = get_post_meta($source_id, '_elementor_version', true);

        if (!empty($src_el_data_raw) || $src_el_data_raw === '0') update_post_meta($new_id, '_elementor_data', $src_el_data_raw);
        else delete_post_meta($new_id, '_elementor_data');

        if (!empty($src_el_page_settings) || $src_el_page_settings === '0') update_post_meta($new_id, '_elementor_page_settings', $src_el_page_settings);
        else delete_post_meta($new_id, '_elementor_page_settings');

        if (!empty($src_el_version) || $src_el_version === '0') update_post_meta($new_id, '_elementor_version', $src_el_version);
        else delete_post_meta($new_id, '_elementor_version');

        update_post_meta($new_id, '_elementor_edit_mode', 'builder');

        if (class_exists('Elementor\\Plugin')) {
            $elementor = \Elementor\Plugin::$instance;
            if (isset($elementor->db) && method_exists($elementor->db, 'copy_elementor_meta')) {
                $elementor->db->copy_elementor_meta($source_id, $new_id);
            }
        }

        $taxes = get_object_taxonomies($source->post_type, 'names');
        if (!empty($taxes)) {
            foreach ($taxes as $tax) {
                $term_ids = wp_get_object_terms($source_id, $tax, array('fields' => 'ids'));
                if (!is_wp_error($term_ids)) wp_set_object_terms($new_id, $term_ids, $tax, false);
            }
        }

        HMPCv2_Translations::set_group($new_id, $group);
        HMPCv2_Translations::set_lang($new_id, $target_lang);

        if (class_exists('Elementor\\Plugin')) {
            do_action('elementor/core/files/clear_cache');
            $elementor = \Elementor\Plugin::$instance;
            if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) $elementor->files_manager->clear_cache();
            if (isset($elementor->posts_css_manager) && method_exists($elementor->posts_css_manager, 'clear_cache_for_post')) $elementor->posts_css_manager->clear_cache_for_post($new_id);
            if (class_exists('Elementor\\Core\\Files\\CSS\\Post')) {
                $post_css = new \Elementor\Core\Files\CSS\Post($new_id);
                if (method_exists($post_css, 'update')) $post_css->update();
            }
        }

        clean_post_cache($new_id);
        return (int)$new_id;
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
            $suggested[] = array(
                'id' => $front,
                'label' => 'Front Page',
                'type' => 'wp_page',
            );
        }

        $posts_page = (int)get_option('page_for_posts');
        if ($posts_page > 0 && get_post($posts_page)) {
            $suggested[] = array(
                'id' => $posts_page,
                'label' => 'Posts Page',
                'type' => 'wp_page',
            );
        }

        $woo_pages = array(
            'shop' => array(
                'label' => 'Shop',
                'id' => (int)get_option('woocommerce_shop_page_id'),
            ),
            'cart' => array(
                'label' => 'Cart',
                'id' => (int)get_option('woocommerce_cart_page_id'),
            ),
            'checkout' => array(
                'label' => 'Checkout',
                'id' => (int)get_option('woocommerce_checkout_page_id'),
            ),
            'myaccount' => array(
                'label' => 'My Account',
                'id' => (int)get_option('woocommerce_myaccount_page_id'),
            ),
        );

        foreach ($woo_pages as $woo_key => $data) {
            $pid = isset($data['id']) ? (int) $data['id'] : 0;
            if ($pid > 0 && get_post($pid)) {
                $suggested[] = array(
                    'id' => $pid,
                    'label' => $data['label'] . ' Page',
                    'type' => 'woo_core',
                    'woo_key' => $woo_key,
                );
            }
        }

        return $suggested;
    }
}
