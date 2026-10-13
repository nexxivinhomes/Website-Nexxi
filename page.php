<?php
/** WordPress pages and WooCommerce account/cart/checkout routes. */
if ( function_exists( 'is_cart' ) && is_cart() ) {
    nexxi_kids_render_stitch( 'cart' );
    return;
}
if ( function_exists( 'is_checkout' ) && is_checkout() ) {
    nexxi_kids_render_stitch( 'checkout' );
    return;
}
if ( function_exists( 'is_account_page' ) && is_account_page() ) {
    nexxi_kids_render_stitch( is_user_logged_in() ? 'account' : 'login' );
    return;
}
// Every other page (Giới thiệu, Liên hệ, Yêu thích, Đổi trả, Vận chuyển…) uses the Stitch content shell.
nexxi_kids_render_stitch( 'page' );
