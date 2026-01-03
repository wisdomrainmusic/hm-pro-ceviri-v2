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
                // Shop title: many themes use Woo title hooks (archive) instead of the_title
                add_filter('woocommerce_page_title', array(__CLASS__, 'filter_shop_page_title'), 20, 1);
                add_filter('woocommerce_get_page_title', array(__CLASS__, 'filter_shop_get_page_title'), 20, 2);
                add_filter('the_content', array(__CLASS__, 'filter_product_content'), 20);
                add_filter('woocommerce_short_description', array(__CLASS__, 'filter_product_short_description'), 20);
                // Ensure translated product titles also appear in loops (shop/category widgets) without affecting global page titles
                add_filter('woocommerce_product_get_name', array(__CLASS__, 'filter_wc_product_get_name'), 20, 2);
                add_filter('woocommerce_product_get_title', array(__CLASS__, 'filter_wc_product_get_name'), 20, 2);
                add_filter('woocommerce_product_title', array(__CLASS__, 'filter_wc_product_title'), 20, 2);
		// Cart / Checkout item name + variation meta translation
		add_filter('woocommerce_cart_item_name', array(__CLASS__, 'filter_cart_item_name'), 20, 3);
		add_filter('woocommerce_order_item_name', array(__CLASS__, 'filter_order_item_name'), 20, 2);
		add_filter('woocommerce_get_item_data', array(__CLASS__, 'filter_cart_item_data'), 20, 2);
		add_filter('woocommerce_get_endpoint_url', array(__CLASS__, 'filter_woocommerce_endpoint_url'), 10, 4);
		add_filter('woocommerce_get_myaccount_page_permalink', array(__CLASS__, 'filter_myaccount_page_permalink'), 10, 1);

		// Attribute label/value translation (display layer)
		add_filter('woocommerce_attribute_label', array(__CLASS__, 'filter_attribute_label'), 20, 3);
		add_filter('woocommerce_display_product_attributes', array(__CLASS__, 'filter_display_attributes'), 20, 2);
		// Translate variation option names (e.g., "34 beden" -> "Size 34")
		add_filter('woocommerce_variation_option_name', array(__CLASS__, 'filter_variation_option_name'), 20, 4);
		// Translate variation options HTML (covers swatches plugins like CFVSW)
		add_filter('woocommerce_dropdown_variation_attribute_options_html', array(__CLASS__, 'filter_variation_attribute_options_html'), 99, 2);
		// Translate attribute term names at source (covers swatches plugins that bypass option filters)
		add_filter('get_term', array(__CLASS__, 'filter_attribute_term_name'), 20, 2);
		// Translate swatches label HTML (CFVSW outputs labels outside Woo label filters)
		add_filter('woocommerce_before_variations_form', array(__CLASS__, 'start_buffer_variations_form'), 0);
		add_filter('woocommerce_after_variations_form', array(__CLASS__, 'end_buffer_variations_form'), 999);

		// Woo core gettext overrides
		add_filter('gettext', array(__CLASS__, 'woo_gettext_override'), 10, 3);
		add_filter('gettext_with_context', array(__CLASS__, 'woo_gettext_with_context_override'), 10, 4);

		// Add-to-cart notice (some themes output final HTML and bypass gettext)
		add_filter('wc_add_to_cart_message_html', array(__CLASS__, 'filter_add_to_cart_message_html'), 20, 3);
		add_filter('woocommerce_add_to_cart_message_html', array(__CLASS__, 'filter_add_to_cart_message_html'), 20, 3);

		// My Account menu item labels (Addresses etc.)
		add_filter('woocommerce_account_menu_items', array(__CLASS__, 'filter_myaccount_menu_items'), 20, 1);

		// Checkout: payment gateway descriptions/instructions (DB-based)
		add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'filter_available_payment_gateways'), 20, 1);

		// Checkout: privacy policy text (DB-based)
		add_filter('woocommerce_get_privacy_policy_text', array(__CLASS__, 'filter_privacy_policy_text'), 20, 1);
		add_filter('woocommerce_checkout_privacy_policy_text', array(__CLASS__, 'filter_privacy_policy_text'), 20, 1);

                // Checkout: field labels and coupon notice (DB-based)
                add_filter('woocommerce_checkout_fields', array(__CLASS__, 'filter_checkout_fields'), 20, 1);
                add_filter('woocommerce_checkout_coupon_message', array(__CLASS__, 'filter_checkout_coupon_message'), 20, 1);

                // Cart: shipping method label (e.g., Free shipping) (DB-based)
                add_filter('woocommerce_package_rates', array(__CLASS__, 'filter_package_rates'), 20, 2);
		// Cart/Checkout display: force shipping label override (DB-based)
		add_filter('woocommerce_cart_shipping_method_full_label', array(__CLASS__, 'filter_cart_shipping_method_full_label'), 20, 2);
		add_filter('woocommerce_shipping_rate_label', array(__CLASS__, 'filter_shipping_rate_label'), 20, 2);

		// Widgets: titles (DB-based)
		add_filter('widget_title', array(__CLASS__, 'filter_widget_title'), 20, 3);
		// Block widgets: translate sidebar block titles/labels (DB-based)
		add_filter('render_block', array(__CLASS__, 'filter_render_block_widgets_titles'), 20, 2);
        }

	public static function filter_available_payment_gateways($gateways) {
		if (is_admin()) return $gateways;

		$lang = self::current_lang_code();
		if ($lang === '') return $gateways;

		// group: checkout, key: bacs_title / bacs_description / bacs_instructions
		$title = self::misc_get($lang, 'checkout', 'bacs_title');
		$desc = self::misc_get($lang, 'checkout', 'bacs_description');
		$instr = self::misc_get($lang, 'checkout', 'bacs_instructions');

		// fallback: EN misc (master)
		if ($title === '') $title = self::misc_get('en', 'checkout', 'bacs_title');
		if ($desc === '') $desc = self::misc_get('en', 'checkout', 'bacs_description');
		if ($instr === '') $instr = self::misc_get('en', 'checkout', 'bacs_instructions');

		if (isset($gateways['bacs']) && is_object($gateways['bacs'])) {
			if ($title !== '' && property_exists($gateways['bacs'], 'title')) $gateways['bacs']->title = $title;
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

	public static function filter_checkout_fields($fields) {
		if (is_admin()) return $fields;

		$lang = self::current_lang_code();
		if ($lang === '') return $fields;

		$map = array(
			'billing_city_label' => array('group' => 'billing', 'field' => 'billing_city'),
			'billing_state_label' => array('group' => 'billing', 'field' => 'billing_state'),
			'billing_postcode_label' => array('group' => 'billing', 'field' => 'billing_postcode'),
			'billing_phone_label' => array('group' => 'billing', 'field' => 'billing_phone'),
			'billing_company_label' => array('group' => 'billing', 'field' => 'billing_company'),
			'order_comments_label' => array('group' => 'order', 'field' => 'order_comments'),
		);

		foreach ($map as $key => $target) {
			$label = self::checkout_fields_get($lang, $key);
			if ($label === '') continue;

			$group = $target['group'];
			$field = $target['field'];

			if (!isset($fields[$group][$field]) || !is_array($fields[$group][$field])) {
				continue;
			}

			$fields[$group][$field]['label'] = $label;
			if (isset($fields[$group][$field]['placeholder'])) {
				$fields[$group][$field]['placeholder'] = $label;
			}
		}

		return $fields;
	}

        public static function filter_checkout_coupon_message($message) {
                if (is_admin()) return $message;

                $lang = self::current_lang_code();
                if ($lang === '') return $message;

                $text = self::checkout_fields_get($lang, 'coupon_notice_text');
                if ($text === '') return $message;

                // Allow only the specific markup Woo expects for the coupon revealer.
                // Important: we must keep the `class="showcoupon"` attribute so Woo's JS can bind.
                $allowed = array(
                        'a' => array(
                                'href'  => true,
                                'class' => true,
                        ),
                );

                return wp_kses($text, $allowed);
        }

        public static function filter_package_rates($rates, $package) {
                if (is_admin()) return $rates;

                $lang = self::current_lang_code();
                if ($lang === '') return $rates;

                $label = self::cart_shipping_get($lang, 'free_shipping_label');
                if ($label === '') $label = self::cart_shipping_get('en', 'free_shipping_label');
                if ($label === '') return $rates;

                foreach ($rates as $rate_id => $rate) {
                        if (!is_object($rate)) continue;

                        $method_id = '';
                        if (method_exists($rate, 'get_method_id')) {
                                $method_id = (string) $rate->get_method_id();
                        } elseif (isset($rate->method_id)) {
                                $method_id = (string) $rate->method_id;
                        }

                        $current_label = '';
                        if (method_exists($rate, 'get_label')) {
                                $current_label = (string) $rate->get_label();
                        } elseif (isset($rate->label)) {
                                $current_label = (string) $rate->label;
                        }

                        $norm = trim(mb_strtolower($current_label));

                        $is_free = ($method_id === 'free_shipping') || ($norm === 'ücretsiz gönderim') || ($norm === 'free shipping');
                        if (!$is_free) continue;

                        if (method_exists($rate, 'set_label')) {
                                $rate->set_label($label);
                        } else {
                                $rate->label = $label;
                        }

                        $rates[$rate_id] = $rate;
                }

                return $rates;
        }

	public static function filter_cart_shipping_method_full_label($label, $method) {
		if (is_admin()) return $label;

		$lang = self::current_lang_code();
		if ($lang === '') return $label;

		$t = self::cart_shipping_get($lang, 'free_shipping_label');
		if ($t === '') $t = self::cart_shipping_get('en', 'free_shipping_label');
		if ($t === '') return $label;

		// $method is WC_Shipping_Rate in cart context
		$method_id = '';
		if (is_object($method) && method_exists($method, 'get_method_id')) {
			$method_id = (string) $method->get_method_id();
		} elseif (is_object($method) && isset($method->method_id)) {
			$method_id = (string) $method->method_id;
		}

		$raw = '';
		if (is_object($method) && method_exists($method, 'get_label')) {
			$raw = (string) $method->get_label();
		} elseif (is_object($method) && isset($method->label)) {
			$raw = (string) $method->label;
		}

		$raw_norm = trim(mb_strtolower($raw));

		$is_free = ($method_id === 'free_shipping') || ($raw_norm === 'ücretsiz gönderim') || ($raw_norm === 'free shipping');
		if (!$is_free) return $label;

		// full label could contain cost HTML etc. free shipping usually no cost, so replace fully
		return $t;
	}

	public static function filter_shipping_rate_label($label, $method) {
		if (is_admin()) return $label;

		$lang = self::current_lang_code();
		if ($lang === '') return $label;

		$t = self::cart_shipping_get($lang, 'free_shipping_label');
		if ($t === '') $t = self::cart_shipping_get('en', 'free_shipping_label');
		if ($t === '') return $label;

		$method_id = '';
		if (is_object($method) && method_exists($method, 'get_method_id')) {
			$method_id = (string) $method->get_method_id();
		} elseif (is_object($method) && isset($method->method_id)) {
			$method_id = (string) $method->method_id;
		}

		$norm = trim(mb_strtolower((string) $label));
		$is_free = ($method_id === 'free_shipping') || ($norm === 'ücretsiz gönderim') || ($norm === 'free shipping');

		return $is_free ? $t : $label;
	}

	public static function filter_widget_title($title, $instance, $id_base) {
		if (is_admin()) return $title;

		$lang = self::current_lang_code();
		if ($lang === '') return $title;

		// Default dilde (TR) dokunma
		$default = 'tr';
		if (class_exists('HMPCv2_Langs') && method_exists('HMPCv2_Langs', 'default_lang')) {
			$d = (string) HMPCv2_Langs::default_lang();
			if ($d !== '') $default = strtolower($d);
		}
		if ($lang === $default) return $title;

		$orig = trim((string) $title);
		if ($orig === '') return $title;

		$t = self::widgets_titles_get($lang, $orig);
		if ($t === '') $t = self::widgets_titles_get('en', $orig); // EN master fallback (only non-default langs)
		return $t !== '' ? $t : $title;
	}

	private static function is_default_lang($lang): bool {
		$default = 'tr';
		if (class_exists('HMPCv2_Langs') && method_exists('HMPCv2_Langs', 'default_lang')) {
			$d = (string) HMPCv2_Langs::default_lang();
			if ($d !== '') $default = strtolower($d);
		}
		return strtolower($lang) === $default;
	}

	private static function widgets_titles_map($lang): array {
		$dict = self::woo_dict();

		$map = array();

		// lang map
		if (isset($dict[$lang]['widgets_titles']) && is_array($dict[$lang]['widgets_titles'])) {
			foreach ($dict[$lang]['widgets_titles'] as $k => $v) {
				$k = (string) $k;
				$v = (string) $v;
				if ($v === '') continue;

				// stored as "s:Original"
				if (strpos($k, 's:') === 0) $k = substr($k, 2);
				$k = trim($k);
				if ($k === '') continue;

				$map[$k] = $v;
			}
		}

		// EN fallback (only for non-default languages)
		if ($lang !== 'en' && !self::is_default_lang($lang) && isset($dict['en']['widgets_titles']) && is_array($dict['en']['widgets_titles'])) {
			foreach ($dict['en']['widgets_titles'] as $k => $v) {
				$k = (string) $k;
				$v = (string) $v;
				if ($v === '') continue;

				if (strpos($k, 's:') === 0) $k = substr($k, 2);
				$k = trim($k);
				if ($k === '') continue;

				// don't override existing lang-specific translations
				if (!isset($map[$k])) $map[$k] = $v;
			}
		}

		return $map;
	}

	private static function replace_text_in_html_exact($html, array $map): string {
		if ($html === '' || empty($map)) return $html;

		// Replace only when the text appears as a node between tags: >TEXT<
		foreach ($map as $orig => $tr) {
			$orig = (string) $orig;
			$tr   = (string) $tr;
			if ($orig === '' || $tr === '') continue;

			// Exact text node replacement (keeps HTML safe)
			$pattern = '/(>)(\s*)' . preg_quote($orig, '/') . '(\s*)(<)/u';
			$html = preg_replace($pattern, '$1$2' . $tr . '$3$4', $html);
		}

		return $html;
	}

	public static function filter_render_block_widgets_titles($block_content, $block) {
		if (is_admin()) return $block_content;

		$lang = self::current_lang_code();
		if ($lang === '' || self::is_default_lang($lang)) return $block_content;

		// We only care about common widget/sidebar-related blocks to avoid global side effects
		$bn = isset($block['blockName']) ? (string) $block['blockName'] : '';

		$allowed_prefixes = array(
			'core/',
			'woocommerce/',
		);

		$ok = false;
		foreach ($allowed_prefixes as $p) {
			if ($bn !== '' && strpos($bn, $p) === 0) { $ok = true; break; }
		}

		if (!$ok) return $block_content;

		$map = self::widgets_titles_map($lang);
		if (empty($map)) return $block_content;

		return self::replace_text_in_html_exact((string) $block_content, $map);
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
		if (class_exists('HMPCv2_Langs') && method_exists('HMPCv2_Langs', 'default_lang')) {
			$d = (string) HMPCv2_Langs::default_lang();
			return $d !== '' ? strtolower($d) : 'tr';
		}
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

	private static function checkout_fields_get($lang, $key) {
		$key = (string) $key;
		$v = self::woo_domain_get($lang, 'checkout_fields', $key);
		if ($v !== '') return $v;

		// fallback defaults (prevents empty dict keys from skipping label override)
		static $defs = null;
		if ($defs === null) {
			$defs = array(
				'billing_city_label'     => 'Town / City',
				'billing_state_label'    => 'State / County',
				'billing_postcode_label' => 'Postcode / ZIP',
				'billing_phone_label'    => 'Phone',
				'billing_company_label'  => 'Company name (optional)',
				'order_comments_label'   => 'Order notes (optional)',
				'coupon_notice_text'     => 'Have a coupon? <a href="#" class="showcoupon">Click here to enter your code.</a>',
			);
		}

		return isset($defs[$key]) ? (string) $defs[$key] : '';
	}

        private static function cart_shipping_get($lang, $key): string {
                return self::woo_domain_get($lang, 'cart_shipping', (string) $key);
        }

	private static function widgets_titles_get($lang, $key): string {
		return self::woo_domain_get($lang, 'widgets_titles', (string) $key);
	}

        private static function woo_domain_get($lang, $domain, $key): string {
                $dict = self::woo_dict();
                $domain = (string) $domain;
                $entry_key = self::dict_key_simple((string) $key);

                if (isset($dict[$lang][$domain][$entry_key])) {
                        $v = (string) $dict[$lang][$domain][$entry_key];
                        if ($v !== '') return $v;
                }

                $default = 'tr';
                if (class_exists('HMPCv2_Langs') && method_exists('HMPCv2_Langs', 'default_lang')) {
                        $d = (string) HMPCv2_Langs::default_lang();
                        if ($d !== '') $default = strtolower($d);
                }

                // EN master fallback only for non-default languages
                if ($lang !== 'en' && $lang !== $default && isset($dict['en'][$domain][$entry_key])) {
                        $v = (string) $dict['en'][$domain][$entry_key];
                        if ($v !== '') return $v;
                }

                return '';
        }

	/**
	 * Translate add-to-cart notice HTML when theme bypasses gettext.
	 * Expected fragments:
	 *   “%s” sepetinize eklendi.
	 *   "%s" sepetinize eklendi.
	 *   %s sepetinize eklendi.
	 */
	public static function filter_add_to_cart_message_html($message, $products = array(), $show_qty = false) {
		if (is_admin()) return $message;

		$lang = self::current_lang_code();
		if ($lang === '') return $message;

		$default = HMPCv2_Langs::default_lang();
		if ($lang === $default) return $message;

		// If it doesn't look like the Turkish template, skip (avoid breaking other locales)
		if (stripos($message, 'sepetinize eklendi') === false) return $message;

		// Candidate originals (these are what you can add in Woo Strings > domain: woocommerce)
		$orig_candidates = array(
			'“%s” sepetinize eklendi.',
			'"%s" sepetinize eklendi.',
			'%s sepetinize eklendi.',
		);

		// Default fallback (EN)
		$fallback_tpl = '"%s" has been added to your cart.';
		$tpl = '';

		// Try dictionary lookups (domain key is "woocommerce" for Woo gettext strings)
		$dict = self::woo_dict();
		foreach ($orig_candidates as $orig) {
			$key = self::dict_key_simple($orig);
			if (isset($dict[$lang]['woocommerce'][$key]) && (string) $dict[$lang]['woocommerce'][$key] !== '') {
				$tpl = (string) $dict[$lang]['woocommerce'][$key];
				break;
			}
			// EN master fallback for non-default languages
			if ($tpl === '' && isset($dict['en']['woocommerce'][$key]) && (string) $dict['en']['woocommerce'][$key] !== '') {
				$tpl = (string) $dict['en']['woocommerce'][$key];
				// keep searching for direct lang match; but we already have an EN fallback
			}
		}
		if ($tpl === '') $tpl = $fallback_tpl;

		// Replace the quoted product part: “NAME” sepetinize eklendi.
		// Keep existing HTML (e.g., View cart button) intact.
		$patterns = array(
			// smart quotes
			'/“([^”]+)”\s*sepetinize eklendi\./u',
			// normal quotes
			'/"([^"]+)"\s*sepetinize eklendi\./u',
		);

		foreach ($patterns as $pat) {
			if (preg_match($pat, $message, $m)) {
				$name = isset($m[1]) ? (string) $m[1] : '';

				// Normalize product name: remove wrapping quotes and unescape slashes
				$name = trim($name);
				// Turn \" into "
				if (strpos($name, '\\') !== false) {
					$name = stripslashes($name);
				}
				// Remove wrapping quotes if still present
				$name = preg_replace('/^[\'"]+|[\'"]+$/u', '', $name);
				// Remove wrapping smart quotes if any
				$name = preg_replace('/^[“”]+|[“”]+$/u', '', $name);
				$name = trim($name);

				$replacement = sprintf($tpl, $name);
				$message = preg_replace($pat, $replacement, $message, 1);
				return $message;
			}
		}

		// Unquoted fallback: NAME sepetinize eklendi.
		// Try to replace only the last occurrence to reduce risk.
		if (preg_match('/([^<>]{2,200})\s*sepetinize eklendi\./u', $message, $m)) {
			$name = trim((string) $m[1]);
			if ($name !== '' && mb_stripos($name, 'View cart') === false) {
				$replacement = sprintf($tpl, $name);
				$message = preg_replace('/' . preg_quote($name, '/') . '\s*sepetinize eklendi\./u', $replacement, $message, 1);
			}
		}

		return $message;
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

		// Product tags attached to this product (used for per-language tag name mapping)
		$tag_terms = get_the_terms($post->ID, 'product_tag');
		if (!is_array($tag_terms)) $tag_terms = array();
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

			// Product tags (term translation map)
			echo '<p style="margin:14px 0 6px;font-weight:600;">Product tags (optional)</p>';
			echo '<p style="margin:0 0 8px;color:#666;">Translate tag names for this product. One per line: Original=Translated. Tip: you can also use TermID=Translated for perfect matching.</p>';

			$tag_lines = '';
			if (!empty($tag_terms)) {
				foreach ($tag_terms as $t) {
					if (!($t instanceof WP_Term)) continue;
					$orig = (string)$t->name;
					$tr_name = self::get_term_translation_name((int)$t->term_id, (string)$code);
					$tag_lines .= $orig . '=' . $tr_name . "\n";
				}
			}
			echo '<textarea style="width:100%;min-height:110px;" name="hmpcv2_prod[' . esc_attr($code) . '][tag_map]">' . esc_textarea($tag_lines) . '</textarea>';
			echo '<div style="font-size:12px;color:#666;">One per line: Original=Translated (or TermID=Translated)</div>';
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

		// Product tags currently attached to this product (used to resolve OriginalName -> term_id)
		$tag_terms = get_the_terms($post_id, 'product_tag');
		if (!is_array($tag_terms)) $tag_terms = array();
		$tag_ids_by_name = array();
		foreach ($tag_terms as $t) {
			if (!($t instanceof WP_Term)) continue;
			$tag_ids_by_name[strtolower((string)$t->name)] = (int)$t->term_id;
		}

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
			$tag_map    = self::parse_map(isset($block['tag_map']) ? (string)$block['tag_map'] : '');

			update_post_meta($post_id, self::k($code, 'attr_labels'), $labels_map);
			update_post_meta($post_id, self::k($code, 'attr_values'), $values_map);

			// Persist tag name translations into the global term translations store
			if (!empty($tag_map)) {
				foreach ($tag_map as $orig_key => $translated_name) {
					$translated_name = sanitize_text_field((string)$translated_name);
					if ($translated_name === '') continue;

					$term_id = 0;
					if (is_numeric($orig_key)) {
						$term_id = (int)$orig_key;
					} else {
						$k = strtolower((string)$orig_key);
						if (isset($tag_ids_by_name[$k])) $term_id = (int)$tag_ids_by_name[$k];
					}

					if ($term_id > 0) {
						self::merge_term_translation_name($term_id, (string)$code, $translated_name);
					}
				}
			}
		}
	}

	private static function get_term_translation_name(int $term_id, string $lang): string {
		if ($term_id < 1) return '';
		$lang = HMPCv2_Langs::sanitize_lang_code($lang, '');
		if ($lang === '') return '';

		if (!class_exists('HMPCv2_Options')) return '';
		$all = HMPCv2_Options::get_term_translations();
		if (!isset($all[$term_id]) || !is_array($all[$term_id])) return '';
		if (!isset($all[$term_id][$lang]) || !is_array($all[$term_id][$lang])) return '';
		$v = isset($all[$term_id][$lang]['name']) ? (string)$all[$term_id][$lang]['name'] : '';
		return trim($v);
	}

	private static function merge_term_translation_name(int $term_id, string $lang, string $name): bool {
		$term_id = (int)$term_id;
		$lang = HMPCv2_Langs::sanitize_lang_code($lang, '');
		$name = sanitize_text_field((string)$name);
		if ($term_id < 1 || $lang === '' || $name === '') return false;

		if (!class_exists('HMPCv2_Options')) return false;
		$all = HMPCv2_Options::get_term_translations();
		$existing = array();
		if (isset($all[$term_id]) && is_array($all[$term_id]) && isset($all[$term_id][$lang]) && is_array($all[$term_id][$lang])) {
			$existing = $all[$term_id][$lang];
		}

		$data = array_merge($existing, array('name' => $name));
		return (bool) HMPCv2_Options::save_term_translation($term_id, $lang, $data);
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

		$p = get_post($post_id);
		if (!$p || $p->post_type !== 'product') return false;

		// Single product page: only translate the main product title
		if (function_exists('is_product') && is_product()) {
			return ((int) get_queried_object_id() === (int) $post_id);
		}

		// Product loops (shop, category, widgets, shortcodes)
		if (function_exists('in_the_loop') && in_the_loop()) {
			return true;
		}

		// Fallback for themes/widgets that call get_the_title($product_id) outside the loop
		if (function_exists('is_shop') && is_shop()) return true;
		if (function_exists('is_product_taxonomy') && is_product_taxonomy()) return true;

		return false;
	}

        private static function current_lang_non_default() {
                $lang = HMPCv2_Router::current_lang();
                $default = HMPCv2_Langs::default_lang();
                if ($lang === $default) return '';
                return $lang;
        }

        private static function get_core_page_title_meta(int $page_id, string $lang): string {
                if ($page_id <= 0 || $lang === '') return '';

                $tr = (string) get_post_meta($page_id, self::k($lang, 'title'), true);
                $tr = trim($tr);
                if ($tr !== '') return $tr;

                $fallback_key = '_hmpcv2_title_' . strtolower($lang);
                $tr2 = (string) get_post_meta($page_id, $fallback_key, true);
                $tr2 = trim($tr2);
                if ($tr2 !== '') return $tr2;

                return '';
        }

        public static function filter_product_title($title, $post_id) {
                if (!self::is_frontend_product_context($post_id)) return $title;

                $lang = self::current_lang_non_default();
                if ($lang === '') return $title;

                $tr = (string) get_post_meta($post_id, self::k($lang, 'title'), true);
                return $tr !== '' ? $tr : $title;
        }

        /**
         * Translate product titles in Woo loops (shop/category widgets).
         * IMPORTANT: We intentionally do NOT reuse is_frontend_product_context() here,
         * because that helper is designed to limit title translation to single product pages.
         */
        public static function filter_wc_product_get_name($name, $product) {
                if (is_admin()) return $name;

                $lang = self::current_lang_non_default();
                if ($lang === '') return $name;

                $product_id = 0;
                if (is_object($product) && method_exists($product, 'get_id')) {
                        $product_id = (int) $product->get_id();
                } elseif (is_numeric($product)) {
                        $product_id = (int) $product;
                }
                if ($product_id <= 0) return $name;
                if (get_post_type($product_id) !== 'product') return $name;

                $tr = (string) get_post_meta($product_id, self::k($lang, 'title'), true);
                $tr = trim($tr);
                return $tr !== '' ? $tr : $name;
        }

        public static function filter_wc_product_title($title, $product) {
                return self::filter_wc_product_get_name($title, $product);
        }

        /**
         * Translate cart item product name (Cart / Mini-cart / Checkout order review).
         * Only affects products (post_type=product) and only when a non-default language is active.
         */
        public static function filter_cart_item_name($product_name, $cart_item, $cart_item_key) {
                if (is_admin()) return $product_name;

                $lang = self::current_lang_non_default();
                if ($lang === '') return $product_name;

                $product_id = 0;
                if (is_array($cart_item) && isset($cart_item['product_id'])) {
                        $product_id = (int) $cart_item['product_id'];
                } elseif (is_array($cart_item) && isset($cart_item['data']) && is_object($cart_item['data']) && method_exists($cart_item['data'], 'get_id')) {
                        $product_id = (int) $cart_item['data']->get_id();
                }
                if ($product_id <= 0) return $product_name;
                if (get_post_type($product_id) !== 'product') return $product_name;

                $tr = (string) get_post_meta($product_id, self::k($lang, 'title'), true);
                $tr = trim($tr);
                if ($tr === '') return $product_name;

                // Keep the original HTML structure (usually an <a> tag) and only replace the inner text.
                if (preg_match('/<a\b[^>]*>(.*?)<\/a>/is', $product_name, $m)) {
                        $inner = $m[1];
                        $new_inner = esc_html($tr);
                        return str_replace($inner, $new_inner, $product_name);
                }

                return esc_html($tr);
        }

        /**
         * Translate order item name (Order received page / My account orders / emails).
         */
        public static function filter_order_item_name($name, $item) {
                if (is_admin()) return $name;

                $lang = self::current_lang_non_default();
                if ($lang === '') return $name;

                $product_id = 0;
                if (is_object($item) && method_exists($item, 'get_product_id')) {
                        $product_id = (int) $item->get_product_id();
                }
                if ($product_id <= 0) return $name;
                if (get_post_type($product_id) !== 'product') return $name;

                $tr = (string) get_post_meta($product_id, self::k($lang, 'title'), true);
                $tr = trim($tr);
                return $tr !== '' ? $tr : $name;
        }

        /**
         * Translate variation meta labels/values shown under cart/checkout item (e.g., Size, Unit).
         * Uses saved per-product maps: attr_labels / attr_values.
         */
        public static function filter_cart_item_data($item_data, $cart_item) {
                if (is_admin()) return $item_data;

                $lang = self::current_lang_non_default();
                if ($lang === '') return $item_data;

                if (!is_array($item_data) || empty($item_data)) return $item_data;

                $product_id = 0;
                if (is_array($cart_item) && isset($cart_item['product_id'])) {
                        $product_id = (int) $cart_item['product_id'];
                }
                if ($product_id <= 0) return $item_data;
                if (get_post_type($product_id) !== 'product') return $item_data;

                $labels = get_post_meta($product_id, self::k($lang, 'attr_labels'), true);
                if (!is_array($labels)) $labels = array();

                $values = get_post_meta($product_id, self::k($lang, 'attr_values'), true);
                if (!is_array($values)) $values = array();

                foreach ($item_data as $i => $row) {
                        if (!is_array($row)) continue;

                        if (isset($row['key']) && isset($labels[$row['key']])) {
                                $item_data[$i]['key'] = (string) $labels[$row['key']];
                        }

                        // Value can be in 'value' and/or 'display'
                        if (isset($row['value']) && isset($values[$row['value']])) {
                                $item_data[$i]['value'] = (string) $values[$row['value']];
                        }
                        if (isset($row['display']) && isset($values[$row['display']])) {
                                $item_data[$i]['display'] = (string) $values[$row['display']];
                        }
                }

                return $item_data;
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

                $tr = self::get_core_page_title_meta($qid, $lang);
                if ($tr !== '') return $tr;

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

        public static function filter_shop_page_title($title) {
                if (is_admin()) return $title;
                if (!function_exists('is_shop') || !is_shop()) return $title;

                $lang = self::current_lang_non_default();
                if ($lang === '') return $title;

                $shop_id = (int) get_option('woocommerce_shop_page_id');
                $tr = self::get_core_page_title_meta($shop_id, $lang);

                if ($tr !== '') return $tr;

                // Keep your EN hard fallback behavior consistent
                if ($lang === 'en') return 'Shop';

                return $title;
        }

        public static function filter_shop_get_page_title($title, $page) {
                if (is_admin()) return $title;
                if ($page !== 'shop') return $title;

                $lang = self::current_lang_non_default();
                if ($lang === '') return $title;

                $shop_id = (int) get_option('woocommerce_shop_page_id');
                $tr = self::get_core_page_title_meta($shop_id, $lang);

                if ($tr !== '') return $tr;

                if ($lang === 'en') return 'Shop';

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

	public static function filter_attribute_term_name($term, $taxonomy) {
		if (is_admin()) return $term;

		// Only single product page
		if (function_exists('is_product')) {
			if (!is_product()) return $term;
		} else {
			if (!is_singular('product')) return $term;
		}

		$lang = self::current_lang_non_default();
		if ($lang === '') return $term;

		// Only Woo attribute taxonomies (pa_*)
		if (!is_string($taxonomy) || strpos($taxonomy, 'pa_') !== 0) return $term;

		// Resolve current product id
		$product_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
		if ($product_id <= 0) return $term;

		$values_map = get_post_meta($product_id, self::k($lang, 'attr_values'), true);
		if (!is_array($values_map) || empty($values_map)) return $term;

		if (is_object($term) && isset($term->name) && is_string($term->name) && $term->name !== '') {
			if (isset($values_map[$term->name])) {
				$term->name = $values_map[$term->name];
			}
		}

		return $term;
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

	public static function filter_variation_option_name($term_name, $term = null, $attribute = null, $product = null) {
		if (is_admin()) return $term_name;

		// Most relevant on single product pages (themes render buttons here)
		if (function_exists('is_product')) {
			if (!is_product()) return $term_name;
		} else {
			if (!is_singular('product')) return $term_name;
		}

		$lang = self::current_lang_non_default();
		if ($lang === '') return $term_name;

		// Resolve product
		$resolved_product = null;
		if (is_object($product) && method_exists($product, 'get_id')) {
			$resolved_product = $product;
		} else {
			if (function_exists('wc_get_product')) {
				global $product;
				$global_product = $product;
				if (is_object($global_product) && method_exists($global_product, 'get_id')) {
					$resolved_product = $global_product;
				} else {
					$qid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
					if ($qid > 0) {
						$p = wc_get_product($qid);
						if (is_object($p) && method_exists($p, 'get_id')) $resolved_product = $p;
					}
				}
			}
		}

		if (!$resolved_product || !method_exists($resolved_product, 'get_id')) return $term_name;

		$post_id = (int) $resolved_product->get_id();

		$values_map = get_post_meta($post_id, self::k($lang, 'attr_values'), true);
		if (!is_array($values_map)) return $term_name;

		// Primary: match by rendered name
		if (isset($values_map[$term_name])) return $values_map[$term_name];

		// Secondary: if term object is provided
		if (is_object($term) && isset($term->name) && isset($values_map[$term->name])) {
			return $values_map[$term->name];
		}

		return $term_name;
	}

	public static function filter_variation_attribute_options_html($html, $args) {
		if (is_admin()) return $html;

		// Only on single product pages
		if (function_exists('is_product')) {
			if (!is_product()) return $html;
		} else {
			if (!is_singular('product')) return $html;
		}

		$lang = self::current_lang_non_default();
		if ($lang === '') return $html;

		// Resolve product id
		$product_id = 0;
		if (!empty($args['product']) && is_object($args['product']) && method_exists($args['product'], 'get_id')) {
			$product_id = (int) $args['product']->get_id();
		} else {
			$qo = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
			if ($qo > 0) $product_id = $qo;
		}

		if ($product_id <= 0) return $html;

		$labels_map = get_post_meta($product_id, self::k($lang, 'attr_labels'), true);
		if (!is_array($labels_map)) $labels_map = array();

		$values_map = get_post_meta($product_id, self::k($lang, 'attr_values'), true);
		if (!is_array($values_map)) $values_map = array();

		// Replace values first (e.g., "34 beden" -> "Size 34")
		if (!empty($values_map)) {
			// Longer keys first to avoid partial collisions
			uksort($values_map, function($a, $b) {
				return strlen((string) $b) <=> strlen((string) $a);
			});

			foreach ($values_map as $from => $to) {
				if (!is_string($from) || $from === '') continue;
				if (!is_string($to)) $to = (string) $to;

				// Replace in text nodes + data-title="..."
				$html = str_replace('>' . esc_html($from) . '<', '>' . esc_html($to) . '<', $html);
				$html = str_replace('data-title="' . esc_attr($from) . '"', 'data-title="' . esc_attr($to) . '"', $html);

				// Fallback raw replace (some plugins output plain)
				$html = str_replace($from, $to, $html);
			}
		}

		// Replace labels if they appear inside the html (some swatches render label)
		if (!empty($labels_map)) {
			uksort($labels_map, function($a, $b) {
				return strlen((string) $b) <=> strlen((string) $a);
			});

			foreach ($labels_map as $from => $to) {
				if (!is_string($from) || $from === '') continue;
				if (!is_string($to)) $to = (string) $to;

				$html = str_replace('>' . esc_html($from) . '<', '>' . esc_html($to) . '<', $html);
				$html = str_replace($from, $to, $html);
			}
		}

		return $html;
	}

	public static function start_buffer_variations_form() {
		if (is_admin()) return;
		if (function_exists('is_product') && !is_product()) return;

		ob_start();
	}

	public static function end_buffer_variations_form() {
		if (is_admin()) return;
		if (function_exists('is_product') && !is_product()) return;

		$html = ob_get_clean();
		if (!is_string($html) || $html === '') {
			echo $html;
			return;
		}

		$lang = self::current_lang_non_default();
		if ($lang === '') {
			echo $html;
			return;
		}

		$product_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
		if ($product_id <= 0) {
			echo $html;
			return;
		}

		$labels_map = get_post_meta($product_id, self::k($lang, 'attr_labels'), true);
		if (!is_array($labels_map) || empty($labels_map)) {
			echo $html;
			return;
		}

		// Longer keys first
		uksort($labels_map, function($a, $b) {
			return strlen((string) $b) <=> strlen((string) $a);
		});

		foreach ($labels_map as $from => $to) {
			if (!is_string($from) || $from === '') continue;
			if (!is_string($to)) $to = (string) $to;

			// Replace common label patterns safely
			$html = str_replace('>' . esc_html($from) . '<', '>' . esc_html($to) . '<', $html);
			$html = str_replace($from, $to, $html);
		}

		echo $html;
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

		$default = HMPCv2_Langs::default_lang();
		$is_woo_domain = in_array($domain, array('woocommerce', 'woocommerce-blocks'), true);

		$dict = self::woo_dict();
		$key = self::dict_key_simple((string) $text);

		if (isset($dict[$lang][$domain][$key])) {
			$t = (string) $dict[$lang][$domain][$key];
			if ($t !== '') return $t;
		}

		// Non-default language: prevent default-language (TR) leaks inside Woo gettext
		if ($is_woo_domain && $lang !== $default) {
			// 1) EN master fallback if available
			if (isset($dict['en'][$domain][$key])) {
				$t = (string) $dict['en'][$domain][$key];
				if ($t !== '') return $t;
			}
			// 2) Otherwise return the source string instead of default locale translation
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

		$default = HMPCv2_Langs::default_lang();
		$is_woo_domain = in_array($domain, array('woocommerce', 'woocommerce-blocks'), true);

		$dict = self::woo_dict();
		$key = self::dict_key_context((string) $context, (string) $text);

		if (isset($dict[$lang][$domain][$key])) {
			$t = (string) $dict[$lang][$domain][$key];
			if ($t !== '') return $t;
		}

		// Non-default language: prevent default-language (TR) leaks inside Woo gettext
		if ($is_woo_domain && $lang !== $default) {
			if (isset($dict['en'][$domain][$key])) {
				$t = (string) $dict['en'][$domain][$key];
				if ($t !== '') return $t;
			}
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
