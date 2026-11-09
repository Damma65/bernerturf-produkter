<?php
/**
 * Översättning med Claude (Anthropic API) från finska till svenska, engelska och norska.
 * Nyckeln läggs in under Produkter > Importera från Excel > Inställningar (eller som konstanten
 * BTP_ANTHROPIC_KEY i wp-config.php, som då går före). Saknas nyckel översätts inget och
 * webben visar engelska där text saknas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_settings() {
	$s = get_option( 'btp_settings', array() );
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'api_key'  => '',
			'model'    => 'claude-sonnet-5-5',
			'glossary' => btp_default_glossary(),
			'source'   => 'fi',
			// SharePoint / automatisk import.
			'ms_tenant' => '',
			'ms_client' => '',
			'ms_secret' => '',
			'ms_file'   => '',
			'ms_folder' => '',
			'auto_on'   => 0,
			'auto_time' => '03:00',
			'report_to' => '',
		)
	);
}

function btp_api_key() {
	if ( defined( 'BTP_ANTHROPIC_KEY' ) && BTP_ANTHROPIC_KEY ) {
		return (string) BTP_ANTHROPIC_KEY;
	}
	return (string) btp_settings()['api_key'];
}

function btp_ai_ready() {
	return '' !== btp_api_key();
}

/**
 * Ordlistan: en rad per term, "finska = svenska | engelska | norska". Hämtad ur Sailas egna översättningar.
 */
function btp_default_glossary() {
	return implode(
		"\n",
		array(
			'viheriö, viheriöt = green, greener | green, greens | green, greener',
			'väylä, väylät = fairway, fairways | fairway, fairways | fairway, fairwayer',
			'avauspaikka = tee | tee | tee',
			'nurmi, nurmikko = gräs, gräsyta | turf | gress, gressflate',
			'viheralue = grönområde | turf area | grøntområde',
			'kasvukausi = växtsäsong | growing season | vekstsesong',
			'käyttömäärä = appliceringsmängd | application rate | doseringsmengde',
			'suositeltu maan lämpötila = rekommenderad jordtemperatur | recommended soil temperature | anbefalt jordtemperatur',
			'kestoaika = hållbarhet | duration | virketid',
			'raekoko = kornstorlek | granule size | kornstørrelse',
			'hidasliukoinen = långsamt löslig | slow-release | langsomt løselig',
			'lehtilannoitus = bladgödsling | foliar feeding | bladgjødsling',
			'kostutusaine = fuktmedel | wetting agent | fuktemiddel',
			'lava = helpall | pallet | pall',
			'suursäkki = storsäck | big bag | storsekk',
			'säkki = säck | bag | sekk',
			'kpl = st | pcs | stk',
		)
	);
}

function btp_lang_name_en( $lang ) {
	$names = array( 'sv' => 'Swedish', 'fi' => 'Finnish', 'en' => 'English (British)', 'no' => 'Norwegian (Bokmål)' );
	return isset( $names[ $lang ] ) ? $names[ $lang ] : $lang;
}

/**
 * Översätter en produkts textfält. $fields = array( fält => text ) på källspråket.
 * $targets = array( 'sv', 'en', 'no' ). $protect = namn som aldrig får översättas.
 * Ger array( språk => array( fält => text ) ) eller WP_Error.
 *
 * Sedan 0.5.1: ett anrop per språk, och Claude måste svara via ett verktyg med fast schema
 * (tool_choice). Då kommer svaret alltid som ett giltigt objekt – inget fritt JSON att tolka.
 * Avklippta svar och tillfälliga fel (429, 5xx, överbelastning) försöks igen.
 * Lyckas inte alla språk returneras de som lyckades; felet gäller bara resten.
 */
function btp_ai_translate( $fields, $targets, $protect = array(), $source = 'fi' ) {
	$key = btp_api_key();
	if ( '' === $key ) {
		return new WP_Error( 'btp_ai', 'Ingen API-nyckel för Claude är inlagd.' );
	}
	$fields  = array_filter( array_map( 'strval', $fields ), 'strlen' );
	$targets = array_values( array_diff( $targets, array( $source ) ) );
	if ( empty( $fields ) || empty( $targets ) ) {
		return array();
	}
	$out    = array();
	$failed = array();
	foreach ( $targets as $lang ) {
		$res = btp_ai_translate_one( $fields, $lang, $protect, $source );
		if ( is_wp_error( $res ) ) {
			$failed[] = $lang . ': ' . $res->get_error_message();
			continue;
		}
		if ( $res ) {
			$out[ $lang ] = $res;
		}
	}
	if ( empty( $out ) && $failed ) {
		return new WP_Error( 'btp_ai', implode( ' · ', $failed ) );
	}
	return $out;
}

