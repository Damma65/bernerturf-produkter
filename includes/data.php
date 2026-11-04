<?php
/**
 * Hämtar och tolkar produktdata. Ordningen för varje uppgift är:
 * 1. fälten som pluginet lägger på produkten (kan ändras i WP-admin eller via REST),
 * 2. temats egna data (ACF, kategorier, certifikat),
 * 3. inbyggda reservvärden (bara för demoprodukten och leverantörer som inte är märkta än).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_countries() {
	return btp_country_names();
}

/**
 * Leverantörerna i filtret. Kommer från taxonomin "Leverantörer" om den har termer,
 * annars från listan nedan. Varje rad: name, slug, color, soon, order.
 */
function btp_suppliers() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$list  = array();
	$terms = taxonomy_exists( 'btp-leverantor' ) ? get_terms( array( 'taxonomy' => 'btp-leverantor', 'hide_empty' => false ) ) : array();
	if ( is_array( $terms ) && ! empty( $terms ) ) {
		foreach ( $terms as $t ) {
			$order  = get_term_meta( $t->term_id, 'btp_ordning', true );
			$list[] = array(
				'name'  => $t->name,
				'slug'  => $t->slug,
				'color' => (string) get_term_meta( $t->term_id, 'btp_farg', true ),
				'soon'  => (bool) get_term_meta( $t->term_id, 'btp_snart', true ),
				'order' => '' === $order ? 50 : (int) $order,
			);
		}
	} else {
		$defaults = array( 'Barenbrug' => true, 'Syngenta' => false, 'Indigrow' => false, 'Haifa' => false, 'DCM' => false );
		$i        = 10;
		foreach ( $defaults as $name => $soon ) {
			$list[] = array( 'name' => $name, 'slug' => sanitize_title( $name ), 'color' => '', 'soon' => $soon, 'order' => $i );
			$i     += 10;
		}
	}
	usort( $list, function ( $a, $b ) {
		return $a['order'] === $b['order'] ? strcmp( $a['name'], $b['name'] ) : $a['order'] - $b['order'];
	} );
	$list[] = array( 'name' => 'Övriga', 'slug' => 'ovriga', 'color' => '', 'soon' => false, 'order' => 999 );
	$cache  = apply_filters( 'bernerturf_produkter_suppliers', $list );
	return $cache;
}

function btp_supplier_info( $name ) {
	foreach ( btp_suppliers() as $s ) {
		if ( $s['name'] === $name ) {
			return $s;
		}
	}
	return array( 'name' => $name, 'slug' => 'ovriga', 'color' => '', 'soon' => false, 'order' => 999 );
}

/**
 * Svenska kategorinamn per kategori-slug i temats taxonomi. Den mest specifika vinner.
 */
function btp_categories() {
	return array(
		'rakeiset-lannoitteet'       => 'Gödsel, granulär',
		'ruiskutettavat-lannoitteet' => 'Gödsel, flytande',
		'kasvinsuojelu'              => 'Växtskydd',
		'kostutusaineet'             => 'Fuktmedel',
		'biostimulantit'             => 'Biostimulanter',
		'maanparannus'               => 'Jordförbättring',
		'siemenlajikkeet'            => 'Frö',
		'siemenseokset'              => 'Frö',
		'siemenet'                   => 'Frö',
		'siemen'                     => 'Frö',
		'tarvikkeet'                 => 'Tillbehör',
		'variaineet'                 => 'Färgämnen',
		'linjamerkinta'              => 'Linjemärkning',
		'lannoitteet'                => 'Gödsel',
	);
}

/**
 * Leverantör: 1) taxonomin Leverantörer, 2) produkttagg med leverantörsnamn, 3) produktnamnet.
 */
function btp_supplier_for( $post_id, $title ) {
	$own = taxonomy_exists( 'btp-leverantor' ) ? get_the_terms( $post_id, 'btp-leverantor' ) : false;
	if ( is_array( $own ) && ! empty( $own ) ) {
		return $own[0]->name;
	}

	$names = wp_list_pluck( btp_suppliers(), 'name' );
	$tags  = get_the_terms( $post_id, 'berner-product-tags' );
	if ( is_array( $tags ) ) {
		foreach ( $tags as $tag ) {
			foreach ( $names as $name ) {
				if ( 0 === strcasecmp( $tag->name, $name ) ) {
					return $name;
				}
			}
		}
	}

	$prefixes = array(
		'Syngenta' => array( 'Ascernity', 'Heritage', 'Instrata', 'Medallion', 'Primo', 'Qualibra', 'Hicure', 'Ryder', 'XC-' ),
		'Indigrow' => array( 'Fusion', 'Compass', 'Magnum', 'Asset', 'Turbine', 'Intek', 'SolarMax', 'Impact', 'Humik', 'Optik', 'RePhlex' ),
		'Haifa'    => array( 'Hi-Green', 'Multigreen', 'Haifa', 'Calcium Nitrate' ),
		'DCM'      => array( 'Ecor' ),
	);
	foreach ( $prefixes as $supplier => $starts ) {
		foreach ( $starts as $start ) {
			if ( 0 === stripos( $title, $start ) ) {
				return $supplier;
			}
		}
	}
	return 'Övriga';
}

