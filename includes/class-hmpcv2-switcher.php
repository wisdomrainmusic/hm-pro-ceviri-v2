<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Switcher {

	public static function init() {
		add_shortcode('hmpc_lang_switcher', array(__CLASS__, 'shortcode'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'));
	}

	public static function styles() {
		$css = '.hmpc-switcher{display:flex;gap:8px;flex-wrap:wrap}.hmpc-switcher a{padding:6px 10px;border:1px solid rgba(0,0,0,.15);border-radius:6px;text-decoration:none}.hmpc-switcher a.is-active{font-weight:600}';
		wp_register_style('hmpc-switcher-inline', false, array(), HMPCV2_VERSION);
		wp_enqueue_style('hmpc-switcher-inline');
		wp_add_inline_style('hmpc-switcher-inline', $css);
	}

	public static function shortcode($atts) {
		$atts = shortcode_atts(array(
			'show_codes' => '1',
		), $atts, 'hmpc_lang_switcher');

		$enabled = HMPCv2_Langs::enabled_langs();
		$current = HMPCv2_Router::current_lang();

		$html = '<div class="hmpc-switcher" role="navigation" aria-label="Language switcher">';

		foreach ($enabled as $code) {
			$is_active = ($code === $current);
			$label = ($atts['show_codes'] === '1') ? strtoupper($code) : HMPCv2_Langs::label($code);

			$url = HMPCv2_Resolver::switch_url_for_current_context($code);

			$html .= sprintf(
				'<a class="%s" href="%s">%s</a>',
				$is_active ? 'is-active' : '',
				esc_url($url),
				esc_html($label)
			);
		}

		$html .= '</div>';
		return $html;
	}
}
