<?php

if (!defined('ABSPATH')) exit;

/**
 * HMPC v2 - CSV Language Importer (Products)
 *
 * What it imports:
 * - Per-language product Title / Short description / Description into HMPC v2 product metabox meta keys
 *   (_hmpcv2_{lang}_title, _hmpcv2_{lang}_short, _hmpcv2_{lang}_desc)
 * - Per-language attribute label/value maps into meta keys
 *   (_hmpcv2_{lang}_attr_labels, _hmpcv2_{lang}_attr_values)
 * - Per-language product tag translations into the global term translation store
 *   (option: hmpcv2_term_translations)
 *
 * WP-CLI usage:
 *   wp hmpcv2 import-products /full/path/file.csv [--delimiter=,] [--dry-run]
 *
 * Admin usage:
 *   WooCommerce -> HMPC Importer
 *   1) Upload CSV (build queue)
 *   2) Process next batch (10..100 per click)
 *
 * CSV header expectation:
 * - Must include: urun_kodu  (used as SKU)
 * - For each language (except default), you can include:
 *   {lang}_title
 *   {lang}_product_description
 *   {lang}_short_description
 *   {lang}_etiketler
 *   {lang}_beden
 *   {lang}_renk
 *   {lang}_olcu_miktari_listesi
 *   {lang}_attr_labels (optional)
 *
 * Notes:
 * - {lang}_etiketler supports 2 formats:
 *   A) Map format: "TurkishTag=EnglishTag" per line (or comma-separated)
 *   B) List format: "EnglishTag1, EnglishTag2, ..." (NO '=')
 *      In this case, importer pairs translated tags with the product's currently attached Turkish tags.
 * - {lang}_beden / {lang}_renk / {lang}_olcu_miktari_listesi are treated as VALUE maps
 *   (one per line: Original=Translated). They are merged into _attr_values.
 */

final class HMPCv2_Language_Importer {
	// Queue job option (single active job)
	const OPTION_QUEUE_JOB = 'hmpcv2_language_import_queue_job';

	// Default batch size
	const DEFAULT_BATCH_SIZE = 30;

	public static function init(): void {
		if (defined('WP_CLI') && WP_CLI) {
			WP_CLI::add_command('hmpcv2 import-products', array(__CLASS__, 'cli_import_products'));
		}

		if (is_admin()) {
			add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
			add_action('admin_post_hmpcv2_import_build_queue', array(__CLASS__, 'handle_build_queue'));
			add_action('admin_post_hmpcv2_import_process_batch', array(__CLASS__, 'handle_process_batch'));
			add_action('admin_post_hmpcv2_import_reset_queue', array(__CLASS__, 'handle_reset_queue'));
		}
	}

	// -------------------- Admin UI --------------------

	public static function register_admin_menu(): void {
		// WooCommerce menu parent
		$cap = function_exists('hmpcv2_admin_cap') ? hmpcv2_admin_cap() : 'manage_woocommerce';
		add_submenu_page(
			'woocommerce',
			__('HMPC Importer', 'hmpcv2'),
			__('HMPC Importer', 'hmpcv2'),
			$cap,
			'hmpcv2-importer',
			array(__CLASS__, 'render_admin_page')
		);
	}

	private static function queue_dir_abs(): string {
		$up = wp_upload_dir();
		$basedir = isset($up['basedir']) ? (string)$up['basedir'] : '';
		if ($basedir === '') return WP_CONTENT_DIR . '/uploads';
		return rtrim($basedir, '/\\') . '/hmpcv2-import-queue';
	}

	private static function ensure_queue_dir(): bool {
		$dir = self::queue_dir_abs();
		if (!file_exists($dir)) {
			return (bool) wp_mkdir_p($dir);
		}
		return is_dir($dir) && is_writable($dir);
	}

	private static function get_queue_job(): array {
		$job = get_option(self::OPTION_QUEUE_JOB, array());
		return is_array($job) ? $job : array();
	}

	private static function set_queue_job(array $job): void {
		update_option(self::OPTION_QUEUE_JOB, $job, false);
	}

	private static function clear_queue_job(): void {
		delete_option(self::OPTION_QUEUE_JOB);
	}

	private static function sanitize_batch_size($n): int {
		$n = (int)$n;
		if ($n <= 0) $n = (int)self::DEFAULT_BATCH_SIZE;
		if ($n < 10) $n = 10;
		if ($n > 100) $n = 100;
		// force step 10
		$n = (int)(round($n / 10) * 10);
		if ($n < 10) $n = 10;
		if ($n > 100) $n = 100;
		return $n;
	}

