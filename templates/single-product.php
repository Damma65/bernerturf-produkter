<?php
/**
 * Mall: den nya produktsidan på produktens egen adress.
 * Använder temats sidhuvud och sidfot. Om temats sidhuvud inte öppnar <main> gör vi det här.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$btp_id = btp_single_product_id();

ob_start();
get_header();
$btp_header = ob_get_clean();
echo $btp_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- temats egen utdata.

$btp_open_main = ( false === stripos( $btp_header, '<main' ) );
if ( $btp_open_main ) {
	echo '<main id="content" class="site-content">';
}

echo btp_render_article( $btp_id, '', 'h1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped i renderingen.

if ( $btp_open_main ) {
	echo '</main>';
}

get_footer();
