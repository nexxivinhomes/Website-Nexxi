<?php
/** Stitch visual screens for WooCommerce routes. */
if ( is_product() ) {
    nexxi_kids_render_stitch( 'product' );
} elseif ( isset( $_GET['on_sale'] ) ) {
    nexxi_kids_render_stitch( 'flash-sale' );
} else {
    nexxi_kids_render_stitch( 'shop' );
}
