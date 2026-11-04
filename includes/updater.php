<?php
/**
 * Uppdateringar direkt från GitHub (publikt arkiv Damma65/bernerturf-produkter).
 *
 * Nya versioner läggs upp som "Release" med zip-filen bifogad. WordPress ser dem som vanliga
 * pluginuppdateringar och installerar dem automatiskt (bakgrundsuppdatering, ungefär två gånger per dygn).
 *
 * Stäng av automatiken: define( 'BTP_DISABLE_AUTO_UPDATE', true ); i wp-config.php – då visas
 * uppdateringen bara som vanligt under Tillägg och installeras för hand.
 * Stäng av uppdateringskollen helt: define( 'BTP_UPDATE_REPO', '' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_update_repo() {
	return defined( 'BTP_UPDATE_REPO' ) ? (string) BTP_UPDATE_REPO : 'Damma65/bernerturf-produkter';
}

function btp_plugin_basename() {
	return plugin_basename( BTP_DIR . 'bernerturf-produkter.php' );
}

/**
 * Senaste versionen på GitHub: array( version, package, notes, date, url ) eller null. Sparas i 12 timmar.
 */
function btp_update_info( $force = false ) {
	$repo = btp_update_repo();
	if ( '' === $repo ) {
		return null;
	}
	$cached = get_site_transient( 'btp_update_info' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached['version'] ? $cached : null;
	}
	$response = wp_remote_get(
		'https://api.github.com/repos/' . $repo . '/releases/latest',
		array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'bernerturf-produkter/' . BTP_VERSION ),
		)
	);
	$info = array( 'version' => '', 'package' => '', 'notes' => '', 'date' => '', 'url' => 'https://github.com/' . $repo );
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && empty( $body['draft'] ) && empty( $body['prerelease'] ) ) {
			foreach ( (array) $body['assets'] as $asset ) {
				if ( preg_match( '/^bernerturf-produkter.*\.zip$/', (string) $asset['name'] ) ) {
					$info['package'] = $asset['browser_download_url'];
				}
			}
			if ( $info['package'] && 0 === strpos( $info['package'], 'https://github.com/' . $repo . '/' ) ) {
				$info['version'] = ltrim( (string) $body['tag_name'], 'vV' );
				$info['notes']   = (string) $body['body'];
				$info['date']    = (string) $body['published_at'];
				$info['url']     = (string) $body['html_url'];
			}
		}
	}
	// Även misslyckade frågor sparas (kortare), så att GitHub inte frågas vid varje sidladdning.
	set_site_transient( 'btp_update_info', $info, $info['version'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $info['version'] ? $info : null;
}

/**
 * Talar om för WordPress att det finns en ny version.
 */
function btp_update_inject( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$info = btp_update_info();
	if ( ! $info ) {
		return $transient;
	}
	$item = (object) array(
		'id'            => btp_update_repo(),
		'slug'          => 'bernerturf-produkter',
		'plugin'        => btp_plugin_basename(),
		'new_version'   => $info['version'],
		'url'           => $info['url'],
		'package'       => $info['package'],
		'requires_php'  => '7.4',
		'icons'         => array(),
		'banners'       => array(),
		'compatibility' => new stdClass(),
	);
	if ( version_compare( $info['version'], BTP_VERSION, '>' ) ) {
		$transient->response[ btp_plugin_basename() ] = $item;
	} else {
		$item->new_version = BTP_VERSION;
		$transient->no_update[ btp_plugin_basename() ] = $item;
	}
	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'btp_update_inject' );

/**
 * Rutan "Visa detaljer" under Tillägg.
 */
function btp_update_details( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'bernerturf-produkter' !== $args->slug ) {
		return $result;
	}
	$info = btp_update_info();
	return (object) array(
		'name'          => 'Berner Turf Produkter',
		'slug'          => 'bernerturf-produkter',
		'version'       => $info ? $info['version'] : BTP_VERSION,
		'author'        => 'Berner Turf',
		'homepage'      => $info ? $info['url'] : '',
		'requires_php'  => '7.4',
		'last_updated'  => $info ? $info['date'] : '',
		'download_link' => $info ? $info['package'] : '',
		'sections'      => array(
			'description' => 'Egen produktkatalog och produktsida för bernerturf.com, med import från Excel/SharePoint och översättning.',
			'changelog'   => $info ? wpautop( esc_html( $info['notes'] ) ) : '',
		),
	);
}
add_filter( 'plugins_api', 'btp_update_details', 10, 3 );

