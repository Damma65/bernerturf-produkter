<?php
/**
 * Automatisk import från SharePoint en gång per natt.
 *
 * 1. Kollar om Excel-filen eller bildmappen har ändrats sedan förra gången (annars händer inget).
 * 2. Hämtar filen och bara de bilder/datablad som är nya eller ändrade.
 * 3. Claude översätter det som behövs, i omgångar så att servern aldrig får för långa anrop.
 * 4. Genomför importen – utom om något ser fel ut (skyddsspärr), då väntar den på godkännande.
 * 5. Skickar en rapport via e-post när något har ändrats, väntar eller gått fel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'btp_auto_import', 'btp_auto_run' );
add_action( 'btp_auto_continue', 'btp_auto_continue' );

/**
 * Lägger nattkörningen i WordPress schema (eller tar bort den om automatiken är avstängd).
 */
function btp_auto_schedule() {
	wp_clear_scheduled_hook( 'btp_auto_import' );
	$s = btp_settings();
	if ( empty( $s['auto_on'] ) || ! btp_ms_ready() ) {
		return;
	}
	$time = preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $s['auto_time'], $m ) ? array( (int) $m[1], (int) $m[2] ) : array( 3, 0 );
	$tz   = wp_timezone();
	$next = new DateTime( 'now', $tz );
	$next->setTime( $time[0], $time[1] );
	if ( $next->getTimestamp() <= time() ) {
		$next->modify( '+1 day' );
	}
	wp_schedule_event( $next->getTimestamp(), 'daily', 'btp_auto_import' );
}

function btp_auto_status( $update = null ) {
	$status = get_option( 'btp_auto_status', array() );
	if ( is_array( $update ) ) {
		$status = array_merge( is_array( $status ) ? $status : array(), $update );
		update_option( 'btp_auto_status', $status, false );
	}
	return is_array( $status ) ? $status : array();
}

/**
 * Startar en körning. $force = kör även om inget har ändrats (knappen "Kör nu").
 */
function btp_auto_run( $force = false ) {
	$current = get_option( 'btp_auto_current' );
	if ( is_array( $current ) && ( time() - (int) $current['started'] ) < 2 * HOUR_IN_SECONDS ) {
		return 'En körning pågår redan.';
	}
	$c = btp_ms_config();
	if ( ! btp_ms_ready() ) {
		return btp_auto_fail( 'SharePoint-kopplingen är inte inställd.' );
	}
	$file = btp_graph_item( $c['file'] );
	if ( is_wp_error( $file ) ) {
		return btp_auto_fail( $file->get_error_message() );
	}
	if ( ! isset( $file['file'] ) || 'xlsx' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
		return btp_auto_fail( 'Länken pekar inte på en .xlsx-fil.' );
	}
	$children = array();
	if ( '' !== $c['folder'] ) {
		$folder = btp_graph_item( $c['folder'] );
		$list   = is_wp_error( $folder ) ? $folder : btp_graph_children( $folder );
		if ( is_wp_error( $list ) ) {
			return btp_auto_fail( 'Bildmappen: ' . $list->get_error_message() );
		}
		foreach ( $list as $item ) {
			if ( btp_import_classify( $item['name'] ) ) {
				$children[ $item['name'] ] = $item;
			}
		}
	}

	// Har något ändrats sedan förra lyckade körningen?
	$known = get_option( 'btp_auto_files', array() );
	$sig   = md5( $file['eTag'] . '|' . implode( ',', array_map( function ( $i ) {
		return $i['name'] . '=' . $i['eTag'];
	}, $children ) ) );
	$status = btp_auto_status();
	if ( ! $force && isset( $status['sig'] ) && $status['sig'] === $sig ) {
		btp_auto_status( array( 'last_run' => current_time( 'mysql' ), 'result' => 'same', 'message' => 'Inga ändringar i Excel-filen eller bildmappen.' ) );
		return 'Inga ändringar.';
	}

	$token = wp_generate_password( 20, false );
	$dir   = btp_import_dir( $token );
	$ok    = btp_graph_download( $file, $dir . '/source.xlsx' );
	if ( is_wp_error( $ok ) ) {
		btp_import_remove( $token );
		return btp_auto_fail( $ok->get_error_message() );
	}
	// Bara nya eller ändrade bilder och datablad hämtas.
	wp_mkdir_p( $dir . '/files' );
	$files = array();
	foreach ( $children as $name => $item ) {
		if ( ! $force && isset( $known[ $name ] ) && $known[ $name ] === $item['eTag'] ) {
			continue;
		}
		$safe = sanitize_file_name( $name );
		$dl   = btp_graph_download( $item, $dir . '/files/' . $safe );
		if ( ! is_wp_error( $dl ) ) {
			$files[ $safe ] = btp_import_classify( $safe ) + array( 'path' => $dir . '/files/' . $safe, 'name' => $safe );
		}
	}
	$data = btp_import_prepare( $token, $file['name'], $files, 'auto' );
	if ( is_wp_error( $data ) ) {
		btp_import_remove( $token );
		return btp_auto_fail( $data->get_error_message() );
	}
	update_option(
		'btp_auto_current',
		array(
			'token'   => $token,
			'started' => time(),
			'sig'     => $sig,
			'files'   => array_map( function ( $i ) {
				return $i['eTag'];
			}, $children ),
		),
		false
	);
	btp_auto_status( array( 'last_run' => current_time( 'mysql' ), 'result' => 'running', 'message' => 'Körning pågår …', 'token' => $token ) );
	return btp_auto_continue();
}