function btp_countries_for( $post_id ) {
	$own = btp_parse_markets( get_post_meta( $post_id, 'btp_godkand', true ) );
	if ( array( 'ALL' ) === $own ) {
		return array_keys( btp_countries() );
	}
	if ( ! empty( $own ) && 'DELETED' !== $own[0] ) {
		return $own;
	}
	$terms = get_the_terms( $post_id, 'berner-product-certificates' );
	if ( ! is_array( $terms ) ) {
		return array();
	}
	$words = array(
		'SE' => array( 'sweden', 'sverige', 'ruotsi' ),
		'NO' => array( 'norway', 'norge', 'norja' ),
		'FI' => array( 'finland', 'suomi' ),
		'EE' => array( 'estonia', 'estland', 'viro' ),
		'LV' => array( 'latvia', 'lettland', 'latvian' ),
		'LT' => array( 'lithuania', 'litauen', 'lithuanian', 'liettua' ),
	);
	$found = array();
	foreach ( $terms as $term ) {
		$haystack = strtolower( $term->name . ' ' . $term->slug );
		foreach ( $words as $code => $list ) {
			foreach ( $list as $word ) {
				if ( false !== strpos( $haystack, $word ) ) {
					$found[ $code ] = true;
				}
			}
		}
	}
	return array_values( array_intersect( array_keys( btp_countries() ), array_keys( $found ) ) );
}

function btp_category_for( $post_id, $lang = null ) {
	$lang = $lang ? $lang : btp_lang();
	$key  = (string) get_post_meta( $post_id, 'btp_kat', true );
	if ( '' !== $key && '' !== btp_category_label( $key, $lang ) ) {
		return btp_category_label( $key, $lang );
	}
	$own = trim( (string) get_post_meta( $post_id, 'btp_kategori', true ) );
	if ( '' !== $own && 'sv' === $lang ) {
		return $own;
	}
	$terms = get_the_terms( $post_id, 'berner-product-cat' );
	if ( ! is_array( $terms ) ) {
		return '';
	}
	$slugs = wp_list_pluck( $terms, 'slug' );
	foreach ( array_keys( btp_categories() ) as $slug ) {
		if ( in_array( $slug, $slugs, true ) ) {
			$label = btp_category_label( btp_category_key( $slug ), $lang );
			return '' !== $label ? $label : btp_categories()[ $slug ];
		}
	}
	return '';
}

function btp_image_for( $post_id, $size = 'medium_large' ) {
	$own = absint( get_post_meta( $post_id, 'btp_bild', true ) );
	if ( $own ) {
		$url = wp_get_attachment_image_url( $own, $size );
		if ( $url ) {
			return $url;
		}
	}
	$ids = function_exists( 'get_field' ) ? get_field( 'product_images', $post_id, false ) : get_post_meta( $post_id, 'product_images', true );
	if ( is_string( $ids ) ) {
		$ids = maybe_unserialize( $ids );
	}
	if ( is_array( $ids ) && ! empty( $ids ) ) {
		$first = reset( $ids );
		$first = is_array( $first ) && isset( $first['ID'] ) ? $first['ID'] : $first;
		$url   = wp_get_attachment_image_url( absint( $first ), $size );
		if ( $url ) {
			return $url;
		}
	}
	$thumb = get_the_post_thumbnail_url( $post_id, $size );
	return $thumb ? $thumb : '';
}

function btp_plain( $text ) {
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
}

function btp_theme_short_text( $post_id ) {
	$data = function_exists( 'get_field' ) ? get_field( 'product_data', $post_id ) : array();
	return btp_plain( is_array( $data ) && ! empty( $data['short_description'] ) ? $data['short_description'] : '' );
}

/**
 * Text som katalogens sökfält söker i (visas inte på korten): ingress, annars temats beskrivning.
 */
function btp_card_text_for( $post_id ) {
	$a = btp_article_data( $post_id );
	if ( '' !== $a['ingress'] ) {
		return $a['ingress'];
	}
	return btp_theme_short_text( $post_id );
}

function btp_lines( $text ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ), 'strlen' ) );
}

/**
 * "K | 6,6 | grön" per rad => array( label, value, tone ).
 */
