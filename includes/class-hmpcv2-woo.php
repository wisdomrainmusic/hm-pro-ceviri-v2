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
		add_filter('the_title', array(__CLASS__, 'filter_woo_core_page_title'), 21, 2);
		add_filter('the_content', array(__CLASS__, 'filter_product_content'), 20);
		add_filter('woocommerce_short_description', array(__CLASS__, 'filter_product_short_description'), 20);
		add_filter('woocommerce_get_endpoint_url', array(__CLASS__, 'filter_woocommerce_endpoint_url'), 10, 4);
		add_filter('woocommerce_get_myaccount_page_permalink', array(__CLASS__, 'filter_myaccount_page_permalink'), 10, 1);

		// Attribute label/value translation (display layer)
		add_filter('woocommerce_attribute_label', array(__CLASS__, 'filter_attribute_label'), 20, 3);
		add_filter('woocommerce_display_product_attributes', array(__CLASS__, 'filter_display_attributes'), 20, 2);

		// Woo core gettext overrides
		add_filter('gettext', array(__CLASS__, 'woo_gettext_override'), 10, 3);
		add_filter('gettext_with_context', array(__CLASS__, 'woo_gettext_with_context_override'), 10, 4);

		// My Account menu item labels (Addresses etc.)
		add_filter('woocommerce_account_menu_items', array(__CLASS__, 'filter_myaccount_menu_items'), 20, 1);

		// Checkout: payment gateway descriptions/instructions (DB-based)
		add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'filter_available_payment_gateways'), 20, 1);

		// Checkout: privacy policy text (DB-based)
		add_filter('woocommerce_get_privacy_policy_text', array(__CLASS__, 'filter_privacy_policy_text'), 20, 1);
		add_filter('woocommerce_checkout_privacy_policy_text', array(__CLASS__, 'filter_privacy_policy_text'), 20, 1);
	}

	public static function filter_available_payment_gateways($gateways) {
		if (is_admin()) return $gateways;

		$lang = self::current_lang_code();
		if ($lang === '') return $gateways;

		// group: checkout, key: bacs_description / bacs_instructions
		$desc = self::misc_get($lang, 'checkout', 'bacs_description');
		$instr = self::misc_get($lang, 'checkout', 'bacs_instructions');

		// fallback: EN misc (master)
		if ($desc === '') $desc = self::misc_get('en', 'checkout', 'bacs_description');
		if ($instr === '') $instr = self::misc_get('en', 'checkout', 'bacs_instructions');

		if (isset($gateways['bacs']) && is_object($gateways['bacs'])) {
			if ($desc !== '') $gateways['bacs']->description = $desc;
			if ($instr !== '') $gateways['bacs']->instructions = $instr;
		}

		return $gateways;
	}

	public static function filter_privacy_policy_text($text) {
		if (is_admin()) return $text;

		$lang = self::current_lang_code();
		if ($lang === '') return $text;

		$v = self::misc_get($lang, 'checkout', 'privacy_policy_text');

		// fallback: EN master
		if ($v === '') $v = self::misc_get('en', 'checkout', 'privacy_policy_text');

		return ($v !== '') ? $v : $text;
	}

	// ---------- Meta keys ----------
	private static function k($lang, $field) {
		return '_hmpcv2_' . strtolower($lang) . '_' . $field;
	}

	private static function woo_core_page_ids(): array {
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

	private static function is_woo_core_page_id(int $page_id): bool {
		return in_array($page_id, self::woo_core_page_ids(), true);
	}

	private static function is_on_woo_core_page(): bool {
		if (is_admin()) return false;

		// Woo conditional tags (DOĞRU YÖNTEM)
		if (function_exists('is_cart') && is_cart()) return true;
		if (function_exists('is_checkout') && is_checkout()) return true;
		if (function_exists('is_account_page') && is_account_page()) return true;
		if (function_exists('is_shop') && is_shop()) return true;

		return false;
	}

	private static function current_lang_code(): string {
		$uri = $_SERVER['REQUEST_URI'] ?? '';

		if (preg_match('#^/([a-z]{2})(/|$)#i', $uri, $m)) {
			return strtolower($m[1]);
		}

		// fallback → varsayılan dil
		return 'tr';
	}

	private static function woo_dict(): array {
		static $cache = null;
		if ($cache !== null) return $cache;
		$dict = get_option('hmpcv2_woo_dict', array());
		$cache = is_array($dict) ? $dict : array();
		return $cache;
	}

	private static function misc_dict(): array {
		$dict = get_option('hmpcv2_misc_dict', array());
		return is_array($dict) ? $dict : array();
	}

	private static function misc_get($lang, $group, $key): string {
		$dict = self::misc_dict();
		if (isset($dict[$lang][$group][$key])) {
			$v = (string) $dict[$lang][$group][$key];
			if ($v !== '') return $v;
		}
		return '';
	}

	private static function dict_key_simple(string $text): string {
		return 's:' . $text;
	}

	private static function dict_key_context(string $context, string $text): string {
		return 'c:' . $context . "\x1F" . $text;
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
		$p = get_post($post_id);
		return ($p && $p->post_type === 'product');
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

	public static function filter_woo_core_page_title($title, $post_id) {
		if (is_admin()) return $title;

		$qid = (int) get_queried_object_id();
		if ($qid <= 0 || (int) $post_id !== $qid) return $title;

		$post = get_post($qid);
		if (!$post || (string) $post->post_type !== 'page') return $title;

		if (!self::is_woo_core_page_id($qid)) return $title;

		$lang = self::current_lang_non_default();
		if ($lang === '') return $title;

		$tr = (string) get_post_meta($qid, self::k($lang, 'title'), true);
		if ($tr !== '') return $tr;

		$fallback_key = '_hmpcv2_title_' . strtolower($lang);
		$tr2 = (string) get_post_meta($qid, $fallback_key, true);
		if ($tr2 !== '') return $tr2;

		// EN fallback for core Woo pages when the WP page title is Turkish
		if ($lang === 'en') {
			$shop_id     = (int) get_option('woocommerce_shop_page_id');
			$cart_id     = (int) get_option('woocommerce_cart_page_id');
			$checkout_id = (int) get_option('woocommerce_checkout_page_id');
			$account_id  = (int) get_option('woocommerce_myaccount_page_id');

			if ($qid === $shop_id) return 'Shop';
			if ($qid === $cart_id) return 'Cart';
			if ($qid === $checkout_id) return 'Checkout';
			if ($qid === $account_id) return 'My account';
		}

		return $title;
	}

	public static function filter_product_content($content) {
		if (is_admin()) return $content;
		if (!function_exists('is_product') || !is_product()) return $content;

		// IMPORTANT:
		// Builders/themes may call the_content() for template posts while on a product page.
		// Only override when the_content() is executed for the queried product post itself.
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
		// Keep short description override scoped to the actual queried product post,
		// not template posts rendered by builders/themes.
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

	public static function filter_attribute_label($label, $name, $maybe_product) {
		if (is_admin()) return $label;
		if (!is_singular('product')) return $label;

		$lang = self::current_lang_non_default();
		if ($lang === '') return $label;

		// Woo sometimes passes taxonomy string instead of product object.
		$resolved_product = null;

		if (is_object($maybe_product) && method_exists($maybe_product, 'get_id')) {
			$resolved_product = $maybe_product;
		} else {
			// Fallback: try global product, then queried object
			if (function_exists('wc_get_product')) {
				global $product;
				$global_product = $product;
				if (is_object($global_product) && method_exists($global_product, 'get_id')) {
					$resolved_product = $global_product;
				} else {
					$qid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
					if ($qid > 0) {
						$p = wc_get_product($qid);
						if (is_object($p) && method_exists($p, 'get_id')) {
							$resolved_product = $p;
						}
					}
				}
			}
		}

		if (!$resolved_product) return $label;

		$post_id = (int) $resolved_product->get_id();
		$map = get_post_meta($post_id, self::k($lang, 'attr_labels'), true);
		if (!is_array($map)) return $label;

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

	public static function filter_myaccount_menu_items($items) {
		if (is_admin()) return $items;

		$lang = self::current_lang_code();
		if ($lang === '') return $items;

		// Woo endpoint -> EN base labels (source of truth)
		$base = array(
			'dashboard' => 'Dashboard',
			'orders' => 'Orders',
			'downloads' => 'Downloads',
			'edit-address' => 'Addresses',
			'edit-account' => 'Account details',
			'customer-logout' => 'Log out',
		);

		foreach ($items as $endpoint => $label) {
			if (!isset($base[$endpoint])) continue;

			$en_label = $base[$endpoint];

			// Try preset first (domain: woocommerce, key: s:<EN label>)
			$dict = self::woo_dict();
			$domain = 'woocommerce';
			$key = self::dict_key_simple($en_label);

			if (isset($dict[$lang][$domain][$key])) {
				$t = (string) $dict[$lang][$domain][$key];
				if ($t !== '') {
					$items[$endpoint] = $t;
					continue;
				}
			}

			// Fallback: if EN -> show EN base label instead of Turkish
			if ($lang === 'en') {
				$items[$endpoint] = $en_label;
			}
		}

		return $items;
	}

	public static function woo_gettext_override($translation, $text, $domain) {
		$lang = self::current_lang_code();
		if (defined('HMPC_DEBUG') && HMPC_DEBUG) {
			error_log('[HMPCv2][woo_gettext] text=' . $text . ' translation=' . $translation);
		}

		if ($lang === '') return $translation;
		if (is_admin()) return $translation;

		$dict = self::woo_dict();
		$key = self::dict_key_simple((string) $text);

		if (isset($dict[$lang][$domain][$key])) {
			$t = (string) $dict[$lang][$domain][$key];
			if ($t !== '') return $t;
		}

		// EN master fallback for Woo domains (prevents Turkish leaks)
		if ($lang === 'en' && in_array($domain, array('woocommerce', 'woocommerce-blocks'), true)) {
			return $text;
		}

		return $translation;
	}

	public static function woo_gettext_with_context_override($translation, $text, $context, $domain) {
		$lang = self::current_lang_code();
		if (defined('HMPC_DEBUG') && HMPC_DEBUG) {
			error_log('[HMPCv2][woo_gettext_context] context=' . $context . ' text=' . $text . ' translation=' . $translation);
		}
		if ($lang === '') return $translation;
		if (is_admin()) return $translation;

		$dict = self::woo_dict();
		$key = self::dict_key_context((string) $context, (string) $text);

		if (isset($dict[$lang][$domain][$key])) {
			$t = (string) $dict[$lang][$domain][$key];
			if ($t !== '') return $t;
		}

		// EN master fallback for contextual gettext too.
		if ($lang === 'en' && in_array($domain, array('woocommerce', 'woocommerce-blocks'), true)) {
			return $text;
		}

		return $translation;
	}

	public static function debug_footer_comment() {
		if (!get_option('hmpcv2_debug_enabled', 0)) return;
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

	public static function filter_woocommerce_endpoint_url($url, $endpoint, $value, $permalink) {
		if (is_admin()) return $url;

		$current = HMPCv2_Router::current_lang();
		$default = HMPCv2_Langs::default_lang();
		$enabled = HMPCv2_Langs::enabled_langs();
		$prefix_default = HMPCv2_Router::prefix_default_lang();

		if (!$current || ($current === $default && !$prefix_default)) {
			return $url;
		}

		$parts = wp_parse_url($url);
		if (!$parts || empty($parts['path'])) {
			return $url;
		}

		$path = '/' . ltrim((string) $parts['path'], '/');
		$trim = trim($path, '/');
		$segments = ($trim === '') ? array() : explode('/', $trim);

		if (!empty($segments)) {
			$maybe = strtolower((string) $segments[0]);
			if (in_array($maybe, $enabled, true)) {
				array_shift($segments);
			}
		}

		$base_path = '/' . implode('/', $segments);
		if ($base_path === '//') {
			$base_path = '/';
		}

		if ($base_path === '/' || $base_path === '') {
			$new_path = '/' . $current . '/';
		} else {
			$new_path = '/' . $current . '/' . ltrim($base_path, '/');
		}

		$new_path = preg_replace('#/+#', '/', $new_path);

		$scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
		$host = $parts['host'] ?? '';
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		$query = isset($parts['query']) ? '?' . $parts['query'] : '';
		$fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

		if ($host === '') {
			return home_url($new_path) . $query . $fragment;
		}

		return $scheme . $host . $port . $new_path . $query . $fragment;
	}

	public static function filter_myaccount_page_permalink($url) {
		if (!is_string($url) || $url === '') return $url;

		$lang = self::current_lang_code();
		if ($lang === 'tr') return $url;

		$parts = wp_parse_url($url);
		if (empty($parts['path'])) return $url;

		$path = $parts['path'];

		if (preg_match('#^/' . preg_quote($lang, '#') . '(/|$)#i', $path)) {
			return $url;
		}

		$path = '/' . $lang . rtrim($path, '/');
		$path = preg_replace('#//+#', '/', $path);

		$rebuilt =
			(isset($parts['scheme']) ? $parts['scheme'] . '://' : '') .
			($parts['host'] ?? '') .
			(isset($parts['port']) ? ':' . $parts['port'] : '') .
			$path .
			(isset($parts['query']) ? '?' . $parts['query'] : '') .
			(isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

		return $rebuilt;
	}
}
