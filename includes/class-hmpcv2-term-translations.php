<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Term_Translations {

    public static function init() {
        // Safely adjust term objects without polluting cached objects
        add_filter('get_term', array(__CLASS__, 'filter_get_term'), 20, 2);
        add_filter('get_terms', array(__CLASS__, 'filter_get_terms'), 20, 4);

        // Ensure archive title uses translated queried object
        add_action('wp', array(__CLASS__, 'maybe_override_queried_object'), 20);
    }

    private static function current_lang() {
        // Use router if available; fallback to default
        if (class_exists('HMPCv2_Router') && method_exists('HMPCv2_Router', 'current_lang')) {
            $lang = HMPCv2_Router::current_lang();
            if ($lang) return $lang;
        }
        return HMPCv2_Langs::default_lang();
    }

    private static function get_term_translation($term_id, $lang) {
        $all = HMPCv2_Options::get_term_translations();
        $term_id = (int)$term_id;

        if (!isset($all[$term_id]) || !is_array($all[$term_id])) return null;
        if (!isset($all[$term_id][$lang]) || !is_array($all[$term_id][$lang])) return null;

        $t = $all[$term_id][$lang];

        $name = isset($t['name']) ? (string)$t['name'] : '';
        $desc = isset($t['description']) ? (string)$t['description'] : '';

        // slug kept for later URL work; do not change WP routing now
        $slug = isset($t['slug']) ? (string)$t['slug'] : '';

        return array(
            'name' => $name,
            'description' => $desc,
            'slug' => $slug,
        );
    }

    public static function filter_get_term($term, $taxonomy) {
        if (!$term || is_wp_error($term) || !is_object($term)) return $term;

        $lang = self::current_lang();
        $default = HMPCv2_Langs::default_lang();

        if ($lang === $default) return $term;

        $tr = self::get_term_translation($term->term_id, $lang);
        if (!$tr) return $term;

        // clone so we do not mutate cached object
        $clone = clone $term;

        if (!empty($tr['name'])) {
            $clone->name = $tr['name'];
        }
        if ($tr['description'] !== '') {
            $clone->description = $tr['description'];
        }

        return $clone;
    }

    public static function filter_get_terms($terms, $taxonomies, $args, $term_query) {
        if (!is_array($terms) || empty($terms)) return $terms;

        $lang = self::current_lang();
        $default = HMPCv2_Langs::default_lang();
        if ($lang === $default) return $terms;

        $out = array();
        foreach ($terms as $t) {
            if (!is_object($t) || empty($t->term_id)) {
                $out[] = $t;
                continue;
            }

            $tr = self::get_term_translation((int)$t->term_id, $lang);
            if (!$tr) {
                $out[] = $t;
                continue;
            }

            $clone = clone $t;
            if (!empty($tr['name'])) $clone->name = $tr['name'];
            if ($tr['description'] !== '') $clone->description = $tr['description'];

            $out[] = $clone;
        }

        return $out;
    }

    public static function maybe_override_queried_object() {
        if (!is_tax() && !is_category() && !is_tag()) return;

        global $wp_query;
        if (empty($wp_query) || empty($wp_query->queried_object) || !is_object($wp_query->queried_object)) return;

        $obj = $wp_query->queried_object;
        if (empty($obj->term_id)) return;

        $lang = self::current_lang();
        $default = HMPCv2_Langs::default_lang();
        if ($lang === $default) return;

        $tr = self::get_term_translation((int)$obj->term_id, $lang);
        if (!$tr) return;

        $clone = clone $obj;
        if (!empty($tr['name'])) $clone->name = $tr['name'];
        if ($tr['description'] !== '') $clone->description = $tr['description'];

        $wp_query->queried_object = $clone;
    }
}