	private static function calc_batch_info(int $cursor, int $total, int $batch_size): array {
		$cursor = max(0, (int)$cursor);
		$total = max(0, (int)$total);
		$batch_size = max(1, (int)$batch_size);

		$done = min($cursor, $total);
		$left = max($total - $done, 0);
		$start = $done;
		$end = min($start + $batch_size, $total);
		$will = max($end - $start, 0);

		$batch_no = (int) floor($start / $batch_size) + 1;
		$batch_total = (int) ceil($total / $batch_size);

		$human_from = ($will > 0) ? ($start + 1) : 0;
		$human_to = ($will > 0) ? $end : 0;

		return array(
			'done' => $done,
			'left' => $left,
			'start' => $start,
			'end' => $end,
			'will' => $will,
			'batch_no' => $batch_no,
			'batch_total' => $batch_total,
			'human_from' => $human_from,
			'human_to' => $human_to,
		);
	}

	private static function admin_notice(string $type, string $msg): void {
		$base = admin_url('admin.php?page=hmpcv2-importer');
		$key = ($type === 'error') ? 'hmpcv2_err' : 'hmpcv2_msg';
		wp_safe_redirect(add_query_arg(array($key => rawurlencode($msg)), $base));
		exit;
	}

	public static function render_admin_page(): void {
		$cap = function_exists('hmpcv2_admin_cap') ? hmpcv2_admin_cap() : 'manage_woocommerce';
		$can = function_exists('hmpcv2_user_can_manage') ? hmpcv2_user_can_manage() : current_user_can($cap);
		if (!$can) {
			wp_die(__('You do not have permission to access this page.', 'hmpcv2'));
		}

		$job = self::get_queue_job();
		$has_queue = !empty($job['job_id']) && !empty($job['queue_path']) && file_exists((string)$job['queue_path']);

		$cursor = isset($job['cursor']) ? (int)$job['cursor'] : 0;
		$total = isset($job['total_rows']) ? (int)$job['total_rows'] : 0;
		$batch_size = isset($job['batch_size']) ? self::sanitize_batch_size($job['batch_size']) : (int)self::DEFAULT_BATCH_SIZE;
		$dry_run = !empty($job['dry_run']);
		$info = self::calc_batch_info($cursor, $total, $batch_size);

		echo '<div class="wrap">';
		echo '<h1>HMPC Importer — Batch Queue</h1>';
		echo '<p style="color:#666">CSV upload builds a queue in uploads; process next batch per click to avoid hosting limits.</p>';

		if (!empty($_GET['hmpcv2_msg'])) {
			echo '<div class="notice notice-success"><p>' . esc_html(rawurldecode((string)$_GET['hmpcv2_msg'])) . '</p></div>';
		}
		if (!empty($_GET['hmpcv2_err'])) {
			$err = trim((string)rawurldecode((string)$_GET['hmpcv2_err']));
			if ($err !== '') {
				echo '<div class="notice notice-warning"><pre style="white-space:pre-wrap;margin:0;padding:10px 12px;">' . esc_html($err) . '</pre></div>';
			}
		}

		if ($has_queue) {
			echo '<div class="notice notice-info" style="padding:10px 12px;">';
			echo '<p style="margin:0;">';
			echo '<strong>Active Queue:</strong> <code>' . esc_html((string)$job['job_id']) . '</code>';
			echo ' | Total rows: <strong>' . (int)$total . '</strong>';
			echo ' | Done: <strong>' . (int)$info['done'] . '</strong>';
			echo ' | Left: <strong>' . (int)$info['left'] . '</strong>';
			echo ' | Batch size: <strong>' . (int)$batch_size . '</strong>';
			echo $dry_run ? ' | <strong>Dry-run</strong>' : '';
			if ($info['will'] > 0) {
				echo ' | Next: <strong>' . (int)$info['batch_no'] . '/' . (int)$info['batch_total'] . '</strong> (Rows ' . (int)$info['human_from'] . '–' . (int)$info['human_to'] . ')';
			}
			echo '</p>';
			echo '</div>';
		}

		// 1) Upload / Build queue
		echo '<h2 style="margin-top:18px;">1) Upload CSV (Build Queue)</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
		wp_nonce_field('hmpcv2_import_build_queue');
		echo '<input type="hidden" name="action" value="hmpcv2_import_build_queue" />';
		echo '<table class="form-table">';
		echo '<tr><th scope="row">CSV File</th><td><input type="file" name="csv" accept=".csv,text/csv" required></td></tr>';
		echo '<tr><th scope="row">Delimiter</th><td>';
		echo '<select name="delimiter">';
		echo '<option value="auto" selected>Auto</option>';
		echo '<option value=",">Comma (,)</option>';
		echo '<option value=";">Semicolon (;)</option>';
		echo '<option value="\t">Tab</option>';
		echo '</select>';
		echo '<p class="description">Header must include <code>urun_kodu</code>. Language columns are optional (only filled ones are imported).</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">Mode</th><td>';
		echo '<label><input type="checkbox" name="dry_run" value="1" /> Dry-run (do not write, just validate)</label>';
		echo '</td></tr>';
		echo '</table>';
		submit_button('Build Queue');
		echo '</form>';

		echo '<hr style="margin:22px 0;" />';

		// 2) Process batch
		echo '<h2>2) Process Queue (Batch)</h2>';
		if ($has_queue) {
			echo '<p style="margin-top:6px;color:#555">Each click processes next batch of rows to avoid timeouts.</p>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:12px;">';
			wp_nonce_field('hmpcv2_import_process_batch');
			echo '<input type="hidden" name="action" value="hmpcv2_import_process_batch" />';
			echo '<label style="margin-right:10px;vertical-align:middle;"><strong>Batch size:</strong> ';
			echo '<select name="batch_size" style="min-width:110px;">';
			foreach (array(10,20,30,40,50,60,70,80,90,100) as $opt) {
				echo '<option value="' . (int)$opt . '" ' . selected($batch_size, (int)$opt, false) . '>' . (int)$opt . '</option>';
			}
			echo '</select></label>';
			$btnWill = (int)$info['will'];
			$btnMax = (int)$batch_size;
			submit_button('Process Next Batch (' . $btnWill . ' / ' . $btnMax . ')', 'primary', 'submit', false);
			echo '</form>';

			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
			wp_nonce_field('hmpcv2_import_reset_queue');
			echo '<input type="hidden" name="action" value="hmpcv2_import_reset_queue" />';
			submit_button('Reset Queue', 'secondary', 'submit', false);
			echo '</form>';
		} else {
			echo '<p style="margin-top:6px;color:#666">No active queue. Upload a CSV to start.</p>';
		}

		echo '</div>';
	}

