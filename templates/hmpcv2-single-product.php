<?php
/**
 * HMPCv2 Single Product Wrapper
 * Purpose: render Woo single product with normal theme header/footer (NOT header-shop).
 */
if (!defined('ABSPATH')) exit;

get_header();

// Astra wrapper aç
if (function_exists('astra_primary_content_top')) {
    astra_primary_content_top();
}

// Astra container / primary wrapper (çok kritik)
echo '<div id="primary" class="content-area">';
echo '<main id="main" class="site-main">';

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

echo '</main>';
echo '</div>';

// Astra wrapper kapa
if (function_exists('astra_primary_content_bottom')) {
    astra_primary_content_bottom();
}

get_footer();
