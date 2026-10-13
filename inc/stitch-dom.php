<?php
/**
 * Marker-based HTML helpers for the Stitch shells.
 *
 * Sections are located through data-nx hooks (or ids) and matched with a
 * balanced tag scan, so edits never depend on sample copy, prices or long
 * Tailwind class strings. The rest of the document stays byte-for-byte intact.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Void elements never have a closing tag. */
function nexxi_dom_is_void( $tag ) {
	return in_array( strtolower( $tag ), array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' ), true );
}

/**
 * Locate an element by attribute selector.
 *
 * @param string $html     Document.
 * @param string $selector `nx:name` (data-nx), `#id`, or a raw attribute string such as `data-path="lien-he"`.
 * @param int    $offset   Byte offset to start searching from.
 * @return array|null      start, open_end, inner_start, inner_end, end, tag.
 */
function nexxi_dom_locate( $html, $selector, $offset = 0 ) {
	if ( 0 === strpos( $selector, 'nx:' ) ) {
		$needle = 'data-nx="' . substr( $selector, 3 ) . '"';
	} elseif ( 0 === strpos( $selector, '#' ) ) {
		$needle = 'id="' . substr( $selector, 1 ) . '"';
	} else {
		$needle = $selector;
	}
	$pos = strpos( $html, $needle, $offset );
	if ( false === $pos ) { return null; }
	$start = '<' === $needle[0] ? $pos : strrpos( substr( $html, 0, $pos ), '<' );
	if ( false === $start || ! preg_match( '~\G<([a-zA-Z][a-zA-Z0-9-]*)~', $html, $m, 0, $start ) ) { return null; }
	$tag = strtolower( $m[1] );
	$open_end = nexxi_dom_tag_end( $html, $start );
	if ( false === $open_end ) { return null; }
	if ( nexxi_dom_is_void( $tag ) ) {
		return array( 'start' => $start, 'open_end' => $open_end, 'inner_start' => $open_end, 'inner_end' => $open_end, 'end' => $open_end, 'tag' => $tag );
	}
	$close = nexxi_dom_matching_close( $html, $tag, $open_end );
	if ( null === $close ) { return null; }
	return array( 'start' => $start, 'open_end' => $open_end, 'inner_start' => $open_end, 'inner_end' => $close[0], 'end' => $close[1], 'tag' => $tag );
}

/** Position right after the `>` that ends the tag starting at $start (quote aware). */
function nexxi_dom_tag_end( $html, $start ) {
	$len = strlen( $html );
	$quote = '';
	for ( $i = $start + 1; $i < $len; $i++ ) {
		$c = $html[ $i ];
		if ( '' !== $quote ) { if ( $c === $quote ) { $quote = ''; } continue; }
		if ( '"' === $c || "'" === $c ) { $quote = $c; continue; }
		if ( '>' === $c ) { return $i + 1; }
	}
	return false;
}

/** Find the closing tag that balances an opening tag, skipping script/style bodies and comments. */
function nexxi_dom_matching_close( $html, $tag, $from ) {
	$depth = 1;
	$pattern = '~<!--.*?-->|<(script|style)\b[^>]*>.*?</\1\s*>|<(/?)(' . preg_quote( $tag, '~' ) . ')\b[^>]*>~is';
	if ( 'script' === $tag || 'style' === $tag ) {
		if ( ! preg_match( '~</' . $tag . '\s*>~i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) { return null; }
		return array( $m[0][1], $m[0][1] + strlen( $m[0][0] ) );
	}
	$offset = $from;
	while ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$offset = $m[0][1] + strlen( $m[0][0] );
		if ( ! isset( $m[3] ) || '' === $m[3][0] ) { continue; } // comment or script/style block.
		if ( '/' === $m[2][0] ) {
			--$depth;
			if ( 0 === $depth ) { return array( $m[0][1], $offset ); }
		} elseif ( '/>' !== substr( $m[0][0], -2 ) ) {
			++$depth;
		}
	}
	return null;
}

/** Replace the children of an element. Returns the document unchanged if the hook is missing. */
function nexxi_dom_set_inner( $html, $selector, $inner ) {
	$el = nexxi_dom_locate( $html, $selector );
	if ( ! $el ) { return $html; }
	return substr( $html, 0, $el['inner_start'] ) . $inner . substr( $html, $el['inner_end'] );
}

/** Replace a whole element (tag included). */
function nexxi_dom_set_outer( $html, $selector, $outer ) {
	$el = nexxi_dom_locate( $html, $selector );
	if ( ! $el ) { return $html; }
	return substr( $html, 0, $el['start'] ) . $outer . substr( $html, $el['end'] );
}

/** Return the outer HTML of an element, or '' if missing. */
function nexxi_dom_get_outer( $html, $selector ) {
	$el = nexxi_dom_locate( $html, $selector );
	return $el ? substr( $html, $el['start'], $el['end'] - $el['start'] ) : '';
}

/** Return the inner HTML of an element, or '' if missing. */
function nexxi_dom_get_inner( $html, $selector ) {
	$el = nexxi_dom_locate( $html, $selector );
	return $el ? substr( $html, $el['inner_start'], $el['inner_end'] - $el['inner_start'] ) : '';
}

/** Set (or add) an attribute on the opening tag of an element. */
function nexxi_dom_set_attr( $html, $selector, $name, $value ) {
	$el = nexxi_dom_locate( $html, $selector );
	if ( ! $el ) { return $html; }
	$open = substr( $html, $el['start'], $el['open_end'] - $el['start'] );
	$open = nexxi_dom_tag_with_attr( $open, $name, $value );
	return substr( $html, 0, $el['start'] ) . $open . substr( $html, $el['open_end'] );
}

/** Apply an attribute to an opening tag string. */
function nexxi_dom_tag_with_attr( $open, $name, $value ) {
	$attr = $name . '="' . esc_attr( $value ) . '"';
	$pattern = '~(\s)' . preg_quote( $name, '~' ) . '="[^"]*"~';
	if ( preg_match( $pattern, $open ) ) {
		return preg_replace_callback( $pattern, static function ( $m ) use ( $attr ) { return $m[1] . $attr; }, $open, 1 );
	}
	return preg_replace( '~^<([a-zA-Z0-9-]+)~', '<$1 ' . str_replace( '$', '\$', $attr ), $open, 1 );
}

/** Apply a callback to the outer HTML of every element that carries the attribute string. */
function nexxi_dom_each( $html, $selector, callable $callback ) {
	$offset = 0;
	$guard = 0;
	while ( $guard++ < 500 ) {
		$probe = nexxi_dom_locate( $html, $selector, $offset );
		if ( ! $probe ) { break; }
		$outer = substr( $html, $probe['start'], $probe['end'] - $probe['start'] );
		$new = (string) call_user_func( $callback, $outer );
		$html = substr( $html, 0, $probe['start'] ) . $new . substr( $html, $probe['end'] );
		$offset = $probe['start'] + max( 1, strlen( $new ) );
	}
	return $html;
}

/** Remove an element entirely. */
function nexxi_dom_remove( $html, $selector ) {
	return nexxi_dom_set_outer( $html, $selector, '' );
}

/** Replace the first top-level element of a tag (e.g. the outer <main>) using balanced matching. */
function nexxi_dom_replace_first_tag( $html, $tag, $outer ) {
	if ( ! preg_match( '~<' . preg_quote( $tag, '~' ) . '\b~i', $html, $m, PREG_OFFSET_CAPTURE ) ) { return $html; }
	$start = $m[0][1];
	$open_end = nexxi_dom_tag_end( $html, $start );
	$close = false === $open_end ? null : nexxi_dom_matching_close( $html, strtolower( $tag ), $open_end );
	if ( ! $close ) { return $html; }
	return substr( $html, 0, $start ) . $outer . substr( $html, $close[1] );
}
