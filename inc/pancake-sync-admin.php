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

if ( ! class_exists( 'Nexxi_Pancake_Sync_Manager' ) ) :

final class Nexxi_Pancake_Sync_Manager {

	const VERSION          = '1.1.0';
	const MENU_SLUG        = 'nexxi-pancake-sync';
	const CACHE_OPTION     = 'nexxi_pancake_catalog_cache_v2';
	const CACHE_TIME_OPT   = 'nexxi_pancake_catalog_cache_time';
	const SETTINGS_OPT     = 'nexxi_pancake_sync_settings';
	const CRON_HOOK        = 'nexxi_pancake_auto_scan_event';
	const DEFAULT_CF_API   = 'https://nexxi-cloudflare-api.nexxivinhomes.workers.dev';
	const DEFAULT_SHOP_ID  = '714396188';

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

		// Background WP-Cron schedule for automatic Pancake image scanning.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron_auto_scan' ) );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
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
		return wp_parse_args(
			$saved,
			array(
				'cf_api_url'      => self::DEFAULT_CF_API,
				'shop_id'         => self::DEFAULT_SHOP_ID,
				'pancake_api_key' => '',
				'auto_cron'       => 0,
				'auto_set_sku'    => 1,
				'auto_set_thumb'  => 1,
				'auto_set_gallery'=> 1,
				'auto_set_var_img'=> 1,
			)
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
		$settings['auto_cron']        = ! empty( $_POST['auto_cron'] ) ? 1 : 0;
		$settings['auto_set_sku']     = ! empty( $_POST['auto_set_sku'] ) ? 1 : 0;
		$settings['auto_set_thumb']   = ! empty( $_POST['auto_set_thumb'] ) ? 1 : 0;
		$settings['auto_set_gallery'] = ! empty( $_POST['auto_set_gallery'] ) ? 1 : 0;
		$settings['auto_set_var_img'] = ! empty( $_POST['auto_set_var_img'] ) ? 1 : 0;

		update_option( self::SETTINGS_OPT, $settings );
		$this->maybe_schedule_cron();

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
	 * Normalize Vietnamese / abbreviated color or classification names into a canonical key.
	 * Examples:
	 *  - "Đỏ", "DO", "DO01", "ĐỎ ĐÔ" -> "do"
	 *  - "Trắng", "TRANG", "TRA", "TRẮNG KEM" -> "trang"
	 *  - "Hồng", "HONG", "HON" -> "hong"
	 *  - "Đen", "DEN" -> "den"
	 *  - "Xanh", "XANH", "XAN" -> "xanh"
	 */
	public static function normalize_class_key( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$clean = strtolower( remove_accents( $raw ) );
		$clean = preg_replace( '/^(mau|color|phan-loai|phan loai)[\s:\-_]+/i', '', $clean );
		$slug  = sanitize_title( $clean );
		// Strip trailing numeric code suffixes like "do01" -> "do", "trang-02" -> "trang"
		$slug_no_num = preg_replace( '/-?\d+$/', '', $slug );
		if ( '' !== $slug_no_num && ! is_numeric( $slug_no_num ) ) {
			$slug = $slug_no_num;
		}
		$aliases = array(
			'tra'        => 'trang',
			'trang'      => 'trang',
			'white'      => 'trang',
			'aotrang'    => 'trang',
			'do'         => 'do',
			'red'        => 'do',
			'dau'        => 'do-dau',
			'hon'        => 'hong',
			'hong'       => 'hong',
			'pink'       => 'hong',
			'hdam'       => 'hong-dam',
			'hphan'      => 'hong-phan',
			'hong-phan'  => 'hong-phan',
			'hnhat'      => 'hong-nhat',
			'sen'        => 'hong-sen',
			'den'        => 'den',
			'black'      => 'den',
			'xan'        => 'xanh',
			'xanh'       => 'xanh',
			'blue'       => 'xanh',
			'xla'        => 'xanh-la',
			'xanh-la'    => 'xanh-la',
			'xduong'     => 'xanh-duong',
			'xanh-duong' => 'xanh-duong',
			'xden'       => 'xanh-den',
			'xanh-den'   => 'xanh-den',
			'xnhat'      => 'xanh-nhat',
			'xngoc'      => 'xanh-ngoc',
			'van'        => 'vang',
			'vang'       => 'vang',
			'yellow'     => 'vang',
			'kem'        => 'kem',
			'cream'      => 'kem',
			'be'         => 'be',
			'beige'      => 'be',
			'nau'        => 'nau',
			'brown'      => 'nau',
			'tim'        => 'tim',
			'purple'     => 'tim',
			'xam'        => 'xam',
			'ghi'        => 'xam',
			'grey'       => 'xam',
			'gray'       => 'xam',
			'cam'        => 'cam',
			'orange'     => 'cam',
			'reu'        => 'reu',
			'xanh-reu'   => 'reu',
			'nhat'       => 'nhat',
			'dam'        => 'dam',
		);
		if ( isset( $aliases[ $slug ] ) ) {
			return $aliases[ $slug ];
		}
		foreach ( $aliases as $prefix => $canonical ) {
			if ( 0 === strpos( $slug, $prefix . '-' ) ) {
				return $canonical;
			}
		}
		return $slug;
	}

	/**
	 * Pretty Vietnamese label for a classification code (e.g. "DO" -> "Đỏ", "TRANG" -> "Trắng").
	 */
	public static function pretty_class_label( $raw ) {
		$key = self::normalize_class_key( $raw );
		$map = array(
			'trang'      => 'Trắng',
			'do'         => 'Đỏ',
			'do-dau'     => 'Đỏ dâu',
			'hong'       => 'Hồng',
			'hong-dam'   => 'Hồng đậm',
			'hong-phan'  => 'Hồng phấn',
			'hong-nhat'  => 'Hồng nhạt',
			'hong-sen'   => 'Hồng sen',
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
			'nhat'       => 'Màu nhạt',
			'dam'        => 'Màu đậm',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] . ' (' . strtoupper( trim( (string) $raw ) ) . ')' : trim( (string) $raw );
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
			return trailingslashit( $up['basedir'] ) . 'nexxi-pancake-catalog-v2.json';
		}
		return '';
	}

