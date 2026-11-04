<?php
/**
 * Läser och skriver .xlsx-filer utan externa bibliotek (ZipArchive + DOM, finns i vanlig PHP).
 * Läser första bladet. Skriver bara in text i tomma/angivna celler i en kopia av filen,
 * så att formatering, rullistor och övriga blad i Excel-filen behålls.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_xlsx_available() {
	return class_exists( 'ZipArchive' ) && class_exists( 'DOMDocument' );
}

/**
 * "AB12" => array( 11, 27 ) (rad, kolumnindex från 0).
 */
function btp_xlsx_ref( $ref ) {
	if ( ! preg_match( '/^([A-Z]+)(\d+)$/', strtoupper( $ref ), $m ) ) {
		return array( 0, 0 );
	}
	$col = 0;
	foreach ( str_split( $m[1] ) as $ch ) {
		$col = $col * 26 + ( ord( $ch ) - 64 );
	}
	return array( (int) $m[2], $col - 1 );
}

function btp_xlsx_col_letter( $index ) {
	$index++;
	$out = '';
	while ( $index > 0 ) {
		$mod   = ( $index - 1 ) % 26;
		$out   = chr( 65 + $mod ) . $out;
		$index = (int) ( ( $index - $mod ) / 26 );
	}
	return $out;
}

/**
 * Sökvägen i zip-filen till första bladet.
 */
function btp_xlsx_first_sheet( ZipArchive $zip ) {
	$wb   = $zip->getFromName( 'xl/workbook.xml' );
	$rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
	if ( $wb && $rels ) {
		$wbx = new DOMDocument();
		$rlx = new DOMDocument();
		if ( @$wbx->loadXML( $wb ) && @$rlx->loadXML( $rels ) ) {
			$sheet = $wbx->getElementsByTagName( 'sheet' )->item( 0 );
			if ( $sheet ) {
				$rid = $sheet->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
				foreach ( $rlx->getElementsByTagName( 'Relationship' ) as $rel ) {
					if ( $rel->getAttribute( 'Id' ) === $rid ) {
						$target = ltrim( $rel->getAttribute( 'Target' ), '/' );
						return 0 === strpos( $target, 'xl/' ) ? $target : 'xl/' . $target;
					}
				}
			}
		}
	}
	return 'xl/worksheets/sheet1.xml';
}

/**
 * Läser första bladet. Ger array( 'rows' => array( radnr => array( kolumn => text ) ), 'hidden' => array( radnr => true ), 'sheet' => sökväg )
 * eller WP_Error.
 */
function btp_xlsx_read( $path ) {
	if ( ! btp_xlsx_available() ) {
		return new WP_Error( 'btp_xlsx', 'Servern saknar ZipArchive/DOM i PHP – be webbyrån aktivera dem.' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'btp_xlsx', 'Filen kunde inte öppnas. Är det en .xlsx-fil?' );
	}
	$strings = array();
	$sst     = $zip->getFromName( 'xl/sharedStrings.xml' );
	if ( $sst ) {
		$doc = new DOMDocument();
		@$doc->loadXML( $sst, LIBXML_NONET | LIBXML_COMPACT );
		foreach ( $doc->getElementsByTagName( 'si' ) as $si ) {
			$text = '';
			foreach ( $si->getElementsByTagName( 't' ) as $t ) {
				// Fonetiska tillägg (rPh) hoppas över.
				if ( 'rPh' !== $t->parentNode->nodeName ) {
					$text .= $t->textContent;
				}
			}
			$strings[] = $text;
		}
	}
	$sheet_path = btp_xlsx_first_sheet( $zip );
	$xml        = $zip->getFromName( $sheet_path );
	$zip->close();
	if ( ! $xml ) {
		return new WP_Error( 'btp_xlsx', 'Hittade inget blad i Excel-filen.' );
	}
	$doc = new DOMDocument();
	if ( ! @$doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE ) ) {
		return new WP_Error( 'btp_xlsx', 'Bladet i Excel-filen kunde inte läsas.' );
	}
	$rows   = array();
	$hidden = array();
	foreach ( $doc->getElementsByTagName( 'row' ) as $row ) {
		$r = (int) $row->getAttribute( 'r' );
		if ( '1' === $row->getAttribute( 'hidden' ) || 'true' === $row->getAttribute( 'hidden' ) ) {
			$hidden[ $r ] = true;
		}
		foreach ( $row->getElementsByTagName( 'c' ) as $c ) {
			list( $cr, $col ) = btp_xlsx_ref( $c->getAttribute( 'r' ) );
			$type  = $c->getAttribute( 't' );
			$value = '';
			if ( 'inlineStr' === $type ) {
				foreach ( $c->getElementsByTagName( 't' ) as $t ) {
					$value .= $t->textContent;
				}
			} else {
				$v = $c->getElementsByTagName( 'v' )->item( 0 );
				$v = $v ? $v->textContent : '';
				if ( 's' === $type ) {
					$value = isset( $strings[ (int) $v ] ) ? $strings[ (int) $v ] : '';
				} elseif ( 'e' === $type ) {
					$value = ''; // #VALUE!, #BUSY! m.m. räknas som tom cell.
				} elseif ( 'b' === $type ) {
					$value = '1' === $v ? 'TRUE' : 'FALSE';
				} else {
					$value = $v;
					if ( is_numeric( $value ) && false !== strpos( $value, '.' ) ) {
						$value = rtrim( rtrim( sprintf( '%.6F', (float) $value ), '0' ), '.' );
					}
				}
			}
			$value = trim( str_replace( array( "\r\n", "\r", '_x000D_' ), "\n", $value ) );
			if ( '' !== $value ) {
				$rows[ $cr ? $cr : $r ][ $col ] = $value;
			}
		}
	}
	ksort( $rows );
	return array( 'rows' => $rows, 'hidden' => $hidden, 'sheet' => $sheet_path );
}

