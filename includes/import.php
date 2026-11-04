<?php
/**
 * Produkter > Importera från Excel.
 *
 * Flödet: ladda upp Excel-filen (+ ev. bilder och säkerhetsdatablad) → förhandsvisning där
 * Claude översätter tomma språkceller från finskan → Godkänn → klart. Varje import sparas i
 * loggen och kan ångras. Excel-filen är modern: det som står där gäller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BTP_IMPORT_CAP   = 'edit_others_posts';
const BTP_IMPORT_LANGS = array( 'fi', 'sv', 'en', 'no' );

/* -------------------------------------------------------------------------
 * Meny
 * ---------------------------------------------------------------------- */

function btp_import_menu() {
	add_submenu_page( 'edit.php?post_type=berner-products', 'Importera från Excel', 'Importera från Excel', BTP_IMPORT_CAP, 'btp-import', 'btp_import_page' );
}
add_action( 'admin_menu', 'btp_import_menu' );

function btp_import_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'edit.php?post_type=berner-products&page=btp-import' ) );
}

/* -------------------------------------------------------------------------
 * Arbetsmapp för en import (skyddad mot direktåtkomst)
 * ---------------------------------------------------------------------- */

function btp_import_dir( $token = '' ) {
	$base = trailingslashit( wp_upload_dir()['basedir'] ) . 'btp-import';
	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" );
		file_put_contents( $base . '/index.php', "<?php // Tyst.\n" );
		// phpcs:enable
	}
	if ( '' === $token ) {
		return $base;
	}
	$dir = $base . '/' . preg_replace( '/[^A-Za-z0-9]/', '', $token );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

function btp_import_get( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	$data  = '' === $token ? false : get_option( 'btp_imp_' . $token );
	return is_array( $data ) ? $data : null;
}

function btp_import_put( $token, $data ) {
	update_option( 'btp_imp_' . $token, $data, false );
}

/* -------------------------------------------------------------------------
 * Kolumner
 * ---------------------------------------------------------------------- */

function btp_import_field_keys() {
	$keys = array( 'id', 'webb', 'status', 'kat1', 'kat2', 'kat3', 'leverantor', 'certifikat', 'kommentar', 'namn', 'bild' );
	foreach ( BTP_IMPORT_LANGS as $l ) {
		foreach ( array( 'namn', 'ingress', 'beskrivning', 'punkter', 'anvandning', 'forpackning', 'sds', 'naring' ) as $f ) {
			$keys[] = $l . '_' . $f;
		}
	}
	return $keys;
}

/**
 * Hittar kolumnerna. 1) En (gärna dold) rad med fasta nycklar (id, webb, fi_ingress …).
 * 2) Annars rubrikerna i Sailas fil (Status, Product name FI, Headline description FI, Tuotekuvaus …).
 * Ger array( 'map' => nyckel => kolumn, 'start' => första dataraden ) eller WP_Error.
 */
function btp_import_columns( $rows ) {
	$known  = array_flip( btp_import_field_keys() );
	$keymap = array();
	$header = 0;
	$guess  = array();
	foreach ( $rows as $r => $cells ) {
		if ( $r > 12 ) {
			break;
		}
		$hits = array();
		foreach ( $cells as $col => $v ) {
			$k = strtolower( trim( $v ) );
			if ( isset( $known[ $k ] ) ) {
				$hits[ $k ] = $col;
			}
		}
		$joined = mb_strtolower( implode( ' | ', $cells ), 'UTF-8' );
		$is_hdr = false !== strpos( $joined, 'product name' ) || false !== strpos( $joined, 'tuotteen nimi' ) || false !== strpos( $joined, 'produktnamn' );
		if ( count( $hits ) >= 5 ) {
			$keymap = $hits;
			$header = max( $header, $r );
		} elseif ( $is_hdr ) {
			$header = max( $header, $r );
			$guess  = btp_import_guess_columns( $cells );
		}
	}
	$map = $keymap ? $keymap : $guess;
	if ( ! isset( $map['namn'] ) && ! isset( $map['fi_namn'] ) && ! isset( $map['en_namn'] ) && ! isset( $map['sv_namn'] ) ) {
		return new WP_Error( 'btp_cols', 'Hittade ingen kolumn för produktnamn. Finns rubrikraden kvar (t.ex. "Product name FI")?' );
	}
	return array( 'map' => $map, 'start' => $header + 1 );
}

function btp_import_guess_columns( $cells ) {
	$map  = array();
	$lang = 'fi';
	ksort( $cells );
	foreach ( $cells as $col => $raw ) {
		$h = mb_strtolower( trim( preg_replace( '/\s+/', ' ', $raw ) ), 'UTF-8' );
		if ( '' === $h ) {
			continue;
		}
		$suffix = '';
		if ( preg_match( '/\b(fi|se|sv|en|no|nb)\s*$/u', $h, $m ) ) {
			$suffix = array( 'fi' => 'fi', 'se' => 'sv', 'sv' => 'sv', 'en' => 'en', 'no' => 'no', 'nb' => 'no' )[ $m[1] ];
		}
		$key = '';
		if ( 'id' === $h ) {
			$key = 'id';
		} elseif ( preg_match( '/^(webb|web|visible|näkyvyys|visas på)/u', $h ) ) {
			$key = 'webb';
		} elseif ( 'status' === $h ) {
			$key = 'status';
		} elseif ( preg_match( '/supplier|toimittaja|leverantör|leverandør/u', $h ) ) {
			$key = 'leverantor';
		} elseif ( preg_match( '/primääri|primary/u', $h ) ) {
			$key = 'kat1';
		} elseif ( false !== strpos( $h, 'välikategoria' ) ) {
			$key = 'kat2';
		} elseif ( preg_match( '/ala-kategoria|sub-category|subcategory/u', $h ) ) {
			$key = 'kat3';
		} elseif ( false !== strpos( $h, 'certificate' ) || false !== strpos( $h, 'sertifikaat' ) ) {
			$key = 'certifikat';
		} elseif ( preg_match( '/^(comment|kommentti|kommentar)/u', $h ) ) {
			$key = 'kommentar';
		} elseif ( preg_match( '/^(bild|image|kuva|produktbild|product image)(\s|$)/u', $h ) ) {
			$key = 'bild';
		} elseif ( preg_match( '/product name|tuotteen nimi|produktnamn|produktnavn/u', $h ) ) {
			$lang = $suffix ? $suffix : $lang;
			$key  = $lang . '_namn';
		} else {
			$field = '';
			if ( preg_match( '/headline|ingress/u', $h ) ) {
				$field = 'ingress';
			} elseif ( preg_match( '/tuotekuvaus|produktbeskrivning|product description|produktbeskrivelse/u', $h ) ) {
				$field = 'punkter';
			} elseif ( preg_match( '/käyttöohje|bruksa?n?s?anvisning|bruksanvisning|directions|bruksveiledning/u', $h ) ) {
				$field = 'anvandning';
			} elseif ( preg_match( '/pakkauskoko|förpackning|package|emballasje|pakning/u', $h ) ) {
				$field = 'forpackning';
			} elseif ( preg_match( '/käyttöturvallisuus|säkerhetsdatablad|safety|sikkerhetsdatablad|sds/u', $h ) ) {
				$field = 'sds';
			} elseif ( preg_match( '/näring|ravinte|nutrient|næring/u', $h ) ) {
				$field = 'naring';
			}
			if ( $field ) {
				$key = ( $suffix ? $suffix : $lang ) . '_' . $field;
			}
		}
		if ( $key && ! isset( $map[ $key ] ) ) {
			$map[ $key ] = $col;
		}
	}
	return $map;
}

/* -------------------------------------------------------------------------
 * Filer: bilder och säkerhetsdatablad, matchade på filnamn
 * ---------------------------------------------------------------------- */

/**
 * "GreenCare 8.jpg" => array( 'slug' => 'greencare-8', 'type' => 'image' )
 * "GreenCare 8 SDS SV.pdf" => array( 'slug' => 'greencare-8', 'type' => 'sds', 'lang' => 'sv' )
 */
function btp_import_classify( $filename ) {
	$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$slug = btp_slug( pathinfo( $filename, PATHINFO_FILENAME ) );
	if ( preg_match( '/^(.*)-(sds|kttt|ktt|msds|sdb)-(sv|se|fi|en|no|nb)$/', $slug, $m ) ) {
		$lang = array( 'sv' => 'sv', 'se' => 'sv', 'fi' => 'fi', 'en' => 'en', 'no' => 'no', 'nb' => 'no' )[ $m[3] ];
		return 'pdf' === $ext ? array( 'slug' => $m[1], 'type' => 'sds', 'lang' => $lang ) : null;
	}
	if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
		return array( 'slug' => $slug, 'type' => 'image' );
	}
	return null;
}

/**
 * Tar emot uppladdade filer (även .zip med filer i) till importmappen. Ger lista med filinfo.
 */
