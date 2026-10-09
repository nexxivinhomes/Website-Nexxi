<?php
/**
 * Nexxi theme setup and assets.
 *
 * @package Nexxi_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register WordPress and WooCommerce theme features.
 *
 * @return void
 */
function nexxi_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'woocommerce' );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'nexxi-theme' ),
		)
	);
}
add_action( 'after_setup_theme', 'nexxi_theme_setup' );

/**
 * Load locally built production assets.
 *
 * @return void
 */
function nexxi_theme_enqueue_assets() {
	$css_path = get_theme_file_path( '/assets/css/app.css' );
	$js_path  = get_theme_file_path( '/assets/js/app.js' );

	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'nexxi-theme',
			get_theme_file_uri( '/assets/css/app.css' ),
			array(),
			(string) filemtime( $css_path )
		);
	}

	if ( file_exists( $js_path ) ) {
		wp_enqueue_script(
			'nexxi-theme',
			get_theme_file_uri( '/assets/js/app.js' ),
			array(),
			(string) filemtime( $js_path ),
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'nexxi_theme_enqueue_assets' );
