<?php
/**
 * Språk: vilken språkversion besökaren är på, sajtens fasta texter och kategorinamn per språk.
 * Språken är sv, fi, en och no. Baltikum och övriga får engelska.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_languages() {
	return array( 'sv' => 'Svenska', 'fi' => 'Finska', 'en' => 'Engelska', 'no' => 'Norska' );
}

/**
 * Språkkoden för den språkversion besökaren är på (WPML), annars sajtens språk.
 */
function btp_raw_lang() {
	$lang = '';
	if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
		$lang = ICL_LANGUAGE_CODE;
	}
	$lang = apply_filters( 'wpml_current_language', $lang );
	if ( ! $lang ) {
		$lang = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	}
	return strtolower( substr( (string) $lang, 0, 2 ) );
}

/**
 * Språket texterna visas på: sv, fi, no eller en (allt annat, även Baltikum).
 */
function btp_lang() {
	$raw = btp_raw_lang();
	$map = array( 'sv' => 'sv', 'fi' => 'fi', 'nb' => 'no', 'nn' => 'no', 'no' => 'no' );
	return apply_filters( 'bernerturf_produkter_lang', isset( $map[ $raw ] ) ? $map[ $raw ] : 'en', $raw );
}

/**
 * Fasta texter på sajten per språk.
 */
function btp_strings() {
	return array(
		'sv' => array(
			'products' => 'Produkter', 'search' => 'Sök produkt', 'search_ph' => 'Sök produkt, t.ex. Primo eller fuktmedel',
			'filter' => 'Filter', 'clear' => 'Rensa alla', 'supplier' => 'Leverantör', 'category' => 'Kategori',
			'approved' => 'Godkänd i', 'approved_hint' => 'Visa bara produkter som är registrerade i landet.', 'soon' => 'Snart',
			'count' => 'produkter', 'infotext' => 'Visa infotext', 'per_row' => 'Per rad', 'sort_az' => 'A–Ö', 'empty' => 'Inga produkter matchar. Prova att ta bort ett filter.',
			'from' => 'från', 'in' => 'i', 'approved_in' => 'godkända i',
			'delivered' => 'Levereras till', 'quote' => 'Begär offert', 'talk' => 'Prata med en säljare',
			'description' => 'Beskrivning', 'usage' => 'Användning', 'nutrients' => 'Näringsämnen', 'unit' => 'i viktprocent (%/kg)',
			'package' => 'Förpackning', 'sds' => 'Säkerhetsdatablad', 'sds_missing' => 'Kontakta oss så skickar vi säkerhetsdatabladet.',
			'others' => 'Övriga',
			'hero_title' => 'Allt för gräsytor som ska prestera',
			'hero_lead' => 'Gödsel, växtskydd, fuktmedel, frö och mätverktyg från Syngenta, Indigrow, Haifa, DCM och Barenbrug – samlat på ett ställe.',
		),
		'fi' => array(
			'products' => 'Tuotteet', 'search' => 'Hae tuotetta', 'search_ph' => 'Hae tuotetta, esim. Primo tai kostutusaine',
			'filter' => 'Suodata', 'clear' => 'Tyhjennä', 'supplier' => 'Toimittaja', 'category' => 'Kategoria',
			'approved' => 'Hyväksytty maassa', 'approved_hint' => 'Näytä vain tuotteet, jotka on rekisteröity maassa.', 'soon' => 'Tulossa',
			'count' => 'tuotetta', 'infotext' => 'Näytä kuvaus', 'per_row' => 'Rivillä', 'sort_az' => 'A–Ö', 'empty' => 'Ei osumia. Kokeile poistaa suodatin.',
			'from' => '', 'in' => '', 'approved_in' => 'hyväksytty:',
			'delivered' => 'Toimitetaan', 'quote' => 'Pyydä tarjous', 'talk' => 'Ota yhteyttä myyjään',
			'description' => 'Kuvaus', 'usage' => 'Käyttö', 'nutrients' => 'Ravinteet', 'unit' => 'painoprosenttia (%/kg)',
			'package' => 'Pakkaus', 'sds' => 'Käyttöturvallisuustiedote', 'sds_missing' => 'Ota yhteyttä, niin lähetämme käyttöturvallisuustiedotteen.',
			'others' => 'Muut',
			'hero_title' => 'Kaikki viheralueille, joiden pitää menestyä',
			'hero_lead' => 'Lannoitteet, kasvinsuojelu, kostutusaineet, siemenet ja mittaustyökalut – Syngenta, Indigrow, Haifa, DCM ja Barenbrug samasta paikasta.',
		),
		'en' => array(
			'products' => 'Products', 'search' => 'Search product', 'search_ph' => 'Search product, e.g. Primo or wetting agent',
			'filter' => 'Filter', 'clear' => 'Clear all', 'supplier' => 'Supplier', 'category' => 'Category',
			'approved' => 'Approved in', 'approved_hint' => 'Show only products registered in the country.', 'soon' => 'Soon',
			'count' => 'products', 'infotext' => 'Show info text', 'per_row' => 'Per row', 'sort_az' => 'A–Z', 'empty' => 'No products match. Try removing a filter.',
			'from' => 'from', 'in' => 'in', 'approved_in' => 'approved in',
			'delivered' => 'Delivered to', 'quote' => 'Request a quote', 'talk' => 'Talk to a sales rep',
			'description' => 'Description', 'usage' => 'Directions for use', 'nutrients' => 'Nutrients', 'unit' => 'percent by weight (%/kg)',
			'package' => 'Package size', 'sds' => 'Safety data sheet', 'sds_missing' => 'Contact us and we will send the safety data sheet.',
			'others' => 'Others',
			'hero_title' => 'Everything for turf that has to perform',
			'hero_lead' => 'Fertilisers, plant protection, wetting agents, seed and measuring tools from Syngenta, Indigrow, Haifa, DCM and Barenbrug – all in one place.',
		),
		'no' => array(
			'products' => 'Produkter', 'search' => 'Søk produkt', 'search_ph' => 'Søk produkt, f.eks. Primo eller fuktemiddel',
			'filter' => 'Filter', 'clear' => 'Nullstill', 'supplier' => 'Leverandør', 'category' => 'Kategori',
			'approved' => 'Godkjent i', 'approved_hint' => 'Vis bare produkter som er registrert i landet.', 'soon' => 'Snart',
			'count' => 'produkter', 'infotext' => 'Vis infotekst', 'per_row' => 'Per rad', 'sort_az' => 'A–Å', 'empty' => 'Ingen produkter passer. Prøv å fjerne et filter.',
			'from' => 'fra', 'in' => 'i', 'approved_in' => 'godkjent i',
			'delivered' => 'Leveres til', 'quote' => 'Be om tilbud', 'talk' => 'Snakk med en selger',
			'description' => 'Beskrivelse', 'usage' => 'Bruk', 'nutrients' => 'Næringsstoffer', 'unit' => 'i vektprosent (%/kg)',
			'package' => 'Emballasje', 'sds' => 'Sikkerhetsdatablad', 'sds_missing' => 'Kontakt oss, så sender vi sikkerhetsdatabladet.',
			'others' => 'Øvrige',
			'hero_title' => 'Alt for gressflater som skal prestere',
			'hero_lead' => 'Gjødsel, plantevern, fuktemidler, frø og måleverktøy fra Syngenta, Indigrow, Haifa, DCM og Barenbrug – samlet på ett sted.',
		),
	);
}

