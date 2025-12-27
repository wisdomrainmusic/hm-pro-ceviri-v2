<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Switcher {

	public static function init() {
		add_shortcode('hmpc_lang_switcher', array(__CLASS__, 'shortcode'));
		add_shortcode('hmpc_lang_dropdown', array(__CLASS__, 'shortcode_dropdown'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'));
	}

        public static function styles() {
                $opts = HMPCv2_Options::get_all();
                $style = isset($opts['style']) && is_array($opts['style']) ? $opts['style'] : array();

                $z = isset($style['switcher_z']) ? (int)$style['switcher_z'] : 99999;
                $bg = isset($style['switcher_bg']) ? (string)$style['switcher_bg'] : 'rgba(0,0,0,0.35)';
                $color = isset($style['switcher_color']) ? (string)$style['switcher_color'] : '#ffffff';
                $force = !empty($style['force_on_hero']) ? 1 : 0;

                $css = ''
                . '.hmpc-switcher{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:' . $z . '}'
                . '.hmpc-switcher a{padding:6px 10px;border:1px solid rgba(0,0,0,.15);border-radius:6px;text-decoration:none;'
                . 'background:' . $bg . ';color:' . $color . ';}'
                . '.hmpc-switcher a.is-active{font-weight:600}'
                . '.hmpc-dropdown{display:inline-block;position:relative}'
                . '.hmpc-dropdown-select{padding:6px 10px;border:1px solid rgba(0,0,0,.15);border-radius:6px;'
                . 'background:' . $bg . ';color:' . $color . ';}';

                if ($force) {
                        // Hero overlay / header stacking context fix
                        $css .= '.site-header{position:relative;z-index:9999}'
                              . '.elementor-background-overlay{z-index:1}';
                }

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

	public static function shortcode_dropdown($atts) {
		$atts = shortcode_atts(array(
			'show_codes' => '0',
			'class' => '',
		), $atts, 'hmpc_lang_dropdown');

		$enabled = HMPCv2_Langs::enabled_langs();
		$current = HMPCv2_Router::current_lang();

		$cls = 'hmpc-dropdown';
		if (!empty($atts['class'])) {
			$classes = preg_split('/\s+/', (string) $atts['class']);
			$classes = array_filter(array_map('sanitize_html_class', $classes));
			if (!empty($classes)) {
				$cls .= ' ' . implode(' ', $classes);
			}
		}

		$html  = '<div class="' . esc_attr($cls) . '">';
		$html .= '<select class="hmpc-dropdown-select" aria-label="Language selector" onchange="if(this.value){window.location.href=this.value;}">';

		foreach ($enabled as $code) {
			$label = ($atts['show_codes'] === '1') ? strtoupper($code) : HMPCv2_Langs::label($code);
			$url = HMPCv2_Resolver::switch_url_for_current_context($code);
			$html .= '<option value="' . esc_url($url) . '" ' . selected($code, $current, false) . '>' . esc_html($label) . '</option>';
		}

		$html .= '</select></div>';
		return $html;
	}
}
