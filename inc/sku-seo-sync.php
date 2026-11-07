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
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
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
	 * Từ điển chuẩn hóa chính tả tiếng Việt thực tế đúc kết từ 822 bài đăng Shopee & POS Pancake của Nexxi,
	 * đồng thời quét sạch ký tự biến thể tàng hình U+FE0F.
	 */
	public static function normalize_shopee_vi_orthography( $text ) {
		$s = (string) $text;
		if ( '' === $s ) {
			return '';
		}
		// Xóa ký tự biến thể tàng hình U+FE0F & zero-width spaces
		$s = preg_replace( '/[\x{FE0E}\x{FE0F}\x{200B}-\x{200D}\x{FEFF}]/u', '', $s );

		$rules = array(
			'/(?<![\p{L}\p{N}])(?:Aó|Áó|Âo|aó|áó|âo)(?![\p{L}\p{N}])/u' => 'Áo',
			'/(?<![\p{L}\p{N}])Ao(?=\s+(?:Thun|Sơ\s*Mi|Sơ\s*Mí|Khoác|Polo|Croptop|Bí|Gile|Ghile|Vest|Dài|2\s*Dây|Ba\s*Lỗ|Ba\s*Lổ|Sát\s*Nách|Sát\s*Nach))(?![\p{L}\p{N}])/iu' => 'Áo',
			'/(?<![\p{L}\p{N}])Sơ\s+Mí(?![\p{L}\p{N}])/iu'              => 'Sơ Mi',
			'/(?:^\s*|(?<=[-\–—+|,(\s]))Sét(?=\s+(?:váy|vaý|vái|vày|đầm|bộ|đồ|áo|aó|quần|quàn|vest|yếm|2\s*món|3\s*món|thu\s*đông|chân\s*váy))(?![\p{L}\p{N}])/iu' => 'Set',
			'/^\s*Sét(?![\p{L}\p{N}])/iu'                                => 'Set',
			'/(?<![\p{L}\p{N}])(?:Vaý|Vái|Vày)(?![\p{L}\p{N}])/iu'       => 'Váy',
			'/(?<![\p{L}\p{N}])Quàn(?![\p{L}\p{N}])/iu'                  => 'Quần',
			'/(?:^\s*|(?<=[\s\-]))Đẫm(?=\s+(?:bé\s*gái|xòe|xoè|công\s*chúa|tiểu\s*thư))(?![\p{L}\p{N}])/iu' => 'Đầm',
			'/(?<![\p{L}\p{N}])(?:Jockker|Jocker)(?![\p{L}\p{N}])/iu'    => 'Jogger',
			'/(?<![\p{L}\p{N}])Ghile(?![\p{L}\p{N}])/iu'                 => 'Gile',
			'/(?<![\p{L}\p{N}])(?:Pizama|Pỵjama)(?![\p{L}\p{N}])/iu'     => 'Pyjama',
			'/(?<![\p{L}\p{N}])Cavat(?![\p{L}\p{N}])/iu'                 => 'Cà Vạt',
			'/(?<![\p{L}\p{N}])(?:Lững|Lứng)(?![\p{L}\p{N}])/iu'         => 'Lửng',
			'/(?<![\p{L}\p{N}])Co\s+Dãn(?![\p{L}\p{N}])/iu'              => 'Co Giãn',
			'/(?<![\p{L}\p{N}])(co|độ|mềm|thun|jean|kaki)\s+dãn(?![\p{L}\p{N}])/iu' => '$1 giãn',
			'/(?<![\p{L}\p{N}])(?:Ông|Ổng)\s+Suông(?![\p{L}\p{N}])/iu'   => 'Ống Suông',
			'/(?<![\p{L}\p{N}])(Viền|Phối|Kẻ|Tay)\s+Soc(?![\p{L}\p{N}])/iu' => '$1 Sọc',
			'/(?<![\p{L}\p{N}])(?:Xepps|Xêp)\s+Ly(?![\p{L}\p{N}])/iu'    => 'Xếp Ly',
			'/(?<![\p{L}\p{N}])Sát\s+Nach(?![\p{L}\p{N}])/iu'            => 'Sát Nách',
			'/(?<![\p{L}\p{N}])Ngắn\s+Tạy(?![\p{L}\p{N}])/iu'            => 'Ngắn Tay',
			'/(?<![\p{L}\p{N}])Ba\s+Lổ(?![\p{L}\p{N}])/iu'               => 'Ba Lỗ',
			'/(?<![\p{L}\p{N}])Có\s+Chíp(?![\p{L}\p{N}])/iu'             => 'Có Chip',
			'/(?<![\p{L}\p{N}])Quần\s+Chíp(?![\p{L}\p{N}])/iu'           => 'Quần Chip',
			'/(?<![\p{L}\p{N}])Gỉa\s+(Váy|Vest)(?![\p{L}\p{N}])/iu'      => 'Giả $1',
			'/(?<![\p{L}\p{N}])(Dây\s+Rút|In)\s+Chứ(?![\p{L}\p{N}])/iu'  => '$1 Chữ',
			'/(?<![\p{L}\p{N}])In\s+Mặc\s+Cười(?![\p{L}\p{N}])/iu'       => 'In Mặt Cười',
			'/(?<![\p{L}\p{N}])In\s+Hinh(?![\p{L}\p{N}])/iu'             => 'In Hình',
			'/(?<![\p{L}\p{N}])Hoạ\s+Tiết(?![\p{L}\p{N}])/iu'            => 'Họa Tiết',
			'/(?<![\p{L}\p{N}])Cottom(?![\p{L}\p{N}])/iu'                => 'Cotton',
			'/(?<![\p{L}\p{N}])Demin(?![\p{L}\p{N}])/iu'                 => 'Denim',
			'/(?<![\p{L}\p{N}])(Vải|Chất)\s+Kata(?![\p{L}\p{N}])/iu'     => '$1 Kate',
			'/(?<![\p{L}\p{N}])(Vải|Chất)\s+Đủi(?![\p{L}\p{N}])/iu'      => '$1 Đũi',
			'/(?<![\p{L}\p{N}])KaKi(?![\p{L}\p{N}])/u'                   => 'Kaki',
			'/(?<![\p{L}\p{N}])Bé\s+GáI(?![\p{L}\p{N}])/u'               => 'Bé Gái',
			'/(?<![\p{L}\p{N}])Bé\s+Gá(?![\p{L}\p{N}])/iu'               => 'Bé Gái',
			'/(?<![\p{L}\p{N}])(?:sIZE|szie)(?![\p{L}\p{N}])/u'          => 'Size',
			'/(?<![\p{L}\p{N}])Chup\s+Hình(?![\p{L}\p{N}])/iu'           => 'Chụp Hình',
			'/(?<![\p{L}\p{N}])Du\s+Lich(?![\p{L}\p{N}])/iu'             => 'Du Lịch',
			'/(?<![\p{L}\p{N}])(Đi|Dự)\s+Tiêc(?![\p{L}\p{N}])/iu'        => '$1 Tiệc',
			'/(?<![\p{L}\p{N}])Đi\s+Choi(?![\p{L}\p{N}])/iu'             => 'Đi Chơi',
			'/(?<![\p{L}\p{N}])Đễ\s+Thương(?![\p{L}\p{N}])/iu'           => 'Dễ Thương',
			'/(?<![\p{L}\p{N}])(?:Kiếu\s+Dáng|Kiểu\s+Giáng)(?![\p{L}\p{N}])/u' => 'Kiểu Dáng',
			'/(?<![\p{L}\p{N}])(?:kiếu\s+dáng|kiểu\s+giáng)(?![\p{L}\p{N}])/iu' => 'kiểu dáng',
			'/(?<![\p{L}\p{N}])Thám\s+Hút(?![\p{L}\p{N}])/u'             => 'Thấm Hút',
			'/(?<![\p{L}\p{N}])thám\s+hút(?![\p{L}\p{N}])/iu'            => 'thấm hút',
			'/(?<![\p{L}\p{N}])Vận\s+Dộng(?![\p{L}\p{N}])/iu'            => 'Vận Động',
			'/(?<![\p{L}\p{N}])Chát(?=\s+(?:Vải|Liệu|Cotton|Thun|Lụa|Kaki|Nỉ|Jean|Denim|Đũi|Kate|Dù|Gân|Tăm|Nhung|Len))(?![\p{L}\p{N}])/u' => 'Chất',
			'/(?<![\p{L}\p{N}])chát(?=\s+(?:vải|liệu|cotton|thun|lụa|kaki|nỉ|jean|denim|đũi|kate|dù|gân|tăm|nhung|len))(?![\p{L}\p{N}])/iu' => 'chất',
			'/(?<![\p{L}\p{N}])mềm\s+mại\s+mại(?![\p{L}\p{N}])/iu'       => 'mềm mại',
			'/(?<![\p{L}\p{N}])(?:Cappypara|Capypara)(?![\p{L}\p{N}])/iu'=> 'Capybara',
			'/(?<![\p{L}\p{N}])Baketball(?![\p{L}\p{N}])/iu'             => 'Basketball',
			'/(?<![\p{L}\p{N}])Authetntic(?![\p{L}\p{N}])/iu'            => 'Authentic',
		);

		foreach ( $rules as $pat => $rep ) {
			$s = preg_replace( $pat, $rep, $s );
		}

		return trim( preg_replace( '/[ \t]{2,}/u', ' ', $s ) );
	}

	/**
	 * Định dạng Title Case tiếng Việt sang trọng cho tên sản phẩm trên Website, giữ in hoa NEXXI / 2H / 3D / 4C.
	 */
	public static function format_vi_title_case( $text ) {
		$s = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $s ) {
			return '';
		}
		$titled = mb_convert_case( mb_strtolower( $s, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
		$titled = preg_replace_callback(
			'/(?<![\p{L}\p{N}])(nexxi|[1-9][hc]|[23]d|uv|upf|ykk)(?![\p{L}\p{N}])/iu',
			static function ( $m ) {
				return strtoupper( $m[1] );
			},
			$titled
		);
		return trim( $titled );
	}

	/**
	 * Tạo khóa chữ thường không dấu để khử trùng lặp dòng (Fingerprint Deduplication).
	 */
	private static function text_fingerprint( $text ) {
		$s = mb_strtolower( trim( (string) $text ), 'UTF-8' );
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		} else {
			$s = strtr(
				$s,
				array(
					'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
					'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
					'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
					'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'ơ','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
					'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
					'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
				)
			);
		}
		$s = preg_replace( '/^\d{3,4}\.\d+\s*/', '', $s );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	}

	/**
	 * Cắt chuỗi theo ranh giới từ tiếng Việt an toàn, không cắt cụt giữa từ hoặc để sót từ ghép lửng ở cuối câu.
	 */
	private static function trim_words_to_chars( $text, $max_chars ) {
		$s = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $s, 'UTF-8' ) <= $max_chars ) {
			return $s;
		}
		$cut = mb_substr( $s, 0, $max_chars, 'UTF-8' );
		$sp  = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $sp && $sp >= (int) ( $max_chars * 0.55 ) ) {
			$cut = mb_substr( $cut, 0, $sp, 'UTF-8' );
		}
		$cut = rtrim( $cut, " \t\n\r\0\x0B,;:-–—|+/" );
		// Gỡ từ đệm/từ ghép bị cắt dở ở cuối (VD: "...Form Rộng Bé", "...Quần Dù Ống", "...dáng croptop trẻ")
		$trimmed_tail = preg_replace(
			'/\s+(?:bé|ống|cổ|tay|sọc|số|lót|phối|trẻ|dáng|vải|chất|áo|quần|chân|có|cho|với|và|kết|hợp|mềm|co|thấm|thoáng|form|kiểu|họa|in|thêu)$/iu',
			'',
			$cut
		);
		if ( null !== $trimmed_tail && mb_strlen( $trimmed_tail, 'UTF-8' ) >= (int) ( $max_chars * 0.5 ) ) {
			$cut = rtrim( $trimmed_tail, " \t\n\r\0\x0B,;:-–—|+/" );
		}
		return $cut;
	}

	/**
	 * Đường ống 6 bước làm sạch văn bản tham khảo từ Shopee & `note_product` POS (6-Stage Sanitizer).
	 * Tách câu dính liền `.- `, khử lặp 2–8 lần, quét sạch 100% `#hashtag`, emoji, U+FE0F và CTA sàn ngoại.
	 *
	 * @param string $raw_text  Văn bản gộp từ mô tả Shopee và `note_product` POS.
	 * @param string $pos_name  Tên gốc POS để loại trừ dòng chỉ lặp tên mã lô.
	 * @param string $web_title Tên chuẩn Website để loại trừ dòng lặp tiêu đề.
	 * @return string[]         Danh sách các dòng thông tin thực tế sạch.
	 */
	public static function sanitize_reference_lines( $raw_text, $pos_name = '', $web_title = '' ) {
		$txt = html_entity_decode( (string) $raw_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === trim( $txt ) ) {
			return array();
		}

		// Bước 1: Chuẩn hóa ngắt dòng HTML & tách gạch đầu dòng bị dính liền trên POS (VD: "...Hồng.- Chất vải...")
		$txt = preg_replace( '/<\s*(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/iu', "\n", $txt );
		$txt = wp_strip_all_tags( $txt );
		$txt = str_replace( array( "\r\n", "\r" ), "\n", $txt );
		$txt = preg_replace( '/(?<=[^\s\r\n])(-\s+[A-ZÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ])/u', "\n$1", $txt );

		// Bước 2 & 3: Xóa U+FE0F, emoji trang trí và toàn bộ #hashtag sàn TMĐT
		$txt = preg_replace( '/[\x{FE0E}\x{FE0F}\x{200B}-\x{200D}\x{FEFF}\x{20E3}]/u', '', $txt );
		$txt = preg_replace( '/[\x{1F1E6}-\x{1F1FF}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', ' ', $txt );
		$txt = preg_replace( '/(?:^|\s)#[A-Za-z0-9_\p{L}]+/u', ' ', $txt );

		$fp_pos = '' !== $pos_name ? self::text_fingerprint( $pos_name ) : '';
		$fp_web = '' !== $web_title ? self::text_fingerprint( $web_title ) : '';

		$seen = array();
		$out  = array();

		foreach ( preg_split( '/\n+/u', $txt ) as $line ) {
			$line = trim( preg_replace( '/^[\s\-\*\•\+>▪▫◦]+/u', '', trim( $line ) ) );
			if ( '' === $line || preg_match( '/^[-=_*~.]{3,}$/u', $line ) ) {
				continue;
			}

			// Bước 4: Loại bỏ câu điều hướng sàn ngoại / câu rác sàn Shopee hoặc dòng liệt kê màu đã đưa xuống mục Bảng màu riêng
			if ( preg_match( '/(shopee|gian\s*hàng|follow\s*shop|theo\s*dõi\s*shop|áp\s*mã|áp\s*voucher|freeship|hoàn\s*xu|livestream|inbox\s*cho\s*shop|nhắn\s*tin\s*cho\s*shop|quay\s*video|đánh\s*giá\s*5\s*sao)/iu', $line ) ) {
				continue;
			}
			if ( preg_match( '/^(?:có\s*\d+\s*màu|tone\s*màu|gam\s*màu|bảng\s*màu|màu\s*sắc)\s*[:\-]/iu', $line ) ) {
				continue;
			}
			// Loại bỏ dòng chỉ chứa chuỗi từ khóa ngăn cách dấu phẩy cuối bài Shopee
			if ( substr_count( $line, ',' ) >= 4 && mb_strlen( $line, 'UTF-8' ) > 90 && ! preg_match( '/(chất|thiết\s*kế|áo|váy|quần)\s+.+\./iu', $line ) ) {
				continue;
			}

			// Bước 5: Đồng bộ chính sách 15 ngày đổi hàng, chuẩn hóa chính tả và làm mượt khẩu ngữ POS
			$line = preg_replace( '/\b0?7\s*ngày\b/iu', '15 ngày', $line );
			$line = self::normalize_shopee_vi_orthography( $line );
			$line = preg_replace( '/,?\s*\b(?:xịn\s*nhất\s*ạ|xịn\s*sò(?:\s*ạ)?|siêu\s*ngầu|siêu\s*sang|ưng\s*vô\s*cùng|hottrend|hot\s*trend)\b[!.]*/iu', '', $line );
			$line = preg_replace( '/\b4C\b/u', '4 chiều', $line );
			$line = preg_replace( '/\bmix\b/iu', 'kết hợp', $line );
			$line = preg_replace( '/\bstyle\s+sporty\b/iu', 'Phong cách thể thao', $line );
			$line = preg_replace( '/\bstyle\b/iu', 'phong cách', $line );
			$line = preg_replace( '/\bcool\s*ngầu\b/iu', 'khỏe khoắn', $line );
			$line = trim( preg_replace( '/\s{2,}/u', ' ', $line ), " \t,;:-–" );

			if ( mb_strlen( $line, 'UTF-8' ) < 10 ) {
				continue;
			}

			// Bước 6: Khử trùng lặp theo Fingerprint không dấu
			$fp = self::text_fingerprint( $line );
			if ( '' === $fp || isset( $seen[ $fp ] ) ) {
				continue;
			}
			if ( ( '' !== $fp_pos && $fp === $fp_pos ) || ( '' !== $fp_web && $fp === $fp_web ) ) {
				continue;
			}
			$seen[ $fp ] = true;

			// Viết hoa chữ cái đầu câu và kết thúc bằng dấu chấm gọn gàng
			$first_ch = mb_strtoupper( mb_substr( $line, 0, 1, 'UTF-8' ), 'UTF-8' );
			$line     = $first_ch . mb_substr( $line, 1, null, 'UTF-8' );
			if ( ! preg_match( '/[.!?]$/u', $line ) ) {
				$line .= '.';
			}
			$out[] = $line;
			if ( count( $out ) >= 6 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Làm sạch & hợp nhất Tiêu đề Shopee tham khảo + Tên gốc POS để sinh Tên sản phẩm chuẩn Website (`post_title`).
	 * Sửa 30+ lỗi chính tả phổ biến, bóc mã lô `2610.11`, gỡ lặp từ khóa sàn Shopee, giữ định danh mẫu thật và kết thúc bằng `NEXXI`.
	 *
	 * @param string $shopee_title Tiêu đề lấy từ Shopee POS / OpenGraph.
	 * @param string $pos_name     Tên gốc trên POS.
	 * @param string $note_text    Ghi chú `note_product` trên POS (để đối chiếu chính xác chất vải/cấu tạo).
	 * @param string $sku          Mã SKU cha.
	 * @return string
	 */
	public static function clean_shopee_reference_title_for_web( $shopee_title, $pos_name = '', $note_text = '', $sku = '' ) {
		$clean_strip = static function ( $raw_in ) {
			$t = trim( (string) $raw_in );
			if ( '' === $t ) {
				return '';
			}
			$t = preg_replace( '/^\s*(\[[^\]]{1,60}\]|\((?:freeship|hỏa\s*tốc|mẫu\s*mới|sẵn\s*hàng|sale|giá\s*sốc)[^)]{0,40}\))\s*/iu', '', $t );
			$t = preg_replace( '/^\s*(\[[^\]]{1,60}\])\s*/iu', '', $t );
			$t = preg_replace( '/^\s*\d{3,4}\.\d+\s*[-–:]?\s*/u', '', $t );
			$t = preg_replace( '/\s*\|\s*Shopee.*$/iu', '', $t );
			$t = preg_replace( '/\[[^\]]*(?:giá\s*sốc|sale|hàng\s*sẵn|mẫu\s*mới|freeship|xả\s*kho|tặng\s*kèm|mã\s*[^\]]+)[^\]]*\]/iu', ' ', $t );
			$t = preg_replace( '/(?:^|\s)#[A-Za-z0-9_\p{L}]+/u', ' ', $t );
			$t = preg_replace( '/\b(?:cho\s*bé\s*(?:từ\s*)?)?(?:size\s*(?:đại\s*)?)?\d{1,2}\s*(?:kg)?\s*(?:[-–~]|đến|tới)\s*\d{2}\s*kg\b/iu', ' ', $t );
			$t = preg_replace( '/\b(?:hottrend|hot\s*trend|hottend|hotrend|siêu\s*xinh|cực\s*xinh|siêu\s*mát|cực\s*mát|siêu\s*hot|cực\s*hot|cực\s*đẹp|siêu\s*đáng\s*yêu|siêu\s*dễ\s*thương|giá\s*rẻ|giá\s*sốc|xả\s*lỗ|bán\s*chạy)\b/iu', ' ', $t );
			$t = Nexxi_SKU_SEO_Sync::normalize_shopee_vi_orthography( $t );
			return trim( preg_replace( '/\s{2,}/u', ' ', $t ), " \t\n\r\0\x0B-–|,+" );
		};

		$s_clean = self::is_raw_pos_title( $shopee_title ) ? '' : $clean_strip( $shopee_title );
		$p_clean = $clean_strip( $pos_name );
		$n_clean = self::normalize_shopee_vi_orthography( (string) $note_text );

		// Xác định giới tính chuẩn từ Shopee Title -> POS Name -> Note
		$gender_src = mb_strtolower( $s_clean . ' ' . $p_clean . ' ' . $n_clean, 'UTF-8' );
		$has_girl   = (bool) preg_match( '/\bbé\s*gái\b/u', mb_strtolower( $s_clean . ' ' . $p_clean, 'UTF-8' ) );
		$has_boy    = (bool) preg_match( '/\bbé\s*trai\b/u', mb_strtolower( $s_clean . ' ' . $p_clean, 'UTF-8' ) );
		if ( ! $has_girl && ! $has_boy ) {
			$has_girl = (bool) preg_match( '/(váy|đầm|chân\s*váy|bèo|nơ|croptop|babytee|công\s*chúa|tiểu\s*thư|có\s*chip)/u', $gender_src );
			$has_boy  = (bool) preg_match( '/(lịch\s*lãm|soái\s*ca|cowboy)/u', $gender_src );
		}
		if ( $has_girl && ! $has_boy ) {
			$gender_tag = 'Bé Gái';
		} elseif ( $has_boy && ! $has_girl ) {
			$gender_tag = 'Bé Trai';
		} else {
			$gender_tag = 'Cho Bé';
		}

		$pos_has_diacritics = ( '' !== $p_clean && (bool) preg_match( '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $p_clean ) );
		$evidence_low       = mb_strtolower( $s_clean . ' ' . $p_clean . ' ' . $n_clean, 'UTF-8' );

		if ( $pos_has_diacritics && mb_strlen( $p_clean, 'UTF-8' ) >= 16 ) {
			// Đường dẫn ưu tiên: Tên mẫu có dấu trên POS đã súc tích & đúng định danh mẫu -> làm giàu thêm 1-2 chi tiết xác thực từ Shopee/Note
			$base = $p_clean;
			// Gỡ mã ký hiệu nội bộ cuối câu như "2H" nếu đã có mô tả tay sọc rõ ràng
			$base = trim( preg_replace( '/\s+\b2H\b$/iu', '', $base ) );
			// Chuẩn hóa "Da Cá" -> "Nỉ Da Cá" khi note_product xác nhận nỉ da cá
			if ( false !== mb_strpos( $evidence_low, 'nỉ da cá', 0, 'UTF-8' ) && preg_match( '/(?<!nỉ\s)\bda\s+cá\b/iu', $base ) ) {
				$base = preg_replace( '/(?<!nỉ\s)\bda\s+cá\b/iu', 'Nỉ Da Cá', $base );
			}
			// Bổ sung "Dài Tay" nếu Shopee/Note xác nhận dài tay mà tên POS chưa ghi
			if ( preg_match( '/\bdài\s+tay\b/iu', $evidence_low ) && ! preg_match( '/\b(dài\s+tay|tay\s+dài|ngắn\s+tay|sát\s+nách|lửng)\b/iu', $base ) ) {
				if ( preg_match( '/\bcổ\s+sơ\s+mi\b/iu', $base ) ) {
					$base = preg_replace( '/(\bcổ\s+sơ\s+mi\b)/iu', 'Dài Tay $1', $base, 1 );
				} elseif ( preg_match( '/\btay\s+sọc\b/iu', $base ) ) {
					$base = preg_replace( '/\btay\s+sọc\b/iu', 'Dài Tay Sọc', $base, 1 );
				} else {
					$base .= ' Dài Tay';
				}
			}
			// Bổ sung "2 Lớp Lót Lưới" cho áo khoác gió nếu nguồn xác thực
			if ( preg_match( '/\báo\s+khoác\s+gió\b/iu', $base ) && false !== mb_strpos( $evidence_low, 'lót', 0, 'UTF-8' ) && false !== mb_strpos( $evidence_low, 'lưới', 0, 'UTF-8' ) && false === mb_stripos( $base, 'lót lưới', 0, 'UTF-8' ) ) {
				$l_tag = ( false !== mb_strpos( $evidence_low, '2 lớp', 0, 'UTF-8' ) ) ? '2 Lớp Lót Lưới' : 'Lót Lưới';
				$base  = preg_replace( '/(\báo\s+khoác\s+gió\b)/iu', '$1 ' . $l_tag, $base, 1 );
			}
			// Bổ sung "Ống Cong" cho quần dù nếu nguồn xác thực
			if ( preg_match( '/\bquần\s+dù\b/iu', $base ) && false !== mb_strpos( $evidence_low, 'ống cong', 0, 'UTF-8' ) && false === mb_stripos( $base, 'ống cong', 0, 'UTF-8' ) ) {
				$base = preg_replace( '/(\bquần\s+dù\b)/iu', '$1 Ống Cong', $base, 1 );
			}
			// Bổ sung "Form Rộng" nếu tiêu đề ngắn và nguồn xác thực form rộng
			if ( mb_strlen( $base, 'UTF-8' ) <= 38 && false !== mb_strpos( $evidence_low, 'form rộng', 0, 'UTF-8' ) && false === mb_stripos( $base, 'form rộng', 0, 'UTF-8' ) ) {
				$base .= ' Form Rộng';
			}

			// Đảo vế "Set Quần Dù ... Phối Áo Thun Dài Tay Sọc" -> "Set Bộ Thun Dài Tay Sọc Phối Quần Dù ..." nếu Shopee định vị là Bộ Thun Dài Tay
			if ( preg_match( '/^Set\s+Quần\s+Dù(?:\s+Ống\s+Cong)?\s+Phối\s+Áo\s+Thun\s+(.+)$/iu', $base, $m_swap ) && preg_match( '/^Bộ\s+Thun\s+Dài\s+Tay/iu', $s_clean ) ) {
				$has_oc = false !== mb_stripos( $base, 'Ống Cong', 0, 'UTF-8' );
				$base   = 'Set Bộ Thun ' . trim( $m_swap[1] ) . ' Phối Quần Dù' . ( $has_oc ? ' Ống Cong' : '' );
			}

			$base = preg_replace( '/\b(?:bé\s*gái|bé\s*trai|cho\s*bé|nexxi(?:\s*kids)?)\b/iu', ' ', $base );
			$base = self::format_vi_title_case( $base );
			$base = self::trim_words_to_chars( $base, 78 - mb_strlen( ' ' . $gender_tag . ' NEXXI', 'UTF-8' ) );
			return trim( $base . ' ' . $gender_tag . ' NEXXI' );
		}

		if ( '' !== $s_clean ) {
			// Trường hợp tên POS viết tắt không dấu hoặc quá ngắn -> Khử nhồi từ khóa trực tiếp trên tiêu đề Shopee
			$t = preg_replace( '/\bNEXXI(?:\s+Kids(?:\s+Fashion)?)?\b/iu', ' ', $s_clean );
			$t = preg_replace( '/\bcho\s+bé\s+mặc\s+(?:hàng\s+ngày|hằng\s+ngày|quanh\s+năm|đi\s+chơi|đi\s+học)\b/iu', ' ', $t );
			$t = str_replace( '+', ' Phối ', $t );
			$clauses = preg_split( '/\s*(?:[|–—]|(?<=\S)\s+-\s+)\s*/u', $t );
			$kept    = array();
			foreach ( (array) $clauses as $idx => $cl ) {
				$cl = trim( preg_replace( '/\b(?:bé\s*gái|bé\s*trai|cho\s*bé)\b/iu', ' ', $cl ) );
				$cl = trim( preg_replace( '/\s{2,}/u', ' ', $cl ), ' ,;:-–' );
				if ( '' === $cl ) {
					continue;
				}
				if ( $idx > 0 ) {
					// Bỏ cụm danh từ loại đồ bị lặp lại ở mệnh đề thứ 2 của Shopee
					$cl = preg_replace( '/^(?:set\s+bộ(?:\s+thu\s+đông|\s+mùa\s+hè)?|bộ\s+đồ|set\s+đồ|váy|đầm|áo\s+khoác)\s+/iu', '', $cl );
				}
				if ( '' !== trim( $cl ) ) {
					$kept[] = trim( $cl );
				}
				if ( count( $kept ) >= 2 ) {
					break;
				}
			}
			$merged_t = implode( ' ', $kept );
			// Khử từ liền kề bị lặp
			$merged_t = self::format_vi_title_case( $merged_t );
			$merged_t = self::trim_words_to_chars( $merged_t, 78 - mb_strlen( ' ' . $gender_tag . ' NEXXI', 'UTF-8' ) );
			if ( '' !== $merged_t ) {
				return trim( $merged_t . ' ' . $gender_tag . ' NEXXI' );
			}
		}

		// Dự phòng cuối cùng nếu cả Shopee và POS đều chỉ có mã viết tắt không dấu
		$fb = self::build_smart_fallback_seo( $p_clean ?: $pos_name, '', '', $sku );
		return $fb['clean_title'];
	}

	/**
	 * Trích xuất các tín hiệu chất liệu, cấu tạo và dịp mặc CÓ BẰNG CHỨNG XÁC THỰC (Zero Hallucination Gate).
	 * Tuyệt đối không suy diễn hay bịa thêm thành phần vải/chi tiết không xuất hiện trong dữ liệu nguồn.
	 *
	 * @param string $combined_text Văn bản nguồn hợp nhất (`shopee_title + shopee_desc + pos_name + note_product`).
	 * @return array                `['fabrics' => string[], 'pa_terms' => string[], 'details' => string[], 'occasions' => string[]]`.
	 */
	private static function extract_verified_garment_signals( $combined_text ) {
		$low = mb_strtolower( self::normalize_shopee_vi_orthography( (string) $combined_text ), 'UTF-8' );

		$fabrics  = array();
		$pa_terms = array();

		if ( preg_match( '/cotton\s*(?:co\s*giãn\s*)?4\s*(?:chiều|c)\b/u', $low ) ) {
			$fabrics[]  = 'thun cotton 4 chiều mềm mịn';
			$pa_terms[] = 'Cotton 4 chiều';
		} elseif ( preg_match( '/\bcotton\b/u', $low ) ) {
			$fabrics[]  = 'thun cotton mềm mát';
			$pa_terms[] = 'Cotton';
		}

		if ( preg_match( '/dù\s*nhăn/u', $low ) ) {
			$fabrics[]  = 'vải dù nhăn mềm nhẹ';
			$pa_terms[] = 'Vải dù nhăn';
		} elseif ( preg_match( '/\b(?:vải\s*dù|quần\s*dù|khoác\s*dù)\b/u', $low ) ) {
			$fabrics[]  = 'vải dù thoáng nhẹ';
			$pa_terms[] = 'Vải dù';
		}

		if ( preg_match( '/lót\s*(?:vải\s*)?lưới/u', $low ) ) {
			$fabrics[]  = 'lót lưới thoáng khí';
			$pa_terms[] = 'Lót lưới thoáng khí';
		}
		if ( preg_match( '/(?:nỉ\s*da\s*cá|\bda\s*cá\b)/u', $low ) ) {
			$fabrics[]  = 'nỉ da cá co giãn';
			$pa_terms[] = 'Nỉ da cá';
		}
		if ( preg_match( '/sọc\s*xốp/u', $low ) ) {
			$fabrics[]  = 'phối vải sọc xốp mềm mại';
			$pa_terms[] = 'Vải sọc xốp';
		}
		if ( preg_match( '/thun\s*gân/u', $low ) ) {
			$fabrics[]  = 'thun gân co giãn mềm mát';
			$pa_terms[] = 'Vải thun gân';
		}
		if ( preg_match( '/thun\s*lạnh/u', $low ) ) {
			$fabrics[]  = 'thun lạnh mát mịn';
			$pa_terms[] = 'Thun lạnh';
		}
		if ( preg_match( '/thun\s*cá\s*sấu/u', $low ) ) {
			$fabrics[]  = 'thun cá sấu đứng phom';
			$pa_terms[] = 'Thun cá sấu';
		}
		if ( preg_match( '/\bkate\b/u', $low ) ) {
			$fabrics[]  = 'vải kate mềm thoáng';
			$pa_terms[] = 'Vải kate';
		}
		if ( preg_match( '/\bđũi\b/u', $low ) ) {
			$fabrics[]  = 'vải đũi thoáng nhẹ';
			$pa_terms[] = 'Vải đũi';
		}
		if ( preg_match( '/\blinen\b/u', $low ) ) {
			$fabrics[]  = 'vải linen thoáng mát';
			$pa_terms[] = 'Linen';
		}
		if ( preg_match( '/\b(?:jean|denim)\b/u', $low ) ) {
			$fabrics[]  = 'vải jean mềm năng động';
			$pa_terms[] = 'Jean mềm';
		}
		if ( preg_match( '/\bkaki\b/u', $low ) ) {
			$fabrics[]  = 'vải kaki đứng phom';
			$pa_terms[] = 'Vải kaki';
		}
		if ( preg_match( '/\b(?:tơ\s*nến|vải\s*tơ|organza)\b/u', $low ) ) {
			$fabrics[]  = 'vải tơ mềm bồng bềnh';
			$pa_terms[] = 'Vải tơ';
		}
		if ( preg_match( '/\bvoan\b/u', $low ) ) {
			$fabrics[]  = 'vải voan nhẹ nhàng';
			$pa_terms[] = 'Vải voan';
		}
		if ( preg_match( '/\bborip\b/u', $low ) ) {
			$fabrics[] = 'viền sọc borip';
		}

		$details = array();
		$detail_map = array(
			'/có\s*chip|quần\s*bảo\s*hộ/u' => 'lớp quần chip bảo hộ kín đáo bên trong',
			'/\b2\s*lớp\b/u'               => 'cấu trúc may 2 lớp chỉn chu',
			'/\bcó\s*nón\b/u'              => 'thiết kế có nón năng động',
			'/cổ\s*sơ\s*mi/u'              => 'phối cổ sơ mi thanh lịch',
			'/dây\s*rút/u'                 => 'chi tiết phối dây rút cá tính',
			'/ống\s*cong/u'                => 'quần dáng ống cong hiện đại',
			'/ống\s*suông/u'               => 'dáng ống suông thoải mái',
			'/xếp\s*ly/u'                  => 'đường xếp ly giữ nếp gọn đẹp',
			'/form\s*rộng/u'               => 'phom rộng thoáng thoải mái vận động',
			'/\bcroptop\b/u'               => 'dáng croptop trẻ trung vừa vặn',
			'/sát\s*nách/u'                => 'kiểu sát nách thoáng mát',
			'/in\s*2\s*hình/u'             => 'họa tiết in 2 hình sắc nét',
			'/số\s*86\b|màu\s*86\b/u'      => 'điểm nhấn phối màu số 86 khỏe khoắn',
			'/số\s*5\s*cowboy/u'           => 'điểm nhấn in chữ Số 5 Cowboy cá tính',
			'/los\s*angeles/u'             => 'điểm nhấn chữ Los Angeles hiện đại',
			'/\bpretty\b/u'                => 'điểm nhấn chữ Pretty xinh xắn',
		);
		foreach ( $detail_map as $pat => $lbl ) {
			if ( preg_match( $pat, $low ) ) {
				$details[] = $lbl;
			}
		}

		$occasions = array();
		if ( preg_match( '/\b(?:mặc\s*tết|diện\s*tết|du\s*xuân|lễ\s*hội)\b/u', $low ) ) {
			$occasions[] = 'diện Tết, du xuân, dự tiệc';
		}
		if ( preg_match( '/chống\s*nắng/u', $low ) ) {
			$occasions[] = 'che nắng, chắn gió giao mùa quanh năm';
		}
		if ( preg_match( '/thu\s*đông|giao\s*mùa|se\s*lạnh/u', $low ) ) {
			$occasions[] = 'mặc đẹp ngày giao mùa và thu đông';
		}
		$occasions[] = 'đi học, đi chơi, dạo phố hay chụp ảnh cùng gia đình';

		return array(
			'fabrics'   => array_values( array_unique( $fabrics ) ),
			'pa_terms'  => array_values( array_unique( array_filter( $pa_terms ) ) ),
			'details'   => array_values( array_unique( $details ) ),
			'occasions' => array_values( array_unique( $occasions ) ),
		);
	}

	/**
	 * Chuyển nhãn màu phân loại không dấu từ POS (`Kem xanh`, `Trang do`, `Kem nau`, `Do`, `Hong`, `Xam`)
	 * sang tiếng Việt có dấu tự nhiên, không kèm mã code in hoa trong ngoặc.
	 */
	public static function pretty_color_for_prose( $raw_color ) {
		$raw = trim( (string) $raw_color );
		if ( '' === $raw ) {
			return '';
		}
		$clean_key = self::text_fingerprint( $raw );
		$exact_map = array(
			'trang'      => 'Trắng',
			'trang kem'  => 'Trắng Kem',
			'trang do'   => 'Trắng Phối Đỏ',
			'trang xanh' => 'Trắng Phối Xanh',
			'trang den'  => 'Trắng Phối Đen',
			'do'         => 'Đỏ',
			'do dau'     => 'Đỏ Dâu',
			'do do'      => 'Đỏ Đô',
			'hong'       => 'Hồng',
			'hong dam'   => 'Hồng Đậm',
			'hong phan'  => 'Hồng Phấn',
			'hong nhat'  => 'Hồng Nhạt',
			'hong sen'   => 'Hồng Sen',
			'den'        => 'Đen',
			'xanh'       => 'Xanh',
			'xanh la'    => 'Xanh Lá',
			'xanh duong' => 'Xanh Dương',
			'xanh den'   => 'Xanh Đen',
			'xanh nhat'  => 'Xanh Nhạt',
			'xanh ngoc'  => 'Xanh Ngọc',
			'xanh reu'   => 'Xanh Rêu',
			'reu'        => 'Xanh Rêu',
			'vang'       => 'Vàng',
			'kem'        => 'Kem',
			'kem xanh'   => 'Kem Phối Xanh',
			'kem nau'    => 'Kem Nâu',
			'kem den'    => 'Kem Đen',
			'kem do'     => 'Kem Đỏ',
			'be'         => 'Be',
			'nau'        => 'Nâu',
			'tim'        => 'Tím',
			'xam'        => 'Xám',
			'xam trang'  => 'Xám Phối Trắng',
			'ghi'        => 'Ghi',
			'cam'        => 'Cam',
			'soc'        => 'Kẻ Sọc',
			'caro'       => 'Caro',
		);
		if ( isset( $exact_map[ $clean_key ] ) ) {
			return $exact_map[ $clean_key ];
		}
		if ( class_exists( 'Nexxi_Pancake_Sync_Manager_V7' ) ) {
			$lbl = Nexxi_Pancake_Sync_Manager_V7::pretty_class_label( $raw );
			$lbl = trim( preg_replace( '/\s*\([A-Z0-9_\-\s]+\)\s*$/u', '', $lbl ) );
			if ( '' !== $lbl && mb_strtoupper( $lbl, 'UTF-8' ) !== $lbl ) {
				return self::format_vi_title_case( $lbl );
			}
		}
		$words    = explode( ' ', $clean_key );
		$word_map = array(
			'trang' => 'Trắng', 'do' => 'Đỏ', 'hong' => 'Hồng', 'den' => 'Đen',
			'xanh' => 'Xanh', 'la' => 'Lá', 'duong' => 'Dương', 'ngoc' => 'Ngọc',
			'vang' => 'Vàng', 'kem' => 'Kem', 'be' => 'Be', 'nau' => 'Nâu',
			'tim' => 'Tím', 'xam' => 'Xám', 'ghi' => 'Ghi', 'cam' => 'Cam',
			'reu' => 'Rêu', 'dam' => 'Đậm', 'nhat' => 'Nhạt', 'phan' => 'Phấn',
		);
		$mapped_any = false;
		foreach ( $words as &$w ) {
			if ( isset( $word_map[ $w ] ) ) {
				$w          = $word_map[ $w ];
				$mapped_any = true;
			}
		}
		unset( $w );
		if ( $mapped_any ) {
			return self::format_vi_title_case( implode( ' ', $words ) );
		}
		return self::format_vi_title_case( $raw );
	}

	/**
	 * Gán tự động các chất liệu xác thực vào taxonomy `pa_chat-lieu` của sản phẩm WooCommerce
	 * để kích hoạt huy hiệu chất liệu trên ảnh PDP (`stitch-product.php:208`) & Schema `ProductGroup.material`.
	 */
	private static function assign_verified_material_terms( $wc_id, array $pa_terms ) {
		$wc_id    = (int) $wc_id;
		$pa_terms = array_values( array_unique( array_filter( array_map( 'trim', $pa_terms ) ) ) );
		if ( $wc_id <= 0 || empty( $pa_terms ) || ! taxonomy_exists( 'pa_chat-lieu' ) ) {
			return;
		}

		$term_ids = array();
		foreach ( $pa_terms as $t_name ) {
			$existing = term_exists( $t_name, 'pa_chat-lieu' );
			if ( ! $existing ) {
				$inserted = wp_insert_term( $t_name, 'pa_chat-lieu', array( 'slug' => sanitize_title( $t_name ) ) );
				if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
					$term_ids[] = (int) $inserted['term_id'];
				}
			} elseif ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
				$term_ids[] = (int) $existing['term_id'];
			} elseif ( is_numeric( $existing ) ) {
				$term_ids[] = (int) $existing;
			}
		}
		if ( empty( $term_ids ) ) {
			return;
		}

		wp_set_object_terms( $wc_id, $term_ids, 'pa_chat-lieu', false );

		if ( function_exists( 'wc_get_product' ) && class_exists( 'WC_Product_Attribute' ) ) {
			$wc_prod = wc_get_product( $wc_id );
			if ( $wc_prod ) {
				$attrs = $wc_prod->get_attributes();
				$attrs = is_array( $attrs ) ? $attrs : array();
				if ( ! isset( $attrs['pa_chat-lieu'] ) ) {
					$tax_id = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? (int) wc_attribute_taxonomy_id_by_name( 'pa_chat-lieu' ) : 0;
					$attr_m = new WC_Product_Attribute();
					$attr_m->set_id( $tax_id );
					$attr_m->set_name( 'pa_chat-lieu' );
					$attr_m->set_options( $term_ids );
					$attr_m->set_position( count( $attrs ) );
					$attr_m->set_visible( true );
					$attr_m->set_variation( false );
					$attrs['pa_chat-lieu'] = $attr_m;
					$wc_prod->set_attributes( $attrs );
					$wc_prod->save();
				}
			}
		}
	}

	/**
	 * Làm sạch tên POS thô và tự sinh bộ SEO thông minh cho các sản phẩm nằm ngoài 2 file Excel
	 * để đảm bảo 100% sản phẩm trên Website đều đạt chuẩn SEO (Title <= 60 ký tự, chính sách 15 ngày đổi hàng).
	 */
	public static function build_smart_fallback_seo( $raw_title, $short_desc, $full_desc, $sku = '' ) {
		$title = trim( (string) $raw_title );
		// Bóc mã lô đầu dòng như "2603.8"
		$title = trim( preg_replace( '/^\s*\d{3,4}\.\d+\s*[-–:]?\s*/u', '', $title ) );
		$title = self::normalize_shopee_vi_orthography( $title );

		// Mở rộng một số từ viết tắt phổ biến từ POS nếu tên toàn chữ in hoa không dấu
		$letters = preg_replace( '/[^\p{L}]/u', '', $title );
		if ( '' !== $letters && mb_strtoupper( $letters, 'UTF-8' ) === $letters && ! preg_match( '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $title ) ) {
			$rep = array(
				'/\bCVAY\b/i'  => 'Chân Váy',
				'/\bAT\b/i'    => 'Áo Thun',
				'/\bQS\b/i'    => 'Quần Short',
				'/\bQD\b/i'    => 'Quần Dài',
				'/\bQLUNG\b/i' => 'Quần Lửng',
				'/\bAK\b/i'    => 'Áo Khoác',
				'/\bSM\b/i'    => 'Sơ Mi',
				'/\bCB\b/i'    => 'Cổ Bẻ',
				'/\bTT\b/i'    => 'Thể Thao',
				'/\bPM\b/i'    => 'Phối Màu',
				'/\bXLY\b/i'   => 'Xếp Ly',
				'/\bLG\b/i'    => 'Logo',
				'/\+/u'        => ' Phối ',
			);
			foreach ( $rep as $pat => $r ) {
				$title = preg_replace( $pat, $r, $title );
			}
			$title = self::format_vi_title_case( $title );
		}

		if ( '' === $title ) {
			$title = 'Thời Trang Trẻ Em NEXXI Kids ' . $sku;
		} else {
			$title = preg_replace( '/\bnexxi\b/iu', 'NEXXI', $title );
			if ( false === stripos( $title, 'NEXXI' ) ) {
				$title .= ' NEXXI';
			}
		}

		$t_low      = mb_strtolower( $title, 'UTF-8' );
		$is_girl    = (bool) preg_match( '/(bé gái|be gai|váy|vay|đầm|dam|yếm|bèo|nơ|babytee|croptop|có chip)/u', $t_low );
		$is_boy     = (bool) preg_match( '/(bé trai|be trai|lịch lãm|soái ca|cowboy)/u', $t_low );
		$gender_lbl = ( $is_girl && ! $is_boy ) ? 'bé gái' : ( ( $is_boy && ! $is_girl ) ? 'bé trai' : 'bé' );
		$gender_kw  = ( $is_girl && ! $is_boy ) ? 'bé gái' : ( ( $is_boy && ! $is_girl ) ? 'bé trai' : 'trẻ em' );

		if ( preg_match( '/(set\s+váy|chân\s+váy.*kèm|áo.*chân\s+váy)/u', $t_low ) ) {
			$primary_kw = 'set váy bé gái';
		} elseif ( preg_match( '/(đầm|váy)/u', $t_low ) && false === strpos( $t_low, 'chân váy' ) ) {
			$primary_kw = 'đầm bé gái';
		} elseif ( false !== strpos( $t_low, 'chân váy' ) ) {
			$primary_kw = 'chân váy bé gái';
		} elseif ( false !== strpos( $t_low, 'áo khoác' ) ) {
			$primary_kw = 'áo khoác ' . $gender_kw;
		} elseif ( preg_match( '/^(set|bộ|đồ\s+bộ)\b/u', $t_low ) ) {
			$primary_kw = 'đồ bộ ' . $gender_kw;
		} elseif ( false !== strpos( $t_low, 'quần' ) ) {
			$primary_kw = 'quần ' . $gender_kw;
		} elseif ( false !== strpos( $t_low, 'sơ mi' ) ) {
			$primary_kw = 'áo sơ mi ' . $gender_kw;
		} else {
			$primary_kw = 'áo thun ' . $gender_kw;
		}

		// SEO Title chuẩn SERP Google & seo-technical.php (38–60 ký tự, tuyệt đối <= 60 ký tự)
		$suffix   = ' | NEXXI Kids'; // 13 ký tự
		$core     = trim( preg_replace( '/\bNEXXI(?:\s+Kids(?:\s+Fashion)?)?\b/iu', '', $title ), " \t\n\r\0\x0B-–,|" );
		$core     = preg_replace( '/\s{2,}/u', ' ', $core );
		$max_core = 60 - mb_strlen( $suffix, 'UTF-8' ); // 47 ký tự
		$seo_core = self::trim_words_to_chars( $core, $max_core );
		if ( mb_strlen( $seo_core, 'UTF-8' ) < 26 ) {
			$seo_core = self::trim_words_to_chars( $seo_core . ' Cho ' . self::format_vi_title_case( $gender_kw ), $max_core );
		}
		$seo_title = $seo_core . $suffix;

		// Short & Full Description fallback nếu sản phẩm chưa có mô tả
		$gen_short = trim( (string) $short_desc );
		if ( '' === $gen_short ) {
			$plain_full = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $full_desc ) ) );
			if ( mb_strlen( $plain_full, 'UTF-8' ) >= 60 ) {
				$gen_short = mb_strlen( $plain_full, 'UTF-8' ) > 235 ? self::trim_words_to_chars( $plain_full, 230 ) . '.' : $plain_full;
			} else {
				$gen_short = sprintf(
					'%s thiết kế chuẩn phom nhà NEXXI Kids với bề mặt vải mềm thoáng, đường may êm ái giúp %s thoải mái vận động khi đi học, đi chơi và dạo phố hằng ngày.',
					$title,
					$gender_lbl
				);
			}
		}

		$return_url = function_exists( 'home_url' ) ? home_url( '/doi-tra/' ) : 'https://nexxi.vn/doi-tra/';
		$gen_full   = trim( (string) $full_desc );
		if ( '' === $gen_full ) {
			$gen_full = '<p><strong>' . esc_html( $title ) . '</strong> là thiết kế thời trang trẻ em được <strong>NEXXI Kids</strong> chăm chút tỉ mỉ từ bề mặt chất liệu đến phom dáng, mang lại sự dễ chịu tối đa cho ' . esc_html( $gender_lbl ) . ' trong suốt ngày dài năng động.</p>'
				. '<h3>Chất liệu &amp; Thiết kế nổi bật</h3>'
				. '<ul>'
				. '<li><strong>Chất liệu êm thoáng:</strong> Bề mặt vải mềm mịn, thoáng khí, thấm hút mồ hôi tốt và thân thiện với làn da nhạy cảm của trẻ nhỏ.</li>'
				. '<li><strong>Phom dáng chuẩn đẹp:</strong> Đường cắt may gọn gàng, chắc chắn, tôn nét đáng yêu và khỏe khoắn để ' . esc_html( $gender_lbl ) . ' tự tin diện đi học, đi chơi hay dạo phố cuối tuần.</li>'
				. '</ul>'
				. '<h3>Bảng màu &amp; Gợi ý chọn size theo cân nặng</h3>'
				. '<ul>'
				. '<li><strong>Đa dạng kích cỡ chuẩn phom Việt:</strong> Ba mẹ có thể nhập cân nặng và chiều cao của bé vào khung <em>Bảng Quy Đổi Kích Cỡ Trang Phục</em> ngay phía trên trang sản phẩm để chọn đúng size vừa vặn nhất.</li>'
				. '</ul>'
				. '<h3>Cam kết chính hãng từ NEXXI Kids</h3>'
				. '<ul>'
				. '<li><strong>Chuẩn mẫu thiết kế NEXXI Kids:</strong> Hình ảnh thực tế chính chủ, kiểm tra kỹ từng đường kim mũi chỉ trước khi đóng gói giao đến tay ba mẹ.</li>'
				. '<li><strong>Hỗ trợ 15 ngày đổi hàng an tâm:</strong> Áp dụng <a href="' . esc_url( $return_url ) . '">chính sách hỗ trợ 15 ngày đổi hàng</a> nhanh chóng khi sản phẩm còn nguyên tem mác, chưa qua giặt ủi.</li>'
				. '</ul>';
		}

		// Meta Description chuẩn 140–155 ký tự (đồng bộ cứng "đổi hàng 15 ngày")
		$tail     = ' – chất vải mềm mát, phom chuẩn đẹp cho bé. Chính hãng NEXXI Kids, đổi hàng 15 ngày.'; // 84 ký tự
		$max_lead = 155 - mb_strlen( $tail, 'UTF-8' ); // 71 ký tự
		$lead     = self::trim_words_to_chars( $core, $max_lead );
		if ( mb_strlen( $lead . $tail, 'UTF-8' ) < 138 ) {
			$lead = self::trim_words_to_chars( $core . ' thiết kế mới cho ' . $gender_lbl, $max_lead );
		}
		$seo_desc = $lead . $tail;

		$kws = array_unique(
			array_filter(
				array(
					$primary_kw,
					$primary_kw . ' nexxi',
					mb_strtolower( $seo_core, 'UTF-8' ),
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
	 * Hậu kiểm và chuẩn hóa bản ghi Catalog 705 SKU để đảm bảo:
	 * 1) Xóa ký tự lỗi U+FE0F, 2) Đồng bộ chính sách 15 ngày đổi hàng, 3) SEO Title <= 60 ký tự.
	 */
	private static function sanitize_catalog_entry_for_web( array $matched ) {
		foreach ( array( 'name', 'short_description', 'description', 'seo_title', 'seo_description', 'og_title', 'og_description' ) as $f ) {
			if ( ! empty( $matched[ $f ] ) && is_string( $matched[ $f ] ) ) {
				$v = preg_replace( '/[\x{FE0E}\x{FE0F}\x{200B}-\x{200D}\x{FEFF}]/u', '', $matched[ $f ] );
				$v = preg_replace( '/\bđổi\s*(?:size|trả|hàng)\s*(?:nhanh\s*chóng\s*)?(?:trong\s*(?:vòng\s*)?)?0?7\s*ngày\b/iu', 'đổi hàng 15 ngày', $v );
				$matched[ $f ] = $v;
			}
		}
		if ( ! empty( $matched['seo_title'] ) && mb_strlen( $matched['seo_title'], 'UTF-8' ) > 60 ) {
			$suffix = ' | NEXXI Kids';
			$body   = preg_replace( '/\s*[|\-–]\s*NEXXI(?:\s+Kids(?:\s+Fashion)?)?\s*$/iu', '', $matched['seo_title'] );
			$body   = preg_replace( '/\bNEXXI(?:\s+Kids)?\b/iu', '', (string) $body );
			$matched['seo_title'] = self::trim_words_to_chars( $body, 60 - mb_strlen( $suffix, 'UTF-8' ) ) . $suffix;
			$matched['og_title']  = $matched['seo_title'];
		}
		return $matched;
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
	 * Cập nhật thuộc tính Alt giàu ngữ cảnh cho ảnh đại diện, ảnh biến thể màu & album chi tiết sản phẩm.
	 */
	private static function sync_product_image_alts( $thumb_id, array $gallery_ids, $clean_name, $wc_id = 0 ) {
		$clean_name = trim( wp_strip_all_tags( (string) $clean_name ) );
		if ( '' === $clean_name ) {
			return;
		}

		$color_alt_by_aid = array();
		if ( $wc_id > 0 ) {
			$class_map = get_post_meta( (int) $wc_id, '_nexxi_class_image_map', true );
			if ( is_array( $class_map ) ) {
				foreach ( $class_map as $ck => $aid ) {
					$aid = (int) $aid;
					if ( $aid > 0 && ! isset( $color_alt_by_aid[ $aid ] ) ) {
						$c_pretty = self::pretty_color_for_prose( (string) $ck );
						if ( '' !== $c_pretty ) {
							$color_alt_by_aid[ $aid ] = $clean_name . ' - Phiên bản màu ' . $c_pretty;
						}
					}
				}
			}
		}

		$img_ids = array_values( array_unique( array_filter( array_merge( array( (int) $thumb_id ), array_map( 'absint', $gallery_ids ), array_keys( $color_alt_by_aid ) ) ) ) );
		foreach ( $img_ids as $idx => $aid ) {
			$cur_alt = trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) );
			if ( '' === $cur_alt || self::is_raw_pos_title( $cur_alt ) || preg_match( '/^[a-f0-9_-]{8,}(?:\.[a-z0-9]+)?$/i', $cur_alt ) ) {
				if ( (int) $aid === (int) $thumb_id ) {
					$alt_txt = $clean_name;
				} elseif ( isset( $color_alt_by_aid[ $aid ] ) ) {
					$alt_txt = $color_alt_by_aid[ $aid ];
				} else {
					$alt_txt = $clean_name . ' - Chi tiết chất liệu và phom dáng ' . ( $idx + 1 );
				}
				update_post_meta( $aid, '_wp_attachment_image_alt', $alt_txt );
			}
		}
	}

	/**
	 * Tổng hợp trọn bộ Tên sản phẩm, Mô tả ngắn (160–240 ký tự), Mô tả HTML (>= 200 từ, 3 mục H3 semantic) & SEO
	 * cho sản phẩm MỚI trên Website dựa trên Link Shopee từ POS Marketplace + `note_product` POS + Biến thể thực tế.
	 *
	 * @param int   $wc_id        ID sản phẩm WooCommerce.
	 * @param array $pancake_item Mảng sản phẩm đã parse từ Pancake POS (`sku`, `name`, `note_product`, `variations`, `categories`...).
	 * @param array $shopee_ctx   Kết quả từ `lookup_pos_shopee_by_sku()` (`primary`, `all_urls`) + `og` từ `probe_shopee_public_og_metadata()`.
	 * @param array $write_flags  `['write_title' => bool, 'write_desc' => bool]` kiểm soát trường được phép ghi (bảo vệ SP cũ).
	 * @return array              `['applied' => bool, 'source' => string, 'title' => string, 'shopee_url' => string]`.
	 */
	public static function apply_shopee_and_pos_reference_to_wc_product( $wc_id, array $pancake_item, array $shopee_ctx = array(), array $write_flags = array() ) {
		global $wpdb;
		$wc_id = (int) $wc_id;
		$post  = $wc_id > 0 ? get_post( $wc_id ) : null;
		if ( ! $post || 'product' !== $post->post_type ) {
			return array( 'applied' => false, 'source' => '', 'title' => '', 'shopee_url' => '' );
		}

		$write_title = isset( $write_flags['write_title'] ) ? (bool) $write_flags['write_title'] : true;
		$write_desc  = isset( $write_flags['write_desc'] ) ? (bool) $write_flags['write_desc'] : true;

		$sku          = self::normalize_sku( $pancake_item['sku'] ?? get_post_meta( $wc_id, '_sku', true ) );
		$pos_name     = trim( (string) ( $pancake_item['name'] ?? '' ) );
		$note_product = trim( (string) ( $pancake_item['note_product'] ?? '' ) );
		$note_extra   = trim( (string) ( $pancake_item['note'] ?? '' ) );

		$primary_mp   = isset( $shopee_ctx['primary'] ) && is_array( $shopee_ctx['primary'] ) ? $shopee_ctx['primary'] : array();
		$all_mp_urls  = isset( $shopee_ctx['all_urls'] ) && is_array( $shopee_ctx['all_urls'] ) ? $shopee_ctx['all_urls'] : array();
		$og_data      = isset( $shopee_ctx['og'] ) && is_array( $shopee_ctx['og'] ) ? $shopee_ctx['og'] : array();

		$shopee_url   = trim( (string) ( $primary_mp['shopee_url'] ?? '' ) );
		$shopee_title = trim( (string) ( $og_data['title'] ?? '' ) );
		if ( '' === $shopee_title || self::is_raw_pos_title( $shopee_title ) ) {
			$shopee_title = trim( (string) ( $primary_mp['title'] ?? '' ) );
		}
		$shopee_desc  = trim( (string) ( $og_data['description'] ?? '' ) );

		// Lưu Post Meta truy vết nội bộ cho Admin Tab 1 (tuyệt đối không render link Shopee ra ngoài giao diện khách hàng)
		if ( '' !== $shopee_url ) {
			update_post_meta( $wc_id, '_nexxi_shopee_url', esc_url_raw( $shopee_url ) );
		}
		if ( ! empty( $all_mp_urls ) ) {
			update_post_meta( $wc_id, '_nexxi_shopee_all_urls', wp_json_encode( $all_mp_urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
		if ( '' !== $shopee_title ) {
			update_post_meta( $wc_id, '_nexxi_shopee_ref_title', $shopee_title );
		}

		// TẦNG 1: Khớp trong bộ 705 mã SKU chuẩn `sku-product-seo-catalog.json`
		$cat_matched = self::lookup_by_sku( $sku );
		if ( is_array( $cat_matched ) && ! empty( $cat_matched['name'] ) ) {
			$cat_clean = self::sanitize_catalog_entry_for_web( $cat_matched );
			$p_upd_cat = array();
			if ( $write_title ) {
				$p_upd_cat['post_title'] = $cat_clean['name'];
				if ( self::should_refresh_slug( $post->post_name ) ) {
					$old_slug = (string) $post->post_name;
					$new_slug = wp_unique_post_slug( sanitize_title( $cat_clean['name'] ), $wc_id, $post->post_status, 'product', 0 );
					if ( '' !== $new_slug && $new_slug !== $old_slug ) {
						$p_upd_cat['post_name'] = $new_slug;
						if ( '' !== $old_slug ) {
							add_post_meta( $wc_id, '_wp_old_slug', $old_slug );
						}
					}
				}
			}
			if ( $write_desc ) {
				$p_upd_cat['post_excerpt'] = $cat_clean['short_description'];
				$p_upd_cat['post_content'] = $cat_clean['description'];
			}
			if ( ! empty( $p_upd_cat ) ) {
				$wpdb->update( $wpdb->posts, $p_upd_cat, array( 'ID' => $wc_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $wc_id );
			}
			$thumb_id    = (int) get_post_thumbnail_id( $wc_id );
			$og_img      = $thumb_id > 0 ? (string) wp_get_attachment_url( $thumb_id ) : '';
			$gallery_raw = (string) get_post_meta( $wc_id, '_product_image_gallery', true );
			$gallery_ids = '' !== $gallery_raw ? array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) : array();
			if ( $write_title || $write_desc ) {
				self::write_seo_meta( $wc_id, $cat_clean, $og_img );
			}
			self::sync_product_image_alts( $thumb_id, $gallery_ids, $write_title ? $cat_clean['name'] : $post->post_title, $wc_id );
			if ( ! empty( $cat_clean['tags'] ) ) {
				$existing_tags = wp_get_object_terms( $wc_id, 'product_tag', array( 'fields' => 'ids' ) );
				if ( empty( $existing_tags ) || is_wp_error( $existing_tags ) ) {
					$tag_names = array_values( array_filter( array_map( 'trim', explode( '|', (string) $cat_clean['tags'] ) ) ) );
					if ( ! empty( $tag_names ) ) {
						wp_set_object_terms( $wc_id, $tag_names, 'product_tag', false );
					}
				}
			}
			$sig_cat = self::extract_verified_garment_signals( $cat_clean['name'] . ' ' . $cat_clean['short_description'] . ' ' . wp_strip_all_tags( $cat_clean['description'] ) );
			if ( ! empty( $sig_cat['pa_terms'] ) ) {
				self::assign_verified_material_terms( $wc_id, $sig_cat['pa_terms'] );
			}
			$src = '' !== $shopee_url ? 'catalog_json+pos_shopee' : 'catalog_json';
			update_post_meta( $wc_id, '_nexxi_sku_seo_ver', self::SYNC_VER );
			update_post_meta( $wc_id, '_nexxi_shopee_ref_source', $src );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $wc_id );
			}
			return array(
				'applied'    => ! empty( $p_upd_cat ),
				'source'     => $src,
				'title'      => (string) get_the_title( $wc_id ),
				'shopee_url' => $shopee_url,
			);
		}

		// TẦNG 2: Tổng hợp mới chuẩn SEO/AEO/GEO từ Link Shopee POS + note_product + Biến thể thực tế (Zero Hallucination)
		$synth = self::synthesize_web_copy_from_shopee_and_pos( $pancake_item, $shopee_ctx, $wc_id );

		$post_updates = array();
		if ( $write_title ) {
			$post_updates['post_title'] = $synth['post_title'];
			if ( self::should_refresh_slug( $post->post_name ) ) {
				$old_slug = (string) $post->post_name;
				$new_slug = wp_unique_post_slug( sanitize_title( $synth['post_title'] ), $wc_id, $post->post_status, 'product', 0 );
				if ( '' !== $new_slug && $new_slug !== $old_slug ) {
					$post_updates['post_name'] = $new_slug;
					if ( '' !== $old_slug ) {
						add_post_meta( $wc_id, '_wp_old_slug', $old_slug );
					}
				}
			}
		}
		if ( $write_desc ) {
			$post_updates['post_excerpt'] = $synth['short_description'];
			$post_updates['post_content'] = $synth['description'];
		}

		if ( ! empty( $post_updates ) ) {
			$wpdb->update( $wpdb->posts, $post_updates, array( 'ID' => $wc_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $wc_id );
		}

		$thumb_id    = (int) get_post_thumbnail_id( $wc_id );
		$og_img      = $thumb_id > 0 ? (string) wp_get_attachment_url( $thumb_id ) : '';
		$gallery_raw = (string) get_post_meta( $wc_id, '_product_image_gallery', true );
		$gallery_ids = '' !== $gallery_raw ? array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) : array();

		$cur_seo_title = trim( (string) get_post_meta( $wc_id, '_nexxi_seo_title', true ) );
		if ( $write_title || $write_desc || '' === $cur_seo_title || self::is_raw_pos_title( $cur_seo_title ) ) {
			self::write_seo_meta( $wc_id, $synth, $og_img );
		}

		if ( ! empty( $synth['tags'] ) ) {
			$existing_tags = wp_get_object_terms( $wc_id, 'product_tag', array( 'fields' => 'ids' ) );
			if ( empty( $existing_tags ) || is_wp_error( $existing_tags ) ) {
				wp_set_object_terms( $wc_id, $synth['tags'], 'product_tag', false );
			}
		}

		if ( ! empty( $synth['pa_terms'] ) ) {
			self::assign_verified_material_terms( $wc_id, $synth['pa_terms'] );
		}

		self::sync_product_image_alts( $thumb_id, $gallery_ids, $write_title ? $synth['post_title'] : $post->post_title, $wc_id );
		update_post_meta( $wc_id, '_nexxi_shopee_ref_source', $synth['ref_source'] );

		if ( function_exists( 'nexxi_seo_sanitize_physical_llms_txt' ) ) {
			@nexxi_seo_sanitize_physical_llms_txt(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $wc_id );
		}

		return array(
			'applied'    => ! empty( $post_updates ),
			'source'     => $synth['ref_source'],
			'title'      => (string) get_the_title( $wc_id ),
			'shopee_url' => $shopee_url,
		);
	}

	/**
	 * Bộ tổng hợp độc lập (pure-data) chuyển đổi dữ liệu tham khảo Shopee + POS Pancake sang trọn bộ nội dung Website:
	 * - `post_title` (50–85 ký tự, sạch mã lô & chính tả, kết thúc bằng NEXXI)
	 * - `short_description` (160–240 ký tự, 0 emoji, 0 hashtag, ghi đúng Size & cân nặng biến thể thực tế)
	 * - `description` (>= 200 từ HTML semantic 3 mục H3, liên kết danh mục + /doi-tra/, chính sách 15 ngày đổi hàng)
	 * - `seo_title` (42–60 ký tự), `seo_description` (140–155 ký tự), `seo_keywords`, `tags`, `pa_terms`.
	 */
	public static function synthesize_web_copy_from_shopee_and_pos( array $pancake_item, array $shopee_ctx = array(), $wc_id = 0 ) {
		$sku          = self::normalize_sku( $pancake_item['sku'] ?? '' );
		$pos_name     = trim( (string) ( $pancake_item['name'] ?? '' ) );
		$note_product = trim( (string) ( $pancake_item['note_product'] ?? '' ) );
		$note_extra   = trim( (string) ( $pancake_item['note'] ?? '' ) );

		$primary_mp   = isset( $shopee_ctx['primary'] ) && is_array( $shopee_ctx['primary'] ) ? $shopee_ctx['primary'] : array();
		$og_data      = isset( $shopee_ctx['og'] ) && is_array( $shopee_ctx['og'] ) ? $shopee_ctx['og'] : array();
		$shopee_url   = trim( (string) ( $primary_mp['shopee_url'] ?? '' ) );
		$shopee_title = trim( (string) ( $og_data['title'] ?? '' ) );
		if ( '' === $shopee_title || self::is_raw_pos_title( $shopee_title ) ) {
			$shopee_title = trim( (string) ( $primary_mp['title'] ?? '' ) );
		}
		$shopee_desc  = trim( (string) ( $og_data['description'] ?? '' ) );

		// 1. Tên sản phẩm chuẩn Website (`post_title`)
		$web_title = self::clean_shopee_reference_title_for_web( $shopee_title, $pos_name, $note_product . "\n" . $note_extra, $sku );

		// 2. Đọc biến thể thực tế từ POS (Màu sắc chuẩn dấu + Size min-max -> Cân nặng & Chiều cao chuẩn Website + Giá bán lẻ)
		$pretty_colors    = array();
		$has_zero_stk_col = false;
		$col_stock_map    = array();
		$size_nums        = array();
		$min_price        = (float) ( $pancake_item['min_price'] ?? 0 );
		$p_vars           = ! empty( $pancake_item['variations'] ) && is_array( $pancake_item['variations'] ) ? $pancake_item['variations'] : array();

		foreach ( $p_vars as $pv ) {
			$c_raw = trim( (string) ( $pv['color_raw'] ?? '' ) );
			$v_qty = (int) ( $pv['stock'] ?? $pv['remain_quantity'] ?? 0 );
			$v_pri = (float) ( $pv['price'] ?? $pv['retail_price'] ?? 0 );
			if ( $v_pri > 0 && ( $min_price <= 0 || $v_pri < $min_price ) ) {
				$min_price = $v_pri;
			}
			if ( '' !== $c_raw ) {
				$c_lbl = self::pretty_color_for_prose( $c_raw );
				if ( '' !== $c_lbl ) {
					if ( ! in_array( $c_lbl, $pretty_colors, true ) ) {
						$pretty_colors[] = $c_lbl;
					}
					$col_stock_map[ $c_lbl ] = ( $col_stock_map[ $c_lbl ] ?? 0 ) + max( 0, $v_qty );
				}
			}
			$s_raw = trim( (string) ( $pv['size'] ?? '' ) );
			if ( '' !== $s_raw && preg_match( '/(\d{1,2})/', $s_raw, $sm ) ) {
				$sn = (int) $sm[1];
				if ( $sn >= 1 && $sn <= 16 ) {
					$size_nums[ $sn ] = $sn;
				}
			}
		}
		foreach ( $col_stock_map as $c_qty_sum ) {
			if ( $c_qty_sum <= 0 && count( $col_stock_map ) > 1 ) {
				$has_zero_stk_col = true;
			}
		}
		ksort( $size_nums );

		$chart = class_exists( 'Nexxi_Kids_Catalog' ) && method_exists( 'Nexxi_Kids_Catalog', 'size_chart' )
			? Nexxi_Kids_Catalog::size_chart()
			: array(
				1 => array( 8, 10, 75 ), 2 => array( 10, 12, 87 ), 3 => array( 12, 14, 96 ), 4 => array( 14, 16, 104 ),
				5 => array( 16, 18, 110 ), 6 => array( 18, 20, 116 ), 7 => array( 20, 22, 122 ), 8 => array( 22, 24, 128 ),
				9 => array( 24, 27, 134 ), 10 => array( 27, 30, 140 ), 11 => array( 30, 33, 146 ), 12 => array( 33, 36, 152 ),
				13 => array( 36, 40, 156 ), 14 => array( 40, 45, 160 ), 15 => array( 45, 50, 165 ), 16 => array( 50, 55, 170 ),
			);

		$s_min = 0;
		$s_max = 0;
		$w_min = 0;
		$w_max = 0;
		$h_min = 0;
		$h_max = 0;
		if ( ! empty( $size_nums ) ) {
			$s_min = (int) min( $size_nums );
			$s_max = (int) max( $size_nums );
			if ( isset( $chart[ $s_min ] ) ) {
				$w_min = (int) $chart[ $s_min ][0];
				$h_min = (int) $chart[ $s_min ][2];
			}
			if ( isset( $chart[ $s_max ] ) ) {
				$w_max = (int) $chart[ $s_max ][1];
				$h_max = (int) $chart[ $s_max ][2];
			}
		}

		$size_badge_txt = ( $s_min > 0 && $s_max > 0 && $w_min > 0 && $w_max > 0 )
			? ( $s_min === $s_max ? sprintf( '(Size %d, %d–%dkg)', $s_min, $w_min, $w_max ) : sprintf( '(Size %d–%d, %d–%dkg)', $s_min, $s_max, $w_min, $w_max ) )
			: '';
		$weight_range_txt = ( $w_min > 0 && $w_max > 0 ) ? sprintf( '%d–%dkg', $w_min, $w_max ) : '';

		// 3. Làm sạch các dòng mô tả tham khảo từ Shopee OG/JSON-LD + POS note_product & trích xuất tín hiệu thật
		$ref_lines = self::sanitize_reference_lines( $shopee_desc . "\n" . $note_product . "\n" . $note_extra, $pos_name, $web_title );
		$combined  = $web_title . "\n" . $shopee_title . "\n" . implode( "\n", $ref_lines );
		$signals   = self::extract_verified_garment_signals( $combined );

		$t_low      = mb_strtolower( $web_title, 'UTF-8' );
		$is_girl    = (bool) preg_match( '/\bbé\s*gái\b/u', $t_low );
		$is_boy     = (bool) preg_match( '/\bbé\s*trai\b/u', $t_low );
		$gender_lbl = $is_girl ? 'bé gái' : ( $is_boy ? 'bé trai' : 'bé' );
		$gender_kw  = $is_girl ? 'bé gái' : ( $is_boy ? 'bé trai' : 'trẻ em' );
		$is_set     = (bool) preg_match( '/^(?:set|bộ|đồ\s*bộ)\b/iu', $web_title );
		$is_jacket  = (bool) preg_match( '/\báo\s*khoác\b/iu', $web_title );

		// 4. Xây dựng Mô tả ngắn (`post_excerpt` — chuẩn 160–240 ký tự UTF-8, văn xuôi sạch trên fold đầu PDP)
		$fabric_phrase = ! empty( $signals['fabrics'] )
			? implode( ' kết hợp ', array_slice( $signals['fabrics'], 0, 2 ) )
			: 'chất liệu vải mềm thoáng, thân thiện với làn da trẻ nhỏ';
		$detail_phrase = ! empty( $signals['details'] )
			? ', nổi bật với ' . implode( ' và ', array_slice( $signals['details'], 0, 2 ) )
			: ', đường may tỉ mỉ giữ phom chuẩn đẹp';
		$occ_short     = ! empty( $signals['occasions'][0] ) ? $signals['occasions'][0] : 'đi học, đi chơi, dạo phố';

		$lead_noun = trim( preg_replace( '/\s+NEXXI$/iu', '', $web_title ) );
		$sent_1    = $lead_noun . ' NEXXI ứng dụng ' . $fabric_phrase . $detail_phrase . '.';
		$sent_2    = 'Phom dáng năng động dễ diện ' . $occ_short . ( '' !== $size_badge_txt ? ' ' . $size_badge_txt : '' ) . '.';
		$short_desc = $sent_1 . ' ' . $sent_2;

		if ( mb_strlen( $short_desc, 'UTF-8' ) > 240 ) {
			// Rút gọn gọn gàng phần tên mở đầu nhưng giữ nguyên cụm (Size S_min–S_max, W_min–W_maxkg) ở cuối
			$short_lead = self::trim_words_to_chars( $lead_noun, 42 ) . ' NEXXI sử dụng ' . $fabric_phrase
				. ( ! empty( $signals['details'][0] ) ? ', ' . $signals['details'][0] : '' ) . '.';
			$short_tail = ' Dễ diện đi học, đi chơi' . ( '' !== $size_badge_txt ? ' ' . $size_badge_txt : '' ) . '.';
			$max_s1     = 240 - mb_strlen( $short_tail, 'UTF-8' );
			$short_desc = rtrim( self::trim_words_to_chars( $short_lead, $max_s1 ), '. ' ) . '.' . $short_tail;
		}
		if ( mb_strlen( $short_desc, 'UTF-8' ) < 160 ) {
			$color_addon = ! empty( $pretty_colors )
				? ' Bảng màu dễ phối gồm ' . implode( ', ', array_slice( $pretty_colors, 0, 3 ) ) . ' tôn da cho ' . $gender_lbl . '.'
				: ' Đường may êm ái, chuẩn phom trẻ em Việt Nam để con thoải mái vận động.';
			if ( mb_strlen( $short_desc . $color_addon, 'UTF-8' ) <= 240 ) {
				$short_desc .= $color_addon;
			}
		}

		// 5. Xây dựng Mô tả chi tiết HTML chuẩn `.nexxi-prose` (`>= 200` từ, 3 mục H3 semantic, không trùng Bảng size & Bảo quản)
		$home_base  = function_exists( 'home_url' ) ? rtrim( home_url( '/' ), '/' ) : 'https://nexxi.vn';
		$return_url = $home_base . '/doi-tra/';
		$cat_url    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : ( $home_base . '/cua-hang/' );
		$cat_anchor = 'Bộ sưu tập thời trang ' . $gender_kw . ' NEXXI';

		if ( $wc_id > 0 && function_exists( 'wp_get_post_terms' ) ) {
			$p_terms = wp_get_post_terms( (int) $wc_id, 'product_cat' );
			if ( is_array( $p_terms ) && ! is_wp_error( $p_terms ) && ! empty( $p_terms ) ) {
				$best_term = $p_terms[0];
				foreach ( $p_terms as $pt ) {
					if ( ! empty( $pt->parent ) ) {
						$best_term = $pt;
						break;
					}
				}
				$t_link = get_term_link( $best_term, 'product_cat' );
				if ( ! is_wp_error( $t_link ) && is_string( $t_link ) && '' !== $t_link ) {
					$cat_url    = $t_link;
					$cat_anchor = trim( $best_term->name ) . ' cho ' . $gender_kw;
				}
			}
		}

		$intro_fabric = ! empty( $signals['fabrics'][0] ) ? $signals['fabrics'][0] : 'bề mặt vải mềm mịn, thoáng khí';
		$html_parts   = array();
		$html_parts[] = '<p><strong>' . esc_html( $web_title ) . '</strong> là thiết kế được <strong>NEXXI Kids</strong> chăm chút cho tủ đồ của ' . esc_html( $gender_lbl ) . ', kết hợp hài hòa giữa ' . esc_html( $intro_fabric ) . ' êm ái và phom dáng hiện đại để con tự do vui chơi, học tập suốt ngày dài.</p>';

		$html_parts[] = '<h3>Chất liệu &amp; Thiết kế nổi bật</h3>';
		$sec1_lis     = array();
		if ( ! empty( $signals['fabrics'] ) ) {
			$sec1_lis[] = '<li><strong>Chất liệu chọn lọc êm nhẹ:</strong> Trang phục sử dụng ' . esc_html( implode( ', ', $signals['fabrics'] ) ) . ', giúp bề mặt tiếp xúc mềm mại, thoáng khí, thấm hút mồ hôi tốt và thân thiện với làn da nhạy cảm của trẻ nhỏ.</li>';
		} else {
			$sec1_lis[] = '<li><strong>Chất liệu chọn lọc êm nhẹ:</strong> Bề mặt vải mềm mịn, thoáng khí và dễ chịu khi tiếp xúc trực tiếp với làn da của trẻ, giữ phom dáng gọn đẹp qua nhiều lần mặc.</li>';
		}

		if ( ! empty( $ref_lines ) ) {
			foreach ( $ref_lines as $idx_r => $r_line ) {
				$prefix_lbl = ( 0 === $idx_r ) ? '<strong>Chi tiết thiết kế &amp; Phom dáng:</strong> ' : '';
				$sec1_lis[] = '<li>' . $prefix_lbl . esc_html( $r_line ) . '</li>';
			}
		} elseif ( ! empty( $signals['details'] ) ) {
			$sec1_lis[] = '<li><strong>Phom dáng &amp; Điểm nhấn:</strong> Nổi bật với ' . esc_html( implode( ', ', $signals['details'] ) ) . ', từng đường kim mũi chỉ và viền cổ, tay áo đều được xử lý êm gọn để không gây cọ xát khi bé vận động.</li>';
		} else {
			$sec1_lis[] = '<li><strong>Phom dáng chuẩn trẻ em Việt:</strong> Tỷ lệ cắt may vừa vặn, đường kim mũi chỉ chắc chắn giúp tôn nét đáng yêu và khỏe khoắn của ' . esc_html( $gender_lbl ) . '.</li>';
		}

		if ( $is_set ) {
			$sec1_lis[] = '<li><strong>Tính ứng dụng linh hoạt:</strong> Set đồ phối sẵn đồng bộ giúp bé diện đẹp ngay khi ' . esc_html( $occ_short ) . '; ba mẹ cũng có thể tách lẻ từng món để kết hợp linh hoạt cùng các trang phục khác trong tủ đồ của con.</li>';
		} elseif ( $is_jacket ) {
			$sec1_lis[] = '<li><strong>Tính ứng dụng bốn mùa:</strong> Gọn nhẹ để gấp gọn trong ba lô đi học hằng ngày, khoác ngoài áo thun hoặc áo sơ mi khi ra đường che nắng, chắn gió giao mùa, đi chơi hay du lịch cùng gia đình.</li>';
		} else {
			$sec1_lis[] = '<li><strong>Dễ dàng biến tấu trang phục:</strong> Phù hợp cho ' . esc_html( $gender_lbl ) . ' diện khi ' . esc_html( $occ_short ) . '; dễ phối nhanh cùng quần jean, quần short, chân váy hoặc giày thể thao sẵn có.</li>';
		}
		$html_parts[] = '<ul>' . implode( "\n", $sec1_lis ) . '</ul>';

		$html_parts[] = '<h3>Bảng màu &amp; Gợi ý chọn size theo cân nặng</h3>';
		$sec2_lis     = array();
		if ( ! empty( $pretty_colors ) ) {
			$cnt_c = count( $pretty_colors );
			if ( 1 === $cnt_c ) {
				$color_str = $pretty_colors[0];
			} else {
				$last_c    = array_pop( $pretty_colors );
				$color_str = implode( ', ', $pretty_colors ) . ' và ' . $last_c;
				$pretty_colors[] = $last_c;
			}
			$stk_note   = $has_zero_stk_col ? ' (ba mẹ chọn trực tiếp trên thanh phân loại màu đang còn hàng thực tế ngay phía trên)' : ', tôn da và rất dễ kết hợp cùng giày sneaker, sandal hay phụ kiện của bé';
			$sec2_lis[] = '<li><strong>Phân loại màu sắc:</strong> Thiết kế có ' . (int) $cnt_c . ' phiên bản màu thực tế gồm <strong>' . esc_html( $color_str ) . '</strong>' . esc_html( $stk_note ) . '.</li>';
		}
		if ( $s_min > 0 && $s_max > 0 && $w_min > 0 && $w_max > 0 ) {
			$size_span  = ( $s_min === $s_max ) ? ( 'Size ' . $s_min ) : ( 'Size ' . $s_min . ' đến Size ' . $s_max );
			$height_txt = ( $h_min > 0 && $h_max > 0 ) ? sprintf( ', chiều cao khoảng <strong>%d–%dcm</strong>', $h_min, $h_max ) : '';
			$sec2_lis[] = '<li><strong>Dải kích cỡ chuẩn phom Việt:</strong> Sản phẩm có đủ <strong>' . esc_html( $size_span ) . '</strong> (tương ứng cân nặng tham khảo <strong>' . esc_html( $weight_range_txt ) . '</strong>' . $height_txt . '). Ba mẹ hãy nhập trực tiếp chiều cao và cân nặng của con vào khung <em>Bảng Quy Đổi Kích Cỡ Trang Phục</em> trên trang để nhận gợi ý size vừa vặn nhất; nếu bé trộm vía đầy đặn hoặc thích mặc rộng rãi, mẹ nên cân nhắc nhích lên 1 size.</li>';
		} else {
			$sec2_lis[] = '<li><strong>Hướng dẫn chọn kích cỡ:</strong> Ba mẹ vui lòng đối chiếu chiều cao và cân nặng thực tế của bé tại khung <em>Bảng Quy Đổi Kích Cỡ Trang Phục</em> phía trên trang sản phẩm để chọn size thoải mái nhất cho con.</li>';
		}
		$html_parts[] = '<ul>' . implode( "\n", $sec2_lis ) . '</ul>';

		$html_parts[] = '<h3>Cam kết chính hãng từ NEXXI Kids</h3>'
			. '<ul>'
			. '<li><strong>Chuẩn mẫu thiết kế NEXXI Kids:</strong> Hình ảnh sản phẩm thực tế chính chủ do đội ngũ NEXXI thực hiện, từng sản phẩm đều được kiểm tra kỹ đường may và khuy cúc trước khi đóng gói. Khám phá thêm nhiều mẫu mới cùng dòng tại danh mục <a href="' . esc_url( $cat_url ) . '">' . esc_html( $cat_anchor ) . '</a>.</li>'
			. '<li><strong>Hỗ trợ 15 ngày đổi hàng an tâm:</strong> NEXXI Kids áp dụng <a href="' . esc_url( $return_url ) . '">chính sách hỗ trợ 15 ngày đổi hàng</a> nhanh chóng nếu bé mặc chưa vừa size hoặc muốn đổi mẫu (khi sản phẩm còn nguyên tem mác, chưa qua giặt ủi và sử dụng).</li>'
			. '</ul>';

		$full_html = implode( "\n\n", $html_parts );

		// 6. Bộ Metadata SEO chuẩn Google SERP: seo_title <= 60 ký tự, seo_description 140–155 ký tự ("đổi hàng 15 ngày")
		$base_seo  = self::build_smart_fallback_seo( $web_title, $short_desc, $full_html, $sku );
		$seo_title = $base_seo['seo_title'];

		$price_k_txt = $min_price >= 1000 ? ( ', giá từ ' . (int) round( $min_price / 1000 ) . 'k' ) : '';
		$meta_tail   = '. Chính hãng NEXXI Kids, đổi hàng 15 ngày.'; // 42 ký tự
		$meta_core   = trim( preg_replace( '/\bNEXXI(?:\s+Kids)?\b/iu', '', $web_title ) ) . ' NEXXI'
			. ( '' !== $weight_range_txt ? ' (' . $weight_range_txt . ')' : '' )
			. ': ' . $fabric_phrase
			. ( ! empty( $signals['details'][0] ) ? ', ' . $signals['details'][0] : '' )
			. $price_k_txt;
		$max_m_core  = 155 - mb_strlen( $meta_tail, 'UTF-8' ); // 113 ký tự
		$meta_lead   = rtrim( self::trim_words_to_chars( $meta_core, $max_m_core ), '.! ,;:-–' );
		if ( mb_strlen( $meta_lead . $meta_tail, 'UTF-8' ) < 140 ) {
			$meta_ext  = $meta_core . ', phom chuẩn đẹp cho ' . $gender_lbl . ' đi học đi chơi';
			$meta_lead = rtrim( self::trim_words_to_chars( $meta_ext, $max_m_core ), '.! ,;:-–' );
		}
		$seo_desc = $meta_lead . $meta_tail;

		// 7. Thẻ sản phẩm (product_tag) & Nguồn truy vết
		$tags = array();
		if ( $is_set && false !== mb_strpos( $t_low, 'váy', 0, 'UTF-8' ) ) {
			$tags[] = 'Set váy ' . $gender_kw;
		} elseif ( $is_set ) {
			$tags[] = 'Đồ bộ ' . $gender_kw;
		} elseif ( $is_jacket ) {
			$tags[] = 'Áo khoác ' . $gender_kw;
		} elseif ( false !== mb_strpos( $t_low, 'váy', 0, 'UTF-8' ) || false !== mb_strpos( $t_low, 'đầm', 0, 'UTF-8' ) ) {
			$tags[] = 'Váy đầm ' . $gender_kw;
		} else {
			$tags[] = 'Áo ' . $gender_kw;
		}
		if ( ! empty( $signals['pa_terms'][0] ) ) {
			$tags[] = $signals['pa_terms'][0];
		}
		$tags[] = 'Thời trang ' . $gender_kw;
		$tags[] = 'NEXXI Kids';
		$tags   = array_values( array_unique( $tags ) );

		if ( '' !== $shopee_desc && ! empty( $ref_lines ) && '' !== $note_product ) {
			$ref_source = 'shopee_og+pos_note';
		} elseif ( '' !== $shopee_desc ) {
			$ref_source = 'shopee_og+attrs';
		} elseif ( '' !== $shopee_url && '' !== $note_product ) {
			$ref_source = 'shopee_title+pos_note';
		} elseif ( '' !== $shopee_url ) {
			$ref_source = 'shopee_title+attrs';
		} elseif ( '' !== $note_product ) {
			$ref_source = 'pos_note+attrs';
		} else {
			$ref_source = 'pos_smart_fallback';
		}

		return array(
			'post_title'        => $web_title,
			'short_description' => $short_desc,
			'description'       => $full_html,
			'seo_title'         => $seo_title,
			'seo_description'   => $seo_desc,
			'seo_keywords'      => $base_seo['seo_keywords'],
			'seo_robots'        => 'index,follow',
			'og_title'          => $seo_title,
			'og_description'    => $seo_desc,
			'tags'              => $tags,
			'pa_terms'          => $signals['pa_terms'],
			'ref_source'        => $ref_source,
			'shopee_url'        => $shopee_url,
		);
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
			$matched        = self::sanitize_catalog_entry_for_web( $matched );
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
			self::sync_product_image_alts( $thumb_id, $gallery_ids, $matched['name'], $wc_id );

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
		self::sync_product_image_alts( $thumb_id, $gallery_ids, $fb['clean_title'], $wc_id );
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
				$matched = self::sanitize_catalog_entry_for_web( $matched );
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
				self::sync_product_image_alts( $row['thumb_id'], $row['gallery_ids'], $matched['name'], $pid );

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
				self::sync_product_image_alts( $row['thumb_id'], $row['gallery_ids'], $fb['clean_title'], $pid );

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