	/**
	 * Save catalog cache to local JSON file (and compact copy to wp_options).
	 */
	public static function save_catalog_cache( array $products_by_id ) {
		$file = self::get_cache_file_path();
		if ( $file ) {
			file_put_contents( $file, wp_json_encode( $products_by_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		update_option( self::CACHE_OPTION, $products_by_id, false );
		update_option( self::CACHE_TIME_OPT, time(), false );
	}

	/**
	 * Fetch fresh Pancake products + variations from NEXXI Cloudflare D1 API (or direct Pancake API)
	 * and build a compact, fast lookup index cached in wp-content/uploads & wp_options.
	 *
	 * @param bool $trigger_live_sync Whether to ask Cloudflare worker to pull fresh data from Pancake POS first.
	 * @return array|WP_Error
	 */
	public static function fetch_and_cache_pancake_catalog( $trigger_live_sync = false ) {
		$settings = self::get_settings();
		$cf_base  = rtrim( $settings['cf_api_url'], '/' );
		$req_headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		);

		if ( $trigger_live_sync && $cf_base ) {
			// Trigger Cloudflare incremental sync non-blocking so it never times out the PHP request
			wp_remote_post(
				$cf_base . '/api/pancake-sync',
				array(
					'timeout'   => 3,
					'blocking'  => false,
					'sslverify' => false,
					'headers'   => array_merge( $req_headers, array( 'Content-Type' => 'application/json' ) ),
					'body'      => wp_json_encode( array( 'mode' => 'incremental', 'source' => 'nexxi-wp-plugin' ) ),
				)
			);
		}

		$products_by_id = array();

		// 1. Fetch pancake_products from Cloudflare D1 mirror
		$prod_resp = wp_remote_get(
			$cf_base . '/api/table?table=pancake_products&offset=0&limit=5000',
			array(
				'timeout'   => 30,
				'sslverify' => false,
				'headers'   => $req_headers,
			)
		);

		if ( is_wp_error( $prod_resp ) ) {
			return $prod_resp;
		}

		$prod_body = json_decode( wp_remote_retrieve_body( $prod_resp ), true );
		$prod_rows = array();
		if ( isset( $prod_body['rows'] ) && is_array( $prod_body['rows'] ) ) {
			$prod_rows = $prod_body['rows'];
		} elseif ( isset( $prod_body['data'] ) && is_array( $prod_body['data'] ) ) {
			$prod_rows = $prod_body['data'];
		}

		foreach ( $prod_rows as $row ) {
			$pid = isset( $row['pancake_product_id'] ) ? (string) $row['pancake_product_id'] : '';
			if ( '' === $pid ) {
				continue;
			}
			$raw = array();
			if ( ! empty( $row['raw_data'] ) ) {
				$raw = is_array( $row['raw_data'] ) ? $row['raw_data'] : json_decode( (string) $row['raw_data'], true );
				if ( ! is_array( $raw ) ) {
					$raw = array();
				}
			}
			$custom_id  = ! empty( $raw['custom_id'] ) ? trim( (string) $raw['custom_id'] ) : trim( (string) ( $row['display_id'] ?? '' ) );
			$display_id = trim( (string) ( $row['display_id'] ?? $custom_id ) );
			$name       = trim( (string) ( $row['name'] ?? '' ) );
			$img_url    = ! empty( $row['image_url'] ) ? trim( (string) $row['image_url'] ) : '';
			$cats       = array();
			if ( ! empty( $row['category_names'] ) ) {
				$cats = is_array( $row['category_names'] ) ? $row['category_names'] : json_decode( (string) $row['category_names'], true );
			}

			$products_by_id[ $pid ] = array(
				'id'             => $pid,
				'sku'            => $custom_id ? $custom_id : $display_id,
				'display_id'     => $display_id,
				'code'           => self::extract_leading_code( $name ),
				'name'           => $name,
				'categories'     => is_array( $cats ) ? $cats : array(),
				'product_image'  => $img_url,
				'all_images'     => $img_url ? array( $img_url ) : array(),
				'class_images'   => array(),
				'var_images'     => array(),
				'variations'     => array(),
				'total_stock'    => 0,
				'min_price'      => 0,
				'max_price'      => 0,
			);
		}

		// 2. Fetch pancake_product_variations in pages of 5000
		$offset = 0;
		$limit  = 5000;
		$max_pages = 6;
		for ( $page = 0; $page < $max_pages; $page++ ) {
			$var_resp = wp_remote_get(
				sprintf( '%s/api/table?table=pancake_product_variations&offset=%d&limit=%d', $cf_base, $offset, $limit ),
				array(
					'timeout'   => 35,
					'sslverify' => false,
					'headers'   => $req_headers,
				)
			);
			if ( is_wp_error( $var_resp ) ) {
				break;
			}
			$var_body = json_decode( wp_remote_retrieve_body( $var_resp ), true );
			$var_rows = array();
			if ( isset( $var_body['rows'] ) && is_array( $var_body['rows'] ) ) {
				$var_rows = $var_body['rows'];
			} elseif ( isset( $var_body['data'] ) && is_array( $var_body['data'] ) ) {
				$var_rows = $var_body['data'];
			}
			if ( empty( $var_rows ) ) {
				break;
			}

			foreach ( $var_rows as $vrow ) {
				$pid = isset( $vrow['pancake_product_id'] ) ? (string) $vrow['pancake_product_id'] : '';
				if ( '' === $pid ) {
					continue;
				}
				$vsku      = trim( (string) ( $vrow['sku'] ?? $vrow['display_id'] ?? '' ) );
				$vimg      = ! empty( $vrow['image_url'] ) ? trim( (string) $vrow['image_url'] ) : '';
				$price     = (float) ( $vrow['retail_price'] ?? 0 );
				$avail_qty = (int) ( $vrow['available_quantity'] ?? $vrow['total_quantity'] ?? 0 );
				$attrs_json = array();
				if ( ! empty( $vrow['attributes'] ) ) {
					$attrs_json = is_array( $vrow['attributes'] ) ? $vrow['attributes'] : json_decode( (string) $vrow['attributes'], true );
					if ( ! is_array( $attrs_json ) ) {
						$attrs_json = array();
					}
				}

				// If parent product wasn't in pancake_products table, create a stub entry from variation data
				if ( ! isset( $products_by_id[ $pid ] ) ) {
					$vraw = array();
					if ( ! empty( $vrow['raw_data'] ) ) {
						$vraw = is_array( $vrow['raw_data'] ) ? $vrow['raw_data'] : json_decode( (string) $vrow['raw_data'], true );
						if ( ! is_array( $vraw ) ) {
							$vraw = array();
						}
					}
					$parent_sku = ! empty( $vraw['product_custom_id'] ) ? trim( (string) $vraw['product_custom_id'] ) : '';
					if ( '' === $parent_sku && false !== strpos( $vsku, '-' ) ) {
						$parts = explode( '-', $vsku );
						$parent_sku = $parts[0];
					}
					$vname = trim( (string) ( $vrow['name'] ?? $vrow['display_name'] ?? $parent_sku ) );
					$products_by_id[ $pid ] = array(
						'id'            => $pid,
						'sku'           => $parent_sku,
						'display_id'    => $parent_sku,
						'code'          => self::extract_leading_code( $vname ),
						'name'          => $vname,
						'categories'    => array(),
						'product_image' => '',
						'all_images'    => array(),
						'class_images'  => array(),
						'var_images'    => array(),
						'variations'    => array(),
						'total_stock'   => 0,
						'min_price'     => 0,
						'max_price'     => 0,
					);
				}

				$p_ref = &$products_by_id[ $pid ];
				if ( '' === $p_ref['sku'] && false !== strpos( $vsku, '-' ) ) {
					$parts        = explode( '-', $vsku );
					$p_ref['sku'] = $parts[0];
				}

				// Extract Color / Classification attribute and Size attribute from Pancake variation
				$color_val = '';
				$size_val  = '';
				foreach ( $attrs_json as $a ) {
					$aname = strtolower( remove_accents( trim( (string) ( $a['name'] ?? '' ) ) ) );
					$aval  = trim( (string) ( $a['value'] ?? '' ) );
					if ( '' === $aval ) {
						continue;
					}
					if ( false !== strpos( $aname, 'mau' ) || false !== strpos( $aname, 'color' ) || false !== strpos( $aname, 'phan loai' ) || false !== strpos( $aname, 'kieu' ) ) {
						$color_val = $aval;
					} elseif ( false !== strpos( $aname, 'size' ) || false !== strpos( $aname, 'kich co' ) || 'co' === $aname ) {
						$size_val = $aval;
					}
				}

				// Fallback: parse middle segment of SKU {PARENT}-{COLOR}-{SIZE}
				if ( '' === $color_val && '' !== $vsku && false !== strpos( $vsku, '-' ) ) {
					$parts = explode( '-', $vsku );
					if ( count( $parts ) >= 3 ) {
						$color_val = $parts[1];
						if ( '' === $size_val ) {
							$size_val = $parts[2];
						}
					}
				}

				$class_key = self::normalize_class_key( $color_val );

				if ( $vimg && 0 === strpos( $vimg, 'http' ) ) {
					if ( ! in_array( $vimg, $p_ref['all_images'], true ) ) {
						$p_ref['all_images'][] = $vimg;
					}
					if ( '' === $p_ref['product_image'] ) {
						$p_ref['product_image'] = $vimg;
					}
					if ( '' !== $vsku ) {
						$p_ref['var_images'][ strtoupper( $vsku ) ] = $vimg;
					}
					if ( '' !== $class_key ) {
						if ( ! isset( $p_ref['class_images'][ $class_key ] ) ) {
							$p_ref['class_images'][ $class_key ] = array(
								'key'   => $class_key,
								'raw'   => $color_val,
								'label' => self::pretty_class_label( $color_val ),
								'image' => $vimg,
								'skus'  => array(),
							);
						}
						if ( '' !== $vsku && count( $p_ref['class_images'][ $class_key ]['skus'] ) < 15 && ! in_array( $vsku, $p_ref['class_images'][ $class_key ]['skus'], true ) ) {
							$p_ref['class_images'][ $class_key ]['skus'][] = $vsku;
						}
					}
				} elseif ( '' !== $class_key && isset( $p_ref['class_images'][ $class_key ] ) && '' !== $vsku ) {
					if ( count( $p_ref['class_images'][ $class_key ]['skus'] ) < 15 && ! in_array( $vsku, $p_ref['class_images'][ $class_key ]['skus'], true ) ) {
						$p_ref['class_images'][ $class_key ]['skus'][] = $vsku;
					}
				}

				if ( $avail_qty > 0 ) {
					$p_ref['total_stock'] += $avail_qty;
				}
				if ( $price > 0 ) {
					if ( 0 === (int) $p_ref['min_price'] || $price < $p_ref['min_price'] ) {
						$p_ref['min_price'] = $price;
					}
					if ( $price > $p_ref['max_price'] ) {
						$p_ref['max_price'] = $price;
					}
				}

				$p_ref['variations'][] = array(
					'sku'       => $vsku,
					'color_raw' => $color_val,
					'color_key' => $class_key,
					'size'      => $size_val,
					'price'     => $price,
					'stock'     => max( 0, $avail_qty ),
				);
				unset( $p_ref );
			}

			if ( count( $var_rows ) < $limit ) {
				break;
			}
			$offset += $limit;
		}

		if ( ! empty( $products_by_id ) ) {
			self::save_catalog_cache( $products_by_id );
		}

		return $products_by_id;
	}

	/**
	 * Get cached Pancake catalog (from local JSON cache file or wp_options, or fetch if empty).
	 */
	public static function get_pancake_catalog( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$file = self::get_cache_file_path();
			if ( $file && is_readable( $file ) ) {
				$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$dec = $raw ? json_decode( $raw, true ) : null;
				if ( is_array( $dec ) && ! empty( $dec ) ) {
					return $dec;
				}
			}
			$cached = get_option( self::CACHE_OPTION, null );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}
		$res = self::fetch_and_cache_pancake_catalog( false );
		return is_wp_error( $res ) ? array() : $res;
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
			if ( ! empty( $p['display_id'] ) ) {
				$by_sku[ strtoupper( trim( $p['display_id'] ) ) ] = $p;
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
						$by_var_sku[ strtoupper( trim( $v['sku'] ) ) ] = $p;
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
	 * Match a WooCommerce product to its corresponding Pancake product entry.
	 *
	 * @return array{pancake:array|null,match_type:string}
	 */
	public static function match_wc_product( WC_Product $product, array $maps ) {
		$wc_id = $product->get_id();

		// 1. Explicit linked Pancake Product ID or SKU
		$linked_pid = get_post_meta( $wc_id, '_nexxi_pancake_product_id', true );
		if ( ! $linked_pid ) {
			$linked_pid = get_post_meta( $wc_id, '_pancake_product_id', true );
		}
		if ( $linked_pid && isset( $maps['by_id'][ $linked_pid ] ) ) {
			return array( 'pancake' => $maps['by_id'][ $linked_pid ], 'match_type' => 'id_meta' );
		}

		$linked_sku = strtoupper( trim( (string) get_post_meta( $wc_id, '_nexxi_pancake_sku', true ) ) );
		if ( $linked_sku && isset( $maps['by_sku'][ $linked_sku ] ) ) {
			return array( 'pancake' => $maps['by_sku'][ $linked_sku ], 'match_type' => 'manual_sku' );
		}

		// 2. Match by WooCommerce Parent SKU
		$wc_sku = strtoupper( trim( (string) $product->get_sku() ) );
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
			if ( isset( $maps['by_code'][ $wc_sku ] ) && 1 === count( $maps['by_code'][ $wc_sku ] ) ) {
				return array( 'pancake' => $maps['by_code'][ $wc_sku ][0], 'match_type' => 'code_sku' );
			}
		}

		// 3. Match by Child Variation SKUs
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
				}
			}
		}