function btp_t( $key, $lang = null ) {
	static $all = null;
	if ( null === $all ) {
		$all = apply_filters( 'bernerturf_produkter_strings', btp_strings() );
	}
	$lang = $lang ? $lang : btp_lang();
	if ( isset( $all[ $lang ][ $key ] ) ) {
		return $all[ $lang ][ $key ];
	}
	return isset( $all['en'][ $key ] ) ? $all['en'][ $key ] : $key;
}

function btp_country_names( $lang = null ) {
	$lang  = $lang ? $lang : btp_lang();
	$names = array(
		'sv' => array( 'SE' => 'Sverige', 'NO' => 'Norge', 'FI' => 'Finland', 'EE' => 'Estland', 'LV' => 'Lettland', 'LT' => 'Litauen' ),
		'fi' => array( 'SE' => 'Ruotsi', 'NO' => 'Norja', 'FI' => 'Suomi', 'EE' => 'Viro', 'LV' => 'Latvia', 'LT' => 'Liettua' ),
		'en' => array( 'SE' => 'Sweden', 'NO' => 'Norway', 'FI' => 'Finland', 'EE' => 'Estonia', 'LV' => 'Latvia', 'LT' => 'Lithuania' ),
		'no' => array( 'SE' => 'Sverige', 'NO' => 'Norge', 'FI' => 'Finland', 'EE' => 'Estland', 'LV' => 'Latvia', 'LT' => 'Litauen' ),
	);
	return isset( $names[ $lang ] ) ? $names[ $lang ] : $names['en'];
}

/**
 * Kategorier: nyckel (temats slug) => namn per språk.
 */