/**
 * Ett språk. Ger array( fält => text ) eller WP_Error.
 */
function btp_ai_translate_one( $fields, $lang, $protect, $source ) {
	$settings = btp_settings();
	$protect  = array_values( array_unique( array_filter( array_map( 'trim', (array) $protect ) ) ) );

	$system = 'You translate product texts for Berner Turf, a Nordic supplier of fertilisers, plant protection, wetting agents and seed for golf courses, football pitches and municipal sports turf. '
		. 'The readers are greenkeepers and groundsmen. Write the way a native turf professional in the target country would write it: precise, natural and short. Do not add or drop information.' . "\n\n"
		. "Rules:\n"
		. "1. Never translate product names, brand names, supplier names or trademarks. Copy them exactly. Protected names: " . ( $protect ? implode( '; ', $protect ) : '(none)' ) . "\n"
		. "2. Keep every number, unit, range, percentage, chemical symbol, NPK value and granule size (e.g. SGN 140) exactly as written. Use the decimal separator of the target language (comma for sv, no and fi; point for en).\n"
		. "3. Keep the same lines: one line in gives one line out, in the same order. Keep bullet characters (•) and lines starting with '#' at the start of lines.\n"
		. "4. Lines in the form 'Label: value' keep that form; translate both parts but keep numbers unchanged.\n"
		. "5. In the field 'naring' each line is 'name | value | colour'. Translate only the name; copy the value and the colour word exactly.\n"
		. "6. URLs are copied unchanged.\n"
		. "7. Use this glossary (Finnish = Swedish | English | Norwegian):\n" . trim( (string) $settings['glossary'] ) . "\n\n"
		. 'Return the result only by calling the tool save_translation, with every field filled in.';

	$props = array();
	foreach ( array_keys( $fields ) as $f ) {
		$props[ $f ] = array( 'type' => 'string', 'description' => 'The field "' . $f . '" translated into ' . btp_lang_name_en( $lang ) . '.' );
	}
	$tool = array(
		'name'         => 'save_translation',
		'description'  => 'Saves the translated product fields in ' . btp_lang_name_en( $lang ) . '.',
		'input_schema' => array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => array_keys( $fields ),
			'additionalProperties' => false,
		),
	);
	$user = 'Source language: ' . btp_lang_name_en( $source ) . "\nTarget language: " . btp_lang_name_en( $lang ) . "\nFields:\n" . wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

	$max_tokens = 8000;
	$last       = null;
	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 90,
				'headers' => array(
					'x-api-key'         => btp_api_key(),
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $settings['model'] ? $settings['model'] : 'claude-sonnet-5-5',
						'max_tokens'  => $max_tokens,
						'system'      => $system,
						'tools'       => array( $tool ),
						'tool_choice' => array( 'type' => 'tool', 'name' => 'save_translation' ),
						'messages'    => array( array( 'role' => 'user', 'content' => $user ) ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$last = new WP_Error( 'btp_ai', 'Kunde inte nå Claude: ' . $response->get_error_message() );
			sleep( 2 * $attempt );
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			$last = new WP_Error( 'btp_ai', 'Claude svarade med fel: ' . $msg );
			if ( 429 === $code || $code >= 500 ) {
				sleep( 3 * $attempt );
				continue;
			}
			return $last; // T.ex. fel nyckel – lönt inte att försöka igen.
		}
		if ( isset( $body['stop_reason'] ) && 'max_tokens' === $body['stop_reason'] ) {
			$last       = new WP_Error( 'btp_ai', 'Svaret blev för långt och klipptes av.' );
			$max_tokens = 16000;
			continue;
		}
		$input = null;
		foreach ( (array) ( isset( $body['content'] ) ? $body['content'] : array() ) as $part ) {
			if ( isset( $part['type'], $part['input'] ) && 'tool_use' === $part['type'] && is_array( $part['input'] ) ) {
				$input = $part['input'];
			}
		}
		if ( ! is_array( $input ) ) {
			$last = new WP_Error( 'btp_ai', 'Claude lämnade inget svar i rätt format.' );
			continue;
		}
		$out = array();
		foreach ( array_keys( $fields ) as $field ) {
			if ( isset( $input[ $field ] ) && is_string( $input[ $field ] ) && '' !== trim( $input[ $field ] ) ) {
				$out[ $field ] = trim( $input[ $field ] );
			}
		}
		if ( $out ) {
			return $out;
		}
		$last = new WP_Error( 'btp_ai', 'Claude lämnade tomma fält.' );
	}
	return $last ? $last : new WP_Error( 'btp_ai', 'Översättningen misslyckades.' );
}
