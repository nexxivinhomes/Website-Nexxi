<?php
/**
 * Stitch screens for Blog / Category / Tag / Search archives ('blog')
 * and Single Blog Posts ('single').
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Estimate Vietnamese reading time in minutes (minimum 1 minute). */
function nexxi_kids_reading_minutes( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return 1;
	}
	$text  = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
	$words = '' === $text ? array() : preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$count = is_array( $words ) ? count( $words ) : 0;
	return max( 1, (int) ceil( $count / 220 ) );
}

/** Primary post category (respects Yoast primary category if set, skips Uncategorized when possible). */
function nexxi_kids_primary_post_category( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}
	$cats = get_the_category( $post->ID );
	if ( empty( $cats ) || is_wp_error( $cats ) ) {
		return null;
	}
	$primary_id = (int) get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );
	if ( $primary_id > 0 ) {
		foreach ( $cats as $cat ) {
			if ( (int) $cat->term_id === $primary_id ) {
				return $cat;
			}
		}
	}
	foreach ( $cats as $cat ) {
		if ( ! in_array( $cat->slug, array( 'uncategorized', 'chua-phan-loai' ), true ) ) {
			return $cat;
		}
	}
	return reset( $cats );
}

/** Check whether post_content already begins with the exact same featured image to avoid double banners. */
function nexxi_kids_post_has_duplicate_first_image( $post, $thumb_id ) {
	$post     = get_post( $post );
	$thumb_id = (int) $thumb_id;
	if ( ! $post || $thumb_id <= 0 ) {
		return false;
	}
	$head = substr( trim( (string) $post->post_content ), 0, 1200 );
	if ( '' === $head ) {
		return false;
	}
	if ( false !== strpos( $head, 'wp-image-' . $thumb_id ) || false !== strpos( $head, '"id":' . $thumb_id ) ) {
		return true;
	}
	$thumb_url = wp_get_attachment_url( $thumb_id );
	if ( $thumb_url ) {
		$base = pathinfo( (string) wp_parse_url( $thumb_url, PHP_URL_PATH ), PATHINFO_FILENAME );
		if ( '' !== $base && preg_match( '~^\s*(?:<!--[^>]*-->\s*)*<(?:figure|p|div)[^>]*>\s*<img\b[^>]*' . preg_quote( $base, '~' ) . '~i', $head ) ) {
			return true;
		}
	}
	return false;
}

/** Inject stable anchor IDs into <h2> tags and return both updated HTML and TOC items. */
function nexxi_kids_inject_post_toc( $html ) {
	$items = array();
	$used  = array();
	$idx   = 0;

	$updated = preg_replace_callback(
		'~<h2\b([^>]*)>(.*?)</h2>~is',
		static function ( $m ) use ( &$items, &$used, &$idx ) {
			$attrs = $m[1];
			$inner = $m[2];
			$label = trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $label ) {
				return $m[0];
			}
			$idx++;
			if ( preg_match( '~\bid=(["\'])([^"\']+)\1~i', $attrs, $id_match ) ) {
				$anchor = $id_match[2];
			} else {
				$slug   = sanitize_title( $label );
				$anchor = '' !== $slug ? $slug : 'muc-' . $idx;
				if ( isset( $used[ $anchor ] ) ) {
					$used[ $anchor ]++;
					$anchor .= '-' . $used[ $anchor ];
				} else {
					$used[ $anchor ] = 1;
				}
				$attrs .= ' id="' . esc_attr( $anchor ) . '"';
			}
			$items[] = array(
				'id'    => $anchor,
				'label' => $label,
			);
			return '<h2' . $attrs . '>' . $inner . '</h2>';
		},
		(string) $html
	);

	return array(
		'content' => null !== $updated ? $updated : (string) $html,
		'items'   => $items,
	);
}