	public static function handle_build_queue(): void {
		$cap = function_exists('hmpcv2_admin_cap') ? hmpcv2_admin_cap() : 'manage_woocommerce';
		$can = function_exists('hmpcv2_user_can_manage') ? hmpcv2_user_can_manage() : current_user_can($cap);
		if (!$can) wp_die('No permission');
		check_admin_referer('hmpcv2_import_build_queue');

		if (!self::ensure_queue_dir()) {
			self::admin_notice('error', 'Queue directory is not writable: ' . self::queue_dir_abs());
		}

		if (empty($_FILES['csv']) || !isset($_FILES['csv']['tmp_name'])) {
			self::admin_notice('error', 'CSV file missing.');
		}

		$tmp = (string) $_FILES['csv']['tmp_name'];
		if ($tmp === '' || !file_exists($tmp) || !is_readable($tmp)) {
			self::admin_notice('error', 'Uploaded CSV not readable.');
		}

		$delimiter = isset($_POST['delimiter']) ? (string) sanitize_text_field(wp_unslash($_POST['delimiter'])) : 'auto';
		$dry_run = !empty($_POST['dry_run']);

		$fh = fopen($tmp, 'r');
		if (!$fh) {
			self::admin_notice('error', 'Failed to open uploaded CSV.');
		}

		$header_line = fgets($fh);
		if ($header_line === false) {
			fclose($fh);
			self::admin_notice('error', 'CSV is empty.');
		}

		$header_line = self::strip_bom($header_line);
		if ($delimiter === 'auto') {
			$delimiter = self::detect_delimiter($header_line);
		} elseif ($delimiter === '\\t') {
			$delimiter = "\t";
		}

		$headers = str_getcsv(rtrim($header_line, "\r\n"), $delimiter);
		$headers = array_map(array(__CLASS__, 'norm_header'), $headers);
		$col = array();
		foreach ($headers as $i => $h) {
			if ($h === '') continue;
			$col[$h] = $i;
		}
		if (!isset($col['urun_kodu'])) {
			fclose($fh);
			self::admin_notice('error', 'Missing required column: urun_kodu');
		}

		$enabled = class_exists('HMPCv2_Langs') ? HMPCv2_Langs::enabled_langs() : array();
		$default = class_exists('HMPCv2_Langs') ? HMPCv2_Langs::default_lang() : 'tr';
		if (empty($enabled)) {
			fclose($fh);
			self::admin_notice('error', 'No enabled languages found.');
		}

		$job_id = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
		$queue_path = self::queue_dir_abs() . '/' . $job_id . '.jsonl';
		$out = fopen($queue_path, 'w');
		if (!$out) {
			fclose($fh);
			self::admin_notice('error', 'Failed to create queue file.');
		}

		$total_rows = 0;
		$row_no = 1; // header line already consumed
		while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
			$row_no++;
			if (!is_array($row) || count(array_filter($row, function($v){ return trim((string)$v) !== ''; })) === 0) {
				continue;
			}

			$sku = trim(self::cell($row, $col, 'urun_kodu'));
			if ($sku === '') continue;

			// Keep only relevant columns to minimize queue size
			$payload = array(
				'urun_kodu' => $sku,
			);

			foreach ($enabled as $lang) {
				$lang = (string)$lang;
				if ($lang === $default) continue;

				foreach (array(
					$lang . '_title',
					$lang . '_product_description',
					$lang . '_short_description',
					$lang . '_etiketler',
					$lang . '_beden',
					$lang . '_renk',
					$lang . '_olcu_miktari_listesi',
					$lang . '_attr_labels',
				) as $k) {
					if (!isset($col[$k])) continue;
					$val = (string) $row[(int)$col[$k]];
					if (trim($val) === '') continue;
					$payload[$k] = $val;
				}
			}

			fwrite($out, wp_json_encode($payload) . "\n");
			$total_rows++;
		}

