<?php
if (!defined('ABSPATH')) exit;

final class HMPCv2_Woo {

	public static function init() {
		if (!class_exists('WooCommerce')) return;

		// Admin UI for products
		add_action('add_meta_boxes', array(__CLASS__, 'add_product_metabox'), 30);
		add_action('save_post_product', array(__CLASS__, 'save_product_metabox'), 10, 2);
		
		// Frontend overrides
		add_filter('the_title', array(__CLASS__, 'filter_product_title'), 20, 2);
		add_filter('the_content', array(__CLASS__, 'filter_product_content'), 20);
		add_filter('woocommerce_short_description', array(__CLASS__, 'filter_product_short_description'), 20);
		add_action('wp_footer', array(__CLASS__, 'debug_footer_comment'), 9999);

		// Attribute label/value translation (display layer)
		add_filter('woocommerce_attribute_label', array(__CLASS__, 'filter_attribute_label'), 20, 3);
		add_filter('woocommerce_display_product_attributes', array(__CLASS__, 'filter_display_attributes'), 20, 2);
	}

	// ---------- Meta keys ----------
	private static function k($lang, $field) {
		return '_hmpcv2_' . strtolower($lang) . '_' . $field;
	}

	// ---------- Admin ----------
	public static function add_product_metabox() {
		add_meta_box(
			'hmpcv2_product_texts',
			'HMPC v2 Product Languages',
			array(__CLASS__, 'render_product_metabox'),
			'product',
			'normal',
			'high'
		);
	}

	public static function render_product_metabox($post) {
		if (!$post || empty($post->ID)) return;

		$enabled = HMPCv2_Langs::enabled_langs();
		$default = HMPCv2_Langs::default_lang();

		wp_nonce_field('hmpcv2_product_texts_save', 'hmpcv2_product_texts_nonce');

		echo '<p style="margin-top:0;">Single product, multi-language texts (no duplication). Stock/price/variations stay the same.</p>';

		foreach ($enabled as $code) {
			if ($code === $default) continue; // Default language already uses WP native fields

			$title = (string) get_post_meta($post->ID, self::k($code, 'title'), true);
			$short = (string) get_post_meta($post->ID, self::k($code, 'short'), true);
			$desc  = (string) get_post_meta($post->ID, self::k($code, 'desc'), true);

			$attr_labels = get_post_meta($post->ID, self::k($code, 'attr_labels'), true);
			if (!is_array($attr_labels)) $attr_labels = array();

			$attr_values = get_post_meta($post->ID, self::k($code, 'attr_values'), true);
			if (!is_array($attr_values)) $attr_values = array();

			echo '<hr style="margin:16px 0;">';
			echo '<h3 style="margin:0 0 10px;">' . esc_html(strtoupper($code) . ' — ' . HMPCv2_Langs::label($code)) . '</h3>';

			echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Title</label>';
			echo '<input type="text" style="width:100%;" name="hmpcv2_prod[' . esc_attr($code) . '][title]" value="' . esc_attr($title) . '" placeholder="Translated title..." /></p>';

			echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Short description</label>';
			echo '<textarea style="width:100%;min-height:70px;" name="hmpcv2_prod[' . esc_attr($code) . '][short]" placeholder="Translated short description...">' . esc_textarea($short) . '</textarea></p>';

			echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Description</label>';
			echo '<textarea style="width:100%;min-height:140px;" name="hmpcv2_prod[' . esc_attr($code) . '][desc]" placeholder="Translated description...">' . esc_textarea($desc) . '</textarea></p>';

			// Attributes (simple key-value translation map)
			echo '<p style="margin:12px 0 6px;font-weight:600;">Attributes (optional)</p>';
			echo '<p style="margin:0 0 8px;color:#666;">Translate attribute labels and values. Example: Beden → Size, Renk → Color.</p>';

			echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';

			// Labels map textarea (one per line: original=translated)
			$labels_lines = '';
			foreach ($attr_labels as $orig => $tr) {
				$labels_lines .= $orig . '=' . $tr . "\n";
			}
			echo '<div style="flex:1;min-width:280px;">';
			echo '<label style="display:block;font-weight:600;margin-bottom:4px;">Attribute labels map</label>';
			echo '<textarea style="width:100%;min-height:120px;" name="hmpcv2_prod[' . esc_attr($code) . '][attr_labels]">' . esc_textarea($labels_lines) . '</textarea>';
			echo '<div style="font-size:12px;color:#666;">One per line: Original=Translated</div>';
			echo '</div>';

			// Values map textarea (one per line: original=translated)
			$values_lines = '';
			foreach ($attr_values as $orig => $tr) {
				$values_lines .= $orig . '=' . $tr . "\n";
			}
			echo '<div style="flex:1;min-width:280px;">';
			echo '<label style="display:block;font-weight:600;margin-bottom:4px;">Attribute values map</label>';
			echo '<textarea style="width:100%;min-height:120px;" name="hmpcv2_prod[' . esc_attr($code) . '][attr_values]">' . esc_textarea($values_lines) . '</textarea>';
			echo '<div style="font-size:12px;color:#666;">One per line: Original=Translated (e.g., Small=Klein)</div>';
			echo '</div>';

			echo '</div>';
		}

		echo '<hr style="margin:16px 0;">';
		echo '<p style="margin:0;color:#666;">Default language uses native WooCommerce fields. Other languages override on frontend when URL prefix matches.</p>';
	}