/** Render a single Stitch-styled Blog / Article card for archive grids & related posts. */
function nexxi_kids_blog_card( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$url       = get_permalink( $post );
	$title     = get_the_title( $post );
	$date      = get_the_date( 'd/m/Y', $post );
	$read_mins = nexxi_kids_reading_minutes( $post );
	$cat       = nexxi_kids_primary_post_category( $post );
	$cat_name  = $cat instanceof WP_Term ? $cat->name : 'Cẩm nang NEXXI';
	$cat_link  = $cat instanceof WP_Term ? get_category_link( $cat->term_id ) : '';
	if ( is_wp_error( $cat_link ) ) {
		$cat_link = '';
	}

	$raw_excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
	$excerpt     = wp_trim_words( $raw_excerpt, 32, '…' );

	$thumb_html = '';
	if ( has_post_thumbnail( $post ) ) {
		$thumb_html = get_the_post_thumbnail(
			$post,
			'large',
			array(
				'class'    => 'nexxi-blog-card__img',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'alt'      => $title,
			)
		);
	}

	ob_start();
	?>
	<article class="nexxi-blog-card">
		<a class="nexxi-blog-card__media" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $title ); ?>">
			<?php if ( $thumb_html ) : ?>
				<?php echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<div class="nexxi-blog-card__placeholder">
					<span class="material-symbols-outlined">menu_book</span>
					<span>NEXXI Kids Fashion</span>
				</div>
			<?php endif; ?>
		</a>

		<div class="nexxi-blog-card__body">
			<div class="nexxi-blog-card__meta">
				<?php if ( $cat_link ) : ?>
					<a class="nexxi-blog-card__badge" href="<?php echo esc_url( $cat_link ); ?>"><?php echo esc_html( $cat_name ); ?></a>
				<?php else : ?>
					<span class="nexxi-blog-card__badge"><?php echo esc_html( $cat_name ); ?></span>
				<?php endif; ?>
				<span class="nexxi-blog-card__date">
					<span class="material-symbols-outlined">schedule</span>
					<?php echo esc_html( $date ); ?> · <?php echo (int) $read_mins; ?> phút đọc
				</span>
			</div>

			<h2 class="nexxi-blog-card__title">
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
			</h2>

			<?php if ( $excerpt ) : ?>
				<p class="nexxi-blog-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
			<?php endif; ?>

			<div class="nexxi-blog-card__footer">
				<a class="nexxi-blog-card__more" href="<?php echo esc_url( $url ); ?>">
					<span>Đọc chi tiết</span>
					<span class="material-symbols-outlined">arrow_forward</span>
				</a>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/** Render the Stitch <main> for Category, Tag, Search, Date/Author Archives and Blog Home. */
