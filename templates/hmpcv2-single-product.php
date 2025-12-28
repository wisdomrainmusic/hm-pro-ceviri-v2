<?php
/**
 * HMPCv2 Single Product Wrapper
 * Purpose: render Woo single product with normal theme header/footer (NOT header-shop).
 */
if (!defined('ABSPATH')) exit;

get_header();

do_action('woocommerce_before_main_content');

if (function_exists('woocommerce_content')) {
    woocommerce_content();
} else {
    // Fallback: if Woo not loaded for some reason.
    while (have_posts()) {
        the_post();
        the_content();
    }
}

do_action('woocommerce_after_main_content');

get_footer();
