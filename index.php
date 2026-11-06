<?php
/** Blog home, post search and fallback routes rendered inside the shared Stitch shell. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
	require get_template_directory() . '/woocommerce.php';
	return;
}
if ( is_singular( 'post' ) ) {
	nexxi_kids_render_stitch( 'single' );
	return;
}
if ( is_singular() ) {
	nexxi_kids_render_stitch( 'page' );
	return;
}
nexxi_kids_render_stitch( 'blog' );