function nexxi_kids_blog_main() {
	$eyebrow  = 'Cẩm Nang & Tin Tức NEXXI';
	$title    = 'Cẩm Nang Chọn Đồ Cho Bé';
	$intro    = 'Chia sẻ kinh nghiệm chọn chất vải mềm mát, bảng quy đổi size chuẩn theo cân nặng (12–45kg) và gợi ý phối đồ xinh xắn cho bé gái & bé trai.';
	$cur_term = get_queried_object();

	if ( is_category() ) {
		$eyebrow = 'Chuyên Mục Bài Viết';
		$title   = (string) single_cat_title( '', false );
		$desc    = trim( wp_strip_all_tags( term_description() ) );
		if ( '' !== $desc ) {
			$intro = $desc;
		}
	} elseif ( is_tag() ) {
		$eyebrow = 'Chủ Đề Bài Viết';
		$title   = '#' . (string) single_tag_title( '', false );
		$desc    = trim( wp_strip_all_tags( term_description() ) );
		if ( '' !== $desc ) {
			$intro = $desc;
		}
	} elseif ( is_search() ) {
		$eyebrow = 'Kết Quả Tìm Kiếm';
		$title   = sprintf( 'Tìm kiếm: “%s”', get_search_query() );
		$intro   = 'Các bài viết và cẩm nang phù hợp với từ khóa mẹ vừa tìm trên NEXXI Kids.';
	} elseif ( is_author() ) {
		$eyebrow = 'Biên Tập Viên NEXXI';
		$title   = get_the_author();
	} elseif ( is_home() ) {
		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id > 0 ) {
			$title = get_the_title( $posts_page_id );
		} else {
			$title = 'Tin Tức & Cẩm Nang NEXXI';
		}
	} elseif ( is_archive() ) {
		$raw_arch = wp_strip_all_tags( get_the_archive_title() );
		$clean    = preg_replace( '/^[^:]+:\s*/u', '', $raw_arch );
		if ( '' !== trim( (string) $clean ) ) {
			$title = trim( (string) $clean );
		}
	}

	// Breadcrumb trail
	$crumbs = '<nav aria-label="Breadcrumb" class="nexxi-stitch-crumbs">'
		. '<a class="nexxi-stitch-crumbs__link" href="' . esc_url( home_url( '/' ) ) . '"><span class="material-symbols-outlined">home</span><span>Trang Chủ</span></a>';

	$posts_page_id = (int) get_option( 'page_for_posts' );
	if ( $posts_page_id > 0 && ! is_home() ) {
		$crumbs .= '<span class="nexxi-stitch-crumbs__sep">/</span>'
			. '<a class="nexxi-stitch-crumbs__link" href="' . esc_url( get_permalink( $posts_page_id ) ) . '">' . esc_html( get_the_title( $posts_page_id ) ) . '</a>';
	}

	if ( is_category() && $cur_term instanceof WP_Term && $cur_term->parent > 0 ) {
		$ancestors = array_reverse( get_ancestors( $cur_term->term_id, 'category' ) );
		foreach ( $ancestors as $anc_id ) {
			$anc = get_term( $anc_id, 'category' );
			if ( $anc instanceof WP_Term && ! is_wp_error( $anc ) ) {
				$anc_link = get_category_link( $anc->term_id );
				if ( ! is_wp_error( $anc_link ) ) {
					$crumbs .= '<span class="nexxi-stitch-crumbs__sep">/</span>'
						. '<a class="nexxi-stitch-crumbs__link" href="' . esc_url( $anc_link ) . '">' . esc_html( $anc->name ) . '</a>';
				}
			}
		}
	}

	$crumbs .= '<span class="nexxi-stitch-crumbs__sep">/</span>'
		. '<span class="nexxi-stitch-crumbs__current">' . esc_html( $title ) . '</span></nav>';

	// Category filter pills (only shown when meaningful)
	$all_cats   = get_categories(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	$valid_cats = array();
	foreach ( (array) $all_cats as $c_item ) {
		if ( $c_item instanceof WP_Term && ! in_array( $c_item->slug, array( 'uncategorized', 'chua-phan-loai' ), true ) ) {
			$valid_cats[] = $c_item;
		}
	}

	$filter_pills = '';
	if ( count( $valid_cats ) > 1 || ( count( $valid_cats ) === 1 && $posts_page_id > 0 ) ) {
		$filter_pills .= '<div class="nexxi-blog-filters" role="navigation" aria-label="Chuyên mục bài viết">';
		if ( $posts_page_id > 0 ) {
			$is_all_active = is_home();
			$filter_pills .= '<a class="nexxi-blog-filter-pill' . ( $is_all_active ? ' is-active' : '' ) . '" href="' . esc_url( get_permalink( $posts_page_id ) ) . '">Tất cả bài viết</a>';
		}
		foreach ( $valid_cats as $vcat ) {
			$vlink = get_category_link( $vcat->term_id );
			if ( is_wp_error( $vlink ) ) {
				continue;
			}
			$is_cat_active = ( is_category() && $cur_term instanceof WP_Term && (int) $cur_term->term_id === (int) $vcat->term_id );
			$filter_pills .= '<a class="nexxi-blog-filter-pill' . ( $is_cat_active ? ' is-active' : '' ) . '" href="' . esc_url( $vlink ) . '">'
				. esc_html( $vcat->name )
				. '<span class="nexxi-blog-filter-pill__count">' . (int) $vcat->count . '</span></a>';
		}
		$filter_pills .= '</div>';
	}

	// Cards loop
	$cards_html = '';
	if ( have_posts() ) {
		while ( have_posts() ) {
			the_post();
			$cards_html .= nexxi_kids_blog_card( get_post() );
		}
		rewind_posts();
		wp_reset_postdata();
	}

	// Pagination
	$pagination_links = paginate_links(
		array(
			'type'      => 'array',
			'mid_size'  => 2,
			'prev_text' => '<span class="material-symbols-outlined">chevron_left</span><span>Trước</span>',
			'next_text' => '<span>Tiếp</span><span class="material-symbols-outlined">chevron_right</span>',
		)
	);
	$pagination_html  = '';
	if ( is_array( $pagination_links ) && ! empty( $pagination_links ) ) {
		$pagination_html .= '<nav class="nexxi-blog-pagination" aria-label="Phân trang bài viết">';
		foreach ( $pagination_links as $plink ) {
			$pagination_html .= '<span class="nexxi-blog-pagination__item">' . $plink . '</span>';
		}
		$pagination_html .= '</nav>';
	}

	$shop_url  = nexxi_kids_shop_url();
	$flash_url = nexxi_kids_flash_url();

	ob_start();
	?>
	<main class="w-full bg-background min-h-screen nexxi-blog-screen">
		<div class="nexxi-blog-container">
			<?php echo $crumbs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<section data-nx="page-hero" class="nexxi-blog-hero">
				<div class="nexxi-blog-hero__inner">
					<span class="nexxi-blog-eyebrow">
						<span class="material-symbols-outlined">menu_book</span>
						<span><?php echo esc_html( $eyebrow ); ?></span>
					</span>
					<h1 class="nexxi-blog-hero__title"><?php echo esc_html( $title ); ?></h1>
					<?php if ( $intro ) : ?>
						<p class="nexxi-blog-hero__intro"><?php echo esc_html( $intro ); ?></p>
					<?php endif; ?>
					<?php echo $filter_pills; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</section>

			<?php if ( '' !== $cards_html ) : ?>
				<section aria-label="Danh sách bài viết">
					<div class="nexxi-blog-grid">
						<?php echo $cards_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php echo $pagination_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
			<?php else : ?>
				<section>
					<?php echo nexxi_kids_empty_state( 'Chưa có bài viết nào trong mục này', 'NEXXI đang biên tập thêm các cẩm nang chọn đồ và phối size cho bé. Mẹ ghé cửa hàng xem mẫu mới trước nhé!', 'Khám phá cửa hàng', $shop_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
			<?php endif; ?>

			<section class="nexxi-blog-cta">
				<div class="nexxi-blog-cta__content">
					<span class="nexxi-blog-cta__pill">
						<span class="material-symbols-outlined">verified</span>
						<span>Thời Trang Thiết Kế Cho Bé 12–45kg</span>
					</span>
					<h2 class="nexxi-blog-cta__title">Sắm ngay đầm xinh &amp; set bộ mềm mát chuẩn size cho bé</h2>
					<p class="nexxi-blog-cta__desc">Tất cả mẫu tại NEXXI Kids đều được tuyển chọn chất vải thoáng khí, chuẩn form vóc dáng trẻ em Việt Nam và hỗ trợ đổi hàng trong 15 ngày.</p>
					<div class="nexxi-blog-cta__actions">
						<a class="nexxi-blog-btn nexxi-blog-btn--primary" href="<?php echo esc_url( $shop_url ); ?>">
							<span class="material-symbols-outlined">shopping_bag</span>
							<span>Khám phá Cửa hàng</span>
						</a>
						<a class="nexxi-blog-btn nexxi-blog-btn--light" href="<?php echo esc_url( $flash_url ); ?>">
							<span class="material-symbols-outlined">bolt</span>
							<span>Xem Flash Sale</span>
						</a>
					</div>
				</div>
			</section>
		</div>
	</main>
	<?php
	return ob_get_clean();
}

/** Render the Stitch <main> for a Single Blog Post ('single'). */
function nexxi_kids_single_post_main() {
	$main_post = get_queried_object();
	if ( ! ( $main_post instanceof WP_Post ) ) {
		$main_post = get_post();
	}
	if ( ! ( $main_post instanceof WP_Post ) ) {
		return nexxi_kids_page_shell(
			'Không tìm thấy bài viết',
			'Cẩm nang NEXXI',
			nexxi_kids_empty_state( 'Bài viết không tồn tại hoặc đã được di chuyển', 'Mẹ quay lại cửa hàng để xem những mẫu thiết kế mới nhất cho bé nhé.', 'Về cửa hàng', nexxi_kids_shop_url() )
		);
	}

	$GLOBALS['post'] = $main_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $main_post );

	$post_id      = (int) $main_post->ID;
	$title        = get_the_title( $main_post );
	$pub_date     = get_the_date( 'd/m/Y', $main_post );
	$mod_ts       = (int) get_post_modified_time( 'U', false, $main_post );
	$pub_ts       = (int) get_post_time( 'U', false, $main_post );
	$mod_date     = ( $mod_ts - $pub_ts > DAY_IN_SECONDS ) ? get_the_modified_date( 'd/m/Y', $main_post ) : '';
	$read_mins    = nexxi_kids_reading_minutes( $main_post );
	$primary_cat  = nexxi_kids_primary_post_category( $main_post );
	$cat_name     = $primary_cat instanceof WP_Term ? $primary_cat->name : 'Cẩm nang NEXXI';
	$cat_url      = $primary_cat instanceof WP_Term ? get_category_link( $primary_cat->term_id ) : '';
	if ( is_wp_error( $cat_url ) ) {
		$cat_url = '';
	}

	// Breadcrumb
	$crumbs = '<nav aria-label="Breadcrumb" class="nexxi-stitch-crumbs">'
		. '<a class="nexxi-stitch-crumbs__link" href="' . esc_url( home_url( '/' ) ) . '"><span class="material-symbols-outlined">home</span><span>Trang Chủ</span></a>';
	if ( $cat_url ) {
		$crumbs .= '<span class="nexxi-stitch-crumbs__sep">/</span>'
			. '<a class="nexxi-stitch-crumbs__link" href="' . esc_url( $cat_url ) . '">' . esc_html( $cat_name ) . '</a>';
	}
	$crumbs .= '<span class="nexxi-stitch-crumbs__sep">/</span>'
		. '<span class="nexxi-stitch-crumbs__current">' . esc_html( wp_trim_words( $title, 12, '…' ) ) . '</span></nav>';

	// Featured Image (full width, uncropped natural ratio, skipped if already duplicated at top of content)
	$thumb_id   = (int) get_post_thumbnail_id( $main_post );
	$hero_media = '';
	if ( $thumb_id > 0 && ! nexxi_kids_post_has_duplicate_first_image( $main_post, $thumb_id ) ) {
		$img_tag = wp_get_attachment_image(
			$thumb_id,
			'full',
			false,
			array(
				'class'         => 'nexxi-post-featured__img',
				'fetchpriority' => 'high',
				'decoding'      => 'async',
				'alt'           => $title,
			)
		);
		if ( $img_tag ) {
			$hero_media = '<figure class="nexxi-post-featured">' . $img_tag . '</figure>';
		}
	}

	// Render content + inject TOC IDs on <h2>
	$raw_rendered = apply_filters( 'the_content', $main_post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$toc_data     = nexxi_kids_inject_post_toc( $raw_rendered );
	$body_html    = $toc_data['content'];
	$toc_items    = $toc_data['items'];

	$link_pages = wp_link_pages(
		array(
			'before' => '<nav class="nexxi-post-pages" aria-label="Các trang của bài viết"><span>Trang:</span>',
			'after'  => '</nav>',
			'echo'   => 0,
		)
	);

	// Tags
	$tags      = get_the_tags( $post_id );
	$tags_html = '';
	if ( is_array( $tags ) && ! empty( $tags ) ) {
		$tags_html .= '<div class="nexxi-post-tags"><span class="nexxi-post-tags__label"><span class="material-symbols-outlined">sell</span> Chủ đề:</span>';
		foreach ( $tags as $tag_term ) {
			$tlink = get_tag_link( $tag_term->term_id );
			if ( ! is_wp_error( $tlink ) ) {
				$tags_html .= '<a class="nexxi-post-tag-pill" href="' . esc_url( $tlink ) . '">#' . esc_html( $tag_term->name ) . '</a>';
			}
		}
		$tags_html .= '</div>';
	}

	// Prev / Next posts
	$prev_post = get_previous_post();
	$next_post = get_next_post();

	// Sidebar quick products (compact list of 4 products)
	$sidebar_products = array();
	$bottom_products  = array();
	if ( function_exists( 'wc_get_products' ) ) {
		$fetched_prods = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 4,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		if ( is_array( $fetched_prods ) ) {
			$sidebar_products = array_slice( $fetched_prods, 0, 3 );
			$bottom_products  = $fetched_prods;
		}
	}

	// Related posts (up to 3 posts in same category, backfilled with latest posts)
	$rel_args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 3,
		'post__not_in'        => array( $post_id ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);
	if ( $primary_cat instanceof WP_Term ) {
		$rel_args['cat'] = (int) $primary_cat->term_id;
	}
	$related_posts = get_posts( $rel_args );
	if ( count( $related_posts ) < 3 && ! empty( $rel_args['cat'] ) ) {
		$exclude_ids = array( $post_id );
		foreach ( $related_posts as $rp ) {
			$exclude_ids[] = (int) $rp->ID;
		}
		$more_posts = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 3 - count( $related_posts ),
				'post__not_in'        => $exclude_ids,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		if ( is_array( $more_posts ) ) {
			$related_posts = array_merge( $related_posts, $more_posts );
		}
	}

	$shop_url    = nexxi_kids_shop_url();
	$flash_url   = nexxi_kids_flash_url();
	$return_url  = nexxi_kids_page_url( 'doi-tra' );
	$size_url    = home_url( '/#size-guide' );
	$hotline     = nexxi_kids_setting( 'nexxi_kids_hotline' );
	$chat_url    = nexxi_kids_setting( 'nexxi_kids_chat_url' );

	// Restore main post data cleanly after helper queries so wp_head() sees the exact post
	$GLOBALS['post'] = $main_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $main_post );

	ob_start();
	?>
	<main class="w-full bg-background min-h-screen nexxi-post-screen">
		<div class="nexxi-blog-container">
			<?php echo $crumbs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="nexxi-post-layout">
				<article id="post-<?php echo (int) $post_id; ?>" class="nexxi-post-article">
					<header class="nexxi-post-header">
						<div class="nexxi-post-meta">
							<?php if ( $cat_url ) : ?>
								<a class="nexxi-post-meta__cat" href="<?php echo esc_url( $cat_url ); ?>">
									<span class="material-symbols-outlined">menu_book</span>
									<span><?php echo esc_html( $cat_name ); ?></span>
								</a>
							<?php else : ?>
								<span class="nexxi-post-meta__cat">
									<span class="material-symbols-outlined">menu_book</span>
									<span><?php echo esc_html( $cat_name ); ?></span>
								</span>
							<?php endif; ?>

							<span class="nexxi-post-meta__item">
								<span class="material-symbols-outlined">schedule</span>
								<span><?php echo esc_html( $mod_date ? 'Cập nhật ' . $mod_date : $pub_date ); ?></span>
							</span>

							<span class="nexxi-post-meta__item">
								<span class="material-symbols-outlined">bookmark_added</span>
								<span><?php echo (int) $read_mins; ?> phút đọc</span>
							</span>
						</div>

						<h1 class="nexxi-post-title"><?php echo esc_html( $title ); ?></h1>
					</header>

					<?php echo $hero_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php if ( count( $toc_items ) >= 2 ) : ?>
						<details class="nexxi-post-toc nexxi-post-toc--mobile">
							<summary class="nexxi-post-toc__summary">
								<span class="nexxi-post-toc__summary-title">
									<span class="material-symbols-outlined">menu_book</span>
									<span>Nội dung chính trong bài (<?php echo count( $toc_items ); ?> mục)</span>
								</span>
								<span class="material-symbols-outlined nexxi-post-toc__chevron">expand_more</span>
							</summary>
							<ol class="nexxi-post-toc__list">
								<?php foreach ( $toc_items as $t_idx => $t_item ) : ?>
									<li>
										<a href="#<?php echo esc_attr( $t_item['id'] ); ?>">
											<span class="nexxi-post-toc__num"><?php echo str_pad( (string) ( $t_idx + 1 ), 2, '0', STR_PAD_LEFT ); ?></span>
											<span><?php echo esc_html( $t_item['label'] ); ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ol>
						</details>
					<?php endif; ?>

					<div class="nexxi-prose nexxi-post-prose">
						<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>

					<?php echo $link_pages; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $tags_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<div class="nexxi-post-trust">
						<div class="nexxi-post-trust__item">
							<span class="material-symbols-outlined">verified</span>
							<div>
								<strong>Chất vải an toàn cho bé</strong>
								<span>Cotton co giãn, Kate Hàn lót lụa mềm mát</span>
							</div>
						</div>
						<div class="nexxi-post-trust__item">
							<span class="material-symbols-outlined">straighten</span>
							<div>
								<strong>Chuẩn vóc dáng trẻ em Việt</strong>
								<span>Đầy đủ dải size cho bé từ 12–45kg</span>
							</div>
						</div>
						<a class="nexxi-post-trust__item nexxi-post-trust__item--link" href="<?php echo esc_url( $return_url ); ?>">
							<span class="material-symbols-outlined">swap_horizontal_circle</span>
							<div>
								<strong>Hỗ trợ 15 ngày đổi hàng</strong>
								<span>Đổi size hoặc đổi mẫu nhanh chóng</span>
							</div>
						</a>
					</div>

					<?php if ( $prev_post instanceof WP_Post || $next_post instanceof WP_Post ) : ?>
						<nav class="nexxi-post-nav" aria-label="Điều hướng bài viết">
							<?php if ( $prev_post instanceof WP_Post ) : ?>
								<a class="nexxi-post-nav__card" href="<?php echo esc_url( get_permalink( $prev_post ) ); ?>">
									<span class="nexxi-post-nav__label"><span class="material-symbols-outlined">arrow_back</span> Bài trước</span>
									<strong class="nexxi-post-nav__title"><?php echo esc_html( get_the_title( $prev_post ) ); ?></strong>
								</a>
							<?php else : ?>
								<div></div>
							<?php endif; ?>

							<?php if ( $next_post instanceof WP_Post ) : ?>
								<a class="nexxi-post-nav__card nexxi-post-nav__card--next" href="<?php echo esc_url( get_permalink( $next_post ) ); ?>">
									<span class="nexxi-post-nav__label">Bài tiếp theo <span class="material-symbols-outlined">arrow_forward</span></span>
									<strong class="nexxi-post-nav__title"><?php echo esc_html( get_the_title( $next_post ) ); ?></strong>
								</a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				</article>

				<aside class="nexxi-post-sidebar" aria-label="Mục lục và hỗ trợ mua sắm">
					<?php if ( count( $toc_items ) >= 2 ) : ?>
						<div class="nexxi-post-sidecard nexxi-post-toc--desktop">
							<div class="nexxi-post-sidecard__head">
								<span class="material-symbols-outlined">menu_book</span>
								<h2>Mục lục bài viết</h2>
							</div>
							<ol class="nexxi-post-toc__list">
								<?php foreach ( $toc_items as $t_idx => $t_item ) : ?>
									<li>
										<a href="#<?php echo esc_attr( $t_item['id'] ); ?>">
											<span class="nexxi-post-toc__num"><?php echo str_pad( (string) ( $t_idx + 1 ), 2, '0', STR_PAD_LEFT ); ?></span>
											<span><?php echo esc_html( $t_item['label'] ); ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ol>
						</div>
					<?php endif; ?>

					<div class="nexxi-post-sidecard nexxi-post-sidecard--assist">
						<span class="nexxi-post-sidecard__badge">Tư vấn chọn size chuẩn</span>
						<h2>Cần NEXXI chọn size theo cân nặng &amp; chiều cao của bé?</h2>
						<p>Đội ngũ NEXXI luôn sẵn sàng tư vấn chất vải, số đo thực tế từng mẫu và giữ size cho bé.</p>
						<div class="nexxi-post-sidecard__actions">
							<a class="nexxi-blog-btn nexxi-blog-btn--primary nexxi-blog-btn--full" href="<?php echo esc_url( $shop_url ); ?>">
								<span class="material-symbols-outlined">shopping_bag</span>
								<span>Xem Cửa Hàng NEXXI</span>
							</a>
							<a class="nexxi-blog-btn nexxi-blog-btn--outline nexxi-blog-btn--full" href="<?php echo esc_url( $size_url ); ?>">
								<span class="material-symbols-outlined">straighten</span>
								<span>Tra Bảng Size 12–45kg</span>
							</a>
							<?php if ( $chat_url ) : ?>
								<a class="nexxi-blog-btn nexxi-blog-btn--zalo nexxi-blog-btn--full" href="<?php echo esc_url( $chat_url ); ?>" target="_blank" rel="noopener">
									<span class="material-symbols-outlined">forum</span>
									<span>Nhắn Zalo Tư Vấn Ngay</span>
								</a>
							<?php elseif ( $hotline ) : ?>
								<a class="nexxi-blog-btn nexxi-blog-btn--outline nexxi-blog-btn--full" href="<?php echo esc_url( nexxi_kids_tel( $hotline ) ); ?>">
									<span class="material-symbols-outlined">call</span>
									<span>Hotline: <?php echo esc_html( $hotline ); ?></span>
								</a>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( ! empty( $sidebar_products ) && function_exists( 'nexxi_kids_card_data' ) ) : ?>
						<div class="nexxi-post-sidecard">
							<div class="nexxi-post-sidecard__head">
								<span class="material-symbols-outlined">auto_awesome</span>
								<h2>Mẫu mới tại NEXXI</h2>
							</div>
							<div class="nexxi-post-miniprods">
								<?php foreach ( $sidebar_products as $sprod ) :
									if ( ! ( $sprod instanceof WC_Product ) ) {
										continue;
									}
									$sc = nexxi_kids_card_data( $sprod, 'regular' );
									?>
									<a class="nexxi-post-miniprod" href="<?php echo esc_url( $sc['url'] ); ?>">
										<img class="nexxi-post-miniprod__img" src="<?php echo esc_url( $sc['image'] ); ?>" alt="<?php echo esc_attr( $sc['name'] ); ?>" width="72" height="72" loading="lazy" decoding="async">
										<div class="nexxi-post-miniprod__info">
											<span class="nexxi-post-miniprod__name"><?php echo esc_html( $sc['name'] ); ?></span>
											<span class="nexxi-post-miniprod__price">
												<strong><?php echo esc_html( $sc['priceFrom'] ); ?></strong>
												<?php if ( ! empty( $sc['regular'] ) ) : ?>
													<del><?php echo esc_html( $sc['regular'] ); ?></del>
												<?php endif; ?>
											</span>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
							<a class="nexxi-post-sidecard__more" href="<?php echo esc_url( $flash_url ); ?>">
								<span>Xem ưu đãi Flash Sale</span>
								<span class="material-symbols-outlined">arrow_forward</span>
							</a>
						</div>
					<?php endif; ?>
				</aside>
			</div>

			<?php if ( ! empty( $related_posts ) ) : ?>
				<section class="nexxi-post-section" aria-label="Bài viết liên quan">
					<div class="nexxi-post-section__head">
						<div>
							<span class="nexxi-blog-eyebrow"><span class="material-symbols-outlined">menu_book</span><span>Đọc Thêm Cùng Chuyên Mục</span></span>
							<h2 class="nexxi-post-section__title">Cẩm nang hữu ích khác cho ba mẹ</h2>
						</div>
						<?php if ( $cat_url ) : ?>
							<a class="nexxi-post-section__link" href="<?php echo esc_url( $cat_url ); ?>">
								<span>Xem tất cả chuyên mục</span>
								<span class="material-symbols-outlined">arrow_forward</span>
							</a>
						<?php endif; ?>
					</div>
					<div class="nexxi-blog-grid">
						<?php
						foreach ( $related_posts as $rpost ) {
							echo nexxi_kids_blog_card( $rpost ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $bottom_products ) && function_exists( 'nexxi_kids_card_unified' ) ) : ?>
				<section class="nexxi-post-section" aria-label="Sản phẩm gợi ý cho bé">
					<div class="nexxi-post-section__head">
						<div>
							<span class="nexxi-blog-eyebrow"><span class="material-symbols-outlined">checkroom</span><span>Gợi Ý Từ Cửa Hàng NEXXI</span></span>
							<h2 class="nexxi-post-section__title">Trang phục mềm mát cho bé sẵn kho</h2>
						</div>
						<a class="nexxi-post-section__link" href="<?php echo esc_url( $shop_url ); ?>">
							<span>Khám phá toàn bộ sản phẩm</span>
							<span class="material-symbols-outlined">arrow_forward</span>
						</a>
					</div>
					<div class="nexxi-post-prod-grid">
						<?php
						foreach ( $bottom_products as $bprod ) {
							if ( $bprod instanceof WC_Product ) {
								echo nexxi_kids_card_unified( $bprod, 'regular' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
						}
						?>
					</div>
				</section>
			<?php endif; ?>
		</div>
	</main>
	<?php
	$GLOBALS['post'] = $main_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $main_post );
	return ob_get_clean();
}
