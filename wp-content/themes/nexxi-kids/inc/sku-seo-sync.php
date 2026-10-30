<?php
/**
 * NEXXI SKU Content & SEO Sync Engine.
 *
 * Tự động đối chiếu mã SKU từ bộ dữ liệu sản phẩm chuẩn (705+ mã SKU từ 2 file thông tin sản phẩm)
 * để điền Tên sản phẩm, Mô tả ngắn, Mô tả chi tiết chuẩn HTML và tối ưu toàn diện SEO
 * (SEO Title, Meta Description, Focus Keywords, OpenGraph, Image Alt, Slug chuẩn SEO, Thẻ sản phẩm)
 * cho toàn bộ sản phẩm trên Website và các sản phẩm đồng bộ mới từ Pancake POS.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Nexxi_SKU_SEO_Sync' ) ) :

final class Nexxi_SKU_SEO_Sync {

	const SYNC_VER    = '20261030_sku_seo_v1';
	const OPT_VER     = 'nexxi_sku_seo_sync_ver';
	const OPT_REPORT  = 'nexxi_sku_seo_last_report';
	const LOCK_KEY    = 'nexxi_sku_seo_running_lock';

	/** @var array|null */
	private static $catalog = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_auto_sync' ), 25 );
	}

	/**
	 * Tự động chạy đồng bộ 1 lần ngay khi phiên bản dữ liệu mới được triển khai lên Website.
	 */
	public static function maybe_auto_sync() {
		if ( get_option( self::OPT_VER ) === self::SYNC_VER ) {
			return;
		}
		if ( wp_installing() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );
		try {
			self::sync_all_wc_products( true );
			update_option( self::OPT_VER, self::SYNC_VER, true );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Đọc bộ dữ liệu chuẩn từ assets/data/sku-product-seo-catalog.json (chỉ nạp vào RAM khi cần đồng bộ).
	 */
	public static function get_catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}
		$file = get_template_directory() . '/assets/data/sku-product-seo-catalog.json';
		if ( ! is_readable( $file ) ) {
			self::$catalog = array( 'by_sku' => array(), 'aliases' => array(), 'by_title' => array() );
			return self::$catalog;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$dec = $raw ? json_decode( $raw, true ) : null;
		unset( $raw );
		if ( ! is_array( $dec ) || empty( $dec['by_sku'] ) ) {
			self::$catalog = array( 'by_sku' => array(), 'aliases' => array(), 'by_title' => array() );
			return self::$catalog;
		}
		self::$catalog = array(
			'by_sku'   => is_array( $dec['by_sku'] ?? null ) ? $dec['by_sku'] : array(),
			'aliases'  => is_array( $dec['aliases'] ?? null ) ? $dec['aliases'] : array(),
			'by_title' => is_array( $dec['by_title'] ?? null ) ? $dec['by_title'] : array(),
		);
		return self::$catalog;
	}

	/**
	 * Chuẩn hóa mã SKU để tra cứu chính xác (bỏ dấu gạch ngang thừa cuối mã, tách tiền tố SKU cha từ SKU biến thể).
	 */
	public static function normalize_sku( $raw_sku ) {
		$s = strtoupper( trim( (string) $raw_sku ) );
		if ( '' === $s ) {
			return '';
		}
		if ( 'HQTBN01-NA' === $s ) {
			return $s;
		}
		$s = rtrim( $s, "- \t\n\r\0\x0B" );
		return $s;
	}

	/**
	 * Tra cứu thông tin Tên + Mô tả + SEO theo một mã SKU bất kỳ (hỗ trợ cả SKU cha, alias và SKU biến thể).
	 *
	 * @param string $raw_sku Mã SKU cần tra cứu.
	 * @return array|null
	 */
	public static function lookup_by_sku( $raw_sku ) {
		$cat = self::get_catalog();
		if ( empty( $cat['by_sku'] ) ) {
			return null;
		}
		$sku = self::normalize_sku( $raw_sku );
		if ( '' === $sku ) {
			return null;
		}

		$candidates = array( $sku );
		if ( isset( $cat['aliases'][ $sku ] ) ) {
			$candidates[] = $cat['aliases'][ $sku ];
		}
		if ( false !== strpos( $sku, '-' ) ) {
			$prefix = trim( explode( '-', $sku )[0] );
			if ( '' !== $prefix ) {
				$candidates[] = $prefix;
				if ( isset( $cat['aliases'][ $prefix ] ) ) {
					$candidates[] = $cat['aliases'][ $prefix ];
				}
			}
		}
		// Hỗ trợ đối chiếu mã bị cắt thiếu số 1 ở cuối (VD: 2TCYNTKH0 <-> 2TCYNTKH01, 2ATVDNC0 <-> 2ATVDNC01)
		foreach ( $candidates as $cand ) {
			if ( preg_match( '/^[0-9A-Z]{6,}0$/', $cand ) ) {
				$candidates[] = $cand . '1';
			} elseif ( preg_match( '/^([0-9A-Z]{6,}0)1$/', $cand, $m ) ) {
				$candidates[] = $m[1];
			}
		}

		foreach ( array_unique( $candidates ) as $k ) {
			if ( isset( $cat['by_sku'][ $k ] ) ) {
				return $cat['by_sku'][ $k ];
			}
		}
		return null;
	}

	/**
	 * Tra cứu dữ liệu chuẩn cho một dòng sản phẩm WooCommerce (theo _sku, _nexxi_pancake_sku, SKU biến thể con, hoặc tên cũ nếu thiếu SKU).
	 *
	 * @param array $p_row Mảng thông tin sản phẩm.
	 * @return array|null
	 */
	public static function match_wc_product_row( array $p_row ) {
		$try_skus = array();
		if ( ! empty( $p_row['sku'] ) ) {
			$try_skus[] = $p_row['sku'];
		}
		if ( ! empty( $p_row['pancake_sku'] ) ) {
			$try_skus[] = $p_row['pancake_sku'];
		}
		if ( ! empty( $p_row['child_skus'] ) && is_array( $p_row['child_skus'] ) ) {
			foreach ( $p_row['child_skus'] as $csku ) {
				$try_skus[] = $csku;
			}
		}

		$has_alpha_sku = false;
		foreach ( $try_skus as $ts ) {
			$clean = self::normalize_sku( $ts );
			if ( '' === $clean ) {
				continue;
			}
			if ( ! preg_match( '/^\d+(\.\d+)?$/', $clean ) ) {
				$has_alpha_sku = true;
			}
			$found = self::lookup_by_sku( $clean );
			if ( $found ) {
				return $found;
			}
		}

		// Chỉ fallback theo tên khi sản phẩm chưa có mã SKU chữ-số riêng
		if ( ! $has_alpha_sku && ! empty( $p_row['title'] ) ) {
			$cat      = self::get_catalog();
			$norm_key = trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( (string) $p_row['title'] ) ) );
			if ( '' !== $norm_key && isset( $cat['by_title'][ $norm_key ] ) ) {
				return $cat['by_title'][ $norm_key ];
			}
		}

		return null;
	}

	/**
	 * Kiểm tra tên sản phẩm có đang ở dạng mã lô nội bộ Pancake POS (VD: "2501.8SET AO HAPPY+CVAY BI JEAN") hay không.
	 */
	public static function is_raw_pos_title( $title ) {
		$t = trim( (string) $title );
		if ( '' === $t ) {
			return true;
		}
		if ( preg_match( '/^\d{3,4}\.\d+/', $t ) ) {
			return true;
		}
		if ( preg_match( '/^Sản phẩm\s+[A-Z0-9-]+$/iu', $t ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Kiểm tra đường dẫn tĩnh (slug) hiện tại có đang là mã POS thô cần làm đẹp chuẩn SEO hay không.
	 */
	private static function should_refresh_slug( $slug ) {
		$s = trim( (string) $slug );
		if ( '' === $s ) {
			return true;
		}
		if ( preg_match( '/^\d{3,4}-\d+/', $s ) || preg_match( '/^\d+$/', $s ) || preg_match( '/^san-pham-[a-z0-9-]+$/', $s ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Làm sạch tên POS thô và tự sinh bộ SEO thông minh cho các sản phẩm nằm ngoài 2 file Excel
	 * để đảm bảo 100% sản phẩm trên Website đều đạt chuẩn SEO.
	 */
	public static function build_smart_fallback_seo( $raw_title, $short_desc, $full_desc, $sku = '' ) {
		$title = trim( (string) $raw_title );
		// Bóc mã lô đầu dòng như "2603.8"
		$title = trim( preg_replace( '/^\d{3,4}\.\d+\s*/u', '', $title ) );

		// Mở rộng một số từ viết tắt phổ biến từ POS nếu tên toàn chữ in hoa không dấu
		$letters = preg_replace( '/[^\p{L}]/u', '', $title );
		if ( '' !== $letters && mb_strtoupper( $letters ) === $letters && ! preg_match( '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $title ) ) {
			$rep = array(
				'/\bCVAY\b/i' => 'Chân Váy',
				'/\bAT\b/i'   => 'Áo Thun',
				'/\bQS\b/i'   => 'Quần Short',
				'/\bQD\b/i'   => 'Quần Dài',
				'/\bQLUNG\b/i'=> 'Quần Lửng',
				'/\bCB\b/i'   => 'Cổ Bẻ',
				'/\bTT\b/i'   => 'Thể Thao',
				'/\bPM\b/i'   => 'Phối Màu',
				'/\bXLY\b/i'  => 'Xếp Ly',
				'/\bLG\b/i'   => 'Logo',
				'/\+/u'       => ' Kèm ',
			);
			foreach ( $rep as $pat => $r ) {
				$title = preg_replace( $pat, $r, $title );
			}
			$title = mb_convert_case( mb_strtolower( trim( preg_replace( '/\s{2,}/', ' ', $title ) ) ), MB_CASE_TITLE, 'UTF-8' );
		}

		if ( '' === $title ) {
			$title = 'Thời Trang Trẻ Em NEXXI Kids ' . $sku;
		} elseif ( false === stripos( $title, 'nexxi' ) ) {
			$title .= ' NEXXI';
		}

		$t_low      = mb_strtolower( $title );
		$is_girl    = (bool) preg_match( '/(bé gái|be gai|váy|vay|đầm|dam|yếm|bèo|nơ|babytee|croptop)/u', $t_low );
		$is_boy     = (bool) preg_match( '/(bé trai|be trai|lịch lãm|soái ca)/u', $t_low );
		$gender_lbl = ( $is_girl && ! $is_boy ) ? 'bé gái' : ( ( $is_boy && ! $is_girl ) ? 'bé trai' : 'bé' );
		$gender_kw  = ( $is_girl && ! $is_boy ) ? 'bé gái' : ( ( $is_boy && ! $is_girl ) ? 'bé trai' : 'trẻ em' );

		if ( preg_match( '/(set\s+váy|chân\s+váy.*kèm|áo.*chân\s+váy)/u', $t_low ) ) {
			$primary_kw = 'set váy bé gái';
		} elseif ( preg_match( '/(đầm|váy)/u', $t_low ) && false === strpos( $t_low, 'chân váy' ) ) {
			$primary_kw = 'đầm bé gái';
		} elseif ( false !== strpos( $t_low, 'chân váy' ) ) {
			$primary_kw = 'chân váy bé gái';
		} elseif ( preg_match( '/^(set|bộ|đồ\s+bộ)\b/u', $t_low ) ) {
			$primary_kw = 'đồ bộ ' . $gender_kw;
		} elseif ( false !== strpos( $t_low, 'quần' ) ) {
			$primary_kw = 'quần ' . $gender_kw;
		} elseif ( false !== strpos( $t_low, 'sơ mi' ) ) {
			$primary_kw = 'áo sơ mi ' . $gender_kw;
		} else {
			$primary_kw = 'áo thun ' . $gender_kw;
		}

		// SEO Title (~50-65 ký tự)
		$suffix   = ' | NEXXI Kids';
		$core     = trim( preg_replace( '/\bNEXXI(?:\s+Kids)?\b/iu', '', $title ), " \t\n\r\0\x0B-–,|" );
		$core     = preg_replace( '/\s{2,}/u', ' ', $core );
		$max_core = 65 - mb_strlen( $suffix );
		if ( mb_strlen( $core ) > $max_core ) {
			$cut      = mb_substr( $core, 0, $max_core );
			$last_sp  = mb_strrpos( $cut, ' ' );
			$seo_core = $last_sp > 20 ? rtrim( mb_substr( $cut, 0, $last_sp ), ' ,;:-–+' ) : rtrim( $cut, ' ,;:-–+' );
		} else {
			$seo_core = $core;
		}
		$seo_title = $seo_core . $suffix;

		// Short & Full Description fallback nếu sản phẩm chưa có mô tả
		$gen_short = trim( (string) $short_desc );
		if ( '' === $gen_short ) {
			$plain_full = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $full_desc ) ) );
			if ( mb_strlen( $plain_full ) >= 40 ) {
				$gen_short = mb_strlen( $plain_full ) > 230 ? rtrim( mb_substr( $plain_full, 0, 225 ) ) . '…' : $plain_full;
			} else {
				$gen_short = sprintf(
					'%s chất liệu mềm mịn, thoáng mát, phom dáng chuẩn đẹp giúp %s thoải mái vận động khi đi học, đi chơi hằng ngày.',
					$title,
					$gender_lbl
				);
			}
		}

		$gen_full = trim( (string) $full_desc );
		if ( '' === $gen_full ) {
			$gen_full = '<p>' . esc_html( $gen_short ) . '</p>'
				. '<h3>Đặc điểm nổi bật của sản phẩm</h3>'
				. '<ul>'
				. '<li>Chất liệu vải mềm mịn, thoáng khí, thấm hút mồ hôi tốt và an toàn cho làn da nhạy cảm của trẻ nhỏ.</li>'
				. '<li>Phom dáng hiện đại, đường kim mũi chỉ chắc chắn, dễ phối đồ cho ' . esc_html( $gender_lbl ) . ' diện đi học, đi chơi, dạo phố.</li>'
				. '<li>Đa dạng size theo cân nặng và chiều cao của bé (ba mẹ xem bảng quy đổi size ngay trên trang sản phẩm).</li>'
				. '</ul>'
				. '<h3>Cam kết &amp; Hỗ trợ đổi size từ NEXXI Kids</h3>'
				. '<ul>'
				. '<li>Hình ảnh thực tế chuẩn mẫu NEXXI Kids, kiểm tra kỹ từng sản phẩm trước khi đóng gói.</li>'
				. '<li>Hỗ trợ đổi size nhanh chóng nếu bé mặc chưa vừa (sản phẩm còn nguyên tem mác, chưa qua giặt ủi).</li>'
				. '</ul>';
		}

		// Meta Description (~140-158 ký tự)
		$tail     = ' – chất vải mềm mát, đường may tỉ mỉ, an toàn cho da bé. Chính hãng NEXXI Kids, đổi size 7 ngày.';
		$max_lead = 158 - mb_strlen( $tail );
		$lead     = mb_strlen( $core ) <= $max_lead ? $core : rtrim( mb_substr( $core, 0, $max_lead ), ' ,;:-–+' );
		if ( mb_strlen( $core ) > $max_lead ) {
			$sp = mb_strrpos( $lead, ' ' );
			if ( $sp > 18 ) {
				$lead = rtrim( mb_substr( $lead, 0, $sp ), ' ,;:-–+' );
			}
		}
		$seo_desc = $lead . $tail;

		$kws = array_unique(
			array_filter(
				array(
					$primary_kw,
					$primary_kw . ' nexxi',
					mb_strtolower( $seo_core ),
					'quần áo ' . $gender_kw . ' nexxi',
					'thời trang trẻ em nexxi',
					'' !== $sku ? strtolower( $sku ) : '',
				)
			)
		);

		return array(
			'clean_title'       => $title,
			'short_description' => $gen_short,
			'description'       => $gen_full,
			'seo_title'         => $seo_title,
			'seo_description'   => $seo_desc,
			'seo_keywords'      => implode( ', ', array_slice( $kws, 0, 6 ) ),
			'seo_robots'        => 'index,follow',
			'og_title'          => $seo_title,
			'og_description'    => $seo_desc,
		);
	}

	/**
	 * Lưu toàn bộ các meta SEO (hỗ trợ đồng thời NEXXI SEO, Yoast SEO và Rank Math).
	 */
	private static function write_seo_meta( $post_id, array $seo_data, $og_image_url = '' ) {
		$fields = array(
			'seo_title'       => $seo_data['seo_title'] ?? '',
			'seo_description' => $seo_data['seo_description'] ?? '',
			'seo_keywords'    => $seo_data['seo_keywords'] ?? '',
			'seo_robots'      => $seo_data['seo_robots'] ?? 'index,follow',
			'og_title'        => $seo_data['og_title'] ?? ( $seo_data['seo_title'] ?? '' ),
			'og_description'  => $seo_data['og_description'] ?? ( $seo_data['seo_description'] ?? '' ),
		);
		if ( '' !== $og_image_url ) {
			$fields['og_image_url'] = $og_image_url;
		}
		if ( class_exists( 'Nexxi_Web_Product_Manager' ) ) {
			foreach ( $fields as $k => $v ) {
				if ( '' !== (string) $v ) {
					Nexxi_Web_Product_Manager::set_seo( $post_id, $k, $v );
				}
			}
		} else {
			$fallback_map = array(
				'seo_title'       => array( '_nexxi_seo_title', '_yoast_wpseo_title', 'rank_math_title' ),
				'seo_description' => array( '_nexxi_seo_desc', '_yoast_wpseo_metadesc', 'rank_math_description' ),
				'seo_keywords'    => array( '_nexxi_seo_keywords', '_yoast_wpseo_focuskw', 'rank_math_focus_keyword' ),
				'seo_robots'      => array( '_nexxi_seo_robots' ),
				'og_title'        => array( '_nexxi_og_title', '_yoast_wpseo_opengraph-title', 'rank_math_facebook_title' ),
				'og_description'  => array( '_nexxi_og_desc', '_yoast_wpseo_opengraph-description', 'rank_math_facebook_description' ),
				'og_image_url'    => array( '_nexxi_og_image', '_yoast_wpseo_opengraph-image', 'rank_math_facebook_image' ),
			);
			foreach ( $fields as $k => $v ) {
				if ( '' === (string) $v || empty( $fallback_map[ $k ] ) ) {
					continue;
				}
				foreach ( $fallback_map[ $k ] as $mk ) {
					update_post_meta( $post_id, $mk, $v );
				}
			}
		}
	}

	/**
	 * Cập nhật thuộc tính Alt cho ảnh đại diện & album ảnh sản phẩm (rất quan trọng cho Google Image SEO).
	 */
	private static function sync_product_image_alts( $thumb_id, array $gallery_ids, $clean_name ) {
		$clean_name = trim( wp_strip_all_tags( (string) $clean_name ) );
		if ( '' === $clean_name ) {
			return;
		}
		$img_ids = array_values( array_unique( array_filter( array_merge( array( (int) $thumb_id ), array_map( 'absint', $gallery_ids ) ) ) ) );
		foreach ( $img_ids as $idx => $aid ) {
			$cur_alt = trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) );
			if ( '' === $cur_alt || self::is_raw_pos_title( $cur_alt ) ) {
				$alt_txt = 0 === $idx ? $clean_name : ( $clean_name . ' - Ảnh ' . ( $idx + 1 ) );
				update_post_meta( $aid, '_wp_attachment_image_alt', $alt_txt );
			}
		}
	}

	/**
	 * Áp dụng Tên, Mô tả & SEO cho 1 sản phẩm WooCommerce đơn lẻ (được gọi khi đồng bộ sản phẩm mới từ Pancake POS).
	 *
	 * @param int    $wc_id             ID sản phẩm WooCommerce.
	 * @param string $hint_sku          Mã SKU gợi ý (nếu có).
	 * @param bool   $overwrite_content Ghi đè tên/mô tả kể cả khi sản phẩm đã có nội dung trước đó.
	 * @return bool                     True nếu khớp từ bộ 705 mã SKU chuẩn, False nếu dùng SEO tự động dự phòng.
	 */
	public static function apply_to_wc_product( $wc_id, $hint_sku = '', $overwrite_content = false ) {
		global $wpdb;
		$wc_id = (int) $wc_id;
		if ( $wc_id <= 0 ) {
			return false;
		}
		$post = get_post( $wc_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return false;
		}

		$sku         = (string) get_post_meta( $wc_id, '_sku', true );
		$pancake_sku = (string) get_post_meta( $wc_id, '_nexxi_pancake_sku', true );
		$child_skus  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value
				 FROM {$wpdb->posts} v
				 INNER JOIN {$wpdb->postmeta} pm ON v.ID = pm.post_id AND pm.meta_key = '_sku'
				 WHERE v.post_type = 'product_variation' AND v.post_parent = %d AND pm.meta_value != ''
				 LIMIT 10",
				$wc_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( '' !== $hint_sku ) {
			array_unshift( $child_skus, $hint_sku );
		}

		$matched = self::match_wc_product_row(
			array(
				'sku'         => $sku,
				'pancake_sku' => $pancake_sku,
				'child_skus'  => $child_skus,
				'title'       => $post->post_title,
			)
		);

		$thumb_id    = (int) get_post_thumbnail_id( $wc_id );
		$og_img      = $thumb_id > 0 ? (string) wp_get_attachment_url( $thumb_id ) : '';
		$gallery_raw = (string) get_post_meta( $wc_id, '_product_image_gallery', true );
		$gallery_ids = '' !== $gallery_raw ? array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) : array();

		if ( $matched ) {
			$already_synced = ( self::SYNC_VER === (string) get_post_meta( $wc_id, '_nexxi_sku_seo_ver', true ) );
			$post_updates   = array();

			if ( $overwrite_content || ! $already_synced || self::is_raw_pos_title( $post->post_title ) ) {
				$post_updates['post_title']   = $matched['name'];
				$post_updates['post_excerpt'] = $matched['short_description'];
				$post_updates['post_content'] = $matched['description'];
			}

			if ( self::should_refresh_slug( $post->post_name ) ) {
				$old_slug = (string) $post->post_name;
				$new_slug = wp_unique_post_slug( sanitize_title( $matched['name'] ), $wc_id, $post->post_status, 'product', 0 );
				if ( '' !== $new_slug && $new_slug !== $old_slug ) {
					$post_updates['post_name'] = $new_slug;
					if ( '' !== $old_slug ) {
						add_post_meta( $wc_id, '_wp_old_slug', $old_slug );
					}
				}
			}

			if ( ! empty( $post_updates ) ) {
				$wpdb->update( $wpdb->posts, $post_updates, array( 'ID' => $wc_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $wc_id );
			}

			if ( '' === trim( $sku ) && ! empty( $matched['sku'] ) ) {
				$owner = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $matched['sku'] ) : 0;
				if ( ! $owner || $owner === $wc_id ) {
					update_post_meta( $wc_id, '_sku', $matched['sku'] );
				}
			}

			self::write_seo_meta( $wc_id, $matched, $og_img );
			self::sync_product_image_alts( $thumb_id, $gallery_ids, $matched['name'] );

			if ( ! empty( $matched['tags'] ) ) {
				$existing_tags = wp_get_object_terms( $wc_id, 'product_tag', array( 'fields' => 'ids' ) );
				if ( empty( $existing_tags ) || is_wp_error( $existing_tags ) ) {
					$tag_names = array_values( array_filter( array_map( 'trim', explode( '|', (string) $matched['tags'] ) ) ) );
					if ( ! empty( $tag_names ) ) {
						wp_set_object_terms( $wc_id, $tag_names, 'product_tag', false );
					}
				}
			}

			update_post_meta( $wc_id, '_nexxi_sku_seo_ver', self::SYNC_VER );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $wc_id );
			}
			return true;
		}

		// Fallback cho sản phẩm không nằm trong 2 file Excel: làm sạch tên POS thô & điền SEO nếu đang trống
		$eff_sku  = self::normalize_sku( $sku ?: ( $pancake_sku ?: $hint_sku ) );
		$fb       = self::build_smart_fallback_seo( $post->post_title, $post->post_excerpt, $post->post_content, $eff_sku );
		$p_upd    = array();
		if ( self::is_raw_pos_title( $post->post_title ) ) {
			$p_upd['post_title'] = $fb['clean_title'];
		}
		if ( '' === trim( (string) $post->post_excerpt ) ) {
			$p_upd['post_excerpt'] = $fb['short_description'];
		}
		if ( '' === trim( (string) $post->post_content ) ) {
			$p_upd['post_content'] = $fb['description'];
		}
		if ( self::should_refresh_slug( $post->post_name ) ) {
			$old_slug = (string) $post->post_name;
			$new_slug = wp_unique_post_slug( sanitize_title( $fb['clean_title'] ), $wc_id, $post->post_status, 'product', 0 );
			if ( '' !== $new_slug && $new_slug !== $old_slug ) {
				$p_upd['post_name'] = $new_slug;
				if ( '' !== $old_slug ) {
					add_post_meta( $wc_id, '_wp_old_slug', $old_slug );
				}
			}
		}
		if ( ! empty( $p_upd ) ) {
			$wpdb->update( $wpdb->posts, $p_upd, array( 'ID' => $wc_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $wc_id );
		}

		$cur_seo_title = trim( (string) get_post_meta( $wc_id, '_nexxi_seo_title', true ) );
		$cur_seo_desc  = trim( (string) get_post_meta( $wc_id, '_nexxi_seo_desc', true ) );
		if ( '' === $cur_seo_title || '' === $cur_seo_desc || self::is_raw_pos_title( $cur_seo_title ) ) {
			self::write_seo_meta( $wc_id, $fb, $og_img );
		}
		self::sync_product_image_alts( $thumb_id, $gallery_ids, $fb['clean_title'] );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $wc_id );
		}
		return false;
	}

	/**
	 * Đồng bộ hàng loạt tốc độ cao toàn bộ sản phẩm trên Website bằng truy vấn SQL gom lô (tránh N+1 query).
	 *
	 * @param bool $overwrite_matched Ghi đè Tên + Mô tả chuẩn từ 2 file cho mọi SP khớp SKU.
	 * @return array                  Báo cáo kết quả chi tiết.
	 */
	public static function sync_all_wc_products( $overwrite_matched = true ) {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$GLOBALS['nexxi_bulk_syncing'] = true;
		wp_defer_term_counting( true );

		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_excerpt, post_content, post_name, post_status
			 FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status IN ('publish', 'draft', 'private', 'pending')
			 ORDER BY ID ASC"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$report = array(
			'total_web_products' => is_array( $posts ) ? count( $posts ) : 0,
			'matched_by_sku'     => 0,
			'updated_matched'    => 0,
			'fallback_seo_fixed' => 0,
			'catalog_sku_count'  => count( self::get_catalog()['by_sku'] ),
			'synced_at'          => current_time( 'd/m/Y H:i:s' ),
			'samples'            => array(),
		);

		if ( empty( $posts ) ) {
			wp_defer_term_counting( false );
			update_option( self::OPT_REPORT, $report, false );
			return $report;
		}

		$by_id = array();
		foreach ( $posts as $p ) {
			$pid           = (int) $p->ID;
			$by_id[ $pid ] = array(
				'id'          => $pid,
				'title'       => (string) $p->post_title,
				'excerpt'     => (string) $p->post_excerpt,
				'content'     => (string) $p->post_content,
				'slug'        => (string) $p->post_name,
				'status'      => (string) $p->post_status,
				'sku'         => '',
				'pancake_sku' => '',
				'thumb_id'    => 0,
				'gallery_ids' => array(),
				'seo_title'   => '',
				'seo_desc'    => '',
				'child_skus'  => array(),
			);
		}

		// 1 truy vấn SQL lấy toàn bộ postmeta cần thiết của các sản phẩm cha
		$meta_rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'product'
			   AND p.post_status IN ('publish', 'draft', 'private', 'pending')
			   AND pm.meta_key IN ('_sku', '_nexxi_pancake_sku', '_thumbnail_id', '_product_image_gallery', '_nexxi_seo_title', '_nexxi_seo_desc')"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $meta_rows as $mr ) {
			$pid = (int) $mr->post_id;
			if ( ! isset( $by_id[ $pid ] ) ) {
				continue;
			}
			$mk = (string) $mr->meta_key;
			$mv = trim( (string) $mr->meta_value );
			if ( '_sku' === $mk ) {
				$by_id[ $pid ]['sku'] = $mv;
			} elseif ( '_nexxi_pancake_sku' === $mk ) {
				$by_id[ $pid ]['pancake_sku'] = $mv;
			} elseif ( '_thumbnail_id' === $mk ) {
				$by_id[ $pid ]['thumb_id'] = (int) $mv;
			} elseif ( '_product_image_gallery' === $mk && '' !== $mv ) {
				$by_id[ $pid ]['gallery_ids'] = array_values( array_filter( array_map( 'absint', explode( ',', $mv ) ) ) );
			} elseif ( '_nexxi_seo_title' === $mk ) {
				$by_id[ $pid ]['seo_title'] = $mv;
			} elseif ( '_nexxi_seo_desc' === $mk ) {
				$by_id[ $pid ]['seo_desc'] = $mv;
			}
		}

		// 1 truy vấn SQL lấy toàn bộ SKU của các biến thể con
		$var_skus = $wpdb->get_results(
			"SELECT v.post_parent, pm.meta_value AS v_sku
			 FROM {$wpdb->posts} v
			 INNER JOIN {$wpdb->postmeta} pm ON v.ID = pm.post_id AND pm.meta_key = '_sku'
			 WHERE v.post_type = 'product_variation'
			   AND v.post_parent > 0
			   AND pm.meta_value != ''"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $var_skus as $vr ) {
			$pid = (int) $vr->post_parent;
			if ( isset( $by_id[ $pid ] ) && count( $by_id[ $pid ]['child_skus'] ) < 12 ) {
				$by_id[ $pid ]['child_skus'][] = trim( (string) $vr->v_sku );
			}
		}

		$now_local = current_time( 'mysql' );
		$now_gmt   = current_time( 'mysql', 1 );

		foreach ( $by_id as $pid => $row ) {
			$matched = self::match_wc_product_row( $row );
			$og_img  = $row['thumb_id'] > 0 ? (string) wp_get_attachment_url( $row['thumb_id'] ) : '';

			if ( $matched ) {
				$report['matched_by_sku']++;
				$post_upd = array();

				if ( $overwrite_matched || self::is_raw_pos_title( $row['title'] ) || '' === trim( $row['content'] ) ) {
					if ( $row['title'] !== $matched['name'] ) {
						$post_upd['post_title'] = $matched['name'];
					}
					if ( trim( $row['excerpt'] ) !== trim( $matched['short_description'] ) ) {
						$post_upd['post_excerpt'] = $matched['short_description'];
					}
					if ( trim( $row['content'] ) !== trim( $matched['description'] ) ) {
						$post_upd['post_content'] = $matched['description'];
					}
				}

				if ( self::should_refresh_slug( $row['slug'] ) ) {
					$new_slug = wp_unique_post_slug( sanitize_title( $matched['name'] ), $pid, $row['status'], 'product', 0 );
					if ( '' !== $new_slug && $new_slug !== $row['slug'] ) {
						$post_upd['post_name'] = $new_slug;
						if ( '' !== $row['slug'] ) {
							add_post_meta( $pid, '_wp_old_slug', $row['slug'] );
						}
					}
				}

				if ( ! empty( $post_upd ) ) {
					$post_upd['post_modified']     = $now_local;
					$post_upd['post_modified_gmt'] = $now_gmt;
					$wpdb->update( $wpdb->posts, $post_upd, array( 'ID' => $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					clean_post_cache( $pid );
				}

				if ( '' === $row['sku'] && ! empty( $matched['sku'] ) ) {
					$owner = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $matched['sku'] ) : 0;
					if ( ! $owner || $owner === $pid ) {
						update_post_meta( $pid, '_sku', $matched['sku'] );
					}
				}

				self::write_seo_meta( $pid, $matched, $og_img );
				self::sync_product_image_alts( $row['thumb_id'], $row['gallery_ids'], $matched['name'] );

				if ( ! empty( $matched['tags'] ) ) {
					$cur_tags = wp_get_object_terms( $pid, 'product_tag', array( 'fields' => 'ids' ) );
					if ( empty( $cur_tags ) || is_wp_error( $cur_tags ) ) {
						$tag_names = array_values( array_filter( array_map( 'trim', explode( '|', (string) $matched['tags'] ) ) ) );
						if ( ! empty( $tag_names ) ) {
							wp_set_object_terms( $pid, $tag_names, 'product_tag', false );
						}
					}
				}

				update_post_meta( $pid, '_nexxi_sku_seo_ver', self::SYNC_VER );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $pid );
				}
				$report['updated_matched']++;
				if ( count( $report['samples'] ) < 12 ) {
					$report['samples'][] = array(
						'id'        => $pid,
						'sku'       => $matched['sku'] ?? $row['sku'],
						'name'      => $matched['name'],
						'seo_title' => $matched['seo_title'],
					);
				}
			} else {
				// Tối ưu SEO dự phòng cho các sản phẩm ngoài danh sách 2 file Excel
				$eff_sku = self::normalize_sku( $row['sku'] ?: ( $row['pancake_sku'] ?: ( $row['child_skus'][0] ?? '' ) ) );
				if ( false !== strpos( $eff_sku, '-' ) && 'HQTBN01-NA' !== $eff_sku ) {
					$eff_sku = explode( '-', $eff_sku )[0];
				}
				$fb       = self::build_smart_fallback_seo( $row['title'], $row['excerpt'], $row['content'], $eff_sku );
				$post_upd = array();

				if ( self::is_raw_pos_title( $row['title'] ) ) {
					$post_upd['post_title'] = $fb['clean_title'];
				}
				if ( '' === trim( $row['excerpt'] ) ) {
					$post_upd['post_excerpt'] = $fb['short_description'];
				}
				if ( '' === trim( $row['content'] ) ) {
					$post_upd['post_content'] = $fb['description'];
				}
				if ( self::should_refresh_slug( $row['slug'] ) ) {
					$new_slug = wp_unique_post_slug( sanitize_title( $fb['clean_title'] ), $pid, $row['status'], 'product', 0 );
					if ( '' !== $new_slug && $new_slug !== $row['slug'] ) {
						$post_upd['post_name'] = $new_slug;
						if ( '' !== $row['slug'] ) {
							add_post_meta( $pid, '_wp_old_slug', $row['slug'] );
						}
					}
				}
				if ( ! empty( $post_upd ) ) {
					$post_upd['post_modified']     = $now_local;
					$post_upd['post_modified_gmt'] = $now_gmt;
					$wpdb->update( $wpdb->posts, $post_upd, array( 'ID' => $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					clean_post_cache( $pid );
				}

				$changed_seo = false;
				if ( '' === $row['seo_title'] || '' === $row['seo_desc'] || self::is_raw_pos_title( $row['seo_title'] ) ) {
					self::write_seo_meta( $pid, $fb, $og_img );
					$changed_seo = true;
				}
				self::sync_product_image_alts( $row['thumb_id'], $row['gallery_ids'], $fb['clean_title'] );

				if ( ! empty( $post_upd ) || $changed_seo ) {
					if ( function_exists( 'wc_delete_product_transients' ) ) {
						wc_delete_product_transients( $pid );
					}
					$report['fallback_seo_fixed']++;
				}
			}
		}

		wp_defer_term_counting( false );
		do_action( 'litespeed_purge_all' );

		update_option( self::OPT_REPORT, $report, false );
		update_option( self::OPT_VER, self::SYNC_VER, true );

		return $report;
	}
}

Nexxi_SKU_SEO_Sync::init();

endif;