function btp_category_table() {
	return apply_filters(
		'bernerturf_produkter_category_table',
		array(
			'rakeiset-lannoitteet'       => array( 'sv' => 'Gödsel, granulär', 'fi' => 'Rakeiset lannoitteet', 'en' => 'Granular fertilisers', 'no' => 'Granulert gjødsel' ),
			'ruiskutettavat-lannoitteet' => array( 'sv' => 'Gödsel, flytande', 'fi' => 'Ruiskutettavat lannoitteet', 'en' => 'Liquid fertilisers', 'no' => 'Flytende gjødsel' ),
			'kasvinsuojelu'              => array( 'sv' => 'Växtskydd', 'fi' => 'Kasvinsuojelu', 'en' => 'Plant protection', 'no' => 'Plantevern' ),
			'kostutusaineet'             => array( 'sv' => 'Fuktmedel', 'fi' => 'Kostutusaineet', 'en' => 'Wetting agents', 'no' => 'Fuktemidler' ),
			'biostimulantit'             => array( 'sv' => 'Biostimulanter', 'fi' => 'Biostimulantit', 'en' => 'Biostimulants', 'no' => 'Biostimulanter' ),
			'maanparannus'               => array( 'sv' => 'Jordförbättring', 'fi' => 'Maanparannus', 'en' => 'Soil improvement', 'no' => 'Jordforbedring' ),
			'siemenet'                   => array( 'sv' => 'Frö', 'fi' => 'Siemenet', 'en' => 'Seed', 'no' => 'Frø' ),
			'tarvikkeet'                 => array( 'sv' => 'Tillbehör', 'fi' => 'Tarvikkeet', 'en' => 'Accessories', 'no' => 'Tilbehør' ),
			'variaineet'                 => array( 'sv' => 'Färgämnen', 'fi' => 'Väriaineet', 'en' => 'Colourants', 'no' => 'Fargestoffer' ),
			'linjamerkinta'              => array( 'sv' => 'Linjemärkning', 'fi' => 'Linjamerkintä', 'en' => 'Line marking', 'no' => 'Linjemerking' ),
			'lannoitteet'                => array( 'sv' => 'Gödsel', 'fi' => 'Lannoitteet', 'en' => 'Fertilisers', 'no' => 'Gjødsel' ),
		)
	);
}

/**
 * Gör om ett kategorinamn från Excel (finska, svenska eller engelska) till nyckel i tabellen ovan.
 */
function btp_category_key( $text ) {
	$slug = btp_slug( $text );
	if ( '' === $slug ) {
		return '';
	}
	$aliases = array(
		'linajkmerkinta' => 'linjamerkinta', 'viivamerkinta' => 'linjamerkinta',
		'varinaineet' => 'variaineet', 'siemen' => 'siemenet', 'siemenlajikkeet' => 'siemenet', 'siemenseokset' => 'siemenet',
	);
	if ( isset( $aliases[ $slug ] ) ) {
		return $aliases[ $slug ];
	}
	$table = btp_category_table();
	if ( isset( $table[ $slug ] ) ) {
		return $slug;
	}
	foreach ( $table as $key => $labels ) {
		foreach ( $labels as $label ) {
			if ( btp_slug( $label ) === $slug ) {
				return $key;
			}
		}
	}
	return '';
}

function btp_category_label( $key, $lang = null ) {
	$lang  = $lang ? $lang : btp_lang();
	$table = btp_category_table();
	if ( ! isset( $table[ $key ] ) ) {
		return '';
	}
	return isset( $table[ $key ][ $lang ] ) ? $table[ $key ][ $lang ] : $table[ $key ]['en'];
}

/**
 * Enkel slug utan beroenden: gemener, å/ä→a, ö→o, allt annat än a–z/0–9 blir bindestreck.
 */
function btp_slug( $text ) {
	$text = mb_strtolower( trim( (string) $text ), 'UTF-8' );
	$text = strtr( $text, array( 'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'ø' => 'o', 'æ' => 'ae', 'é' => 'e', 'ü' => 'u' ) );
	$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
	return trim( $text, '-' );
}

/**
 * Läser en marknadslista: "SE, NO", "FI, Baltics", "All", "Deleted" …
 * Ger array med landskoder, array( 'ALL' ) eller array( 'DELETED' ). Tom array = inget angivet.
 */
function btp_parse_markets( $text ) {
	$text = mb_strtolower( trim( (string) $text ), 'UTF-8' );
	if ( '' === $text ) {
		return array();
	}
	if ( preg_match( '/\b(deleted?|poistettu|poista|borttagen|ta bort|slettet)\b/u', $text ) ) {
		return array( 'DELETED' );
	}
	if ( preg_match( '/\b(all|alla|kaikki|alle)\b/u', $text ) ) {
		return array( 'ALL' );
	}
	$words = array(
		'SE' => array( 'se', 'sv', 'sweden', 'sverige', 'ruotsi' ),
		'NO' => array( 'no', 'nb', 'norway', 'norge', 'norja' ),
		'FI' => array( 'fi', 'finland', 'suomi' ),
		'EE' => array( 'ee', 'et', 'estonia', 'estland', 'viro' ),
		'LV' => array( 'lv', 'latvia', 'lettland' ),
		'LT' => array( 'lt', 'lithuania', 'litauen', 'liettua' ),
	);
	$tokens = preg_split( '/[^a-z]+/u', strtr( $text, array( 'ä' => 'a', 'ö' => 'o', 'å' => 'a' ) ), -1, PREG_SPLIT_NO_EMPTY );
	$found  = array();
	foreach ( $tokens as $tok ) {
		if ( in_array( $tok, array( 'baltics', 'baltic', 'baltikum', 'baltia', 'baltian', 'baltia' ), true ) ) {
			$found['EE'] = $found['LV'] = $found['LT'] = true;
			continue;
		}
		foreach ( $words as $code => $list ) {
			if ( in_array( $tok, $list, true ) ) {
				$found[ $code ] = true;
			}
		}
	}
	return array_values( array_intersect( array( 'SE', 'NO', 'FI', 'EE', 'LV', 'LT' ), array_keys( $found ) ) );
}
