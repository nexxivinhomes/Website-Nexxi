<?php
/**
 * NEXXI Web Product Manager — Tab 3 của trang "🥞 Pancake SKU".
 *
 * Chức năng:
 * 1. Quản lý toàn bộ sản phẩm trên Website: tìm kiếm / lọc / phân trang, sửa toàn bộ thông tin
 *    (SKU, tên, slug, trạng thái, danh mục, thẻ, giá, tồn kho, mô tả, ảnh đại diện, album ảnh,
 *    biến thể Màu/Size, SEO & thẻ meta), thêm mới, chuyển thùng rác / xóa vĩnh viễn.
 * 2. Xuất file CSV (UTF-8 BOM, mở trực tiếp bằng Excel / Google Sheets) chứa toàn bộ thông tin
 *    sản phẩm + biến thể + SEO.
 * 3. Nhập file CSV để sửa hàng loạt — CHỈ cập nhật những ô có giá trị khác với dữ liệu hiện tại
 *    trên Website (ô trống = giữ nguyên, nhập [XOA] để xóa trống một mục). Có chế độ kiểm tra trước.
 * 4. Xuất thẻ SEO (title, meta description, keywords, robots, canonical, OpenGraph) ra <head>
 *    của trang sản phẩm khi Website không dùng plugin SEO (Yoast / Rank Math / AIOSEO ...).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Nexxi_Web_Product_Manager' ) ) :

final class Nexxi_Web_Product_Manager {

	const PAGE_SLUG  = 'nexxi-pancake-sync';
	const NONCE      = 'nxwpm_ajax';
	const LIST_SEP   = ' | ';
	const IMPORT_DIR = 'nexxi-product-imports';

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 30 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_post_nxwpm_export', array( __CLASS__, 'handle_export' ) );
			foreach ( array( 'list', 'get', 'save', 'delete', 'bulk_status', 'import_upload', 'import_process' ) as $action ) {
				add_action( 'wp_ajax_nxwpm_' . $action, array( __CLASS__, 'ajax_' . $action ) );
			}
		}
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ), 20 );
		add_action( 'wp', array( __CLASS__, 'maybe_disable_duplicate_og' ) );
		add_action( 'wp_head', array( __CLASS__, 'output_seo_head' ), 2 );
	}

	/* ==========================================================================
	 * 1. MENU & ASSETS
	 * ========================================================================== */

	public static function register_submenu() {
		add_submenu_page( self::PAGE_SLUG, 'Đồng bộ sản phẩm Pancake theo SKU', '📦 Đồng bộ SP Pancake', 'manage_woocommerce', self::PAGE_SLUG );
		add_submenu_page( self::PAGE_SLUG, 'Đồng bộ ảnh Pancake theo SKU', '🖼️ Đồng bộ ảnh SKU', 'manage_woocommerce', 'admin.php?page=' . self::PAGE_SLUG . '&tab=images' );
		add_submenu_page( self::PAGE_SLUG, 'Quản lý sản phẩm trên Web', '🛍️ Quản lý SP trên Web', 'manage_woocommerce', 'admin.php?page=' . self::PAGE_SLUG . '&tab=web_products' );
	}

	public static function enqueue_assets() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_enqueue_media();
		$dir = get_template_directory();
		$uri = get_template_directory_uri();
		$css = '/assets/css/web-product-manager.css';
		$js  = '/assets/js/web-product-manager.js';
		wp_enqueue_style( 'nexxi-wpm', $uri . $css, array(), file_exists( $dir . $css ) ? (string) filemtime( $dir . $css ) : NEXXI_KIDS_VERSION );
		wp_enqueue_script( 'nexxi-wpm', $uri . $js, array(), file_exists( $dir . $js ) ? (string) filemtime( $dir . $js ) : NEXXI_KIDS_VERSION, true );
		wp_localize_script(
			'nexxi-wpm',
			'NXWPM',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'exportUrl'  => add_query_arg( array( 'action' => 'nxwpm_export', '_wpnonce' => wp_create_nonce( 'nxwpm_export' ) ), admin_url( 'admin-post.php' ) ),
				'categories' => self::get_category_options(),
				'labels'     => self::columns(),
				'currency'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '₫',
				'seoPlugin'  => self::seo_plugin_active(),
			)
		);
	}

	/* ==========================================================================
	 * 2. FIELD DEFINITIONS & HELPERS
	 * ========================================================================== */

	/** Cột trong file xuất / nhập (key => nhãn tiếng Việt). */
	public static function columns() {
		return array(
			'row_type'           => 'Loại dòng (product / variation)',
			'product_id'         => 'ID',
			'parent_id'          => 'ID SP cha',
			'product_type'       => 'Kiểu SP',
			'sku'                => 'Mã SKU',
			'name'               => 'Tên sản phẩm',
			'slug'               => 'Đường dẫn tĩnh (slug)',
			'status'             => 'Trạng thái (publish/draft/private)',
			'categories'         => 'Danh mục (cách nhau |, cấp con dùng >)',
			'tags'               => 'Thẻ (cách nhau |)',
			'regular_price'      => 'Giá gốc',
			'sale_price'         => 'Giá giảm',
			'manage_stock'       => 'Quản lý tồn kho (yes/no)',
			'stock_quantity'     => 'Tồn kho',
			'stock_status'       => 'Tình trạng kho (instock/outofstock)',
			'color'              => 'Màu (biến thể)',
			'size'               => 'Size (biến thể)',
			'short_description'  => 'Mô tả ngắn',
			'description'        => 'Mô tả chi tiết',
			'featured_image_url' => 'Ảnh đại diện (URL)',
			'gallery_image_urls' => 'Album ảnh (URL, cách nhau |)',
			'seo_title'          => 'SEO Title',
			'seo_description'    => 'Meta Description',
			'seo_keywords'       => 'Từ khóa SEO (Focus keywords)',
			'seo_canonical'      => 'Canonical URL',
			'seo_robots'         => 'Meta Robots (index,follow / noindex,nofollow)',
			'og_title'           => 'OpenGraph Title',
			'og_description'     => 'OpenGraph Description',
			'og_image_url'       => 'OpenGraph Image (URL)',
			'permalink'          => 'Link sản phẩm (chỉ xem)',
		);
	}

	public static function product_fields() {
		return array( 'sku', 'name', 'slug', 'status', 'categories', 'tags', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'short_description', 'description', 'featured_image_url', 'gallery_image_urls', 'seo_title', 'seo_description', 'seo_keywords', 'seo_canonical', 'seo_robots', 'og_title', 'og_description', 'og_image_url' );
	}

	public static function variation_fields() {
		return array( 'sku', 'status', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'color', 'size', 'description', 'featured_image_url' );
	}

	private static function seo_map() {
		return array(
			'seo_title'       => array( '_nexxi_seo_title', '_yoast_wpseo_title', 'rank_math_title' ),
			'seo_description' => array( '_nexxi_seo_desc', '_yoast_wpseo_metadesc', 'rank_math_description' ),
			'seo_keywords'    => array( '_nexxi_seo_keywords', '_yoast_wpseo_focuskw', 'rank_math_focus_keyword' ),
			'seo_canonical'   => array( '_nexxi_seo_canonical', '_yoast_wpseo_canonical', 'rank_math_canonical_url' ),
			'seo_robots'      => array( '_nexxi_seo_robots' ),
			'og_title'        => array( '_nexxi_og_title', '_yoast_wpseo_opengraph-title', 'rank_math_facebook_title' ),
			'og_description'  => array( '_nexxi_og_desc', '_yoast_wpseo_opengraph-description', 'rank_math_facebook_description' ),
			'og_image_url'    => array( '_nexxi_og_image', '_yoast_wpseo_opengraph-image', 'rank_math_facebook_image' ),
		);
	}

	public static function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	public static function get_seo( $id ) {
		$out = array();
		foreach ( self::seo_map() as $field => $keys ) {
			$val = '';
			foreach ( $keys as $key ) {
				$v = get_post_meta( $id, $key, true );
				if ( is_string( $v ) && '' !== trim( $v ) ) {
					$val = trim( $v );
					break;
				}
			}
			$out[ $field ] = $val;
		}
		return $out;
	}

	public static function set_seo( $id, $field, $value ) {
		$map = self::seo_map();
		if ( ! isset( $map[ $field ] ) ) {
			return;
		}
		$value = trim( (string) $value );
		if ( in_array( $field, array( 'seo_canonical', 'og_image_url' ), true ) ) {
			$value = esc_url_raw( $value );
		} elseif ( 'seo_robots' === $field ) {
			$value = self::normalize_robots( $value );
		} else {
			$value = sanitize_textarea_field( $value );
		}
		foreach ( $map[ $field ] as $key ) {
			if ( '' === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}
		if ( 'seo_robots' === $field ) {
			if ( false !== strpos( $value, 'noindex' ) ) {
				update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' );
			} else {
				delete_post_meta( $id, '_yoast_wpseo_meta-robots-noindex' );
			}
		}
	}

	private static function normalize_robots( $v ) {
		$v = strtolower( preg_replace( '/\s+/', '', (string) $v ) );
		if ( '' === $v ) {
			return '';
		}
		$index  = false !== strpos( $v, 'noindex' ) ? 'noindex' : 'index';
		$follow = false !== strpos( $v, 'nofollow' ) ? 'nofollow' : 'follow';
		return $index . ',' . $follow;
	}

	private static function normalize_status( $v, $is_variation = false ) {
		$v   = strtolower( trim( remove_accents( (string) $v ) ) );
		$map = array(
			'publish' => 'publish', 'published' => 'publish', 'hien thi' => 'publish', 'cong khai' => 'publish',
			'draft' => 'draft', 'nhap' => 'draft', 'ban nhap' => 'draft',
			'private' => 'private', 'rieng tu' => 'private', 'an' => 'private',
			'pending' => 'pending', 'cho duyet' => 'pending',
		);
		$s = isset( $map[ $v ] ) ? $map[ $v ] : '';
		if ( $is_variation && '' !== $s ) {
			$s = 'publish' === $s ? 'publish' : 'private';
		}
		return $s;
	}

	private static function normalize_stock_status( $v ) {
		$v   = strtolower( trim( remove_accents( (string) $v ) ) );
		$map = array(
			'instock' => 'instock', 'in stock' => 'instock', 'con hang' => 'instock',
			'outofstock' => 'outofstock', 'out of stock' => 'outofstock', 'het hang' => 'outofstock',
			'onbackorder' => 'onbackorder', 'dat truoc' => 'onbackorder',
		);
		return isset( $map[ $v ] ) ? $map[ $v ] : '';
	}

	private static function to_bool( $v ) {
		$v = strtolower( trim( remove_accents( (string) $v ) ) );
		return in_array( $v, array( '1', 'yes', 'y', 'true', 'co', 'x', 'on' ), true );
	}

	/** Đọc giá kiểu VN: "297.000", "297,000", "297000₫" => "297000". */
	private static function parse_price( $v ) {
		$v = trim( str_replace( array( '₫', 'đ', 'Đ', 'VND', 'vnd', ' ', "\xc2\xa0" ), '', (string) $v ) );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^\d{1,3}([.,]\d{3})+$/', $v ) ) {
			$v = str_replace( array( '.', ',' ), '', $v );
		} else {
			$v = str_replace( ',', '.', $v );
		}
		return is_numeric( $v ) ? wc_format_decimal( $v ) : '';
	}

	private static function split_list( $v, $allow_comma = false ) {
		$parts = preg_split( $allow_comma ? '/[|,\n]+/' : '/[|\n]+/', (string) $v );
		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );
	}

	private static function is_clear_token( $v ) {
		$v = strtoupper( trim( remove_accents( (string) $v ) ) );
		return in_array( $v, array( '[XOA]', '[CLEAR]', '[TRONG]', '[EMPTY]' ), true );
	}

	/** Tên danh mục dạng đường dẫn "Cha > Con". */
	private static function term_path( $term ) {
		$names = array( $term->name );
		$pid   = (int) $term->parent;
		$guard = 0;
		while ( $pid > 0 && $guard++ < 10 ) {
			$p = get_term( $pid, $term->taxonomy );
			if ( ! $p || is_wp_error( $p ) ) {
				break;
			}
			array_unshift( $names, $p->name );
			$pid = (int) $p->parent;
		}
		return implode( ' > ', $names );
	}

	private static function term_names( $id, $tax ) {
		$terms = get_the_terms( $id, $tax );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = 'product_cat' === $tax ? self::term_path( $t ) : $t->name;
		}
		sort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return implode( self::LIST_SEP, $out );
	}

	/** Chuyển danh sách tên (hoặc đường dẫn "Cha > Con") thành term IDs, tự tạo nếu chưa có. */
	private static function names_to_term_ids( $value, $tax ) {
		$ids = array();
		foreach ( self::split_list( $value, 'product_tag' === $tax ) as $item ) {
			$parent = 0;
			$tid    = 0;
			foreach ( array_map( 'trim', explode( '>', $item ) ) as $name ) {
				if ( '' === $name ) {
					continue;
				}
				$found = get_terms( array( 'taxonomy' => $tax, 'name' => $name, 'parent' => $parent, 'hide_empty' => false, 'number' => 1 ) );
				if ( ! is_wp_error( $found ) && ! empty( $found ) ) {
					$tid = (int) $found[0]->term_id;
				} else {
					$ins = wp_insert_term( $name, $tax, array( 'parent' => $parent ) );
					if ( is_wp_error( $ins ) ) {
						$tid = (int) $ins->get_error_data( 'term_exists' );
					} else {
						$tid = (int) $ins['term_id'];
					}
				}
				$parent = $tid;
			}
			if ( $tid > 0 ) {
				$ids[] = $tid;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function get_category_options() {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$out   = array();
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			$out[] = array( 'id' => (int) $t->term_id, 'path' => self::term_path( $t ), 'count' => (int) $t->count );
		}
		usort( $out, function ( $a, $b ) {
			return strnatcasecmp( $a['path'], $b['path'] );
		} );
		return $out;
	}

	/* ---------- Thuộc tính biến thể Màu / Size ---------- */

	private static function attr_kind( $key ) {
		$clean = strtolower( remove_accents( urldecode( (string) $key ) ) );
		if ( false !== strpos( $clean, 'mau' ) || false !== strpos( $clean, 'color' ) || false !== strpos( $clean, 'loai' ) ) {
			return 'color';
		}
		if ( false !== strpos( $clean, 'size' ) || false !== strpos( $clean, 'kich' ) ) {
			return 'size';
		}
		return '';
	}

	private static function get_attr_keys( $parent ) {
		$keys = array(
			'color' => array( 'key' => 'mau', 'name' => 'MÀU', 'tax' => false ),
			'size'  => array( 'key' => 'size', 'name' => 'SIZE', 'tax' => false ),
		);
		if ( $parent instanceof WC_Product ) {
			$found = array();
			foreach ( $parent->get_attributes() as $ak => $attr ) {
				$kind = self::attr_kind( $ak );
				if ( '' === $kind || isset( $found[ $kind ] ) ) {
					continue;
				}
				$found[ $kind ] = true;
				$keys[ $kind ]  = array(
					'key'  => $ak,
					'name' => is_object( $attr ) && method_exists( $attr, 'get_name' ) ? $attr->get_name() : $ak,
					'tax'  => taxonomy_exists( $ak ),
				);
			}
		}
		return $keys;
	}

	private static function get_var_attr_value( $variation, $which ) {
		foreach ( $variation->get_attributes() as $ak => $av ) {
			if ( self::attr_kind( $ak ) !== $which ) {
				continue;
			}
			$av = (string) $av;
			if ( '' !== $av && taxonomy_exists( $ak ) ) {
				$term = get_term_by( 'slug', $av, $ak );
				if ( $term ) {
					return $term->name;
				}
			}
			return $av;
		}
		return '';
	}

	private static function ensure_term( $name, $tax ) {
		$term = get_term_by( 'name', $name, $tax );
		if ( ! $term ) {
			$ins = wp_insert_term( $name, $tax );
			if ( ! is_wp_error( $ins ) ) {
				$term = get_term( $ins['term_id'], $tax );
			}
		}
		return $term && ! is_wp_error( $term ) ? $term : null;
	}

	private static function set_var_attr( $variation, $which, $value, $parent ) {
		$info  = self::get_attr_keys( $parent )[ $which ];
		$attrs = $variation->get_attributes();
		foreach ( array_keys( $attrs ) as $ak ) {
			if ( self::attr_kind( $ak ) === $which ) {
				unset( $attrs[ $ak ] );
			}
		}
		if ( '' !== $value ) {
			if ( $info['tax'] ) {
				$term                   = self::ensure_term( $value, $info['key'] );
				$attrs[ $info['key'] ] = $term ? $term->slug : sanitize_title( $value );
			} else {
				$attrs[ sanitize_title( $info['key'] ) ] = $value;
			}
		}
		$variation->set_attributes( $attrs );
	}

	/** Đảm bảo SP cha có đủ các tùy chọn Màu / Size mà các biến thể đang dùng. */
	private static function sync_parent_attrs( $parent_id ) {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) || ! class_exists( 'WC_Product_Attribute' ) ) {
			return;
		}
		$values = array( 'color' => array(), 'size' => array() );
		foreach ( $parent->get_children() as $cid ) {
			$child = wc_get_product( $cid );
			if ( ! $child ) {
				continue;
			}
			foreach ( array( 'color', 'size' ) as $which ) {
				$v = self::get_var_attr_value( $child, $which );
				if ( '' !== $v && ! in_array( strtolower( $v ), array_map( 'strtolower', $values[ $which ] ), true ) ) {
					$values[ $which ][] = $v;
				}
			}
		}
		$keys    = self::get_attr_keys( $parent );
		$attrs   = $parent->get_attributes();
		$changed = false;
		foreach ( array( 'color', 'size' ) as $pos => $which ) {
			if ( empty( $values[ $which ] ) ) {
				continue;
			}
			$info = $keys[ $which ];
			$akey = $info['tax'] ? $info['key'] : sanitize_title( $info['key'] );
			if ( isset( $attrs[ $akey ] ) && is_object( $attrs[ $akey ] ) ) {
				$attr = $attrs[ $akey ];
				if ( $info['tax'] ) {
					$ids = array_map( 'intval', (array) $attr->get_options() );
					foreach ( $values[ $which ] as $v ) {
						$term = self::ensure_term( $v, $info['key'] );
						if ( $term && ! in_array( (int) $term->term_id, $ids, true ) ) {
							$ids[]   = (int) $term->term_id;
							$changed = true;
						}
					}
					$attr->set_options( $ids );
					wp_set_object_terms( $parent_id, $ids, $info['key'] );
				} else {
					$opts  = (array) $attr->get_options();
					$lower = array_map( 'strtolower', $opts );
					foreach ( $values[ $which ] as $v ) {
						if ( ! in_array( strtolower( $v ), $lower, true ) ) {
							$opts[]  = $v;
							$lower[] = strtolower( $v );
							$changed = true;
						}
					}
					$attr->set_options( $opts );
				}
				if ( ! $attr->get_variation() ) {
					$attr->set_variation( true );
					$changed = true;
				}
				$attrs[ $akey ] = $attr;
			} else {
				$attr = new WC_Product_Attribute();
				$attr->set_id( 0 );
				$attr->set_name( $info['name'] );
				$attr->set_options( $values[ $which ] );
				$attr->set_position( $pos );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attrs[ $akey ] = $attr;
				$changed        = true;
			}
		}
		if ( $changed ) {
			$parent->set_attributes( $attrs );
			$parent->save();
		}
	}

	/* ---------- Ảnh ---------- */

	/** Trả về attachment ID từ ID / URL (tìm trong thư viện trước, nếu không có thì tải về). */
	private static function resolve_image( $value, $parent_id = 0, $title = '' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return 'attachment' === get_post_type( (int) $value ) ? (int) $value : new WP_Error( 'nxwpm_img', 'Không tìm thấy ảnh ID ' . $value );
		}
		$url = esc_url_raw( $value );
		if ( '' === $url ) {
			return new WP_Error( 'nxwpm_img', 'URL ảnh không hợp lệ: ' . $value );
		}
		$aid = attachment_url_to_postid( $url );
		if ( ! $aid ) {
			$aid = attachment_url_to_postid( preg_replace( '~-(\d+x\d+|scaled)(?=\.[a-z0-9]+$)~i', '', $url ) );
		}
		if ( $aid ) {
			return (int) $aid;
		}
		if ( class_exists( 'Nexxi_Pancake_Sync_Manager_V4' ) && method_exists( 'Nexxi_Pancake_Sync_Manager_V4', 'sideload_pancake_image' ) ) {
			$res = Nexxi_Pancake_Sync_Manager_V4::sideload_pancake_image( $url, $parent_id, $title );
			if ( ! is_wp_error( $res ) && (int) $res > 0 ) {
				return (int) $res;
			}
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$res = media_sideload_image( $url, $parent_id, $title, 'id' );
		return is_wp_error( $res ) ? $res : (int) $res;
	}

	private static function image_url( $aid, $size = 'full' ) {
		$aid = (int) $aid;
		if ( $aid <= 0 ) {
			return '';
		}
		if ( 'full' === $size ) {
			$u = wp_get_attachment_url( $aid );
		} else {
			$u = wp_get_attachment_image_url( $aid, $size );
		}
		return $u ? $u : '';
	}

	/* ==========================================================================
	 * 3. READ / WRITE PRODUCT DATA
	 * ========================================================================== */

	/** Một dòng dữ liệu phẳng (dùng cho file xuất & so sánh khi nhập). */
	public static function get_row( $product ) {
		$is_var = $product->is_type( 'variation' );
		$id     = $product->get_id();
		$row    = array_fill_keys( array_keys( self::columns() ), '' );
		$ms     = $product->get_manage_stock( 'edit' );
		$qty    = $product->get_stock_quantity( 'edit' );

		$row['row_type']           = $is_var ? 'variation' : 'product';
		$row['product_id']         = (string) $id;
		$row['parent_id']          = $is_var ? (string) $product->get_parent_id() : '';
		$row['product_type']       = $product->get_type();
		$row['sku']                = (string) $product->get_sku( 'edit' );
		$row['name']               = (string) $product->get_name( 'edit' );
		$row['status']             = (string) $product->get_status( 'edit' );
		$row['regular_price']      = (string) $product->get_regular_price( 'edit' );
		$row['sale_price']         = (string) $product->get_sale_price( 'edit' );
		$row['manage_stock']       = ( true === $ms || 'yes' === $ms ) ? 'yes' : 'no';
		$row['stock_quantity']     = null === $qty || '' === $qty ? '' : (string) (int) $qty;
		$row['stock_status']       = (string) $product->get_stock_status( 'edit' );
		$row['description']        = (string) $product->get_description( 'edit' );
		$row['featured_image_url'] = self::image_url( $product->get_image_id( 'edit' ) );
		$row['permalink']          = (string) get_permalink( $is_var ? $product->get_parent_id() : $id );

		if ( $is_var ) {
			$row['color'] = self::get_var_attr_value( $product, 'color' );
			$row['size']  = self::get_var_attr_value( $product, 'size' );
		} else {
			$row['slug']              = (string) $product->get_slug( 'edit' );
			$row['categories']        = self::term_names( $id, 'product_cat' );
			$row['tags']              = self::term_names( $id, 'product_tag' );
			$row['short_description'] = (string) $product->get_short_description( 'edit' );
			$urls                     = array();
			foreach ( $product->get_gallery_image_ids( 'edit' ) as $gid ) {
				$u = self::image_url( $gid );
				if ( $u ) {
					$urls[] = $u;
				}
			}
			$row['gallery_image_urls'] = implode( self::LIST_SEP, $urls );
			$row                       = array_merge( $row, self::get_seo( $id ) );
		}
		return $row;
	}

	/** Chuẩn hóa giá trị để so sánh "có thay đổi hay không". */
	private static function norm( $field, $v ) {
		$v = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $v ) );
		switch ( $field ) {
			case 'regular_price':
			case 'sale_price':
				$p = self::parse_price( $v );
				return '' === $p ? '' : (string) (float) $p;
			case 'stock_quantity':
				return '' === $v ? '' : (string) (int) $v;
			case 'manage_stock':
				return self::to_bool( $v ) ? 'yes' : 'no';
			case 'stock_status':
				return self::normalize_stock_status( $v );
			case 'status':
				return self::normalize_status( $v );
			case 'seo_robots':
				return self::normalize_robots( $v );
			case 'categories':
			case 'tags':
				$parts = self::split_list( $v, 'tags' === $field );
				$parts = array_map( function ( $p ) {
					return preg_replace( '/\s*>\s*/', ' > ', mb_strtolower( $p ) );
				}, $parts );
				sort( $parts, SORT_NATURAL );
				return implode( '|', $parts );
			case 'gallery_image_urls':
				return implode( '|', self::split_list( $v ) );
			case 'description':
			case 'short_description':
				return preg_replace( '/[ \t]+/', ' ', preg_replace( "/\n{2,}/", "\n", $v ) );
		}
		return $v;
	}

	/**
	 * Gán danh sách trường cho 1 sản phẩm / biến thể và lưu lại.
	 *
	 * @return array{errors: string[], applied: string[]}
	 */
	public static function apply_fields( $product, array $vals, $sync_parent = true ) {
		$errors       = array();
		$applied      = array();
		$seo          = array();
		$is_var       = $product->is_type( 'variation' );
		$id           = $product->get_id();
		$parent       = $is_var && $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$attr_changed = false;

		foreach ( $vals as $f => $v ) {
			$v = is_scalar( $v ) ? (string) $v : '';
			$t = trim( $v );
			try {
				switch ( $f ) {
					case 'sku':
						if ( '' !== $t ) {
							$owner = (int) wc_get_product_id_by_sku( $t );
							if ( $owner && $owner !== (int) $id ) {
								$errors[] = 'Mã SKU "' . $t . '" đã được dùng cho sản phẩm #' . $owner;
								continue 2;
							}
						}
						$product->set_sku( $t );
						break;
					case 'name':
						if ( '' === $t ) {
							$errors[] = 'Tên sản phẩm không được để trống';
							continue 2;
						}
						$product->set_name( sanitize_text_field( $t ) );
						break;
					case 'slug':
						$product->set_slug( sanitize_title( $t ) );
						break;
					case 'status':
						$s = self::normalize_status( $t, $is_var );
						if ( '' === $s ) {
							$errors[] = 'Trạng thái không hợp lệ: "' . $t . '" (dùng publish / draft / private)';
							continue 2;
						}
						$product->set_status( $s );
						break;
					case 'categories':
						$product->set_category_ids( self::names_to_term_ids( $t, 'product_cat' ) );
						break;
					case 'tags':
						$product->set_tag_ids( self::names_to_term_ids( $t, 'product_tag' ) );
						break;
					case 'regular_price':
					case 'sale_price':
						if ( $product->is_type( 'variable' ) ) {
							if ( '' !== $t ) {
								$errors[] = 'SP có biến thể: hãy sửa giá ở từng dòng biến thể';
							}
							continue 2;
						}
						$p = '' === $t ? '' : self::parse_price( $t );
						if ( '' !== $t && '' === $p ) {
							$errors[] = 'Giá không hợp lệ: "' . $t . '"';
							continue 2;
						}
						if ( 'regular_price' === $f ) {
							$product->set_regular_price( $p );
						} else {
							$product->set_sale_price( $p );
						}
						break;
					case 'manage_stock':
						$product->set_manage_stock( self::to_bool( $t ) );
						break;
					case 'stock_quantity':
						if ( '' === $t ) {
							$product->set_stock_quantity( null );
						} else {
							$product->set_manage_stock( true );
							$product->set_stock_quantity( (int) $t );
						}
						break;
					case 'stock_status':
						$ss = self::normalize_stock_status( $t );
						if ( '' === $ss ) {
							$errors[] = 'Tình trạng kho không hợp lệ: "' . $t . '"';
							continue 2;
						}
						$product->set_stock_status( $ss );
						break;
					case 'color':
					case 'size':
						if ( ! $is_var || ! $parent ) {
							continue 2;
						}
						self::set_var_attr( $product, $f, $t, $parent );
						$attr_changed = true;
						break;
					case 'short_description':
						$product->set_short_description( wp_kses_post( $v ) );
						break;
					case 'description':
						$product->set_description( wp_kses_post( $v ) );
						break;
					case 'featured_image_url':
						if ( '' === $t ) {
							$product->set_image_id( 0 );
						} else {
							$aid = self::resolve_image( $t, $is_var ? $product->get_parent_id() : $id, $product->get_name() );
							if ( is_wp_error( $aid ) || ! $aid ) {
								$errors[] = 'Không tải được ảnh đại diện: ' . ( is_wp_error( $aid ) ? $aid->get_error_message() : $t );
								continue 2;
							}
							$product->set_image_id( $aid );
						}
						break;
					case 'gallery_image_urls':
						$ids = array();
						foreach ( self::split_list( $t ) as $u ) {
							$aid = self::resolve_image( $u, $id, $product->get_name() );
							if ( is_wp_error( $aid ) || ! $aid ) {
								$errors[] = 'Không tải được ảnh album: ' . $u;
							} else {
								$ids[] = (int) $aid;
							}
						}
						$product->set_gallery_image_ids( array_values( array_unique( $ids ) ) );
						break;
					default:
						if ( array_key_exists( $f, self::seo_map() ) && ! $is_var ) {
							$seo[ $f ] = $v;
						}
						continue 2;
				}
				$applied[] = $f;
			} catch ( Exception $e ) {
				$errors[] = self::columns()[ $f ] . ': ' . $e->getMessage();
			}
		}

		try {
			$product->save();
			$id = $product->get_id();
		} catch ( Exception $e ) {
			$errors[] = 'Lỗi lưu sản phẩm: ' . $e->getMessage();
		}

		foreach ( $seo as $f => $v ) {
			self::set_seo( $id, $f, $v );
			$applied[] = $f;
		}

		if ( $is_var && $parent && $sync_parent ) {
			if ( $attr_changed ) {
				self::sync_parent_attrs( $parent->get_id() );
			}
			WC_Product_Variable::sync( $parent->get_id() );
			wc_delete_product_transients( $parent->get_id() );
		}
		if ( $id ) {
			wc_delete_product_transients( $id );
		}
		return array( 'errors' => $errors, 'applied' => $applied );
	}

	/** Dữ liệu đầy đủ cho cửa sổ sửa sản phẩm. */
	private static function get_editor_data( $product ) {
		$id   = $product->get_id();
		$data = self::get_row( $product );

		$data['id']                 = $id;
		$data['featured_image_id']  = (int) $product->get_image_id( 'edit' );
		$data['featured_image_thumb'] = self::image_url( $data['featured_image_id'], 'medium' );
		$data['gallery']            = array();
		foreach ( $product->get_gallery_image_ids( 'edit' ) as $gid ) {
			$data['gallery'][] = array( 'id' => (int) $gid, 'url' => self::image_url( $gid, 'thumbnail' ) );
		}
		$data['category_ids'] = array_map( 'intval', $product->get_category_ids( 'edit' ) );
		$data['view_url']     = get_permalink( $id );
		$data['wc_edit_url']  = get_edit_post_link( $id, 'raw' );
		$data['variations']   = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $cid ) {
				$v = wc_get_product( $cid );
				if ( ! $v ) {
					continue;
				}
				$vr                 = self::get_row( $v );
				$vr['id']           = (int) $cid;
				$vr['image_id']     = (int) $v->get_image_id( 'edit' );
				$vr['image_thumb']  = self::image_url( $vr['image_id'], 'thumbnail' );
				$data['variations'][] = $vr;
			}
		}
		return $data;
	}

	private static function check_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Bạn không có quyền thực hiện thao tác này.' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/* ==========================================================================
	 * 4. AJAX: LIST / GET / SAVE / DELETE
	 * ========================================================================== */

	/** Truy vấn ID sản phẩm theo bộ lọc. */
	private static function query_ids( array $f, $paged = 1, $per_page = 20, &$total = 0 ) {
		global $wpdb;
		$statuses = array( 'publish', 'draft', 'private', 'pending' );
		$status   = isset( $f['status'] ) ? sanitize_key( $f['status'] ) : '';
		$args     = array(
			'post_type'      => 'product',
			'post_status'    => in_array( $status, $statuses, true ) ? $status : $statuses,
			'posts_per_page' => $per_page,
			'paged'          => max( 1, (int) $paged ),
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => $per_page < 0,
		);
		$orderby = isset( $f['orderby'] ) ? sanitize_key( $f['orderby'] ) : '';
		if ( 'title' === $orderby ) {
			$args['orderby'] = 'title';
			$args['order']   = 'ASC';
		} elseif ( 'modified' === $orderby ) {
			$args['orderby'] = 'modified';
		}

		$q = isset( $f['q'] ) ? trim( (string) $f['q'] ) : '';
		if ( '' !== $q ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status <> 'trash' AND ( post_title LIKE %s OR ID = %d )
					 UNION
					 SELECT IF( p.post_type = 'product_variation', p.post_parent, p.ID ) FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = '_sku' AND pm.meta_value LIKE %s AND p.post_type IN ('product','product_variation')",
					$like,
					absint( $q ),
					$like
				)
			);
			$args['post__in'] = $ids ? array_map( 'intval', $ids ) : array( 0 );
		}
		if ( ! empty( $f['cat'] ) ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => absint( $f['cat'] ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( ! empty( $f['type'] ) && in_array( $f['type'], array( 'simple', 'variable' ), true ) ) {
			$args['tax_query']   = isset( $args['tax_query'] ) ? $args['tax_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['tax_query'][] = array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => sanitize_key( $f['type'] ) );
		}
		$meta = array();
		$stock = isset( $f['stock'] ) ? sanitize_key( $f['stock'] ) : '';
		if ( in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$meta[] = array( 'key' => '_stock_status', 'value' => $stock );
		}
		$extra = isset( $f['extra'] ) ? sanitize_key( $f['extra'] ) : '';
		if ( 'no_image' === $extra ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_thumbnail_id', 'value' => array( '', '0' ), 'compare' => 'IN' ),
			);
		} elseif ( 'no_seo' === $extra ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => '_nexxi_seo_desc', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_nexxi_seo_desc', 'value' => '' ),
			);
		} elseif ( 'no_sku' === $extra ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => '_sku', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_sku', 'value' => '' ),
			);
		}
		if ( $meta ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$query = new WP_Query( $args );
		$total = (int) $query->found_posts;
		return array_map( 'intval', $query->posts );
	}

	private static function filters_from_request( $src ) {
		$out = array();
		foreach ( array( 'q', 'cat', 'status', 'stock', 'type', 'extra', 'orderby' ) as $k ) {
			$out[ $k ] = isset( $src[ $k ] ) ? sanitize_text_field( wp_unslash( $src[ $k ] ) ) : '';
		}
		return $out;
	}

	public static function ajax_list() {
		self::check_ajax();
		$filters  = self::filters_from_request( $_POST );
		$paged    = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? min( 200, max( 10, absint( $_POST['per_page'] ) ) ) : 20;
		$total    = 0;
		$ids      = self::query_ids( $filters, $paged, $per_page, $total );
		$rows     = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$seo      = self::get_seo( $id );
			$children = $p->is_type( 'variable' ) ? $p->get_children() : array();
			$stock    = 0;
			$managed  = false;
			if ( $children ) {
				foreach ( $children as $cid ) {
					$q = get_post_meta( $cid, '_stock', true );
					if ( '' !== $q && null !== $q ) {
						$managed = true;
						$stock  += max( 0, (int) $q );
					}
				}
			} elseif ( $p->get_manage_stock() ) {
				$managed = true;
				$stock   = (int) $p->get_stock_quantity();
			}
			$cats = array();
			foreach ( (array) get_the_terms( $id, 'product_cat' ) as $t ) {
				if ( $t && ! is_wp_error( $t ) ) {
					$cats[] = $t->name;
				}
			}
			$rows[] = array(
				'id'           => $id,
				'name'         => $p->get_name(),
				'sku'          => $p->get_sku(),
				'type'         => $p->get_type(),
				'status'       => $p->get_status(),
				'thumb'        => self::image_url( $p->get_image_id(), 'thumbnail' ),
				'gallery'      => count( $p->get_gallery_image_ids() ),
				'price_html'   => wp_kses_post( $p->get_price_html() ),
				'stock_status' => $p->get_stock_status(),
				'stock'        => $managed ? $stock : null,
				'variations'   => count( $children ),
				'categories'   => implode( ', ', $cats ),
				'seo_ok'       => '' !== $seo['seo_title'] && '' !== $seo['seo_description'],
				'seo_partial'  => '' !== $seo['seo_title'] || '' !== $seo['seo_description'],
				'view_url'     => get_permalink( $id ),
				'modified'     => get_the_modified_date( 'd/m/Y H:i', $id ),
			);
		}
		$counts = wp_count_posts( 'product' );
		wp_send_json_success(
			array(
				'rows'     => $rows,
				'total'    => $total,
				'paged'    => $paged,
				'pages'    => (int) ceil( $total / $per_page ),
				'per_page' => $per_page,
				'counts'   => array(
					'all'     => (int) $counts->publish + (int) $counts->draft + (int) $counts->private + (int) $counts->pending,
					'publish' => (int) $counts->publish,
					'draft'   => (int) $counts->draft,
					'private' => (int) $counts->private,
					'trash'   => (int) $counts->trash,
				),
			)
		);
	}

	public static function ajax_get() {
		self::check_ajax();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p || $p->is_type( 'variation' ) ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm #' . $id ) );
		}
		wp_send_json_success( self::get_editor_data( $p ) );
	}

	public static function ajax_save() {
		self::check_ajax();
		$GLOBALS['nexxi_bulk_syncing'] = true;
		$d = isset( $_POST['payload'] ) ? json_decode( wp_unslash( $_POST['payload'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $d ) ) {
			wp_send_json_error( array( 'message' => 'Dữ liệu gửi lên không hợp lệ.' ) );
		}
		$id      = isset( $d['id'] ) ? absint( $d['id'] ) : 0;
		$created = false;
		if ( $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variation' ) ) {
				wp_send_json_error( array( 'message' => 'Không tìm thấy sản phẩm #' . $id ) );
			}
		} else {
			$name = trim( (string) ( $d['name'] ?? '' ) );
			if ( '' === $name ) {
				wp_send_json_error( array( 'message' => 'Vui lòng nhập tên sản phẩm.' ) );
			}
			$product = ( isset( $d['product_type'] ) && 'variable' === $d['product_type'] ) ? new WC_Product_Variable() : new WC_Product_Simple();
			$product->set_name( sanitize_text_field( $name ) );
			$product->set_status( 'draft' );
			$id      = $product->save();
			$created = true;
		}

		$fields = array();
		foreach ( self::product_fields() as $f ) {
			if ( array_key_exists( $f, $d ) ) {
				$fields[ $f ] = $d[ $f ];
			}
		}
		$res    = self::apply_fields( $product, $fields );
		$errors = $res['errors'];

		$product = wc_get_product( $id );
		if ( $product && $product->is_type( 'variable' ) ) {
			foreach ( (array) ( $d['deleted_variations'] ?? array() ) as $vid ) {
				$v = wc_get_product( absint( $vid ) );
				if ( $v && $v->is_type( 'variation' ) && (int) $v->get_parent_id() === (int) $id ) {
					$v->delete( true );
				}
			}
			foreach ( (array) ( $d['variations'] ?? array() ) as $idx => $vd ) {
				if ( ! is_array( $vd ) ) {
					continue;
				}
				$vid = absint( $vd['id'] ?? 0 );
				if ( $vid ) {
					$v = wc_get_product( $vid );
					if ( ! $v || (int) $v->get_parent_id() !== (int) $id ) {
						continue;
					}
				} else {
					$v = new WC_Product_Variation();
					$v->set_parent_id( $id );
					$v->set_status( 'publish' );
				}
				$vf = array();
				foreach ( self::variation_fields() as $f ) {
					if ( array_key_exists( $f, $vd ) ) {
						$vf[ $f ] = $vd[ $f ];
					}
				}
				$vr = self::apply_fields( $v, $vf, false );
				foreach ( $vr['errors'] as $er ) {
					$errors[] = 'Biến thể ' . ( $vd['sku'] ?? ( '#' . ( $idx + 1 ) ) ) . ': ' . $er;
				}
			}
			self::sync_parent_attrs( $id );
			WC_Product_Variable::sync( $id );
			wc_delete_product_transients( $id );
		}
		clean_post_cache( $id );
		$product = wc_get_product( $id );
		wp_send_json_success(
			array(
				'created' => $created,
				'errors'  => $errors,
				'product' => $product ? self::get_editor_data( $product ) : null,
				'message' => ( $created ? 'Đã tạo sản phẩm mới #' . $id : 'Đã lưu sản phẩm #' . $id ) . ( $errors ? ' (có ' . count( $errors ) . ' cảnh báo)' : '' ),
			)
		);
	}

	public static function ajax_delete() {
		self::check_ajax();
		$ids   = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		$force = ! empty( $_POST['force'] ) && '1' === (string) $_POST['force'];
		$done  = 0;
		foreach ( $ids as $id ) {
			if ( 'product' !== get_post_type( $id ) ) {
				continue;
			}
			if ( $force ) {
				$p = wc_get_product( $id );
				if ( $p ) {
					foreach ( $p->get_children() as $cid ) {
						wp_delete_post( (int) $cid, true );
					}
				}
				wc_delete_product_transients( $id );
				if ( wp_delete_post( $id, true ) ) {
					$done++;
				}
			} elseif ( wp_trash_post( $id ) ) {
				$done++;
			}
		}
		wp_send_json_success(
			array(
				'done'    => $done,
				'message' => $force ? 'Đã xóa vĩnh viễn ' . $done . ' sản phẩm (kèm toàn bộ biến thể).' : 'Đã chuyển ' . $done . ' sản phẩm vào Thùng rác (có thể khôi phục trong Sản phẩm → Thùng rác).',
			)
		);
	}

	public static function ajax_bulk_status() {
		self::check_ajax();
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		$status = self::normalize_status( isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '' );
		if ( ! $status ) {
			wp_send_json_error( array( 'message' => 'Trạng thái không hợp lệ.' ) );
		}
		$done = 0;
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p && ! $p->is_type( 'variation' ) ) {
				$p->set_status( $status );
				$p->save();
				$done++;
			}
		}
		wp_send_json_success( array( 'message' => 'Đã đổi trạng thái ' . $done . ' sản phẩm.' ) );
	}

	/* ==========================================================================
	 * 5. EXPORT CSV
	 * ========================================================================== */

	public static function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'nxwpm_export' ) ) {
			wp_die( 'Không có quyền tải file.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$scope    = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
		$with_var = ! isset( $_GET['variations'] ) || '1' === (string) $_GET['variations'];
		if ( 'selected' === $scope && ! empty( $_GET['ids'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) );
		} else {
			$filters = 'filtered' === $scope ? self::filters_from_request( $_GET ) : array();
			$total   = 0;
			$ids     = self::query_ids( $filters, 1, -1, $total );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="nexxi-san-pham-' . gmdate( 'Ymd-His', time() + 7 * HOUR_IN_SECONDS ) . '.csv"' );
		$out  = fopen( 'php://output', 'w' );
		$cols = self::columns();
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array_keys( $cols ) );
		// Dòng mô tả tiếng Việt (bắt đầu bằng #) — bị bỏ qua khi nhập lại file.
		$labels             = array_values( $cols );
		$labels[0]          = '# ' . $labels[0];
		fputcsv( $out, $labels );

		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || $p->is_type( 'variation' ) ) {
				continue;
			}
			fputcsv( $out, array_values( self::get_row( $p ) ) );
			if ( $with_var && $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $cid ) {
					$v = wc_get_product( $cid );
					if ( $v ) {
						fputcsv( $out, array_values( self::get_row( $v ) ) );
					}
				}
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ==========================================================================
	 * 6. IMPORT CSV (DIFF-ONLY)
	 * ========================================================================== */

	private static function import_dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . self::IMPORT_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
		}
		return $dir;
	}

	private static function import_file( $token ) {
		return self::import_dir() . '/' . preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $token ) ) . '.json';
	}

	public static function ajax_import_upload() {
		self::check_ajax();
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => 'Vui lòng chọn file CSV.' ) );
		}
		$name = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'xlsx', 'xls' ), true ) ) {
			wp_send_json_error( array( 'message' => 'File Excel (.xlsx) chưa được hỗ trợ trực tiếp. Trong Excel chọn File → Save As → "CSV UTF-8 (Comma delimited) (*.csv)" rồi tải lên lại.' ) );
		}
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Chỉ hỗ trợ file .csv' ) );
		}
		$content = file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.Security.ValidatedSanitizedInput
		if ( false === $content || '' === trim( $content ) ) {
			wp_send_json_error( array( 'message' => 'File rỗng.' ) );
		}
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		} elseif ( 0 === strpos( $content, "\xFF\xFE" ) || 0 === strpos( $content, "\xFE\xFF" ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'UTF-16' );
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$conv    = function_exists( 'iconv' ) ? @iconv( 'CP1258', 'UTF-8//IGNORE', $content ) : false; // phpcs:ignore
			$content = $conv ? $conv : mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}

		$first_line = strtok( $content, "\n" );
		$delims     = array( ',' => substr_count( $first_line, ',' ), ';' => substr_count( $first_line, ';' ), "\t" => substr_count( $first_line, "\t" ) );
		arsort( $delims );
		$delim = key( $delims );

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );

		$cols      = self::columns();
		$label_map = array();
		foreach ( $cols as $k => $label ) {
			$label_map[ mb_strtolower( trim( $label ) ) ] = $k;
			$label_map[ $k ]                              = $k;
		}
		$header = fgetcsv( $fh, 0, $delim );
		$keys   = array();
		$known  = array();
		$unknown = array();
		foreach ( (array) $header as $i => $h ) {
			$h = mb_strtolower( trim( (string) $h ) );
			$h = ltrim( $h, "# \t" );
			if ( isset( $label_map[ $h ] ) ) {
				$keys[ $i ] = $label_map[ $h ];
				$known[]    = $label_map[ $h ];
			} else {
				$keys[ $i ] = '';
				if ( '' !== $h ) {
					$unknown[] = $h;
				}
			}
		}
		if ( ! in_array( 'product_id', $known, true ) && ! in_array( 'sku', $known, true ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( array( 'message' => 'File thiếu cột "product_id" hoặc "sku" để nhận diện sản phẩm. Hãy dùng file được tải về từ chức năng "Tải file" rồi chỉnh sửa.' ) );
		}

		$rows = array();
		$line = 1;
		while ( false !== ( $cells = fgetcsv( $fh, 0, $delim ) ) ) {
			$line++;
			if ( ! is_array( $cells ) || ( 1 === count( $cells ) && '' === trim( (string) $cells[0] ) ) ) {
				continue;
			}
			$r = array( '_line' => $line );
			foreach ( $cells as $i => $c ) {
				if ( ! empty( $keys[ $i ] ) ) {
					$r[ $keys[ $i ] ] = (string) $c;
				}
			}
			if ( isset( $r['row_type'] ) && 0 === strpos( ltrim( $r['row_type'] ), '#' ) ) {
				continue;
			}
			if ( '' === trim( (string) ( $r['product_id'] ?? '' ) ) && '' === trim( (string) ( $r['sku'] ?? '' ) ) ) {
				continue;
			}
			$rows[] = $r;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $rows ) {
			wp_send_json_error( array( 'message' => 'Không tìm thấy dòng dữ liệu nào trong file.' ) );
		}

		// Dọn file nhập cũ hơn 1 ngày.
		foreach ( (array) glob( self::import_dir() . '/*.json' ) as $old ) {
			if ( $old && filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				@unlink( $old ); // phpcs:ignore
			}
		}
		$token = strtolower( wp_generate_password( 20, false, false ) );
		file_put_contents( self::import_file( $token ), wp_json_encode( array( 'user' => get_current_user_id(), 'name' => $name, 'rows' => $rows ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		wp_send_json_success(
			array(
				'token'   => $token,
				'total'   => count( $rows ),
				'columns' => array_values( array_unique( $known ) ),
				'unknown' => $unknown,
			)
		);
	}

	public static function ajax_import_process() {
		self::check_ajax();
		$GLOBALS['nexxi_bulk_syncing'] = true;
		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? min( 100, max( 1, absint( $_POST['limit'] ) ) ) : 20;
		$dry    = ! empty( $_POST['dry_run'] ) && '1' === (string) $_POST['dry_run'];
		$file   = self::import_file( $token );
		if ( '' === $token || ! file_exists( $file ) ) {
			wp_send_json_error( array( 'message' => 'Phiên nhập file đã hết hạn, vui lòng tải file lên lại.' ) );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || empty( $data['rows'] ) || (int) $data['user'] !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => 'Dữ liệu nhập không hợp lệ.' ) );
		}
		$rows    = $data['rows'];
		$total   = count( $rows );
		$slice   = array_slice( $rows, $offset, $limit );
		$results = array();
		$parents = array();
		foreach ( $slice as $r ) {
			$res = self::import_row( $r, $dry );
			if ( ! empty( $res['parent_sync'] ) ) {
				$parents[ $res['parent_sync'] ] = ! empty( $res['attr_changed'] ) || ! empty( $parents[ $res['parent_sync'] ] );
			}
			unset( $res['parent_sync'], $res['attr_changed'] );
			$results[] = $res;
		}
		foreach ( $parents as $pid => $attr_changed ) {
			if ( $attr_changed ) {
				self::sync_parent_attrs( $pid );
			}
			WC_Product_Variable::sync( $pid );
			wc_delete_product_transients( $pid );
		}
		$next = $offset + count( $slice );
		$done = $next >= $total;
		if ( $done && ! $dry ) {
			@unlink( $file ); // phpcs:ignore
		}
		wp_send_json_success( array( 'results' => $results, 'next' => $next, 'total' => $total, 'done' => $done ) );
	}

	private static function short( $v, $len = 70 ) {
		$v = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $v ) ) );
		return mb_strlen( $v ) > $len ? mb_substr( $v, 0, $len ) . '…' : $v;
	}

	private static function import_row( array $r, $dry ) {
		$line    = (int) ( $r['_line'] ?? 0 );
		$id      = absint( $r['product_id'] ?? 0 );
		$sku     = trim( (string) ( $r['sku'] ?? '' ) );
		$product = null;
		if ( $id ) {
			$product = wc_get_product( $id );
		}
		if ( ! $product && '' !== $sku ) {
			$pid     = (int) wc_get_product_id_by_sku( $sku );
			$product = $pid ? wc_get_product( $pid ) : null;
		}
		if ( ! $product ) {
			return array(
				'line'   => $line,
				'status' => 'notfound',
				'label'  => ( $id ? '#' . $id . ' ' : '' ) . ( $sku ? '[' . $sku . ']' : '' ),
				'message' => 'Không tìm thấy sản phẩm trên Website (bỏ qua).',
			);
		}
		$is_var = $product->is_type( 'variation' );
		$cur    = self::get_row( $product );
		$label  = $is_var
			? '↳ Biến thể #' . $product->get_id() . ' [' . $cur['sku'] . '] ' . trim( $cur['color'] . ' / ' . $cur['size'], ' /' )
			: '#' . $product->get_id() . ' [' . $cur['sku'] . '] ' . self::short( $cur['name'], 60 );
		$labels  = self::columns();
		$changes = array();
		$vals    = array();
		foreach ( $is_var ? self::variation_fields() : self::product_fields() as $f ) {
			if ( ! array_key_exists( $f, $r ) ) {
				continue;
			}
			$raw = (string) $r[ $f ];
			if ( '' === trim( $raw ) ) {
				continue; // Ô trống = giữ nguyên.
			}
			$new = self::is_clear_token( $raw ) ? '' : $raw;
			if ( self::norm( $f, $new ) === self::norm( $f, $cur[ $f ] ) ) {
				continue; // Không thay đổi.
			}
			if ( in_array( $f, array( 'regular_price', 'sale_price' ), true ) && $product->is_type( 'variable' ) ) {
				continue;
			}
			$vals[ $f ] = $new;
			$changes[]  = array(
				'field' => preg_replace( '/\s*\(.*\)$/', '', $labels[ $f ] ),
				'old'   => self::short( $cur[ $f ] ),
				'new'   => '' === $new ? '(xóa trống)' : self::short( $new ),
			);
		}
		$base = array( 'line' => $line, 'label' => $label, 'changes' => $changes );
		if ( ! $changes ) {
			return $base + array( 'status' => 'unchanged' );
		}
		if ( $dry ) {
			return $base + array( 'status' => 'will_update' );
		}
		$res = self::apply_fields( $product, $vals, false );
		return $base + array(
			'status'       => $res['errors'] ? ( $res['applied'] ? 'partial' : 'error' ) : 'updated',
			'errors'       => $res['errors'],
			'parent_sync'  => $is_var ? (int) $product->get_parent_id() : 0,
			'attr_changed' => $is_var && ( isset( $vals['color'] ) || isset( $vals['size'] ) ),
		);
	}

	/* ==========================================================================
	 * 7. FRONTEND SEO OUTPUT
	 * ========================================================================== */

	public static function filter_document_title( $title ) {
		if ( ! is_singular( 'product' ) || self::seo_plugin_active() ) {
			return $title;
		}
		$t = trim( (string) get_post_meta( get_queried_object_id(), '_nexxi_seo_title', true ) );
		return '' !== $t ? wp_strip_all_tags( $t ) : $title;
	}

	public static function maybe_disable_duplicate_og() {
		if ( is_singular( 'product' ) && ! self::seo_plugin_active() ) {
			add_filter( 'jetpack_enable_open_graph', '__return_false', 99 );
			add_filter( 'jetpack_disable_twitter_cards', '__return_true', 99 );
		}
	}

	public static function output_seo_head() {
		if ( ! is_singular( 'product' ) || self::seo_plugin_active() ) {
			return;
		}
		$id      = get_queried_object_id();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return;
		}
		$seo  = self::get_seo( $id );
		$desc = $seo['seo_description'];
		if ( '' === $desc ) {
			$desc = wp_trim_words( wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ), 32, '…' );
		}
		$title     = '' !== $seo['seo_title'] ? $seo['seo_title'] : $product->get_name();
		$canonical = '' !== $seo['seo_canonical'] ? $seo['seo_canonical'] : get_permalink( $id );
		$og_img    = '' !== $seo['og_image_url'] ? $seo['og_image_url'] : self::image_url( $product->get_image_id() );

		echo "\n<!-- NEXXI SEO -->\n";
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( '' !== $seo['seo_keywords'] ) {
			echo '<meta name="keywords" content="' . esc_attr( $seo['seo_keywords'] ) . '">' . "\n";
		}
		if ( '' !== $seo['seo_robots'] ) {
			echo '<meta name="robots" content="' . esc_attr( $seo['seo_robots'] ) . '">' . "\n";
		}
		if ( '' !== $seo['seo_canonical'] ) {
			remove_action( 'wp_head', 'rel_canonical' );
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="product">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( '' !== $seo['og_title'] ? $seo['og_title'] : $title ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( '' !== $seo['og_description'] ? $seo['og_description'] : $desc ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $canonical ) . '">' . "\n";
		if ( $og_img ) {
			echo '<meta property="og:image" content="' . esc_url( $og_img ) . '">' . "\n";
		}
		$price = $product->get_price();
		if ( '' !== (string) $price ) {
			echo '<meta property="product:price:amount" content="' . esc_attr( $price ) . '">' . "\n";
			echo '<meta property="product:price:currency" content="' . esc_attr( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND' ) . '">' . "\n";
		}
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		echo "<!-- /NEXXI SEO -->\n";
	}

	/* ==========================================================================
	 * 8. TAB UI (khung HTML — dữ liệu nạp qua AJAX, JS ở assets/js/web-product-manager.js)
	 * ========================================================================== */

	public static function render_tab() {
		$cats = self::get_category_options();
		?>
		<div class="nxwpm" id="nxwpmRoot">
			<div class="nxwpm-stats" id="nxwpmStats">
				<div class="nxp-stat-card"><div class="num" data-stat="all">…</div><div class="lbl">Tổng sản phẩm trên Website</div></div>
				<div class="nxp-stat-card ok"><div class="num" data-stat="publish">…</div><div class="lbl">Đang hiển thị</div></div>
				<div class="nxp-stat-card"><div class="num" data-stat="draft">…</div><div class="lbl">Bản nháp</div></div>
				<div class="nxp-stat-card"><div class="num" data-stat="private">…</div><div class="lbl">Riêng tư (ẩn)</div></div>
				<div class="nxp-stat-card accent"><div class="num" data-stat="trash">…</div><div class="lbl">Trong thùng rác</div></div>
			</div>

			<div class="nxwpm-grid">
				<div class="nxp-card">
					<h2>⬇️ Tải về file toàn bộ thông tin sản phẩm</h2>
					<p class="nxwpm-muted">File <strong>.csv (UTF-8)</strong> mở trực tiếp bằng Excel / Google Sheets, gồm: ID, SKU, tên, slug, trạng thái, danh mục, thẻ, giá, tồn kho, Màu/Size biến thể, mô tả ngắn, mô tả chi tiết, ảnh đại diện, album ảnh, SEO Title, Meta Description, từ khóa, Canonical, Robots, OpenGraph…</p>
					<label class="nxwpm-check"><input type="checkbox" id="nxwpmExportVariations" checked> Kèm dòng biến thể (SKU phân loại, Màu, Size, giá, tồn kho)</label>
					<div class="nxwpm-actions">
						<button type="button" class="nxp-btn nxp-btn-navy" data-export="all">⬇️ Tải toàn bộ sản phẩm</button>
						<button type="button" class="nxp-btn nxp-btn-outline" data-export="filtered">⬇️ Tải theo bộ lọc hiện tại</button>
						<button type="button" class="nxp-btn nxp-btn-outline" data-export="selected">⬇️ Tải SP đã chọn (<span class="nxwpm-sel-count">0</span>)</button>
					</div>
				</div>
				<div class="nxp-card">
					<h2>⬆️ Chỉnh sửa hàng loạt bằng file</h2>
					<p class="nxwpm-muted">Tải file về → sửa trong Excel → lưu dạng <strong>CSV UTF-8</strong> → tải lên. Hệ thống so sánh từng ô với dữ liệu hiện tại và <strong>chỉ cập nhật những mục có thay đổi</strong>. Ô để trống = giữ nguyên; nhập <code>[XOA]</code> để xóa trống một mục. Nhận diện theo cột <code>product_id</code> (hoặc <code>sku</code>).</p>
					<div class="nxwpm-actions">
						<input type="file" id="nxwpmImportFile" accept=".csv,text/csv">
						<button type="button" class="nxp-btn nxp-btn-outline" id="nxwpmBtnDryRun">🔍 Kiểm tra thay đổi (chưa lưu)</button>
						<button type="button" class="nxp-btn nxp-btn-primary" id="nxwpmBtnImport">⬆️ Cập nhật thay đổi lên Website</button>
					</div>
				</div>
			</div>

			<div class="nxp-progress-box" id="nxwpmProgress">
				<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">
					<strong id="nxwpmProgTitle">Đang xử lý…</strong>
					<span id="nxwpmProgCount" class="nxwpm-muted"></span>
				</div>
				<div class="nxp-progress-bar-wrap"><div class="nxp-progress-bar" id="nxwpmProgBar"></div></div>
				<div id="nxwpmImportSummary" class="nxwpm-summary"></div>
				<div id="nxwpmImportReport" class="nxwpm-report"></div>
			</div>

			<form class="nxp-filter-bar nxwpm-filter" id="nxwpmFilter" onsubmit="return false;">
				<div class="nxwpm-filter-fields">
					<input type="search" name="q" placeholder="🔎 Tìm theo tên, SKU, SKU biến thể hoặc ID…" class="nxwpm-search">
					<select name="cat">
						<option value="">Tất cả danh mục</option>
						<?php foreach ( $cats as $c ) : ?>
							<option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['path'] . ' (' . $c['count'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="status">
						<option value="">Mọi trạng thái</option>
						<option value="publish">Đang hiển thị</option>
						<option value="draft">Bản nháp</option>
						<option value="private">Riêng tư</option>
						<option value="pending">Chờ duyệt</option>
					</select>
					<select name="stock">
						<option value="">Mọi tình trạng kho</option>
						<option value="instock">Còn hàng</option>
						<option value="outofstock">Hết hàng</option>
						<option value="onbackorder">Đặt trước</option>
					</select>
					<select name="type">
						<option value="">Mọi kiểu SP</option>
						<option value="variable">Có biến thể</option>
						<option value="simple">Đơn giản</option>
					</select>
					<select name="extra">
						<option value="">Tất cả</option>
						<option value="no_image">Thiếu ảnh đại diện</option>
						<option value="no_seo">Chưa có Meta Description</option>
						<option value="no_sku">Chưa có SKU</option>
					</select>
					<select name="orderby">
						<option value="">Mới nhất</option>
						<option value="modified">Sửa gần đây</option>
						<option value="title">Tên A → Z</option>
					</select>
					<select name="per_page">
						<option value="20">20 / trang</option>
						<option value="50">50 / trang</option>
						<option value="100">100 / trang</option>
					</select>
				</div>
				<div class="nxwpm-actions">
					<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmBtnReset">✖ Xóa lọc</button>
					<button type="button" class="nxp-btn nxp-btn-primary" id="nxwpmBtnAdd">➕ Thêm sản phẩm mới</button>
				</div>
			</form>

			<div class="nxwpm-bulkbar" id="nxwpmBulkbar">
				<span>Đã chọn <strong class="nxwpm-sel-count">0</strong> sản phẩm</span>
				<select id="nxwpmBulkStatus">
					<option value="">— Đổi trạng thái —</option>
					<option value="publish">Hiển thị</option>
					<option value="draft">Bản nháp</option>
					<option value="private">Riêng tư</option>
				</select>
				<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmBtnBulkStatus">Áp dụng</button>
				<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmBtnBulkTrash">🗑️ Chuyển thùng rác</button>
				<button type="button" class="nxp-btn nxp-btn-danger nxp-btn-sm" id="nxwpmBtnBulkDelete">❌ Xóa vĩnh viễn</button>
				<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmBtnClearSel">Bỏ chọn</button>
			</div>

			<div id="nxwpmTableWrap"><div class="nxwpm-empty">⏳ Đang tải danh sách sản phẩm…</div></div>
			<div class="nxwpm-pager" id="nxwpmPager"></div>

			<div class="nxwpm-modal" id="nxwpmModal" hidden>
				<div class="nxwpm-modal-backdrop" data-close="1"></div>
				<div class="nxwpm-modal-box" role="dialog" aria-modal="true">
					<div class="nxwpm-modal-head">
						<div>
							<div class="nxwpm-modal-title" id="nxwpmModalTitle">Sửa sản phẩm</div>
							<div class="nxwpm-muted" id="nxwpmModalSub"></div>
						</div>
						<button type="button" class="nxwpm-x" data-close="1" aria-label="Đóng">✕</button>
					</div>
					<div class="nxwpm-modal-tabs" id="nxwpmModalTabs">
						<button type="button" data-sec="info" class="active">📋 Thông tin &amp; Giá</button>
						<button type="button" data-sec="desc">📝 Mô tả</button>
						<button type="button" data-sec="images">🖼️ Hình ảnh</button>
						<button type="button" data-sec="variations">🎨 Biến thể</button>
						<button type="button" data-sec="seo">🔍 SEO &amp; Thẻ meta</button>
					</div>
					<div class="nxwpm-modal-body" id="nxwpmModalBody"></div>
					<div class="nxwpm-modal-foot">
						<div class="nxwpm-foot-links" id="nxwpmModalLinks"></div>
						<div class="nxwpm-actions">
							<button type="button" class="nxp-btn nxp-btn-outline" data-close="1">Hủy</button>
							<button type="button" class="nxp-btn nxp-btn-primary" id="nxwpmBtnSave">💾 Lưu tất cả thay đổi</button>
						</div>
					</div>
				</div>
			</div>
			<div class="nxwpm-toast" id="nxwpmToast" hidden></div>
		</div>
		<?php
	}
}

Nexxi_Web_Product_Manager::init();

endif;
