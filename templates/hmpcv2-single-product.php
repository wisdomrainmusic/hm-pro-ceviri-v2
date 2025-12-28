<?php
/**
 * HMPCv2 Single Product Wrapper (Elementor-first)
 * Goal: For prefixed product URLs, render Elementor Theme Builder "Single" template
 * so layout matches the site’s real product design.
 */
if (!defined('ABSPATH')) exit;

get_header();

// If Elementor Theme Builder can render a "single" location, use it.
// This will output the correct Single Product template (same as TR) when conditions match.
$rendered = false;

if (function_exists('elementor_theme_do_location')) {
    ob_start();
    $ok = elementor_theme_do_location('single');
    $out = ob_get_clean();

    // elementor_theme_do_location returns true/false; also ensure output is non-empty
    if ($ok && trim((string) $out) !== '') {
        echo $out;
        $rendered = true;
    }
}

// Fallback: if Elementor doesn't render anything, use Woo content in Astra-like wrappers.
if (!$rendered) {

    if (function_exists('astra_primary_content_top')) {
        astra_primary_content_top();
    }

    echo '<div id="primary" class="content-area primary">';
    echo '<main id="main" class="site-main">';

    do_action('woocommerce_before_main_content');

    if (function_exists('woocommerce_content')) {
        woocommerce_content();
    } else {
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
}

get_footer();
