<?php
/**
 * Plugin Name:       Berner Turf Produkter
 * Description:       Egen produktkatalog med filter (leverantör, kategori, land) och artikelsida med näringstabell. Kortkoder: [bernerturf_produkter] och [bernerturf_produkt id="123"].
 * Version:           0.5.0
 * Author:            Berner Turf
 * Text Domain:       bernerturf-produkter
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * Läser produkterna som redan finns i posttypen "berner-products" (temat från Into Digital).
 * Lägger till en taxonomi "Leverantörer" och egna fält (btp_*) på produkterna så att texter,
 * näringsinnehåll och leverantörer kan ändras i WP-admin eller via REST utan ny pluginversion.
 * Produkterna kan styras från en Excel-fil (Produkter > Importera från Excel), med översättning via Claude.
 * Valfri nattlig import direkt från SharePoint (Microsoft Graph, endast läsrätt).
 * Uppdaterar sig själv från GitHub (Damma65/bernerturf-produkter). Stängs av med BTP_DISABLE_AUTO_UPDATE.
 * Update URI:        https://github.com/Damma65/bernerturf-produkter
 * Inga egna tabeller. Temats egna produktfält rörs inte.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BTP_VERSION', '0.5.0' );
define( 'BTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'BTP_URL', plugin_dir_url( __FILE__ ) );

require_once BTP_DIR . 'includes/i18n.php';
require_once BTP_DIR . 'includes/register.php';
require_once BTP_DIR . 'includes/data.php';
require_once BTP_DIR . 'includes/render-catalog.php';
require_once BTP_DIR . 'includes/render-article.php';
require_once BTP_DIR . 'includes/translate.php';
if ( is_admin() || wp_doing_cron() ) {
	require_once BTP_DIR . 'includes/admin.php';
	require_once BTP_DIR . 'includes/xlsx.php';
	require_once BTP_DIR . 'includes/import.php';
	require_once BTP_DIR . 'includes/graph.php';
	require_once BTP_DIR . 'includes/auto.php';
	require_once BTP_DIR . 'includes/updater.php';
}

// Nattkörningen tas bort när pluginet inaktiveras och läggs tillbaka när det aktiveras.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'btp_auto_import' );
	wp_clear_scheduled_hook( 'btp_auto_continue' );
} );
register_activation_hook( __FILE__, function () {
	require_once BTP_DIR . 'includes/graph.php';
	require_once BTP_DIR . 'includes/auto.php';
	btp_auto_schedule();
} );

/**
 * Registrerar CSS och JS. De laddas bara på sidor där en av kortkoderna används.
 */
function btp_register_assets() {
	wp_register_style( 'bernerturf-produkter', BTP_URL . 'assets/produkter.css', array(), BTP_VERSION );
	wp_register_script( 'bernerturf-produkter', BTP_URL . 'assets/produkter.js', array(), BTP_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'btp_register_assets' );

function btp_enqueue_assets() {
	wp_enqueue_style( 'bernerturf-produkter' );
	wp_enqueue_script( 'bernerturf-produkter' );
}

/**
 * [bernerturf_produkter land="SE" artikelsida="" rubrik="…" ingress="…"]
 * land        = förvalt land i landfiltret: auto (landet för den språkversion besökaren är på), SE, NO, FI, EE, LV, LT eller tomt för alla.
 * artikelsida = adress till sidan med [bernerturf_produkt]. Tomt = samma sida.
 * rubrik, ingress = texterna i katalogens toppfält.
 */
function btp_shortcode_catalog( $atts ) {
	$atts = shortcode_atts(
		array(
			'land'        => 'auto',
			'artikelsida' => '',
			'rubrik'      => '',
			'ingress'     => '',
		),
		$atts,
		'bernerturf_produkter'
	);
	if ( '' === trim( $atts['rubrik'] ) ) {
		$atts['rubrik'] = btp_t( 'hero_title' );
	}
	if ( '' === trim( $atts['ingress'] ) ) {
		$atts['ingress'] = btp_t( 'hero_lead' );
	}
	btp_enqueue_assets();
	return btp_render_catalog( $atts );
}
add_shortcode( 'bernerturf_produkter', 'btp_shortcode_catalog' );

/**
 * [bernerturf_produkt id="3068" kontakt=""]
 * id      = produkten som visas om adressen inte anger ?produkt=ID.
 * kontakt = adress för knapparna Begär offert / Prata med en säljare.
 */
function btp_shortcode_article( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0, 'kontakt' => '' ), $atts, 'bernerturf_produkt' );
	$id   = btp_resolve_article_id( absint( $atts['id'] ) );
	if ( ! $id || 'berner-products' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || ! btp_visible_here( $id ) ) {
		return '';
	}
	btp_enqueue_assets();
	return btp_render_article( $id, esc_url_raw( $atts['kontakt'] ) );
}
add_shortcode( 'bernerturf_produkt', 'btp_shortcode_article' );