	public static function save_product_metabox($post_id, $post) {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!$post || empty($post_id)) return;
		if (!current_user_can('edit_post', $post_id)) return;

		$nonce = isset($_POST['hmpcv2_product_texts_nonce']) ? (string)$_POST['hmpcv2_product_texts_nonce'] : '';
		if (!wp_verify_nonce($nonce, 'hmpcv2_product_texts_save')) return;

		$enabled = HMPCv2_Langs::enabled_langs();
		$default = HMPCv2_Langs::default_lang();

		$all = isset($_POST['hmpcv2_prod']) && is_array($_POST['hmpcv2_prod']) ? $_POST['hmpcv2_prod'] : array();

		foreach ($enabled as $code) {
			if ($code === $default) continue;

			$block = isset($all[$code]) && is_array($all[$code]) ? $all[$code] : array();

			$title = isset($block['title']) ? sanitize_text_field((string)$block['title']) : '';
			$short = isset($block['short']) ? wp_kses_post((string)$block['short']) : '';
			$desc  = isset($block['desc']) ? wp_kses_post((string)$block['desc']) : '';

			update_post_meta($post_id, self::k($code, 'title'), $title);
			update_post_meta($post_id, self::k($code, 'short'), $short);
			update_post_meta($post_id, self::k($code, 'desc'), $desc);

			$labels_map = self::parse_map(isset($block['attr_labels']) ? (string)$block['attr_labels'] : '');
			$values_map = self::parse_map(isset($block['attr_values']) ? (string)$block['attr_values'] : '');

			update_post_meta($post_id, self::k($code, 'attr_labels'), $labels_map);
			update_post_meta($post_id, self::k($code, 'attr_values'), $values_map);
		}
	}

	private static function parse_map($text) {
		$text = (string)$text;
		$lines = preg_split('/\r\n|\r|\n/', $text);
		$out = array();

		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') continue;
			if (strpos($line, '=') === false) continue;

			list($a, $b) = array_map('trim', explode('=', $line, 2));
			if ($a === '' || $b === '') continue;
			$out[$a] = $b;
		}

		return $out;
	}

	// ---------- Frontend ----------
	private static function is_frontend_product_context($post_id) {
		if (is_admin()) return false;
		if (!function_exists('is_product') || !is_product()) return false;
		if ((int)get_queried_object_id() !== (int)$post_id) return false;

		$post = get_post($post_id);
		if (!$post || $post->post_type !== 'product') return false;

		return true;
	}

	private static function current_lang_non_default() {
		$lang = HMPCv2_Router::current_lang();
		$default = HMPCv2_Langs::default_lang();
		if ($lang === $default) return '';
		return $lang;
	}

	public static function filter_product_title($title, $post_id) {
		if (!self::is_frontend_product_context($post_id)) return $title;

		$lang = self::current_lang_non_default();
		if ($lang === '') return $title;

		$tr = (string) get_post_meta($post_id, self::k($lang, 'title'), true);
		return $tr !== '' ? $tr : $title;
	}

	public static function filter_product_content($content) {
		if (is_admin()) return $content;
		if (!function_exists('is_product') || !is_product()) return $content;

		// IMPORTANT:
		// On product pages, builders/themes may call the_content() for non-product template posts.
		// Only translate when the_content() is applied to the queried product post itself.
		global $post;
		$qid = (int) get_queried_object_id();
		if ($qid < 1) return $content;
		if (!$post || (int)$post->ID !== $qid) return $content;
		if ((string)$post->post_type !== 'product') return $content;

		$post_id = $qid;

		$lang = self::current_lang_non_default();
		if ($lang === '') return $content;

		$tr = (string) get_post_meta($post_id, self::k($lang, 'desc'), true);
		return $tr !== '' ? $tr : $content;
	}

	public static function filter_product_short_description($short) {
		if (is_admin()) return $short;
		if (!function_exists('is_product') || !is_product()) return $short;

		// IMPORTANT:
		// Keep short description override scoped to the actual queried product,
		// not builder/template posts rendered on the same request.
		global $post;
		$qid = (int) get_queried_object_id();
		if ($qid < 1) return $short;
		if (!$post || (int)$post->ID !== $qid) return $short;
		if ((string)$post->post_type !== 'product') return $short;

		$post_id = $qid;

		$lang = self::current_lang_non_default();
		if ($lang === '') return $short;

		$tr = (string) get_post_meta($post_id, self::k($lang, 'short'), true);
		return $tr !== '' ? $tr : $short;
	}

	public static function filter_attribute_label($label, $name, $product) {
		if (is_admin()) return $label;
		if (!is_singular('product')) return $label;

		$lang = self::current_lang_non_default();
		if ($lang === '') return $label;

		$post_id = (int) $product->get_id();
		$map = get_post_meta($post_id, self::k($lang, 'attr_labels'), true);
		if (!is_array($map)) return $label;

		// Try match by current label (e.g., "Beden") or by raw name
		if (isset($map[$label])) return $map[$label];
		if (is_string($name) && isset($map[$name])) return $map[$name];

		return $label;
	}

	public static function filter_display_attributes($attributes, $product) {
		if (is_admin()) return $attributes;
		if (!is_singular('product')) return $attributes;

		$lang = self::current_lang_non_default();
		if ($lang === '') return $attributes;

		$post_id = (int) $product->get_id();
		$values_map = get_post_meta($post_id, self::k($lang, 'attr_values'), true);
		if (!is_array($values_map) || empty($values_map)) return $attributes;

		// Replace values inside rendered attributes array
		foreach ($attributes as $key => $attr) {
			if (!isset($attr['value'])) continue;
			$val = (string) $attr['value'];

			// Replace comma separated values
			$parts = array_map('trim', explode(',', $val));
			$new_parts = array();

			foreach ($parts as $p) {
				$new_parts[] = isset($values_map[$p]) ? $values_map[$p] : $p;
			}

			$attributes[$key]['value'] = implode(', ', $new_parts);
		}

		return $attributes;
	}

	public static function debug_footer_comment() {
		if (is_admin()) return;
		if (!function_exists('is_product') || !is_product()) return;

		$post_id = (int) get_queried_object_id();
		if ($post_id < 1) return;

		$lang = HMPCv2_Router::current_lang();
		$default = HMPCv2_Langs::default_lang();

		$en_title = (string) get_post_meta($post_id, '_hmpcv2_en_title', true);
		$en_short = (string) get_post_meta($post_id, '_hmpcv2_en_short', true);
		$en_desc  = (string) get_post_meta($post_id, '_hmpcv2_en_desc', true);

		$data = array(
			'url' => (isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : ''),
			'current_lang' => $lang,
			'default_lang' => $default,
			'queried_id' => $post_id,
			'en_title_set' => ($en_title !== ''),
			'en_short_set' => ($en_short !== ''),
			'en_desc_set' => ($en_desc !== ''),
		);

		echo "\n<!-- HMPCv2 PRODUCT DEBUG: " . esc_html(wp_json_encode($data)) . " -->\n";
	}
}