/**
 * Automatiska uppdateringar på för just det här pluginet (om det inte är avstängt i wp-config.php).
 */
function btp_update_auto( $update, $item ) {
	if ( isset( $item->plugin ) && btp_plugin_basename() === $item->plugin ) {
		return ! ( defined( 'BTP_DISABLE_AUTO_UPDATE' ) && BTP_DISABLE_AUTO_UPDATE );
	}
	return $update;
}
add_filter( 'auto_update_plugin', 'btp_update_auto', 20, 2 );

/**
 * Efter en uppdatering: glöm den sparade versionsinfon.
 */
function btp_update_done() {
	delete_site_transient( 'btp_update_info' );
}
add_action( 'upgrader_process_complete', 'btp_update_done' );

/**
 * Knappen "Sök efter uppdatering nu" under Inställningar.
 */
function btp_update_handle_check() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Bara administratörer.' );
	}
	check_admin_referer( 'btp_update_check' );
	$info = btp_update_info( true );
	delete_site_transient( 'update_plugins' );
	if ( function_exists( 'wp_update_plugins' ) ) {
		wp_update_plugins();
	}
	if ( ! $info ) {
		$msg = 'Hittade ingen version på GitHub (' . btp_update_repo() . ').';
	} elseif ( version_compare( $info['version'], BTP_VERSION, '>' ) ) {
		$msg = 'Version ' . $info['version'] . ' finns. ' . ( ( defined( 'BTP_DISABLE_AUTO_UPDATE' ) && BTP_DISABLE_AUTO_UPDATE ) ? 'Den installeras under Tillägg.' : 'WordPress installerar den automatiskt inom några timmar.' );
	} else {
		$msg = 'Senaste versionen (' . BTP_VERSION . ') är installerad.';
	}
	wp_safe_redirect( btp_import_url( array( 'tab' => 'settings', 'btp_msg' => rawurlencode( $msg ) ) ) );
	exit;
}
add_action( 'admin_post_btp_update_check', 'btp_update_handle_check' );

function btp_update_box() {
	$info = btp_update_info();
	$auto = ! ( defined( 'BTP_DISABLE_AUTO_UPDATE' ) && BTP_DISABLE_AUTO_UPDATE );
	?>
	<div class="card-ish">
		<h2 style="margin-top:0">Version och uppdateringar</h2>
		<p>Installerad version: <b><?php echo esc_html( BTP_VERSION ); ?></b>.
			<?php if ( $info ) : ?>Senaste på GitHub: <b><?php echo esc_html( $info['version'] ); ?></b>.<?php endif; ?>
			Automatiska uppdateringar: <b><?php echo $auto ? 'på' : 'av (BTP_DISABLE_AUTO_UPDATE)'; ?></b>.</p>
		<p class="hint">Nya versioner hämtas från <a href="<?php echo esc_url( 'https://github.com/' . btp_update_repo() . '/releases' ); ?>" target="_blank" rel="noopener">github.com/<?php echo esc_html( btp_update_repo() ); ?></a> och installeras av WordPress själv. Innehåll (texter, bilder, synlighet) kräver aldrig en ny version.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'btp_update_check' ); ?>
			<input type="hidden" name="action" value="btp_update_check">
			<button class="button">Sök efter uppdatering nu</button>
		</form>
	</div>
	<?php
}