		// 4. Match by normalized Product Name or Leading Code (e.g. "2607.8 SET ÁO...")
		$wc_name  = trim( (string) $product->get_name() );
		$norm_nam = sanitize_title( remove_accents( $wc_name ) );
		if ( $norm_nam && isset( $maps['by_norm_name'][ $norm_nam ] ) ) {
			return array( 'pancake' => $maps['by_norm_name'][ $norm_nam ], 'match_type' => 'exact_name' );
		}

		$lead_code = strtoupper( self::extract_leading_code( $wc_name ) );
		if ( '' !== $lead_code && isset( $maps['by_code'][ $lead_code ] ) ) {
			if ( 1 === count( $maps['by_code'][ $lead_code ] ) ) {
				return array( 'pancake' => $maps['by_code'][ $lead_code ][0], 'match_type' => 'leading_code' );
			}
			// If multiple share the same leading code, pick highest title similarity
			$best_p   = null;
			$best_sim = 0;
			foreach ( $maps['by_code'][ $lead_code ] as $cand ) {
				similar_text( $norm_nam, sanitize_title( remove_accents( $cand['name'] ) ), $pct );
				if ( $pct > $best_sim ) {
					$best_sim = $pct;
					$best_p   = $cand;
				}
			}
			if ( $best_p && $best_sim >= 65 ) {
				return array( 'pancake' => $best_p, 'match_type' => 'code_and_name' );
			}
		}

