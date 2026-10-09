<?php
/**
 * Site header.
 *
 * @package Nexxi_Theme
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'bg-nexxi-cream text-nexxi-ink antialiased' ); ?>>
<?php wp_body_open(); ?>
<a class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-3" href="#main-content"><?php esc_html_e( 'Skip to content', 'nexxi-theme' ); ?></a>
<header class="border-b border-nexxi-line bg-white" x-data="{ menuOpen: false }">
	<div class="mx-auto flex max-w-nexxi items-center justify-between gap-4 px-4 py-4 sm:px-6">
		<a class="text-xl font-semibold tracking-wide" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
		<nav class="hidden md:block" aria-label="<?php esc_attr_e( 'Primary navigation', 'nexxi-theme' ); ?>">
			<?php if ( has_nav_menu( 'primary' ) ) : ?>
				<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'menu_class' => 'flex items-center gap-6' ) ); ?>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'nexxi-theme' ); ?></a>
			<?php endif; ?>
		</nav>
		<button class="rounded-nexxi border border-nexxi-line px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-nexxi-accent md:hidden" type="button" aria-controls="nexxi-mobile-menu" aria-expanded="false" :aria-expanded="menuOpen.toString()" @click="menuOpen = !menuOpen">
			<span x-show="!menuOpen"><?php esc_html_e( 'Open menu', 'nexxi-theme' ); ?></span>
			<span x-cloak x-show="menuOpen"><?php esc_html_e( 'Close menu', 'nexxi-theme' ); ?></span>
		</button>
	</div>
	<nav id="nexxi-mobile-menu" class="border-t border-nexxi-line px-4 py-4 md:hidden" aria-label="<?php esc_attr_e( 'Mobile navigation', 'nexxi-theme' ); ?>" x-cloak x-show="menuOpen" @keydown.escape.window="menuOpen = false">
		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'menu_class' => 'space-y-3' ) ); ?>
		<?php else : ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'nexxi-theme' ); ?></a>
		<?php endif; ?>
	</nav>
	<noscript>
		<nav class="px-4 pb-4 md:hidden" aria-label="<?php esc_attr_e( 'Mobile navigation', 'nexxi-theme' ); ?>">
			<?php if ( has_nav_menu( 'primary' ) ) : ?>
				<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'menu_class' => 'space-y-3' ) ); ?>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'nexxi-theme' ); ?></a>
			<?php endif; ?>
		</nav>
	</noscript>
</header>