function btp_import_receive_files( $dir ) {
	$out = array();
	if ( empty( $_FILES['btp_files'] ) || ! is_array( $_FILES['btp_files']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce kontrolleras i anroparen.
		return $out;
	}
	$files = $_FILES['btp_files']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	wp_mkdir_p( $dir . '/files' );
	foreach ( $files['name'] as $i => $name ) {
		if ( UPLOAD_ERR_OK !== (int) $files['error'][ $i ] || ! is_uploaded_file( $files['tmp_name'][ $i ] ) ) {
			continue;
		}
		$name = sanitize_file_name( wp_basename( $name ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'zip' === $ext && class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $files['tmp_name'][ $i ] ) ) {
				for ( $z = 0; $z < $zip->numFiles; $z++ ) {
					$entry = $zip->getNameIndex( $z );
					$base  = sanitize_file_name( wp_basename( $entry ) );
					if ( '' === $base || 0 === strpos( $base, '.' ) || false !== strpos( $entry, '__MACOSX' ) || ! btp_import_classify( $base ) ) {
						continue;
					}
					$data = $zip->getFromIndex( $z );
					if ( false !== $data ) {
						file_put_contents( $dir . '/files/' . $base, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						$out[ $base ] = btp_import_classify( $base ) + array( 'path' => $dir . '/files/' . $base, 'name' => $base );
					}
				}
				$zip->close();
			}
			continue;
		}
		$info = btp_import_classify( $name );
		if ( ! $info ) {
			continue;
		}
		$type = wp_check_filetype_and_ext( $files['tmp_name'][ $i ], $name );
		if ( empty( $type['type'] ) ) {
			continue;
		}
		if ( move_uploaded_file( $files['tmp_name'][ $i ], $dir . '/files/' . $name ) ) {
			$out[ $name ] = $info + array( 'path' => $dir . '/files/' . $name, 'name' => $name );
		}
	}
	return $out;
}

/**
 * Bilder och PDF:er som redan ligger i mediabiblioteket, per filnamns-slug.
 */
function btp_import_media_index() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows  = $wpdb->get_results( "SELECT ID, post_title, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ( post_mime_type LIKE 'image/%' OR post_mime_type = 'application/pdf' ) ORDER BY ID DESC" );
	$index = array();
	foreach ( (array) $rows as $row ) {
		$info = btp_import_classify( $row->post_title . ( 'application/pdf' === $row->post_mime_type ? '.pdf' : '.jpg' ) );
		if ( ! $info ) {
			continue;
		}
		$key = 'sds' === $info['type'] ? $info['slug'] . '|sds|' . $info['lang'] : $info['slug'] . '|image';
		if ( ! isset( $index[ $key ] ) ) {
			$index[ $key ] = (int) $row->ID;
		}
	}
	return $index;
}

/* -------------------------------------------------------------------------
 * Plan: vad importen kommer att göra med varje rad
 * ---------------------------------------------------------------------- */

function btp_import_existing_index() {
	$posts = get_posts(
		array(
			'post_type'        => 'berner-products',
			'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
			'post_parent'      => 0,
			'numberposts'      => -1,
			'suppress_filters' => true,
		)
	);
	$index = array();
	foreach ( $posts as $p ) {
		$slug = btp_slug( html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ) );
		if ( ! isset( $index[ $slug ] ) ) {
			$index[ $slug ] = $p->ID;
		}
	}
	return $index;
}

function btp_import_is_url( $v ) {
	return (bool) preg_match( '#https?://\S+#i', (string) $v );
}

/**
 * Säkerhetsdatablad i en cell: en länk per rad, gärna med text före: "Säkerhetsdatablad (FW) | https://…".
 */
function btp_import_clean_sds( $v ) {
	$out = array();
	foreach ( btp_lines( $v ) as $line ) {
		if ( preg_match( '#^(.*?)\s*\|?\s*(https?://\S+)\s*$#u', $line, $m ) ) {
			$label = trim( $m[1], " |\t" );
			$out[] = ( '' !== $label ? $label . ' | ' : '' ) . esc_url_raw( $m[2] );
		}
	}
	return implode( "\n", $out );
}

/**
 * Var bilden kommer ifrån, för att se om den är ny. Uppladdade filer jämförs på innehåll,
 * så en ny bild med samma filnamn ("GreenCare 8.jpg") byter bilden.
 */
function btp_import_image_src( $image ) {
	if ( isset( $image['url'] ) ) {
		return $image['url'];
	}
	if ( isset( $image['file'] ) ) {
		return 'file:' . ( isset( $image['hash'] ) ? $image['hash'] : $image['file'] );
	}
	return 'media:' . $image['att'];
}

function btp_import_text_fields() {
	return array( 'ingress', 'beskrivning', 'punkter', 'anvandning', 'forpackning', 'naring' );
}

