<?php
/**
 * NEXXI Pancake POS Product & Variation Image Manager (by SKU).
 *
 * Features:
 * 1. Manage WooCommerce products synced with Pancake POS by SKU (Parent SKU, Variation SKU, or Product Code).
 * 2. Auto-scan images from Pancake POS (via NEXXI Cloudflare D1 mirror or direct Pancake POS API) and sideload
 *    them into WordPress Media Library (Featured Image + Product Gallery), deduplicated by source URL.
 * 3. Fetch & assign images by Classification / Color (Phân loại / Màu sắc) from Pancake variations to
 *    matching WooCommerce variations (both auto-matched by SKU/Color attribute and manual classification mapping).
 * 4. Optional background WP-Cron auto-scanner for newly uploaded Pancake images.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Unhook any older plugin instance (v1.4.0 or v1.5.0-v2/v3 from wp-content/plugins/nexxi-pancake-manager) if it loaded before the theme
if ( ! class_exists( 'Nexxi_Pancake_Sync_Manager_V4', false ) ) {
	foreach ( array( 'Nexxi_Pancake_Sync_Manager_V3', 'Nexxi_Pancake_Sync_Manager_V2', 'Nexxi_Pancake_Sync_Manager' ) as $legacy_cls ) {
		if ( class_exists( $legacy_cls, false ) && method_exists( $legacy_cls, 'init' ) ) {
			$legacy_pancake_inst = $legacy_cls::init();
			if ( is_object( $legacy_pancake_inst ) ) {
				remove_action( 'admin_menu', array( $legacy_pancake_inst, 'register_menus' ), 25 );
				remove_action( 'admin_init', array( $legacy_pancake_inst, 'handle_post_actions' ) );
				remove_action( 'wp_ajax_nexxi_pancake_refresh_cache', array( $legacy_pancake_inst, 'ajax_refresh_cache' ) );
				remove_action( 'wp_ajax_nexxi_pancake_push_browser_cache', array( $legacy_pancake_inst, 'ajax_push_browser_cache' ) );
				remove_action( 'wp_ajax_nexxi_pancake_sync_single', array( $legacy_pancake_inst, 'ajax_sync_single' ) );
				remove_action( 'wp_ajax_nexxi_pancake_get_classifications', array( $legacy_pancake_inst, 'ajax_get_classifications' ) );
				remove_action( 'wp_ajax_nexxi_pancake_apply_classifications', array( $legacy_pancake_inst, 'ajax_apply_classifications' ) );
				remove_action( 'wp_ajax_nexxi_pancake_link_sku', array( $legacy_pancake_inst, 'ajax_link_sku' ) );
				remove_action( 'wp_ajax_nexxi_pancake_import_product', array( $legacy_pancake_inst, 'ajax_import_product' ) );
				remove_action( 'wp_ajax_nexxi_pancake_delete_disconnect', array( $legacy_pancake_inst, 'ajax_delete_disconnect' ) );
				remove_action( 'wp_ajax_nexxi_pancake_cleanup_duplicates', array( $legacy_pancake_inst, 'ajax_cleanup_duplicates' ) );
				remove_action( 'wp_ajax_nexxi_pancake_set_warehouse', array( $legacy_pancake_inst, 'ajax_set_warehouse' ) );
				remove_action( 'wp_ajax_nexxi_pancake_spa_nav', array( $legacy_pancake_inst, 'ajax_spa_nav' ) );
				remove_action( 'nexxi_pancake_auto_scan_event', array( $legacy_pancake_inst, 'run_cron_auto_scan' ) );
				remove_action( 'init', array( $legacy_pancake_inst, 'maybe_schedule_cron' ) );
				remove_action( 'init', array( $legacy_pancake_inst, 'maybe_auto_cleanup_duplicates' ), 20 );
			}
		}
	}
	if ( defined( 'WP_PLUGIN_DIR' ) ) {
		$legacy_plugin_file = WP_PLUGIN_DIR . '/nexxi-pancake-manager/includes/class-pancake-sync.php';
		if ( @is_file( $legacy_plugin_file ) && @is_writable( $legacy_plugin_file ) && realpath( $legacy_plugin_file ) !== realpath( __FILE__ ) ) {
			@copy( __FILE__, $legacy_plugin_file );
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $legacy_plugin_file, true );
			}
		}
	}
	if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
		$active_plgs = get_option( 'active_plugins', array() );
		if ( is_array( $active_plgs ) && in_array( 'nexxi-pancake-manager/nexxi-pancake-manager.php', $active_plgs, true ) ) {
			update_option( 'active_plugins', array_values( array_diff( $active_plgs, array( 'nexxi-pancake-manager/nexxi-pancake-manager.php' ) ) ) );
		}
	}
}

if ( ! class_exists( 'Nexxi_Pancake_Sync_Manager_V4' ) ) :

final class Nexxi_Pancake_Sync_Manager_V4 {

	const VERSION              = '1.5.1';
	const MENU_SLUG            = 'nexxi-pancake-sync';
	const CACHE_OPTION         = 'nexxi_pancake_catalog_cache_v4';
	const CACHE_TIME_OPT       = 'nexxi_pancake_catalog_cache_time';
	const DISCONNECTED_OPT     = 'nexxi_pancake_disconnected_skus';
	const SETTINGS_OPT         = 'nexxi_pancake_sync_settings';
	const CRON_HOOK            = 'nexxi_pancake_auto_scan_event';
	const DEFAULT_CF_API       = 'https://nexxi-cloudflare-api.nexxivinhomes.workers.dev';
	const DEFAULT_SHOP_ID      = '714396188';
	const DEFAULT_API_KEY      = '355cc43ff67827b8c0b1ceca53227b8c';
	const DEFAULT_WAREHOUSE_ID = '35fa7b6a-3127-414b-96ad-3329f9dac77c'; // Kho mặc định (theo cấu hình đồng bộ POS -> WOO trên Pancake)

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ), 25 );
		add_action( 'admin_init', array( $this, 'handle_post_actions' ) );

		// AJAX endpoints for fast, non-timeout batch processing & interactive classification mapping.
		add_action( 'wp_ajax_nexxi_pancake_refresh_cache', array( $this, 'ajax_refresh_cache' ) );
		add_action( 'wp_ajax_nexxi_pancake_push_browser_cache', array( $this, 'ajax_push_browser_cache' ) );
		add_action( 'wp_ajax_nexxi_pancake_sync_single', array( $this, 'ajax_sync_single' ) );
		add_action( 'wp_ajax_nexxi_pancake_get_classifications', array( $this, 'ajax_get_classifications' ) );
		add_action( 'wp_ajax_nexxi_pancake_apply_classifications', array( $this, 'ajax_apply_classifications' ) );
		add_action( 'wp_ajax_nexxi_pancake_link_sku', array( $this, 'ajax_link_sku' ) );

		// Tab 1 AJAX endpoints: Sync/Import Pancake products to Website & Delete from Website + Disconnect Pancake + Cleanup Duplicates + SPA Navigation.
		add_action( 'wp_ajax_nexxi_pancake_import_product', array( $this, 'ajax_import_product' ) );
		add_action( 'wp_ajax_nexxi_pancake_delete_disconnect', array( $this, 'ajax_delete_disconnect' ) );
		add_action( 'wp_ajax_nexxi_pancake_cleanup_duplicates', array( $this, 'ajax_cleanup_duplicates' ) );
		add_action( 'wp_ajax_nexxi_pancake_set_warehouse', array( $this, 'ajax_set_warehouse' ) );
		add_action( 'wp_ajax_nexxi_pancake_spa_nav', array( $this, 'ajax_spa_nav' ) );

		// Background WP-Cron schedule for automatic Pancake image scanning & one-time duplicate product cleanup.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron_auto_scan' ) );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
		add_action( 'init', array( $this, 'maybe_auto_cleanup_duplicates' ), 20 );
	}

	/**
	 * Register custom 30-minute interval for background scanning.
	 */
	public function register_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['nexxi_every_30m'] ) ) {
			$schedules['nexxi_every_30m'] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => 'Mỗi 30 phút (NEXXI Pancake Auto-Scan)',
			);
		}
		return $schedules;
	}

	/**
	 * Ensure cron matches current settings.
	 */
	public function maybe_schedule_cron() {
		$settings = self::get_settings();
		$next     = wp_next_scheduled( self::CRON_HOOK );
		if ( ! empty( $settings['auto_cron'] ) && ! $next ) {
			wp_schedule_event( time() + 300, 'nexxi_every_30m', self::CRON_HOOK );
		} elseif ( empty( $settings['auto_cron'] ) && $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Get saved plugin settings with defaults.
	 */
	public static function get_settings() {
		$saved = get_option( self::SETTINGS_OPT, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$parsed = wp_parse_args(
			$saved,
			array(
				'cf_api_url'      => self::DEFAULT_CF_API,
				'shop_id'         => self::DEFAULT_SHOP_ID,
				'pancake_api_key' => '',
				'warehouse_id'    => self::DEFAULT_WAREHOUSE_ID,
				'auto_cron'       => 0,
				'auto_set_sku'    => 1,
				'auto_set_thumb'  => 1,
				'auto_set_gallery'=> 1,
				'auto_set_var_img'=> 1,
			)
		);
		if ( '' === trim( (string) $parsed['warehouse_id'] ) ) {
			$parsed['warehouse_id'] = self::DEFAULT_WAREHOUSE_ID;
		}
		return $parsed;
	}

	/**
	 * Return the list of Pancake POS warehouses for the configured shop.
	 */
	public static function get_pancake_warehouses() {
		return array(
			self::DEFAULT_WAREHOUSE_ID             => array(
				'name'    => 'Kho mặc định (Chuẩn cài đặt POS → WOO)',
				'address' => 'Vinhome Grandpark',
			),
			'f43a438d-8476-4d44-a409-6334f9d10301' => array(
				'name'    => 'Kho Tân Phú',
				'address' => '40 Phan Chu Trinh',
			),
		);
	}

	/**
	 * Calculate the exact available stock (`Có thể bán` / `remain_quantity`) for a Pancake variation
	 * strictly adhering to the configured Pancake warehouse (`warehouse_id`, default: `Kho mặc định`).
	 *
	 * @param array       $rv           Raw Pancake variation or cached variation entry.
	 * @param string|null $warehouse_id Target Pancake warehouse ID (`35fa7b6a-3127-414b-96ad-3329f9dac77c` = Kho mặc định).
	 * @return array{stock:int,warehouses:array<string,int>}
	 */
	public static function extract_variation_warehouse_stock( array $rv, $warehouse_id = null ) {
		if ( null === $warehouse_id || '' === trim( (string) $warehouse_id ) ) {
			$settings     = self::get_settings();
			$warehouse_id = $settings['warehouse_id'];
		}
		$warehouse_id = trim( (string) $warehouse_id );

		$wh_map      = array();
		$has_wh_data = false;
		if ( isset( $rv['warehouses'] ) && is_array( $rv['warehouses'] ) ) {
			$has_wh_data = true;
			foreach ( $rv['warehouses'] as $wid => $wqty ) {
				$wqty = max( 0, (int) $wqty );
				if ( $wqty > 0 ) {
					$wh_map[ trim( (string) $wid ) ] = $wqty;
				}
			}
		} elseif ( isset( $rv['variations_warehouses'] ) && is_array( $rv['variations_warehouses'] ) ) {
			$has_wh_data = true;
			foreach ( $rv['variations_warehouses'] as $wh ) {
				if ( ! is_array( $wh ) || empty( $wh['warehouse_id'] ) ) {
					continue;
				}
				$wid  = trim( (string) $wh['warehouse_id'] );
				$wqty = max( 0, (int) ( $wh['remain_quantity'] ?? $wh['actual_remain_quantity'] ?? 0 ) );
				if ( $wqty > 0 ) {
					$wh_map[ $wid ] = ( $wh_map[ $wid ] ?? 0 ) + $wqty;
				}
			}
		}

		// If 'all' warehouses is explicitly chosen, sum across all warehouses or fallback to remain_quantity.
		if ( 'all' === $warehouse_id ) {
			$total = $has_wh_data ? array_sum( $wh_map ) : max( 0, (int) ( $rv['remain_quantity'] ?? $rv['available_quantity'] ?? $rv['stock'] ?? 0 ) );
			return array(
				'stock'      => $total,
				'warehouses' => $wh_map,
			);
		}

		// Strict per-warehouse mode (e.g. Kho mặc định = 35fa7b6a-3127-414b-96ad-3329f9dac77c):
		// Only count stock present in the configured warehouse(s). If the variation has no entry in that warehouse, stock is 0!
		$allowed_ids = array_filter( array_map( 'trim', explode( ',', $warehouse_id ) ) );
		$stock       = 0;
		foreach ( $allowed_ids as $aid ) {
			if ( isset( $wh_map[ $aid ] ) ) {
				$stock += (int) $wh_map[ $aid ];
			}
		}

		return array(
			'stock'      => max( 0, $stock ),
			'warehouses' => $wh_map,
		);
	}

	/**
	 * Register WP Admin menu items (both top-level and under Products).
	 */
	public function register_menus() {
		add_submenu_page(
			'edit.php?post_type=product',
			'Quản Lý Sản Phẩm & Ảnh Pancake (Theo SKU)',
			'🥞 Pancake & Ảnh SKU',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
		add_menu_page(
			'Quản Lý Sản Phẩm & Ảnh Pancake (Theo SKU)',
			'🥞 Pancake SKU',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-images-alt2',
			56
		);
	}

	/**
	 * Handle settings form submission.
	 */
	public function handle_post_actions() {
		if ( ! is_admin() || ! isset( $_POST['nexxi_pancake_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexxi_pancake_settings_nonce'] ) ), 'nexxi_pancake_save_settings' ) ) {
			return;
		}

		$settings = self::get_settings();
		if ( isset( $_POST['cf_api_url'] ) ) {
			$url = esc_url_raw( trim( wp_unslash( $_POST['cf_api_url'] ) ) );
			$settings['cf_api_url'] = $url ? rtrim( $url, '/' ) : self::DEFAULT_CF_API;
		}
		if ( isset( $_POST['shop_id'] ) ) {
			$settings['shop_id'] = sanitize_text_field( wp_unslash( $_POST['shop_id'] ) );
		}
		if ( isset( $_POST['pancake_api_key'] ) ) {
			$settings['pancake_api_key'] = sanitize_text_field( wp_unslash( $_POST['pancake_api_key'] ) );
		}
		if ( isset( $_POST['warehouse_id'] ) ) {
			$wh_id = sanitize_text_field( wp_unslash( $_POST['warehouse_id'] ) );
			$settings['warehouse_id'] = '' !== $wh_id ? $wh_id : self::DEFAULT_WAREHOUSE_ID;
		}
		$settings['auto_cron']        = ! empty( $_POST['auto_cron'] ) ? 1 : 0;
		$settings['auto_set_sku']     = ! empty( $_POST['auto_set_sku'] ) ? 1 : 0;
		$settings['auto_set_thumb']   = ! empty( $_POST['auto_set_thumb'] ) ? 1 : 0;
		$settings['auto_set_gallery'] = ! empty( $_POST['auto_set_gallery'] ) ? 1 : 0;
		$settings['auto_set_var_img'] = ! empty( $_POST['auto_set_var_img'] ) ? 1 : 0;

		update_option( self::SETTINGS_OPT, $settings );
		$this->maybe_schedule_cron();

		if ( ! empty( $settings['pancake_api_key'] ) && 0 !== strpos( $settings['pancake_api_key'], 'ck_' ) && 0 !== strpos( $settings['pancake_api_key'], 'cs_' ) ) {
			self::fetch_and_cache_pancake_catalog( false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'saved_msg'   => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ==========================================================================
	 * 1. PANCAKE CATALOG FETCHING & INDEXING
	 * ========================================================================== */

	/**
	 * Return a valid Pancake POS API Key (falls back to built-in POS API key if blank or if a WooCommerce ck_/cs_ key was pasted).
	 */
	public static function get_effective_api_key() {
		$settings = self::get_settings();
		$key      = isset( $settings['pancake_api_key'] ) ? trim( (string) $settings['pancake_api_key'] ) : '';
		if ( '' === $key || 0 === strpos( $key, 'ck_' ) || 0 === strpos( $key, 'cs_' ) ) {
			return self::DEFAULT_API_KEY;
		}
		return $key;
	}

	/**
	 * Normalize Vietnamese / abbreviated color or classification names into a canonical key
	 * without collapsing distinct classifications (e.g. "Trang kem", "TRANGSEN", "TRANGDAU" stay distinct).
	 */
	public static function normalize_class_key( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || '.' === $raw ) {
			return '';
		}
		$clean = strtolower( remove_accents( $raw ) );
		$clean = preg_replace( '/^(mau|color|phan-loai|phan loai)[\s:\-_]+/i', '', $clean );
		$slug  = sanitize_title( $clean );
		// Only strip trailing numeric code suffixes on short SKU codes like "do01" -> "do", "tra02" -> "tra"
		if ( preg_match( '/^([a-z]{2,5})-?\d{1,2}$/', $slug, $m ) ) {
			$slug = $m[1];
		}
		$aliases = array(
			'tra'    => 'trang',
			'trang'  => 'trang',
			'white'  => 'trang',
			'tk'     => 'trang-kem',
			'do'     => 'do',
			'red'    => 'do',
			'ho'     => 'hong',
			'hon'    => 'hong',
			'hong'   => 'hong',
			'pink'   => 'hong',
			'hdam'   => 'hong-dam',
			'hphan'  => 'hong-phan',
			'hnhat'  => 'hong-nhat',
			'se'     => 'sen',
			'de'     => 'den',
			'den'    => 'den',
			'black'  => 'den',
			'xan'    => 'xanh',
			'xanh'   => 'xanh',
			'blue'   => 'xanh',
			'xla'    => 'xanh-la',
			'xduong' => 'xanh-duong',
			'xd'     => 'xanh-den',
			'xden'   => 'xanh-den',
			'xnhat'  => 'xanh-nhat',
			'xngoc'  => 'xanh-ngoc',
			'va'     => 'vang',
			'van'    => 'vang',
			'vang'   => 'vang',
			'yellow' => 'vang',
			'kem'    => 'kem',
			'cream'  => 'kem',
			'be'     => 'be',
			'beige'  => 'be',
			'nau'    => 'nau',
			'brown'  => 'nau',
			'ti'     => 'tim',
			'tim'    => 'tim',
			'purple' => 'tim',
			'xam'    => 'xam',
			'ghi'    => 'xam',
			'cam'    => 'cam',
			'reu'    => 'reu',
		);
		if ( isset( $aliases[ $slug ] ) ) {
			return $aliases[ $slug ];
		}
		return $slug;
	}

	/**
	 * Pretty Vietnamese label for a classification code (e.g. "DO" -> "Đỏ", "Trang kem" -> "Trắng kem").
	 */
	public static function pretty_class_label( $raw ) {
		$raw_trim = trim( (string) $raw );
		$key      = self::normalize_class_key( $raw_trim );
		$map      = array(
			'trang'      => 'Trắng',
			'trang-kem'  => 'Trắng kem',
			'do'         => 'Đỏ',
			'do-dau'     => 'Đỏ dâu',
			'dau'        => 'Dâu',
			'hong'       => 'Hồng',
			'hong-dam'   => 'Hồng đậm',
			'hong-phan'  => 'Hồng phấn',
			'hong-nhat'  => 'Hồng nhạt',
			'hong-sen'   => 'Hồng sen',
			'sen'        => 'Sen',
			'den'        => 'Đen',
			'xanh'       => 'Xanh',
			'xanh-la'    => 'Xanh lá',
			'xanh-duong' => 'Xanh dương',
			'xanh-den'   => 'Xanh đen',
			'xanh-nhat'  => 'Xanh nhạt',
			'xanh-ngoc'  => 'Xanh ngọc',
			'vang'       => 'Vàng',
			'kem'        => 'Kem',
			'be'         => 'Be',
			'nau'        => 'Nâu',
			'tim'        => 'Tím',
			'xam'        => 'Xám',
			'cam'        => 'Cam',
			'reu'        => 'Xanh rêu',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] . ' (' . strtoupper( $raw_trim ) . ')' : $raw_trim;
	}

	/**
	 * Extract leading product code from a product name or SKU (e.g. "2607.8 SET ÁO..." -> "2607.8", "2208.Quần" -> "2208").
	 */
	public static function extract_leading_code( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/^([0-9]{3,5}(?:\.[0-9]{1,3})?)(?=[\s._\-]|[^\d.]|$)/u', $text, $m ) ) {
			return rtrim( $m[1], '.' );
		}
		return '';
	}

	/**
	 * Path to the local JSON cache file in wp-content/uploads (avoids MySQL max_allowed_packet limits).
	 */
	public static function get_cache_file_path() {
		$up = wp_upload_dir();
		if ( ! empty( $up['basedir'] ) && is_writable( $up['basedir'] ) ) {
			return trailingslashit( $up['basedir'] ) . 'nexxi-pancake-catalog-v4.json';
		}
		return '';
	}

	/**
	 * Merge split product/variation stub entries ONLY when they share the exact same Parent SKU (`sku`),
	 * and recalculate variation/product stock strictly for the configured Pancake warehouse (`Kho mặc định`).
	 */
	public static function merge_catalog_entries_by_sku( array $catalog ) {
		if ( empty( $catalog ) ) {
			return array();
		}
		$settings     = self::get_settings();
		$warehouse_id = $settings['warehouse_id'];
		$merged       = array();
		$sku_to_pid   = array();

		foreach ( $catalog as $pid => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			// Recalculate stock strictly according to the active Pancake warehouse if per-warehouse data is present
			if ( ! empty( $p['variations'] ) && is_array( $p['variations'] ) ) {
				$recalc_total = 0;
				foreach ( $p['variations'] as &$pv ) {
					if ( isset( $pv['warehouses'] ) && is_array( $pv['warehouses'] ) ) {
						$wh_res      = self::extract_variation_warehouse_stock( $pv, $warehouse_id );
						$pv['stock'] = $wh_res['stock'];
					}
					$recalc_total += max( 0, (int) ( $pv['stock'] ?? 0 ) );
				}
				unset( $pv );
				$p['total_stock'] = $recalc_total;
			}

			$sku  = strtoupper( trim( (string) ( $p['sku'] ?? '' ) ) );
			$code = strtoupper( trim( (string) ( $p['code'] ?? self::extract_leading_code( $p['name'] ?? '' ) ) ) );
			$p['code'] = $code ? $code : ( $p['code'] ?? '' );

			$target_pid = '';
			if ( '' !== $sku && isset( $sku_to_pid[ $sku ] ) ) {
				$target_pid = $sku_to_pid[ $sku ];
			}

			if ( '' === $target_pid || ! isset( $merged[ $target_pid ] ) ) {
				$merged[ $pid ] = $p;
				if ( '' !== $sku ) {
					$sku_to_pid[ $sku ] = $pid;
				}
				continue;
			}

			// Merge $p into $merged[$target_pid] (same exact Parent SKU)
			$m = &$merged[ $target_pid ];
			if ( empty( $m['sku'] ) && ! empty( $p['sku'] ) ) {
				$m['sku'] = $p['sku'];
			}
			if ( ! empty( $p['all_images'] ) && is_array( $p['all_images'] ) ) {
				foreach ( $p['all_images'] as $u ) {
					if ( $u && ! in_array( $u, $m['all_images'], true ) ) {
						$m['all_images'][] = $u;
					}
				}
			}
			if ( empty( $m['product_image'] ) && ! empty( $m['all_images'][0] ) ) {
				$m['product_image'] = $m['all_images'][0];
			}
			if ( ! empty( $p['class_images'] ) && is_array( $p['class_images'] ) ) {
				foreach ( $p['class_images'] as $ck => $ci ) {
					$norm_ck = self::normalize_class_key( $ci['raw'] ?? $ck );
					if ( '' === $norm_ck ) {
						continue;
					}
					$ci['key']   = $norm_ck;
					$ci['label'] = self::pretty_class_label( $ci['raw'] ?? $ck );
					if ( ! isset( $m['class_images'][ $norm_ck ] ) ) {
						$m['class_images'][ $norm_ck ] = $ci;
					}
				}
			}
			if ( ! empty( $p['var_images'] ) && is_array( $p['var_images'] ) ) {
				foreach ( $p['var_images'] as $vk => $vu ) {
					$m['var_images'][ $vk ] = $vu;
				}
			}
			if ( ! empty( $p['variations'] ) && is_array( $p['variations'] ) ) {
				$m['variations'] = array_merge( $m['variations'], $p['variations'] );
			}
			$m['total_stock'] = (int) ( $m['total_stock'] ?? 0 ) + (int) ( $p['total_stock'] ?? 0 );
			if ( ! empty( $p['min_price'] ) && ( empty( $m['min_price'] ) || $p['min_price'] < $m['min_price'] ) ) {
				$m['min_price'] = $p['min_price'];
			}
			if ( ! empty( $p['max_price'] ) && $p['max_price'] > ( $m['max_price'] ?? 0 ) ) {
				$m['max_price'] = $p['max_price'];
			}
			unset( $m );
		}

		return $merged;
	}

	/**
	 * Get map of Pancake SKUs / IDs that have been explicitly disconnected or deleted from the Website.
	 */
	public static function get_disconnected_map() {
		$map = get_option( self::DISCONNECTED_OPT, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Mark a Pancake SKU and/or Product ID as disconnected from the Website.
	 */
	public static function mark_sku_disconnected( $pancake_sku = '', $pancake_id = '' ) {
		$map = self::get_disconnected_map();
		$sku = strtoupper( trim( (string) $pancake_sku ) );
		$pid = trim( (string) $pancake_id );
		if ( '' !== $sku ) {
			$map[ 'SKU:' . $sku ] = time();
		}
		if ( '' !== $pid ) {
			$map[ 'ID:' . $pid ] = time();
		}
		update_option( self::DISCONNECTED_OPT, $map, false );
	}

	/**
	 * Clear disconnected flag when a product is re-synced / imported back to the Website.
	 */
	public static function unmark_sku_disconnected( $pancake_sku = '', $pancake_id = '' ) {
		$map = self::get_disconnected_map();
		$sku = strtoupper( trim( (string) $pancake_sku ) );
		$pid = trim( (string) $pancake_id );
		$changed = false;
		if ( '' !== $sku && isset( $map[ 'SKU:' . $sku ] ) ) {
			unset( $map[ 'SKU:' . $sku ] );
			$changed = true;
		}
		if ( '' !== $pid && isset( $map[ 'ID:' . $pid ] ) ) {
			unset( $map[ 'ID:' . $pid ] );
			$changed = true;
		}
		if ( $changed ) {
			update_option( self::DISCONNECTED_OPT, $map, false );
		}
	}

	/**
	 * Check if a Pancake product is currently marked as disconnected from the Website.
	 */
	public static function is_pancake_product_disconnected( array $pancake_item ) {
		$map = self::get_disconnected_map();
		if ( empty( $map ) ) {
			return false;
		}
		$sku = strtoupper( trim( (string) ( $pancake_item['sku'] ?? '' ) ) );
		$pid = trim( (string) ( $pancake_item['id'] ?? '' ) );
		if ( '' !== $sku && isset( $map[ 'SKU:' . $sku ] ) ) {
			return true;
		}
		if ( '' !== $pid && isset( $map[ 'ID:' . $pid ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Save catalog cache to local JSON file (and compact copy to wp_options if uploads dir is unavailable).
	 */
	public static function save_catalog_cache( array $products_by_id ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

		$products_by_id = self::merge_catalog_entries_by_sku( $products_by_id );
		$file           = self::get_cache_file_path();
		$wrote_file     = false;
		if ( $file ) {
			$wrote_file = ( false !== file_put_contents( $file, wp_json_encode( $products_by_id ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! $wrote_file ) {
			update_option( self::CACHE_OPTION, array_slice( $products_by_id, 0, 150, true ), false );
		}
		update_option( self::CACHE_TIME_OPT, time(), false );
	}

	/**
	 * Helper: Extract all valid HTTP image URLs from a Pancake product or variation record.
	 */
	public static function extract_record_images( array $record ) {
		$urls = array();
		if ( ! empty( $record['image_url'] ) && is_string( $record['image_url'] ) ) {
			$u = trim( $record['image_url'] );
			if ( 0 === strpos( $u, 'http' ) ) {
				$urls[] = $u;
			}
		}
		if ( ! empty( $record['images'] ) ) {
			$imgs = is_array( $record['images'] ) ? $record['images'] : json_decode( (string) $record['images'], true );
			if ( is_array( $imgs ) ) {
				foreach ( $imgs as $item ) {
					$u = '';
					if ( is_string( $item ) ) {
						$u = trim( $item );
					} elseif ( is_array( $item ) ) {
						$u = trim( (string) ( $item['url'] ?? $item['image_url'] ?? $item['src'] ?? '' ) );
					}
					if ( $u && 0 === strpos( $u, 'http' ) && ! in_array( $u, $urls, true ) ) {
						$urls[] = $u;
					}
				}
			}
		}
		return $urls;
	}

	/**
	 * Helper: Extract Color and Size from a Pancake variation's fields/attributes and SKU.
	 */
	public static function extract_variation_color_and_size( array $vrow, $vsku ) {
		$attrs_json = array();
		if ( ! empty( $vrow['fields'] ) ) {
			$attrs_json = is_array( $vrow['fields'] ) ? $vrow['fields'] : json_decode( (string) $vrow['fields'], true );
		} elseif ( ! empty( $vrow['attributes'] ) ) {
			$attrs_json = is_array( $vrow['attributes'] ) ? $vrow['attributes'] : json_decode( (string) $vrow['attributes'], true );
		}
		if ( ! is_array( $attrs_json ) ) {
			$attrs_json = array();
		}

		$color_val = '';
		$size_val  = '';
		$unclassified = array();

		foreach ( $attrs_json as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$aname = strtolower( remove_accents( trim( (string) ( $a['name'] ?? $a['key'] ?? '' ) ) ) );
			$aval  = trim( (string) ( $a['value'] ?? '' ) );
			if ( '' === $aval ) {
				continue;
			}
			if ( false !== strpos( $aname, 'mau' ) || false !== strpos( $aname, 'color' ) || false !== strpos( $aname, 'phan loai' ) || false !== strpos( $aname, 'kieu' ) ) {
				$color_val = $aval;
			} elseif ( false !== strpos( $aname, 'size' ) || false !== strpos( $aname, 'kich co' ) || 'co' === $aname ) {
				$size_val = $aval;
			} else {
				$unclassified[] = $aval;
			}
		}

		foreach ( $unclassified as $uval ) {
			if ( preg_match( '/^\d{1,2}$/', $uval ) && '' === $size_val ) {
				$size_val = $uval;
			} elseif ( '' === $color_val && ! is_numeric( $uval ) ) {
				$color_val = $uval;
			}
		}

		if ( '' === $color_val && '' !== $vsku && false !== strpos( $vsku, '-' ) ) {
			$parts = explode( '-', $vsku );
			if ( count( $parts ) >= 3 ) {
				$color_val = $parts[1];
				if ( '' === $size_val ) {
					$size_val = $parts[2];
				}
			}
		}

		return array(
			'color' => $color_val,
			'size'  => $size_val,
		);
	}

	/**
	 * Parse a single product object from Pancake POS API (`pos.pages.fm`) into our normalized catalog structure.
	 */
	public static function parse_pancake_pos_product( array $raw_prod ) {
		$pid = trim( (string) ( $raw_prod['id'] ?? $raw_prod['pancake_product_id'] ?? '' ) );
		if ( '' === $pid ) {
			return null;
		}
		$nested_vars = isset( $raw_prod['variations'] ) && is_array( $raw_prod['variations'] ) ? $raw_prod['variations'] : array();
		$custom_id   = trim( (string) ( $raw_prod['custom_id'] ?? $raw_prod['sku'] ?? '' ) );
		if ( '' === $custom_id && ! empty( $nested_vars ) ) {
			foreach ( $nested_vars as $nv ) {
				$first_vsku = trim( (string) ( $nv['display_id'] ?? $nv['sku'] ?? '' ) );
				if ( false !== strpos( $first_vsku, '-' ) ) {
					$custom_id = explode( '-', $first_vsku )[0];
					break;
				}
			}
		}
		$display_id = trim( (string) ( $raw_prod['display_id'] ?? $custom_id ) );
		$parent_sku = $custom_id ? $custom_id : $display_id;
		$name       = trim( (string) ( $raw_prod['name'] ?? '' ) );
		$code       = self::extract_leading_code( $name );
		$prod_imgs  = self::extract_record_images( $raw_prod );

		$cats = array();
		if ( ! empty( $raw_prod['categories'] ) && is_array( $raw_prod['categories'] ) ) {
			foreach ( $raw_prod['categories'] as $c ) {
				$cname = is_string( $c ) ? trim( $c ) : trim( (string) ( $c['name'] ?? '' ) );
				if ( '' !== $cname && ! in_array( $cname, $cats, true ) ) {
					$cats[] = $cname;
				}
			}
		}

		$item = array(
			'id'            => $pid,
			'sku'           => $parent_sku,
			'display_id'    => $display_id,
			'code'          => $code,
			'name'          => $name,
			'categories'    => $cats,
			'inserted_at'   => trim( (string) ( $raw_prod['inserted_at'] ?? '' ) ),
			'product_image' => ! empty( $prod_imgs[0] ) ? $prod_imgs[0] : '',
			'all_images'    => $prod_imgs,
			'class_images'  => array(),
			'var_images'    => array(),
			'variations'    => array(),
			'total_stock'   => 0,
			'min_price'     => 0,
			'max_price'     => 0,
		);

		foreach ( $nested_vars as $rv ) {
			if ( ! is_array( $rv ) || ! empty( $rv['is_removed'] ) ) {
				continue;
			}
			$vsku     = trim( (string) ( $rv['display_id'] ?? $rv['sku'] ?? $rv['custom_id'] ?? '' ) );
			$vimgs    = self::extract_record_images( $rv );
			$vimg     = ! empty( $vimgs[0] ) ? $vimgs[0] : '';
			$price    = (float) ( $rv['retail_price'] ?? $rv['price'] ?? 0 );
			$wh_stock = self::extract_variation_warehouse_stock( $rv );
			$qty      = $wh_stock['stock'];

			$cs        = self::extract_variation_color_and_size( $rv, $vsku );
			$color_val = $cs['color'];
			$size_val  = $cs['size'];
			$class_key = self::normalize_class_key( $color_val );

			foreach ( $vimgs as $u ) {
				if ( ! in_array( $u, $item['all_images'], true ) ) {
					$item['all_images'][] = $u;
				}
			}
			if ( $vimg ) {
				if ( '' === $item['product_image'] ) {
					$item['product_image'] = $vimg;
				}
				if ( '' !== $vsku ) {
					$item['var_images'][ strtoupper( $vsku ) ] = $vimg;
				}
				if ( '' !== $class_key ) {
					if ( ! isset( $item['class_images'][ $class_key ] ) ) {
						$item['class_images'][ $class_key ] = array(
							'key'   => $class_key,
							'raw'   => $color_val,
							'label' => self::pretty_class_label( $color_val ),
							'image' => $vimg,
							'skus'  => array(),
						);
					}
					if ( '' !== $vsku && count( $item['class_images'][ $class_key ]['skus'] ) < 15 && ! in_array( $vsku, $item['class_images'][ $class_key ]['skus'], true ) ) {
						$item['class_images'][ $class_key ]['skus'][] = $vsku;
					}
				}
			} elseif ( '' !== $class_key && isset( $item['class_images'][ $class_key ] ) && '' !== $vsku ) {
				if ( count( $item['class_images'][ $class_key ]['skus'] ) < 15 && ! in_array( $vsku, $item['class_images'][ $class_key ]['skus'], true ) ) {
					$item['class_images'][ $class_key ]['skus'][] = $vsku;
				}
			}

			if ( $qty > 0 ) {
				$item['total_stock'] += $qty;
			}
			if ( $price > 0 ) {
				if ( 0 === (int) $item['min_price'] || $price < $item['min_price'] ) {
					$item['min_price'] = $price;
				}
				if ( $price > $item['max_price'] ) {
					$item['max_price'] = $price;
				}
			}
			$item['variations'][] = array(
				'sku'        => $vsku,
				'color_raw'  => $color_val,
				'color_key'  => $class_key,
				'size'       => $size_val,
				'image'      => $vimg,
				'price'      => $price,
				'stock'      => max( 0, $qty ),
				'warehouses' => $wh_stock['warehouses'],
			);
		}

		// Second pass: if some variations of the same color didn't have images[0] set on Pancake, inherit from class_images[color_key]
		foreach ( $item['variations'] as &$v_entry ) {
			if ( empty( $v_entry['image'] ) && ! empty( $v_entry['color_key'] ) && ! empty( $item['class_images'][ $v_entry['color_key'] ]['image'] ) ) {
				$v_entry['image'] = $item['class_images'][ $v_entry['color_key'] ]['image'];
				if ( ! empty( $v_entry['sku'] ) ) {
					$item['var_images'][ strtoupper( $v_entry['sku'] ) ] = $v_entry['image'];
				}
			}
		}
		unset( $v_entry );

		return $item;
	}

	/**
	 * Determine the primary lookup SKU for a WooCommerce product (Parent SKU, or parent prefix of its Variation SKU).
	 */
	public static function get_wc_product_lookup_sku( WC_Product $product ) {
		$wc_sku = strtoupper( trim( (string) $product->get_sku() ) );
		if ( '' !== $wc_sku ) {
			if ( false !== strpos( $wc_sku, '-' ) ) {
				return explode( '-', $wc_sku )[0];
			}
			return $wc_sku;
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$c_sku = strtoupper( trim( (string) get_post_meta( $child_id, '_sku', true ) ) );
				if ( '' !== $c_sku ) {
					return false !== strpos( $c_sku, '-' ) ? explode( '-', $c_sku )[0] : $c_sku;
				}
			}
		}
		$linked_sku = strtoupper( trim( (string) get_post_meta( $product->get_id(), '_nexxi_pancake_sku', true ) ) );
		if ( '' !== $linked_sku ) {
			return $linked_sku;
		}
		return '';
	}

	/**
	 * Collect all unique Product SKUs across all WooCommerce products on the Website via a single fast SQL query.
	 */
	public static function get_all_wc_product_lookup_skus( $wc_products = null ) {
		global $wpdb;
		$raw_skus = $wpdb->get_col(
			"SELECT DISTINCT UPPER(TRIM(pm.meta_value))
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'draft', 'private', 'pending')
			   AND pm.meta_key IN ('_sku', '_nexxi_pancake_sku')
			   AND pm.meta_value != ''"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$skus = array();
		foreach ( (array) $raw_skus as $s ) {
			$s = trim( (string) $s );
			if ( '' === $s ) {
				continue;
			}
			if ( false !== strpos( $s, '-' ) ) {
				$s = explode( '-', $s )[0];
			}
			if ( '' !== $s ) {
				$skus[ $s ] = $s;
			}
		}
		return array_values( $skus );
	}

	/**
	 * Fast SQL loader for all WooCommerce products & their variation counts/SKUs/thumbnails
	 * without instantiating hundreds of heavy WC_Product_Variable objects on admin page load.
	 */
	public static function get_fast_wc_catalog_rows() {
		global $wpdb;
		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_status, post_name
			 FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status IN ('publish', 'draft', 'private')
			 ORDER BY ID DESC"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $posts ) ) {
			return array();
		}

		$by_id = array();
		foreach ( $posts as $p ) {
			$pid = (int) $p->ID;
			$by_id[ $pid ] = array(
				'id'            => $pid,
				'name'          => (string) $p->post_title,
				'status'        => (string) $p->post_status,
				'slug'          => (string) $p->post_name,
				'sku'           => '',
				'wc_sku'        => '',
				'thumb_id'      => 0,
				'gallery_ids'   => array(),
				'gallery_count' => 0,
				'pancake_id'    => '',
				'pancake_sku'   => '',
				'var_total'     => 0,
				'var_with_img'  => 0,
				'child_skus'    => array(),
			);
		}

		// Load relevant product postmeta in 1 single query
		$meta_rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'product'
			   AND p.post_status IN ('publish', 'draft', 'private')
			   AND pm.meta_key IN ('_sku', '_thumbnail_id', '_product_image_gallery', '_nexxi_pancake_product_id', '_nexxi_pancake_sku')"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $meta_rows as $mr ) {
			$pid = (int) $mr->post_id;
			if ( ! isset( $by_id[ $pid ] ) ) {
				continue;
			}
			$mk = (string) $mr->meta_key;
			$mv = trim( (string) $mr->meta_value );
			if ( '_sku' === $mk ) {
				$by_id[ $pid ]['sku']    = $mv;
				$by_id[ $pid ]['wc_sku'] = $mv;
			} elseif ( '_thumbnail_id' === $mk ) {
				$by_id[ $pid ]['thumb_id'] = (int) $mv;
			} elseif ( '_product_image_gallery' === $mk && '' !== $mv ) {
				$g_ids = array_values( array_filter( array_map( 'absint', explode( ',', $mv ) ) ) );
				$by_id[ $pid ]['gallery_ids']   = $g_ids;
				$by_id[ $pid ]['gallery_count'] = count( $g_ids );
			} elseif ( '_nexxi_pancake_product_id' === $mk ) {
				$by_id[ $pid ]['pancake_id'] = $mv;
			} elseif ( '_nexxi_pancake_sku' === $mk ) {
				$by_id[ $pid ]['pancake_sku'] = $mv;
			}
		}

		// Load child variation counts, SKUs, and thumbnail counts in 1 single query
		$var_rows = $wpdb->get_results(
			"SELECT v.ID, v.post_parent,
			        MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value ELSE '' END) AS v_sku,
			        MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN CAST(pm.meta_value AS UNSIGNED) ELSE 0 END) AS v_thumb
			 FROM {$wpdb->posts} v
			 LEFT JOIN {$wpdb->postmeta} pm ON v.ID = pm.post_id AND pm.meta_key IN ('_sku', '_thumbnail_id')
			 WHERE v.post_type = 'product_variation'
			   AND v.post_status IN ('publish', 'private')
			   AND v.post_parent > 0
			 GROUP BY v.ID, v.post_parent"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $var_rows as $vr ) {
			$parent_id = (int) $vr->post_parent;
			if ( ! isset( $by_id[ $parent_id ] ) ) {
				continue;
			}
			$by_id[ $parent_id ]['var_total']++;
			if ( (int) $vr->v_thumb > 0 ) {
				$by_id[ $parent_id ]['var_with_img']++;
			}
			$vsku = trim( (string) $vr->v_sku );
			if ( '' !== $vsku ) {
				$by_id[ $parent_id ]['child_skus'][] = $vsku;
			}
		}

		return $by_id;
	}

	/**
	 * Match a lightweight WC catalog row (from get_fast_wc_catalog_rows) to Pancake catalog maps.
	 */
	public static function match_fast_wc_row( array $wrow, array $maps ) {
		$wc_sku = strtoupper( trim( (string) $wrow['wc_sku'] ) );
		if ( '' !== $wc_sku ) {
			if ( isset( $maps['by_sku'][ $wc_sku ] ) ) {
				return array( 'pancake' => $maps['by_sku'][ $wc_sku ], 'match_type' => 'exact_sku' );
			}
			if ( isset( $maps['by_var_sku'][ $wc_sku ] ) ) {
				return array( 'pancake' => $maps['by_var_sku'][ $wc_sku ], 'match_type' => 'var_sku_on_parent' );
			}
			if ( false !== strpos( $wc_sku, '-' ) ) {
				$prefix = explode( '-', $wc_sku )[0];
				if ( isset( $maps['by_sku'][ $prefix ] ) ) {
					return array( 'pancake' => $maps['by_sku'][ $prefix ], 'match_type' => 'sku_prefix' );
				}
			}
			if ( ! preg_match( '/^\d+(\.\d+)?$/', $wc_sku ) ) {
				return array( 'pancake' => null, 'match_type' => '' );
			}
		}

		if ( ! empty( $wrow['child_skus'] ) ) {
			foreach ( $wrow['child_skus'] as $c_sku_raw ) {
				$c_sku = strtoupper( trim( (string) $c_sku_raw ) );
				if ( '' === $c_sku ) {
					continue;
				}
				if ( isset( $maps['by_var_sku'][ $c_sku ] ) ) {
					return array( 'pancake' => $maps['by_var_sku'][ $c_sku ], 'match_type' => 'child_var_sku' );
				}
				if ( false !== strpos( $c_sku, '-' ) ) {
					$prefix = explode( '-', $c_sku )[0];
					if ( isset( $maps['by_sku'][ $prefix ] ) ) {
						return array( 'pancake' => $maps['by_sku'][ $prefix ], 'match_type' => 'child_sku_prefix' );
					}
				}
			}
		}

		$linked_id = trim( (string) $wrow['pancake_id'] );
		if ( '' !== $linked_id && isset( $maps['by_id'][ $linked_id ] ) ) {
			return array( 'pancake' => $maps['by_id'][ $linked_id ], 'match_type' => 'pancake_id' );
		}

		$linked_sku = strtoupper( trim( (string) $wrow['pancake_sku'] ) );
		if ( '' !== $linked_sku && isset( $maps['by_sku'][ $linked_sku ] ) ) {
			return array( 'pancake' => $maps['by_sku'][ $linked_sku ], 'match_type' => 'manual_sku' );
		}

		$norm_nam = sanitize_title( remove_accents( (string) $wrow['name'] ) );
		if ( $norm_nam && isset( $maps['by_norm_name'][ $norm_nam ] ) ) {
			return array( 'pancake' => $maps['by_norm_name'][ $norm_nam ], 'match_type' => 'exact_name' );
		}

		return array( 'pancake' => null, 'match_type' => '' );
	}

	/**
	 * Query Pancake POS directly by a single Product SKU (`search={sku}`).
	 */
	public static function query_pancake_product_by_sku( $sku, $save_to_cache = false ) {
		$sku = strtoupper( trim( (string) $sku ) );
		if ( '' === $sku ) {
			return null;
		}
		if ( false !== strpos( $sku, '-' ) ) {
			$sku = explode( '-', $sku )[0];
		}

		$settings    = self::get_settings();
		$cf_base     = rtrim( $settings['cf_api_url'], '/' );
		$shop_id     = trim( (string) $settings['shop_id'] ) ?: self::DEFAULT_SHOP_ID;
		$api_key     = self::get_effective_api_key();
		$req_headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		);

		$urls_to_try = array(
			sprintf(
				'https://pos.pages.fm/api/v1/shops/%s/products?api_key=%s&page_number=1&page_size=30&search=%s',
				rawurlencode( $shop_id ),
				rawurlencode( $api_key ),
				rawurlencode( $sku )
			),
		);
		if ( $cf_base ) {
			$urls_to_try[] = sprintf(
				'%s/api/pancake-by-skus?shop_id=%s&skus=%s',
				$cf_base,
				rawurlencode( $shop_id ),
				rawurlencode( $sku )
			);
		}

		$matched_item = null;
		foreach ( $urls_to_try as $url ) {
			$resp = wp_remote_get(
				$url,
				array(
					'timeout'   => 15,
					'sslverify' => false,
					'headers'   => $req_headers,
				)
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$body  = json_decode( wp_remote_retrieve_body( $resp ), true );
			$items = array();
			if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
				$items = $body['data'];
			} elseif ( isset( $body['products'] ) && is_array( $body['products'] ) ) {
				$items = $body['products'];
			}
			foreach ( $items as $raw_prod ) {
				if ( ! is_array( $raw_prod ) ) {
					continue;
				}
				$parsed = self::parse_pancake_pos_product( $raw_prod );
				if ( ! $parsed ) {
					continue;
				}
				if ( strtoupper( $parsed['sku'] ) === $sku || strtoupper( $parsed['display_id'] ) === $sku ) {
					$matched_item = $parsed;
					break 2;
				}
				foreach ( $parsed['variations'] as $pv ) {
					$pv_sku = strtoupper( (string) $pv['sku'] );
					if ( $pv_sku === $sku || 0 === strpos( $pv_sku, $sku . '-' ) ) {
						$matched_item = $parsed;
						break 3;
					}
				}
			}
		}

		if ( $matched_item && $save_to_cache ) {
			$file     = self::get_cache_file_path();
			$existing = array();
			if ( $file && is_readable( $file ) ) {
				$dec = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_array( $dec ) ) {
					$existing = $dec;
				}
			}
			$existing[ $matched_item['id'] ] = $matched_item;
			self::save_catalog_cache( $existing );
		}

		return $matched_item;
	}

	/**
	 * Query Pancake POS live by any search keyword / SKU / batch code (`search={q}`) and merge all matching products into cache.
	 */
	public static function query_pancake_products_by_search( $search_q ) {
		$search_q = trim( (string) $search_q );
		if ( '' === $search_q ) {
			return array();
		}

		$settings    = self::get_settings();
		$cf_base     = rtrim( $settings['cf_api_url'], '/' );
		$shop_id     = trim( (string) $settings['shop_id'] ) ?: self::DEFAULT_SHOP_ID;
		$api_key     = self::get_effective_api_key();
		$req_headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		);

		$urls_to_try = array();
		if ( $cf_base ) {
			$urls_to_try[] = sprintf(
				'%s/api/pancake-live-products?shop_id=%s&api_key=%s&page_number=1&page_size=50&search=%s',
				$cf_base,
				rawurlencode( $shop_id ),
				rawurlencode( $api_key ),
				rawurlencode( $search_q )
			);
		}
		$urls_to_try[] = sprintf(
			'https://pos.pages.fm/api/v1/shops/%s/products?api_key=%s&page_number=1&page_size=50&search=%s',
			rawurlencode( $shop_id ),
			rawurlencode( $api_key ),
			rawurlencode( $search_q )
		);

		$found = array();
		foreach ( $urls_to_try as $url ) {
			$resp = wp_remote_get(
				$url,
				array(
					'timeout'   => 15,
					'sslverify' => false,
					'headers'   => $req_headers,
				)
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$body  = json_decode( wp_remote_retrieve_body( $resp ), true );
			$items = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : ( isset( $body['products'] ) && is_array( $body['products'] ) ? $body['products'] : array() );
			foreach ( $items as $raw_prod ) {
				if ( ! is_array( $raw_prod ) ) {
					continue;
				}
				$parsed = self::parse_pancake_pos_product( $raw_prod );
				if ( $parsed ) {
					$found[ $parsed['id'] ] = $parsed;
				}
			}
			if ( ! empty( $found ) ) {
				break;
			}
		}

		if ( ! empty( $found ) ) {
			$file     = self::get_cache_file_path();
			$existing = array();
			if ( $file && is_readable( $file ) ) {
				$dec = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_array( $dec ) ) {
					$existing = $dec;
				}
			}
			foreach ( $found as $fid => $fitem ) {
				$existing[ $fid ] = $fitem;
			}
			self::save_catalog_cache( $existing );
		}

		return $found;
	}

	/**
	 * Fetch fresh Pancake products + variations by querying Pancake POS directly for all Website Product SKUs
	 * (`pos.pages.fm` / Cloudflare `/api/pancake-by-skus`) AND recent pages of Pancake POS products.
	 *
	 * @param bool $trigger_live_sync Whether to pull fresh data from Pancake POS first.
	 * @return array|WP_Error
	 */
	public static function fetch_and_cache_pancake_catalog( $trigger_live_sync = false ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		@set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged

		$settings    = self::get_settings();
		$cf_base     = rtrim( $settings['cf_api_url'], '/' );
		$shop_id     = trim( (string) $settings['shop_id'] );
		if ( '' === $shop_id ) {
			$shop_id = self::DEFAULT_SHOP_ID;
		}
		$api_key     = self::get_effective_api_key();
		$req_headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		);

		$products_by_id = array();
		$file           = self::get_cache_file_path();
		if ( $file && is_readable( $file ) ) {
			$dec = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $dec ) ) {
				$products_by_id = $dec;
			}
			unset( $dec );
		} elseif ( $file ) {
			$legacy_v3 = dirname( $file ) . '/nexxi-pancake-catalog-v3.json';
			if ( is_readable( $legacy_v3 ) ) {
				$dec = json_decode( (string) file_get_contents( $legacy_v3 ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_array( $dec ) ) {
					$products_by_id = $dec;
				}
				unset( $dec );
			}
		}

		// 1. Query Pancake POS by Website Product SKUs in batches via Cloudflare /api/pancake-by-skus
		$wc_skus = self::get_all_wc_product_lookup_skus();
		if ( ! empty( $wc_skus ) && $cf_base ) {
			$chunks     = array_chunk( $wc_skus, 25 );
			$max_chunks = $trigger_live_sync ? 6 : 2;
			$chunk_idx  = 0;
			foreach ( $chunks as $chunk ) {
				if ( $chunk_idx >= $max_chunks ) {
					break;
				}
				$chunk_idx++;
				$batch_url = sprintf(
					'%s/api/pancake-by-skus?shop_id=%s&api_key=%s&skus=%s',
					$cf_base,
					rawurlencode( $shop_id ),
					rawurlencode( $api_key ),
					rawurlencode( implode( ',', $chunk ) )
				);
				$resp = wp_remote_get(
					$batch_url,
					array(
						'timeout'   => $trigger_live_sync ? 15 : 8,
						'sslverify' => false,
						'headers'   => $req_headers,
					)
				);
				if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
					$body  = json_decode( wp_remote_retrieve_body( $resp ), true );
					unset( $resp );
					$items = isset( $body['products'] ) && is_array( $body['products'] ) ? $body['products'] : ( isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array() );
					unset( $body );
					foreach ( $items as $raw_prod ) {
						if ( ! is_array( $raw_prod ) ) {
							continue;
						}
						$parsed = self::parse_pancake_pos_product( $raw_prod );
						if ( $parsed ) {
							$products_by_id[ $parsed['id'] ] = $parsed;
						}
					}
					unset( $items );
				} else {
					unset( $resp );
				}
			}
		}

		// 2. Fetch pages via Cloudflare /api/pancake-live-products?pages=... in memory-safe single-page batches
		$fetched_via_cf_pages = false;
		if ( $cf_base ) {
			$page_groups = $trigger_live_sync ? array( '1', '2', '3', '4', '5', '6' ) : array( '1', '2' );
			foreach ( $page_groups as $pages_group ) {
				$multi_url = sprintf(
					'%s/api/pancake-live-products?shop_id=%s&api_key=%s&pages=%s&page_size=50',
					$cf_base,
					rawurlencode( $shop_id ),
					rawurlencode( $api_key ),
					rawurlencode( $pages_group )
				);
				$resp = wp_remote_get(
					$multi_url,
					array(
						'timeout'   => $trigger_live_sync ? 15 : 8,
						'sslverify' => false,
						'headers'   => $req_headers,
					)
				);
				if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
					$body       = json_decode( wp_remote_retrieve_body( $resp ), true );
					unset( $resp );
					$page_prods = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
					unset( $body );
					if ( ! empty( $page_prods ) ) {
						$fetched_via_cf_pages = true;
						foreach ( $page_prods as $raw_prod ) {
							if ( ! is_array( $raw_prod ) ) {
								continue;
							}
							$parsed = self::parse_pancake_pos_product( $raw_prod );
							if ( $parsed ) {
								$products_by_id[ $parsed['id'] ] = $parsed;
							}
						}
					}
					unset( $page_prods );
				} else {
					unset( $resp );
				}
			}
		}

		// 3. Fallback: fetch pages from Pancake POS API (`pos.pages.fm`) directly using memory-safe page_size=50
		if ( ! $fetched_via_cf_pages ) {
			$max_pages = $trigger_live_sync ? 5 : 2;
			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$direct_url = sprintf(
					'https://pos.pages.fm/api/v1/shops/%s/products?api_key=%s&page_number=%d&page_size=50',
					rawurlencode( $shop_id ),
					rawurlencode( $api_key ),
					$page
				);
				$resp = wp_remote_get(
					$direct_url,
					array(
						'timeout'   => $trigger_live_sync ? 15 : 8,
						'sslverify' => false,
						'headers'   => $req_headers,
					)
				);
				if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
					unset( $resp );
					break;
				}
				$body       = json_decode( wp_remote_retrieve_body( $resp ), true );
				unset( $resp );
				$page_prods = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
				unset( $body );
				if ( empty( $page_prods ) ) {
					break;
				}
				$page_count = count( $page_prods );
				foreach ( $page_prods as $raw_prod ) {
					if ( ! is_array( $raw_prod ) ) {
						continue;
					}
					$parsed = self::parse_pancake_pos_product( $raw_prod );
					if ( $parsed ) {
						$products_by_id[ $parsed['id'] ] = $parsed;
					}
				}
				unset( $page_prods );
				if ( $page_count < 50 ) {
					break;
				}
			}
		}

		if ( ! empty( $products_by_id ) ) {
			self::save_catalog_cache( $products_by_id );
			return $products_by_id;
		}

		return $products_by_id;
	}

	/**
	 * Get cached Pancake catalog (from local JSON cache file or wp_options).
	 * Only performs remote HTTP fetching when $force_refresh is explicitly true.
	 */
	public static function get_pancake_catalog( $force_refresh = false ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

		if ( ! $force_refresh ) {
			$file = self::get_cache_file_path();
			if ( $file && is_readable( $file ) ) {
				$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$dec = $raw ? json_decode( $raw, true ) : null;
				unset( $raw );
				if ( is_array( $dec ) && ! empty( $dec ) ) {
					return self::merge_catalog_entries_by_sku( $dec );
				}
			}
			// Fallback to legacy v3 JSON cache file if v4 hasn't been written yet
			if ( $file ) {
				$legacy_v3 = dirname( $file ) . '/nexxi-pancake-catalog-v3.json';
				if ( is_readable( $legacy_v3 ) ) {
					$raw = file_get_contents( $legacy_v3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$dec = $raw ? json_decode( $raw, true ) : null;
					unset( $raw );
					if ( is_array( $dec ) && ! empty( $dec ) ) {
						return self::merge_catalog_entries_by_sku( $dec );
					}
				}
			}
			$cached = get_option( self::CACHE_OPTION, null );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return self::merge_catalog_entries_by_sku( $cached );
			}
			$cached_v3 = get_option( 'nexxi_pancake_catalog_cache_v3', null );
			if ( is_array( $cached_v3 ) && ! empty( $cached_v3 ) ) {
				return self::merge_catalog_entries_by_sku( $cached_v3 );
			}
			return array();
		}

		$res = self::fetch_and_cache_pancake_catalog( true );
		if ( is_wp_error( $res ) || empty( $res ) ) {
			return array();
		}
		return $res;
	}

	/**
	 * Build fast lookup maps from the Pancake catalog:
	 *  - by_id: pancake_product_id => product
	 *  - by_sku: UPPER(parent_sku) => product
	 *  - by_var_sku: UPPER(variation_sku) => product
	 *  - by_code: leading_code (e.g. "2607.8") => list of products
	 *  - by_norm_name: normalized title => product
	 */
	public static function build_lookup_maps( array $catalog ) {
		$by_id        = array();
		$by_sku       = array();
		$by_var_sku   = array();
		$by_code      = array();
		$by_norm_name = array();

		foreach ( $catalog as $pid => $p ) {
			$by_id[ $pid ] = $p;
			if ( ! empty( $p['sku'] ) ) {
				$by_sku[ strtoupper( trim( $p['sku'] ) ) ] = $p;
			}
			if ( ! empty( $p['code'] ) ) {
				$code_key = strtoupper( $p['code'] );
				if ( ! isset( $by_code[ $code_key ] ) ) {
					$by_code[ $code_key ] = array();
				}
				$by_code[ $code_key ][] = $p;
			}
			if ( ! empty( $p['name'] ) ) {
				$norm = sanitize_title( remove_accents( $p['name'] ) );
				if ( $norm ) {
					$by_norm_name[ $norm ] = $p;
				}
			}
			if ( ! empty( $p['variations'] ) ) {
				foreach ( $p['variations'] as $v ) {
					if ( ! empty( $v['sku'] ) ) {
						$vsku_up = strtoupper( trim( $v['sku'] ) );
						$by_var_sku[ $vsku_up ] = $p;
						if ( false !== strpos( $vsku_up, '-' ) ) {
							$v_prefix = explode( '-', $vsku_up )[0];
							if ( '' !== $v_prefix && ! isset( $by_sku[ $v_prefix ] ) ) {
								$by_sku[ $v_prefix ] = $p;
							}
						}
					}
				}
			}
		}

		return array(
			'by_id'        => $by_id,
			'by_sku'       => $by_sku,
			'by_var_sku'   => $by_var_sku,
			'by_code'      => $by_code,
			'by_norm_name' => $by_norm_name,
		);
	}

	/**
	 * Match a WooCommerce product to its corresponding Pancake product entry STRICTLY by SKU first.
	 * NEVER matches a product with SKU `2VBKHN2B01` to a different product SKU `2TCBHTBH01` that shares a batch prefix like `2603.8`!
	 *
	 * @return array{pancake:array|null,match_type:string}
	 */
	public static function match_wc_product( WC_Product $product, array $maps, $allow_live_query = false ) {
		$wc_id  = $product->get_id();
		$wc_sku = strtoupper( trim( (string) $product->get_sku() ) );

		// 1. Primary Match: WooCommerce Parent SKU (`2VBKHN2B01`)
		if ( '' !== $wc_sku ) {
			if ( isset( $maps['by_sku'][ $wc_sku ] ) ) {
				return array( 'pancake' => $maps['by_sku'][ $wc_sku ], 'match_type' => 'exact_sku' );
			}
			if ( isset( $maps['by_var_sku'][ $wc_sku ] ) ) {
				return array( 'pancake' => $maps['by_var_sku'][ $wc_sku ], 'match_type' => 'var_sku_on_parent' );
			}
			if ( false !== strpos( $wc_sku, '-' ) ) {
				$prefix = explode( '-', $wc_sku )[0];
				if ( isset( $maps['by_sku'][ $prefix ] ) ) {
					return array( 'pancake' => $maps['by_sku'][ $prefix ], 'match_type' => 'sku_prefix' );
				}
			}
			if ( $allow_live_query ) {
				$live_item = self::query_pancake_product_by_sku( $wc_sku );
				if ( $live_item ) {
					return array( 'pancake' => $live_item, 'match_type' => 'live_pos_sku' );
				}
			}
			// IMPORTANT: If the product has a non-numeric SKU (like 2VBKHN2B01), do NOT fall back to matching a different SKU by batch code!
			if ( ! preg_match( '/^\d+(\.\d+)?$/', $wc_sku ) ) {
				return array( 'pancake' => null, 'match_type' => '' );
			}
		}

		// 2. Match by Child Variation SKUs (`2VBKHN2B01-XAN-3`)
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$c_sku = strtoupper( trim( (string) get_post_meta( $child_id, '_sku', true ) ) );
				if ( '' === $c_sku ) {
					continue;
				}
				if ( isset( $maps['by_var_sku'][ $c_sku ] ) ) {
					return array( 'pancake' => $maps['by_var_sku'][ $c_sku ], 'match_type' => 'child_var_sku' );
				}
				if ( false !== strpos( $c_sku, '-' ) ) {
					$prefix = explode( '-', $c_sku )[0];
					if ( isset( $maps['by_sku'][ $prefix ] ) ) {
						return array( 'pancake' => $maps['by_sku'][ $prefix ], 'match_type' => 'child_sku_prefix' );
					}
					if ( $allow_live_query ) {
						$live_item = self::query_pancake_product_by_sku( $prefix );
						if ( $live_item ) {
							return array( 'pancake' => $live_item, 'match_type' => 'live_pos_child_sku' );
						}
					}
				}
			}
		}

		// 3. Explicit manual linked SKU (only if WC product doesn't have its own conflicting SKU)
		$linked_sku = strtoupper( trim( (string) get_post_meta( $wc_id, '_nexxi_pancake_sku', true ) ) );
		if ( $linked_sku && isset( $maps['by_sku'][ $linked_sku ] ) ) {
			return array( 'pancake' => $maps['by_sku'][ $linked_sku ], 'match_type' => 'manual_sku' );
		}

		// 4. Match by exact normalized Product Name (only when product has no SKU yet)
		$wc_name  = trim( (string) $product->get_name() );
		$norm_nam = sanitize_title( remove_accents( $wc_name ) );
		if ( $norm_nam && isset( $maps['by_norm_name'][ $norm_nam ] ) ) {
			return array( 'pancake' => $maps['by_norm_name'][ $norm_nam ], 'match_type' => 'exact_name' );
		}

		return array( 'pancake' => null, 'match_type' => '' );
	}

	/* ==========================================================================
	 * 2. IMAGE SIDELOADING & VARIATION CLASSIFICATION ASSIGNMENT ENGINE
	 * ========================================================================== */

	/**
	 * Extract the unique filename hash from a Pancake image URL (e.g. "7c867e05d3fdd49c51224a6810aec930d6766042").
	 */
	public static function extract_pancake_image_hash( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! $path ) {
			return '';
		}
		$base = pathinfo( basename( $path ), PATHINFO_FILENAME );
		// Strip WordPress duplicate suffixes like "-1", "-2", "-scaled" or "nexxi-<md5>-"
		$base = preg_replace( '/^nexxi-[a-f0-9]{10}-/i', '', $base );
		$base = preg_replace( '/-(?:scaled|\d+)$/i', '', $base );
		return strtolower( trim( $base ) );
	}

	/**
	 * Check if a Pancake image URL already exists in the WordPress Media Library (by source URL or filename hash)
	 * so we NEVER re-download images that are already on the Website ("chỉ lấy ảnh chưa có").
	 *
	 * @param string $image_url Full URL on content.pancake.vn
	 * @return int              Existing Attachment ID, or 0 if not found
	 */
	public static function find_existing_attachment_for_url( $image_url ) {
		global $wpdb;
		$image_url = trim( (string) $image_url );
		if ( '' === $image_url ) {
			return 0;
		}

		// 1. Check by exact _nexxi_pancake_source_url meta
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = 'attachment'
				   AND pm.meta_key = '_nexxi_pancake_source_url'
				   AND pm.meta_value = %s
				 LIMIT 1",
				$image_url
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $existing_id > 0 && wp_get_attachment_url( $existing_id ) ) {
			return $existing_id;
		}

		// 2. Check by Pancake filename hash in _wp_attached_file (matches images previously synced by Pancake WooCommerce sync)
		$hash = self::extract_pancake_image_hash( $image_url );
		if ( strlen( $hash ) >= 12 ) {
			$like = '%' . $wpdb->esc_like( $hash ) . '%';
			$by_file_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					 WHERE p.post_type = 'attachment'
					   AND pm.meta_key = '_wp_attached_file'
					   AND pm.meta_value LIKE %s
					 ORDER BY p.ID ASC
					 LIMIT 1",
					$like
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $by_file_id > 0 && wp_get_attachment_url( $by_file_id ) ) {
				update_post_meta( $by_file_id, '_nexxi_pancake_source_url', $image_url );
				return $by_file_id;
			}
		}

		return 0;
	}

	/**
	 * Download an image URL from Pancake (`https://content.pancake.vn/...`) into the WordPress
	 * Media Library ONLY if it does not already exist on the Website.
	 *
	 * @param string $image_url      Full URL on content.pancake.vn
	 * @param int    $parent_post_id WooCommerce parent product ID
	 * @param string $title          Attachment title / alt text
	 * @param string $caption        Optional caption (e.g. "Phân loại: Đỏ")
	 * @param bool   $is_new_download Output flag set to true if newly downloaded, false if reused existing
	 * @return int|WP_Error          Attachment ID on success
	 */
	public static function sideload_pancake_image( $image_url, $parent_post_id = 0, $title = '', $caption = '', &$is_new_download = false ) {
		$is_new_download = false;
		$image_url       = trim( (string) $image_url );
		if ( '' === $image_url || 0 !== strpos( $image_url, 'http' ) ) {
			return new WP_Error( 'invalid_url', 'URL ảnh Pancake không hợp lệ.' );
		}

		$existing_id = self::find_existing_attachment_for_url( $image_url );
		if ( $existing_id > 0 ) {
			if ( $caption && '' === wp_get_attachment_caption( $existing_id ) ) {
				wp_update_post(
					array(
						'ID'           => $existing_id,
						'post_excerpt' => $caption,
					)
				);
			}
			return $existing_id;
		}

		// Download image bytes via wp_remote_get
		$response = wp_remote_get(
			$image_url,
			array(
				'timeout'   => 25,
				'sslverify' => false,
				'headers'   => array(
					'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
					'Referer'    => 'https://pos.pages.fm/',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== $code || empty( $body ) ) {
			return new WP_Error( 'download_failed', 'Không tải được ảnh từ Pancake (HTTP ' . $code . ').' );
		}

		$path_part = wp_parse_url( $image_url, PHP_URL_PATH );
		$basename  = $path_part ? basename( $path_part ) : 'pancake-img.jpg';
		$ext       = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ) {
			$ext      = 'jpg';
			$basename = 'pancake-' . md5( $image_url ) . '.jpg';
		} else {
			$basename = 'nexxi-' . substr( md5( $image_url ), 0, 10 ) . '-' . sanitize_file_name( $basename );
		}

		$upload = wp_upload_bits( $basename, null, $body );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_bits_error', $upload['error'] );
		}

		$file_path = $upload['file'];
		$filetype  = wp_check_filetype( basename( $file_path ), null );

		$att_title = $title ? $title : 'NEXXI Baby - ' . $parent_post_id;
		$attachment = array(
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/jpeg',
			'post_title'     => sanitize_text_field( $att_title ),
			'post_content'   => '',
			'post_excerpt'   => sanitize_text_field( $caption ),
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file_path, $parent_post_id );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'insert_attachment_failed', 'Không thể lưu ảnh vào Thư viện Media.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		// Speed optimization: only generate 'thumbnail' during bulk Pancake image sideloading (prevents 8x GD/Imagick CPU timeout)
		$fast_sizes_cb = function( $sizes ) {
			return isset( $sizes['thumbnail'] ) ? array( 'thumbnail' => $sizes['thumbnail'] ) : array();
		};
		add_filter( 'intermediate_image_sizes_advanced', $fast_sizes_cb, 999 );
		add_filter( 'big_image_size_threshold', '__return_false', 999 );
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		remove_filter( 'intermediate_image_sizes_advanced', $fast_sizes_cb, 999 );
		remove_filter( 'big_image_size_threshold', '__return_false', 999 );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		update_post_meta( $attach_id, '_nexxi_pancake_source_url', $image_url );
		if ( $att_title ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $att_title ) );
		}

		$is_new_download = true;
		return (int) $attach_id;
	}

	/**
	 * Determine the normalized color/classification key of a WooCommerce variation.
	 */
	public static function get_wc_variation_class_key( WC_Product_Variation $variation ) {
		// 1. Check variation attributes (e.g. attribute_pa_mau-sac, attribute_mau, etc.)
		$attrs = $variation->get_attributes();
		foreach ( $attrs as $attr_name => $attr_val ) {
			if ( '' === (string) $attr_val ) {
				continue;
			}
			$clean_attr = strtolower( remove_accents( urldecode( (string) $attr_name ) ) );
			$role       = class_exists( 'Nexxi_Kids_Catalog' ) ? Nexxi_Kids_Catalog::attribute_role( $attr_name ) : '';
			if ( 'color' === $role || false !== strpos( $clean_attr, 'mau' ) || false !== strpos( $clean_attr, 'color' ) || false !== strpos( $clean_attr, 'phan-loai' ) || false !== strpos( $clean_attr, 'loai' ) ) {
				$tax = 0 === strpos( $attr_name, 'pa_' ) ? $attr_name : 'pa_' . $attr_name;
				if ( taxonomy_exists( $tax ) ) {
					$term = get_term_by( 'slug', $attr_val, $tax );
					if ( $term && ! is_wp_error( $term ) ) {
						return self::normalize_class_key( $term->name );
					}
				}
				return self::normalize_class_key( $attr_val );
			}
		}

		// 2. Fallback: check middle segment of variation SKU (e.g. 2VBKHN2B01-XAN-3 -> XAN -> xanh)
		$vsku = trim( (string) $variation->get_sku() );
		if ( '' !== $vsku && false !== strpos( $vsku, '-' ) ) {
			$parts = explode( '-', $vsku );
			if ( count( $parts ) >= 3 ) {
				return self::normalize_class_key( $parts[1] );
			}
		}

		return '';
	}

	/**
	 * Check whether an existing attachment ID was previously sideloaded from a DIFFERENT Pancake product
	 * (so we can automatically clean up or replace any wrongly synced images).
	 */
	public static function is_attachment_from_different_pancake_product( $att_id, array $valid_product_urls ) {
		$att_id = (int) $att_id;
		if ( $att_id <= 0 ) {
			return false;
		}
		$src = (string) get_post_meta( $att_id, '_nexxi_pancake_source_url', true );
		if ( '' === $src ) {
			return false;
		}
		if ( in_array( $src, $valid_product_urls, true ) ) {
			return false;
		}
		$src_hash = self::extract_pancake_image_hash( $src );
		foreach ( $valid_product_urls as $vu ) {
			if ( $src_hash && $src_hash === self::extract_pancake_image_hash( $vu ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sync a single WooCommerce product with its matched Pancake product data in 2 strict steps:
	 *  - BƯỚC 1: Dùng SKU sản phẩm truy vấn dữ liệu trên POS Pancake, tìm các Phân loại & SKU Phân loại
	 *            của sản phẩm đó đồng bộ về các biến thể trên Website.
	 *  - BƯỚC 2: Sau khi có SKU sản phẩm và SKU phân loại trên Website:
	 *            + Lấy SKU sản phẩm để đồng bộ toàn bộ ảnh về Website (CHỈ lấy ảnh chưa có).
	 *            + Lấy SKU phân loại để lấy ảnh của từng phân loại gán cho biến thể (CHỈ lấy ảnh chưa có).
	 *
	 * @param WC_Product $product
	 * @param array      $pancake_item
	 * @param array      $options
	 * @return array     Summary of actions performed
	 */
	public static function sync_product_with_pancake( WC_Product $product, array $pancake_item, array $options = array() ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$GLOBALS['nexxi_bulk_syncing'] = true;

		$defaults = array(
			'set_sku'          => true,
			'set_thumb'        => true,
			'set_gallery'      => true,
			'set_var_img'      => true,
			'sync_stock'       => false,
			'overwrite_images' => false,
			'skip_live_query'  => false,
			'custom_class_map' => array(), // wc_color_key => pancake_image_url
		);
		$opts = wp_parse_args( $options, $defaults );
		$pid  = $product->get_id();

		// Query Pancake POS live by this product's SKU only if not already loaded or if variations/images are missing
		if ( empty( $opts['skip_live_query'] ) && ( empty( $pancake_item['variations'] ) || empty( $pancake_item['all_images'] ) ) ) {
			$lookup_sku = self::get_wc_product_lookup_sku( $product );
			if ( '' !== $lookup_sku ) {
				$live_item = self::query_pancake_product_by_sku( $lookup_sku, false );
				if ( is_array( $live_item ) && ! empty( $live_item['id'] ) ) {
					$pancake_item = $live_item;
				}
			}
		}

		$result = array(
			'product_id'         => $pid,
			'name'               => $product->get_name(),
			'pancake_sku'        => $pancake_item['sku'],
			'sku_updated'        => false,
			'thumb_set'          => false,
			'gallery_count'      => 0,
			'variations_img_set' => 0,
			'var_sku_updated'    => 0,
			'stock_updated'      => 0,
			'downloaded_urls'    => 0,
			'messages'           => array(),
		);

		// Save persistent link to Pancake product ID & SKU
		update_post_meta( $pid, '_nexxi_pancake_product_id', $pancake_item['id'] );
		if ( ! empty( $pancake_item['sku'] ) ) {
			update_post_meta( $pid, '_nexxi_pancake_sku', $pancake_item['sku'] );
		}

		// =========================================================================
		// BƯỚC 1: ĐỒNG BỘ SKU SẢN PHẨM, PHÂN LOẠI & SKU PHÂN LOẠI TỪ PANCAKE VỀ WEBSITE
		// =========================================================================
		$parent_sku  = trim( (string) $pancake_item['sku'] );
		$current_sku = trim( (string) $product->get_sku() );
		if ( $opts['set_sku'] && '' !== $parent_sku && ( '' === $current_sku || $current_sku === $pancake_item['code'] ) ) {
			$owner = wc_get_product_id_by_sku( $parent_sku );
			if ( ! $owner || (int) $owner === (int) $pid ) {
				update_post_meta( $pid, '_sku', $parent_sku );
				$result['sku_updated'] = true;
				$result['messages'][]  = 'Đã đồng bộ SKU sản phẩm: ' . $parent_sku;
			}
		}

		// Build Pancake variation lookup tables for this exact product SKU
		$p_var_by_sku        = array();
		$p_var_by_color_size = array();
		$p_var_by_raw_size   = array();
		$p_var_by_size       = array();

		if ( ! empty( $pancake_item['variations'] ) && is_array( $pancake_item['variations'] ) ) {
			foreach ( $pancake_item['variations'] as $pv ) {
				if ( ! empty( $pv['sku'] ) ) {
					$p_var_by_sku[ strtoupper( trim( $pv['sku'] ) ) ] = $pv;
				}
				$s_num    = preg_match( '/(\d+)/', (string) $pv['size'], $sm ) ? (int) $sm[1] : strtolower( trim( (string) $pv['size'] ) );
				$c_key    = (string) ( $pv['color_key'] ?? '' );
				$raw_slug = sanitize_title( remove_accents( (string) ( $pv['color_raw'] ?? '' ) ) );

				if ( '' !== $c_key && '' !== (string) $s_num ) {
					$p_var_by_color_size[ $c_key . '__' . $s_num ] = $pv;
				}
				if ( '' !== $raw_slug && '' !== (string) $s_num ) {
					$p_var_by_raw_size[ $raw_slug . '__' . $s_num ] = $pv;
				}
				if ( '' !== (string) $s_num && ! isset( $p_var_by_size[ $s_num ] ) ) {
					$p_var_by_size[ $s_num ] = $pv;
				}
			}
		}

		// Match each Website variation & sync its exact Variation SKU (`SKU phân loại`) from Pancake POS
		$wc_children_matched = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$var_obj = wc_get_product( $child_id );
				if ( ! $var_obj || ! $var_obj instanceof WC_Product_Variation ) {
					continue;
				}

				$v_sku    = strtoupper( trim( (string) $var_obj->get_sku() ) );
				$wc_c_key = self::get_wc_variation_class_key( $var_obj );
				$v_attrs  = $var_obj->get_attributes();
				$wc_s_num = '';
				$wc_c_raw = '';

				foreach ( $v_attrs as $ak => $av ) {
					$clean_ak = strtolower( remove_accents( urldecode( (string) $ak ) ) );
					if ( false !== strpos( $clean_ak, 'size' ) || false !== strpos( $clean_ak, 'kich-co' ) || 'co' === $clean_ak ) {
						$wc_s_num = preg_match( '/(\d+)/', (string) $av, $m ) ? (int) $m[1] : strtolower( trim( (string) $av ) );
					} elseif ( false !== strpos( $clean_ak, 'mau' ) || false !== strpos( $clean_ak, 'color' ) || false !== strpos( $clean_ak, 'loai' ) ) {
						$wc_c_raw = sanitize_title( remove_accents( (string) $av ) );
					}
				}

				$matched_pv = null;
				if ( '' !== $v_sku && isset( $p_var_by_sku[ $v_sku ] ) ) {
					$matched_pv = $p_var_by_sku[ $v_sku ];
				} elseif ( '' !== $wc_c_raw && '' !== (string) $wc_s_num && isset( $p_var_by_raw_size[ $wc_c_raw . '__' . $wc_s_num ] ) ) {
					$matched_pv = $p_var_by_raw_size[ $wc_c_raw . '__' . $wc_s_num ];
				} elseif ( '' !== $wc_c_key && '' !== (string) $wc_s_num && isset( $p_var_by_color_size[ $wc_c_key . '__' . $wc_s_num ] ) ) {
					$matched_pv = $p_var_by_color_size[ $wc_c_key . '__' . $wc_s_num ];
				} elseif ( '' === $wc_c_key && 1 === count( $pancake_item['class_images'] ) && '' !== (string) $wc_s_num && isset( $p_var_by_size[ $wc_s_num ] ) ) {
					$matched_pv = $p_var_by_size[ $wc_s_num ];
				}

				// Sync exact Variation SKU (`SKU phân loại`, e.g. 2VBKHN2B01-XAN-3) to Website variation
				if ( $matched_pv && ! empty( $matched_pv['sku'] ) ) {
					$target_vsku = trim( (string) $matched_pv['sku'] );
					$wrong_prefix = ( '' !== $v_sku && '' !== $parent_sku && 0 !== strpos( $v_sku, strtoupper( $parent_sku ) ) );
					if ( $opts['set_sku'] && ( '' === $v_sku || $wrong_prefix ) ) {
						$owner = wc_get_product_id_by_sku( $target_vsku );
						if ( ! $owner || (int) $owner === (int) $child_id ) {
							update_post_meta( $child_id, '_sku', $target_vsku );
							$v_sku = strtoupper( $target_vsku );
							$result['var_sku_updated']++;
						}
					}
				}

				if ( $opts['sync_stock'] && $matched_pv ) {
					$qty = (int) $matched_pv['stock'];
					update_post_meta( $child_id, '_manage_stock', 'yes' );
					wc_update_product_stock( $child_id, $qty, 'set' );
					$result['stock_updated']++;
				}

				$wc_children_matched[ $child_id ] = array(
					'v_sku'      => $v_sku,
					'wc_c_key'   => $wc_c_key,
					'matched_pv' => $matched_pv,
				);
			}
		}

		// =========================================================================
		// BƯỚC 2: ĐỒNG BỘ ẢNH SẢN PHẨM (THEO SKU SP) & ẢNH PHÂN LOẠI (THEO SKU PHÂN LOẠI)
		//         CHỈ LẤY NHỮNG ẢNH CHƯA CÓ TRÊN WEBSITE!
		// =========================================================================
		$valid_product_urls = ! empty( $pancake_item['all_images'] ) && is_array( $pancake_item['all_images'] ) ? $pancake_item['all_images'] : array();
		$url_to_att_id      = array();
		$class_att_ids      = array();
		$saved_class_map    = array();

		// Helper closure to resolve or download a Pancake image URL only when needed
		$resolve_image_att = function( $img_url, $title_suffix = '', $caption = '' ) use ( $pid, $product, &$url_to_att_id, &$result ) {
			$img_url = trim( (string) $img_url );
			if ( '' === $img_url || 0 !== strpos( $img_url, 'http' ) ) {
				return 0;
			}
			if ( isset( $url_to_att_id[ $img_url ] ) ) {
				return $url_to_att_id[ $img_url ];
			}
			$is_new = false;
			$full_title = $title_suffix ? $product->get_name() . ' - ' . $title_suffix : $product->get_name();
			$att_id = self::sideload_pancake_image( $img_url, $pid, $full_title, $caption, $is_new );
			if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
				$url_to_att_id[ $img_url ] = (int) $att_id;
				if ( $is_new ) {
					$result['downloaded_urls']++;
				}
				return (int) $att_id;
			}
			return 0;
		};

		// 2A. Lấy SKU Sản phẩm để đồng bộ toàn bộ ảnh của sản phẩm về Website (chỉ lấy ảnh chưa có)
		$current_thumb = (int) get_post_thumbnail_id( $pid );
		if ( $current_thumb > 0 && self::is_attachment_from_different_pancake_product( $current_thumb, $valid_product_urls ) ) {
			// Clean up thumbnail if it was previously set from a wrong Pancake product
			delete_post_thumbnail( $pid );
			$current_thumb = 0;
		}

		$existing_gallery = array_filter( array_map( 'absint', $product->get_gallery_image_ids() ) );
		// Remove any gallery images that were previously added from a different Pancake product
		if ( ! empty( $existing_gallery ) && ! empty( $valid_product_urls ) ) {
			$existing_gallery = array_values(
				array_filter(
					$existing_gallery,
					function( $gid ) use ( $valid_product_urls ) {
						return ! self::is_attachment_from_different_pancake_product( $gid, $valid_product_urls );
					}
				)
			);
		}

		// Collect hashes of images ALREADY attached to this product on Website
		$existing_product_hashes = array();
		$existing_product_att_ids = array_values( array_unique( array_filter( array_merge( array( $current_thumb ), $existing_gallery ) ) ) );
		foreach ( $existing_product_att_ids as $eid ) {
			$e_url = wp_get_attachment_url( $eid );
			if ( $e_url ) {
				$eh = self::extract_pancake_image_hash( $e_url );
				if ( $eh ) {
					$existing_product_hashes[ $eh ] = $eid;
				}
			}
			$e_src = (string) get_post_meta( $eid, '_nexxi_pancake_source_url', true );
			if ( $e_src ) {
				$url_to_att_id[ $e_src ] = $eid;
				$eh2 = self::extract_pancake_image_hash( $e_src );
				if ( $eh2 ) {
					$existing_product_hashes[ $eh2 ] = $eid;
				}
			}
		}

		// Featured image: only set if product does not have one yet (or if overwrite is enabled)
		if ( $opts['set_thumb'] && ! empty( $valid_product_urls ) && ( $current_thumb <= 0 || $opts['overwrite_images'] ) ) {
			$primary_url = ! empty( $pancake_item['product_image'] ) ? $pancake_item['product_image'] : $valid_product_urls[0];
			$primary_att = $resolve_image_att( $primary_url );
			if ( $primary_att > 0 ) {
				set_post_thumbnail( $pid, $primary_att );
				$current_thumb        = $primary_att;
				$result['thumb_set']  = true;
				$result['messages'][] = 'Đã đặt Ảnh đại diện từ SKU sản phẩm ' . $parent_sku;
				$ph = self::extract_pancake_image_hash( $primary_url );
				if ( $ph ) {
					$existing_product_hashes[ $ph ] = $primary_att;
				}
			}
		}

		// Product Gallery: check all images of Product SKU (`all_images`), ONLY download & add images not yet on the product
		if ( $opts['set_gallery'] && ! empty( $valid_product_urls ) ) {
			$new_gallery_ids = $opts['overwrite_images'] ? array() : $existing_gallery;
			foreach ( $valid_product_urls as $p_img_url ) {
				$p_hash = self::extract_pancake_image_hash( $p_img_url );
				if ( ! $opts['overwrite_images'] && $p_hash && isset( $existing_product_hashes[ $p_hash ] ) ) {
					$att_id = $existing_product_hashes[ $p_hash ];
					$url_to_att_id[ $p_img_url ] = $att_id;
				} else {
					$att_id = $resolve_image_att( $p_img_url );
					if ( $att_id > 0 && $p_hash ) {
						$existing_product_hashes[ $p_hash ] = $att_id;
					}
				}
				if ( $att_id > 0 && $att_id !== $current_thumb && ! in_array( $att_id, $new_gallery_ids, true ) ) {
					$new_gallery_ids[] = $att_id;
				}
			}
			update_post_meta( $pid, '_product_image_gallery', implode( ',', $new_gallery_ids ) );
			$result['gallery_count'] = count( $new_gallery_ids );
		}

		// Prepare classification image map (`_nexxi_class_image_map`) for frontend color swatches & fallback
		if ( ! empty( $pancake_item['class_images'] ) ) {
			foreach ( $pancake_item['class_images'] as $c_key => $c_info ) {
				$img_url = $c_info['image'] ?? '';
				if ( ! $img_url ) {
					continue;
				}
				$caption = 'Phân loại: ' . $c_info['label'];
				$att_id  = $resolve_image_att( $img_url, $c_info['label'], $caption );
				if ( $att_id > 0 ) {
					$class_att_ids[ $c_key ]   = $att_id;
					$full_url                  = wp_get_attachment_image_url( $att_id, 'full' );
					$saved_class_map[ $c_key ] = array(
						'key'    => $c_key,
						'raw'    => $c_info['raw'],
						'label'  => preg_replace( '/\s*\([^)]*\)\s*$/', '', $c_info['label'] ),
						'att_id' => $att_id,
						'url'    => $full_url ? $full_url : $img_url,
					);
				}
			}
		}

		// Handle manual classification overrides if passed from interactive modal
		if ( ! empty( $opts['custom_class_map'] ) && is_array( $opts['custom_class_map'] ) ) {
			foreach ( $opts['custom_class_map'] as $wc_ckey => $custom_url ) {
				$custom_url = trim( (string) $custom_url );
				if ( '' === $custom_url ) {
					continue;
				}
				$c_att = $resolve_image_att( $custom_url, $wc_ckey );
				if ( $c_att > 0 ) {
					$norm_ck = self::normalize_class_key( $wc_ckey );
					$class_att_ids[ $norm_ck ] = $c_att;
					$full_u = wp_get_attachment_image_url( $c_att, 'full' );
					$saved_class_map[ $norm_ck ] = array(
						'key'    => $norm_ck,
						'raw'    => strtoupper( $wc_ckey ),
						'label'  => preg_replace( '/\s*\([^)]*\)\s*$/', '', self::pretty_class_label( $wc_ckey ) ),
						'att_id' => $c_att,
						'url'    => $full_u ? $full_u : $custom_url,
					);
				}
			}
		}

		if ( ! empty( $saved_class_map ) && $opts['set_var_img'] ) {
			update_post_meta( $pid, '_nexxi_class_image_map', $saved_class_map );
		}

		// 2B. Lấy SKU Phân loại (`v_sku`) trên từng biến thể Website để lấy ảnh của từng phân loại (chỉ lấy ảnh chưa có)
		if ( $product->is_type( 'variable' ) && $opts['set_var_img'] ) {
			foreach ( $wc_children_matched as $child_id => $cinfo ) {
				$v_sku      = $cinfo['v_sku'];
				$wc_c_key   = $cinfo['wc_c_key'];
				$matched_pv = $cinfo['matched_pv'];

				$existing_var_thumb = (int) get_post_meta( $child_id, '_thumbnail_id', true );
				// If variation already has an image, check if it was wrongly set from a different Pancake product
				if ( $existing_var_thumb > 0 && self::is_attachment_from_different_pancake_product( $existing_var_thumb, $valid_product_urls ) ) {
					delete_post_meta( $child_id, '_thumbnail_id' );
					$existing_var_thumb = 0;
				}

				// "chỉ lấy ảnh chưa có": if variation already has a valid image of this product, skip unless overwrite/manual map is requested
				if ( $existing_var_thumb > 0 && wp_get_attachment_url( $existing_var_thumb ) && ! $opts['overwrite_images'] && empty( $opts['custom_class_map'] ) ) {
					continue;
				}

				$target_att_id = 0;

				// Priority 1: Manual override from modal (`custom_class_map`)
				if ( '' !== $wc_c_key && isset( $opts['custom_class_map'][ $wc_c_key ] ) ) {
					$target_att_id = $resolve_image_att( $opts['custom_class_map'][ $wc_c_key ], $wc_c_key );
				}

				// Priority 2: Exact Variation SKU (`SKU phân loại`, e.g. 2VBKHN2B01-XAN-3) image from Pancake POS
				if ( ! $target_att_id && '' !== $v_sku && ! empty( $pancake_item['var_images'][ $v_sku ] ) ) {
					$target_att_id = $resolve_image_att( $pancake_item['var_images'][ $v_sku ], $v_sku );
				}

				// Priority 3: Matched Pancake variation's own image URL
				if ( ! $target_att_id && $matched_pv && ! empty( $matched_pv['image'] ) ) {
					$target_att_id = $resolve_image_att( $matched_pv['image'], $matched_pv['color_raw'] ?? $v_sku );
				}

				// Priority 4: Classification key match within this product
				if ( ! $target_att_id && '' !== $wc_c_key && isset( $class_att_ids[ $wc_c_key ] ) ) {
					$target_att_id = $class_att_ids[ $wc_c_key ];
				}
				if ( ! $target_att_id && $matched_pv && ! empty( $matched_pv['color_key'] ) && isset( $class_att_ids[ $matched_pv['color_key'] ] ) ) {
					$target_att_id = $class_att_ids[ $matched_pv['color_key'] ];
				}

				if ( $target_att_id > 0 ) {
					update_post_meta( $child_id, '_thumbnail_id', $target_att_id );
					$result['variations_img_set']++;
				}
			}

			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $pid );
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $pid );
			}
		}

		update_post_meta( $pid, '_nexxi_pancake_last_sync', time() );
		return $result;
	}

	/**
	 * Background WP-Cron task: automatically scans up to 15 products missing images per run.
	 */
	public function run_cron_auto_scan() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return;
		}
		$catalog = self::fetch_and_cache_pancake_catalog( false );
		if ( empty( $catalog ) ) {
			return;
		}
		$maps     = self::build_lookup_maps( $catalog );
		$settings = self::get_settings();
		$products = wc_get_products(
			array(
				'status' => array( 'publish', 'draft', 'private' ),
				'limit'  => -1,
			)
		);

		$count = 0;
		foreach ( $products as $product ) {
			if ( $count >= 15 ) {
				break;
			}
			$match = self::match_wc_product( $product, $maps );
			$p_item = $match['pancake'];
			if ( ! $p_item || empty( $p_item['all_images'] ) ) {
				continue;
			}
			$has_thumb = (int) $product->get_image_id() > 0;
			if ( ! $has_thumb ) {
				self::sync_product_with_pancake(
					$product,
					$p_item,
					array(
						'set_sku'          => ! empty( $settings['auto_set_sku'] ),
						'set_thumb'        => ! empty( $settings['auto_set_thumb'] ),
						'set_gallery'      => ! empty( $settings['auto_set_gallery'] ),
						'set_var_img'      => ! empty( $settings['auto_set_var_img'] ),
						'overwrite_images' => false,
					)
				);
				$count++;
			}
		}
	}

	/* ==========================================================================
	 * 3. AJAX HANDLERS FOR INTERACTIVE ADMIN UI
	 * ========================================================================== */

	/**
	 * AJAX: Refresh Pancake Catalog Cache (and optionally trigger Cloudflare worker live sync).
	 */
	public function ajax_refresh_cache() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}
		$live = ! empty( $_POST['trigger_live'] );
		$res  = self::fetch_and_cache_pancake_catalog( $live );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$with_img   = 0;
		$with_class = 0;
		foreach ( $res as $item ) {
			if ( ! empty( $item['all_images'] ) ) {
				$with_img++;
			}
			if ( ! empty( $item['class_images'] ) ) {
				$with_class++;
			}
		}
		wp_send_json_success(
			array(
				'total_pancake' => count( $res ),
				'with_images'   => $with_img,
				'with_classes'  => $with_class,
				'message'       => sprintf( 'Đã đồng bộ %d sản phẩm từ Pancake (%d SP có ảnh, %d SP có ảnh phân loại màu).', count( $res ), $with_img, $with_class ),
			)
		);
	}

	/**
	 * AJAX: Receive browser-fetched Pancake catalog JSON (fallback if hosting firewall blocks outgoing PHP requests).
	 * Supports `append=1` so browser can push multi-page catalog in chunks.
	 */
	public function ajax_push_browser_cache() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}
		$raw_json = isset( $_POST['catalog_json'] ) ? wp_unslash( $_POST['catalog_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$decoded  = $raw_json ? json_decode( $raw_json, true ) : null;
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			wp_send_json_error( array( 'message' => 'Dữ liệu catalog trống.' ) );
		}

		// Normalize keys and labels through PHP helper
		foreach ( $decoded as $pid => &$p ) {
			$p['code'] = self::extract_leading_code( $p['name'] ?? '' );
			$norm_classes = array();
			if ( ! empty( $p['class_images'] ) && is_array( $p['class_images'] ) ) {
				foreach ( $p['class_images'] as $raw_c => $c_info ) {
					$ckey = self::normalize_class_key( $c_info['raw'] ?? $raw_c );
					if ( '' === $ckey ) {
						continue;
					}
					$c_info['key']   = $ckey;
					$c_info['label'] = self::pretty_class_label( $c_info['raw'] ?? $raw_c );
					$norm_classes[ $ckey ] = $c_info;
				}
			}
			$p['class_images'] = $norm_classes;
			if ( ! empty( $p['variations'] ) && is_array( $p['variations'] ) ) {
				foreach ( $p['variations'] as &$v ) {
					$v['color_key'] = self::normalize_class_key( $v['color_raw'] ?? '' );
				}
				unset( $v );
			}
		}
		unset( $p );

		$is_append = ! empty( $_POST['append'] ) && '1' === (string) $_POST['append'];
		if ( $is_append ) {
			$existing = self::get_pancake_catalog( false );
			foreach ( $decoded as $pid => $pitem ) {
				$existing[ $pid ] = $pitem;
			}
			$decoded = $existing;
		}

		self::save_catalog_cache( $decoded );
		$final_catalog = self::merge_catalog_entries_by_sku( $decoded );

		$with_img   = 0;
		$with_class = 0;
		foreach ( $final_catalog as $item ) {
			if ( ! empty( $item['all_images'] ) ) {
				$with_img++;
			}
			if ( ! empty( $item['class_images'] ) ) {
				$with_class++;
			}
		}

		wp_send_json_success(
			array(
				'total_pancake' => count( $final_catalog ),
				'with_images'   => $with_img,
				'with_classes'  => $with_class,
				'message'       => sprintf( 'Đã đồng bộ %d sản phẩm từ Pancake (%d SP có ảnh, %d SP có ảnh phân loại màu).', count( $final_catalog ), $with_img, $with_class ),
			)
		);
	}

	/**
	 * Automatically detect and clean up any duplicate WooCommerce products on the Website
	 * that share the same Pancake SKU, Parent SKU, Pancake Product ID, or exact Product Title.
	 * Keeps the single most complete product (with thumbnail, variation images, SKU & variations)
	 * and permanently deletes redundant duplicate copies.
	 */
	public static function cleanup_duplicate_wc_products() {
		global $wpdb;
		$GLOBALS['nexxi_bulk_syncing'] = true;

		$rows = self::get_fast_wc_catalog_rows();
		if ( count( $rows ) < 2 ) {
			return array(
				'deleted_products'   => 0,
				'deleted_variations' => 0,
				'kept_products'      => count( $rows ),
				'details'            => array(),
			);
		}

		// Score each product so we always keep the most complete one (has thumbnail > has variation images > has SKU > most variations > oldest ID)
		$scored = array();
		foreach ( $rows as $pid => $r ) {
			$score = 0;
			if ( (int) $r['thumb_id'] > 0 ) {
				$score += 100000;
			}
			if ( (int) $r['var_with_img'] > 0 ) {
				$score += (int) $r['var_with_img'] * 500;
			}
			if ( '' !== trim( (string) $r['sku'] ) ) {
				$score += 20000;
			}
			if ( (int) $r['var_total'] > 0 ) {
				$score += (int) $r['var_total'] * 100;
			}
			$r['_score']     = $score;
			$scored[ $pid ] = $r;
		}

		// Group products by canonical key (SKU, Pancake ID, or Normalized Title)
		$groups_by_key = array();
		foreach ( $scored as $pid => $r ) {
			$keys = array();

			$sku = strtoupper( trim( (string) $r['sku'] ) );
			if ( '' !== $sku ) {
				$base_sku = false !== strpos( $sku, '-' ) ? explode( '-', $sku )[0] : $sku;
				if ( '' !== $base_sku ) {
					$keys[] = 'SKU:' . $base_sku;
				}
			}

			$psku = strtoupper( trim( (string) $r['pancake_sku'] ) );
			if ( '' !== $psku ) {
				$base_psku = false !== strpos( $psku, '-' ) ? explode( '-', $psku )[0] : $psku;
				if ( '' !== $base_psku ) {
					$keys[] = 'SKU:' . $base_psku;
				}
			}

			if ( empty( $keys ) && ! empty( $r['child_skus'] ) ) {
				foreach ( $r['child_skus'] as $csku ) {
					if ( false !== strpos( $csku, '-' ) ) {
						$cpref = strtoupper( trim( explode( '-', $csku )[0] ) );
						if ( '' !== $cpref ) {
							$keys[] = 'SKU:' . $cpref;
							break;
						}
					}
				}
			}

			$pancake_id = trim( (string) $r['pancake_id'] );
			if ( '' !== $pancake_id ) {
				$keys[] = 'PID:' . $pancake_id;
			}

			$norm_title = mb_strtolower( trim( preg_replace( '/\s+/', ' ', (string) $r['name'] ) ) );
			if ( '' !== $norm_title ) {
				$keys[] = 'TITLE:' . $norm_title;
			}

			$keys = array_unique( $keys );
			foreach ( $keys as $k ) {
				if ( ! isset( $groups_by_key[ $k ] ) ) {
					$groups_by_key[ $k ] = array();
				}
				$groups_by_key[ $k ][] = (int) $pid;
			}
		}

		// Merge overlapping groups using adjacency / connected components
		$adj = array();
		foreach ( $groups_by_key as $pids ) {
			if ( count( $pids ) < 2 ) {
				continue;
			}
			$first = $pids[0];
			for ( $i = 1, $n = count( $pids ); $i < $n; $i++ ) {
				$u = $pids[ $i ];
				$adj[ $first ][ $u ] = true;
				$adj[ $u ][ $first ] = true;
			}
		}

		$visited            = array();
		$deleted_products   = 0;
		$deleted_variations = 0;
		$details            = array();

		foreach ( array_keys( $adj ) as $start_pid ) {
			if ( isset( $visited[ $start_pid ] ) ) {
				continue;
			}
			// BFS to collect all duplicate product IDs in this component
			$component = array();
			$queue     = array( $start_pid );
			$visited[ $start_pid ] = true;
			while ( ! empty( $queue ) ) {
				$curr        = array_shift( $queue );
				$component[] = $curr;
				if ( ! empty( $adj[ $curr ] ) ) {
					foreach ( array_keys( $adj[ $curr ] ) as $nbr ) {
						if ( ! isset( $visited[ $nbr ] ) ) {
							$visited[ $nbr ] = true;
							$queue[]         = $nbr;
						}
					}
				}
			}

			if ( count( $component ) < 2 ) {
				continue;
			}

			// Sort component so the highest-scored product (or lowest ID on tie) is first
			usort(
				$component,
				function( $a, $b ) use ( $scored ) {
					$sa = $scored[ $a ]['_score'] ?? 0;
					$sb = $scored[ $b ]['_score'] ?? 0;
					if ( $sa !== $sb ) {
						return $sb <=> $sa;
					}
					return $a <=> $b;
				}
			);

			$keeper_id  = $component[0];
			$keeper_row = $scored[ $keeper_id ];
			$duplicates = array_slice( $component, 1 );

			// Transfer SKU / Pancake metadata / Thumbnail from duplicates to keeper if keeper was missing any
			$best_sku        = $keeper_row['sku'];
			$best_psku       = $keeper_row['pancake_sku'];
			$best_pid        = $keeper_row['pancake_id'];
			$best_thumb      = (int) $keeper_row['thumb_id'];

			foreach ( $duplicates as $dup_id ) {
				$drow = $scored[ $dup_id ];
				if ( '' === $best_sku && '' !== $drow['sku'] ) {
					$best_sku = $drow['sku'];
				}
				if ( '' === $best_psku && '' !== $drow['pancake_sku'] ) {
					$best_psku = $drow['pancake_sku'];
				}
				if ( '' === $best_pid && '' !== $drow['pancake_id'] ) {
					$best_pid = $drow['pancake_id'];
				}
				if ( $best_thumb <= 0 && (int) $drow['thumb_id'] > 0 ) {
					$best_thumb = (int) $drow['thumb_id'];
				}

				// Delete all child variations of the duplicate product
				$child_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
						$dup_id
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( ! empty( $child_ids ) ) {
					foreach ( $child_ids as $cid ) {
						wp_delete_post( (int) $cid, true );
						$deleted_variations++;
					}
				}

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $dup_id );
				}
				wp_delete_post( (int) $dup_id, true );
				$deleted_products++;
			}

			// Ensure keeper has the SKU and thumbnail after duplicates are removed
			if ( '' === $best_sku && '' !== $best_psku ) {
				$best_sku = $best_psku;
			}
			if ( '' !== $best_sku ) {
				update_post_meta( $keeper_id, '_sku', $best_sku );
				update_post_meta( $keeper_id, '_nexxi_pancake_sku', $best_sku );
			}
			if ( '' !== $best_pid ) {
				update_post_meta( $keeper_id, '_nexxi_pancake_product_id', $best_pid );
			}
			if ( (int) $keeper_row['thumb_id'] <= 0 && $best_thumb > 0 ) {
				set_post_thumbnail( $keeper_id, $best_thumb );
			}

			$details[] = sprintf(
				'Giữ lại SP #%d [%s] "%s" — Đã xóa %d bản trùng (#%s)',
				$keeper_id,
				$best_sku ?: 'SKU',
				$keeper_row['name'],
				count( $duplicates ),
				implode( ', #', $duplicates )
			);
		}

		if ( $deleted_products > 0 && function_exists( 'nexxi_kids_flush_sale_cache' ) ) {
			nexxi_kids_flush_sale_cache();
		}

		return array(
			'deleted_products'   => $deleted_products,
			'deleted_variations' => $deleted_variations,
			'details'            => $details,
		);
	}

	/**
	 * Run automatic one-time duplicate cleanup when v1.4.0 is deployed.
	 */
	public function maybe_auto_cleanup_duplicates() {
		if ( '1' === (string) get_option( 'nexxi_pancake_dedup_v140', '0' ) ) {
			return;
		}
		update_option( 'nexxi_pancake_dedup_v140', '1', true );
		self::cleanup_duplicate_wc_products();
	}

	/**
	 * AJAX endpoint: Clean up duplicate products on the Website on demand.
	 */
	public function ajax_cleanup_duplicates() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}
		$res = self::cleanup_duplicate_wc_products();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX endpoint: Fast SPA navigation for pagination & filter changes in Tab 1 and Tab 2 without full page reload.
	 */
	public function ajax_spa_nav() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$query_str = isset( $_REQUEST['query_string'] ) ? wp_unslash( $_REQUEST['query_string'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$params    = array();
		if ( is_string( $query_str ) && '' !== $query_str ) {
			wp_parse_str( ltrim( $query_str, '?' ), $params );
		}
		foreach ( $params as $k => $v ) {
			$_GET[ $k ] = $v;
		}

		$GLOBALS['nexxi_pancake_page_rendered'] = false;
		ob_start();
		$this->render_admin_page();
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Find an existing parent WooCommerce product (`WC_Product`) for a Pancake product entry by SKU, Pancake ID, Variation SKU prefix, or Title.
	 */
	public static function find_existing_wc_product_for_pancake( array $pancake_item ) {
		global $wpdb;
		$p_id   = trim( (string) ( $pancake_item['id'] ?? '' ) );
		$p_sku  = strtoupper( trim( (string) ( $pancake_item['sku'] ?? '' ) ) );
		$p_name = trim( (string) ( $pancake_item['name'] ?? '' ) );

		// 1. Direct SQL check by _sku or _nexxi_pancake_sku in postmeta (100% reliable even before wc_product_meta_lookup updates)
		if ( '' !== $p_sku ) {
			$sku_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					 WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private','pending')
					   AND pm.meta_key IN ('_sku', '_nexxi_pancake_sku') AND UPPER(TRIM(pm.meta_value)) = %s
					 ORDER BY p.ID ASC
					 LIMIT 1",
					$p_sku
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $sku_id > 0 ) {
				$prod = wc_get_product( $sku_id );
				if ( $prod && 'trash' !== $prod->get_status() ) {
					return $prod;
				}
			}
		}

		// 2. Check by wc_get_product_id_by_sku
		if ( '' !== $p_sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$found_id = (int) wc_get_product_id_by_sku( $p_sku );
			if ( $found_id > 0 ) {
				$parent_id = wp_get_post_parent_id( $found_id );
				$target_id = $parent_id > 0 ? $parent_id : $found_id;
				$prod      = wc_get_product( $target_id );
				if ( $prod && 'trash' !== $prod->get_status() ) {
					return $prod;
				}
			}
		}

		// 3. Check by _nexxi_pancake_product_id postmeta
		if ( '' !== $p_id ) {
			$meta_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					 WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private','pending')
					   AND pm.meta_key = '_nexxi_pancake_product_id' AND pm.meta_value = %s
					 ORDER BY p.ID ASC
					 LIMIT 1",
					$p_id
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $meta_id > 0 ) {
				$prod = wc_get_product( $meta_id );
				if ( $prod && 'trash' !== $prod->get_status() ) {
					return $prod;
				}
			}
		}

		// 4. Check if any variation on Website has a SKU starting with $p_sku . '-'
		if ( '' !== $p_sku ) {
			$like_prefix = $wpdb->esc_like( $p_sku . '-' ) . '%';
			$var_parent_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.post_parent FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					 WHERE p.post_type = 'product_variation' AND p.post_parent > 0
					   AND pm.meta_key = '_sku' AND UPPER(pm.meta_value) LIKE %s
					 ORDER BY p.post_parent ASC
					 LIMIT 1",
					$like_prefix
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $var_parent_id > 0 ) {
				$prod = wc_get_product( $var_parent_id );
				if ( $prod && 'trash' !== $prod->get_status() ) {
					return $prod;
				}
			}
		}

		// 5. Check by exact product title (prevents duplicate creation when a previous sync created the product without SKU)
		if ( '' !== $p_name ) {
			$title_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending')
					   AND post_title = %s
					 ORDER BY ID ASC
					 LIMIT 1",
					$p_name
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $title_id > 0 ) {
				$prod = wc_get_product( $title_id );
				if ( $prod && 'trash' !== $prod->get_status() ) {
					return $prod;
				}
			}
		}

		return null;
	}

	/**
	 * Tab 1 Engine: Create or update a WooCommerce product from a Pancake POS product entry (by SKU),
	 * syncing product title, parent SKU, categories, MÀU & SIZE attributes, variations (`SKU phân loại`),
	 * prices, stock quantities, and (optionally) 2-step product & classification images.
	 *
	 * By default (`skip_existing => true`), if a product with the same SKU already exists on the Website,
	 * it is strictly SKIPPED to prevent duplicate syncing.
	 */
	public static function sync_or_create_wc_product_from_pancake( array $pancake_item, array $options = array() ) {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$GLOBALS['nexxi_bulk_syncing'] = true;

		$defaults = array(
			'post_status'     => 'publish',
			'skip_existing'   => true,
			'sync_variations' => true,
			'sync_price'      => true,
			'sync_stock'      => true,
			'sync_categories' => true,
			'sync_images'     => true,
		);
		$opts = wp_parse_args( $options, $defaults );

		$lookup_sku = strtoupper( trim( (string) ( $pancake_item['sku'] ?? '' ) ) );
		$pancake_id = trim( (string) ( $pancake_item['id'] ?? '' ) );
		$lock_name  = 'nexxi_sku_lock_' . substr( md5( $lookup_sku ?: $pancake_id ), 0, 24 );

		// Acquire atomic database lock for this SKU so concurrent requests can never create duplicate products
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Check if this SKU already exists on the Website BEFORE making any remote API calls
		$existing_prod = self::find_existing_wc_product_for_pancake( $pancake_item );
		if ( $existing_prod && ! empty( $opts['skip_existing'] ) ) {
			$wc_id      = $existing_prod->get_id();
			$parent_sku = $lookup_sku ?: (string) $existing_prod->get_sku();
			$prod_name  = trim( (string) ( $pancake_item['name'] ?? $existing_prod->get_name() ) );

			// Ensure SKU & Pancake link metadata are recorded on the existing product
			if ( '' !== $lookup_sku && '' === trim( (string) $existing_prod->get_sku() ) ) {
				update_post_meta( $wc_id, '_sku', $lookup_sku );
			}
			if ( '' !== $lookup_sku ) {
				update_post_meta( $wc_id, '_nexxi_pancake_sku', $lookup_sku );
			}
			if ( '' !== $pancake_id ) {
				update_post_meta( $wc_id, '_nexxi_pancake_product_id', $pancake_id );
			}

			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$thumb_url = wp_get_attachment_image_url( get_post_thumbnail_id( $wc_id ), 'thumbnail' );
			return array(
				'wc_id'              => $wc_id,
				'pancake_id'         => $pancake_id,
				'pancake_sku'        => $parent_sku,
				'name'               => $prod_name,
				'created_new'        => false,
				'skipped_duplicate'  => true,
				'variations_synced'  => $existing_prod->is_type( 'variable' ) ? count( $existing_prod->get_children() ) : 0,
				'downloaded_urls'    => 0,
				'variations_img_set' => 0,
				'edit_url'           => get_edit_post_link( $wc_id, 'raw' ),
				'view_url'           => get_permalink( $wc_id ),
				'thumb_url'          => $thumb_url ? $thumb_url : '',
			);
		}

		wp_defer_term_counting( true );

		// Query live from Pancake POS by SKU only if variations or images are not already populated in cache
		if ( '' !== $lookup_sku && ( empty( $pancake_item['variations'] ) || empty( $pancake_item['all_images'] ) ) ) {
			$live_item = self::query_pancake_product_by_sku( $lookup_sku, false );
			if ( is_array( $live_item ) && ! empty( $live_item['id'] ) ) {
				$pancake_item = $live_item;
			}
		}

		$parent_sku = trim( (string) ( $pancake_item['sku'] ?? '' ) );
		$pancake_id = trim( (string) ( $pancake_item['id'] ?? '' ) );
		$prod_name  = trim( (string) ( $pancake_item['name'] ?? '' ) );
		if ( '' === $prod_name ) {
			$prod_name = 'Sản phẩm ' . $parent_sku;
		}

		// Unmark from disconnected list since user explicitly chose to sync this product to Website
		self::unmark_sku_disconnected( $parent_sku, $pancake_id );

		if ( ! $existing_prod ) {
			$existing_prod = self::find_existing_wc_product_for_pancake( $pancake_item );
		}
		$created_new = false;

		$p_vars = ! empty( $pancake_item['variations'] ) && is_array( $pancake_item['variations'] ) ? $pancake_item['variations'] : array();
		$has_var_attrs = false;
		foreach ( $p_vars as $pv ) {
			if ( '' !== trim( (string) ( $pv['color_raw'] ?? '' ) ) || '' !== trim( (string) ( $pv['size'] ?? '' ) ) ) {
				$has_var_attrs = true;
				break;
			}
		}
		$should_be_variable = ( count( $p_vars ) > 1 || $has_var_attrs );

		if ( ! $existing_prod ) {
			$created_new = true;
			if ( $should_be_variable && class_exists( 'WC_Product_Variable' ) ) {
				$product = new WC_Product_Variable();
			} else {
				$product = new WC_Product_Simple();
			}
			$product->set_name( $prod_name );
			$product->set_status( in_array( $opts['post_status'], array( 'publish', 'draft' ), true ) ? $opts['post_status'] : 'publish' );
			$product->set_date_created( time() );
			$product->set_menu_order( 0 );
			if ( '' !== $parent_sku ) {
				$owner = wc_get_product_id_by_sku( $parent_sku );
				if ( ! $owner ) {
					$product->set_sku( $parent_sku );
				}
			}
			$wc_id = $product->save();
		} else {
			$wc_id = $existing_prod->get_id();
			// If existing product is simple but Pancake has variations, convert post_type product_type term to variable
			if ( $should_be_variable && ! $existing_prod->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
				wp_set_object_terms( $wc_id, 'variable', 'product_type' );
				$product = new WC_Product_Variable( $wc_id );
			} else {
				$product = $existing_prod;
			}
			$product->set_name( $prod_name );
			$product->set_date_created( time() );
			$product->set_menu_order( 0 );
			if ( '' !== $parent_sku ) {
				$owner = wc_get_product_id_by_sku( $parent_sku );
				if ( ! $owner || (int) $owner === (int) $wc_id ) {
					$product->set_sku( $parent_sku );
				}
			}
			$product->save();
		}

		update_post_meta( $wc_id, '_nexxi_pancake_product_id', $pancake_id );
		if ( '' !== $parent_sku ) {
			update_post_meta( $wc_id, '_nexxi_pancake_sku', $parent_sku );
		}

		// Sync categories from Pancake (`categories` array) to WooCommerce `product_cat`
		if ( $opts['sync_categories'] && ! empty( $pancake_item['categories'] ) && is_array( $pancake_item['categories'] ) ) {
			$cat_term_ids = array();
			foreach ( $pancake_item['categories'] as $cat_name ) {
				$cat_name = trim( (string) $cat_name );
				if ( '' === $cat_name ) {
					continue;
				}
				$term = get_term_by( 'name', $cat_name, 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					$inserted = wp_insert_term( $cat_name, 'product_cat' );
					if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
						$cat_term_ids[] = (int) $inserted['term_id'];
					}
				} else {
					$cat_term_ids[] = (int) $term->term_id;
				}
			}
			if ( ! empty( $cat_term_ids ) ) {
				wp_set_object_terms( $wc_id, $cat_term_ids, 'product_cat', true );
			}
		}

		$variations_synced = 0;

		if ( $should_be_variable && $opts['sync_variations'] && ! empty( $p_vars ) ) {
			// Collect distinct MÀU and SIZE options in order
			$color_options = array();
			$size_options  = array();
			foreach ( $p_vars as $pv ) {
				$c_val = trim( (string) ( $pv['color_raw'] ?? '' ) );
				$s_val = trim( (string) ( $pv['size'] ?? '' ) );
				if ( '' !== $c_val && ! in_array( $c_val, $color_options, true ) ) {
					$color_options[] = $c_val;
				}
				if ( '' !== $s_val && ! in_array( $s_val, $size_options, true ) ) {
					$size_options[] = $s_val;
				}
			}

			// Detect existing attribute keys on the product if it already had variations (e.g., pa_mau-sac or mau, pa_size or size)
			$color_attr_slug = 'mau';
			$color_attr_name = 'MÀU';
			$size_attr_slug  = 'size';
			$size_attr_name  = 'SIZE';

			$existing_attrs = $product->get_attributes();
			if ( is_array( $existing_attrs ) ) {
				foreach ( $existing_attrs as $ak => $attr_obj ) {
					$clean_ak = strtolower( remove_accents( urldecode( (string) $ak ) ) );
					if ( false !== strpos( $clean_ak, 'mau' ) || false !== strpos( $clean_ak, 'color' ) ) {
						$color_attr_slug = $ak;
						if ( is_object( $attr_obj ) && method_exists( $attr_obj, 'get_name' ) ) {
							$color_attr_name = $attr_obj->get_name();
						}
					} elseif ( false !== strpos( $clean_ak, 'size' ) || false !== strpos( $clean_ak, 'kich-co' ) ) {
						$size_attr_slug = $ak;
						if ( is_object( $attr_obj ) && method_exists( $attr_obj, 'get_name' ) ) {
							$size_attr_name = $attr_obj->get_name();
						}
					}
				}
			}

			$new_wc_attrs = array();
			if ( ! empty( $color_options ) && class_exists( 'WC_Product_Attribute' ) ) {
				$attr_c = new WC_Product_Attribute();
				$attr_c->set_id( 0 );
				$attr_c->set_name( $color_attr_name );
				$attr_c->set_options( $color_options );
				$attr_c->set_position( 0 );
				$attr_c->set_visible( true );
				$attr_c->set_variation( true );
				$new_wc_attrs[ sanitize_title( $color_attr_slug ) ] = $attr_c;
			}
			if ( ! empty( $size_options ) && class_exists( 'WC_Product_Attribute' ) ) {
				$attr_s = new WC_Product_Attribute();
				$attr_s->set_id( 0 );
				$attr_s->set_name( $size_attr_name );
				$attr_s->set_options( $size_options );
				$attr_s->set_position( 1 );
				$attr_s->set_visible( true );
				$attr_s->set_variation( true );
				$new_wc_attrs[ sanitize_title( $size_attr_slug ) ] = $attr_s;
			}
			if ( ! empty( $new_wc_attrs ) ) {
				$product->set_attributes( $new_wc_attrs );
				$product->save();
			}

			// Map existing child variations by SKU and by (color_key, size)
			$existing_children_by_sku = array();
			$existing_children_by_cs  = array();
			foreach ( $product->get_children() as $child_id ) {
				$c_obj = wc_get_product( $child_id );
				if ( ! $c_obj || ! $c_obj instanceof WC_Product_Variation ) {
					continue;
				}
				$c_sku = strtoupper( trim( (string) $c_obj->get_sku() ) );
				if ( '' !== $c_sku ) {
					$existing_children_by_sku[ $c_sku ] = $c_obj;
				}
				$c_ckey = self::get_wc_variation_class_key( $c_obj );
				$c_attrs = $c_obj->get_attributes();
				$c_size  = '';
				foreach ( $c_attrs as $ak => $av ) {
					if ( false !== strpos( strtolower( $ak ), 'size' ) ) {
						$c_size = strtolower( trim( (string) $av ) );
					}
				}
				$existing_children_by_cs[ $c_ckey . '__' . $c_size ] = $c_obj;
			}

			$c_attr_key         = sanitize_title( $color_attr_slug );
			$s_attr_key         = sanitize_title( $size_attr_slug );
			$matched_wc_var_ids = array();
			$total_var_stock    = 0;

			foreach ( $p_vars as $pv ) {
				$vsku  = trim( (string) ( $pv['sku'] ?? '' ) );
				$vsku_up = strtoupper( $vsku );
				$c_val = trim( (string) ( $pv['color_raw'] ?? '' ) );
				$c_key = (string) ( $pv['color_key'] ?? self::normalize_class_key( $c_val ) );
				$s_val = trim( (string) ( $pv['size'] ?? '' ) );
				$cs_lookup = $c_key . '__' . strtolower( $s_val );

				$var_obj = null;
				if ( '' !== $vsku_up && isset( $existing_children_by_sku[ $vsku_up ] ) ) {
					$var_obj = $existing_children_by_sku[ $vsku_up ];
				} elseif ( isset( $existing_children_by_cs[ $cs_lookup ] ) ) {
					$var_obj = $existing_children_by_cs[ $cs_lookup ];
				}

				if ( ! $var_obj ) {
					$var_obj = new WC_Product_Variation();
					$var_obj->set_parent_id( $wc_id );
					$var_obj->set_status( 'publish' );
				}

				$var_attrs = array();
				if ( ! empty( $color_options ) && '' !== $c_val ) {
					$var_attrs[ $c_attr_key ] = $c_val;
				}
				if ( ! empty( $size_options ) && '' !== $s_val ) {
					$var_attrs[ $s_attr_key ] = $s_val;
				}
				$var_obj->set_attributes( $var_attrs );

				if ( '' !== $vsku ) {
					$v_owner = wc_get_product_id_by_sku( $vsku );
					if ( ! $v_owner || (int) $v_owner === (int) $var_obj->get_id() ) {
						$var_obj->set_sku( $vsku );
					}
				}

				if ( $opts['sync_price'] && (float) ( $pv['price'] ?? 0 ) > 0 ) {
					$price_str = (string) (float) $pv['price'];
					$var_obj->set_regular_price( $price_str );
					if ( '' === (string) $var_obj->get_sale_price() ) {
						$var_obj->set_price( $price_str );
					}
				}

				if ( $opts['sync_stock'] ) {
					$qty              = max( 0, (int) ( $pv['stock'] ?? 0 ) );
					$total_var_stock += $qty;
					$var_obj->set_manage_stock( true );
					$var_obj->set_stock_quantity( $qty );
					$var_obj->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
				}

				$saved_vid            = $var_obj->save();
				$matched_wc_var_ids[] = (int) $saved_vid;
				$variations_synced++;
			}

			// Zero out stock on any stale Website variations that are no longer in Pancake's active variations
			if ( $opts['sync_stock'] ) {
				foreach ( $product->get_children() as $child_id ) {
					if ( ! in_array( (int) $child_id, $matched_wc_var_ids, true ) ) {
						update_post_meta( (int) $child_id, '_manage_stock', 'yes' );
						wc_update_product_stock( (int) $child_id, 0, 'set' );
						update_post_meta( (int) $child_id, '_stock_status', 'outofstock' );
					}
				}
				update_post_meta( $wc_id, '_manage_stock', 'no' );
				delete_post_meta( $wc_id, '_stock' );
				update_post_meta( $wc_id, '_stock_status', $total_var_stock > 0 ? 'instock' : 'outofstock' );
			}

			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $wc_id );
			}
		} else {
			// Simple product price & stock sync
			if ( $opts['sync_price'] && (float) ( $pancake_item['min_price'] ?? 0 ) > 0 ) {
				$price_str = (string) (float) $pancake_item['min_price'];
				$product->set_regular_price( $price_str );
				if ( '' === (string) $product->get_sale_price() ) {
					$product->set_price( $price_str );
				}
			}
			if ( $opts['sync_stock'] ) {
				$qty = max( 0, (int) ( $pancake_item['total_stock'] ?? 0 ) );
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $qty );
				$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			}
			$product->save();
		}

		// Reload product object so get_children() has all newly created variation IDs
		$fresh_product = wc_get_product( $wc_id );
		$img_res       = array(
			'downloaded_urls'    => 0,
			'variations_img_set' => 0,
		);
		if ( $opts['sync_images'] && $fresh_product ) {
			$img_res = self::sync_product_with_pancake(
				$fresh_product,
				$pancake_item,
				array(
					'set_sku'          => true,
					'set_thumb'        => true,
					'set_gallery'      => true,
					'set_var_img'      => true,
					'sync_stock'       => false,
					'overwrite_images' => false,
					'skip_live_query'  => true,
				)
			);
		} else {
			update_post_meta( $wc_id, '_nexxi_pancake_last_sync', time() );
		}

		wp_defer_term_counting( false );

		// Bump post_date & post_modified so newly synced/updated Pancake products immediately sort first under 'Newest'
		$now_local = current_time( 'mysql' );
		$now_gmt   = current_time( 'mysql', 1 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'         => $now_local,
				'post_date_gmt'     => $now_gmt,
				'post_modified'     => $now_local,
				'post_modified_gmt' => $now_gmt,
				'menu_order'        => 0,
			),
			array( 'ID' => (int) $wc_id ),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $wc_id );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $wc_id );
		}
		if ( function_exists( 'wc_update_product_lookup_tables_column' ) ) {
			wc_delete_product_transients( $wc_id );
		}
		do_action( 'litespeed_purge_post', $wc_id );
		do_action( 'litespeed_purge_all' );

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$thumb_url = wp_get_attachment_image_url( get_post_thumbnail_id( $wc_id ), 'thumbnail' );

		return array(
			'wc_id'              => $wc_id,
			'pancake_id'         => $pancake_id,
			'pancake_sku'        => $parent_sku,
			'name'               => $prod_name,
			'created_new'        => $created_new,
			'skipped_duplicate'  => false,
			'variations_synced'  => $variations_synced,
			'total_stock'        => (int) ( $pancake_item['total_stock'] ?? 0 ),
			'min_price'          => (float) ( $pancake_item['min_price'] ?? 0 ),
			'downloaded_urls'    => (int) ( $img_res['downloaded_urls'] ?? 0 ),
			'variations_img_set' => (int) ( $img_res['variations_img_set'] ?? 0 ),
			'edit_url'           => get_edit_post_link( $wc_id, 'raw' ),
			'view_url'           => get_permalink( $wc_id ),
			'thumb_url'          => $thumb_url ? $thumb_url : '',
		);
	}

	/**
	 * Tab 1 Engine: Delete a product (and all its child variations) from the Website & disconnect from Pancake,
	 * or disconnect only without deleting if `$mode === 'disconnect_only'`.
	 */
	public static function delete_and_disconnect_wc_product( $wc_id, $pancake_sku = '', $pancake_id = '', $mode = 'delete_and_disconnect' ) {
		$wc_id       = absint( $wc_id );
		$pancake_sku = strtoupper( trim( (string) $pancake_sku ) );
		$pancake_id  = trim( (string) $pancake_id );

		// If wc_id wasn't passed, try finding it by Pancake SKU / ID
		if ( $wc_id <= 0 && ( '' !== $pancake_sku || '' !== $pancake_id ) ) {
			$found = self::find_existing_wc_product_for_pancake(
				array(
					'id'  => $pancake_id,
					'sku' => $pancake_sku,
				)
			);
			if ( $found ) {
				$wc_id = $found->get_id();
			}
		}

		if ( $wc_id > 0 ) {
			if ( '' === $pancake_sku ) {
				$pancake_sku = strtoupper( trim( (string) get_post_meta( $wc_id, '_nexxi_pancake_sku', true ) ) );
				if ( '' === $pancake_sku ) {
					$pancake_sku = strtoupper( trim( (string) get_post_meta( $wc_id, '_sku', true ) ) );
				}
			}
			if ( '' === $pancake_id ) {
				$pancake_id = trim( (string) get_post_meta( $wc_id, '_nexxi_pancake_product_id', true ) );
			}
		}

		// Record disconnected status so Pancake auto-sync knows this SKU is intentionally disconnected
		self::mark_sku_disconnected( $pancake_sku, $pancake_id );

		$deleted_variations = 0;
		$deleted_product    = false;

		if ( $wc_id > 0 ) {
			if ( 'disconnect_only' === $mode ) {
				delete_post_meta( $wc_id, '_nexxi_pancake_product_id' );
				delete_post_meta( $wc_id, '_nexxi_pancake_sku' );
				delete_post_meta( $wc_id, '_nexxi_pancake_last_sync' );
			} else {
				$prod = wc_get_product( $wc_id );
				if ( $prod ) {
					$children = $prod->get_children();
					foreach ( $children as $child_id ) {
						wp_delete_post( (int) $child_id, true );
						$deleted_variations++;
					}
				}
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $wc_id );
				}
				$del_res = wp_delete_post( $wc_id, true );
				$deleted_product = (bool) $del_res;
			}
		}

		return array(
			'wc_id'              => $wc_id,
			'pancake_sku'        => $pancake_sku,
			'pancake_id'         => $pancake_id,
			'mode'               => $mode,
			'deleted_product'    => $deleted_product,
			'deleted_variations' => $deleted_variations,
		);
	}

	/**
	 * AJAX: Change active Pancake Warehouse for POS -> WOO stock sync and recalculate catalog stock immediately.
	 */
	public function ajax_set_warehouse() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}
		$wh_id    = isset( $_POST['warehouse_id'] ) ? sanitize_text_field( wp_unslash( $_POST['warehouse_id'] ) ) : self::DEFAULT_WAREHOUSE_ID;
		$settings = self::get_settings();
		$settings['warehouse_id'] = '' !== $wh_id ? $wh_id : self::DEFAULT_WAREHOUSE_ID;
		update_option( self::SETTINGS_OPT, $settings );

		// Re-save catalog cache so per-warehouse stock is immediately recalculated
		$catalog = self::get_pancake_catalog( false );
		if ( ! empty( $catalog ) ) {
			self::save_catalog_cache( $catalog );
		}

		wp_send_json_success(
			array(
				'warehouse_id' => $settings['warehouse_id'],
				'message'      => 'Đã chuyển kho đồng bộ tồn POS → WOO thành công!',
			)
		);
	}

	/**
	 * AJAX (Tab 1): Import / Sync a single Pancake product to the Website by `pancake_id` or `pancake_sku`.
	 */
	public function ajax_import_product() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$pancake_id  = isset( $_POST['pancake_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pancake_id'] ) ) : '';
		$pancake_sku = isset( $_POST['pancake_sku'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['pancake_sku'] ) ) ) ) : '';
		$live_query  = ! empty( $_POST['live_query'] ) && '1' === (string) $_POST['live_query'];

		$p_item = null;
		if ( $live_query && '' !== $pancake_sku ) {
			$p_item = self::query_pancake_product_by_sku( $pancake_sku, true );
		}

		if ( ! $p_item ) {
			$catalog = self::get_pancake_catalog( false );
			$maps    = self::build_lookup_maps( $catalog );

			if ( '' !== $pancake_id && isset( $maps['by_id'][ $pancake_id ] ) ) {
				$p_item = $maps['by_id'][ $pancake_id ];
			} elseif ( '' !== $pancake_sku && isset( $maps['by_sku'][ $pancake_sku ] ) ) {
				$p_item = $maps['by_sku'][ $pancake_sku ];
			} elseif ( '' !== $pancake_sku && isset( $maps['by_var_sku'][ $pancake_sku ] ) ) {
				$p_item = $maps['by_var_sku'][ $pancake_sku ];
			}
		}

		if ( ! $p_item && '' !== $pancake_sku ) {
			$p_item = self::query_pancake_product_by_sku( $pancake_sku, true );
		}

		if ( ! $p_item ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm trên Pancake POS (' . ( $pancake_sku ?: $pancake_id ) . ').' ) );
		}

		$opts = array(
			'post_status'     => isset( $_POST['post_status'] ) && 'draft' === $_POST['post_status'] ? 'draft' : 'publish',
			'skip_existing'   => ! isset( $_POST['skip_existing'] ) || '1' === (string) $_POST['skip_existing'],
			'sync_variations' => ! isset( $_POST['sync_variations'] ) || '1' === (string) $_POST['sync_variations'],
			'sync_price'      => ! isset( $_POST['sync_price'] ) || '1' === (string) $_POST['sync_price'],
			'sync_stock'      => ! isset( $_POST['sync_stock'] ) || '1' === (string) $_POST['sync_stock'],
			'sync_categories' => ! isset( $_POST['sync_categories'] ) || '1' === (string) $_POST['sync_categories'],
			'sync_images'     => ! isset( $_POST['sync_images'] ) || '1' === (string) $_POST['sync_images'],
		);

		$res = self::sync_or_create_wc_product_from_pancake( $p_item, $opts );
		wp_send_json_success( $res );
	}

	/**
	 * AJAX (Tab 1): Delete from Website & disconnect from Pancake for 1 product (called per item in batch queue).
	 */
	public function ajax_delete_disconnect() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$wc_id       = isset( $_POST['wc_id'] ) ? absint( $_POST['wc_id'] ) : 0;
		$pancake_id  = isset( $_POST['pancake_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pancake_id'] ) ) : '';
		$pancake_sku = isset( $_POST['pancake_sku'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['pancake_sku'] ) ) ) ) : '';
		$mode        = isset( $_POST['mode'] ) && 'disconnect_only' === $_POST['mode'] ? 'disconnect_only' : 'delete_and_disconnect';

		if ( $wc_id <= 0 && '' === $pancake_sku && '' === $pancake_id ) {
			wp_send_json_error( array( 'message' => 'Thiếu thông tin sản phẩm cần xóa / ngắt kết nối.' ) );
		}

		$res = self::delete_and_disconnect_wc_product( $wc_id, $pancake_sku, $pancake_id, $mode );
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Sync a single product (or batch item) with Pancake by WC Product ID.
	 */
	public function ajax_sync_single() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm #' . $product_id ) );
		}

		$catalog = self::get_pancake_catalog( false );
		$maps    = self::build_lookup_maps( $catalog );
		$match   = self::match_wc_product( $product, $maps, true );

		if ( empty( $match['pancake'] ) ) {
			wp_send_json_error( array( 'message' => 'Sản phẩm chưa khớp mã SKU với Pancake.' ) );
		}

		$opts = array(
			'set_sku'          => ! isset( $_POST['set_sku'] ) || '1' === (string) $_POST['set_sku'],
			'set_thumb'        => ! isset( $_POST['set_thumb'] ) || '1' === (string) $_POST['set_thumb'],
			'set_gallery'      => ! isset( $_POST['set_gallery'] ) || '1' === (string) $_POST['set_gallery'],
			'set_var_img'      => ! isset( $_POST['set_var_img'] ) || '1' === (string) $_POST['set_var_img'],
			'sync_stock'       => ! empty( $_POST['sync_stock'] ) && '1' === (string) $_POST['sync_stock'],
			'overwrite_images' => ! empty( $_POST['overwrite_images'] ) && '1' === (string) $_POST['overwrite_images'],
		);

		$res = self::sync_product_with_pancake( $product, $match['pancake'], $opts );
		$new_thumb = wp_get_attachment_image_url( get_post_thumbnail_id( $product_id ), 'thumbnail' );
		$res['thumb_url'] = $new_thumb ? $new_thumb : '';

		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Manually link a WooCommerce product to a Pancake SKU (`custom_id`).
	 */
	public function ajax_link_sku() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$pancake_sku = isset( $_POST['pancake_sku'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['pancake_sku'] ) ) ) ) : '';
		$product     = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product || '' === $pancake_sku ) {
			wp_send_json_error( array( 'message' => 'Vui lòng nhập mã SKU Pancake hợp lệ.' ) );
		}

		$matched = self::query_pancake_product_by_sku( $pancake_sku );
		if ( ! $matched ) {
			$catalog = self::get_pancake_catalog( false );
			$maps    = self::build_lookup_maps( $catalog );
			if ( isset( $maps['by_sku'][ $pancake_sku ] ) ) {
				$matched = $maps['by_sku'][ $pancake_sku ];
			} elseif ( isset( $maps['by_var_sku'][ $pancake_sku ] ) ) {
				$matched = $maps['by_var_sku'][ $pancake_sku ];
			}
		}

		if ( ! $matched ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm nào trên Pancake POS có SKU: ' . $pancake_sku ) );
		}

		update_post_meta( $product_id, '_nexxi_pancake_product_id', $matched['id'] );
		update_post_meta( $product_id, '_nexxi_pancake_sku', $matched['sku'] );

		$owner = wc_get_product_id_by_sku( $matched['sku'] );
		if ( ! $owner || (int) $owner === (int) $product_id ) {
			update_post_meta( $product_id, '_sku', $matched['sku'] );
		}

		wp_send_json_success(
			array(
				'message'     => 'Đã liên kết với sản phẩm Pancake: [' . $matched['sku'] . '] ' . $matched['name'],
				'pancake_sku' => $matched['sku'],
			)
		);
	}

	/**
	 * AJAX: Get detailed Pancake classifications & Website variation classifications for a product
	 * so the admin can inspect and map classification images interactively.
	 */
	public function ajax_get_classifications() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm.' ) );
		}

		$lookup_sku = self::get_wc_product_lookup_sku( $product );
		$live_p     = $lookup_sku ? self::query_pancake_product_by_sku( $lookup_sku ) : null;
		if ( $live_p ) {
			$p_item = $live_p;
		} else {
			$catalog = self::get_pancake_catalog( false );
			$maps    = self::build_lookup_maps( $catalog );
			$match   = self::match_wc_product( $product, $maps, true );
			if ( empty( $match['pancake'] ) ) {
				wp_send_json_error( array( 'message' => 'Sản phẩm chưa khớp với Pancake.' ) );
			}
			$p_item = $match['pancake'];
		}

		// Collect Website classifications (color groups + variations)
		$wc_classes = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( ! $v || ! $v instanceof WC_Product_Variation ) {
					continue;
				}
				$ckey = self::get_wc_variation_class_key( $v );
				if ( '' === $ckey ) {
					$ckey = 'all_variations';
				}
				if ( ! isset( $wc_classes[ $ckey ] ) ) {
					$label = 'all_variations' === $ckey ? 'Tất cả biến thể (Theo Size)' : self::pretty_class_label( $ckey );
					$wc_classes[ $ckey ] = array(
						'key'            => $ckey,
						'label'          => $label,
						'variation_ids'  => array(),
						'skus'           => array(),
						'current_img'    => '',
						'matched_p_img'  => isset( $p_item['class_images'][ $ckey ]['image'] ) ? $p_item['class_images'][ $ckey ]['image'] : ( ! empty( $p_item['all_images'][0] ) ? $p_item['all_images'][0] : '' ),
					);
				}
				$wc_classes[ $ckey ]['variation_ids'][] = $child_id;
				if ( $v->get_sku() ) {
					$wc_classes[ $ckey ]['skus'][] = $v->get_sku();
				}
				$v_thumb = (int) get_post_meta( $child_id, '_thumbnail_id', true );
				if ( $v_thumb > 0 && '' === $wc_classes[ $ckey ]['current_img'] ) {
					$wc_classes[ $ckey ]['current_img'] = wp_get_attachment_image_url( $v_thumb, 'thumbnail' );
				}
			}
		}

		// Also include Pancake classifications (from _nexxi_class_image_map or Pancake POS) so every color can be mapped on Website
		$saved_map = get_post_meta( $product_id, '_nexxi_class_image_map', true );
		if ( ! is_array( $saved_map ) ) {
			$saved_map = array();
		}
		if ( ! empty( $p_item['class_images'] ) ) {
			foreach ( $p_item['class_images'] as $p_ckey => $p_cinfo ) {
				if ( ! isset( $wc_classes[ $p_ckey ] ) ) {
					$cur_u = '';
					if ( ! empty( $saved_map[ $p_ckey ]['att_id'] ) ) {
						$cur_u = wp_get_attachment_image_url( (int) $saved_map[ $p_ckey ]['att_id'], 'thumbnail' );
					}
					$wc_classes[ $p_ckey ] = array(
						'key'           => $p_ckey,
						'label'         => $p_cinfo['label'],
						'variation_ids' => $product->is_type( 'variable' ) ? $product->get_children() : array(),
						'skus'          => $p_cinfo['skus'],
						'current_img'   => $cur_u ? $cur_u : '',
						'matched_p_img' => $p_cinfo['image'],
					);
				}
			}
			// If we have specific color rows from Pancake, remove the generic 'all_variations' row if there are > 1 colors
			if ( count( $p_item['class_images'] ) > 1 && isset( $wc_classes['all_variations'] ) ) {
				unset( $wc_classes['all_variations'] );
			}
		}

		wp_send_json_success(
			array(
				'product_id'      => $product_id,
				'product_name'    => $product->get_name(),
				'pancake_sku'     => $p_item['sku'],
				'pancake_classes' => array_values( $p_item['class_images'] ),
				'pancake_images'  => $p_item['all_images'],
				'wc_classes'      => array_values( $wc_classes ),
			)
		);
	}

	/**
	 * AJAX: Save & apply classification images from Pancake to Website variations for a product.
	 */
	public function ajax_apply_classifications() {
		check_ajax_referer( 'nexxi_pancake_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm.' ) );
		}

		$catalog = self::get_pancake_catalog( false );
		$maps    = self::build_lookup_maps( $catalog );
		$match   = self::match_wc_product( $product, $maps, true );
		if ( empty( $match['pancake'] ) ) {
			wp_send_json_error( array( 'message' => 'Sản phẩm chưa khớp với Pancake.' ) );
		}

		$custom_map = array();
		if ( ! empty( $_POST['class_map'] ) && is_array( $_POST['class_map'] ) ) {
			foreach ( wp_unslash( $_POST['class_map'] ) as $k => $url ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$k   = sanitize_text_field( $k );
				$url = esc_url_raw( $url );
				if ( $k && $url ) {
					$custom_map[ $k ] = $url;
				}
			}
		}

		// Also handle 'all_variations' key if product only has Size variations without Color attribute
		$res = self::sync_product_with_pancake(
			$product,
			$match['pancake'],
			array(
				'set_sku'          => true,
				'set_thumb'        => true,
				'set_gallery'      => true,
				'set_var_img'      => true,
				'overwrite_images' => true,
				'custom_class_map' => $custom_map,
			)
		);

		// If 'all_variations' was mapped to a specific URL, apply to all children
		if ( ! empty( $custom_map['all_variations'] ) && $product->is_type( 'variable' ) ) {
			$att_id = self::sideload_pancake_image( $custom_map['all_variations'], $product_id, $product->get_name() );
			if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
				foreach ( $product->get_children() as $cid ) {
					update_post_meta( $cid, '_thumbnail_id', $att_id );
					$res['variations_img_set']++;
				}
			}
		}

		$new_thumb = wp_get_attachment_image_url( get_post_thumbnail_id( $product_id ), 'thumbnail' );
		$res['thumb_url'] = $new_thumb ? $new_thumb : '';

		wp_send_json_success( $res );
	}

	/* ==========================================================================
	 * 4. ADMIN DASHBOARD RENDER
	 * ========================================================================== */

	public function render_admin_page() {
		if ( ! empty( $GLOBALS['nexxi_pancake_page_rendered'] ) ) {
			return;
		}
		$GLOBALS['nexxi_pancake_page_rendered'] = true;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'products';
		if ( ! in_array( $active_tab, array( 'products', 'images' ), true ) ) {
			$active_tab = 'products';
		}

		$search_q   = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
		$filter_st  = isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : 'all';
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page   = 40;

		// If user is searching in Tab 1 (Product Sync by SKU), also query Pancake POS live so any product on Pancake POS is immediately found
		if ( 'products' === $active_tab && '' !== $search_q ) {
			self::query_pancake_products_by_search( $search_q );
		}

		$settings   = self::get_settings();
		$catalog    = self::get_pancake_catalog( false );
		$cache_time = (int) get_option( self::CACHE_TIME_OPT, 0 );
		$maps       = self::build_lookup_maps( $catalog );

		// Automatically clean up duplicate products on initial page load (skipped during fast AJAX pagination)
		if ( ! wp_doing_ajax() ) {
			self::cleanup_duplicate_wc_products();
		}

		// Fast SQL load of all WooCommerce products, SKUs, thumbnails & variations (0 WC_Product object overhead)
		$wc_products = self::get_fast_wc_catalog_rows();

		$ajax_nonce = wp_create_nonce( 'nexxi_pancake_ajax' );
		$tab_prod_url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'products',
			),
			admin_url( 'admin.php' )
		);
		$tab_img_url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'images',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap nexxi-pancake-wrap">
			<style>
				.nexxi-pancake-wrap { max-width: 1480px; margin-top: 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.nxp-hero { background: linear-gradient(135deg, #1b2a59 0%, #2b4285 100%); color: #fff; border-radius: 16px; padding: 22px 28px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; box-shadow: 0 10px 28px rgba(27,42,89,0.16); margin-bottom: 16px; }
				.nxp-hero h1 { color: #fff; font-size: 23px; font-weight: 800; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
				.nxp-hero p { margin: 0; color: rgba(255,255,255,0.86); font-size: 13.5px; }
				.nxp-tabs-nav { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }
				@media (max-width: 860px) { .nxp-tabs-nav { grid-template-columns: 1fr; } }
				.nxp-tab-link { display: flex; align-items: center; gap: 14px; padding: 16px 20px; border-radius: 14px; background: #fff; border: 2px solid #e2e8f0; text-decoration: none; color: #1e293b; transition: all 0.18s; box-shadow: 0 2px 6px rgba(15,23,42,0.04); }
				.nxp-tab-link:hover { border-color: #94a3b8; color: #0f172a; transform: translateY(-1px); }
				.nxp-tab-link.active { border-color: #e85d3f; background: linear-gradient(135deg, #fff7f5 0%, #ffffff 100%); box-shadow: 0 6px 18px rgba(232,93,63,0.15); }
				.nxp-tab-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; background: #f1f5f9; flex-shrink: 0; }
				.nxp-tab-link.active .nxp-tab-icon { background: #e85d3f; color: #fff; }
				.nxp-tab-title { font-size: 15.5px; font-weight: 800; color: #1b2a59; margin-bottom: 3px; display: flex; align-items: center; gap: 8px; }
				.nxp-tab-desc { font-size: 12.5px; color: #64748b; line-height: 1.35; }
				.nxp-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px; }
				.nxp-stat-card { background: #fff; border-radius: 12px; padding: 16px 18px; border: 1px solid #e2e8f0; box-shadow: 0 2px 6px rgba(15,23,42,0.04); }
				.nxp-stat-card .num { font-size: 26px; font-weight: 800; color: #1b2a59; line-height: 1.1; }
				.nxp-stat-card .lbl { font-size: 12.5px; color: #64748b; margin-top: 4px; font-weight: 500; }
				.nxp-stat-card.accent .num { color: #e85d3f; }
				.nxp-stat-card.ok .num { color: #16a34a; }
				.nxp-grid-2 { display: grid; grid-template-columns: 1.35fr 1fr; gap: 18px; margin-bottom: 20px; }
				@media (max-width: 1100px) { .nxp-grid-2 { grid-template-columns: 1fr; } }
				.nxp-card { background: #fff; border-radius: 14px; padding: 20px 22px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(15,23,42,0.04); }
				.nxp-card h2 { margin: 0 0 12px; font-size: 16.5px; font-weight: 700; color: #1b2a59; display: flex; align-items: center; gap: 8px; }
				.nxp-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; border: none; text-decoration: none; transition: all 0.15s; }
				.nxp-btn-primary { background: #e85d3f; color: #fff; box-shadow: 0 4px 12px rgba(232,93,63,0.28); }
				.nxp-btn-primary:hover { background: #d44d30; color: #fff; }
				.nxp-btn-navy { background: #1b2a59; color: #fff; }
				.nxp-btn-navy:hover { background: #263b7a; color: #fff; }
				.nxp-btn-danger { background: #dc2626; color: #fff; box-shadow: 0 4px 12px rgba(220,38,38,0.22); }
				.nxp-btn-danger:hover { background: #b91c1c; color: #fff; }
				.nxp-btn-outline { background: #f8fafc; color: #1b2a59; border: 1px solid #cbd5e1; }
				.nxp-btn-outline:hover { background: #f1f5f9; color: #0f172a; }
				.nxp-btn-sm { padding: 6px 11px; font-size: 12px; border-radius: 6px; }
				.nxp-btn:disabled { opacity: 0.55; cursor: not-allowed; }
				.nxp-progress-box { display: none; margin-top: 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 14px; }
				.nxp-progress-bar-wrap { width: 100%; height: 12px; background: #e2e8f0; border-radius: 99px; overflow: hidden; margin: 8px 0; }
				.nxp-progress-bar { height: 100%; width: 0%; background: linear-gradient(90deg, #e85d3f, #f59e0b); transition: width 0.25s; }
				.nxp-log { max-height: 160px; overflow-y: auto; font-family: monospace; font-size: 12px; background: #0f172a; color: #e2e8f0; padding: 10px; border-radius: 8px; margin-top: 8px; }
				.nxp-filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; background: #fff; padding: 14px 18px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 16px; }
				.nxp-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }
				.nxp-table th { background: #f8fafc; color: #334155; font-weight: 700; font-size: 12.5px; text-align: left; padding: 12px 14px; border-bottom: 1px solid #e2e8f0; }
				.nxp-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 13px; }
				.nxp-table tr:hover td { background: #fcfdff; }
				.nxp-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 99px; font-size: 11.5px; font-weight: 600; }
				.nxp-badge-ok { background: #dcfce7; color: #15803d; }
				.nxp-badge-warn { background: #fef3c7; color: #b45309; }
				.nxp-badge-err { background: #fee2e2; color: #b91c1c; }
				.nxp-badge-info { background: #e0e7ff; color: #1d4ed8; }
				.nxp-thumb-grid { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
				.nxp-mini-img { width: 42px; height: 42px; border-radius: 6px; object-fit: cover; border: 1px solid #cbd5e1; background: #f8fafc; }
				.nxp-class-chip { display: inline-flex; align-items: center; gap: 5px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 3px 7px 3px 3px; font-size: 11.5px; font-weight: 600; color: #1e293b; }
				.nxp-class-chip img { width: 26px; height: 26px; border-radius: 5px; object-fit: cover; }
				.nxp-drawer { background: #f8fafc; border-top: 2px dashed #cbd5e1; padding: 16px 20px; }
				.nxp-map-row { display: grid; grid-template-columns: 220px 1fr; gap: 14px; align-items: center; padding: 10px 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; }
				.nxp-choice-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 2px solid #e2e8f0; border-radius: 10px; cursor: pointer; background: #fff; transition: all 0.15s; }
				.nxp-choice-pill.active { border-color: #e85d3f; background: #fff5f2; }
				.nxp-choice-pill img { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; }
			</style>

			<div class="nxp-hero">
				<div>
					<h1>🥞 Quản Lý Sản Phẩm &amp; Đồng Bộ Ảnh Pancake (Theo Mã SKU)</h1>
					<p>Quản lý danh mục sản phẩm đồng bộ từ Pancake POS về Website &amp; đồng bộ ảnh sản phẩm / ảnh phân loại màu sắc theo mã SKU riêng biệt.</p>
				</div>
				<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
					<span style="font-size:12px;background:rgba(255,255,255,0.14);padding:6px 12px;border-radius:8px;">
						Dữ liệu Pancake: <strong><?php echo count( $catalog ); ?> SP</strong>
						<?php if ( $cache_time ) : ?>
							(Cập nhật <?php echo esc_html( human_time_diff( $cache_time, time() ) ); ?> trước)
						<?php endif; ?>
					</span>
					<button type="button" class="nxp-btn nxp-btn-primary" id="nxpBtnRefreshPancake">
						🔄 Làm Mới Dữ Liệu Từ Pancake POS
					</button>
				</div>
			</div>

			<!-- 2-TAB NAVIGATION BAR (NO-RELOAD INSTANT SWITCHING) -->
			<div class="nxp-tabs-nav">
				<a href="<?php echo esc_url( $tab_prod_url ); ?>" id="nxpTabBtnProducts" data-tab="products" class="nxp-tab-link <?php echo 'products' === $active_tab ? 'active' : ''; ?>">
					<div class="nxp-tab-icon">📦</div>
					<div>
						<div class="nxp-tab-title">
							<span>Tab 1: Quản Lý Đồng Bộ Sản Phẩm Pancake Theo Mã SKU</span>
							<span class="nxp-badge nxp-badge-ok nxp-tab-open-badge" style="<?php echo 'products' === $active_tab ? '' : 'display:none;'; ?>">Đang mở</span>
						</div>
						<div class="nxp-tab-desc">Quản lý sản phẩm có trên Pancake POS • Chọn 1 hoặc nhiều SP đồng bộ từ Pancake về Website • Chọn 1 hoặc nhiều SP xóa khỏi Website &amp; ngắt kết nối Pancake • Bộ lọc đa năng</div>
					</div>
				</a>
				<a href="<?php echo esc_url( $tab_img_url ); ?>" id="nxpTabBtnImages" data-tab="images" class="nxp-tab-link <?php echo 'images' === $active_tab ? 'active' : ''; ?>">
					<div class="nxp-tab-icon">🖼️</div>
					<div>
						<div class="nxp-tab-title">
							<span>Tab 2: Quản Lý Đồng Bộ Ảnh Pancake Theo Mã SKU</span>
							<span class="nxp-badge nxp-badge-ok nxp-tab-open-badge" style="<?php echo 'images' === $active_tab ? '' : 'display:none;'; ?>">Đang mở</span>
						</div>
						<div class="nxp-tab-desc">Đồng bộ SKU sản phẩm &amp; SKU phân loại từ Pancake POS • Tải toàn bộ ảnh SP (chỉ lấy ảnh chưa có) &amp; gán ảnh từng phân loại màu sắc theo SKU phân loại</div>
					</div>
				</a>
			</div>

			<?php if ( isset( $_GET['saved_msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong>Đã lưu cấu hình đồng bộ Pancake SKU &amp; Ảnh thành công!</strong></p></div>
			<?php endif; ?>

			<!-- TAB 1 PANE -->
			<div id="nxp-pane-products" class="nxp-tab-pane" style="<?php echo 'products' === $active_tab ? '' : 'display:none;'; ?>">
				<?php $this->render_tab_products( $settings, $catalog, $maps, $wc_products, $ajax_nonce ); ?>
			</div>

			<!-- TAB 2 PANE -->
			<div id="nxp-pane-images" class="nxp-tab-pane" style="<?php echo 'images' === $active_tab ? '' : 'display:none;'; ?>">
			<?php
			// =========================================================================
			// TAB 2: QUẢN LÝ ĐỒNG BỘ ẢNH PANCAKE THEO MÃ SKU (GIỮ NGUYÊN 100%)
			// =========================================================================
			$rows = array();
			$stats = array(
				'total_wc'              => count( $wc_products ),
				'matched_pancake'       => 0,
				'missing_web_img'       => 0,
				'has_pancake_img_ready' => 0,
				'has_class_img_ready'   => 0,
				'fully_synced_img'      => 0,
			);

			$queue_missing_img_ids = array();
			$queue_class_img_ids   = array();
			$queue_all_matched_ids = array();

			foreach ( $wc_products as $wrow ) {
				$pid          = (int) $wrow['id'];
				$wc_sku       = (string) $wrow['sku'];
				$thumb_id     = (int) $wrow['thumb_id'];
				$match_info   = self::match_fast_wc_row( $wrow, $maps );
				$p_item       = $match_info['pancake'];
				$var_total    = (int) $wrow['var_total'];
				$var_with_img = (int) $wrow['var_with_img'];
				$child_skus   = $wrow['child_skus'];

				$p_img_count   = $p_item ? count( $p_item['all_images'] ) : 0;
				$p_class_count = $p_item ? count( $p_item['class_images'] ) : 0;

				if ( $p_item ) {
					$stats['matched_pancake']++;
					$queue_all_matched_ids[] = $pid;
				}
				if ( $thumb_id <= 0 ) {
					$stats['missing_web_img']++;
					if ( $p_img_count > 0 ) {
						$stats['has_pancake_img_ready']++;
						$queue_missing_img_ids[] = $pid;
					}
				} else {
					$stats['fully_synced_img']++;
				}
				if ( $p_class_count > 0 ) {
					$stats['has_class_img_ready']++;
					$queue_class_img_ids[] = $pid;
					if ( $var_total > 0 && $var_with_img < $var_total && ! in_array( $pid, $queue_missing_img_ids, true ) ) {
						$queue_missing_img_ids[] = $pid;
					}
				}

				// Apply Status Filter
				if ( 'matched' === $filter_st && ! $p_item ) {
					continue;
				}
				if ( 'missing_img' === $filter_st && ( $thumb_id > 0 || $p_img_count <= 0 ) ) {
					continue;
				}
				if ( 'has_class' === $filter_st && $p_class_count <= 0 ) {
					continue;
				}
				if ( 'missing_var_img' === $filter_st && ( $p_class_count <= 0 || ( $var_total > 0 && $var_with_img >= $var_total ) ) ) {
					continue;
				}
				if ( 'unmatched' === $filter_st && $p_item ) {
					continue;
				}

				// Apply Search Filter (by SKU, Pancake SKU, Variation SKU, Leading Code, or Name)
				if ( '' !== $search_q ) {
					$q_low = mb_strtolower( $search_q );
					$haystack = mb_strtolower(
						implode(
							' ',
							array(
								$wrow['name'],
								$wc_sku,
								$p_item ? $p_item['sku'] : '',
								$p_item ? $p_item['code'] : '',
								$p_item ? $p_item['name'] : '',
								implode( ' ', $child_skus ),
							)
						)
					);
					if ( false === mb_strpos( $haystack, $q_low ) ) {
						continue;
					}
				}

				$rows[] = array(
					'id'            => $pid,
					'name'          => $wrow['name'],
					'wc_sku'        => $wc_sku,
					'thumb_id'      => $thumb_id,
					'gallery_count' => (int) $wrow['gallery_count'],
					'var_total'     => $var_total,
					'var_with_img'  => $var_with_img,
					'pancake'       => $p_item,
					'match_type'    => $match_info['match_type'],
				);
			}

			$total_filtered = count( $rows );
			$total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
			$paged          = min( $paged, $total_pages );
			$paged_rows     = array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page );
			foreach ( $paged_rows as &$pr ) {
				$pr['edit_url']  = admin_url( 'post.php?post=' . (int) $pr['id'] . '&action=edit' );
				$pr['view_url']  = get_permalink( (int) $pr['id'] );
				$pr['thumb_url'] = $pr['thumb_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $pr['thumb_id'], 'thumbnail' ) : '';
			}
			unset( $pr );
			$entered_key = isset( $settings['pancake_api_key'] ) ? trim( (string) $settings['pancake_api_key'] ) : '';
			if ( '' !== $entered_key && ( 0 === strpos( $entered_key, 'ck_' ) || 0 === strpos( $entered_key, 'cs_' ) ) ) :
				?>
				<div class="notice notice-warning">
					<p><strong>⚠️ Lưu ý về API Key:</strong> Mã bạn vừa nhập bắt đầu bằng <code>ck_</code> hoặc <code>cs_</code> (đây là mã <em>WooCommerce REST API</em> dùng để điền vào trang Pancake, không phải mã của Pancake POS). Để Website gọi trực tiếp sang Pancake POS lấy toàn bộ ảnh &amp; phân loại màu sắc mà không phụ thuộc giới hạn Cloud D1, hãy vào <strong>pos.pancake.vn &rarr; Cấu hình &rarr; Cấu hình chung &rarr; Thẻ API (API Key)</strong>, copy mã API Key của Pancake POS và dán vào ô <strong>Pancake POS API Key</strong> bên dưới.</p>
				</div>
			<?php endif; ?>

			<!-- STATS ROW -->
			<div class="nxp-stats">
				<div class="nxp-stat-card">
					<div class="num"><?php echo (int) $stats['total_wc']; ?></div>
					<div class="lbl">Tổng sản phẩm trên Website</div>
				</div>
				<div class="nxp-stat-card ok">
					<div class="num"><?php echo (int) $stats['matched_pancake']; ?></div>
					<div class="lbl">Đã khớp mã SKU với Pancake</div>
				</div>
				<div class="nxp-stat-card accent">
					<div class="num"><?php echo (int) $stats['has_pancake_img_ready']; ?></div>
					<div class="lbl">SP thiếu ảnh Web nhưng CÓ ảnh trên Pancake</div>
				</div>
				<div class="nxp-stat-card accent">
					<div class="num"><?php echo (int) $stats['has_class_img_ready']; ?></div>
					<div class="lbl">SP có ảnh Phân Loại (Màu sắc) trên Pancake</div>
				</div>
				<div class="nxp-stat-card ok">
					<div class="num"><?php echo (int) $stats['fully_synced_img']; ?></div>
					<div class="lbl">SP trên Website đã có ảnh đại diện</div>
				</div>
			</div>

			<!-- 2-COLUMN ACTION PANEL: AUTO-SCAN & BULK SKU + SETTINGS -->
			<div class="nxp-grid-2">
				<!-- LEFT: AUTO SCAN IMAGES & VARIATION CLASSIFICATIONS -->
				<div class="nxp-card">
					<h2>🚀 1. Tự Động Quét Ảnh Trên Pancake &amp; Set Ảnh Phân Loại Về Website</h2>
					<p style="margin:0 0 12px;color:#475569;font-size:13px;">
						Hệ thống tự động đối chiếu <strong>Mã SKU gốc</strong> (VD: <code>2MKFBSN01</code>, <code>2TBIHP01</code>) và <strong>Mã SKU phân loại</strong> (VD: <code>2MKFBSN01-DO01-7</code>, <code>MAU: DO / TRANG / HONG</code>) để tải ảnh từ Pancake về Thư viện Website và gán cho từng biến thể màu tương ứng.
					</p>

					<div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:14px;background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;font-size:13px;">
						<label><input type="checkbox" id="nxpOptThumb" checked> Đặt <strong>Ảnh đại diện</strong> SP</label>
						<label><input type="checkbox" id="nxpOptGallery" checked> Đưa toàn bộ ảnh các màu vào <strong>Album ảnh SP</strong></label>
						<label><input type="checkbox" id="nxpOptVarImg" checked> Tự động <strong>Set ảnh theo Phân loại (Màu sắc)</strong> cho biến thể</label>
						<label><input type="checkbox" id="nxpOptSku" checked> Đồng bộ chuẩn <strong>Mã SKU</strong> từ Pancake</label>
						<label><input type="checkbox" id="nxpOptStock"> Đồng bộ luôn <strong>Tồn kho Pancake</strong></label>
						<label><input type="checkbox" id="nxpOptOverwrite"> Ghi đè cả SP đã có ảnh sẵn trên Web</label>
					</div>

					<div style="display:flex;flex-wrap:wrap;gap:10px;">
						<button type="button" class="nxp-btn nxp-btn-primary" id="nxpBtnScanMissing" data-queue="<?php echo esc_attr( wp_json_encode( $queue_missing_img_ids ) ); ?>">
							📷 Quét Tự Động Các SP Đang Thiếu Ảnh (<?php echo count( $queue_missing_img_ids ); ?> SP)
						</button>
						<button type="button" class="nxp-btn nxp-btn-navy" id="nxpBtnScanClassifications" data-queue="<?php echo esc_attr( wp_json_encode( $queue_class_img_ids ) ); ?>">
							🎨 Lấy &amp; Set Ảnh Theo Phân Loại Cho Toàn Bộ SP Có Màu (<?php echo count( $queue_class_img_ids ); ?> SP)
						</button>
						<button type="button" class="nxp-btn nxp-btn-outline" id="nxpBtnScanSelected">
							✅ Quét Các SP Đang Chọn Bên Dưới
						</button>
					</div>

					<!-- BULK SKU INPUT -->
					<div style="margin-top:16px;padding-top:14px;border-top:1px dashed #cbd5e1;">
						<label style="display:block;font-weight:700;color:#1b2a59;font-size:13px;margin-bottom:6px;">
							⚡ Quét nhanh theo danh sách Mã SKU (Nhập 1 hoặc nhiều SKU cách nhau dấu phẩy / xuống dòng, VD: <code>2MKFBSN01, 2TBIHP01, 2607</code>):
						</label>
						<div style="display:flex;gap:8px;">
							<input type="text" id="nxpBulkSkuInput" placeholder="VD: 2MKFBSN01, 2TBIHP01, 1BCDBH05, 2607.8..." style="flex:1;padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;">
							<button type="button" class="nxp-btn nxp-btn-navy" id="nxpBtnScanBySkuList">
								🔍 Quét Ảnh &amp; Set Phân Loại Theo SKU
							</button>
						</div>
					</div>

					<!-- PROGRESS BAR & LIVE LOG -->
					<div class="nxp-progress-box" id="nxpProgressBox">
						<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:700;color:#1b2a59;">
							<span id="nxpProgressTitle">Đang quét ảnh từ Pancake...</span>
							<span id="nxpProgressCount">0 / 0</span>
						</div>
						<div class="nxp-progress-bar-wrap">
							<div class="nxp-progress-bar" id="nxpProgressBar"></div>
						</div>
						<div class="nxp-log" id="nxpProgressLog"></div>
					</div>
				</div>

				<!-- RIGHT: AUTO-CRON & API SETTINGS -->
				<div class="nxp-card">
					<h2>⚙️ 2. Cấu Hình Kết Nối Pancake &amp; Tự Động Quét Ngầm</h2>
					<form method="post" action="">
						<?php wp_nonce_field( 'nexxi_pancake_save_settings', 'nexxi_pancake_settings_nonce' ); ?>
						<div style="margin-bottom:10px;">
							<label style="display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:4px;">Pancake Cloud Sync API (NEXXI D1 Mirror):</label>
							<input type="url" name="cf_api_url" value="<?php echo esc_attr( $settings['cf_api_url'] ); ?>" style="width:100%;padding:7px 10px;border-radius:7px;border:1px solid #cbd5e1;font-size:13px;">
						</div>
						<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
							<div>
								<label style="display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:4px;">Pancake Shop ID:</label>
								<input type="text" name="shop_id" value="<?php echo esc_attr( $settings['shop_id'] ); ?>" style="width:100%;padding:7px 10px;border-radius:7px;border:1px solid #cbd5e1;font-size:13px;">
							</div>
							<div>
								<label style="display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:4px;">Pancake POS API Key (Lấy tại Cấu hình &rarr; Thẻ API):</label>
								<input type="password" name="pancake_api_key" value="<?php echo esc_attr( $settings['pancake_api_key'] ); ?>" placeholder="Dán API Key từ pos.pancake.vn (Cấu hình -> Thẻ API)" style="width:100%;padding:7px 10px;border-radius:7px;border:1px solid #cbd5e1;font-size:13px;">
							</div>
							<div style="grid-column:1 / -1;">
								<label style="display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:4px;">Kho hàng đồng bộ tồn POS &rarr; WOO (Tuân thủ cài đặt Kênh bán Website trên Pancake):</label>
								<?php
								$warehouses_list = self::get_pancake_warehouses();
								$cur_wh_id       = isset( $settings['warehouse_id'] ) ? trim( (string) $settings['warehouse_id'] ) : self::DEFAULT_WAREHOUSE_ID;
								?>
								<select name="warehouse_id" style="width:100%;padding:7px 10px;border-radius:7px;border:1px solid #cbd5e1;font-size:13px;font-weight:600;color:#1b2a59;">
									<?php foreach ( $warehouses_list as $wh_id => $wh_info ) :
										$wh_name = is_array( $wh_info ) ? ( $wh_info['name'] ?? $wh_id ) : (string) $wh_info;
										$wh_addr = is_array( $wh_info ) ? ( $wh_info['address'] ?? '' ) : '';
										?>
										<option value="<?php echo esc_attr( $wh_id ); ?>" <?php selected( $cur_wh_id, $wh_id ); ?>>
											📦 <?php echo esc_html( $wh_name ); ?><?php echo '' !== $wh_addr ? ' (' . esc_html( $wh_addr ) . ')' : ''; ?>
										</option>
									<?php endforeach; ?>
									<option value="all" <?php selected( $cur_wh_id, 'all' ); ?>>🌐 Gộp tổng tồn kho của tất cả các kho</option>
								</select>
							</div>
						</div>
						<div style="background:#f8fafc;padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:12px;font-size:12.5px;display:flex;flex-direction:column;gap:6px;">
							<label><input type="checkbox" name="auto_cron" value="1" <?php checked( ! empty( $settings['auto_cron'] ) ); ?>> <strong>Bật tự động quét ngầm mỗi 30 phút (WP-Cron)</strong>: Tự động phát hiện ảnh mới trên Pancake để đưa về Website</label>
							<label><input type="checkbox" name="auto_set_thumb" value="1" <?php checked( ! empty( $settings['auto_set_thumb'] ) ); ?>> Tự động gán Ảnh đại diện khi quét ngầm</label>
							<label><input type="checkbox" name="auto_set_gallery" value="1" <?php checked( ! empty( $settings['auto_set_gallery'] ) ); ?>> Tự động thêm toàn bộ ảnh màu vào Thư viện ảnh SP</label>
							<label><input type="checkbox" name="auto_set_var_img" value="1" <?php checked( ! empty( $settings['auto_set_var_img'] ) ); ?>> Tự động set ảnh theo Phân loại (Màu sắc) cho biến thể</label>
							<label><input type="checkbox" name="auto_set_sku" value="1" <?php checked( ! empty( $settings['auto_set_sku'] ) ); ?>> Tự động điền Mã SKU Pancake cho SP/biến thể chưa có SKU</label>
						</div>
						<button type="submit" class="nxp-btn nxp-btn-navy" style="width:100%;justify-content:center;">💾 Lưu Cấu Hình Tự Động</button>
					</form>
				</div>
			</div>

			<!-- TAB 2 DYNAMIC RESULTS CONTAINER (NO-RELOAD FILTER & PAGINATION) -->
			<div id="nxp-tab2-results" style="transition:opacity 0.15s;">
			<!-- SEARCH & FILTER BAR -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nxp-filter-bar nxp-spa-filter-form" data-target-tab="images">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="tab" value="images">
				<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;">
					<input type="search" name="q" value="<?php echo esc_attr( $search_q ); ?>" placeholder="Tìm theo Mã SKU (VD: 2MKFBSN01, 2TBIHP01-TRA-14), mã số (2607) hoặc tên SP..." style="min-width:340px;padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;">
					<select name="filter_status" style="padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;">
						<option value="all" <?php selected( $filter_st, 'all' ); ?>>Tất cả sản phẩm (<?php echo (int) $stats['total_wc']; ?>)</option>
						<option value="matched" <?php selected( $filter_st, 'matched' ); ?>>✅ Đã khớp Pancake (<?php echo (int) $stats['matched_pancake']; ?>)</option>
						<option value="missing_img" <?php selected( $filter_st, 'missing_img' ); ?>>📷 Thiếu ảnh Web nhưng Pancake CÓ ảnh (<?php echo (int) $stats['has_pancake_img_ready']; ?>)</option>
						<option value="has_class" <?php selected( $filter_st, 'has_class' ); ?>>🎨 Có ảnh Phân loại / Màu trên Pancake (<?php echo (int) $stats['has_class_img_ready']; ?>)</option>
						<option value="missing_var_img" <?php selected( $filter_st, 'missing_var_img' ); ?>>⚠️ Biến thể Web chưa gán đủ ảnh Phân loại</option>
						<option value="unmatched" <?php selected( $filter_st, 'unmatched' ); ?>>❓ Chưa khớp Pancake</option>
					</select>
					<button type="submit" class="nxp-btn nxp-btn-navy">Lọc Sản Phẩm</button>
					<?php if ( '' !== $search_q || 'all' !== $filter_st ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=images' ) ); ?>" class="nxp-btn nxp-btn-outline nxp-spa-page-link" data-target-tab="images">Xóa lọc</a>
					<?php endif; ?>
				</div>
				<div style="font-size:13px;color:#64748b;">
					Hiển thị <strong><?php echo count( $paged_rows ); ?></strong> / <strong><?php echo (int) $total_filtered; ?></strong> sản phẩm (Trang <?php echo (int) $paged; ?>/<?php echo (int) $total_pages; ?>)
				</div>
			</form>

			<!-- PRODUCT SKU & IMAGE MANAGEMENT TABLE -->
			<table class="nxp-table">
				<thead>
					<tr>
						<th style="width:32px;"><input type="checkbox" id="nxpCheckAll"></th>
						<th style="width:74px;">Ảnh Web</th>
						<th>Sản Phẩm Website &amp; Mã SKU</th>
						<th>Khớp Pancake (SKU &amp; Kho)</th>
						<th>Ảnh &amp; Phân Loại Trên Pancake</th>
						<th>Trạng Thái Ảnh Phân Loại Web</th>
						<th style="text-align:right;width:230px;">Thao Tác</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $paged_rows ) ) : ?>
						<tr>
							<td colspan="7" style="text-align:center;padding:36px;color:#64748b;">Không có sản phẩm nào khớp với bộ lọc hiện tại.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $paged_rows as $r ) :
							$p = $r['pancake'];
							$p_sku = $p ? $p['sku'] : '';
							$p_imgs = $p ? $p['all_images'] : array();
							$p_classes = $p ? $p['class_images'] : array();
							?>
							<tr id="nxp-row-<?php echo (int) $r['id']; ?>" data-product-id="<?php echo (int) $r['id']; ?>" data-sku="<?php echo esc_attr( strtoupper( $r['wc_sku'] . ' ' . $p_sku . ' ' . ( $p ? $p['code'] : '' ) ) ); ?>">
								<td>
									<input type="checkbox" class="nxp-row-check" value="<?php echo (int) $r['id']; ?>">
								</td>
								<td>
									<div id="nxp-web-thumb-<?php echo (int) $r['id']; ?>">
										<?php if ( $r['thumb_url'] ) : ?>
											<img src="<?php echo esc_url( $r['thumb_url'] ); ?>" class="nxp-mini-img" style="width:54px;height:54px;" alt="">
										<?php else : ?>
											<div style="width:54px;height:54px;border-radius:8px;background:#fee2e2;color:#b91c1c;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;text-align:center;padding:2px;">Chưa có ảnh</div>
										<?php endif; ?>
									</div>
								</td>
								<td>
									<div style="font-weight:700;color:#1b2a59;margin-bottom:4px;">
										<a href="<?php echo esc_url( $r['edit_url'] ); ?>" style="color:#1b2a59;text-decoration:none;"><?php echo esc_html( $r['name'] ); ?></a>
									</div>
									<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px;">
										<span style="background:#f1f5f9;padding:2px 8px;border-radius:5px;font-family:monospace;font-weight:700;color:#0f172a;">
											SKU Web: <span id="nxp-wc-sku-<?php echo (int) $r['id']; ?>"><?php echo $r['wc_sku'] ? esc_html( $r['wc_sku'] ) : '<em style="color:#dc2626;">Chưa có</em>'; ?></span>
										</span>
										<a href="<?php echo esc_url( $r['view_url'] ); ?>" target="_blank" style="color:#64748b;text-decoration:none;">Xem SP ↗</a>
									</div>
								</td>
								<td>
									<?php if ( $p ) : ?>
										<div style="margin-bottom:4px;">
											<span class="nxp-badge nxp-badge-ok">✓ Đã đồng bộ Pancake</span>
										</div>
										<div style="font-size:12px;color:#334155;">
											Mã SKU Pancake: <strong style="font-family:monospace;color:#1b2a59;"><?php echo esc_html( $p['sku'] ); ?></strong>
											<?php if ( $p['code'] ) : ?>
												<span style="color:#64748b;">(Mã: <?php echo esc_html( $p['code'] ); ?>)</span>
											<?php endif; ?>
										</div>
										<div style="font-size:11.5px;color:#64748b;margin-top:2px;">
											<?php echo count( $p['variations'] ); ?> biến thể • Tồn kho: <strong><?php echo (int) $p['total_stock']; ?></strong> • Giá: <strong><?php echo number_format( (float) $p['min_price'], 0, ',', '.' ); ?>₫</strong>
										</div>
									<?php else : ?>
										<span class="nxp-badge nxp-badge-err">Chưa khớp SKU</span>
										<div style="margin-top:6px;display:flex;gap:4px;">
											<input type="text" placeholder="Nhập SKU Pancake..." id="nxp-manual-sku-<?php echo (int) $r['id']; ?>" style="width:125px;font-size:11.5px;padding:3px 6px;border-radius:5px;border:1px solid #cbd5e1;">
											<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm nxp-btn-link-sku" data-id="<?php echo (int) $r['id']; ?>">Gán</button>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! $p ) : ?>
										<span style="color:#94a3b8;font-size:12px;">—</span>
									<?php elseif ( empty( $p_imgs ) ) : ?>
										<span class="nxp-badge nxp-badge-warn">Pancake chưa tải ảnh</span>
									<?php else : ?>
										<div style="display:flex;flex-direction:column;gap:6px;">
											<?php if ( ! empty( $p_classes ) ) : ?>
												<div class="nxp-thumb-grid">
													<?php foreach ( $p_classes as $c_info ) : ?>
														<span class="nxp-class-chip" title="SKU: <?php echo esc_attr( implode( ', ', array_slice( $c_info['skus'], 0, 5 ) ) ); ?>">
															<img src="<?php echo esc_url( $c_info['image'] ); ?>" alt="" loading="lazy">
															<span><?php echo esc_html( $c_info['label'] ); ?></span>
														</span>
													<?php endforeach; ?>
												</div>
											<?php else : ?>
												<div class="nxp-thumb-grid">
													<?php foreach ( array_slice( $p_imgs, 0, 4 ) as $u ) : ?>
														<img src="<?php echo esc_url( $u ); ?>" class="nxp-mini-img" alt="" loading="lazy">
													<?php endforeach; ?>
													<span style="font-size:11.5px;color:#475569;font-weight:600;"><?php echo count( $p_imgs ); ?> ảnh</span>
												</div>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<div id="nxp-var-status-<?php echo (int) $r['id']; ?>">
										<?php if ( $r['thumb_id'] > 0 ) : ?>
											<div style="font-size:12px;color:#15803d;font-weight:600;">✓ Có ảnh đại diện (+<?php echo (int) $r['gallery_count']; ?> ảnh album)</div>
										<?php else : ?>
											<div style="font-size:12px;color:#b91c1c;font-weight:600;">✗ Chưa có ảnh đại diện</div>
										<?php endif; ?>

										<?php if ( $r['var_total'] > 0 ) : ?>
											<div style="font-size:12px;margin-top:3px;color:<?php echo $r['var_with_img'] >= $r['var_total'] ? '#15803d' : '#b45309'; ?>;">
												Ảnh phân loại biến thể: <strong><?php echo (int) $r['var_with_img']; ?> / <?php echo (int) $r['var_total']; ?></strong> biến thể
											</div>
										<?php endif; ?>
									</div>
								</td>
								<td style="text-align:right;">
									<?php if ( $p ) : ?>
										<div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
											<button type="button" class="nxp-btn nxp-btn-primary nxp-btn-sm nxp-btn-sync-one" data-id="<?php echo (int) $r['id']; ?>">
												📷 Quét Ảnh Về Web
											</button>
											<button type="button" class="nxp-btn nxp-btn-navy nxp-btn-sm nxp-btn-open-class" data-id="<?php echo (int) $r['id']; ?>">
												🎨 Set Ảnh Phân Loại
											</button>
										</div>
									<?php else : ?>
										<span style="font-size:12px;color:#94a3b8;">Cần gán SKU</span>
									<?php endif; ?>
								</td>
							</tr>
							<!-- EXPANDABLE CLASSIFICATION IMAGE MAPPER DRAWER -->
							<tr id="nxp-drawer-<?php echo (int) $r['id']; ?>" style="display:none;">
								<td colspan="7" class="nxp-drawer">
									<div id="nxp-drawer-body-<?php echo (int) $r['id']; ?>">Đang tải thông tin phân loại từ Pancake...</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- PAGINATION -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="nxp-pagination" style="margin-top:16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
					<?php for ( $p_i = 1; $p_i <= $total_pages; $p_i++ ) :
						$url = add_query_arg(
							array(
								'page'          => self::MENU_SLUG,
								'tab'           => 'images',
								'q'             => $search_q,
								'filter_status' => $filter_st,
								'paged'         => $p_i,
							),
							admin_url( 'admin.php' )
						);
						?>
						<a href="<?php echo esc_url( $url ); ?>" data-target-tab="images" class="nxp-btn nxp-btn-sm nxp-spa-page-link <?php echo $p_i === $paged ? 'nxp-btn-navy' : 'nxp-btn-outline'; ?>">
							<?php echo (int) $p_i; ?>
						</a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
			</div><!-- /#nxp-tab2-results -->

			<script>
			(function(){
				const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				const nonce   = <?php echo wp_json_encode( $ajax_nonce ); ?>;

				const $ = (sel, root = document) => root.querySelector(sel);
				const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

				const getOptions = () => ({
					set_thumb: $('#nxpOptThumb')?.checked ? '1' : '0',
					set_gallery: $('#nxpOptGallery')?.checked ? '1' : '0',
					set_var_img: $('#nxpOptVarImg')?.checked ? '1' : '0',
					set_sku: $('#nxpOptSku')?.checked ? '1' : '0',
					sync_stock: $('#nxpOptStock')?.checked ? '1' : '0',
					overwrite_images: $('#nxpOptOverwrite')?.checked ? '1' : '0',
				});

				const logBox = $('#nxpProgressLog');
				const progBox = $('#nxpProgressBox');
				const progBar = $('#nxpProgressBar');
				const progCount = $('#nxpProgressCount');
				const progTitle = $('#nxpProgressTitle');

				function appendLog(msg, isErr = false) {
					if (!logBox) return;
					const line = document.createElement('div');
					line.style.color = isErr ? '#fca5a5' : '#a7f3d0';
					line.style.marginBottom = '3px';
					line.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
					logBox.appendChild(line);
					logBox.scrollTop = logBox.scrollHeight;
				}

				let isRunningQueue = false;

				async function postAjax(action, data = {}, retries = 1) {
					const body = new URLSearchParams({ action, nonce, ...data });
					for (let attempt = 0; attempt <= retries; attempt++) {
						try {
							const res = await fetch(ajaxUrl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
								body: body.toString()
							});
							const text = await res.text();
							return JSON.parse(text);
						} catch (err) {
							if (attempt === retries) throw err;
							await new Promise(r => setTimeout(r, 1200));
						}
					}
				}

				function updateRowUI(pid, d) {
					if (d.thumb_url) {
						const thumbWrap = $('#nxp-web-thumb-' + pid);
						if (thumbWrap) {
							thumbWrap.innerHTML = '<img src="' + d.thumb_url + '" class="nxp-mini-img" style="width:54px;height:54px;" alt="">';
						}
					}
					if (d.pancake_sku) {
						const skuEl = $('#nxp-wc-sku-' + pid);
						if (skuEl) skuEl.textContent = d.pancake_sku;
					}
					const stWrap = $('#nxp-var-status-' + pid);
					if (stWrap) {
						stWrap.innerHTML = '<div style="font-size:12px;color:#15803d;font-weight:700;">✓ Đã đồng bộ (' + (d.downloaded_urls || 0) + ' ảnh Pancake, ' + (d.variations_img_set || 0) + ' biến thể được gán ảnh phân loại)</div>';
					}
				}

				// Batch Queue Runner
				async function runBatchQueue(rawIds, label) {
					if (isRunningQueue) return;
					const ids = Array.from(new Set((rawIds || []).map(Number).filter(Boolean)));
					if (!ids.length) {
						alert('Không có sản phẩm nào trong danh sách cần quét.');
						return;
					}
					isRunningQueue = true;
					progBox.style.display = 'block';
					logBox.innerHTML = '';
					progTitle.textContent = label;
					const opts = getOptions();
					let done = 0;

					for (const pid of ids) {
						progCount.textContent = (done + 1) + ' / ' + ids.length;
						try {
							const json = await postAjax('nexxi_pancake_sync_single', { product_id: pid, ...opts }, 1);
							if (json && json.success && json.data) {
								const d = json.data;
								appendLog('✓ [#' + pid + ' - ' + (d.pancake_sku || '') + '] ' + d.name + ' → Tải ' + d.downloaded_urls + ' ảnh, gán ' + d.variations_img_set + ' biến thể phân loại.');
								updateRowUI(pid, d);
							} else {
								appendLog('✗ [#' + pid + '] ' + (json?.data?.message || 'Lỗi không xác định'), true);
							}
						} catch (err) {
							appendLog('✗ [#' + pid + '] Máy chủ phản hồi quá thời gian (Timeout), vui lòng bấm quét lại SP này.', true);
						}
						done++;
						progBar.style.width = Math.round((done / ids.length) * 100) + '%';
					}
					progTitle.textContent = '🎉 Hoàn tất! Đã xử lý ' + done + '/' + ids.length + ' sản phẩm.';
					isRunningQueue = false;
				}

				// Browser-side direct Pancake POS fetch by Website Product SKUs (0 D1 reads, 100% exact SKU match)
				const cfBaseUrl = <?php echo wp_json_encode( rtrim( $settings['cf_api_url'], '/' ) ); ?>;
				const pancakeShopId = <?php echo wp_json_encode( trim( (string) $settings['shop_id'] ) ?: self::DEFAULT_SHOP_ID ); ?>;
				const pancakeApiKey = <?php echo wp_json_encode( self::get_effective_api_key() ); ?>;
				const pancakeWarehouseId = <?php echo wp_json_encode( isset( $settings['warehouse_id'] ) && '' !== trim( (string) $settings['warehouse_id'] ) ? trim( (string) $settings['warehouse_id'] ) : self::DEFAULT_WAREHOUSE_ID ); ?>;
				const wcLookupSkus = <?php echo wp_json_encode( self::get_all_wc_product_lookup_skus( $wc_products ) ); ?>;

				function collectRecordImages(rec) {
					const urls = [];
					if (!rec || typeof rec !== 'object') return urls;
					['image_url', 'image', 'avatar', 'thumbnail'].forEach(k => {
						if (typeof rec[k] === 'string' && rec[k].trim().startsWith('http')) {
							const u = rec[k].trim();
							if (!urls.includes(u)) urls.push(u);
						}
					});
					['images', 'image_urls', 'photos'].forEach(ak => {
						if (Array.isArray(rec[ak])) {
							rec[ak].forEach(it => {
								const u = typeof it === 'string' ? it.trim() : (it && typeof it === 'object' ? String(it.url || it.image_url || it.path || '').trim() : '');
								if (u.startsWith('http') && !urls.includes(u)) urls.push(u);
							});
						}
					});
					return urls;
				}

				function ingestPosProductIntoMap(prod, map) {
					if (!prod || typeof prod !== 'object') return;
					const pid = String(prod.id || prod.pancake_product_id || '').trim();
					if (!pid) return;
					const vars = Array.isArray(prod.variations) ? prod.variations : [];
					let sku = String(prod.custom_id || prod.sku || '').trim();
					if (!sku && vars.length) {
						for (const v of vars) {
							const vs = String(v.display_id || v.sku || '').trim();
							if (vs.includes('-')) { sku = vs.split('-')[0]; break; }
						}
					}
					if (!sku) sku = String(prod.display_id || '').trim();
					const pImgs = collectRecordImages(prod);
					map[pid] = {
						id: pid,
						sku: sku,
						display_id: String(prod.display_id || sku).trim(),
						code: '',
						name: String(prod.name || '').trim(),
						categories: Array.isArray(prod.categories) ? prod.categories.map(c => typeof c === 'string' ? c : (c?.name || '')).filter(Boolean) : [],
						product_image: pImgs[0] || '',
						all_images: [...pImgs],
						class_images: {},
						var_images: {},
						variations: [],
						total_stock: 0,
						min_price: 0,
						max_price: 0
					};
					const p = map[pid];
					vars.forEach(vr => {
						if (vr.is_removed) return;
						const vsku = String(vr.display_id || vr.sku || vr.custom_id || '').trim();
						if (!p.sku && vsku.includes('-')) p.sku = vsku.split('-')[0];
						const vImgs = collectRecordImages(vr);
						const vimg = vImgs[0] || '';
						const price = Number(vr.retail_price ?? vr.price ?? 0);
						const whMap = {};
						if (Array.isArray(vr.variations_warehouses)) {
							vr.variations_warehouses.forEach(wh => {
								const wid = String(wh.warehouse_id || '').trim();
								if (!wid) return;
								const wq = Math.max(0, Number(wh.remain_quantity ?? wh.actual_remain_quantity ?? 0));
								if (wq > 0) whMap[wid] = (whMap[wid] || 0) + wq;
							});
						}
						let qty = 0;
						if (pancakeWarehouseId && pancakeWarehouseId !== 'all') {
							qty = Number(whMap[pancakeWarehouseId] || 0);
						} else if (Object.keys(whMap).length > 0) {
							Object.values(whMap).forEach(wq => { qty += Number(wq || 0); });
						} else {
							qty = Math.max(0, Number(vr.remain_quantity ?? vr.available_quantity ?? vr.total_quantity ?? 0));
						}
						const fields = Array.isArray(vr.fields) ? vr.fields : (Array.isArray(vr.attributes) ? vr.attributes : []);
						let colorVal = '', sizeVal = '';
						fields.forEach(a => {
							const an = String(a.name || a.key || '').toLowerCase();
							const av = String(a.value || a.val || '').trim();
							if (!av) return;
							if (an.includes('màu') || an.includes('mau') || an.includes('color') || an.includes('loại')) colorVal = av;
							else if (an.includes('size') || an.includes('cỡ') || an.includes('kích')) sizeVal = av;
						});
						if (!colorVal && vsku.includes('-')) {
							const pts = vsku.split('-');
							if (pts.length >= 3) { colorVal = pts[1]; if (!sizeVal) sizeVal = pts[2]; }
						}
						const cKey = colorVal.toUpperCase();
						vImgs.forEach(u => { if (!p.all_images.includes(u)) p.all_images.push(u); });
						if (vimg && vimg.startsWith('http')) {
							if (!p.product_image) p.product_image = vimg;
							if (vsku) p.var_images[vsku.toUpperCase()] = vimg;
							if (cKey) {
								if (!p.class_images[cKey]) p.class_images[cKey] = { key: cKey, raw: colorVal, label: colorVal, image: vimg, skus: [] };
								if (vsku && p.class_images[cKey].skus.length < 15 && !p.class_images[cKey].skus.includes(vsku)) p.class_images[cKey].skus.push(vsku);
							}
						} else if (cKey && p.class_images[cKey] && vsku) {
							if (p.class_images[cKey].skus.length < 15 && !p.class_images[cKey].skus.includes(vsku)) p.class_images[cKey].skus.push(vsku);
						}
						if (qty > 0) p.total_stock += qty;
						if (price > 0) {
							if (!p.min_price || price < p.min_price) p.min_price = price;
							if (price > p.max_price) p.max_price = price;
						}
						p.variations.push({ sku: vsku, color_raw: colorVal, color_key: cKey, size: sizeVal, image: vimg, price, stock: Math.max(0, qty), warehouses: whMap });
					});
					// Inherit class image for variations of the same color that didn't have images[0] set directly
					p.variations.forEach(v => {
						if (!v.image && v.color_key && p.class_images[v.color_key]?.image) {
							v.image = p.class_images[v.color_key].image;
							if (v.sku) p.var_images[v.sku.toUpperCase()] = v.image;
						}
					});
				}

				async function syncViaBrowserFallback(btn) {
					const map = {};
					// 1. Query Pancake POS strictly by Website Product SKUs via /api/pancake-by-skus (fast, 0 D1 reads, 100% exact SKU match)
					if (Array.isArray(wcLookupSkus) && wcLookupSkus.length > 0) {
						const chunkSize = 25;
						for (let i = 0; i < wcLookupSkus.length; i += chunkSize) {
							const chunk = wcLookupSkus.slice(i, i + chunkSize);
							if (btn) btn.textContent = '⏳ Đang truy vấn Pancake POS theo SKU (' + Math.min(i + chunkSize, wcLookupSkus.length) + '/' + wcLookupSkus.length + ')...';
							try {
								const url = cfBaseUrl + '/api/pancake-by-skus?shop_id=' + encodeURIComponent(pancakeShopId) + '&api_key=' + encodeURIComponent(pancakeApiKey) + '&skus=' + encodeURIComponent(chunk.join(','));
								const res = await fetch(url).then(r => r.json());
								const items = Array.isArray(res.products) ? res.products : (Array.isArray(res.data) ? res.data : []);
								items.forEach(prod => ingestPosProductIntoMap(prod, map));
							} catch (e) {}
						}
					}

					// 2. Also fetch recent pages from Pancake POS
					for (let page = 1; page <= 3; page++) {
						if (btn) btn.textContent = '⏳ Đang tải sản phẩm mới từ Pancake POS (Trang ' + page + ')...';
						let items = [];
						try {
							const proxyUrl = cfBaseUrl + '/api/pancake-live-products?shop_id=' + encodeURIComponent(pancakeShopId) + '&api_key=' + encodeURIComponent(pancakeApiKey) + '&page_number=' + page + '&page_size=100';
							const res = await fetch(proxyUrl).then(r => r.json());
							items = Array.isArray(res.data) ? res.data : (Array.isArray(res.products) ? res.products : []);
						} catch (e) {}
						if (!items.length) break;
						items.forEach(prod => ingestPosProductIntoMap(prod, map));
						if (items.length < 100) break;
					}

					if (Object.keys(map).length === 0) {
						return {
							success: false,
							data: {
								message: 'Không thể kết nối Pancake POS API. Vui lòng thử lại sau giây lát.'
							}
						};
					}

					if (btn) btn.textContent = '⏳ Đang lưu dữ liệu Pancake vào Website...';
					return postAjax('nexxi_pancake_push_browser_cache', { catalog_json: JSON.stringify(map) });
				}

				// 1. Refresh Pancake Cache button
				$('#nxpBtnRefreshPancake')?.addEventListener('click', async function() {
					const btn = this;
					btn.disabled = true;
					btn.textContent = '⏳ Đang đồng bộ từ Pancake POS...';
					try {
						let json = await postAjax('nexxi_pancake_refresh_cache', { trigger_live: '1' });
						if (!json.success || !json.data || Number(json.data.total_pancake) === 0) {
							json = await syncViaBrowserFallback(btn);
						}
						if (json.success && json.data) {
							alert(json.data.message);
							location.reload();
						} else {
							alert('Lỗi: ' + (json.data?.message || 'Không thể kết nối Pancake'));
							btn.disabled = false;
							btn.textContent = '🔄 Làm Mới Dữ Liệu Từ Pancake POS';
						}
					} catch (e) {
						alert('Lỗi kết nối mạng.');
						btn.disabled = false;
						btn.textContent = '🔄 Làm Mới Dữ Liệu Từ Pancake POS';
					}
				});

				// Auto-sync on first load if Pancake cache has 0 products
				if (<?php echo count( $catalog ) === 0 ? 'true' : 'false'; ?>) {
					const btn = $('#nxpBtnRefreshPancake');
					if (btn) {
						btn.disabled = true;
						syncViaBrowserFallback(btn).then(res => {
							if (res && res.success && res.data && Number(res.data.total_pancake) > 0) {
								location.reload();
							} else {
								btn.disabled = false;
								btn.textContent = '🔄 Làm Mới Dữ Liệu Từ Pancake POS';
							}
						}).catch(() => {
							btn.disabled = false;
							btn.textContent = '🔄 Làm Mới Dữ Liệu Từ Pancake POS';
						});
					}
				}

				// 2. Scan all missing images
				$('#nxpBtnScanMissing')?.addEventListener('click', function() {
					const ids = JSON.parse(this.dataset.queue || '[]');
					runBatchQueue(ids, 'Đang tự động quét & tải ảnh Pancake cho các SP thiếu ảnh...');
				});

				// 3. Scan & set classification images for all products with color classifications
				$('#nxpBtnScanClassifications')?.addEventListener('click', function() {
					const ids = JSON.parse(this.dataset.queue || '[]');
					$('#nxpOptVarImg').checked = true;
					runBatchQueue(ids, 'Đang lấy ảnh theo Phân loại (Màu sắc) trên Pancake & set cho biến thể Website...');
				});

				// 4. Scan selected checkboxes
				$('#nxpBtnScanSelected')?.addEventListener('click', function() {
					const ids = $$('.nxp-row-check:checked').map(cb => Number(cb.value));
					runBatchQueue(ids, 'Đang quét ảnh cho các sản phẩm đã chọn...');
				});

				// 5. Scan by pasted SKU list
				$('#nxpBtnScanBySkuList')?.addEventListener('click', function() {
					const raw = ($('#nxpBulkSkuInput')?.value || '').trim();
					if (!raw) {
						alert('Vui lòng nhập ít nhất 1 mã SKU (VD: 2MKFBSN01, 2TBIHP01, 2607).');
						return;
					}
					const tokens = raw.split(/[\s,;]+/).map(s => s.trim().toUpperCase()).filter(Boolean);
					const matchedIds = [];
					$$('tr[data-product-id]').forEach(tr => {
						const rowSku = (tr.dataset.sku || '').toUpperCase();
						if (tokens.some(t => rowSku.includes(t))) {
							matchedIds.push(Number(tr.dataset.productId));
						}
					});
					if (!matchedIds.length) {
						alert('Không tìm thấy sản phẩm nào khớp với mã SKU đã nhập trên bảng hiện tại. Hãy xóa bộ lọc tìm kiếm hoặc kiểm tra lại SKU.');
						return;
					}
					runBatchQueue(matchedIds, 'Đang quét ảnh & set phân loại cho ' + matchedIds.length + ' sản phẩm theo SKU...');
				});

				// Event delegation on #nxp-pane-images so buttons & checkboxes work across AJAX pagination without reload
				const paneImages = $('#nxp-pane-images');
				paneImages?.addEventListener('change', function(e) {
					if (e.target.id === 'nxpCheckAll') {
						$$('.nxp-row-check', paneImages).forEach(cb => { cb.checked = e.target.checked; });
					} else if (e.target.matches('.nxp-spa-filter-form select')) {
						e.target.closest('form')?.requestSubmit();
					}
				});

				paneImages?.addEventListener('click', async function(e) {
					// 6. Single row: Quét ảnh về Web
					const syncBtn = e.target.closest('.nxp-btn-sync-one');
					if (syncBtn) {
						const pid = Number(syncBtn.dataset.id);
						const orig = syncBtn.textContent;
						syncBtn.disabled = true;
						syncBtn.textContent = '⏳ Đang tải...';
						try {
							const json = await postAjax('nexxi_pancake_sync_single', { product_id: pid, ...getOptions() });
							if (json.success && json.data) {
								updateRowUI(pid, json.data);
								syncBtn.textContent = '✓ Đã xong';
							} else {
								alert(json.data?.message || 'Lỗi đồng bộ');
								syncBtn.textContent = orig;
								syncBtn.disabled = false;
							}
						} catch (err) {
							alert('Lỗi kết nối');
							syncBtn.textContent = orig;
							syncBtn.disabled = false;
						}
						return;
					}

					// 7. Manual link SKU button
					const linkBtn = e.target.closest('.nxp-btn-link-sku');
					if (linkBtn) {
						const pid = Number(linkBtn.dataset.id);
						const input = $('#nxp-manual-sku-' + pid);
						const sku = (input?.value || '').trim();
						if (!sku) {
							alert('Vui lòng nhập mã SKU Pancake.');
							return;
						}
						linkBtn.disabled = true;
						const json = await postAjax('nexxi_pancake_link_sku', { product_id: pid, pancake_sku: sku });
						if (json.success) {
							alert(json.data.message);
							const skuEl = $('#nxp-wc-sku-' + pid);
							if (skuEl && json.data.pancake_sku) skuEl.textContent = json.data.pancake_sku;
						} else {
							alert(json.data?.message || 'Không tìm thấy SKU');
							linkBtn.disabled = false;
						}
						return;
					}

					// 8. Interactive Classification Image Mapper Drawer ("🎨 Set Ảnh Phân Loại")
					const openClassBtn = e.target.closest('.nxp-btn-open-class');
					if (openClassBtn) {
						const pid = Number(openClassBtn.dataset.id);
						const drawer = $('#nxp-drawer-' + pid);
						const body = $('#nxp-drawer-body-' + pid);
						if (!drawer || !body) return;

						if (drawer.style.display !== 'none') {
							drawer.style.display = 'none';
							return;
						}
						drawer.style.display = 'table-row';
						body.innerHTML = '⏳ Đang tải danh sách phân loại & ảnh từ Pancake...';

						const json = await postAjax('nexxi_pancake_get_classifications', { product_id: pid });
						if (!json.success || !json.data) {
							body.innerHTML = '<div style="color:#dc2626;font-weight:600;">' + (json.data?.message || 'Không lấy được phân loại') + '</div>';
							return;
						}

						const d = json.data;
						const pClasses = d.pancake_classes || [];
						const pImages  = d.pancake_images || [];
						const wcClasses = d.wc_classes || [];

						if (!pImages.length) {
							body.innerHTML = '<div style="color:#b45309;font-weight:600;">Sản phẩm này trên Pancake hiện chưa có ảnh nào. Hãy tải ảnh lên Pancake POS trước rồi bấm Làm mới dữ liệu.</div>';
							return;
						}

						let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
						html += '<div><strong style="font-size:14px;color:#1b2a59;">🎨 Ghép Ảnh Theo Phân Loại Pancake → Phân Loại Website: [' + d.pancake_sku + '] ' + d.product_name + '</strong>';
						html += '<div style="font-size:12px;color:#64748b;">Chọn ảnh phân loại trên Pancake cho từng nhóm phân loại/màu sắc trên Website rồi bấm Lưu.</div></div>';
						html += '<button type="button" class="nxp-btn nxp-btn-primary nxp-btn-save-class-map" data-id="' + pid + '">💾 Lưu &amp; Set Ảnh Phân Loại Ngay</button>';
						html += '</div>';

						const choices = [];
						const seenUrls = new Set();
						pClasses.forEach(pc => {
							if (pc.image) {
								choices.push({ url: pc.image, label: 'Pancake: ' + pc.label });
								seenUrls.add(pc.image);
							}
						});
						pImages.forEach((u, idx) => {
							if (!seenUrls.has(u)) {
								choices.push({ url: u, label: 'Ảnh Pancake #' + (idx + 1) });
								seenUrls.add(u);
							}
						});

						wcClasses.forEach(wc => {
							const defaultUrl = wc.matched_p_img || (choices[0] ? choices[0].url : '');
							html += '<div class="nxp-map-row" data-wc-class="' + wc.key + '" data-selected-url="' + defaultUrl + '">';
							html += '<div>';
							html += '<div style="font-weight:700;color:#1b2a59;font-size:13px;">Phân loại Web: ' + wc.label + '</div>';
							html += '<div style="font-size:11.5px;color:#64748b;">Áp dụng cho ' + wc.variation_ids.length + ' biến thể size</div>';
							if (wc.current_img) {
								html += '<div style="margin-top:4px;font-size:11px;color:#15803d;display:flex;align-items:center;gap:6px;"><img src="' + wc.current_img + '" style="width:24px;height:24px;border-radius:4px;object-fit:cover;"> Đang có ảnh trên Web</div>';
							}
							html += '</div>';
							html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
							choices.forEach(ch => {
								const active = ch.url === defaultUrl ? ' active' : '';
								html += '<div class="nxp-choice-pill' + active + '" data-url="' + ch.url + '">';
								html += '<img src="' + ch.url + '" alt="">';
								html += '<span style="font-size:12px;font-weight:600;color:#1e293b;">' + ch.label + '</span>';
								html += '</div>';
							});
							html += '</div>';
							html += '</div>';
						});

						body.innerHTML = html;

						$$('.nxp-map-row', body).forEach(row => {
							$$('.nxp-choice-pill', row).forEach(pill => {
								pill.addEventListener('click', () => {
									$$('.nxp-choice-pill', row).forEach(p => p.classList.remove('active'));
									pill.classList.add('active');
									row.dataset.selectedUrl = pill.dataset.url;
								});
							});
						});

						$('.nxp-btn-save-class-map', body)?.addEventListener('click', async function() {
							const saveBtn = this;
							saveBtn.disabled = true;
							saveBtn.textContent = '⏳ Đang tải & gán ảnh phân loại...';

							const payload = { product_id: pid };
							$$('.nxp-map-row', body).forEach(row => {
								const k = row.dataset.wcClass;
								const u = row.dataset.selectedUrl;
								if (k && u) {
									payload['class_map[' + k + ']'] = u;
								}
							});

							const res = await postAjax('nexxi_pancake_apply_classifications', payload);
							if (res.success && res.data) {
								updateRowUI(pid, res.data);
								saveBtn.textContent = '✓ Đã gán ảnh cho ' + res.data.variations_img_set + ' biến thể!';
								setTimeout(() => { drawer.style.display = 'none'; }, 1000);
							} else {
								alert(res.data?.message || 'Lỗi lưu ảnh phân loại');
								saveBtn.disabled = false;
								saveBtn.textContent = '💾 Lưu & Set Ảnh Phân Loại Ngay';
							}
						});
					}
				});
			})();
			</script>
			</div><!-- /#nxp-pane-images -->
		</div><!-- /.nexxi-pancake-wrap -->
		<?php
	}

	/**
	 * Render Tab 1: Quản Lý Đồng Bộ Sản Phẩm Pancake Theo Mã SKU (`tab=products`).
	 *
	 * Features:
	 * - Manage all products available on Pancake POS by SKU.
	 * - Select 1 or multiple products to sync/import from Pancake POS to the Website's WooCommerce catalog.
	 * - Select 1 or multiple products to delete from the Website & disconnect from Pancake.
	 * - Rich multi-criteria product filter bar (SKU/name search, Website status, Pancake stock, image/classification status, category, sorting).
	 */
	public function render_tab_products( array $settings, array $catalog, array $maps, array $wc_products, $ajax_nonce ) {
		// Build reverse lookup from Pancake ID / Pancake SKU to fast Website product row
		$wc_by_pancake_id  = array();
		$wc_by_pancake_sku = array();

		foreach ( $wc_products as $wrow ) {
			if ( ! is_array( $wrow ) || empty( $wrow['id'] ) ) {
				continue;
			}
			$w_sku = strtoupper( trim( (string) $wrow['sku'] ) );
			if ( '' !== $w_sku ) {
				$wc_by_pancake_sku[ $w_sku ] = $wrow;
				if ( false !== strpos( $w_sku, '-' ) ) {
					$pref = explode( '-', $w_sku )[0];
					if ( '' !== $pref && ! isset( $wc_by_pancake_sku[ $pref ] ) ) {
						$wc_by_pancake_sku[ $pref ] = $wrow;
					}
				}
			}
			$linked_pid = trim( (string) $wrow['pancake_id'] );
			if ( '' !== $linked_pid ) {
				$wc_by_pancake_id[ $linked_pid ] = $wrow;
			}
			$linked_sku = strtoupper( trim( (string) $wrow['pancake_sku'] ) );
			if ( '' !== $linked_sku && ! isset( $wc_by_pancake_sku[ $linked_sku ] ) ) {
				$wc_by_pancake_sku[ $linked_sku ] = $wrow;
			}
			$match_info = self::match_fast_wc_row( $wrow, $maps );
			if ( ! empty( $match_info['pancake'] ) ) {
				$mp = $match_info['pancake'];
				if ( ! empty( $mp['id'] ) && ! isset( $wc_by_pancake_id[ $mp['id'] ] ) ) {
					$wc_by_pancake_id[ $mp['id'] ] = $wrow;
				}
				if ( ! empty( $mp['sku'] ) ) {
					$msku = strtoupper( trim( (string) $mp['sku'] ) );
					if ( '' !== $msku && ! isset( $wc_by_pancake_sku[ $msku ] ) ) {
						$wc_by_pancake_sku[ $msku ] = $wrow;
					}
				}
			}
		}

		// Filter parameters for Tab 1
		$search_q     = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
		$web_status   = isset( $_GET['web_status'] ) ? sanitize_key( wp_unslash( $_GET['web_status'] ) ) : 'all';
		$stock_filter = isset( $_GET['stock_filter'] ) ? sanitize_key( wp_unslash( $_GET['stock_filter'] ) ) : 'all';
		$img_filter   = isset( $_GET['img_filter'] ) ? sanitize_key( wp_unslash( $_GET['img_filter'] ) ) : 'all';
		$cat_filter   = isset( $_GET['cat_filter'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['cat_filter'] ) ) ) : '';
		$sort_by      = isset( $_GET['sort_by'] ) ? sanitize_key( wp_unslash( $_GET['sort_by'] ) ) : 'default';
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page     = 40;

		// Deduplicate catalog by SKU (keep the entry with more images / stock if duplicate records exist in Pancake POS)
		$dedup_catalog = array();
		foreach ( $catalog as $pid => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$p_sku   = strtoupper( trim( (string) ( $p['sku'] ?? '' ) ) );
			$dedup_k = '' !== $p_sku ? 'SKU_' . $p_sku : 'ID_' . $pid;
			if ( ! isset( $dedup_catalog[ $dedup_k ] ) ) {
				$dedup_catalog[ $dedup_k ] = $p;
			} else {
				$prev      = $dedup_catalog[ $dedup_k ];
				$prev_imgs = ! empty( $prev['all_images'] ) && is_array( $prev['all_images'] ) ? count( $prev['all_images'] ) : 0;
				$cur_imgs  = ! empty( $p['all_images'] ) && is_array( $p['all_images'] ) ? count( $p['all_images'] ) : 0;
				if ( $cur_imgs > $prev_imgs || ( $cur_imgs === $prev_imgs && (int) ( $p['total_stock'] ?? 0 ) > (int) ( $prev['total_stock'] ?? 0 ) ) ) {
					$dedup_catalog[ $dedup_k ] = $p;
				}
			}
		}

		$p_stats = array(
			'total_pancake' => count( $dedup_catalog ),
			'on_web'        => 0,
			'not_on_web'    => 0,
			'in_stock'      => 0,
			'disconnected'  => 0,
		);

		$all_categories   = array();
		$filtered_items   = array();
		$all_on_web_items = array();
		$seen_on_web_skus = array();

		$warehouses_list  = self::get_pancake_warehouses();
		$cur_wh_id        = isset( $settings['warehouse_id'] ) && '' !== trim( (string) $settings['warehouse_id'] ) ? trim( (string) $settings['warehouse_id'] ) : self::DEFAULT_WAREHOUSE_ID;
		$cur_wh_entry     = isset( $warehouses_list[ $cur_wh_id ] ) ? $warehouses_list[ $cur_wh_id ] : null;
		$cur_wh_label     = is_array( $cur_wh_entry ) && ! empty( $cur_wh_entry['name'] ) ? $cur_wh_entry['name'] : ( is_string( $cur_wh_entry ) && '' !== $cur_wh_entry ? $cur_wh_entry : ( 'all' === $cur_wh_id ? 'Tất cả kho' : 'Kho mặc định' ) );

		foreach ( $dedup_catalog as $p ) {
			$pid      = (string) ( $p['id'] ?? '' );
			$p_sku    = strtoupper( trim( (string) ( $p['sku'] ?? '' ) ) );
			$is_disc  = self::is_pancake_product_disconnected( $p );
			$wc_match = null;

			if ( '' !== $pid && isset( $wc_by_pancake_id[ $pid ] ) ) {
				$wc_match = $wc_by_pancake_id[ $pid ];
			} elseif ( '' !== $p_sku && isset( $wc_by_pancake_sku[ $p_sku ] ) ) {
				$wc_match = $wc_by_pancake_sku[ $p_sku ];
			}

			// Collect distinct categories for filter dropdown
			if ( ! empty( $p['categories'] ) && is_array( $p['categories'] ) ) {
				foreach ( $p['categories'] as $c_name ) {
					$c_name = trim( (string) $c_name );
					if ( '' !== $c_name ) {
						$all_categories[ $c_name ] = $c_name;
					}
				}
			}

			$stock_qty = (int) ( $p['total_stock'] ?? 0 );
			if ( $stock_qty > 0 ) {
				$p_stats['in_stock']++;
			}

			if ( is_array( $wc_match ) && ! empty( $wc_match['id'] ) ) {
				$p_stats['on_web']++;
				$status_code = 'on_web';
				$r_dom       = sanitize_html_class( $p_sku ? $p_sku : $pid );
				$dedup_key   = $p_sku ? $p_sku : $pid;
				if ( '' !== $dedup_key && ! isset( $seen_on_web_skus[ $dedup_key ] ) ) {
					$seen_on_web_skus[ $dedup_key ] = true;
					$all_on_web_items[]             = array(
						'row_dom'     => $r_dom,
						'wc_id'       => (int) $wc_match['id'],
						'pancake_id'  => $pid,
						'pancake_sku' => $p_sku,
					);
				}
			} elseif ( $is_disc ) {
				$p_stats['disconnected']++;
				$status_code = 'disconnected';
			} else {
				$p_stats['not_on_web']++;
				$status_code = 'not_on_web';
			}

			// 1. Filter by Website sync status
			if ( 'on_web' === $web_status && 'on_web' !== $status_code ) {
				continue;
			}
			if ( 'not_on_web' === $web_status && 'not_on_web' !== $status_code ) {
				continue;
			}
			if ( 'disconnected' === $web_status && 'disconnected' !== $status_code ) {
				continue;
			}

			// 2. Filter by Pancake stock
			if ( 'in_stock' === $stock_filter && $stock_qty <= 0 ) {
				continue;
			}
			if ( 'out_of_stock' === $stock_filter && $stock_qty > 0 ) {
				continue;
			}

			// 3. Filter by Pancake image / classification status
			$img_count   = ! empty( $p['all_images'] ) && is_array( $p['all_images'] ) ? count( $p['all_images'] ) : 0;
			$class_count = ! empty( $p['class_images'] ) && is_array( $p['class_images'] ) ? count( $p['class_images'] ) : 0;
			if ( 'has_img' === $img_filter && $img_count <= 0 ) {
				continue;
			}
			if ( 'has_class' === $img_filter && $class_count <= 0 ) {
				continue;
			}
			if ( 'no_img' === $img_filter && $img_count > 0 ) {
				continue;
			}

			// 4. Filter by Pancake category
			if ( '' !== $cat_filter ) {
				$p_cats = ! empty( $p['categories'] ) && is_array( $p['categories'] ) ? $p['categories'] : array();
				if ( ! in_array( $cat_filter, $p_cats, true ) ) {
					continue;
				}
			}

			// 5. Filter by search query (SKU, Variation SKU, Leading Code, or Product Name)
			if ( '' !== $search_q ) {
				$q_low    = mb_strtolower( $search_q );
				$var_skus = array();
				if ( ! empty( $p['variations'] ) && is_array( $p['variations'] ) ) {
					foreach ( $p['variations'] as $pv ) {
						if ( ! empty( $pv['sku'] ) ) {
							$var_skus[] = $pv['sku'];
						}
					}
				}
				$haystack = mb_strtolower(
					implode(
						' ',
						array(
							$p['sku'] ?? '',
							$p['display_id'] ?? '',
							$p['code'] ?? '',
							$p['name'] ?? '',
							implode( ' ', $var_skus ),
							$wc_match ? $wc_match['name'] : '',
							$wc_match ? $wc_match['sku'] : '',
						)
					)
				);
				if ( false === mb_strpos( $haystack, $q_low ) ) {
					continue;
				}
			}

			$filtered_items[] = array(
				'pancake'      => $p,
				'status_code'  => $status_code,
				'is_disc'      => $is_disc,
				'wc_id'        => $wc_match ? (int) $wc_match['id'] : 0,
				'wc_name'      => $wc_match ? (string) $wc_match['name'] : '',
				'wc_sku'       => $wc_match ? (string) $wc_match['sku'] : '',
				'wc_status'    => $wc_match ? (string) $wc_match['status'] : '',
				'wc_var_count' => $wc_match ? (int) $wc_match['var_total'] : 0,
				'wc_thumb_id'  => $wc_match ? (int) $wc_match['thumb_id'] : 0,
				'wc_thumb_url' => '',
				'wc_edit_url'  => '',
				'wc_view_url'  => '',
			);
		}

		// Sorting
		if ( 'stock_desc' === $sort_by ) {
			usort(
				$filtered_items,
				function( $a, $b ) {
					return (int) ( $b['pancake']['total_stock'] ?? 0 ) <=> (int) ( $a['pancake']['total_stock'] ?? 0 );
				}
			);
		} elseif ( 'price_desc' === $sort_by ) {
			usort(
				$filtered_items,
				function( $a, $b ) {
					return (float) ( $b['pancake']['min_price'] ?? 0 ) <=> (float) ( $a['pancake']['min_price'] ?? 0 );
				}
			);
		} elseif ( 'price_asc' === $sort_by ) {
			usort(
				$filtered_items,
				function( $a, $b ) {
					return (float) ( $a['pancake']['min_price'] ?? 0 ) <=> (float) ( $b['pancake']['min_price'] ?? 0 );
				}
			);
		} elseif ( 'sku_asc' === $sort_by ) {
			usort(
				$filtered_items,
				function( $a, $b ) {
					return strcmp( (string) ( $a['pancake']['sku'] ?? '' ), (string) ( $b['pancake']['sku'] ?? '' ) );
				}
			);
		}

		$total_filtered = count( $filtered_items );
		$total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
		$paged          = min( $paged, $total_pages );
		$paged_items    = array_slice( $filtered_items, ( $paged - 1 ) * $per_page, $per_page );
		foreach ( $paged_items as &$pi ) {
			if ( $pi['wc_id'] > 0 ) {
				$pi['wc_edit_url']  = admin_url( 'post.php?post=' . (int) $pi['wc_id'] . '&action=edit' );
				$pi['wc_view_url']  = get_permalink( (int) $pi['wc_id'] );
				$pi['wc_thumb_url'] = $pi['wc_thumb_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $pi['wc_thumb_id'], 'thumbnail' ) : '';
			}
		}
		unset( $pi );
		ksort( $all_categories );

		// Also include any Website product that has a SKU/Pancake SKU not yet in $seen_on_web_skus
		foreach ( $wc_products as $wrow ) {
			if ( ! is_array( $wrow ) || empty( $wrow['id'] ) ) {
				continue;
			}
			$cand_sku = strtoupper( trim( (string) ( $wrow['pancake_sku'] ?: $wrow['sku'] ) ) );
			if ( '' !== $cand_sku && false !== strpos( $cand_sku, '-' ) ) {
				$cand_sku = explode( '-', $cand_sku )[0];
			}
			$cand_pid = trim( (string) ( $wrow['pancake_id'] ?? '' ) );
			$d_key    = '' !== $cand_sku ? $cand_sku : $cand_pid;
			if ( '' !== $d_key && ! isset( $seen_on_web_skus[ $d_key ] ) ) {
				$seen_on_web_skus[ $d_key ] = true;
				$all_on_web_items[]         = array(
					'row_dom'     => sanitize_html_class( $d_key ),
					'wc_id'       => (int) $wrow['id'],
					'pancake_id'  => $cand_pid,
					'pancake_sku' => $cand_sku,
				);
			}
		}
		?>

		<!-- TAB 1 STATS ROW -->
		<div class="nxp-stats">
			<div class="nxp-stat-card">
				<div class="num"><?php echo (int) $p_stats['total_pancake']; ?></div>
				<div class="lbl">Tổng sản phẩm có trên Pancake POS</div>
			</div>
			<div class="nxp-stat-card ok">
				<div class="num"><?php echo (int) $p_stats['on_web']; ?></div>
				<div class="lbl">Đã đồng bộ có trên Website</div>
			</div>
			<div class="nxp-stat-card accent">
				<div class="num"><?php echo (int) $p_stats['not_on_web']; ?></div>
				<div class="lbl">Chưa có trên Website (Chỉ có trên Pancake)</div>
			</div>
			<div class="nxp-stat-card ok">
				<div class="num"><?php echo (int) $p_stats['in_stock']; ?></div>
				<div class="lbl">Sản phẩm còn tồn kho (<?php echo esc_html( $cur_wh_label ); ?>)</div>
			</div>
			<div class="nxp-stat-card">
				<div class="num" style="color:#dc2626;"><?php echo (int) $p_stats['disconnected']; ?></div>
				<div class="lbl">Đã xóa khỏi Web &amp; Ngắt kết nối Pancake</div>
			</div>
		</div>

		<!-- TAB 1 ACTION PANEL -->
		<div class="nxp-card" style="margin-bottom:20px;">
			<h2>📦 Quản Lý Đồng Bộ Sản Phẩm Từ Pancake Về Website &amp; Xóa / Ngắt Kết Nối Theo Mã SKU</h2>
			<p style="margin:0 0 12px;color:#475569;font-size:13px;">
				Chọn <strong>1 hoặc nhiều sản phẩm</strong> bên dưới (hoặc nhập danh sách Mã SKU) để <strong>đồng bộ sản phẩm từ Pancake POS về trang Sản phẩm của Website</strong> (tạo mới hoặc cập nhật đầy đủ biến thể Màu/Size, SKU phân loại, giá bán, tồn kho chuẩn theo <strong><?php echo esc_html( $cur_wh_label ); ?></strong> cài đặt trên Pancake, danh mục, ảnh SP), hoặc <strong>xóa khỏi Website và ngắt kết nối với Pancake</strong>.
			</p>

			<!-- SYNC OPTIONS BAR -->
			<div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-bottom:14px;background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;font-size:13px;">
				<label style="background:#eff6ff;padding:4px 10px;border-radius:6px;border:1px solid #93c5fd;color:#1e3a8a;font-weight:700;display:inline-flex;align-items:center;gap:6px;">
					<span>🏭 Kho đồng bộ tồn POS &rarr; WOO:</span>
					<select id="nxpProdWarehouseSelect" style="padding:3px 8px;border-radius:5px;border:1px solid #60a5fa;font-size:12.5px;font-weight:700;color:#1e3a8a;background:#fff;">
						<?php foreach ( $warehouses_list as $wh_id => $wh_info ) :
							$wh_name = is_array( $wh_info ) ? ( $wh_info['name'] ?? $wh_id ) : (string) $wh_info;
							?>
							<option value="<?php echo esc_attr( $wh_id ); ?>" <?php selected( $cur_wh_id, $wh_id ); ?>>
								<?php echo esc_html( $wh_name ); ?>
							</option>
						<?php endforeach; ?>
						<option value="all" <?php selected( $cur_wh_id, 'all' ); ?>>Gộp tất cả các kho</option>
					</select>
				</label>
				<label style="background:#dcfce7;padding:4px 10px;border-radius:6px;border:1px solid #86efac;color:#14532d;font-weight:700;"><input type="checkbox" id="nxpProdOptSkipExisting" checked> ⏭️ Bỏ qua SP trùng mã SKU đã có trên Website</label>
				<label><input type="checkbox" id="nxpProdOptVars" checked> Đồng bộ <strong>Biến thể Màu/Size &amp; SKU phân loại</strong></label>
				<label><input type="checkbox" id="nxpProdOptPrice" checked> Đồng bộ <strong>Giá bán</strong></label>
				<label><input type="checkbox" id="nxpProdOptStock" checked> Đồng bộ <strong>Tồn kho Pancake</strong></label>
				<label><input type="checkbox" id="nxpProdOptCats" checked> Đồng bộ <strong>Danh mục SP</strong></label>
				<label><input type="checkbox" id="nxpProdOptImages" checked> Đồng bộ luôn <strong>Ảnh SP &amp; Ảnh phân loại</strong> (chỉ lấy ảnh chưa có)</label>
				<label style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;">
					<span>Trạng thái khi tạo mới:</span>
					<select id="nxpProdOptStatus" style="padding:4px 8px;border-radius:6px;border:1px solid #cbd5e1;font-size:12.5px;">
						<option value="publish">Hiển thị ngay (Publish)</option>
						<option value="draft">Bản nháp (Draft)</option>
					</select>
				</label>
			</div>

			<!-- BULK SELECTION BUTTONS -->
			<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
				<button type="button" class="nxp-btn nxp-btn-primary" id="nxpBtnImportSelected">
					⬇️ Đồng Bộ SP Đã Chọn Từ Pancake Về Website
				</button>
				<button type="button" class="nxp-btn nxp-btn-navy" id="nxpBtnUpdateAllOnWeb" style="background:linear-gradient(135deg,#0f766e,#0d9488);border-color:#0f766e;box-shadow:0 2px 6px rgba(13,148,136,0.25);">
					🔄 Cập Nhật Từ Pancake Cho Toàn Bộ SP Có Trên Web (<?php echo count( $all_on_web_items ); ?> SP)
				</button>
				<button type="button" class="nxp-btn nxp-btn-danger" id="nxpBtnDeleteDisconnectSelected">
					🗑️ Xóa Khỏi Website &amp; Ngắt Kết Nối Pancake (Các SP Đã Chọn)
				</button>
				<button type="button" class="nxp-btn nxp-btn-outline" id="nxpBtnDisconnectOnlySelected">
					🔌 Chỉ Ngắt Kết Nối Pancake (Giữ SP Trên Web)
				</button>
				<button type="button" class="nxp-btn nxp-btn-outline" id="nxpBtnCleanupDuplicates" style="border-color:#f59e0b;color:#b45309;background:#fffbeb;">
					🧹 Dọn Sản Phẩm Bị Trùng Trên Website
				</button>
				<button type="button" class="nxp-btn nxp-btn-navy" id="nxpBtnLoadAllPancakePages" style="margin-left:auto;">
					📥 Tải Toàn Bộ Sản Phẩm Từ Pancake POS (Tất Cả Trang)
				</button>
			</div>

			<!-- QUICK BULK SKU INPUT FOR SYNC OR DELETE/DISCONNECT -->
			<div style="margin-top:16px;padding-top:14px;border-top:1px dashed #cbd5e1;">
				<label style="display:block;font-weight:700;color:#1b2a59;font-size:13px;margin-bottom:6px;">
					⚡ Thao tác nhanh theo danh sách Mã SKU Pancake (Nhập 1 hoặc nhiều mã SKU cách nhau dấu phẩy / khoảng trắng, VD: <code>2VBKHN2B01, 2QVKATL01, 2VCVATTH01</code>):
				</label>
				<div style="display:flex;flex-wrap:wrap;gap:8px;">
					<input type="text" id="nxpProdBulkSkuInput" placeholder="VD: 2VBKHN2B01, 2QVKATL01, 2VCVATTH01, 2TCBHTBH01..." style="flex:1;min-width:280px;padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;">
					<button type="button" class="nxp-btn nxp-btn-primary" id="nxpBtnImportBySkuList">
						⬇️ Đồng Bộ Theo Mã SKU Về Website
					</button>
					<button type="button" class="nxp-btn nxp-btn-danger" id="nxpBtnDeleteBySkuList">
						🗑️ Xóa Khỏi Web &amp; Ngắt Kết Nối Theo Mã SKU
					</button>
				</div>
			</div>

			<!-- PROGRESS BAR & LIVE LOG -->
			<div class="nxp-progress-box" id="nxpProdProgressBox">
				<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:700;color:#1b2a59;">
					<span id="nxpProdProgressTitle">Đang xử lý đồng bộ sản phẩm...</span>
					<span id="nxpProdProgressCount">0 / 0</span>
				</div>
				<div class="nxp-progress-bar-wrap">
					<div class="nxp-progress-bar" id="nxpProdProgressBar"></div>
				</div>
				<div class="nxp-log" id="nxpProdProgressLog"></div>
			</div>
		</div>

		<!-- TAB 1 MULTI-CRITERIA PRODUCT FILTER BAR + TABLE + PAGINATION (AJAX SPA CONTAINER) -->
		<div id="nxp-tab1-results" style="transition:opacity 0.15s;">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nxp-filter-bar nxp-spa-filter-form" data-target-tab="products">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="products">
			<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;">
				<input type="search" name="q" value="<?php echo esc_attr( $search_q ); ?>" placeholder="🔍 Tìm theo Mã SKU (2VBKHN2B01), SKU phân loại, mã lô (2603.8) hoặc tên SP..." style="min-width:300px;flex:1;padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;">

				<select name="web_status" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;font-size:12.5px;">
					<option value="all" <?php selected( $web_status, 'all' ); ?>>🌐 Trạng thái Web: Tất cả (<?php echo (int) $p_stats['total_pancake']; ?>)</option>
					<option value="on_web" <?php selected( $web_status, 'on_web' ); ?>>✅ Đã có trên Website (<?php echo (int) $p_stats['on_web']; ?>)</option>
					<option value="not_on_web" <?php selected( $web_status, 'not_on_web' ); ?>>🆕 Chưa có trên Website (<?php echo (int) $p_stats['not_on_web']; ?>)</option>
					<option value="disconnected" <?php selected( $web_status, 'disconnected' ); ?>>🔌 Đã xóa &amp; Ngắt kết nối (<?php echo (int) $p_stats['disconnected']; ?>)</option>
				</select>

				<select name="stock_filter" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;font-size:12.5px;">
					<option value="all" <?php selected( $stock_filter, 'all' ); ?>>📦 Tồn kho Pancake: Tất cả</option>
					<option value="in_stock" <?php selected( $stock_filter, 'in_stock' ); ?>>📦 Còn hàng trên Pancake (&gt; 0)</option>
					<option value="out_of_stock" <?php selected( $stock_filter, 'out_of_stock' ); ?>>⚠️ Hết hàng trên Pancake (= 0)</option>
				</select>

				<select name="img_filter" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;font-size:12.5px;">
					<option value="all" <?php selected( $img_filter, 'all' ); ?>>🖼️ Ảnh Pancake: Tất cả</option>
					<option value="has_img" <?php selected( $img_filter, 'has_img' ); ?>>📷 Có ảnh trên Pancake</option>
					<option value="has_class" <?php selected( $img_filter, 'has_class' ); ?>>🎨 Có ảnh Phân loại (Màu sắc)</option>
					<option value="no_img" <?php selected( $img_filter, 'no_img' ); ?>>🚫 Chưa có ảnh trên Pancake</option>
				</select>

				<?php if ( ! empty( $all_categories ) ) : ?>
					<select name="cat_filter" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;font-size:12.5px;max-width:190px;">
						<option value="">📂 Danh mục: Tất cả</option>
						<?php foreach ( $all_categories as $cat_name ) : ?>
							<option value="<?php echo esc_attr( $cat_name ); ?>" <?php selected( $cat_filter, $cat_name ); ?>><?php echo esc_html( $cat_name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>

				<select name="sort_by" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;font-size:12.5px;">
					<option value="default" <?php selected( $sort_by, 'default' ); ?>>🔃 Sắp xếp: Mới nhất</option>
					<option value="stock_desc" <?php selected( $sort_by, 'stock_desc' ); ?>>Tồn kho: Cao &rarr; Thấp</option>
					<option value="price_desc" <?php selected( $sort_by, 'price_desc' ); ?>>Giá bán: Cao &rarr; Thấp</option>
					<option value="price_asc" <?php selected( $sort_by, 'price_asc' ); ?>>Giá bán: Thấp &rarr; Cao</option>
					<option value="sku_asc" <?php selected( $sort_by, 'sku_asc' ); ?>>Mã SKU: A &rarr; Z</option>
				</select>

				<button type="submit" class="nxp-btn nxp-btn-navy">Lọc Sản Phẩm</button>
				<?php if ( '' !== $search_q || 'all' !== $web_status || 'all' !== $stock_filter || 'all' !== $img_filter || '' !== $cat_filter || 'default' !== $sort_by ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=products' ) ); ?>" class="nxp-btn nxp-btn-outline nxp-spa-page-link" data-target-tab="products">Xóa lọc</a>
				<?php endif; ?>
			</div>
			<div style="font-size:13px;color:#64748b;">
				Hiển thị <strong><?php echo count( $paged_items ); ?></strong> / <strong><?php echo (int) $total_filtered; ?></strong> SP Pancake
			</div>
		</form>

		<!-- TAB 1 PANCAKE PRODUCTS MANAGEMENT TABLE -->
		<table class="nxp-table">
			<thead>
				<tr>
					<th style="width:32px;"><input type="checkbox" id="nxpProdCheckAll"></th>
					<th style="width:74px;">Ảnh Pancake</th>
					<th>Sản Phẩm Trên Pancake POS &amp; Mã SKU</th>
					<th>Phân Loại Màu / Size, Giá &amp; Kho Pancake</th>
					<th>Trạng Thái Trên Website</th>
					<th style="text-align:right;width:255px;">Thao Tác Đồng Bộ / Xóa</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $paged_items ) ) : ?>
					<tr>
						<td colspan="6" style="text-align:center;padding:38px;color:#64748b;">
							Không tìm thấy sản phẩm Pancake nào khớp với bộ lọc hiện tại. Bạn có thể bấm <strong>"📥 Tải Toàn Bộ Sản Phẩm Từ Pancake POS"</strong> hoặc nhập mã SKU vào ô tìm kiếm.
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $paged_items as $item ) :
						$p         = $item['pancake'];
						$pid       = (string) ( $p['id'] ?? '' );
						$p_sku     = (string) ( $p['sku'] ?? '' );
						$p_imgs    = ! empty( $p['all_images'] ) && is_array( $p['all_images'] ) ? $p['all_images'] : array();
						$p_classes = ! empty( $p['class_images'] ) && is_array( $p['class_images'] ) ? $p['class_images'] : array();
						$p_vars    = ! empty( $p['variations'] ) && is_array( $p['variations'] ) ? $p['variations'] : array();
						$wc_id     = (int) $item['wc_id'];
						$row_dom_id = sanitize_html_class( $p_sku ? $p_sku : $pid );

						// Collect unique sizes for display
						$sizes_list = array();
						foreach ( $p_vars as $pv ) {
							$sv = trim( (string) ( $pv['size'] ?? '' ) );
							if ( '' !== $sv && ! in_array( $sv, $sizes_list, true ) ) {
								$sizes_list[] = $sv;
							}
						}
						?>
						<tr id="nxp-prod-row-<?php echo esc_attr( $row_dom_id ); ?>"
							data-row-dom="<?php echo esc_attr( $row_dom_id ); ?>"
							data-pancake-id="<?php echo esc_attr( $pid ); ?>"
							data-pancake-sku="<?php echo esc_attr( strtoupper( $p_sku ) ); ?>"
							data-wc-id="<?php echo (int) $wc_id; ?>">
							<td>
								<input type="checkbox" class="nxp-prod-check"
									value="<?php echo esc_attr( $pid ); ?>"
									data-row-dom="<?php echo esc_attr( $row_dom_id ); ?>"
									data-pancake-id="<?php echo esc_attr( $pid ); ?>"
									data-pancake-sku="<?php echo esc_attr( strtoupper( $p_sku ) ); ?>"
									data-wc-id="<?php echo (int) $wc_id; ?>">
							</td>
							<td>
								<?php if ( ! empty( $p['product_image'] ) ) : ?>
									<div style="position:relative;width:54px;height:54px;">
										<img src="<?php echo esc_url( $p['product_image'] ); ?>" class="nxp-mini-img" style="width:54px;height:54px;" alt="" loading="lazy">
										<span style="position:absolute;bottom:-4px;right:-4px;background:#1b2a59;color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:99px;">
											<?php echo count( $p_imgs ); ?> ảnh
										</span>
									</div>
								<?php else : ?>
									<div style="width:54px;height:54px;border-radius:8px;background:#f1f5f9;color:#64748b;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;text-align:center;padding:2px;">Chưa có ảnh</div>
								<?php endif; ?>
							</td>
							<td>
								<div style="font-weight:700;color:#1b2a59;font-size:13.5px;margin-bottom:4px;">
									<?php echo esc_html( $p['name'] ?: ( 'Sản phẩm ' . $p_sku ) ); ?>
								</div>
								<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:12px;">
									<span style="background:#eef2ff;padding:2px 8px;border-radius:5px;font-family:monospace;font-weight:700;color:#1e3a8a;border:1px solid #c7d2fe;">
										SKU Pancake: <?php echo esc_html( $p_sku ?: '—' ); ?>
									</span>
									<?php if ( ! empty( $p['code'] ) ) : ?>
										<span style="background:#f8fafc;padding:2px 7px;border-radius:5px;color:#475569;border:1px solid #e2e8f0;">
											Mã lô: <strong><?php echo esc_html( $p['code'] ); ?></strong>
										</span>
									<?php endif; ?>
									<?php if ( ! empty( $p['categories'] ) && is_array( $p['categories'] ) ) : ?>
										<?php foreach ( array_slice( $p['categories'], 0, 2 ) as $cat_lbl ) : ?>
											<span style="background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600;">
												<?php echo esc_html( $cat_lbl ); ?>
											</span>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<div style="font-size:12.5px;color:#1e293b;margin-bottom:5px;">
									<strong><?php echo count( $p_vars ); ?></strong> biến thể •
									Tồn kho (<?php echo esc_html( $cur_wh_label ); ?>): <strong id="nxp-prod-stock-<?php echo esc_attr( $row_dom_id ); ?>" style="color:<?php echo (int) $p['total_stock'] > 0 ? '#15803d' : '#dc2626'; ?>;"><?php echo (int) $p['total_stock']; ?></strong> •
									Giá bán: <strong id="nxp-prod-price-<?php echo esc_attr( $row_dom_id ); ?>" style="color:#e85d3f;"><?php echo number_format( (float) ( $p['min_price'] ?? 0 ), 0, ',', '.' ); ?>₫</strong>
								</div>
								<?php if ( ! empty( $p_classes ) ) : ?>
									<div class="nxp-thumb-grid" style="margin-bottom:4px;">
										<?php foreach ( $p_classes as $c_info ) : ?>
											<span class="nxp-class-chip" title="SKU phân loại: <?php echo esc_attr( implode( ', ', array_slice( $c_info['skus'] ?? array(), 0, 6 ) ) ); ?>">
												<?php if ( ! empty( $c_info['image'] ) ) : ?>
													<img src="<?php echo esc_url( $c_info['image'] ); ?>" alt="" loading="lazy">
												<?php endif; ?>
												<span><?php echo esc_html( $c_info['label'] ); ?></span>
											</span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $sizes_list ) ) : ?>
									<div style="font-size:11.5px;color:#64748b;">
										Size: <strong><?php echo esc_html( implode( ', ', $sizes_list ) ); ?></strong>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<div id="nxp-prod-web-status-<?php echo esc_attr( $row_dom_id ); ?>">
									<?php if ( $wc_id > 0 ) : ?>
										<div style="display:flex;align-items:center;gap:8px;">
											<?php if ( $item['wc_thumb_url'] ) : ?>
												<img src="<?php echo esc_url( $item['wc_thumb_url'] ); ?>" style="width:36px;height:36px;border-radius:6px;object-fit:cover;border:1px solid #cbd5e1;" alt="">
											<?php endif; ?>
											<div>
												<span class="nxp-badge nxp-badge-ok">✓ Đã có trên Website (#<?php echo (int) $wc_id; ?>)</span>
												<div style="font-size:11.5px;color:#475569;margin-top:3px;">
													SKU Web: <strong><?php echo esc_html( $item['wc_sku'] ?: $p_sku ); ?></strong>
													(<?php echo (int) $item['wc_var_count']; ?> biến thể)
												</div>
												<div style="font-size:11.5px;margin-top:2px;display:flex;gap:8px;">
													<a href="<?php echo esc_url( $item['wc_edit_url'] ); ?>" style="color:#1d4ed8;text-decoration:none;font-weight:600;">Sửa SP ↗</a>
													<a href="<?php echo esc_url( $item['wc_view_url'] ); ?>" target="_blank" style="color:#64748b;text-decoration:none;">Xem Web ↗</a>
												</div>
											</div>
										</div>
									<?php elseif ( $item['is_disc'] ) : ?>
										<span class="nxp-badge nxp-badge-err">🔌 Đã xóa khỏi Web &amp; Ngắt kết nối</span>
										<div style="font-size:11.5px;color:#64748b;margin-top:3px;">Đã ngắt kết nối với Pancake. Bấm "Đồng bộ về Web" nếu muốn đưa lại lên Website.</div>
									<?php else : ?>
										<span class="nxp-badge nxp-badge-warn">🆕 Chưa có trên Website</span>
										<div style="font-size:11.5px;color:#64748b;margin-top:3px;">Sẵn sàng đồng bộ từ Pancake về trang Sản phẩm.</div>
									<?php endif; ?>
								</div>
							</td>
							<td style="text-align:right;">
								<div id="nxp-prod-actions-<?php echo esc_attr( $row_dom_id ); ?>" style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
									<?php if ( $wc_id > 0 ) : ?>
										<button type="button" class="nxp-btn nxp-btn-navy nxp-btn-sm nxp-btn-import-one"
											data-row-dom="<?php echo esc_attr( $row_dom_id ); ?>"
											data-pancake-id="<?php echo esc_attr( $pid ); ?>"
											data-pancake-sku="<?php echo esc_attr( strtoupper( $p_sku ) ); ?>">
											🔄 Cập Nhật Từ Pancake
										</button>
										<button type="button" class="nxp-btn nxp-btn-danger nxp-btn-sm nxp-btn-delete-one"
											data-row-dom="<?php echo esc_attr( $row_dom_id ); ?>"
											data-wc-id="<?php echo (int) $wc_id; ?>"
											data-pancake-id="<?php echo esc_attr( $pid ); ?>"
											data-pancake-sku="<?php echo esc_attr( strtoupper( $p_sku ) ); ?>">
											🗑️ Xóa &amp; Ngắt Kết Nối
										</button>
									<?php else : ?>
										<button type="button" class="nxp-btn nxp-btn-primary nxp-btn-sm nxp-btn-import-one"
											data-row-dom="<?php echo esc_attr( $row_dom_id ); ?>"
											data-pancake-id="<?php echo esc_attr( $pid ); ?>"
											data-pancake-sku="<?php echo esc_attr( strtoupper( $p_sku ) ); ?>">
											⬇️ Đồng Bộ Về Website
										</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<!-- TAB 1 PAGINATION -->
		<?php if ( $total_pages > 1 ) : ?>
			<div style="margin-top:16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
				<?php for ( $p_i = 1; $p_i <= $total_pages; $p_i++ ) :
					$url = add_query_arg(
						array(
							'page'         => self::MENU_SLUG,
							'tab'          => 'products',
							'q'            => $search_q,
							'web_status'   => $web_status,
							'stock_filter' => $stock_filter,
							'img_filter'   => $img_filter,
							'cat_filter'   => $cat_filter,
							'sort_by'      => $sort_by,
							'paged'        => $p_i,
						),
						admin_url( 'admin.php' )
					);
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="nxp-btn nxp-btn-sm nxp-spa-page-link <?php echo $p_i === $paged ? 'nxp-btn-navy' : 'nxp-btn-outline'; ?>" data-target-tab="products">
						<?php echo (int) $p_i; ?>
					</a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
		</div><!-- /#nxp-tab1-results -->

		<script>
		(function(){
			const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const nonce   = <?php echo wp_json_encode( $ajax_nonce ); ?>;
			const cfBaseUrl = <?php echo wp_json_encode( rtrim( $settings['cf_api_url'], '/' ) ); ?>;
			const pancakeShopId = <?php echo wp_json_encode( trim( (string) $settings['shop_id'] ) ?: self::DEFAULT_SHOP_ID ); ?>;
			const pancakeApiKey = <?php echo wp_json_encode( self::get_effective_api_key() ); ?>;
			let pancakeWarehouseId = <?php echo wp_json_encode( $cur_wh_id ); ?>;
			const wcLookupSkus  = <?php echo wp_json_encode( self::get_all_wc_product_lookup_skus( $wc_products ) ); ?>;
			const allOnWebItems = <?php echo wp_json_encode( array_values( $all_on_web_items ) ); ?>;

			const $ = (sel, root = document) => root.querySelector(sel);
			const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

			const logBox    = $('#nxpProdProgressLog');
			const progBox   = $('#nxpProdProgressBox');
			const progBar   = $('#nxpProdProgressBar');
			const progCount = $('#nxpProdProgressCount');
			const progTitle = $('#nxpProdProgressTitle');

			function appendLog(msg, isErr = false) {
				if (!logBox) return;
				const line = document.createElement('div');
				line.style.color = isErr ? '#fca5a5' : '#a7f3d0';
				line.style.marginBottom = '3px';
				line.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
				logBox.appendChild(line);
				logBox.scrollTop = logBox.scrollHeight;
			}

			let isRunningBatch = false;

			async function postAjax(action, data = {}, retries = 1) {
				const body = new URLSearchParams({ action, nonce, ...data });
				for (let attempt = 0; attempt <= retries; attempt++) {
					try {
						const res = await fetch(ajaxUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
							body: body.toString()
						});
						const text = await res.text();
						const json = JSON.parse(text);
						return json;
					} catch (err) {
						if (attempt === retries) throw err;
						await new Promise(r => setTimeout(r, 1200));
					}
				}
			}

			function deduplicateBatchItems(items) {
				const seen = new Set();
				const out = [];
				for (const it of (items || [])) {
					const key = String(it.pancake_sku || it.pancake_id || it.wc_id || '').trim().toUpperCase();
					if (!key || seen.has(key)) continue;
					seen.add(key);
					out.push(it);
				}
				return out;
			}

			function getImportOpts(forceUpdate = false) {
				return {
					skip_existing:   forceUpdate ? '0' : ($('#nxpProdOptSkipExisting')?.checked ? '1' : '0'),
					sync_variations: $('#nxpProdOptVars')?.checked ? '1' : '0',
					sync_price:      $('#nxpProdOptPrice')?.checked ? '1' : '0',
					sync_stock:      $('#nxpProdOptStock')?.checked ? '1' : '0',
					sync_categories: $('#nxpProdOptCats')?.checked ? '1' : '0',
					sync_images:     $('#nxpProdOptImages')?.checked ? '1' : '0',
					post_status:     $('#nxpProdOptStatus')?.value || 'publish',
				};
			}

			function updateRowAfterImport(rowDom, d) {
				const stEl = $('#nxp-prod-web-status-' + rowDom);
				if (stEl) {
					const thumbHtml = d.thumb_url ? '<img src="' + d.thumb_url + '" style="width:36px;height:36px;border-radius:6px;object-fit:cover;border:1px solid #cbd5e1;" alt="">' : '';
					stEl.innerHTML = '<div style="display:flex;align-items:center;gap:8px;">' + thumbHtml +
						'<div><span class="nxp-badge nxp-badge-ok">✓ Đã đồng bộ lên Website (#' + d.wc_id + ')</span>' +
						'<div style="font-size:11.5px;color:#475569;margin-top:3px;">SKU Web: <strong>' + (d.pancake_sku || '') + '</strong> (' + (d.variations_synced || 0) + ' biến thể, Tồn: <strong>' + Number(d.total_stock ?? 0) + '</strong>)</div>' +
						'<div style="font-size:11.5px;margin-top:2px;display:flex;gap:8px;">' +
						(d.edit_url ? '<a href="' + d.edit_url + '" style="color:#1d4ed8;text-decoration:none;font-weight:600;">Sửa SP ↗</a>' : '') +
						(d.view_url ? '<a href="' + d.view_url + '" target="_blank" style="color:#64748b;text-decoration:none;">Xem Web ↗</a>' : '') +
						'</div></div></div>';
				}
				if (typeof d.total_stock !== 'undefined') {
					const stockEl = $('#nxp-prod-stock-' + rowDom);
					if (stockEl) {
						const stNum = Number(d.total_stock || 0);
						stockEl.textContent = String(stNum);
						stockEl.style.color = stNum > 0 ? '#15803d' : '#dc2626';
					}
				}
				if (typeof d.min_price !== 'undefined' && Number(d.min_price) > 0) {
					const priceEl = $('#nxp-prod-price-' + rowDom);
					if (priceEl) {
						priceEl.textContent = Number(d.min_price).toLocaleString('vi-VN') + '₫';
					}
				}
				const tr = $('#nxp-prod-row-' + rowDom);
				if (tr) {
					tr.dataset.wcId = d.wc_id;
					const cb = $('.nxp-prod-check', tr);
					if (cb) cb.dataset.wcId = d.wc_id;
				}
			}

			function updateRowAfterDelete(rowDom, d) {
				const stEl = $('#nxp-prod-web-status-' + rowDom);
				if (stEl) {
					if (d.mode === 'disconnect_only') {
						stEl.innerHTML = '<span class="nxp-badge nxp-badge-warn">🔌 Đã ngắt kết nối Pancake (Giữ SP trên Web)</span>';
					} else {
						stEl.innerHTML = '<span class="nxp-badge nxp-badge-err">🗑️ Đã xóa khỏi Website &amp; Ngắt kết nối Pancake</span>';
					}
				}
				const tr = $('#nxp-prod-row-' + rowDom);
				if (tr && d.mode !== 'disconnect_only') {
					tr.dataset.wcId = '0';
					const cb = $('.nxp-prod-check', tr);
					if (cb) cb.dataset.wcId = '0';
				}
			}

			// Batch runner for importing/syncing Pancake products to Website
			async function runBatchImport(rawItems, title, forceUpdate = false, useLiveSingleQuery = false) {
				if (isRunningBatch) return;
				const items = deduplicateBatchItems(rawItems);
				if (!items.length) {
					alert('Vui lòng chọn ít nhất 1 sản phẩm hoặc nhập mã SKU cần đồng bộ về Website.');
					return;
				}
				isRunningBatch = true;
				progBox.style.display = 'block';
				logBox.innerHTML = '';
				progTitle.textContent = title;
				progBar.style.width = '0%';
				const opts = getImportOpts(forceUpdate);
				let done = 0;

				for (const it of items) {
					progCount.textContent = (done + 1) + ' / ' + items.length;
					try {
						const res = await postAjax('nexxi_pancake_import_product', {
							pancake_id: it.pancake_id || '',
							pancake_sku: it.pancake_sku || '',
							live_query: useLiveSingleQuery ? '1' : '0',
							...opts
						}, 1);
						if (res && res.success && res.data) {
							const d = res.data;
							if (d.skipped_duplicate) {
								appendLog('⏭️ Bỏ qua [' + (d.pancake_sku || d.pancake_id) + '] ' + d.name + ' → Đã tồn tại mã SKU trên Website (SP #' + d.wc_id + '), không đồng bộ trùng.');
							} else {
								appendLog('✓ [' + (d.pancake_sku || d.pancake_id) + '] ' + d.name + ' → ' + (d.created_new ? 'Tạo mới SP #' : 'Cập nhật SP #') + d.wc_id + ' (' + d.variations_synced + ' biến thể, Tồn kho: ' + Number(d.total_stock ?? 0) + ', tải mới ' + d.downloaded_urls + ' ảnh)');
							}
							if (it.row_dom) updateRowAfterImport(it.row_dom, d);
						} else {
							appendLog('✗ [' + (it.pancake_sku || it.pancake_id) + '] ' + (res?.data?.message || 'Lỗi đồng bộ'), true);
						}
					} catch (e) {
						appendLog('✗ [' + (it.pancake_sku || it.pancake_id) + '] Máy chủ phản hồi quá thời gian (Timeout), vui lòng bấm cập nhật lại SP này.', true);
					}
					done++;
					progBar.style.width = Math.round((done / items.length) * 100) + '%';
				}
				progTitle.textContent = '🎉 Hoàn tất đồng bộ ' + done + '/' + items.length + ' sản phẩm về Website!';
				isRunningBatch = false;
			}

			// Batch runner for deleting from Website & disconnecting from Pancake
			async function runBatchDeleteDisconnect(rawItems, mode = 'delete_and_disconnect') {
				if (isRunningBatch) return;
				const items = deduplicateBatchItems(rawItems);
				if (!items.length) {
					alert('Vui lòng chọn ít nhất 1 sản phẩm hoặc nhập mã SKU cần xóa / ngắt kết nối.');
					return;
				}
				const confirmMsg = mode === 'disconnect_only'
					? 'Bạn có chắc muốn NGẮT KẾT NỐI Pancake cho ' + items.length + ' sản phẩm đã chọn (giữ nguyên sản phẩm trên Website)?'
					: '⚠️ XÁC NHẬN XÓA: Bạn có chắc chắn muốn XÓA VĨNH VIỄN ' + items.length + ' sản phẩm đã chọn (cùng toàn bộ biến thể) khỏi Website và NGẮT KẾT NỐI với Pancake không?';
				if (!confirm(confirmMsg)) return;

				isRunningBatch = true;
				progBox.style.display = 'block';
				logBox.innerHTML = '';
				progTitle.textContent = mode === 'disconnect_only' ? 'Đang ngắt kết nối Pancake...' : 'Đang xóa sản phẩm khỏi Website & ngắt kết nối Pancake...';
				progBar.style.width = '0%';
				let done = 0;

				for (const it of items) {
					progCount.textContent = (done + 1) + ' / ' + items.length;
					try {
						const res = await postAjax('nexxi_pancake_delete_disconnect', {
							wc_id: it.wc_id || 0,
							pancake_id: it.pancake_id || '',
							pancake_sku: it.pancake_sku || '',
							mode
						}, 1);
						if (res && res.success && res.data) {
							const d = res.data;
							if (mode === 'disconnect_only') {
								appendLog('🔌 [' + (d.pancake_sku || d.wc_id) + '] Đã ngắt kết nối với Pancake.');
							} else {
								appendLog('🗑️ [' + (d.pancake_sku || d.wc_id) + '] Đã xóa SP #' + d.wc_id + ' (' + d.deleted_variations + ' biến thể) khỏi Website & ngắt kết nối Pancake.');
							}
							if (it.row_dom) updateRowAfterDelete(it.row_dom, d);
						} else {
							appendLog('✗ [' + (it.pancake_sku || it.wc_id) + '] ' + (res?.data?.message || 'Lỗi xử lý'), true);
						}
					} catch (e) {
						appendLog('✗ [' + (it.pancake_sku || it.wc_id) + '] Lỗi kết nối mạng', true);
					}
					done++;
					progBar.style.width = Math.round((done / items.length) * 100) + '%';
				}
				progTitle.textContent = '🎉 Hoàn tất xử lý ' + done + '/' + items.length + ' sản phẩm!';
				isRunningBatch = false;
			}

			const paneProducts = $('#nxp-pane-products') || document;
			const selectedProductsMap = new Map();

			function syncSelectedProductCheckbox(cb) {
				if (!cb) return;
				const key = String(cb.dataset.pancakeSku || cb.dataset.pancakeId || '').trim().toUpperCase();
				if (!key) return;
				if (cb.checked) {
					selectedProductsMap.set(key, {
						row_dom: cb.dataset.rowDom,
						wc_id: Number(cb.dataset.wcId || 0),
						pancake_id: cb.dataset.pancakeId,
						pancake_sku: cb.dataset.pancakeSku
					});
				} else {
					selectedProductsMap.delete(key);
				}
			}

			function restoreSelectedProductCheckboxes() {
				$$('.nxp-prod-check', paneProducts).forEach(cb => {
					const key = String(cb.dataset.pancakeSku || cb.dataset.pancakeId || '').trim().toUpperCase();
					if (key && selectedProductsMap.has(key)) {
						cb.checked = true;
					}
				});
			}

			function collectSelectedProductItems() {
				$$('.nxp-prod-check:checked', paneProducts).forEach(cb => syncSelectedProductCheckbox(cb));
				return Array.from(selectedProductsMap.values());
			}

			// Event delegation on #nxp-pane-products for checkboxes & filter selects (persists across AJAX page changes)
			paneProducts.addEventListener('change', function(e) {
				if (e.target && e.target.id === 'nxpProdCheckAll') {
					const checked = e.target.checked;
					$$('.nxp-prod-check', paneProducts).forEach(cb => {
						cb.checked = checked;
						syncSelectedProductCheckbox(cb);
					});
					return;
				}
				if (e.target && e.target.classList && e.target.classList.contains('nxp-prod-check')) {
					syncSelectedProductCheckbox(e.target);
					return;
				}
				const filterSelect = e.target.closest ? e.target.closest('.nxp-spa-filter-form select') : null;
				if (filterSelect) {
					const form = filterSelect.closest('form');
					if (form) form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
				}
			});

			// Event delegation on #nxp-pane-products for per-row buttons
			paneProducts.addEventListener('click', function(e) {
				const btnImport = e.target.closest ? e.target.closest('.nxp-btn-import-one') : null;
				if (btnImport) {
					e.preventDefault();
					const tr = $('#nxp-prod-row-' + btnImport.dataset.rowDom);
					const isExistingOnWeb = tr && Number(tr.dataset.wcId || 0) > 0;
					runBatchImport([{
						row_dom: btnImport.dataset.rowDom,
						pancake_id: btnImport.dataset.pancakeId,
						pancake_sku: btnImport.dataset.pancakeSku
					}], 'Đang cập nhật trực tiếp sản phẩm [' + (btnImport.dataset.pancakeSku || '') + '] từ Pancake POS về Website...', isExistingOnWeb, true);
					return;
				}

				const btnDelete = e.target.closest ? e.target.closest('.nxp-btn-delete-one') : null;
				if (btnDelete) {
					e.preventDefault();
					runBatchDeleteDisconnect([{
						row_dom: btnDelete.dataset.rowDom,
						wc_id: Number(btnDelete.dataset.wcId || 0),
						pancake_id: btnDelete.dataset.pancakeId,
						pancake_sku: btnDelete.dataset.pancakeSku
					}], 'delete_and_disconnect');
					return;
				}
			});

			// Warehouse selector change -> save setting & recalculate stock across catalog
			$('#nxpProdWarehouseSelect')?.addEventListener('change', async function() {
				const newWh = this.value;
				this.disabled = true;
				progBox.style.display = 'block';
				logBox.innerHTML = '';
				progTitle.textContent = '⏳ Đang chuyển kho đồng bộ tồn kho Pancake & tính toán lại số lượng...';
				progBar.style.width = '60%';
				try {
					const res = await postAjax('nexxi_pancake_set_warehouse', { warehouse_id: newWh });
					progBar.style.width = '100%';
					if (res && res.success) {
						pancakeWarehouseId = newWh;
						appendLog('✓ ' + (res.data?.message || 'Đã lưu cài đặt kho đồng bộ.'));
						location.reload();
						return;
					}
				} catch (e) {}
				this.disabled = false;
			});

			// Bulk button: Import selected (supports selections across multiple pages)
			$('#nxpBtnImportSelected')?.addEventListener('click', function() {
				const selected = collectSelectedProductItems();
				runBatchImport(selected, 'Đang đồng bộ ' + selected.length + ' sản phẩm đã chọn từ Pancake về Website...');
			});

			// Bulk button: Update ALL products currently on Website from Pancake POS
			$('#nxpBtnUpdateAllOnWeb')?.addEventListener('click', async function() {
				if (isRunningBatch) return;
				const items = deduplicateBatchItems(allOnWebItems);
				if (!items.length) {
					alert('Hiện chưa có sản phẩm nào trên Website để cập nhật.');
					return;
				}
				const whSelect = $('#nxpProdWarehouseSelect');
				const whLabel = whSelect && whSelect.options[whSelect.selectedIndex] ? whSelect.options[whSelect.selectedIndex].text : 'Kho mặc định';
				if (!confirm('Bạn có muốn CẬP NHẬT TỪ PANCAKE cho toàn bộ ' + items.length + ' sản phẩm đang có trên Website (đồng bộ chuẩn tồn kho theo "' + whLabel + '", giá bán, biến thể & ảnh) không?')) {
					return;
				}

				const btn = this;
				const origHtml = btn.innerHTML;
				btn.disabled = true;
				progBox.style.display = 'block';
				logBox.innerHTML = '';
				progBar.style.width = '5%';
				progTitle.textContent = '⏳ Bước 1/2: Đang lấy dữ liệu tồn kho & giá mới nhất từ Pancake POS (' + whLabel + ')...';

				// Refresh live POS data for all Website SKUs in chunks of 25 so cache has 100% real-time warehouse stock
				const skuList = Array.from(new Set([
					...(Array.isArray(wcLookupSkus) ? wcLookupSkus : []),
					...items.map(it => it.pancake_sku).filter(Boolean)
				]));

				if (skuList.length > 0) {
					const skuMap = {};
					const chunkSize = 25;
					for (let i = 0; i < skuList.length; i += chunkSize) {
						const chunk = skuList.slice(i, i + chunkSize);
						progCount.textContent = 'Quét POS ' + Math.min(i + chunkSize, skuList.length) + ' / ' + skuList.length + ' SKU';
						try {
							const url = cfBaseUrl + '/api/pancake-by-skus?shop_id=' + encodeURIComponent(pancakeShopId) + '&api_key=' + encodeURIComponent(pancakeApiKey) + '&skus=' + encodeURIComponent(chunk.join(','));
							const res = await fetch(url).then(r => r.json());
							const posProds = Array.isArray(res.products) ? res.products : (Array.isArray(res.data) ? res.data : []);
							posProds.forEach(prod => ingestPosProductIntoMap(prod, skuMap));
						} catch (e) {}
					}
					if (Object.keys(skuMap).length > 0) {
						appendLog('✓ Đã tải trực tiếp ' + Object.keys(skuMap).length + ' sản phẩm mới nhất từ Pancake POS (Kho: ' + whLabel + ').');
						await postAjax('nexxi_pancake_push_browser_cache', {
							catalog_json: JSON.stringify(skuMap),
							append: '1'
						});
					} else {
						await postAjax('nexxi_pancake_refresh_cache', { trigger_live: '1' });
					}
				}

				// Step 2: Run batch update for all Website products with forceUpdate = true (skip_existing = 0)
				await runBatchImport(items, '🔄 Bước 2/2: Đang cập nhật ' + items.length + ' sản phẩm trên Website theo chuẩn Pancake (' + whLabel + ')...', true, false);
				btn.disabled = false;
				btn.innerHTML = origHtml;
			});

			// Bulk button: Delete from Web & Disconnect selected
			$('#nxpBtnDeleteDisconnectSelected')?.addEventListener('click', function() {
				const selected = collectSelectedProductItems();
				runBatchDeleteDisconnect(selected, 'delete_and_disconnect');
			});

			// Bulk button: Disconnect only selected
			$('#nxpBtnDisconnectOnlySelected')?.addEventListener('click', function() {
				const selected = collectSelectedProductItems();
				runBatchDeleteDisconnect(selected, 'disconnect_only');
			});

			// Bulk SKU Input: Import by SKU list
			$('#nxpBtnImportBySkuList')?.addEventListener('click', function() {
				const raw = ($('#nxpProdBulkSkuInput')?.value || '').trim();
				if (!raw) {
					alert('Vui lòng nhập ít nhất 1 mã SKU Pancake (VD: 2VBKHN2B01, 2QVKATL01).');
					return;
				}
				const skus = Array.from(new Set(raw.split(/[\s,;]+/).map(s => s.trim().toUpperCase()).filter(Boolean)));
				const items = skus.map(sku => {
					const tr = $('tr[data-pancake-sku="' + sku + '"]');
					return {
						row_dom: tr ? tr.dataset.rowDom : '',
						pancake_id: tr ? tr.dataset.pancakeId : '',
						pancake_sku: sku
					};
				});
				runBatchImport(items, 'Đang đồng bộ ' + items.length + ' sản phẩm theo Mã SKU từ Pancake về Website...');
			});

			// Bulk SKU Input: Delete & Disconnect by SKU list
			$('#nxpBtnDeleteBySkuList')?.addEventListener('click', function() {
				const raw = ($('#nxpProdBulkSkuInput')?.value || '').trim();
				if (!raw) {
					alert('Vui lòng nhập ít nhất 1 mã SKU cần xóa khỏi Website & ngắt kết nối.');
					return;
				}
				const skus = Array.from(new Set(raw.split(/[\s,;]+/).map(s => s.trim().toUpperCase()).filter(Boolean)));
				const items = skus.map(sku => {
					const tr = $('tr[data-pancake-sku="' + sku + '"]');
					return {
						row_dom: tr ? tr.dataset.rowDom : '',
						wc_id: tr ? Number(tr.dataset.wcId || 0) : 0,
						pancake_id: tr ? tr.dataset.pancakeId : '',
						pancake_sku: sku
					};
				});
				runBatchDeleteDisconnect(items, 'delete_and_disconnect');
			});

			// Button: Clean up duplicate products on Website
			$('#nxpBtnCleanupDuplicates')?.addEventListener('click', async function() {
				const btn = this;
				btn.disabled = true;
				progBox.style.display = 'block';
				logBox.innerHTML = '';
				progTitle.textContent = '🧹 Đang quét & dọn dẹp các sản phẩm bị trùng SKU / trùng tên trên Website...';
				progBar.style.width = '50%';
				try {
					const res = await postAjax('nexxi_pancake_cleanup_duplicates', {});
					progBar.style.width = '100%';
					if (res && res.success && res.data) {
						const d = res.data;
						if (d.deleted_products > 0) {
							(d.details || []).forEach(msg => appendLog('✓ ' + msg));
							progTitle.textContent = '🎉 Đã dọn sạch ' + d.deleted_products + ' sản phẩm bị trùng (' + d.deleted_variations + ' biến thể thừa)!';
							loadSpaUrlNoReload(window.location.href, 'products', false);
						} else {
							appendLog('✓ Không phát hiện sản phẩm nào bị trùng lặp trên Website.');
							progTitle.textContent = '✓ Danh mục Website sạch sẽ, không có sản phẩm bị trùng!';
						}
					}
				} catch (e) {
					appendLog('✗ Lỗi khi dọn trùng sản phẩm.', true);
				}
				btn.disabled = false;
			});

			// ============================================================================
			// SPA CONTROLLER: NO-RELOAD TAB SWITCHING & NO-RELOAD PAGINATION / FILTERING
			// ============================================================================
			const wrapRoot = $('.nexxi-pancake-wrap') || document;
			let spaAbortCtrl = null;

			function switchTabNoReload(tabName, pushHistory = true) {
				const targetTab = tabName === 'images' ? 'images' : 'products';
				const p1 = $('#nxp-pane-products');
				const p2 = $('#nxp-pane-images');
				if (p1) p1.style.display = targetTab === 'products' ? 'block' : 'none';
				if (p2) p2.style.display = targetTab === 'images' ? 'block' : 'none';

				$$('.nxp-tab-link', wrapRoot).forEach(link => {
					if (link.dataset.tab === targetTab) {
						link.classList.add('is-active');
					} else {
						link.classList.remove('is-active');
					}
				});

				if (pushHistory && window.history && window.history.pushState) {
					const u = new URL(window.location.href);
					u.searchParams.set('tab', targetTab);
					window.history.pushState({ tab: targetTab, url: u.toString() }, '', u.toString());
				}
			}

			async function loadSpaUrlNoReload(targetUrl, targetTab = '', pushHistory = true) {
				const u = new URL(targetUrl, window.location.origin);
				const tab = targetTab || u.searchParams.get('tab') || 'products';
				try {
					switchTabNoReload(tab, false);

					const resultsContainerId = tab === 'images' ? 'nxp-tab2-results' : 'nxp-tab1-results';
					const currentBox = document.getElementById(resultsContainerId);
					if (currentBox) {
						currentBox.style.opacity = '0.45';
						currentBox.style.pointerEvents = 'none';
					}

					if (spaAbortCtrl) spaAbortCtrl.abort();
					spaAbortCtrl = new AbortController();

					const fetchUrl = new URL(ajaxUrl, window.location.origin);
					u.searchParams.forEach((v, k) => fetchUrl.searchParams.set(k, v));
					fetchUrl.searchParams.set('action', 'nexxi_pancake_spa_nav');
					fetchUrl.searchParams.set('nonce', nonce);
					fetchUrl.searchParams.set('tab', tab);

					const res = await fetch(fetchUrl.toString(), {
						method: 'GET',
						credentials: 'same-origin',
						signal: spaAbortCtrl.signal
					});
					const rawText = await res.text();
					let html = rawText;
					try {
						const parsed = JSON.parse(rawText);
						if (parsed && parsed.data && typeof parsed.data.html === 'string') {
							html = parsed.data.html;
						}
					} catch (e) {}

					const doc = new DOMParser().parseFromString(html, 'text/html');
					const newBox = doc.getElementById(resultsContainerId);

					if (newBox && currentBox) {
						currentBox.innerHTML = newBox.innerHTML;
						currentBox.style.opacity = '1';
						currentBox.style.pointerEvents = 'auto';
						if (tab === 'products') {
							restoreSelectedProductCheckboxes();
						}
						if (pushHistory && window.history && window.history.pushState) {
							window.history.pushState({ tab, url: u.toString() }, '', u.toString());
						}
					} else {
						window.location.href = u.toString();
					}
				} catch (err) {
					if (err && err.name === 'AbortError') return;
					window.location.href = u.toString();
				}
			}

			// Intercept Tab clicks & Pagination clicks across both tabs
			wrapRoot.addEventListener('click', function(e) {
				const tabLink = e.target.closest ? e.target.closest('.nxp-tab-link') : null;
				if (tabLink && tabLink.dataset.tab) {
					if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
					e.preventDefault();
					switchTabNoReload(tabLink.dataset.tab, true);
					return;
				}

				const pageLink = e.target.closest ? e.target.closest('.nxp-spa-page-link') : null;
				if (pageLink && pageLink.href) {
					if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
					e.preventDefault();
					const tab = pageLink.dataset.targetTab || '';
					loadSpaUrlNoReload(pageLink.href, tab, true);
					return;
				}
			});

			// Intercept Filter Form submissions across both tabs
			wrapRoot.addEventListener('submit', function(e) {
				const form = e.target.closest ? e.target.closest('.nxp-spa-filter-form') : null;
				if (!form) return;
				e.preventDefault();
				const u = new URL(form.action || window.location.href, window.location.origin);
				const fd = new FormData(form);
				u.search = '';
				fd.forEach((val, key) => {
					u.searchParams.set(key, String(val));
				});
				u.searchParams.delete('paged');
				const tab = form.dataset.targetTab || u.searchParams.get('tab') || 'products';
				loadSpaUrlNoReload(u.toString(), tab, true);
			});

			// Support Browser Back / Forward buttons without page reload
			window.addEventListener('popstate', function() {
				const u = new URL(window.location.href);
				const tab = u.searchParams.get('tab') || 'products';
				loadSpaUrlNoReload(u.toString(), tab, false);
			});

			// Helper to parse Pancake POS product in browser when fetching all pages
			function collectRecordImages(rec) {
				const urls = [];
				if (!rec || typeof rec !== 'object') return urls;
				['image_url', 'image', 'avatar', 'thumbnail'].forEach(k => {
					if (typeof rec[k] === 'string' && rec[k].trim().startsWith('http')) {
						const u = rec[k].trim();
						if (!urls.includes(u)) urls.push(u);
					}
				});
				['images', 'image_urls', 'photos'].forEach(ak => {
					if (Array.isArray(rec[ak])) {
						rec[ak].forEach(it => {
							const u = typeof it === 'string' ? it.trim() : (it && typeof it === 'object' ? String(it.url || it.image_url || it.path || '').trim() : '');
							if (u.startsWith('http') && !urls.includes(u)) urls.push(u);
						});
					}
				});
				return urls;
			}

			function ingestPosProductIntoMap(prod, map) {
				if (!prod || typeof prod !== 'object') return;
				const pid = String(prod.id || prod.pancake_product_id || '').trim();
				if (!pid) return;
				const vars = Array.isArray(prod.variations) ? prod.variations : [];
				let sku = String(prod.custom_id || prod.sku || '').trim();
				if (!sku && vars.length) {
					for (const v of vars) {
						const vs = String(v.display_id || v.sku || '').trim();
						if (vs.includes('-')) { sku = vs.split('-')[0]; break; }
					}
				}
				if (!sku) sku = String(prod.display_id || '').trim();
				const pImgs = collectRecordImages(prod);
				map[pid] = {
					id: pid,
					sku: sku,
					display_id: String(prod.display_id || sku).trim(),
					code: '',
					name: String(prod.name || '').trim(),
					categories: Array.isArray(prod.categories) ? prod.categories.map(c => typeof c === 'string' ? c : (c?.name || '')).filter(Boolean) : [],
					product_image: pImgs[0] || '',
					all_images: [...pImgs],
					class_images: {},
					var_images: {},
					variations: [],
					total_stock: 0,
					min_price: 0,
					max_price: 0
				};
				const p = map[pid];
				vars.forEach(vr => {
					if (vr.is_removed) return;
					const vsku = String(vr.display_id || vr.sku || vr.custom_id || '').trim();
					if (!p.sku && vsku.includes('-')) p.sku = vsku.split('-')[0];
					const vImgs = collectRecordImages(vr);
					const vimg = vImgs[0] || '';
					const price = Number(vr.retail_price ?? vr.price ?? 0);
					const whMap = {};
					if (Array.isArray(vr.variations_warehouses)) {
						vr.variations_warehouses.forEach(wh => {
							const wid = String(wh.warehouse_id || '').trim();
							if (!wid) return;
							const wq = Math.max(0, Number(wh.remain_quantity ?? wh.actual_remain_quantity ?? 0));
							if (wq > 0) whMap[wid] = (whMap[wid] || 0) + wq;
						});
					}
					let qty = 0;
					if (pancakeWarehouseId && pancakeWarehouseId !== 'all') {
						qty = Number(whMap[pancakeWarehouseId] || 0);
					} else if (Object.keys(whMap).length > 0) {
						Object.values(whMap).forEach(wq => { qty += Number(wq || 0); });
					} else {
						qty = Math.max(0, Number(vr.remain_quantity ?? vr.available_quantity ?? vr.total_quantity ?? 0));
					}
					const fields = Array.isArray(vr.fields) ? vr.fields : (Array.isArray(vr.attributes) ? vr.attributes : []);
					let colorVal = '', sizeVal = '';
					fields.forEach(a => {
						const an = String(a.name || a.key || '').toLowerCase();
						const av = String(a.value || a.val || '').trim();
						if (!av) return;
						if (an.includes('màu') || an.includes('mau') || an.includes('color') || an.includes('loại')) colorVal = av;
						else if (an.includes('size') || an.includes('cỡ') || an.includes('kích')) sizeVal = av;
					});
					if (!colorVal && vsku.includes('-')) {
						const pts = vsku.split('-');
						if (pts.length >= 3) { colorVal = pts[1]; if (!sizeVal) sizeVal = pts[2]; }
					}
					const cKey = colorVal.toUpperCase();
					vImgs.forEach(u => { if (!p.all_images.includes(u)) p.all_images.push(u); });
					if (vimg && vimg.startsWith('http')) {
						if (!p.product_image) p.product_image = vimg;
						if (vsku) p.var_images[vsku.toUpperCase()] = vimg;
						if (cKey) {
							if (!p.class_images[cKey]) p.class_images[cKey] = { key: cKey, raw: colorVal, label: colorVal, image: vimg, skus: [] };
							if (vsku && p.class_images[cKey].skus.length < 15 && !p.class_images[cKey].skus.includes(vsku)) p.class_images[cKey].skus.push(vsku);
						}
					} else if (cKey && p.class_images[cKey] && vsku) {
						if (p.class_images[cKey].skus.length < 15 && !p.class_images[cKey].skus.includes(vsku)) p.class_images[cKey].skus.push(vsku);
					}
					if (qty > 0) p.total_stock += qty;
					if (price > 0) {
						if (!p.min_price || price < p.min_price) p.min_price = price;
						if (price > p.max_price) p.max_price = price;
					}
					p.variations.push({ sku: vsku, color_raw: colorVal, color_key: cKey, size: sizeVal, image: vimg, price, stock: Math.max(0, qty), warehouses: whMap });
				});
				p.variations.forEach(v => {
					if (!v.image && v.color_key && p.class_images[v.color_key]?.image) {
						v.image = p.class_images[v.color_key].image;
						if (v.sku) p.var_images[v.sku.toUpperCase()] = v.image;
					}
				});
			}

			// Button: Load all Pancake POS pages (fetches all 30 pages in parallel groups of 5 pages and appends to Website cache)
			async function fetchAllPancakePages(btn) {
				progBox.style.display = 'block';
				logBox.innerHTML = '';
				progTitle.textContent = 'Đang tải toàn bộ danh mục sản phẩm từ Pancake POS...';
				progBar.style.width = '5%';

				const pageGroups = [
					'1,2,3,4,5',
					'6,7,8,9,10',
					'11,12,13,14,15',
					'16,17,18,19,20',
					'21,22,23,24,25',
					'26,27,28,29,30'
				];
				let totalLoaded = 0;

				for (let gi = 0; gi < pageGroups.length; gi++) {
					const grp = pageGroups[gi];
					progCount.textContent = 'Chặng ' + (gi + 1) + ' / ' + pageGroups.length;
					appendLog('⏳ Đang tải các trang ' + grp + ' từ Pancake POS...');
					const chunkMap = {};
					try {
						const url = cfBaseUrl + '/api/pancake-live-products?shop_id=' + encodeURIComponent(pancakeShopId) + '&api_key=' + encodeURIComponent(pancakeApiKey) + '&pages=' + encodeURIComponent(grp) + '&page_size=100';
						const res = await fetch(url).then(r => r.json());
						const items = Array.isArray(res.data) ? res.data : [];
						if (!items.length) {
							appendLog('Đã hết trang dữ liệu ở chặng ' + (gi + 1) + '.');
							break;
						}
						items.forEach(prod => ingestPosProductIntoMap(prod, chunkMap));
						const cnt = Object.keys(chunkMap).length;
						totalLoaded += cnt;
						await postAjax('nexxi_pancake_push_browser_cache', {
							catalog_json: JSON.stringify(chunkMap),
							append: gi === 0 ? '0' : '1'
						});
						appendLog('✓ Đã lưu thêm ' + cnt + ' sản phẩm Pancake (Tổng cộng: ' + totalLoaded + ' SP).');
						if (items.length < 500) break;
					} catch (e) {
						appendLog('✗ Lỗi tải chặng ' + grp, true);
					}
					progBar.style.width = Math.round(((gi + 1) / pageGroups.length) * 100) + '%';
				}

				// Also ensure all Website SKUs are queried
				if (Array.isArray(wcLookupSkus) && wcLookupSkus.length > 0) {
					const skuMap = {};
					for (let i = 0; i < wcLookupSkus.length; i += 25) {
						const chunk = wcLookupSkus.slice(i, i + 25);
						try {
							const url = cfBaseUrl + '/api/pancake-by-skus?shop_id=' + encodeURIComponent(pancakeShopId) + '&api_key=' + encodeURIComponent(pancakeApiKey) + '&skus=' + encodeURIComponent(chunk.join(','));
							const res = await fetch(url).then(r => r.json());
							const items = Array.isArray(res.products) ? res.products : [];
							items.forEach(prod => ingestPosProductIntoMap(prod, skuMap));
						} catch (e) {}
					}
					if (Object.keys(skuMap).length > 0) {
						await postAjax('nexxi_pancake_push_browser_cache', {
							catalog_json: JSON.stringify(skuMap),
							append: '1'
						});
					}
				}

				progTitle.textContent = '🎉 Đã tải xong toàn bộ sản phẩm từ Pancake POS! Đang làm mới trang...';
				setTimeout(() => location.reload(), 800);
			}

			$('#nxpBtnLoadAllPancakePages')?.addEventListener('click', function() {
				this.disabled = true;
				fetchAllPancakePages(this);
			});

			$('#nxpBtnRefreshPancake')?.addEventListener('click', async function() {
				this.disabled = true;
				this.textContent = '⏳ Đang làm mới từ Pancake POS...';
				await fetchAllPancakePages(this);
			});

			if (<?php
				$v4_cache_path = self::get_cache_file_path();
				echo ( count( $catalog ) === 0 || ( $v4_cache_path && ! file_exists( $v4_cache_path ) ) ) ? 'true' : 'false';
			?>) {
				const btn = $('#nxpBtnRefreshPancake');
				if (btn) {
					btn.disabled = true;
					fetchAllPancakePages(btn);
				}
			}
		})();
		</script>
		<?php
	}
}

endif;

if ( ! class_exists( 'Nexxi_Pancake_Sync_Manager', false ) ) {
	class_alias( 'Nexxi_Pancake_Sync_Manager_V4', 'Nexxi_Pancake_Sync_Manager' );
}
if ( ! class_exists( 'Nexxi_Pancake_Sync_Manager_V3', false ) ) {
	class_alias( 'Nexxi_Pancake_Sync_Manager_V4', 'Nexxi_Pancake_Sync_Manager_V3' );
}
if ( ! class_exists( 'Nexxi_Pancake_Sync_Manager_V2', false ) ) {
	class_alias( 'Nexxi_Pancake_Sync_Manager_V4', 'Nexxi_Pancake_Sync_Manager_V2' );
}

Nexxi_Pancake_Sync_Manager_V4::init();

