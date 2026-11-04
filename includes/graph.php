<?php
/**
 * Läser produktfilen och mappen med bilder/säkerhetsdatablad från SharePoint via Microsoft Graph.
 * Appen registreras av Berner IT i Microsoft Entra ID (app-behörighet Sites.Selected, läsrätt till
 * just den SharePoint-webbplatsen). Pluginet skriver aldrig till SharePoint.
 *
 * Uppgifterna (Tenant ID, Client ID, Client secret) läggs in under Inställningar, eller som
 * konstanterna BTP_MS_TENANT, BTP_MS_CLIENT och BTP_MS_SECRET i wp-config.php (går före).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_ms_config() {
	$s = btp_settings();
	return array(
		'tenant' => defined( 'BTP_MS_TENANT' ) && BTP_MS_TENANT ? BTP_MS_TENANT : (string) $s['ms_tenant'],
		'client' => defined( 'BTP_MS_CLIENT' ) && BTP_MS_CLIENT ? BTP_MS_CLIENT : (string) $s['ms_client'],
		'secret' => defined( 'BTP_MS_SECRET' ) && BTP_MS_SECRET ? BTP_MS_SECRET : (string) $s['ms_secret'],
		'file'   => trim( (string) $s['ms_file'] ),
		'folder' => trim( (string) $s['ms_folder'] ),
	);
}

function btp_ms_ready() {
	$c = btp_ms_config();
	return '' !== $c['tenant'] && '' !== $c['client'] && '' !== $c['secret'] && '' !== $c['file'];
}

function btp_graph_token() {
	$cached = get_transient( 'btp_graph_token' );
	if ( $cached ) {
		return $cached;
	}
	$c = btp_ms_config();
	if ( '' === $c['tenant'] || '' === $c['client'] || '' === $c['secret'] ) {
		return new WP_Error( 'btp_ms', 'Uppgifterna för SharePoint-kopplingen är inte ifyllda.' );
	}
	$response = wp_remote_post(
		'https://login.microsoftonline.com/' . rawurlencode( $c['tenant'] ) . '/oauth2/v2.0/token',
		array(
			'timeout' => 30,
			'body'    => array(
				'client_id'     => $c['client'],
				'client_secret' => $c['secret'],
				'scope'         => 'https://graph.microsoft.com/.default',
				'grant_type'    => 'client_credentials',
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'btp_ms', 'Kunde inte nå Microsoft: ' . $response->get_error_message() );
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['access_token'] ) ) {
		$msg = isset( $body['error_description'] ) ? strtok( $body['error_description'], "\r\n" ) : 'okänt fel';
		return new WP_Error( 'btp_ms', 'Microsoft godkände inte inloggningen: ' . $msg );
	}
	set_transient( 'btp_graph_token', $body['access_token'], max( 60, (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ) - 300 ) );
	return $body['access_token'];
}

function btp_graph_get( $path ) {
	$token = btp_graph_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$url      = 0 === strpos( $path, 'https://' ) ? $path : 'https://graph.microsoft.com/v1.0' . $path;
	$response = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'btp_ms', 'Kunde inte nå SharePoint: ' . $response->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code >= 300 ) {
		$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		if ( 403 === $code || 401 === $code ) {
			$msg .= ' (appen saknar läsrätt till webbplatsen – be Berner IT ge den Sites.Selected-läsrätt)';
		} elseif ( 404 === $code ) {
			$msg .= ' (hittar inte filen eller mappen – kontrollera länken)';
		}
		return new WP_Error( 'btp_ms', 'SharePoint: ' . $msg );
	}
	return is_array( $body ) ? $body : array();
}

/**
 * Hittar filen/mappen för en SharePoint-adress. Fungerar med "Kopiera direktsökväg"
 * (https://berner.sharepoint.com/sites/X/Shared Documents/…/fil.xlsx) och med delningslänkar.
 */
function btp_graph_item( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return new WP_Error( 'btp_ms', 'Ingen SharePoint-adress angiven.' );
	}
	// Delningslänk (…/:x:/s/… eller …/:f:/s/…).
	if ( preg_match( '#/:[a-z]:/#i', $url ) ) {
		$share = 'u!' . rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
		return btp_graph_get( '/shares/' . $share . '/driveItem' );
	}
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
		return new WP_Error( 'btp_ms', 'Adressen till SharePoint ser inte rätt ut.' );
	}
	$path = rawurldecode( $parts['path'] );
	if ( ! preg_match( '#^/(sites|teams)/([^/]+)(/.*)?$#', $path, $m ) ) {
		return new WP_Error( 'btp_ms', 'Adressen ska börja med https://…sharepoint.com/sites/…' );
	}
	$site = btp_graph_get( '/sites/' . $parts['host'] . ':/' . $m[1] . '/' . rawurlencode( $m[2] ) );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	$drives = btp_graph_get( '/sites/' . rawurlencode( $site['id'] ) . '/drives' );
	if ( is_wp_error( $drives ) ) {
		return $drives;
	}
	$full = 'https://' . $parts['host'] . $path;
	foreach ( (array) $drives['value'] as $drive ) {
		$base = rtrim( rawurldecode( $drive['webUrl'] ), '/' );
		if ( 0 === stripos( $full, $base . '/' ) ) {
			$rest = substr( $full, strlen( $base ) + 1 );
			$enc  = implode( '/', array_map( 'rawurlencode', explode( '/', $rest ) ) );
			return btp_graph_get( '/drives/' . rawurlencode( $drive['id'] ) . '/root:/' . $enc );
		}
	}
	return new WP_Error( 'btp_ms', 'Hittade inte dokumentbiblioteket för adressen.' );
}

/**
 * Filerna i en mapp (bara filer, inte undermappar).
 */
function btp_graph_children( $folder ) {
	if ( empty( $folder['parentReference']['driveId'] ) || empty( $folder['id'] ) ) {
		return new WP_Error( 'btp_ms', 'Mappen kunde inte läsas.' );
	}
	$next  = '/drives/' . rawurlencode( $folder['parentReference']['driveId'] ) . '/items/' . rawurlencode( $folder['id'] ) . '/children?$top=999';
	$items = array();
	while ( $next ) {
		$page = btp_graph_get( $next );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		foreach ( (array) $page['value'] as $item ) {
			if ( isset( $item['file'] ) ) {
				$items[] = $item;
			}
		}
		$next = isset( $page['@odata.nextLink'] ) ? $page['@odata.nextLink'] : '';
	}
	return $items;
}

/**
 * Laddar ner en fil (driveItem) till $dest.
 */
function btp_graph_download( $item, $dest ) {
	$url = isset( $item['@microsoft.graph.downloadUrl'] ) ? $item['@microsoft.graph.downloadUrl'] : '';
	if ( '' === $url ) {
		$fresh = btp_graph_get( '/drives/' . rawurlencode( $item['parentReference']['driveId'] ) . '/items/' . rawurlencode( $item['id'] ) );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		$url = isset( $fresh['@microsoft.graph.downloadUrl'] ) ? $fresh['@microsoft.graph.downloadUrl'] : '';
	}
	if ( '' === $url ) {
		return new WP_Error( 'btp_ms', 'Fick ingen nedladdningslänk för ' . $item['name'] );
	}
	$response = wp_remote_get( $url, array( 'timeout' => 120, 'stream' => true, 'filename' => $dest ) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		wp_delete_file( $dest );
		return new WP_Error( 'btp_ms', 'Kunde inte ladda ner ' . $item['name'] );
	}
	return true;
}
