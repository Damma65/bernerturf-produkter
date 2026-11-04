<?php
/**
 * Registrerar det som gör att innehållet kan ändras inifrån WordPress utan ny pluginversion:
 *  - taxonomin "Leverantör" på produkterna,
 *  - fälten för svensk produktsida (text, näring, användning, förpackning),
 *  - fält på leverantörerna (ordning, färg, "kommer snart").
 * Allt syns i REST-API:t så att det också kan uppdateras via kopplingen till sajten.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fälten på produkten. Nyckel => beskrivning (visas i adminrutan).
 */
function btp_meta_fields() {
	return array(
		'btp_artikel'     => array( 'type' => 'boolean', 'label' => 'Visa ny produktsida' ),
		'btp_kategori'    => array( 'type' => 'string', 'label' => 'Kategori (svensk)' ),
		'btp_ingress'     => array( 'type' => 'string', 'label' => 'Ingress' ),
		'btp_beskrivning' => array( 'type' => 'string', 'label' => 'Beskrivning' ),
		'btp_punkter'     => array( 'type' => 'string', 'label' => 'Punktlista' ),
		'btp_naring'      => array( 'type' => 'string', 'label' => 'Näringsämnen' ),
		'btp_anvandning'  => array( 'type' => 'string', 'label' => 'Användning' ),
		'btp_forpackning' => array( 'type' => 'string', 'label' => 'Förpackning' ),
	);
}

/**
 * Fälten som Excel-importen fyller i: per språk (btp_ingress_fi …) och gemensamma.
 * De registreras för REST men visas inte som redigerbara fält i WP-admin (Excel är modern).
 */
function btp_import_meta_fields() {
	$fields = array(
		'btp_webb'      => 'string',  // SE NO FI … / ALL / DELETED
		'btp_godkand'   => 'string',  // landskoder för flaggor och filtret Godkänd i
		'btp_kat'       => 'string',  // kategorinyckel
		'btp_bild'      => 'integer', // bildens id i mediabiblioteket
		'btp_bild_src'  => 'string',  // var bilden kom ifrån (länk eller filnamn)
		'btp_import_at' => 'string',  // senaste import
	);
	foreach ( array_keys( btp_languages() ) as $l ) {
		$fields[ 'btp_namn_' . $l ] = 'string'; // namn per språk (översätts aldrig)
	}
	foreach ( array_keys( btp_text_fields() ) as $f ) {
		foreach ( array_keys( btp_languages() ) as $l ) {
			$fields[ 'btp_' . $f . '_' . $l ] = 'string';
		}
	}
	return $fields;
}

function btp_register_data() {
	if ( ! post_type_exists( 'berner-products' ) ) {
		return;
	}

	// REST-API:t visar bara metafält om posttypen stödjer custom-fields.
	add_post_type_support( 'berner-products', 'custom-fields' );

	register_taxonomy(
		'btp-leverantor',
		'berner-products',
		array(
			'labels'            => array(
				'name'          => 'Leverantörer',
				'singular_name' => 'Leverantör',
				'menu_name'     => 'Leverantörer',
				'add_new_item'  => 'Lägg till leverantör',
				'search_items'  => 'Sök leverantör',
			),
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => 'btp-leverantor',
			'hierarchical'      => false,
			'rewrite'           => false,
			'meta_box_cb'       => 'post_categories_meta_box',
		)
	);

	$auth_term = function () {
		return current_user_can( 'manage_categories' );
	};
	register_term_meta( 'btp-leverantor', 'btp_ordning', array( 'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'default' => 50, 'auth_callback' => $auth_term ) );
	register_term_meta( 'btp-leverantor', 'btp_farg', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '', 'sanitize_callback' => 'sanitize_hex_color', 'auth_callback' => $auth_term ) );
	register_term_meta( 'btp-leverantor', 'btp_snart', array( 'type' => 'boolean', 'single' => true, 'show_in_rest' => true, 'default' => false, 'auth_callback' => $auth_term ) );

	foreach ( btp_import_meta_fields() as $key => $type ) {
		register_post_meta(
			'berner-products',
			$key,
			array(
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	foreach ( btp_meta_fields() as $key => $cfg ) {
		register_post_meta(
			'berner-products',
			$key,
			array(
				'type'              => $cfg['type'],
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => 'boolean' === $cfg['type'] ? false : '',
				'sanitize_callback' => 'boolean' === $cfg['type'] ? 'rest_sanitize_boolean' : 'sanitize_textarea_field',
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
}
add_action( 'init', 'btp_register_data', 20 );