/**
 * Fortsätter en körning: översätter i högst ~40 sekunder per anrop, sedan genomförs importen.
 */
function btp_auto_continue() {
	$current = get_option( 'btp_auto_current' );
	if ( ! is_array( $current ) ) {
		return 'Ingen körning pågår.';
	}
	$token = $current['token'];
	$data  = btp_import_get( $token );
	if ( ! $data ) {
		delete_option( 'btp_auto_current' );
		return btp_auto_fail( 'Importdata saknas.' );
	}
	$start  = time();
	$errors = array();
	if ( btp_ai_ready() ) {
		foreach ( $data['rows'] as $i => $row ) {
			if ( empty( $row['needs'] ) ) {
				continue;
			}
			if ( time() - $start > 40 ) {
				wp_schedule_single_event( time() + 30, 'btp_auto_continue' );
				return 'Fortsätter om en stund.';
			}
			$r = btp_import_translate_row( $token, $i );
			if ( true !== $r ) {
				$errors[] = $r;
			}
		}
	}
	$data = btp_import_get( $token );

	// Skyddsspärr: ser filen trasig ut, eller ska många produkter plötsligt döljas, väntar importen på en människa.
	$status   = btp_auto_status();
	$rows     = count( $data['rows'] );
	$prev     = isset( $status['rows'] ) ? (int) $status['rows'] : 0;
	$new_hide = 0;
	foreach ( $data['rows'] as $row ) {
		if ( 'delete' === btp_import_action( $row ) ) {
			$new_hide++;
		}
	}
	$reason = '';
	if ( $prev && $rows < 0.7 * $prev ) {
		$reason = sprintf( 'Excel-filen har %d produkter mot %d förra gången.', $rows, $prev );
	} elseif ( $new_hide > max( 5, (int) round( 0.1 * $rows ) ) ) {
		$reason = sprintf( '%d produkter skulle döljas på en gång.', $new_hide );
	}
	delete_option( 'btp_auto_current' );
	if ( '' !== $reason ) {
		btp_auto_status( array( 'result' => 'held', 'message' => 'Väntar på godkännande: ' . $reason, 'token' => $token, 'pending' => array( 'sig' => $current['sig'], 'files' => $current['files'] ) ) );
		btp_auto_report( $data, 'held', $reason, $errors );
		return 'Väntar på godkännande.';
	}

	$data = btp_import_apply_token( $token, 'auto' );
	update_option( 'btp_auto_files', $current['files'], false );
	$changed = $data['count']['new'] + $data['count']['update'] + $data['count']['delete'];
	btp_auto_status(
		array(
			'result'  => 'done',
			'sig'     => $current['sig'],
			'rows'    => $rows,
			'token'   => $token,
			'message' => sprintf( 'Klart: %d nya, %d ändrade, %d dolda, %d oförändrade.', $data['count']['new'], $data['count']['update'], $data['count']['delete'], $data['count']['same'] ),
		)
	);
	if ( $changed || $errors || ! empty( $data['errors'] ) ) {
		btp_auto_report( $data, 'done', '', $errors );
	}
	return 'Klart.';
}