function btp_import_build_plan( $parsed, $files ) {
	$map      = $parsed['map'];
	$existing = btp_import_existing_index();
	$media    = btp_import_media_index();
	$plan     = array();
	$seen     = array();
	$names    = array();

	foreach ( $parsed['rows'] as $r => $cells ) {
		if ( $r < $parsed['start'] ) {
			continue;
		}
		$get = function ( $key ) use ( $map, $cells ) {
			return isset( $map[ $key ], $cells[ $map[ $key ] ] ) ? trim( $cells[ $map[ $key ] ] ) : '';
		};
		// Namn: översätts aldrig. Finns olika namn per språk i Excel används de; produktens
		// huvudnamn (titeln i WordPress) är det engelska, som på sajten i dag.
		$lnames = array();
		foreach ( BTP_IMPORT_LANGS as $l ) {
			$v = preg_replace( '/\s+/', ' ', $get( $l . '_namn' ) );
			if ( '' !== $v ) {
				$lnames[ $l ] = $v;
			}
		}
		$name = preg_replace( '/\s+/', ' ', $get( 'namn' ) );
		foreach ( array( 'en', 'sv', 'fi', 'no' ) as $l ) {
			if ( '' === $name && isset( $lnames[ $l ] ) ) {
				$name = $lnames[ $l ];
			}
		}
		if ( '' === $name ) {
			continue;
		}
		$slug  = btp_slug( $name );
		$slugs = array_values( array_unique( array_map( 'btp_slug', array_merge( array( $name ), array_values( $lnames ) ) ) ) );
		$row  = array(
			'row'       => $r,
			'name'      => $name,
			'names'     => $lnames,
			'slug'      => $slug,
			'slugs'     => $slugs,
			'post_id'   => 0,
			'status'    => $get( 'status' ),
			'supplier'  => $get( 'leverantor' ),
			'warnings'  => array(),
			'texts'     => array(),
			'tr'        => array(),
			'needs'     => array(),
			'source'    => 'fi',
			'sds'       => array(),
			'image'     => null,
			'had_id'    => '' !== $get( 'id' ),
		);

		// Vilken produkt på sajten.
		$id = absint( $get( 'id' ) );
		if ( $id && 'berner-products' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			$row['post_id'] = $id;
		} else {
			foreach ( $slugs as $sl ) {
				if ( isset( $existing[ $sl ] ) ) {
					$row['post_id'] = $existing[ $sl ];
					break;
				}
			}
		}
		if ( isset( $seen[ $slug ] ) ) {
			$row['warnings'][] = 'Finns två gånger i Excel (rad ' . $seen[ $slug ] . ' och ' . $r . '). Den sista raden gäller.';
		}
		$seen[ $slug ] = $r;
		$names[]       = $name;

		// Synlighet och godkända länder.
		$cert = btp_parse_markets( $get( 'certifikat' ) );
		$webb = btp_parse_markets( $get( 'webb' ) );
		if ( preg_match( '/delet|poist|ta bort|borttagen/i', $row['status'] ) ) {
			$webb = array( 'DELETED' );
		} elseif ( empty( $webb ) ) {
			$webb = ( empty( $cert ) || array( 'DELETED' ) === $cert ) ? array( 'ALL' ) : $cert;
		}
		$row['webb']    = implode( ' ', $webb );
		$row['godkand'] = ( empty( $cert ) || array( 'DELETED' ) === $cert ) ? '' : implode( ' ', $cert );

		// Kategori.
		$row['kat'] = '';
		foreach ( array( 'kat3', 'kat2', 'kat1' ) as $k ) {
			$key = btp_category_key( $get( $k ) );
			if ( $key ) {
				$row['kat'] = $key;
				break;
			}
		}

		// Texter per språk.
		foreach ( BTP_IMPORT_LANGS as $l ) {
			foreach ( btp_import_text_fields() as $f ) {
				$v = $get( $l . '_' . $f );
				if ( '' !== $v ) {
					$row['texts'][ $l ][ $f ] = $v;
				}
			}
		}
		foreach ( array( 'fi', 'en', 'sv' ) as $src ) {
			if ( ! empty( $row['texts'][ $src ] ) ) {
				$row['source'] = $src;
				break;
			}
		}

		// Det som saknas och ska översättas (svenska, engelska och norska).
		$src_texts = isset( $row['texts'][ $row['source'] ] ) ? $row['texts'][ $row['source'] ] : array();
		foreach ( array( 'sv', 'en', 'no' ) as $l ) {
			if ( $l === $row['source'] ) {
				continue;
			}
			foreach ( $src_texts as $f => $src_value ) {
				if ( isset( $row['texts'][ $l ][ $f ] ) ) {
					// Står Claudes egen översättning kvar oförändrad i cellen (t.ex. från den ifyllda
					// Excel-filen) räknas den fortfarande som automatisk och följer med när finskan ändras.
					// Har någon skrivit om texten är den handskriven och låst – den ändras aldrig automatiskt.
					// Jämför mot alla Claude-texter fältet har haft, så att en äldre ifylld fil inte låser fältet.
					$hist  = $row['post_id'] ? (array) get_post_meta( $row['post_id'], 'btp_' . $f . '_' . $l . '_aihist', true ) : array();
					$is_ai = $row['post_id'] && in_array( md5( $row['texts'][ $l ][ $f ] ), $hist, true );
					if ( ! $is_ai ) {
						continue;
					}
					unset( $row['texts'][ $l ][ $f ] );
				}
				// Samma källtext som förra gången => återanvänd översättningen, ingen ny kostnad.
				if ( $row['post_id'] ) {
					$old     = (string) get_post_meta( $row['post_id'], 'btp_' . $f . '_' . $l, true );
					$old_src = (string) get_post_meta( $row['post_id'], 'btp_' . $f . '_' . $l . '_src', true );
					if ( '' !== $old && md5( $src_value ) === $old_src ) {
						$row['tr'][ $l ][ $f ] = $old;
						continue;
					}
				}
				$row['needs'][ $l ][] = $f;
			}
		}

		// Säkerhetsdatablad: länk i Excel > uppladdad fil > fil i mediabiblioteket > det som redan finns.
		foreach ( BTP_IMPORT_LANGS as $l ) {
			$v = $get( $l . '_sds' );
			if ( btp_import_is_url( $v ) ) {
				$row['sds'][ $l ] = array( 'url' => $v );
				continue;
			}
			foreach ( $files as $file ) {
				if ( 'sds' === $file['type'] && in_array( $file['slug'], $slugs, true ) && $file['lang'] === $l ) {
					$row['sds'][ $l ] = array( 'file' => $file['name'] );
				}
			}
			foreach ( $slugs as $sl ) {
				if ( ! isset( $row['sds'][ $l ] ) && isset( $media[ $sl . '|sds|' . $l ] ) ) {
					$row['sds'][ $l ] = array( 'att' => $media[ $sl . '|sds|' . $l ] );
				}
			}
		}

		// Bild: länk i Excel > uppladdad fil > mediabiblioteket > den produkten redan har.
		$bild = $get( 'bild' );
		if ( btp_import_is_url( $bild ) ) {
			$row['image'] = array( 'url' => $bild );
		} else {
			foreach ( $files as $file ) {
				if ( 'image' === $file['type'] && in_array( $file['slug'], $slugs, true ) ) {
					$row['image'] = array( 'file' => $file['name'], 'hash' => md5_file( $file['path'] ) );
				}
			}
			if ( ! $row['image'] && '' !== $bild ) {
				$bslug = btp_slug( pathinfo( $bild, PATHINFO_FILENAME ) );
				if ( isset( $media[ $bslug . '|image' ] ) ) {
					$row['image'] = array( 'att' => $media[ $bslug . '|image' ] );
				}
			}
			foreach ( $slugs as $sl ) {
				if ( ! $row['image'] && isset( $media[ $sl . '|image' ] ) && ( ! $row['post_id'] || ! btp_image_for( $row['post_id'] ) ) ) {
					$row['image'] = array( 'att' => $media[ $sl . '|image' ] );
				}
			}
		}
		$has_image = $row['image'] || ( $row['post_id'] && btp_image_for( $row['post_id'] ) );
		$deleted   = 'DELETED' === $row['webb'];
		if ( ! $has_image && ! $deleted ) {
			$row['warnings'][] = $row['post_id'] ? 'Saknar bild.' : 'Saknar bild – visas inte förrän den har en bild.';
		}
		if ( ! $deleted ) {
			$shows_se = 'ALL' === $row['webb'] || false !== strpos( $row['webb'], 'SE' );
			$theme_sds = $row['post_id'] ? btp_sds_links( btp_sections_for( $row['post_id'] )['sds'] ) : array();
			if ( $shows_se && empty( $row['sds']['sv'] ) && empty( $theme_sds ) && ( ! $row['post_id'] || '' === (string) get_post_meta( $row['post_id'], 'btp_sds_sv', true ) ) ) {
				$row['warnings'][] = 'Saknar svenskt säkerhetsdatablad.';
			}
			if ( empty( $row['texts']['fi']['naring'] ) && empty( $row['texts']['sv']['naring'] ) && empty( $row['texts']['en']['naring'] ) ) {
				$row['notes'][] = 'Inga näringsämnen.';
			}
			if ( '' === $row['kat'] ) {
				$row['notes'][] = 'Ingen kategori.';
			}
			if ( '' !== $row['status'] && ! preg_match( '/^(ready|valmis|done|klar|.*\bready)$/i', trim( $row['status'] ) ) ) {
				$row['notes'][] = 'Status i Excel: ' . $row['status'];
			}
		}
		$plan[ $r ] = $row;
	}

	// Samma produkt två gånger: sista raden gäller.
	$last = array();
	foreach ( $plan as $r => $row ) {
		$last[ $row['slug'] ] = $r;
	}
	foreach ( $plan as $r => $row ) {
		if ( $last[ $row['slug'] ] !== $r ) {
			unset( $plan[ $r ] );
		}
	}
	// Produkter som finns på webben men inte i Excel: rörs inte, men visas i förhandsvisningen.
	$in_excel = array_filter( wp_list_pluck( $plan, 'post_id' ) );
	$orphans  = array();
	foreach ( array_unique( array_values( $existing ) ) as $pid ) {
		if ( ! in_array( $pid, $in_excel, true ) && 'publish' === get_post_status( $pid ) && 'DELETED' !== implode( '', btp_visibility( $pid ) ) ) {
			$orphans[] = html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' );
		}
	}
	sort( $orphans );
	return array( 'rows' => array_values( $plan ), 'names' => array_values( array_unique( $names ) ), 'orphans' => $orphans );
}

/**
 * Slutliga värden för en rad: Excel-text, annars Claudes översättning.
 */
function btp_import_final_texts( $row ) {
	$out = array();
	foreach ( BTP_IMPORT_LANGS as $l ) {
		foreach ( btp_import_text_fields() as $f ) {
			if ( isset( $row['texts'][ $l ][ $f ] ) ) {
				$out[ $l ][ $f ] = array( 'v' => $row['texts'][ $l ][ $f ], 'ai' => false );
			} elseif ( isset( $row['tr'][ $l ][ $f ] ) ) {
				$out[ $l ][ $f ] = array( 'v' => $row['tr'][ $l ][ $f ], 'ai' => true );
			}
		}
	}
	return $out;
}

/**
 * Vad som ändras för en befintlig produkt, i klartext.
 */
function btp_import_changes( $row ) {
	if ( ! $row['post_id'] ) {
		return array( 'Ny produkt' );
	}
	$id      = $row['post_id'];
	$changes = array();
	if ( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) !== $row['name'] ) {
		$changes[] = 'Namn';
	}
	foreach ( BTP_IMPORT_LANGS as $l ) {
		if ( (string) get_post_meta( $id, 'btp_namn_' . $l, true ) !== ( isset( $row['names'][ $l ] ) ? $row['names'][ $l ] : '' ) ) {
			$changes[] = 'Namn per språk';
			break;
		}
	}
	if ( (string) get_post_meta( $id, 'btp_webb', true ) !== $row['webb'] ) {
		$changes[] = 'Synlighet';
	}
	if ( (string) get_post_meta( $id, 'btp_godkand', true ) !== $row['godkand'] ) {
		$changes[] = 'Godkänd i';
	}
	if ( (string) get_post_meta( $id, 'btp_kat', true ) !== $row['kat'] ) {
		$changes[] = 'Kategori';
	}
	$final  = btp_import_final_texts( $row );
	$labels = btp_text_fields();
	$langs  = btp_languages();
	foreach ( BTP_IMPORT_LANGS as $l ) {
		$changed = array();
		foreach ( btp_import_text_fields() as $f ) {
			$new = isset( $final[ $l ][ $f ] ) ? $final[ $l ][ $f ]['v'] : '';
			if ( (string) get_post_meta( $id, 'btp_' . $f . '_' . $l, true ) !== $new ) {
				$changed[] = mb_strtolower( $labels[ $f ], 'UTF-8' );
			}
		}
		if ( $changed ) {
			$changes[] = $langs[ $l ] . ': ' . implode( ', ', $changed );
		}
	}
	if ( $row['image'] ) {
		$src = btp_import_image_src( $row['image'] );
		if ( (string) get_post_meta( $id, 'btp_bild_src', true ) !== $src ) {
			$changes[] = 'Bild';
		}
	}
	foreach ( $row['sds'] as $l => $s ) {
		if ( isset( $s['file'] ) || ( isset( $s['url'] ) && get_post_meta( $id, 'btp_sds_' . $l, true ) !== btp_import_clean_sds( $s['url'] ) ) || ( isset( $s['att'] ) && (int) get_post_meta( $id, 'btp_sds_' . $l . '_att', true ) !== (int) $s['att'] ) ) {
			$changes[] = 'Säkerhetsdatablad ' . strtoupper( $l );
		}
	}
	return $changes;
}

function btp_import_action( $row ) {
	if ( 'DELETED' === $row['webb'] ) {
		if ( ! $row['post_id'] ) {
			return 'skip';
		}
		return 'DELETED' === (string) get_post_meta( $row['post_id'], 'btp_webb', true ) && empty( $row['created'] ) ? 'same' : 'delete';
	}
	if ( ! $row['post_id'] ) {
		return 'new';
	}
	return btp_import_changes( $row ) ? 'update' : 'same';
}

/* -------------------------------------------------------------------------
 * Steg 1: uppladdning
 * ---------------------------------------------------------------------- */