		return array( 'pancake' => null, 'match_type' => '' );
	}

	/* ==========================================================================
	 * 2. IMAGE SIDELOADING & VARIATION CLASSIFICATION ASSIGNMENT ENGINE
	 * ========================================================================== */

	/**
	 * Download an image URL from Pancake (`https://content.pancake.vn/...`) into the WordPress
	 * Media Library, or return the existing Attachment ID if already downloaded earlier.
	 *
	 * @param string $image_url      Full URL on content.pancake.vn
	 * @param int    $parent_post_id WooCommerce parent product ID
	 * @param string $title          Attachment title / alt text
	 * @param string $caption        Optional caption (e.g. "Phân loại: Đỏ")
	 * @return int|WP_Error          Attachment ID on success
	 */
	public static function sideload_pancake_image( $image_url, $parent_post_id = 0, $title = '', $caption = '' ) {
		$image_url = trim( (string) $image_url );
		if ( '' === $image_url || 0 !== strpos( $image_url, 'http' ) ) {
			return new WP_Error( 'invalid_url', 'URL ảnh Pancake không hợp lệ.' );
		}

		global $wpdb;
		// Check if already sideloaded by _nexxi_pancake_source_url
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
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		update_post_meta( $attach_id, '_nexxi_pancake_source_url', $image_url );
		if ( $att_title ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $att_title ) );
		}

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
			$role = class_exists( 'Nexxi_Kids_Catalog' ) ? Nexxi_Kids_Catalog::attribute_role( $attr_name ) : '';
			if ( 'color' === $role || false !== strpos( $attr_name, 'mau' ) || false !== strpos( $attr_name, 'color' ) || false !== strpos( $attr_name, 'phan-loai' ) ) {
				// If taxonomy term, also check term name
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

		// 2. Fallback: check middle segment of variation SKU (e.g. 2MKFBSN01-DO01-7 -> DO01 -> do)
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
	 * Sync a single WooCommerce product with its matched Pancake product data:
	 *  - Sync Parent SKU & Variation SKUs (if enabled)
	 *  - Sideload & set Featured Image + Gallery Images (if enabled)
	 *  - Sideload & set Variation Images by Classification / Color & SKU (if enabled)
	 *  - Optional manual classification mapping overrides (`$custom_class_map`: wc_class_key => pancake_image_url)
	 *
	 * @param WC_Product $product
	 * @param array      $pancake_item
	 * @param array      $options
	 * @return array     Summary of actions performed
	 */
	public static function sync_product_with_pancake( WC_Product $product, array $pancake_item, array $options = array() ) {
		$defaults = array(
			'set_sku'          => true,
			'set_thumb'        => true,
			'set_gallery'      => true,
			'set_var_img'      => true,
			'sync_stock'       => false,
			'overwrite_images' => false,
			'custom_class_map' => array(), // wc_color_key => pancake_image_url
		);
		$opts = wp_parse_args( $options, $defaults );
		$pid  = $product->get_id();

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

		// 1. Sync Parent SKU if missing (or if overwrite requested)
		$current_sku = trim( (string) $product->get_sku() );
		if ( $opts['set_sku'] && ! empty( $pancake_item['sku'] ) && ( '' === $current_sku || $current_sku === $pancake_item['code'] ) ) {
			$owner = wc_get_product_id_by_sku( $pancake_item['sku'] );
			if ( ! $owner || (int) $owner === (int) $pid ) {
				update_post_meta( $pid, '_sku', $pancake_item['sku'] );
				$result['sku_updated'] = true;
				$result['messages'][]  = 'Đã cập nhật SKU gốc: ' . $pancake_item['sku'];
			}
		}

		// Download all unique Pancake images for this product & build URL -> Attachment ID map
		$url_to_att_id   = array();
		$class_att_ids   = array(); // canonical_class_key => attachment_id
		$saved_class_map = array(); // canonical_class_key => array( 'key', 'label', 'raw', 'att_id', 'url' )

		// Download classification images first so they get descriptive captions (e.g. "Phân loại: Đỏ (DO)")
		if ( ! empty( $pancake_item['class_images'] ) ) {
			foreach ( $pancake_item['class_images'] as $c_key => $c_info ) {
				$img_url = $c_info['image'] ?? '';
				if ( ! $img_url ) {
					continue;
				}
				$caption = 'Phân loại: ' . $c_info['label'];
				$att_id  = self::sideload_pancake_image( $img_url, $pid, $product->get_name() . ' - ' . $c_info['label'], $caption );
				if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
					$url_to_att_id[ $img_url ] = $att_id;
					$class_att_ids[ $c_key ]   = $att_id;
					$full_url                  = wp_get_attachment_image_url( $att_id, 'full' );
					$saved_class_map[ $c_key ] = array(
						'key'    => $c_key,
						'raw'    => $c_info['raw'],
						'label'  => preg_replace( '/\s*\([^)]*\)\s*$/', '', $c_info['label'] ),
						'att_id' => $att_id,
						'url'    => $full_url ? $full_url : $img_url,
					);
					$result['downloaded_urls']++;
				}
			}
		}

		// Download any remaining images in all_images
		if ( ! empty( $pancake_item['all_images'] ) ) {
			foreach ( $pancake_item['all_images'] as $img_url ) {
				if ( isset( $url_to_att_id[ $img_url ] ) ) {
					continue;
				}
				$att_id = self::sideload_pancake_image( $img_url, $pid, $product->get_name() );
				if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
					$url_to_att_id[ $img_url ] = $att_id;
					$result['downloaded_urls']++;
				}
			}
		}

		// Also download any custom_class_map URLs
		if ( ! empty( $opts['custom_class_map'] ) && is_array( $opts['custom_class_map'] ) ) {
			foreach ( $opts['custom_class_map'] as $wc_ckey => $custom_url ) {
				$custom_url = trim( (string) $custom_url );
				if ( '' === $custom_url ) {
					continue;
				}
				if ( ! isset( $url_to_att_id[ $custom_url ] ) ) {
					$att_id = self::sideload_pancake_image( $custom_url, $pid, $product->get_name() . ' - ' . $wc_ckey );
					if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
						$url_to_att_id[ $custom_url ] = $att_id;
					}
				}
				if ( isset( $url_to_att_id[ $custom_url ] ) ) {
					$norm_ck = self::normalize_class_key( $wc_ckey );
					$c_att   = $url_to_att_id[ $custom_url ];
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

		$all_att_ids = array_values( array_unique( array_filter( array_values( $url_to_att_id ) ) ) );

		// 3. Set Featured Image (_thumbnail_id)
		$current_thumb = (int) $product->get_image_id();
		if ( $opts['set_thumb'] && ! empty( $all_att_ids ) && ( $current_thumb <= 0 || $opts['overwrite_images'] ) ) {
			$primary_att = $all_att_ids[0];
			set_post_thumbnail( $pid, $primary_att );
			$result['thumb_set']  = true;
			$result['messages'][] = 'Đã đặt Ảnh đại diện từ Pancake';
		}

		// 4. Set Product Gallery (_product_image_gallery) with all unique Pancake photos
		if ( $opts['set_gallery'] && ! empty( $all_att_ids ) ) {
			$existing_gallery = array_filter( array_map( 'absint', $product->get_gallery_image_ids() ) );
			$active_thumb     = (int) get_post_thumbnail_id( $pid );
			$merged_gallery   = $opts['overwrite_images'] ? $all_att_ids : array_values( array_unique( array_merge( $existing_gallery, $all_att_ids ) ) );
			// Remove primary thumbnail from gallery array if there are multiple images so it isn't duplicated
			if ( count( $merged_gallery ) > 1 && $active_thumb > 0 ) {
				$merged_gallery = array_values( array_diff( $merged_gallery, array( $active_thumb ) ) );
			}
			update_post_meta( $pid, '_product_image_gallery', implode( ',', $merged_gallery ) );
			$result['gallery_count'] = count( $merged_gallery );
		}

		// 5. Sync Variation SKUs, Stock, and Classification/Variation Images (_thumbnail_id on each product_variation)
		if ( $product->is_type( 'variable' ) ) {
			// Ensure WC color terms exist on parent product if Pancake has multiple classifications and user wants full classification support
			$children = $product->get_children();

			// Build Pancake variation lookup by (color_key + size_number) and by (size_number)
			$p_var_by_color_size = array();
			$p_var_by_size       = array();
			$p_var_by_sku        = array();
			foreach ( $pancake_item['variations'] as $pv ) {
				if ( ! empty( $pv['sku'] ) ) {
					$p_var_by_sku[ strtoupper( $pv['sku'] ) ] = $pv;
				}
				$s_num = preg_match( '/(\d+)/', (string) $pv['size'], $sm ) ? (int) $sm[1] : strtolower( trim( (string) $pv['size'] ) );
				$c_key = $pv['color_key'];
				if ( '' !== $c_key && '' !== (string) $s_num ) {
					$p_var_by_color_size[ $c_key . '__' . $s_num ] = $pv;
				}
				if ( '' !== (string) $s_num && ! isset( $p_var_by_size[ $s_num ] ) ) {
					$p_var_by_size[ $s_num ] = $pv;
				}
			}

			foreach ( $children as $child_id ) {
				$var_obj = wc_get_product( $child_id );
				if ( ! $var_obj || ! $var_obj instanceof WC_Product_Variation ) {
					continue;
				}

				$v_sku     = strtoupper( trim( (string) $var_obj->get_sku() ) );
				$wc_c_key  = self::get_wc_variation_class_key( $var_obj );
				$v_attrs   = $var_obj->get_attributes();
				$wc_s_num  = '';
				foreach ( $v_attrs as $ak => $av ) {
					if ( false !== strpos( $ak, 'size' ) || false !== strpos( $ak, 'kich-co' ) ) {
						$wc_s_num = preg_match( '/(\d+)/', (string) $av, $m ) ? (int) $m[1] : strtolower( trim( (string) $av ) );
					}
				}

				// Match this WC variation to a Pancake variation
				$matched_pv = null;
				if ( '' !== $v_sku && isset( $p_var_by_sku[ $v_sku ] ) ) {
					$matched_pv = $p_var_by_sku[ $v_sku ];
				} elseif ( '' !== $wc_c_key && '' !== (string) $wc_s_num && isset( $p_var_by_color_size[ $wc_c_key . '__' . $wc_s_num ] ) ) {
					$matched_pv = $p_var_by_color_size[ $wc_c_key . '__' . $wc_s_num ];
				} elseif ( '' === $wc_c_key && 1 === count( $pancake_item['class_images'] ) && '' !== (string) $wc_s_num && isset( $p_var_by_size[ $wc_s_num ] ) ) {
					$matched_pv = $p_var_by_size[ $wc_s_num ];
				}

				// a) Update Variation SKU if empty and matched
				if ( $opts['set_sku'] && '' === $v_sku && $matched_pv && ! empty( $matched_pv['sku'] ) ) {
					$owner = wc_get_product_id_by_sku( $matched_pv['sku'] );
					if ( ! $owner || (int) $owner === (int) $child_id ) {
						update_post_meta( $child_id, '_sku', $matched_pv['sku'] );
						$v_sku = strtoupper( $matched_pv['sku'] );
						$result['var_sku_updated']++;
					}
				}

				// b) Update Variation Stock if requested
				if ( $opts['sync_stock'] && $matched_pv ) {
					$qty = (int) $matched_pv['stock'];
					update_post_meta( $child_id, '_manage_stock', 'yes' );
					wc_update_product_stock( $child_id, $qty, 'set' );
					$result['stock_updated']++;
				}

				// c) Determine & Assign Variation Classification Image (_thumbnail_id)
				if ( $opts['set_var_img'] ) {
					$target_att_id = 0;

					// Priority 1: Custom classification map override
					if ( '' !== $wc_c_key && isset( $opts['custom_class_map'][ $wc_c_key ] ) ) {
						$c_url = $opts['custom_class_map'][ $wc_c_key ];
						if ( isset( $url_to_att_id[ $c_url ] ) ) {
							$target_att_id = $url_to_att_id[ $c_url ];
						}
					}

					// Priority 2: Exact Variation SKU image on Pancake
					if ( ! $target_att_id && '' !== $v_sku && isset( $pancake_item['var_images'][ $v_sku ] ) ) {
						$v_img_url = $pancake_item['var_images'][ $v_sku ];
						if ( isset( $url_to_att_id[ $v_img_url ] ) ) {
							$target_att_id = $url_to_att_id[ $v_img_url ];
						}
					}

					// Priority 3: Classification / Color key match (e.g. "do", "trang", "hong", "den")
					if ( ! $target_att_id && '' !== $wc_c_key && isset( $class_att_ids[ $wc_c_key ] ) ) {
						$target_att_id = $class_att_ids[ $wc_c_key ];
					}

					// Priority 4: Matched Pancake variation's color key -> its classification image
					if ( ! $target_att_id && $matched_pv && ! empty( $matched_pv['color_key'] ) && isset( $class_att_ids[ $matched_pv['color_key'] ] ) ) {
						$target_att_id = $class_att_ids[ $matched_pv['color_key'] ];
					}

					// Priority 5: Fallback for size-only variations or single classification image
					if ( ! $target_att_id && ( 1 === count( $class_att_ids ) || '' === $wc_c_key ) ) {
						$first_class_att = ! empty( $class_att_ids ) ? reset( $class_att_ids ) : ( ! empty( $all_att_ids ) ? $all_att_ids[0] : 0 );
						if ( $first_class_att > 0 ) {
							$target_att_id = $first_class_att;
						}
					}

					$existing_var_thumb = (int) get_post_meta( $child_id, '_thumbnail_id', true );
					if ( $target_att_id > 0 && ( $existing_var_thumb <= 0 || $opts['overwrite_images'] || ! empty( $opts['custom_class_map'] ) ) ) {
						update_post_meta( $child_id, '_thumbnail_id', $target_att_id );
						$result['variations_img_set']++;
					}
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

		self::save_catalog_cache( $decoded );

		$with_img   = 0;
		$with_class = 0;
		foreach ( $decoded as $item ) {
			if ( ! empty( $item['all_images'] ) ) {
				$with_img++;
			}
			if ( ! empty( $item['class_images'] ) ) {
				$with_class++;
			}
		}

		wp_send_json_success(
			array(
				'total_pancake' => count( $decoded ),
				'with_images'   => $with_img,
				'with_classes'  => $with_class,
				'message'       => sprintf( 'Đã đồng bộ %d sản phẩm từ Pancake (%d SP có ảnh, %d SP có ảnh phân loại màu).', count( $decoded ), $with_img, $with_class ),
			)
		);
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
		$match   = self::match_wc_product( $product, $maps );

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

		$catalog = self::get_pancake_catalog( false );
		$maps    = self::build_lookup_maps( $catalog );

		$matched = null;
		if ( isset( $maps['by_sku'][ $pancake_sku ] ) ) {
			$matched = $maps['by_sku'][ $pancake_sku ];
		} elseif ( isset( $maps['by_var_sku'][ $pancake_sku ] ) ) {
			$matched = $maps['by_var_sku'][ $pancake_sku ];
		} elseif ( isset( $maps['by_code'][ $pancake_sku ] ) ) {
			$matched = $maps['by_code'][ $pancake_sku ][0];
		}

		if ( ! $matched ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm nào trên Pancake có SKU/Mã: ' . $pancake_sku ) );
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

		$catalog = self::get_pancake_catalog( false );
		$maps    = self::build_lookup_maps( $catalog );
		$match   = self::match_wc_product( $product, $maps );
		if ( empty( $match['pancake'] ) ) {
			wp_send_json_error( array( 'message' => 'Sản phẩm chưa khớp với Pancake.' ) );
		}
		$p_item = $match['pancake'];

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
		$match   = self::match_wc_product( $product, $maps );
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
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		$settings   = self::get_settings();
		$catalog    = self::get_pancake_catalog( false );
		$cache_time = (int) get_option( self::CACHE_TIME_OPT, 0 );
		$maps       = self::build_lookup_maps( $catalog );

		$search_q   = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
		$filter_st  = isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : 'all';
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page   = 40;

		// Load all WooCommerce products to compute accurate stats & SKU matches
		$wc_products = function_exists( 'wc_get_products' ) ? wc_get_products(
			array(
				'status'  => array( 'publish', 'draft', 'private' ),
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'DESC',
			)
		) : array();

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

		foreach ( $wc_products as $prod ) {
			$pid        = $prod->get_id();
			$wc_sku     = (string) $prod->get_sku();
			$thumb_id   = (int) $prod->get_image_id();
			$thumb_url  = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
			$gal_ids    = array_filter( $prod->get_gallery_image_ids() );
			$match_info = self::match_wc_product( $prod, $maps );
			$p_item     = $match_info['pancake'];

			// Count variation image status on Website
			$var_total     = 0;
			$var_with_img  = 0;
			$child_skus    = array();
			if ( $prod->is_type( 'variable' ) ) {
				$children  = $prod->get_children();
				$var_total = count( $children );
				foreach ( $children as $cid ) {
					if ( (int) get_post_meta( $cid, '_thumbnail_id', true ) > 0 ) {
						$var_with_img++;
					}
					$csku = (string) get_post_meta( $cid, '_sku', true );
					if ( $csku ) {
						$child_skus[] = $csku;
					}
				}
			}

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
							$prod->get_name(),
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
				'name'          => $prod->get_name(),
				'edit_url'      => get_edit_post_link( $pid ),
				'view_url'      => get_permalink( $pid ),
				'wc_sku'        => $wc_sku,
				'thumb_id'      => $thumb_id,
				'thumb_url'     => $thumb_url,
				'gallery_count' => count( $gal_ids ),
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
		$ajax_nonce     = wp_create_nonce( 'nexxi_pancake_ajax' );
		?>
		<div class="wrap nexxi-pancake-wrap">
			<style>
				.nexxi-pancake-wrap { max-width: 1480px; margin-top: 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.nxp-hero { background: linear-gradient(135deg, #1b2a59 0%, #2b4285 100%); color: #fff; border-radius: 16px; padding: 22px 28px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; box-shadow: 0 10px 28px rgba(27,42,89,0.16); margin-bottom: 20px; }
				.nxp-hero h1 { color: #fff; font-size: 23px; font-weight: 800; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
				.nxp-hero p { margin: 0; color: rgba(255,255,255,0.86); font-size: 13.5px; }
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
					<p>Tự động quét ảnh sản phẩm từ Pancake POS đưa về Website &amp; tự động gán ảnh theo từng Phân loại / Màu sắc cho biến thể dựa trên SKU.</p>
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

			<?php if ( isset( $_GET['saved_msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong>Đã lưu cấu hình đồng bộ Pancake SKU &amp; Ảnh thành công!</strong></p></div>
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
								<label style="display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:4px;">Pancake API Key (Tùy chọn):</label>
								<input type="password" name="pancake_api_key" value="<?php echo esc_attr( $settings['pancake_api_key'] ); ?>" placeholder="Đã kết nối qua NEXXI Cloud" style="width:100%;padding:7px 10px;border-radius:7px;border:1px solid #cbd5e1;font-size:13px;">
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

			<!-- SEARCH & FILTER BAR -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nxp-filter-bar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
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
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="nxp-btn nxp-btn-outline">Xóa lọc</a>
					<?php endif; ?>
				</div>
				<div style="font-size:13px;color:#64748b;">
					Hiển thị <strong><?php echo count( $paged_rows ); ?></strong> / <strong><?php echo (int) $total_filtered; ?></strong> sản phẩm
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
				<div style="margin-top:16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
					<?php for ( $p_i = 1; $p_i <= $total_pages; $p_i++ ) :
						$url = add_query_arg(
							array(
								'page'          => self::MENU_SLUG,
								'q'             => $search_q,
								'filter_status' => $filter_st,
								'paged'         => $p_i,
							),
							admin_url( 'admin.php' )
						);
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="nxp-btn nxp-btn-sm <?php echo $p_i === $paged ? 'nxp-btn-navy' : 'nxp-btn-outline'; ?>">
							<?php echo (int) $p_i; ?>
						</a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>

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

				async function postAjax(action, data = {}) {
					const body = new URLSearchParams({ action, nonce, ...data });
					const res = await fetch(ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: body.toString()
					});
					return res.json();
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
				async function runBatchQueue(ids, label) {
					if (!ids || !ids.length) {
						alert('Không có sản phẩm nào trong danh sách cần quét.');
						return;
					}
					progBox.style.display = 'block';
					logBox.innerHTML = '';
					progTitle.textContent = label;
					const opts = getOptions();
					let done = 0;

					for (const pid of ids) {
						progCount.textContent = (done + 1) + ' / ' + ids.length;
						try {
							const json = await postAjax('nexxi_pancake_sync_single', { product_id: pid, ...opts });
							if (json.success && json.data) {
								const d = json.data;
								appendLog('✓ [#' + pid + ' - ' + (d.pancake_sku || '') + '] ' + d.name + ' → Tải ' + d.downloaded_urls + ' ảnh, gán ' + d.variations_img_set + ' biến thể phân loại.');
								updateRowUI(pid, d);
							} else {
								appendLog('✗ [#' + pid + '] ' + (json.data?.message || 'Lỗi không xác định'), true);
							}
						} catch (err) {
							appendLog('✗ [#' + pid + '] Lỗi kết nối mạng', true);
						}
						done++;
						progBar.style.width = Math.round((done / ids.length) * 100) + '%';
					}
					progTitle.textContent = '🎉 Hoàn tất! Đã xử lý ' + done + '/' + ids.length + ' sản phẩm.';
				}

				// Browser-side direct Pancake Cloud fetch fallback (if hosting firewall blocks server-side wp_remote_get)
				const cfBaseUrl = <?php echo wp_json_encode( rtrim( $settings['cf_api_url'], '/' ) ); ?>;
				async function syncViaBrowserFallback(btn) {
					if (btn) btn.textContent = '⏳ Đang tải sản phẩm từ Pancake Cloud...';
					const prodRes = await fetch(cfBaseUrl + '/api/table?table=pancake_products&offset=0&limit=5000').then(r => r.json());
					const prodRows = prodRes.rows || prodRes.data || [];
					const map = {};
					prodRows.forEach(row => {
						const pid = String(row.pancake_product_id || '');
						if (!pid) return;
						const raw = (typeof row.raw_data === 'string' ? JSON.parse(row.raw_data || '{}') : row.raw_data) || {};
						const sku = String(raw.custom_id || row.display_id || '').trim();
						const img = String(row.image_url || '').trim();
						map[pid] = {
							id: pid,
							sku: sku,
							display_id: String(row.display_id || sku).trim(),
							code: '',
							name: String(row.name || '').trim(),
							categories: Array.isArray(row.category_names) ? row.category_names : [],
							product_image: img,
							all_images: img ? [img] : [],
							class_images: {},
							var_images: {},
							variations: [],
							total_stock: 0,
							min_price: 0,
							max_price: 0
						};
					});

					for (let page = 0; page < 5; page++) {
						const offset = page * 5000;
						if (btn) btn.textContent = '⏳ Đang tải biến thể Pancake (' + offset + ')...';
						const vRes = await fetch(cfBaseUrl + '/api/table?table=pancake_product_variations&offset=' + offset + '&limit=5000').then(r => r.json());
						const vRows = vRes.rows || vRes.data || [];
						if (!vRows.length) break;
						vRows.forEach(vr => {
							const pid = String(vr.pancake_product_id || '');
							if (!pid) return;
							const vsku = String(vr.sku || vr.display_id || '').trim();
							const vimg = String(vr.image_url || '').trim();
							const price = Number(vr.retail_price || 0);
							const qty = Number(vr.available_quantity ?? vr.total_quantity ?? 0);
							const attrs = Array.isArray(vr.attributes) ? vr.attributes : [];
							if (!map[pid]) {
								const parentSku = vsku.includes('-') ? vsku.split('-')[0] : '';
								map[pid] = {
									id: pid, sku: parentSku, display_id: parentSku, code: '',
									name: String(vr.name || vr.display_name || parentSku).trim(),
									categories: [], product_image: '', all_images: [], class_images: {}, var_images: {}, variations: [],
									total_stock: 0, min_price: 0, max_price: 0
								};
							}
							const p = map[pid];
							if (!p.sku && vsku.includes('-')) p.sku = vsku.split('-')[0];
							let colorVal = '';
							let sizeVal = '';
							attrs.forEach(a => {
								const an = String(a.name || '').toLowerCase();
								const av = String(a.value || '').trim();
								if (!av) return;
								if (an.includes('màu') || an.includes('mau') || an.includes('color') || an.includes('loại')) colorVal = av;
								else if (an.includes('size') || an.includes('cỡ')) sizeVal = av;
							});
							if (!colorVal && vsku.includes('-')) {
								const pts = vsku.split('-');
								if (pts.length >= 3) { colorVal = pts[1]; if (!sizeVal) sizeVal = pts[2]; }
							}
							const cKey = colorVal.toUpperCase();
							if (vimg && vimg.startsWith('http')) {
								if (!p.all_images.includes(vimg)) p.all_images.push(vimg);
								if (!p.product_image) p.product_image = vimg;
								if (vsku) p.var_images[vsku.toUpperCase()] = vimg;
								if (cKey) {
									if (!p.class_images[cKey]) p.class_images[cKey] = { key: cKey, raw: colorVal, label: colorVal, image: vimg, skus: [] };
									if (vsku && p.class_images[cKey].skus.length < 10 && !p.class_images[cKey].skus.includes(vsku)) p.class_images[cKey].skus.push(vsku);
								}
							}
							if (qty > 0) p.total_stock += qty;
							if (price > 0) {
								if (!p.min_price || price < p.min_price) p.min_price = price;
								if (price > p.max_price) p.max_price = price;
							}
							p.variations.push({ sku: vsku, color_raw: colorVal, color_key: cKey, size: sizeVal, price, stock: Math.max(0, qty) });
						});
						if (vRows.length < 5000) break;
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

				// Check all toggle
				$('#nxpCheckAll')?.addEventListener('change', function() {
					$$('.nxp-row-check').forEach(cb => { cb.checked = this.checked; });
				});

				// 6. Single row: Quét ảnh về Web
				$$('.nxp-btn-sync-one').forEach(btn => btn.addEventListener('click', async function() {
					const pid = Number(this.dataset.id);
					const orig = this.textContent;
					this.disabled = true;
					this.textContent = '⏳ Đang tải...';
					try {
						const json = await postAjax('nexxi_pancake_sync_single', { product_id: pid, ...getOptions() });
						if (json.success && json.data) {
							updateRowUI(pid, json.data);
							this.textContent = '✓ Đã xong';
						} else {
							alert(json.data?.message || 'Lỗi đồng bộ');
							this.textContent = orig;
							this.disabled = false;
						}
					} catch (e) {
						alert('Lỗi kết nối');
						this.textContent = orig;
						this.disabled = false;
					}
				}));

				// 7. Manual link SKU button
				$$('.nxp-btn-link-sku').forEach(btn => btn.addEventListener('click', async function() {
					const pid = Number(this.dataset.id);
					const input = $('#nxp-manual-sku-' + pid);
					const sku = (input?.value || '').trim();
					if (!sku) {
						alert('Vui lòng nhập mã SKU Pancake.');
						return;
					}
					this.disabled = true;
					const json = await postAjax('nexxi_pancake_link_sku', { product_id: pid, pancake_sku: sku });
					if (json.success) {
						alert(json.data.message);
						location.reload();
					} else {
						alert(json.data?.message || 'Không tìm thấy SKU');
						this.disabled = false;
					}
				}));

				// 8. Interactive Classification Image Mapper Drawer ("🎨 Set Ảnh Phân Loại")
				$$('.nxp-btn-open-class').forEach(btn => btn.addEventListener('click', async function() {
					const pid = Number(this.dataset.id);
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

					// Build candidate choices from Pancake classifications + all Pancake images
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

					// Bind pill selection
					$$('.nxp-map-row', body).forEach(row => {
						$$('.nxp-choice-pill', row).forEach(pill => {
							pill.addEventListener('click', () => {
								$$('.nxp-choice-pill', row).forEach(p => p.classList.remove('active'));
								pill.classList.add('active');
								row.dataset.selectedUrl = pill.dataset.url;
							});
						});
					});

					// Bind save button
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
				}));
			})();
			</script>
		</div>
		<?php
	}
}

endif;

Nexxi_Pancake_Sync_Manager::init();

