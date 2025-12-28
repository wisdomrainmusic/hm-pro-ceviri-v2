<?php
/**
 * HMPCv2 Single Product Wrapper (Astra-safe)
 * Renders prefixed product pages with the same structural wrappers Astra expects.
 */
if (!defined('ABSPATH')) exit;

// Ensure global post is set.
global $post;

// Normal theme header (NOT shop header)
get_header();

// Astra expects these wrappers for layout/container.
if (function_exists('astra_primary_content_top')) {
    astra_primary_content_top();
}

// Match Astra's main content structure.
echo '<div id="primary" class="content-area primary">';
echo '<main id="main" class="site-main">';

// Woo hooks + content.
do_action('woocommerce_before_main_content');

if (function_exists('woocommerce_content')) {
    woocommerce_content();
} else {
    // Fallback
    while (have_posts()) {
        the_post();
        the_content();
    }
}

do_action('woocommerce_after_main_content');

echo '</main>';
echo '</div>';

if (function_exists('astra_primary_content_bottom')) {
    astra_primary_content_bottom();
}

get_footer();
