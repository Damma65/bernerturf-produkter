<?php
/**
 * Den nya produktsidan på produktens egen adress (/product/…, /sv/product/… osv.).
 *
 * När en produkt har den nya produktsidan påslagen (btp_artikel, sätts av importen) visas den
 * godkända designen i stället för temats produktmall. Temats sidhuvud och sidfot används som vanligt.
 * Produkter utan ny produktsida visas med temats mall som förut.
 *
 * Stängs av med define( 'BTP_DISABLE_SINGLE_TEMPLATE', true ); i wp-config.php
 * eller filtret bernerturf_produkter_single_template (false).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_single_template_on() {
	if ( defined( 'BTP_DISABLE_SINGLE_TEMPLATE' ) && BTP_DISABLE_SINGLE_TEMPLATE ) {
		return false;
	}
	return (bool) apply_filters( 'bernerturf_produkter_single_template', true );
}

/**
 * Produkten som ska visas med den nya sidan, eller 0.
 */
function btp_single_product_id() {
	if ( ! btp_single_template_on() || ! is_singular( 'berner-products' ) ) {
		return 0;
	}
	$id  = (int) get_queried_object_id();
	$src = btp_source_id( $id );
	if ( ! $src || 'publish' !== get_post_status( $src ) || ! btp_visible_here( $src ) ) {
		return 0;
	}
	$a = btp_article_data( $src );
	return $a['artikel'] ? $src : 0;
}

function btp_single_template_include( $template ) {
	if ( btp_single_product_id() ) {
		btp_enqueue_assets();
		$own = BTP_DIR . 'templates/single-product.php';
		if ( file_exists( $own ) ) {
			return $own;
		}
	}
	return $template;
}
add_filter( 'template_include', 'btp_single_template_include', 99 );

// Assets registreras på wp_enqueue_scripts; se till att de köas på produktsidan även om mallen väljs tidigt.
add_action( 'wp_enqueue_scripts', function () {
	if ( btp_single_product_id() ) {
		btp_enqueue_assets();
	}
}, 20 );

// Kroppsklass så att temats CSS kan skiljas från vår.
add_filter( 'body_class', function ( $classes ) {
	if ( btp_single_product_id() ) {
		$classes[] = 'btp-single';
	}
	return $classes;
} );

/**
 * Metabeskrivning för produktsidor: ingressen från Excel på besökarens språk, när Yoast inte har
 * någon egen beskrivning för produkten. En beskrivning skriven i Yoast vinner alltid.
 */
function btp_product_meta_description() {
	if ( ! is_singular( 'berner-products' ) ) {
		return '';
	}
	$src  = btp_source_id( (int) get_queried_object_id() );
	$text = trim( wp_strip_all_tags( (string) btp_field( $src, 'ingress' ) ) );
	if ( '' === $text ) {
		$text = trim( wp_strip_all_tags( (string) btp_field( $src, 'beskrivning' ) ) );
	}
	$text = preg_replace( '/\s+/u', ' ', $text );
	if ( mb_strlen( $text ) > 155 ) {
		$cut  = mb_substr( $text, 0, 152 );
		$sp   = mb_strrpos( $cut, ' ' );
		$text = ( $sp > 100 ? mb_substr( $cut, 0, $sp ) : $cut ) . '…';
	}
	return $text;
}

add_filter( 'wpseo_metadesc', function ( $desc ) {
	if ( '' !== trim( (string) $desc ) ) {
		return $desc;
	}
	$own = btp_product_meta_description();
	return '' !== $own ? $own : $desc;
}, 20 );

add_filter( 'wpseo_opengraph_desc', function ( $desc ) {
	if ( '' !== trim( (string) $desc ) ) {
		return $desc;
	}
	$own = btp_product_meta_description();
	return '' !== $own ? $own : $desc;
}, 20 );