function btp_import_handle_upload() {
	if ( ! current_user_can( BTP_IMPORT_CAP ) ) {
		wp_die( 'Du har inte behörighet.' );
	}
	check_admin_referer( 'btp_import_upload' );
	if ( empty( $_FILES['btp_excel']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['btp_excel']['error'] ) {
		wp_safe_redirect( btp_import_url( array( 'btp_err' => rawurlencode( 'Välj Excel-filen (.xlsx).' ) ) ) );
		exit;
	}
	$name = sanitize_file_name( wp_basename( wp_unslash( $_FILES['btp_excel']['name'] ) ) );
	if ( 'xlsx' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
		wp_safe_redirect( btp_import_url( array( 'btp_err' => rawurlencode( 'Excel-filen måste vara .xlsx.' ) ) ) );
		exit;
	}
	$token = wp_generate_password( 20, false );
	$dir   = btp_import_dir( $token );
	move_uploaded_file( $_FILES['btp_excel']['tmp_name'], $dir . '/source.xlsx' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$files = btp_import_receive_files( $dir );
	$data  = btp_import_prepare( $token, $name, $files, 'manual' );
	if ( is_wp_error( $data ) ) {
		btp_import_remove( $token );
		wp_safe_redirect( btp_import_url( array( 'btp_err' => rawurlencode( $data->get_error_message() ) ) ) );
		exit;
	}
	wp_safe_redirect( btp_import_url( array( 'token' => $token ) ) );
	exit;
}

/**
 * Läser source.xlsx i importmappen och bygger planen. $files = filer i mappen files/.
 */
function btp_import_prepare( $token, $excel_name, $files, $source = 'manual' ) {
	$dir  = btp_import_dir( $token );
	$read = btp_xlsx_read( $dir . '/source.xlsx' );
	if ( is_wp_error( $read ) ) {
		return $read;
	}
	$cols = btp_import_columns( $read['rows'] );
	if ( is_wp_error( $cols ) ) {
		return $cols;
	}
	$plan = btp_import_build_plan( array( 'rows' => $read['rows'], 'map' => $cols['map'], 'start' => $cols['start'] ), $files );
	$data = array(
		'token'   => $token,
		'created' => current_time( 'mysql' ),
		'user'    => 'auto' === $source ? 0 : get_current_user_id(),
		'source'  => $source,
		'excel'   => $excel_name,
		'sheet'   => $read['sheet'],
		'map'     => $cols['map'],
		'files'   => $files,
		'rows'    => $plan['rows'],
		'names'   => $plan['names'],
		'orphans' => $plan['orphans'],
		'applied' => false,
	);
	btp_import_put( $token, $data );
	return $data;
}
add_action( 'admin_post_btp_import_upload', 'btp_import_handle_upload' );

/* -------------------------------------------------------------------------
 * Steg 2: översättning (en produkt per anrop, körs från förhandsvisningen)
 * ---------------------------------------------------------------------- */

/**
 * Översätter en rad i en import. Ger true eller ett felmeddelande.
 */
function btp_import_translate_row( $token, $i ) {
	$data = btp_import_get( $token );
	if ( ! $data || ! isset( $data['rows'][ $i ] ) ) {
		return 'Importen hittades inte.';
	}
	$row = $data['rows'][ $i ];
	if ( empty( $row['needs'] ) ) {
		return true;
	}
	$fields = array();
	foreach ( $row['needs'] as $l => $list ) {
		foreach ( $list as $f ) {
			$fields[ $f ] = $row['texts'][ $row['source'] ][ $f ];
		}
	}
	$protect = array_merge( array( $row['name'], $row['supplier'], 'Berner', 'Berner Turf' ), array_values( $row['names'] ), wp_list_pluck( btp_suppliers(), 'name' ) );
	$result  = btp_ai_translate( $fields, array_keys( $row['needs'] ), $protect, $row['source'] );
	if ( is_wp_error( $result ) ) {
		$row['tr_error'] = $result->get_error_message();
	} else {
		foreach ( $row['needs'] as $l => $list ) {
			foreach ( $list as $f ) {
				if ( isset( $result[ $l ][ $f ] ) ) {
					$row['tr'][ $l ][ $f ] = $result[ $l ][ $f ];
				}
			}
		}
		$row['tr_error'] = '';
	}
	$row['needs']       = array();
	$data['rows'][ $i ] = $row;
	btp_import_put( $token, $data );
	return '' === $row['tr_error'] ? true : $row['name'] . ': ' . $row['tr_error'];
}

function btp_import_ajax_translate() {
	if ( ! current_user_can( BTP_IMPORT_CAP ) ) {
		wp_send_json_error( 'Ingen behörighet.' );
	}
	check_ajax_referer( 'btp_import_translate' );
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$i     = isset( $_POST['i'] ) ? absint( $_POST['i'] ) : 0;
	$r     = btp_import_translate_row( $token, $i );
	if ( true !== $r ) {
		wp_send_json_error( $r );
	}
	wp_send_json_success( array( 'done' => true ) );
}
add_action( 'wp_ajax_btp_import_translate', 'btp_import_ajax_translate' );

/* -------------------------------------------------------------------------
 * Steg 3: Godkänn
 * ---------------------------------------------------------------------- */

function btp_import_snapshot( $post_id ) {
	$meta = array();
	foreach ( get_post_meta( $post_id ) as $k => $v ) {
		if ( 0 === strpos( $k, 'btp_' ) ) {
			$meta[ $k ] = maybe_unserialize( $v[0] );
		}
	}
	$terms = wp_get_object_terms( $post_id, 'btp-leverantor', array( 'fields' => 'ids' ) );
	return array(
		'title'  => get_post_field( 'post_title', $post_id ),
		'status' => get_post_status( $post_id ),
		'meta'   => $meta,
		'terms'  => is_array( $terms ) ? $terms : array(),
	);
}

function btp_import_sideload_file( $path, $post_id, $title ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = wp_tempnam( wp_basename( $path ) );
	copy( $path, $tmp );
	$id = media_handle_sideload( array( 'name' => wp_basename( $path ), 'tmp_name' => $tmp ), $post_id, $title );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
	}
	return $id;
}

function btp_import_sideload_url( $url, $post_id, $title ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = download_url( $url, 60 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}
	$name = sanitize_file_name( wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
	if ( ! preg_match( '/\.(jpe?g|png|webp)$/i', $name ) ) {
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : 'image/jpeg';
		$ext  = array( 'image/png' => 'png', 'image/webp' => 'webp' );
		$name = sanitize_title( $title ) . '.' . ( isset( $ext[ $mime ] ) ? $ext[ $mime ] : 'jpg' );
	}
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, $title );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
	}
	return $id;
}

function btp_import_apply_row( $row, $data, &$errors ) {
	$dir   = btp_import_dir( $data['token'] );
	$id    = $row['post_id'];
	$isnew = ! $id;
	$now   = current_time( 'mysql' );

	if ( $isnew ) {
		$id = wp_insert_post(
			array(
				'post_type'   => 'berner-products',
				'post_title'  => $row['name'],
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$errors[] = $row['name'] . ': ' . $id->get_error_message();
			return 0;
		}
	} elseif ( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) !== $row['name'] ) {
		wp_update_post( array( 'ID' => $id, 'post_title' => $row['name'] ) );
	}

	update_post_meta( $id, 'btp_artikel', 1 );
	update_post_meta( $id, 'btp_webb', $row['webb'] );
	update_post_meta( $id, 'btp_godkand', $row['godkand'] );
	update_post_meta( $id, 'btp_kat', $row['kat'] );
	update_post_meta( $id, 'btp_import_at', $now );
	foreach ( BTP_IMPORT_LANGS as $l ) {
		if ( isset( $row['names'][ $l ] ) ) {
			update_post_meta( $id, 'btp_namn_' . $l, $row['names'][ $l ] );
		} else {
			delete_post_meta( $id, 'btp_namn_' . $l );
		}
	}

	// Texter: Excel är modern. Tom cell utan översättning = fältet töms (webben visar då engelska).
	$final = btp_import_final_texts( $row );
	foreach ( BTP_IMPORT_LANGS as $l ) {
		foreach ( btp_import_text_fields() as $f ) {
			$key = 'btp_' . $f . '_' . $l;
			if ( isset( $final[ $l ][ $f ] ) ) {
				update_post_meta( $id, $key, $final[ $l ][ $f ]['v'] );
				if ( $final[ $l ][ $f ]['ai'] ) {
					update_post_meta( $id, $key . '_ai', 1 );
					update_post_meta( $id, $key . '_src', md5( $row['texts'][ $row['source'] ][ $f ] ) );
					$hist   = (array) get_post_meta( $id, $key . '_aihist', true );
					$hist[] = md5( $final[ $l ][ $f ]['v'] );
					update_post_meta( $id, $key . '_aihist', array_slice( array_values( array_unique( array_filter( $hist ) ) ), -10 ) );
				} else {
					delete_post_meta( $id, $key . '_ai' );
					delete_post_meta( $id, $key . '_src' );
				}
			} else {
				delete_post_meta( $id, $key );
				delete_post_meta( $id, $key . '_ai' );
				delete_post_meta( $id, $key . '_src' );
			}
		}
	}

	// Leverantör.
	if ( '' !== $row['supplier'] ) {
		wp_set_object_terms( $id, array( $row['supplier'] ), 'btp-leverantor', false );
	}

	// Bild.
	if ( $row['image'] ) {
		$src = btp_import_image_src( $row['image'] );
		if ( (string) get_post_meta( $id, 'btp_bild_src', true ) !== $src || ! get_post_meta( $id, 'btp_bild', true ) ) {
			$att = 0;
			if ( isset( $row['image']['att'] ) ) {
				$att = (int) $row['image']['att'];
			} elseif ( isset( $row['image']['file'] ) ) {
				$att = btp_import_sideload_file( $dir . '/files/' . $row['image']['file'], $id, $row['name'] );
			} else {
				$att = btp_import_sideload_url( $row['image']['url'], $id, $row['name'] );
			}
			if ( is_wp_error( $att ) ) {
				$errors[] = $row['name'] . ': bilden kunde inte hämtas (' . $att->get_error_message() . ')';
			} elseif ( $att ) {
				update_post_meta( $id, 'btp_bild', (int) $att );
				update_post_meta( $id, 'btp_bild_src', $src );
			}
		}
	}

	// Säkerhetsdatablad.
	foreach ( $row['sds'] as $l => $s ) {
		if ( isset( $s['url'] ) ) {
			update_post_meta( $id, 'btp_sds_' . $l, btp_import_clean_sds( $s['url'] ) );
			continue;
		}
		$att = isset( $s['att'] ) ? (int) $s['att'] : 0;
		if ( isset( $s['file'] ) ) {
			$old  = (int) get_post_meta( $id, 'btp_sds_' . $l . '_att', true );
			$path = $old ? get_attached_file( $old ) : '';
			if ( $path && file_exists( $path ) ) {
				// Ny version av samma datablad: byt filen på plats så att länken på webben är densamma.
				copy( $dir . '/files/' . $s['file'], $path );
				$att = $old;
			} else {
				$att = btp_import_sideload_file( $dir . '/files/' . $s['file'], $id, $row['name'] . ' SDS ' . strtoupper( $l ) );
			}
		}
		if ( is_wp_error( $att ) ) {
			$errors[] = $row['name'] . ': säkerhetsdatabladet kunde inte sparas (' . $att->get_error_message() . ')';
		} elseif ( $att ) {
			update_post_meta( $id, 'btp_sds_' . $l . '_att', $att );
			update_post_meta( $id, 'btp_sds_' . $l, wp_get_attachment_url( $att ) );
		}
	}

	// Publicering: nya produkter först när de har en bild. Borttagna (Deleted) ligger kvar men visas inte.
	if ( 'DELETED' !== $row['webb'] && 'publish' !== get_post_status( $id ) && btp_image_for( $id ) ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	}
	return $id;
}