function btp_parse_nutrients( $text ) {
	$tones = array(
		'grön' => 'green', 'gron' => 'green', 'green' => 'green', 'vihreä' => 'green', 'grønn' => 'green',
		'ljusgrön' => 'light', 'ljusgron' => 'light', 'light' => 'light', 'light green' => 'light', 'vaaleanvihreä' => 'light', 'lysegrønn' => 'light',
		'beige' => 'beige', 'grå' => 'grey', 'gra' => 'grey', 'grey' => 'grey', 'gray' => 'grey', 'harmaa' => 'grey',
	);
	$out   = array();
	foreach ( btp_lines( $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( count( $parts ) < 2 || '' === $parts[0] ) {
			continue;
		}
		$tone  = isset( $parts[2] ) ? mb_strtolower( $parts[2], 'UTF-8' ) : '';
		$out[] = array( 'label' => $parts[0], 'value' => $parts[1], 'tone' => isset( $tones[ $tone ] ) ? $tones[ $tone ] : 'green' );
	}
	return $out;
}

/**
 * "# Rubrik" startar ett nytt block, övriga rader hör till blocket ovanför.
 */
function btp_parse_groups( $text ) {
	$groups  = array();
	$current = '';
	foreach ( btp_lines( $text ) as $line ) {
		if ( 0 === strpos( $line, '#' ) ) {
			$current            = trim( ltrim( $line, '#' ) );
			$groups[ $current ] = array();
			continue;
		}
		// "Appliceringsmängd: 150–350 kg/ha" utan #-rubriker blir rubrik + rad.
		if ( preg_match( '/^([^:]{2,45}):\s+(.+)$/u', $line, $m ) && ! preg_match( '/\d$/', $m[1] ) ) {
			$groups[ trim( $m[1] ) ][] = trim( $m[2] );
			$current                   = '';
			continue;
		}
		if ( ! isset( $groups[ $current ] ) ) {
			$groups[ $current ] = array();
		}
		$groups[ $current ][] = $line;
	}
	return $groups;
}

/**
 * Inbyggda reservvärden för demoprodukten Turbine Humate OneShot (id 3068), från broschyren.
 * Används bara så länge fälten på produkten är tomma.
 */
function btp_builtin_defaults() {
	return apply_filters(
		'bernerturf_produkter_defaults',
		array(
			3068 => array(
				'artikel'     => true,
				'kategori'    => 'Biostimulant',
				'ingress'     => 'Turbine Humate OneShot baseras på humus- och fulvosyror och har en snabb verkan. Produkten förbättrar näringsupptaget, rottillväxten och växtens stresstålighet.',
				'beskrivning' => 'De små molekylerna i fulvosyrorna bryter dessutom ned svåråtkomliga föreningar i jorden och gör näringsämnena mer tillgängliga för växten.',
				'punkter'     => "Stimulerar rot- och skottillväxt samt förbättrar växtens motståndskraft\nInnehåller 13,7 % organiskt material\nFörbättrar näringsupptaget\nVerkningstid i genomsnitt 2–4 veckor\nPassar bra i tankblandningar med andra gödselmedel",
				'naring'      => "K | 6,6 | grön\nhumussyra | 17,2 | beige\nfulvosyra | 15,1 | beige",
				'anvandning'  => "# Appliceringsmängd\n2–4 påsar/ha bladgödsling\n4–6 påsar/ha jordbehandling, beroende på växtförhållanden\nVattenmängd 400–1000 l/ha\n# Rekommenderad jordtemperatur\nminst 8 °C, optimal 15 °C",
				'forpackning' => '20 påsar/låda',
			),
		)
	);
}

/**
 * Textfälten som finns per språk. Nycklar på produkten: btp_<fält>_<språk>, t.ex. btp_ingress_fi.
 */
function btp_text_fields() {
	return array(
		'ingress'     => 'Ingress',
		'beskrivning' => 'Beskrivning',
		'punkter'     => 'Punktlista',
		'anvandning'  => 'Användning',
		'forpackning' => 'Förpackning',
		'naring'      => 'Näringsämnen',
		'sds'         => 'Säkerhetsdatablad (länk)',
	);
}

/**
 * Ett textfält på rätt språk: 1) språket, 2) gamla svenska fältet (bara sv), 3) engelska,
 * 4) inbyggt reservvärde för demoprodukten (bara sv). Säkerhetsdatablad faller aldrig tillbaka på annat språk.
 */
function btp_field( $post_id, $field, $lang = null ) {
	$lang  = $lang ? $lang : btp_lang();
	$order = array( 'btp_' . $field . '_' . $lang );
	if ( 'sv' === $lang ) {
		$order[] = 'btp_' . $field;
	}
	if ( 'sds' !== $field && 'en' !== $lang ) {
		$order[] = 'btp_' . $field . '_en';
	}
	foreach ( $order as $key ) {
		$value = get_post_meta( $post_id, $key, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}
	}
	if ( 'sv' === $lang ) {
		$defaults = btp_builtin_defaults();
		if ( isset( $defaults[ $post_id ][ $field ] ) ) {
			return $defaults[ $post_id ][ $field ];
		}
	}
	return '';
}

/**
 * Punktlista: tar bort inledande •, - eller * på varje rad.
 */
function btp_bullets( $text ) {
	return array_map( function ( $l ) {
		return trim( preg_replace( '/^[\x{2022}\-\*\x{2013}]+\s*/u', '', $l ) );
	}, btp_lines( $text ) );
}

/**
 * Samlad data för produktsidan på besökarens språk.
 */
function btp_article_data( $post_id, $lang = null ) {
	$lang     = $lang ? $lang : btp_lang();
	$defaults = btp_builtin_defaults();
	$d        = isset( $defaults[ $post_id ] ) ? $defaults[ $post_id ] : array();
	$artikel  = get_post_meta( $post_id, 'btp_artikel', true );
	return array(
		'artikel'     => ( '' === $artikel || false === $artikel ) ? ! empty( $d['artikel'] ) : (bool) $artikel,
		'kategori'    => btp_category_for( $post_id, $lang ),
		'ingress'     => (string) btp_field( $post_id, 'ingress', $lang ),
		'beskrivning' => (string) btp_field( $post_id, 'beskrivning', $lang ),
		'punkter'     => btp_bullets( btp_field( $post_id, 'punkter', $lang ) ),
		'naring'      => btp_parse_nutrients( btp_field( $post_id, 'naring', $lang ) ),
		'anvandning'  => btp_parse_groups( btp_field( $post_id, 'anvandning', $lang ) ),
		'forpackning' => btp_lines( btp_field( $post_id, 'forpackning', $lang ) ),
		'sds'         => trim( (string) btp_field( $post_id, 'sds', $lang ) ),
	);
}

/**
 * Produktnamnet på besökarens språk (om Excel har ett eget namn för språket), annars titeln.
 */
function btp_title_for( $post_id, $lang = null ) {
	$lang = $lang ? $lang : btp_lang();
	$own  = trim( (string) get_post_meta( $post_id, 'btp_namn_' . $lang, true ) );
	if ( '' !== $own ) {
		return $own;
	}
	return html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Var produkten får visas (kolumnen Webb i Excel): array med landskoder, 'ALL' eller 'DELETED'.
 * Tomt = ingen begränsning (produkter som inte har importerats än).
 */
function btp_visibility( $post_id ) {
	return btp_parse_markets( get_post_meta( $post_id, 'btp_webb', true ) );
}

/**
 * Får produkten visas på den språkversion besökaren är på? Engelska sajten visar allt utom borttagna.
 */
function btp_visible_here( $post_id ) {
	$vis = btp_visibility( $post_id );
	if ( empty( $vis ) || array( 'ALL' ) === $vis ) {
		return true;
	}
	if ( array( 'DELETED' ) === $vis ) {
		return false;
	}
	$country = btp_visitor_country();
	return '' === $country || in_array( $country, $vis, true );
}

function btp_get_products() {
	$posts    = get_posts(
		array(
			'post_type'        => 'berner-products',
			'post_status'      => 'publish',
			'post_parent'      => 0,
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		)
	);
	$products = array();
	foreach ( $posts as $post ) {
		if ( ! btp_visible_here( $post->ID ) ) {
			continue;
		}
		$title      = btp_title_for( $post->ID );
		$article    = btp_article_data( $post->ID );
		$products[] = array(
			'id'        => $post->ID,
			'title'     => $title,
			'url'       => get_permalink( $post ),
			'supplier'  => btp_supplier_for( $post->ID, $title ),
			'category'  => btp_category_for( $post->ID ),
			'countries' => btp_countries_for( $post->ID ),
			'image'     => btp_image_for( $post->ID ),
			'text'      => btp_card_text_for( $post->ID ),
			'artikel'   => $article['artikel'],
		);
	}
	return apply_filters( 'bernerturf_produkter_products', $products );
}

/**
 * Landet för den språkversion besökaren är på (WPML), annars sajtens språk.
 * sv → SE, nb/nn/no → NO, fi → FI, et → EE, lv → LV, lt → LT. Engelska och okända ger tomt (alla länder).
 */
function btp_visitor_country() {
	$lang = btp_raw_lang();
	$map  = array( 'sv' => 'SE', 'nb' => 'NO', 'nn' => 'NO', 'no' => 'NO', 'fi' => 'FI', 'et' => 'EE', 'lv' => 'LV', 'lt' => 'LT' );
	return apply_filters( 'bernerturf_produkter_visitor_country', isset( $map[ $lang ] ) ? $map[ $lang ] : '', $lang );
}
