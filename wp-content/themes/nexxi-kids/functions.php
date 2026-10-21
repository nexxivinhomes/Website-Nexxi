<?php
/** NEXXI Kids theme setup and helpers. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'NEXXI_KIDS_VERSION', '1.0.0' );
foreach ( array( 'stitch-dom', 'stitch-data', 'stitch-components', 'stitch-home', 'stitch-shop', 'stitch-product', 'stitch-pages', 'stitch', 'stitch-cart', 'stitch-account', 'setup-pages', 'flash-sale-admin', 'studio', 'studio-customizer' ) as $nexxi_kids_module ) {
	require_once get_template_directory() . '/inc/' . $nexxi_kids_module . '.php';
}

function nexxi_kids_setup() {
	load_theme_textdomain( 'nexxi-kids', get_template_directory() . '/languages' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array( 'height' => 90, 'width' => 420, 'flex-height' => true, 'flex-width' => true ) );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'woocommerce', array( 'thumbnail_image_width' => 480, 'single_image_width' => 760, 'product_grid' => array( 'default_rows' => 3, 'min_rows' => 1, 'max_rows' => 8, 'default_columns' => 4, 'min_columns' => 2, 'max_columns' => 5 ) ) );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
	register_nav_menus( array( 'primary' => __( 'Điều hướng chính', 'nexxi-kids' ), 'footer' => __( 'Điều hướng chân trang', 'nexxi-kids' ) ) );
}
add_action( 'after_setup_theme', 'nexxi_kids_setup' );

function nexxi_kids_assets() {
	if ( is_front_page() || ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) { return; }
	wp_enqueue_style( 'nexxi-kids-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap', array(), null );
	wp_enqueue_style( 'nexxi-kids-style', get_stylesheet_uri(), array( 'nexxi-kids-fonts' ), NEXXI_KIDS_VERSION );
	wp_enqueue_style( 'nexxi-kids-theme', get_template_directory_uri() . '/assets/css/theme.css', array( 'nexxi-kids-style' ), NEXXI_KIDS_VERSION );
	wp_enqueue_script( 'nexxi-kids-theme', get_template_directory_uri() . '/assets/js/theme.js', array(), NEXXI_KIDS_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'nexxi_kids_assets' );

function nexxi_kids_fallback_menu() {
	$links = array(
		__( 'Trang chủ', 'nexxi-kids' ) => home_url( '/' ),
		__( 'Cửa hàng', 'nexxi-kids' ) => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
		__( 'Flash Sale', 'nexxi-kids' ) => function_exists( 'wc_get_page_permalink' ) ? add_query_arg( 'on_sale', '1', wc_get_page_permalink( 'shop' ) ) : home_url( '/shop/' ),
		__( 'Bảng size', 'nexxi-kids' ) => home_url( '/#bang-size' ),
	);
	echo '<ul class="site-nav__list">';
	foreach ( $links as $label => $url ) { printf( '<li><a href="%s">%s</a></li>', esc_url( $url ), esc_html( $label ) ); }
	echo '</ul>';
}

function nexxi_kids_cart_count() {
	if ( function_exists( 'WC' ) && WC()->cart ) { return (int) WC()->cart->get_cart_contents_count(); }
	return 0;
}

function nexxi_kids_cart_fragments( $fragments ) {
	ob_start(); ?><span class="cart-count"><?php echo esc_html( nexxi_kids_cart_count() ); ?></span><?php
	$fragments['span.cart-count'] = ob_get_clean();
	$fragments['nexxi-cart-count'] = nexxi_kids_cart_count();
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'nexxi_kids_cart_fragments' );

function nexxi_kids_filter_sale_products( $query ) {
	if ( isset( $_GET['on_sale'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['on_sale'] ) ) ) {
		$ids = nexxi_kids_all_sale_parent_ids();
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
	}
}
add_action( 'woocommerce_product_query', 'nexxi_kids_filter_sale_products' );

function nexxi_kids_product_card( $product ) {
	if ( ! $product instanceof WC_Product ) { return; }
	echo nexxi_kids_card_unified( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function nexxi_kids_product_section( $args = array() ) {
	if ( ! function_exists( 'wc_get_products' ) ) { return; }
	$products = wc_get_products( wp_parse_args( $args, array( 'limit' => 4, 'orderby' => 'date', 'order' => 'DESC', 'status' => 'publish' ) ) );
	if ( ! $products ) { echo '<p class="empty-state">' . esc_html__( 'Sản phẩm mới sẽ sớm được cập nhật.', 'nexxi-kids' ) . '</p>'; return; }
	echo '<div class="product-grid">';
	foreach ( $products as $product ) { nexxi_kids_product_card( $product ); }
	echo '</div>';
}

function nexxi_kids_customize_register( $customizer ) {
	$customizer->add_setting( 'nexxi_kids_flash_sale_end', array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ) );
	$customizer->add_control( 'nexxi_kids_flash_sale_end', array( 'label' => __( 'Flash Sale kết thúc (ISO 8601, kèm múi giờ). Để trống = hết ngày hôm nay', 'nexxi-kids' ), 'section' => 'nexxi_kids_store', 'type' => 'text' ) );
}
add_action( 'customize_register', 'nexxi_kids_customize_register', 30 );