		fclose($out);
		fclose($fh);

		$job = array(
			'job_id' => $job_id,
			'created_at' => current_time('mysql'),
			'queue_path' => $queue_path,
			'cursor' => 0,
			'total_rows' => $total_rows,
			'batch_size' => (int)self::DEFAULT_BATCH_SIZE,
			'dry_run' => $dry_run ? 1 : 0,
			'delimiter' => $delimiter,
		);
		self::set_queue_job($job);

		self::admin_notice('success', 'Queue built. rows=' . $total_rows . ($dry_run ? ' (dry-run ON)' : ''));
	}

	public static function handle_process_batch(): void {
		$cap = function_exists('hmpcv2_admin_cap') ? hmpcv2_admin_cap() : 'manage_woocommerce';
		$can = function_exists('hmpcv2_user_can_manage') ? hmpcv2_user_can_manage() : current_user_can($cap);
		if (!$can) wp_die('No permission');
		check_admin_referer('hmpcv2_import_process_batch');

		$job = self::get_queue_job();
		if (empty($job['job_id']) || empty($job['queue_path']) || !file_exists((string)$job['queue_path'])) {
			self::admin_notice('error', 'No active queue found.');
		}

		$batch_size = isset($_POST['batch_size']) ? self::sanitize_batch_size($_POST['batch_size']) : (int)self::DEFAULT_BATCH_SIZE;
		$job['batch_size'] = $batch_size;

		$cursor = isset($job['cursor']) ? (int)$job['cursor'] : 0;
		$total = isset($job['total_rows']) ? (int)$job['total_rows'] : 0;
		$dry_run = !empty($job['dry_run']);

		if ($cursor >= $total) {
			self::admin_notice('success', 'Queue already completed.');
		}

		$enabled = class_exists('HMPCv2_Langs') ? HMPCv2_Langs::enabled_langs() : array();
		$default = class_exists('HMPCv2_Langs') ? HMPCv2_Langs::default_lang() : 'tr';
		if (empty($enabled)) {
			self::admin_notice('error', 'No enabled languages found.');
		}

		$queue_path = (string)$job['queue_path'];
		$file = new SplFileObject($queue_path, 'r');
		$file->setFlags(SplFileObject::DROP_NEW_LINE);

		$processed = 0;
		$updated = 0;
		$skipped = 0;
		$messages = array();

		$end = min($cursor + $batch_size, $total);
		for ($i = $cursor; $i < $end; $i++) {
			$file->seek($i);
			$line = $file->current();
			if (!is_string($line) || trim($line) === '') {
				$skipped++;
				continue;
			}

			$payload = json_decode($line, true);
			if (!is_array($payload) || empty($payload['urun_kodu'])) {
				$skipped++;
				continue;
			}

			$processed++;
			$sku = trim((string)$payload['urun_kodu']);
			$product_id = self::find_product_id_by_sku($sku);
			if ($product_id < 1) {
				$skipped++;
				$messages[] = "SKU not found: {$sku}";
				continue;
			}

			$changes = array();
			foreach ($enabled as $lang) {
				$lang = (string)$lang;
				if ($lang === $default) continue;

				$title = isset($payload[$lang . '_title']) ? (string)$payload[$lang . '_title'] : '';
				$desc  = isset($payload[$lang . '_product_description']) ? (string)$payload[$lang . '_product_description'] : '';
				$short = isset($payload[$lang . '_short_description']) ? (string)$payload[$lang . '_short_description'] : '';

				if (trim($title) !== '') {
					$changes[] = "{$lang}:title";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'title'), sanitize_text_field($title));
				}
				if (trim($short) !== '') {
					$changes[] = "{$lang}:short";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'short'), wp_kses_post($short));
				}
				if (trim($desc) !== '') {
					$changes[] = "{$lang}:desc";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'desc'), wp_kses_post($desc));
				}

				$attr_labels_raw = isset($payload[$lang . '_attr_labels']) ? (string)$payload[$lang . '_attr_labels'] : '';
				if (trim($attr_labels_raw) !== '') {
					$labels_map = self::parse_map_flexible($attr_labels_raw);
					if (!empty($labels_map)) {
						$changes[] = "{$lang}:attr_labels";
						if (!$dry_run) update_post_meta($product_id, self::k($lang, 'attr_labels'), $labels_map);
					}
				}

				$values_map = array();
				foreach (array('beden', 'renk', 'olcu_miktari_listesi') as $field) {
					$key = $lang . '_' . $field;
					$raw = isset($payload[$key]) ? (string)$payload[$key] : '';
					$values_map = array_merge($values_map, self::parse_attr_values_input($product_id, $field, $raw));
				}
				if (!empty($values_map)) {
					$changes[] = "{$lang}:attr_values";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'attr_values'), $values_map);
				}

				$tags_raw = isset($payload[$lang . '_etiketler']) ? trim((string)$payload[$lang . '_etiketler']) : '';
				if ($tags_raw !== '') {
					$did = self::import_tag_translations_for_product($product_id, $lang, $tags_raw, $dry_run);
					if ($did) $changes[] = "{$lang}:tags";
				}
			}

			if (!empty($changes)) {
				$updated++;
			}
		}

		$job['cursor'] = $end;
		self::set_queue_job($job);

		$done_msg = "Batch done. processed={$processed}, updated={$updated}, skipped={$skipped}. cursor={$end}/{$total}" . ($dry_run ? ' (dry-run)' : '');

		// Auto-finish
		if ($end >= $total) {
			self::clear_queue_job();
			$done_msg .= ' | Queue completed and cleared.';
		}

		self::admin_notice('success', $done_msg);
	}

	public static function handle_reset_queue(): void {
		$cap = function_exists('hmpcv2_admin_cap') ? hmpcv2_admin_cap() : 'manage_woocommerce';
		$can = function_exists('hmpcv2_user_can_manage') ? hmpcv2_user_can_manage() : current_user_can($cap);
		if (!$can) wp_die('No permission');
		check_admin_referer('hmpcv2_import_reset_queue');

		$job = self::get_queue_job();
		if (!empty($job['queue_path']) && file_exists((string)$job['queue_path'])) {
			@unlink((string)$job['queue_path']);
		}
		self::clear_queue_job();
		self::admin_notice('success', 'Queue reset.');
	}

	// -------------------- WP-CLI --------------------

	/**
	 * WP-CLI callback.
	 */
	public static function cli_import_products($args, $assoc_args): void {
		$file = isset($args[0]) ? (string)$args[0] : '';
		if ($file === '' || !file_exists($file) || !is_readable($file)) {
			\WP_CLI::error('CSV file not found or not readable: ' . $file);
			return;
		}

		$dry_run = !empty($assoc_args['dry-run']);
		$delimiter = isset($assoc_args['delimiter']) ? (string)$assoc_args['delimiter'] : '';

		$enabled = class_exists('HMPCv2_Langs') ? HMPCv2_Langs::enabled_langs() : array();
		$default = class_exists('HMPCv2_Langs') ? HMPCv2_Langs::default_lang() : 'tr';
		if (empty($enabled)) {
			\WP_CLI::error('No enabled languages found.');
			return;
		}

		$fh = fopen($file, 'r');
		if (!$fh) {
			\WP_CLI::error('Failed to open: ' . $file);
			return;
		}

		$header_line = fgets($fh);
		if ($header_line === false) {
			fclose($fh);
			\WP_CLI::error('CSV is empty.');
			return;
		}

		$header_line = self::strip_bom($header_line);
		if ($delimiter === '') {
			$delimiter = self::detect_delimiter($header_line);
		}

		// Parse header
		$headers = str_getcsv(rtrim($header_line, "\r\n"), $delimiter);
		$headers = array_map(array(__CLASS__, 'norm_header'), $headers);
		$col = array();
		foreach ($headers as $i => $h) {
			if ($h === '') continue;
			$col[$h] = $i;
		}
		if (!isset($col['urun_kodu'])) {
			fclose($fh);
			\WP_CLI::error('Missing required column: urun_kodu');
			return;
		}

		$processed = 0;
		$updated = 0;
		$skipped = 0;

		while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
			// Skip empty lines
			if (!is_array($row) || count(array_filter($row, function($v){ return trim((string)$v) !== ''; })) === 0) {
				continue;
			}

			$processed++;
			$sku = self::cell($row, $col, 'urun_kodu');
			$sku = trim($sku);
			if ($sku === '') {
				$skipped++;
				\WP_CLI::warning("Row {$processed}: empty urun_kodu, skipped.");
				continue;
			}

			$product_id = self::find_product_id_by_sku($sku);
			if ($product_id < 1) {
				$skipped++;
				\WP_CLI::warning("Row {$processed}: product not found by SKU: {$sku}");
				continue;
			}

			$changes = array();

			foreach ($enabled as $lang) {
				$lang = (string)$lang;
				if ($lang === $default) continue;

				$title = self::cell($row, $col, $lang . '_title');
				$desc  = self::cell($row, $col, $lang . '_product_description');
				$short = self::cell($row, $col, $lang . '_short_description');

				// Import product meta fields
				if (trim($title) !== '') {
					$changes[] = "{$lang}:title";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'title'), sanitize_text_field($title));
				}
				if (trim($short) !== '') {
					$changes[] = "{$lang}:short";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'short'), wp_kses_post($short));
				}
				if (trim($desc) !== '') {
					$changes[] = "{$lang}:desc";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'desc'), wp_kses_post($desc));
				}

				// Optional: full attr_labels map in a dedicated column
				$attr_labels_raw = self::cell($row, $col, $lang . '_attr_labels');
				if (trim($attr_labels_raw) !== '') {
					$labels_map = self::parse_map_flexible($attr_labels_raw);
					if (!empty($labels_map)) {
						$changes[] = "{$lang}:attr_labels";
						if (!$dry_run) update_post_meta($product_id, self::k($lang, 'attr_labels'), $labels_map);
					}
				}

				// Attribute VALUE maps merged from specific columns
				$values_map = array();
				$beden_raw = self::cell($row, $col, $lang . '_beden');
				$renk_raw = self::cell($row, $col, $lang . '_renk');
				$olcu_raw = self::cell($row, $col, $lang . '_olcu_miktari_listesi');

				$values_map = array_merge($values_map, self::parse_attr_values_input($product_id, 'beden', $beden_raw));
				$values_map = array_merge($values_map, self::parse_attr_values_input($product_id, 'renk', $renk_raw));
				$values_map = array_merge($values_map, self::parse_attr_values_input($product_id, 'olcu_miktari_listesi', $olcu_raw));

				if (!empty($values_map)) {
					$changes[] = "{$lang}:attr_values";
					if (!$dry_run) update_post_meta($product_id, self::k($lang, 'attr_values'), $values_map);
				}

				// Tags translation: map or list
				$tags_raw = self::cell($row, $col, $lang . '_etiketler');
				$tags_raw = trim((string)$tags_raw);
				if ($tags_raw !== '') {
					$did = self::import_tag_translations_for_product($product_id, $lang, $tags_raw, $dry_run);
					if ($did) $changes[] = "{$lang}:tags";
				}
			}

			if (!empty($changes)) {
				$updated++;
				\WP_CLI::log("SKU {$sku} (ID {$product_id}) -> " . implode(', ', $changes) . ($dry_run ? ' [dry-run]' : ''));
			} else {
				\WP_CLI::log("SKU {$sku} (ID {$product_id}) -> no changes");
			}
		}

		fclose($fh);

		\WP_CLI::success("Done. processed={$processed}, updated={$updated}, skipped={$skipped}" . ($dry_run ? ' (dry-run)' : ''));
	}

	// -------------------- Helpers --------------------

	private static function k(string $lang, string $field): string {
		return '_hmpcv2_' . strtolower($lang) . '_' . $field;
	}

	private static function strip_bom(string $s): string {
		// UTF-8 BOM
		if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
			return substr($s, 3);
		}
		return $s;
	}

	private static function detect_delimiter(string $line): string {
		$tabs = substr_count($line, "\t");
		$commas = substr_count($line, ',');
		$semis = substr_count($line, ';');
		if ($tabs >= $commas && $tabs >= $semis && $tabs > 0) return "\t";
		if ($semis > $commas) return ';';
		return ',';
	}

	public static function norm_header(string $h): string {
		$h = trim($h);
		$h = str_replace(array("\xEF\xBB\xBF"), '', $h);
		$h = strtolower($h);
		$h = preg_replace('/\s+/', '_', $h);
		$h = preg_replace('/[^a-z0-9_\-]/', '', $h);
		return $h;
	}

	private static function cell(array $row, array $col, string $key): string {
		if (!isset($col[$key])) return '';
		$idx = (int)$col[$key];
		return isset($row[$idx]) ? (string)$row[$idx] : '';
	}

	private static function find_product_id_by_sku(string $sku): int {
		$sku = trim($sku);
		if ($sku === '') return 0;
		if (function_exists('wc_get_product_id_by_sku')) {
			$id = (int) wc_get_product_id_by_sku($sku);
			if ($id > 0) return $id;
		}
		$q = new WP_Query(array(
			'post_type' => 'product',
			'post_status' => array('publish', 'draft', 'pending', 'private'),
			'fields' => 'ids',
			'posts_per_page' => 1,
			'no_found_rows' => true,
			'meta_query' => array(
				array(
					'key' => '_sku',
					'value' => $sku,
					'compare' => '=',
				),
			),
		));
		$ids = is_array($q->posts) ? $q->posts : array();
		return !empty($ids) ? (int)$ids[0] : 0;
	}

	/**
	 * Parse flexible map input.
	 * Accepts:
	 *  - One per line: Original=Translated
	 *  - Comma-separated pairs: Original=Translated, A=B
	 *  - If no '=' is present, returns empty array (list format)
	 */
	private static function parse_map_flexible(string $raw): array {
		$raw = trim((string)$raw);
		if ($raw === '' || strpos($raw, '=') === false) {
			return array();
		}

		$raw = str_replace(array("\r\n", "\r"), "\n", $raw);
		$parts = preg_split('/\n+|\s*,\s*/', $raw);
		$map = array();
		foreach ($parts as $p) {
			$p = trim((string)$p);
			if ($p === '' || strpos($p, '=') === false) continue;
			list($o, $t) = array_pad(explode('=', $p, 2), 2, '');
			$o = trim((string)$o);
			$t = trim((string)$t);
			if ($o === '' || $t === '') continue;
			$map[$o] = $t;
		}
		return $map;
	}

	/**
	 * Parse list input: comma or newline separated.
	 */
	private static function parse_list(string $raw): array {
		$raw = trim((string)$raw);
		if ($raw === '') return array();
		$raw = str_replace(array("\r\n", "\r"), "\n", $raw);
		$parts = preg_split('/\n+|\s*,\s*/', $raw);
		$out = array();
		foreach ($parts as $p) {
			$p = trim((string)$p);
			if ($p === '') continue;
			$out[] = $p;
		}
		return $out;
	}

	/**
	 * Attribute values input parser with "smart" matching.
	 *
	 * - If input contains '=', it is treated as an explicit map Original=Translated.
	 * - If input is a translated list (no '='), we try to map it to the product's existing
	 *   taxonomy-based attribute terms.
	 *
	 * Supported fields:
	 * - beden -> pa_beden (matches by numeric key)
	 * - renk -> pa_renk (matches by order when counts align)
	 * - olcu_miktari_listesi -> pa_olcu-miktari (single-term -> single-translation)
	 */
	private static function parse_attr_values_input(int $product_id, string $field, string $raw): array {
		$raw = trim((string)$raw);
		if ($raw === '') return array();

		// Explicit map
		$map = self::parse_map_flexible($raw);
		if (!empty($map)) return $map;

		// List format -> smart mapping
		$translated = self::parse_list($raw);
		if (empty($translated)) return array();

		$field = strtolower(trim($field));
		$taxonomy = '';
		if ($field === 'beden') $taxonomy = 'pa_beden';
		elseif ($field === 'renk') $taxonomy = 'pa_renk';
		elseif ($field === 'olcu_miktari_listesi') $taxonomy = 'pa_olcu-miktari';

		if ($taxonomy === '') return array();

		$orig_terms = self::get_product_attribute_term_names($product_id, $taxonomy);
		if (empty($orig_terms)) return array();

		// If single term on product, allow single translated value to map it (useful for Quantity, Color)
		if (count($orig_terms) === 1 && count($translated) === 1) {
			return array((string)$orig_terms[0] => (string)$translated[0]);
		}

		// Size matching by numeric key (e.g., "34 beden" <-> "34 size")
		if ($field === 'beden') {
			$index = array();
			foreach ($orig_terms as $t) {
				$k = self::extract_first_number((string)$t);
				if ($k !== '') $index[$k] = (string)$t;
			}
			$out = array();
			foreach ($translated as $tr) {
				$k = self::extract_first_number((string)$tr);
				if ($k === '' || !isset($index[$k])) continue;
				$out[(string)$index[$k]] = (string)$tr;
			}
			return $out;
		}

		// Color (and other non-numeric) matching: only pair by order when counts match
		if (count($orig_terms) === count($translated)) {
			$out = array();
			$max = count($orig_terms);
			for ($i = 0; $i < $max; $i++) {
				$out[(string)$orig_terms[$i]] = (string)$translated[$i];
			}
			return $out;
		}

		return array();
	}

	private static function extract_first_number(string $s): string {
		if (preg_match('/(\d{1,3})/', $s, $m)) {
			return (string)$m[1];
		}
		return '';
	}

	/**
	 * Get product's existing attribute term names for a given taxonomy (pa_*).
	 * Tries product attributes first; falls back to wp_get_post_terms.
	 */
	private static function get_product_attribute_term_names(int $product_id, string $taxonomy): array {
		$taxonomy = trim((string)$taxonomy);
		if ($product_id < 1 || $taxonomy === '') return array();

		$names = array();
		if (function_exists('wc_get_product')) {
			$product = wc_get_product($product_id);
			if ($product) {
				$attrs = $product->get_attributes();
				if (is_array($attrs)) {
					foreach ($attrs as $attr) {
						if (!is_object($attr) || !method_exists($attr, 'is_taxonomy') || !method_exists($attr, 'get_name')) continue;
						if (!$attr->is_taxonomy()) continue;
						if ((string)$attr->get_name() !== $taxonomy) continue;
						if (!method_exists($attr, 'get_options')) continue;
						$opts = $attr->get_options();
						if (!is_array($opts)) $opts = array();
						foreach ($opts as $term_id) {
							$term = get_term((int)$term_id, $taxonomy);
							if ($term && !is_wp_error($term) && isset($term->name)) {
								$names[] = (string)$term->name;
							}
						}
					}
				}
			}
		}

		if (empty($names)) {
			$terms = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));
			if (!is_wp_error($terms) && is_array($terms)) {
				$names = $terms;
			}
		}

		// Normalize + keep order
		$names = array_values(array_filter(array_map(function($x){ return trim((string)$x); }, $names), function($x){ return $x !== ''; }));
		return $names;
	}

	/**
	 * Tag translation import.
	 * If input contains '=', treat it as explicit map Original=Translated.
	 * Otherwise treat it as translated list and pair with product's existing Turkish tags.
	 */
	private static function import_tag_translations_for_product(int $product_id, string $lang, string $tags_raw, bool $dry_run): bool {
		$lang = strtolower($lang);
		$tags_raw = trim($tags_raw);
		if ($tags_raw === '') return false;

		$map = self::parse_map_flexible($tags_raw);
		if (empty($map)) {
			// list format -> pair with existing TR tags
			$translated = self::parse_list($tags_raw);
			if (empty($translated)) return false;

			$terms = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'all'));
			if (is_wp_error($terms) || empty($terms)) return false;

			$terms = array_values(array_filter($terms, function($t){ return is_object($t) && isset($t->term_id) && isset($t->name); }));
			if (empty($terms)) return false;

			$max = min(count($terms), count($translated));
			for ($i = 0; $i < $max; $i++) {
				$map[(string)$terms[$i]->name] = (string)$translated[$i];
			}
		}

		if (empty($map)) return false;

		$store = get_option('hmpcv2_term_translations', array());
		if (!is_array($store)) $store = array();

		$terms = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'all'));
		if (is_wp_error($terms) || empty($terms)) return false;

		$did = false;
		foreach ($terms as $term) {
			if (!is_object($term) || empty($term->term_id) || empty($term->name)) continue;
			$orig_name = (string)$term->name;
			if (!isset($map[$orig_name])) continue;

			$tid = (int)$term->term_id;
			if (!isset($store[$tid]) || !is_array($store[$tid])) $store[$tid] = array();
			if (!isset($store[$tid][$lang]) || !is_array($store[$tid][$lang])) $store[$tid][$lang] = array();
			$store[$tid][$lang]['name'] = (string)$map[$orig_name];
			$did = true;
		}

		if ($did && !$dry_run) {
			update_option('hmpcv2_term_translations', $store, false);
		}

		return $did;
	}
}