function btp_import_handle_apply() {
	if ( ! current_user_can( BTP_IMPORT_CAP ) ) {
		wp_die( 'Du har inte behörighet.' );
	}
	check_admin_referer( 'btp_import_apply' );
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$data  = btp_import_get( $token );
	if ( ! $data || ! empty( $data['applied'] ) ) {
		wp_safe_redirect( btp_import_url( array( 'btp_err' => rawurlencode( 'Importen finns inte eller är redan genomförd.' ) ) ) );
		exit;
	}
	btp_import_apply_token( $token, 'manual' );
	wp_safe_redirect( btp_import_url( array( 'token' => $token, 'done' => 1 ) ) );
	exit;
}

/**
 * Genomför en förberedd import. $source = manual eller auto. Ger importens data.
 */
function btp_import_apply_token( $token, $source = 'manual' ) {
	$data = btp_import_get( $token );
	if ( ! $data || ! empty( $data['applied'] ) ) {
		return $data;
	}
	$user = 'auto' === $source ? 0 : get_current_user_id();
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$snap   = array();
	$errors = array();
	$count  = array( 'new' => 0, 'update' => 0, 'delete' => 0, 'same' => 0, 'skip' => 0 );
	$fill   = array();
	$map    = $data['map'];
	foreach ( $data['rows'] as $i => $row ) {
		$action = btp_import_action( $row );
		$count[ $action ]++;
		if ( 'skip' === $action ) {
			continue;
		}
		if ( $row['post_id'] ) {
			$snap[ $row['post_id'] ] = btp_import_snapshot( $row['post_id'] );
		}
		$id = btp_import_apply_row( $row, $data, $errors );
		if ( ! $id ) {
			continue;
		}
		if ( ! $row['post_id'] ) {
			$snap[ $id ] = array( 'created' => true );
			$data['rows'][ $i ]['created'] = true;
		}
		$data['rows'][ $i ]['post_id'] = $id;
		// Till den ifyllda Excel-filen: id för nya produkter och Claudes översättningar.
		if ( isset( $map['id'] ) && ! $row['had_id'] ) {
			$fill[ $row['row'] ][ $map['id'] ] = (string) $id;
		}
		foreach ( (array) $row['tr'] as $l => $fields ) {
			foreach ( $fields as $f => $text ) {
				if ( isset( $map[ $l . '_' . $f ] ) ) {
					$fill[ $row['row'] ][ $map[ $l . '_' . $f ] ] = $text;
				}
			}
		}
	}
	$dir = btp_import_dir( $token );
	file_put_contents( $dir . '/snapshot.json', wp_json_encode( $snap ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$filled = $fill ? btp_xlsx_fill( $dir . '/source.xlsx', $dir . '/filled.xlsx', $data['sheet'], $fill ) : false;

	$data['applied'] = current_time( 'mysql' );
	$data['count']   = $count;
	$data['errors']  = $errors;
	$data['filled']  = (bool) $filled;
	btp_import_put( $token, $data );

	$log = get_option( 'btp_import_log', array() );
	array_unshift(
		$log,
		array(
			'token'  => $token,
			'time'   => $data['applied'],
			'user'   => get_current_user_id(),
			'excel'  => $data['excel'],
			'count'  => $count,
			'errors' => count( $errors ),
			'filled' => (bool) $filled,
			'undone' => false,
		)
	);
	// Spara de 10 senaste; äldre importers filer tas bort.
	foreach ( array_slice( $log, 10 ) as $old ) {
		btp_import_remove( $old['token'] );
	}
	update_option( 'btp_import_log', array_slice( $log, 0, 10 ), false );
	// En nattimport som väntade på godkännande och nu godkänts för hand: räkna den som genomförd.
	if ( 'manual' === $source && function_exists( 'btp_auto_status' ) ) {
		$st = btp_auto_status();
		if ( isset( $st['result'], $st['token'], $st['pending'] ) && 'held' === $st['result'] && $st['token'] === $token ) {
			update_option( 'btp_auto_files', $st['pending']['files'], false );
			btp_auto_status( array( 'result' => 'done', 'sig' => $st['pending']['sig'], 'rows' => count( $data['rows'] ), 'pending' => null, 'message' => 'Godkänd för hand ' . $data['applied'] . '.' ) );
		}
	}
	return $data;
}
add_action( 'admin_post_btp_import_apply', 'btp_import_handle_apply' );

function btp_import_remove( $token ) {
	$dir = btp_import_dir( $token );
	foreach ( (array) glob( $dir . '/{,files/}*', GLOB_BRACE ) as $f ) {
		if ( is_file( $f ) ) {
			wp_delete_file( $f );
		}
	}
	@rmdir( $dir . '/files' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	delete_option( 'btp_imp_' . preg_replace( '/[^A-Za-z0-9]/', '', $token ) );
}

/* -------------------------------------------------------------------------
 * Ångra, avbryt, ladda ner
 * ---------------------------------------------------------------------- */

function btp_import_handle_undo() {
	if ( ! current_user_can( BTP_IMPORT_CAP ) ) {
		wp_die( 'Du har inte behörighet.' );
	}
	check_admin_referer( 'btp_import_undo' );
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$file  = btp_import_dir( $token ) . '/snapshot.json';
	$snap  = file_exists( $file ) ? json_decode( file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_array( $snap ) ) {
		wp_safe_redirect( btp_import_url( array( 'btp_err' => rawurlencode( 'Hittade inget att ångra.' ) ) ) );
		exit;
	}
	foreach ( $snap as $post_id => $s ) {
		$post_id = (int) $post_id;
		if ( ! empty( $s['created'] ) ) {
			wp_trash_post( $post_id );
			continue;
		}
		foreach ( array_keys( get_post_meta( $post_id ) ) as $k ) {
			if ( 0 === strpos( $k, 'btp_' ) ) {
				delete_post_meta( $post_id, $k );
			}
		}
		foreach ( $s['meta'] as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $s['title'], 'post_status' => $s['status'] ) );
		wp_set_object_terms( $post_id, array_map( 'intval', $s['terms'] ), 'btp-leverantor', false );
	}
	$log = get_option( 'btp_import_log', array() );
	foreach ( $log as &$entry ) {
		if ( $entry['token'] === $token ) {
			$entry['undone'] = current_time( 'mysql' );
		}
	}
	unset( $entry );
	update_option( 'btp_import_log', $log, false );
	wp_safe_redirect( btp_import_url( array( 'btp_msg' => rawurlencode( 'Importen är ångrad. Produkterna är som före importen.' ) ) ) );
	exit;
}
add_action( 'admin_post_btp_import_undo', 'btp_import_handle_undo' );

function btp_import_handle_cancel() {
	if ( ! current_user_can( BTP_IMPORT_CAP ) ) {
		wp_die( 'Du har inte behörighet.' );
	}
	check_admin_referer( 'btp_import_cancel' );
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$data  = btp_import_get( $token );
	if ( $data && empty( $data['applied'] ) ) {
		btp_import_remove( $token );
	}
	wp_safe_redirect( btp_import_url( array( 'btp_msg' => rawurlencode( 'Importen avbröts. Inget har ändrats.' ) ) ) );
	exit;
}
add_action( 'admin_post_btp_import_cancel', 'btp_import_handle_cancel' );

function btp_import_handle_download() {
	if ( ! current_user_can( BTP_IMPORT_CAP ) ) {
		wp_die( 'Du har inte behörighet.' );
	}
	check_admin_referer( 'btp_import_download' );
	$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$data  = btp_import_get( $token );
	$file  = btp_import_dir( $token ) . '/filled.xlsx';
	if ( ! $data || ! file_exists( $file ) ) {
		wp_die( 'Filen finns inte längre.' );
	}
	$name = preg_replace( '/\.xlsx$/i', '', $data['excel'] ) . ' (ifylld ' . substr( $data['applied'], 0, 10 ) . ').xlsx';
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
	header( 'Content-Length: ' . filesize( $file ) );
	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}
add_action( 'admin_post_btp_import_download', 'btp_import_handle_download' );

/* -------------------------------------------------------------------------
 * Inställningar (API-nyckel, modell, ordlista)
 * ---------------------------------------------------------------------- */

function btp_import_handle_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Bara administratörer kan ändra inställningarna.' );
	}
	check_admin_referer( 'btp_settings' );
	$s     = btp_settings();
	$key   = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
	$msg   = 'Inställningarna är sparade.';
	if ( ! empty( $_POST['remove_key'] ) ) {
		$s['api_key'] = '';
	} elseif ( '' !== $key && false === strpos( $key, '•' ) ) {
		$s['api_key'] = $key;
	}
	foreach ( array( 'ms_tenant', 'ms_client', 'ms_file', 'ms_folder', 'auto_time', 'report_to' ) as $k ) {
		if ( isset( $_POST[ $k ] ) ) {
			$s[ $k ] = trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) );
		}
	}
	$secret = isset( $_POST['ms_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ms_secret'] ) ) ) : '';
	if ( '' !== $secret && false === strpos( $secret, '•' ) ) {
		$s['ms_secret'] = $secret;
		delete_transient( 'btp_graph_token' );
	}
	if ( isset( $_POST['section'] ) && 'auto' === $_POST['section'] ) {
		$s['auto_on'] = empty( $_POST['auto_on'] ) ? 0 : 1;
	}
	$s['model']    = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : $s['model'];
	$s['glossary'] = isset( $_POST['glossary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['glossary'] ) ) : $s['glossary'];
	update_option( 'btp_settings', $s, false );
	btp_auto_schedule();
	if ( ! empty( $_POST['test'] ) ) {
		$r   = btp_ai_translate( array( 'ingress' => 'Käyttömäärä: 150–350 kg/ha viheriöille.' ), array( 'sv' ) );
		$msg = is_wp_error( $r ) ? 'Testet misslyckades: ' . $r->get_error_message() : 'Testet fungerade. Claude översatte till: "' . ( isset( $r['sv']['ingress'] ) ? $r['sv']['ingress'] : '' ) . '"';
	}
	wp_safe_redirect( btp_import_url( array( 'tab' => 'settings', 'btp_msg' => rawurlencode( $msg ) ) ) );
	exit;
}
add_action( 'admin_post_btp_settings', 'btp_import_handle_settings' );

/* -------------------------------------------------------------------------
 * Sidan
 * ---------------------------------------------------------------------- */

function btp_import_page() {
	if ( ! current_user_can( BTP_IMPORT_CAP ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- bara visning.
	$tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'import';
	$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$err   = isset( $_GET['btp_err'] ) ? sanitize_text_field( wp_unslash( $_GET['btp_err'] ) ) : '';
	$msg   = isset( $_GET['btp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['btp_msg'] ) ) : '';
	// phpcs:enable
	?>
	<div class="wrap btp-imp">
		<h1>Importera produkter från Excel</h1>
		<?php btp_import_styles(); ?>
		<nav class="nav-tab-wrapper">
			<a class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( btp_import_url() ); ?>">Import</a>
			<a class="nav-tab <?php echo 'log' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( btp_import_url( array( 'tab' => 'log' ) ) ); ?>">Tidigare importer</a>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( btp_import_url( array( 'tab' => 'settings' ) ) ); ?>">Inställningar</a>
			<?php endif; ?>
		</nav>
		<?php if ( $err ) : ?><div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>
		<?php if ( $msg ) : ?><div class="notice notice-success"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<?php
		if ( 'settings' === $tab && current_user_can( 'manage_options' ) ) {
			btp_import_page_settings();
		} elseif ( 'log' === $tab ) {
			btp_import_page_log();
		} elseif ( $token && btp_import_get( $token ) ) {
			btp_import_page_preview( btp_import_get( $token ) );
		} else {
			btp_import_page_upload();
		}
		?>
	</div>
	<?php
}

function btp_import_styles() {
	?>
	<style>
		.btp-imp .card-ish { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:18px 20px; margin:16px 0; max-width:1100px; }
		.btp-imp .drop { border:2px dashed #b7c4d6; border-radius:8px; padding:18px; background:#f6f9fc; margin:10px 0 14px; }
		.btp-imp .drop strong { display:block; margin-bottom:6px; }
		.btp-imp .hint { color:#50575e; font-size:13px; margin:6px 0 0; }
		.btp-imp code { font-size:12px; }
		.btp-imp .sum { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0; }
		.btp-imp .sum div { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:10px 14px; min-width:120px; }
		.btp-imp .sum b { display:block; font-size:22px; line-height:1.1; }
		.btp-imp .tag { display:inline-block; font-size:11px; font-weight:600; padding:2px 7px; border-radius:3px; background:#dde1e8; color:#1d2433; white-space:nowrap; }
		.btp-imp .tag.new { background:#d7f0d0; color:#1e5a14; } .btp-imp .tag.update { background:#dbe7fb; color:#13366e; }
		.btp-imp .tag.delete { background:#fbdcdc; color:#7a1717; } .btp-imp .tag.same { background:#eef0f3; color:#50575e; }
		.btp-imp table.widefat td { vertical-align:top; }
		.btp-imp .warn { color:#8a4b00; } .btp-imp .note { color:#646970; }
		.btp-imp details summary { cursor:pointer; color:#2271b1; }
		.btp-imp .tr { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; margin-top:8px; }
		.btp-imp .tr div { background:#f6f7f7; border-radius:4px; padding:8px 10px; font-size:12px; }
		.btp-imp .tr .val { white-space:pre-wrap; margin:2px 0 10px; }
		.btp-imp .tr h4 { margin:0 0 4px; font-size:12px; }
		.btp-imp .ai { font-size:10px; font-weight:700; color:#5b2d90; }
		.btp-imp .bar { height:8px; background:#eef0f3; border-radius:4px; overflow:hidden; margin:8px 0; max-width:500px; }
		.btp-imp .bar i { display:block; height:100%; width:0; background:#2271b1; transition:width .3s; }
		.btp-imp .filter-row { margin:8px 0; }
	</style>
	<?php
}

function btp_import_page_upload() {
	$ai = btp_ai_ready();
	?>
	<div class="card-ish">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'btp_import_upload' ); ?>
			<input type="hidden" name="action" value="btp_import_upload">
			<div class="drop">
				<strong>1. Excel-filen (.xlsx)</strong>
				<input type="file" name="btp_excel" accept=".xlsx" required>
				<p class="hint">Produktfilen från SharePoint. Ladda ner den som Excel-fil (Arkiv → Spara som → Ladda ner en kopia) och välj den här.</p>
			</div>
			<div class="drop">
				<strong>2. Bilder och säkerhetsdatablad (valfritt)</strong>
				<input type="file" name="btp_files[]" accept=".jpg,.jpeg,.png,.webp,.pdf,.zip" multiple>
				<p class="hint">Döp filerna efter produktnamnet så hamnar de rätt: <code>GreenCare 8.jpg</code>, <code>GreenCare 8 SDS SV.pdf</code>, <code>GreenCare 8 SDS FI.pdf</code>, <code>GreenCare 8 SDS EN.pdf</code>, <code>GreenCare 8 SDS NO.pdf</code>. Många filer? Lägg dem i en .zip.</p>
			</div>
			<p><button class="button button-primary button-hero">Läs in och visa förhandsvisning</button></p>
			<p class="hint">Inget ändras på webben förrän du har sett förhandsvisningen och tryckt på Godkänn.
				<?php echo $ai ? 'Tomma språkceller översätts av Claude från finskan.' : 'Ingen API-nyckel för Claude är inlagd – tomma språkceller visar engelska på webben.'; ?></p>
		</form>
	</div>
	<div class="card-ish">
		<h2 style="margin-top:0">Så fungerar det</h2>
		<ul style="list-style:disc;padding-left:20px">
			<li><b>Excel är modern.</b> Det som står i filen gäller. Texter som ändras direkt i WordPress skrivs över vid nästa import.</li>
			<li><b>Visas på</b> styrs av kolumnen <i>Webb</i> (t.ex. <code>SE, NO, FI</code>, <code>Alla</code> eller <code>Deleted</code>). Är den tom används <i>Certificates</i>. <code>Deleted</code> (eller Status <code>Delete</code>) döljer produkten överallt men den finns kvar.</li>
			<li><b>Språk:</b> finska är källan. Tomma svenska, engelska och norska celler översätts av Claude. Egen text i en cell vinner alltid. Baltikum och engelska sajten visar engelska.</li>
			<li><b>Produktnamn</b> översätts aldrig.</li>
			<li><b>Nya produkter</b> publiceras när de har en bild.</li>
			<li>Varje import kan <b>ångras</b> under Tidigare importer.</li>
		</ul>
	</div>
	<?php
}

function btp_import_page_preview( $data ) {
	$rows    = $data['rows'];
	$pending = array();
	foreach ( $rows as $i => $row ) {
		if ( ! empty( $row['needs'] ) ) {
			$pending[] = $i;
		}
	}
	$ai      = btp_ai_ready();
	$applied = ! empty( $data['applied'] );
	$count   = array( 'new' => 0, 'update' => 0, 'delete' => 0, 'same' => 0, 'skip' => 0 );
	$warn    = 0;
	foreach ( $rows as $row ) {
		$count[ btp_import_action( $row ) ]++;
		$warn += count( $row['warnings'] );
	}
	$langs = btp_languages();
	$tf    = btp_text_fields();
	?>
	<?php if ( $applied ) : ?>
		<div class="notice notice-success"><p><b>Klart.</b> Importen genomfördes <?php echo esc_html( $data['applied'] ); ?>.
			<?php if ( ! empty( $data['errors'] ) ) : ?> <?php echo esc_html( count( $data['errors'] ) ); ?> fel – se nedan.<?php endif; ?></p></div>
		<?php if ( ! empty( $data['filled'] ) ) : ?>
			<div class="card-ish">
				<p style="margin-top:0"><b>Excel-filen med översättningar och nya id ifyllda</b> – lägg upp den på SharePoint i stället för den gamla, så finns allt i filen.</p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=btp_import_download&token=' . $data['token'] ), 'btp_import_download' ) ); ?>">Ladda ner ifylld Excel</a>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $data['errors'] ) ) : ?>
			<div class="notice notice-warning"><ul><?php foreach ( $data['errors'] as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul></div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="sum">
		<div><b><?php echo esc_html( count( $rows ) ); ?></b>rader i <?php echo esc_html( $data['excel'] ); ?></div>
		<div><b><?php echo esc_html( $count['new'] ); ?></b>nya</div>
		<div><b><?php echo esc_html( $count['update'] ); ?></b>ändras</div>
		<div><b><?php echo esc_html( $count['delete'] ); ?></b>döljs (Deleted)</div>
		<div><b><?php echo esc_html( $count['same'] ); ?></b>oförändrade</div>
		<div><b><?php echo esc_html( $warn ); ?></b>varningar</div>
	</div>

	<?php if ( ! empty( $data['orphans'] ) ) : ?>
		<div class="notice notice-warning"><p><b><?php echo esc_html( count( $data['orphans'] ) ); ?> produkter finns på webben men inte i Excel-filen:</b> <?php echo esc_html( implode( ', ', $data['orphans'] ) ); ?>. De rörs inte av importen och visas som förut. Lägg till dem i Excel (med Deleted om de inte ska visas).</p></div>
	<?php endif; ?>

	<?php if ( ! $applied && $pending && $ai ) : ?>
		<div class="card-ish" id="btp-tr">
			<p style="margin:0"><b>Claude översätter <?php echo esc_html( count( $pending ) ); ?> produkter …</b> <span id="btp-tr-txt">0 / <?php echo esc_html( count( $pending ) ); ?></span></p>
			<div class="bar"><i id="btp-tr-bar"></i></div>
			<p class="hint" id="btp-tr-err"></p>
		</div>
		<script>
		(function () {
			var todo = <?php echo wp_json_encode( $pending ); ?>, n = 0, errors = [];
			var txt = document.getElementById('btp-tr-txt'), bar = document.getElementById('btp-tr-bar'), err = document.getElementById('btp-tr-err');
			function next() {
				if (!todo.length) { location.reload(); return; }
				var i = todo.shift(), body = new FormData();
				body.append('action', 'btp_import_translate'); body.append('_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'btp_import_translate' ) ); ?>);
				body.append('token', <?php echo wp_json_encode( $data['token'] ); ?>); body.append('i', i);
				fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
					if (!r.success) { errors.push(r.data); err.textContent = errors.join(' · '); }
				}).catch(function (e) { errors.push(String(e)); err.textContent = errors.join(' · '); }).then(function () {
					n++; txt.textContent = n + ' / <?php echo esc_js( count( $pending ) ); ?>'; bar.style.width = Math.round(100 * n / <?php echo (int) count( $pending ); ?>) + '%'; next();
				});
			}
			next();
		})();
		</script>
	<?php elseif ( ! $applied && $pending ) : ?>
		<div class="notice notice-info"><p>Ingen API-nyckel för Claude är inlagd, så <?php echo esc_html( count( $pending ) ); ?> produkter får inga översättningar. Webben visar då engelska där svensk eller norsk text saknas.</p></div>
	<?php endif; ?>

	<?php if ( ! $applied ) : ?>
		<div class="card-ish" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Genomför …';">
				<?php wp_nonce_field( 'btp_import_apply' ); ?>
				<input type="hidden" name="action" value="btp_import_apply"><input type="hidden" name="token" value="<?php echo esc_attr( $data['token'] ); ?>">
				<button class="button button-primary button-hero" <?php disabled( $pending && $ai ); ?>>Godkänn och uppdatera webben</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'btp_import_cancel' ); ?>
				<input type="hidden" name="action" value="btp_import_cancel"><input type="hidden" name="token" value="<?php echo esc_attr( $data['token'] ); ?>">
				<button class="button button-hero">Avbryt</button>
			</form>
			<span class="hint">Granska översättningarna under "Visa texter". Vill du ändra något: skriv rätt text i Excel och läs in igen.</span>
		</div>
	<?php endif; ?>

	<p class="filter-row">
		<label><input type="checkbox" id="btp-only" checked> Dölj oförändrade</label>
	</p>
	<table class="widefat striped" id="btp-table">
		<thead><tr><th style="width:24%">Produkt</th><th style="width:9%">Åtgärd</th><th style="width:12%">Visas på</th><th>Ändringar och varningar</th></tr></thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$action  = btp_import_action( $row );
			$labels  = array( 'new' => 'Ny', 'update' => 'Ändras', 'delete' => 'Döljs', 'same' => 'Oförändrad', 'skip' => 'Hoppas över' );
			$final   = btp_import_final_texts( $row );
			$changes = 'same' === $action ? array() : btp_import_changes( $row );
			?>
			<tr data-action="<?php echo esc_attr( $action ); ?>" <?php echo 'same' === $action ? 'hidden' : ''; ?>>
				<td><b><?php echo esc_html( $row['name'] ); ?></b><br><span class="note">Rad <?php echo esc_html( $row['row'] ); ?><?php echo $row['supplier'] ? ' · ' . esc_html( $row['supplier'] ) : ''; ?><?php echo $row['kat'] ? ' · ' . esc_html( btp_category_label( $row['kat'], 'sv' ) ) : ''; ?></span></td>
				<td><span class="tag <?php echo esc_attr( $action ); ?>"><?php echo esc_html( $labels[ $action ] ); ?></span></td>
				<td><?php echo esc_html( 'ALL' === $row['webb'] ? 'Alla' : ( 'DELETED' === $row['webb'] ? '–' : str_replace( ' ', ', ', $row['webb'] ) ) ); ?></td>
				<td>
					<?php if ( $changes && 'delete' !== $action ) : ?><div><?php echo esc_html( implode( ' · ', $changes ) ); ?></div><?php endif; ?>
					<?php foreach ( $row['warnings'] as $w ) : ?><div class="warn">⚠ <?php echo esc_html( $w ); ?></div><?php endforeach; ?>
					<?php foreach ( (array) ( isset( $row['notes'] ) ? $row['notes'] : array() ) as $w ) : ?><div class="note"><?php echo esc_html( $w ); ?></div><?php endforeach; ?>
					<?php if ( ! empty( $row['tr_error'] ) ) : ?><div class="warn">⚠ Översättningen misslyckades: <?php echo esc_html( $row['tr_error'] ); ?></div><?php endif; ?>
					<?php if ( 'delete' !== $action ) : ?>
						<details><summary>Visa texter</summary>
							<div class="tr">
								<?php foreach ( array( 'sv', 'en', 'no' ) as $l ) : ?>
									<div><h4><?php echo esc_html( $langs[ $l ] ); ?></h4>
										<?php foreach ( $tf as $f => $label ) : ?>
											<?php if ( isset( $final[ $l ][ $f ] ) ) : ?>
												<b><?php echo esc_html( $label ); ?></b> <?php if ( $final[ $l ][ $f ]['ai'] ) : ?><span class="ai">CLAUDE</span><?php endif; ?>
												<p class="val"><?php echo esc_html( $final[ $l ][ $f ]['v'] ); ?></p>
											<?php endif; ?>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<script>
	(function () {
		var box = document.getElementById('btp-only');
		box.addEventListener('change', function () {
			document.querySelectorAll('#btp-table tr[data-action="same"]').forEach(function (tr) { tr.hidden = box.checked; });
		});
	})();
	</script>
	<?php
}

function btp_import_page_log() {
	$log = get_option( 'btp_import_log', array() );
	if ( empty( $log ) ) {
		echo '<p>Inga importer än.</p>';
		return;
	}
	?>
	<table class="widefat striped" style="max-width:1100px;margin-top:16px">
		<thead><tr><th>När</th><th>Vem</th><th>Fil</th><th>Resultat</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $log as $i => $e ) : ?>
			<?php $user = ! empty( $e['auto'] ) ? (object) array( 'display_name' => 'Automatiskt (SharePoint)' ) : get_userdata( $e['user'] ); ?>
			<tr>
				<td><?php echo esc_html( $e['time'] ); ?></td>
				<td><?php echo esc_html( $user ? $user->display_name : '–' ); ?></td>
				<td><?php echo esc_html( $e['excel'] ); ?></td>
				<td><?php echo esc_html( sprintf( '%d nya, %d ändrade, %d dolda, %d oförändrade', $e['count']['new'], $e['count']['update'], $e['count']['delete'], $e['count']['same'] ) ); ?><?php echo $e['errors'] ? ' · ' . esc_html( $e['errors'] ) . ' fel' : ''; ?></td>
				<td>
					<a href="<?php echo esc_url( btp_import_url( array( 'token' => $e['token'] ) ) ); ?>">Visa</a>
					<?php if ( $e['undone'] ) : ?>
						· <span class="note">Ångrad <?php echo esc_html( $e['undone'] ); ?></span>
					<?php elseif ( 0 === $i ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Ångra importen? Produkterna återställs till hur de var före importen.');">
							<?php wp_nonce_field( 'btp_import_undo' ); ?>
							<input type="hidden" name="action" value="btp_import_undo"><input type="hidden" name="token" value="<?php echo esc_attr( $e['token'] ); ?>">
							· <button class="button-link">Ångra</button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="hint">Den senaste importen kan ångras. De tio senaste sparas.</p>
	<?php
}

function btp_import_page_settings() {
	$s       = btp_settings();
	$has_key = '' !== btp_api_key();
	$const   = defined( 'BTP_ANTHROPIC_KEY' ) && BTP_ANTHROPIC_KEY;
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card-ish">
		<?php wp_nonce_field( 'btp_settings' ); ?>
		<input type="hidden" name="action" value="btp_settings">
		<h2 style="margin-top:0">Översättning med Claude</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="api_key">API-nyckel (Anthropic)</label></th>
				<td>
					<?php if ( $const ) : ?>
						<p>Nyckeln ligger i wp-config.php (BTP_ANTHROPIC_KEY).</p>
					<?php else : ?>
						<input type="password" id="api_key" name="api_key" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr( '•••••••• (inlagd – lämna tomt för att behålla)' ) : 'sk-ant-…'; ?>">
						<?php if ( $has_key ) : ?><label style="margin-left:10px"><input type="checkbox" name="remove_key" value="1"> Ta bort nyckeln</label><?php endif; ?>
						<p class="description">Skapas på console.anthropic.com. Syns aldrig igen efter att den sparats. Bara administratörer ser den här fliken.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="model">Modell</label></th>
				<td><input type="text" id="model" name="model" class="regular-text" value="<?php echo esc_attr( $s['model'] ); ?>"><p class="description">Standard: claude-sonnet-5-5.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="glossary">Ordlista</label></th>
				<td><textarea id="glossary" name="glossary" rows="14" class="large-text code"><?php echo esc_textarea( $s['glossary'] ); ?></textarea>
					<p class="description">En term per rad: <code>finska = svenska | engelska | norska</code>. Claude följer ordlistan. Produktnamn behöver inte stå här – de översätts aldrig.</p></td>
			</tr>
		</table>
		<p><button class="button button-primary">Spara</button> <button class="button" name="test" value="1">Spara och testa</button></p>
	</form>
	<?php
	btp_import_page_settings_auto();
	if ( function_exists( 'btp_update_box' ) ) {
		btp_update_box();
	}
}

function btp_import_page_settings_auto() {
	$s       = btp_settings();
	$st      = btp_auto_status();
	$next    = wp_next_scheduled( 'btp_auto_import' );
	$const   = defined( 'BTP_MS_SECRET' ) && BTP_MS_SECRET;
	$labels  = array( 'done' => 'Klar', 'same' => 'Inga ändringar', 'held' => 'Väntar på godkännande', 'error' => 'Fel', 'running' => 'Pågår' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card-ish">
		<?php wp_nonce_field( 'btp_settings' ); ?>
		<input type="hidden" name="action" value="btp_settings"><input type="hidden" name="section" value="auto">
		<h2 style="margin-top:0">Automatisk import från SharePoint</h2>
		<p class="hint" style="margin-top:0">Varje natt kollar WordPress om Excel-filen eller bildmappen på SharePoint har ändrats. Har något ändrats importeras det automatiskt – med översättning – och en rapport skickas. Inget ändras om filen är oförändrad. Ser något fel ut (t.ex. att många produkter plötsligt skulle döljas) stoppas importen och väntar på godkännande.</p>
		<?php if ( ! empty( $st ) ) : ?>
			<div class="notice <?php echo in_array( isset( $st['result'] ) ? $st['result'] : '', array( 'error', 'held' ), true ) ? 'notice-warning' : 'notice-info'; ?>" style="margin:10px 0 16px">
				<p><b>Senaste körning:</b> <?php echo esc_html( isset( $st['last_run'] ) ? $st['last_run'] : '–' ); ?> – <?php echo esc_html( isset( $labels[ $st['result'] ] ) ? $labels[ $st['result'] ] : '' ); ?>. <?php echo esc_html( isset( $st['message'] ) ? $st['message'] : '' ); ?>
				<?php if ( ! empty( $st['token'] ) && btp_import_get( $st['token'] ) ) : ?> <a href="<?php echo esc_url( btp_import_url( array( 'token' => $st['token'] ) ) ); ?>">Visa</a><?php endif; ?></p>
			</div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Nattlig import</th><td><label><input type="checkbox" name="auto_on" value="1" <?php checked( ! empty( $s['auto_on'] ) ); ?>> På</label>
				&nbsp; klockan <input type="text" name="auto_time" value="<?php echo esc_attr( $s['auto_time'] ); ?>" size="5">
				<p class="description"><?php echo $next ? esc_html( 'Nästa körning: ' . wp_date( 'Y-m-d H:i', $next ) ) : 'Inte schemalagd (slå på och fyll i uppgifterna nedan).'; ?></p></td></tr>
			<tr><th scope="row"><label for="ms_file">Excel-filen på SharePoint</label></th><td><input type="url" id="ms_file" name="ms_file" class="large-text" value="<?php echo esc_attr( $s['ms_file'] ); ?>" placeholder="https://berner.sharepoint.com/sites/…/Shared Documents/…/produkter.xlsx">
				<p class="description">Öppna mappen i SharePoint, markera filen → ⋯ → Detaljer → <i>Sökväg</i> (kopiera direktsökväg). En delningslänk fungerar också.</p></td></tr>
			<tr><th scope="row"><label for="ms_folder">Mapp med bilder och säkerhetsdatablad</label></th><td><input type="url" id="ms_folder" name="ms_folder" class="large-text" value="<?php echo esc_attr( $s['ms_folder'] ); ?>" placeholder="https://berner.sharepoint.com/sites/…/Shared Documents/…/Produktbilder">
				<p class="description">Valfritt. Filer döpta efter produkten: <code>GreenCare 8.jpg</code>, <code>GreenCare 8 SDS SV.pdf</code>. Bara nya eller ändrade filer hämtas.</p></td></tr>
			<tr><th scope="row"><label for="report_to">Rapport till</label></th><td><input type="text" id="report_to" name="report_to" class="large-text" value="<?php echo esc_attr( $s['report_to'] ); ?>" placeholder="namn@bernercompany.com, namn2@bernercompany.com">
				<p class="description">E-post när något ändrats, väntar på godkännande eller gått fel. Ingen e-post när inget har ändrats.</p></td></tr>
			<tr><th scope="row">Microsoft-app (från Berner IT)</th><td>
				<p><label>Tenant ID<br><input type="text" name="ms_tenant" class="regular-text" value="<?php echo esc_attr( $s['ms_tenant'] ); ?>"></label></p>
				<p><label>Client ID<br><input type="text" name="ms_client" class="regular-text" value="<?php echo esc_attr( $s['ms_client'] ); ?>"></label></p>
				<p><label>Client secret<br><?php if ( $const ) : ?>Ligger i wp-config.php.<?php else : ?><input type="password" name="ms_secret" class="regular-text" autocomplete="off" placeholder="<?php echo '' !== $s['ms_secret'] ? esc_attr( '•••••••• (inlagd – lämna tomt för att behålla)' ) : ''; ?>"><?php endif; ?></label></p>
				<p class="description">Appen behöver bara läsrätt (Sites.Selected) till webbplatsen där filen ligger. Pluginet skriver aldrig till SharePoint.</p>
			</td></tr>
		</table>
		<p><button class="button button-primary">Spara</button></p>
	</form>
	<div class="card-ish" style="display:flex;gap:10px;align-items:center">
		<?php foreach ( array( 'test' => 'Testa anslutning', 'run' => 'Kör nu' ) as $what => $label ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'btp_auto_action' ); ?>
				<input type="hidden" name="action" value="btp_auto_action"><input type="hidden" name="what" value="<?php echo esc_attr( $what ); ?>">
				<button class="button" <?php disabled( ! btp_ms_ready() ); ?>><?php echo esc_html( $label ); ?></button>
			</form>
		<?php endforeach; ?>
		<span class="hint">"Kör nu" hämtar och importerar direkt, även om inget har ändrats.</span>
	</div>
	<?php
}