function btp_auto_fail( $message ) {
	btp_auto_status( array( 'last_run' => current_time( 'mysql' ), 'result' => 'error', 'message' => $message ) );
	btp_auto_report( null, 'error', $message, array() );
	return $message;
}

/**
 * Rapport via e-post till adresserna i inställningarna.
 */
function btp_auto_report( $data, $result, $reason, $errors ) {
	$to = array_filter( array_map( 'trim', explode( ',', (string) btp_settings()['report_to'] ) ), 'is_email' );
	if ( empty( $to ) ) {
		return;
	}
	$link  = btp_import_url( $data ? array( 'token' => $data['token'] ) : array( 'tab' => 'settings' ) );
	$lines = array();
	if ( 'error' === $result ) {
		$subject = 'Berner Turf produkter: nattens import misslyckades';
		$lines[] = 'Nattens import från SharePoint kunde inte genomföras:';
		$lines[] = $reason;
		$lines[] = '';
		$lines[] = 'Webben är oförändrad. Inställningar: ' . $link;
	} else {
		$warn = 0;
		$news = array();
		foreach ( $data['rows'] as $row ) {
			$warn += count( $row['warnings'] );
			if ( ! empty( $row['created'] ) || ( empty( $data['applied'] ) && ! $row['post_id'] ) ) {
				$news[] = $row['name'];
			}
		}
		$c = isset( $data['count'] ) ? $data['count'] : array( 'new' => count( $news ), 'update' => 0, 'delete' => 0 );
		if ( 'held' === $result ) {
			$subject = 'Berner Turf produkter: import väntar på godkännande';
			$lines[] = 'Nattens import har stoppats av säkerhetsskäl och väntar på att någon godkänner den:';
			$lines[] = $reason;
			$lines[] = '';
			$lines[] = 'Granska och godkänn (eller avbryt) här: ' . $link;
		} else {
			$subject = sprintf( 'Berner Turf produkter: %d nya, %d ändrade, %d dolda', $c['new'], $c['update'], $c['delete'] );
			$lines[] = 'Nattens import från SharePoint är klar och syns nu på webben.';
			$lines[] = sprintf( '%d nya, %d ändrade, %d dolda (Deleted).', $c['new'], $c['update'], $c['delete'] );
			if ( $news ) {
				$lines[] = 'Nya: ' . implode( ', ', $news ) . ' (visas när de har en bild).';
			}
			$lines[] = '';
			$lines[] = 'Detaljer och ångra: ' . $link;
		}
		if ( $warn ) {
			$lines[] = $warn . ' varningar (t.ex. saknad bild eller säkerhetsdatablad) – se länken.';
		}
	}
	foreach ( array_merge( (array) $errors, $data && ! empty( $data['errors'] ) ? $data['errors'] : array() ) as $e ) {
		$lines[] = 'Fel: ' . $e;
	}
	wp_mail( $to, $subject, implode( "\n", $lines ) );
}

/**
 * Knapparna "Testa anslutning" och "Kör nu" under Inställningar.
 */
function btp_auto_handle_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Bara administratörer.' );
	}
	check_admin_referer( 'btp_auto_action' );
	$what = isset( $_POST['what'] ) ? sanitize_key( $_POST['what'] ) : '';
	if ( 'test' === $what ) {
		delete_transient( 'btp_graph_token' );
		$c    = btp_ms_config();
		$file = btp_graph_item( $c['file'] );
		if ( is_wp_error( $file ) ) {
			$msg = 'Testet misslyckades: ' . $file->get_error_message();
		} else {
			$msg = 'Anslutningen fungerar. Hittade "' . $file['name'] . '" (ändrad ' . substr( (string) $file['lastModifiedDateTime'], 0, 10 ) . ').';
			if ( '' !== $c['folder'] ) {
				$folder = btp_graph_item( $c['folder'] );
				$kids   = is_wp_error( $folder ) ? $folder : btp_graph_children( $folder );
				$msg   .= is_wp_error( $kids ) ? ' Bildmappen: ' . $kids->get_error_message() : ' Bildmappen innehåller ' . count( $kids ) . ' filer.';
			}
		}
	} else {
		$msg = 'Kör nu: ' . btp_auto_run( true );
	}
	wp_safe_redirect( btp_import_url( array( 'tab' => 'settings', 'btp_msg' => rawurlencode( $msg ) ) ) );
	exit;
}
add_action( 'admin_post_btp_auto_action', 'btp_auto_handle_action' );