/**
 * Kopierar $src till $dest och skriver in text i cellerna i $cells (array( radnr => array( kolumn => text ) )).
 */
function btp_xlsx_fill( $src, $dest, $sheet_path, $cells ) {
	if ( ! btp_xlsx_available() || ! copy( $src, $dest ) ) {
		return false;
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $dest ) ) {
		return false;
	}
	$xml = $zip->getFromName( $sheet_path );
	$doc = new DOMDocument();
	if ( ! $xml || ! @$doc->loadXML( $xml, LIBXML_NONET | LIBXML_PARSEHUGE ) ) {
		$zip->close();
		return false;
	}
	$ns        = $doc->documentElement->namespaceURI;
	$sheetData = $doc->getElementsByTagNameNS( $ns, 'sheetData' )->item( 0 );
	$row_nodes = array();
	foreach ( $sheetData->getElementsByTagNameNS( $ns, 'row' ) as $row ) {
		$row_nodes[ (int) $row->getAttribute( 'r' ) ] = $row;
	}
	ksort( $cells );
	foreach ( $cells as $r => $cols ) {
		if ( ! isset( $row_nodes[ $r ] ) ) {
			$new    = $doc->createElementNS( $ns, 'row' );
			$new->setAttribute( 'r', (string) $r );
			$before = null;
			foreach ( $row_nodes as $nr => $node ) {
				if ( $nr > $r ) {
					$before = $node;
					break;
				}
			}
			$before ? $sheetData->insertBefore( $new, $before ) : $sheetData->appendChild( $new );
			$row_nodes[ $r ] = $new;
			ksort( $row_nodes );
		}
		$row = $row_nodes[ $r ];
		$row->removeAttribute( 'spans' );
		ksort( $cols );
		foreach ( $cols as $col => $text ) {
			$ref  = btp_xlsx_col_letter( $col ) . $r;
			$cell = null;
			$next = null;
			foreach ( $row->getElementsByTagNameNS( $ns, 'c' ) as $c ) {
				list( , $cc ) = btp_xlsx_ref( $c->getAttribute( 'r' ) );
				if ( $cc === $col ) {
					$cell = $c;
					break;
				}
				if ( $cc > $col && ! $next ) {
					$next = $c;
				}
			}
			if ( ! $cell ) {
				$cell = $doc->createElementNS( $ns, 'c' );
				$cell->setAttribute( 'r', $ref );
				$next ? $row->insertBefore( $cell, $next ) : $row->appendChild( $cell );
			}
			while ( $cell->firstChild ) {
				$cell->removeChild( $cell->firstChild );
			}
			$cell->setAttribute( 't', 'inlineStr' );
			$is = $doc->createElementNS( $ns, 'is' );
			$t  = $doc->createElementNS( $ns, 't' );
			$t->setAttribute( 'xml:space', 'preserve' );
			$t->appendChild( $doc->createTextNode( (string) $text ) );
			$is->appendChild( $t );
			$cell->appendChild( $is );
		}
	}
	$zip->addFromString( $sheet_path, $doc->saveXML() );
	$zip->close();
	return true;
}
