<?php
/**
 * Produktsidan: bild, text, näringstabell i broschyrens färger, flaggor uppe till höger och
 * flikarna Beskrivning, Användning, Förpackning och Säkerhetsdatablad.
 * Texterna kommer från pluginets fält på produkten, med temats ACF-data som reserv.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Temats ACF-avsnitt (product_descriptions) sorterade på typ – används som reserv.
 */
function btp_sections_for( $post_id ) {
	$rows = function_exists( 'get_field' ) ? get_field( 'product_descriptions', $post_id ) : array();
	$out  = array( 'besk' => '', 'anv' => '', 'forp' => '', 'sds' => '' );
	if ( ! is_array( $rows ) ) {
		return $out;
	}
	$keys = array(
		'besk' => array( 'product description', 'beskrivning', 'tuotekuvaus' ),
		'anv'  => array( 'directions', 'användning', 'käyttö' ),
		'forp' => array( 'package', 'förpackning', 'pakkaus' ),
		'sds'  => array( 'safety', 'säkerhet', 'turvallisuus' ),
	);
	foreach ( $rows as $row ) {
		$title = strtolower( trim( isset( $row['section_title'] ) ? $row['section_title'] : '' ) );
		foreach ( $keys as $key => $words ) {
			foreach ( $words as $word ) {
				if ( '' !== $title && false !== strpos( $title, $word ) && '' === $out[ $key ] ) {
					$out[ $key ] = isset( $row['description'] ) ? $row['description'] : '';
				}
			}
		}
	}
	return $out;
}

/**
 * Länkar till säkerhetsdatablad ur temats avsnitt: array( text => url ).
 */
function btp_sds_links( $html ) {
	$links = array();
	if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', (string) $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$text = trim( wp_strip_all_tags( $match[2] ) );
			if ( '' !== $text ) {
				$links[ $text ] = $match[1];
			}
		}
	}
	return $links;
}

/**
 * Säkerhetsdatablad från pluginets fält: en länk per rad, ev. "Text | länk". Ger array( text => url ).
 */
function btp_sds_from_field( $text ) {
	$links = array();
	$code  = strtoupper( 'sv' === btp_lang() ? 'SE' : btp_lang() );
	foreach ( btp_lines( $text ) as $line ) {
		if ( ! preg_match( '#^(.*?)\s*\|?\s*(https?://\S+)\s*$#u', $line, $m ) ) {
			continue;
		}
		$label = trim( $m[1], " |\t" );
		$label = '' !== $label ? $label : btp_t( 'sds' ) . ' (' . $code . ')';
		while ( isset( $links[ $label ] ) ) {
			$label .= ' ';
		}
		$links[ $label ] = $m[2];
	}
	return $links;
}

/**
 * Vilken produkt som ska visas: ?produkt=ID i adressen om den produkten har ny produktsida,
 * annars id från kortkoden.
 */
function btp_resolve_article_id( $fallback_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- endast läsning av ett id.
	$requested = isset( $_GET['produkt'] ) ? absint( wp_unslash( $_GET['produkt'] ) ) : 0;
	if ( $requested && 'berner-products' === get_post_type( $requested ) && 'publish' === get_post_status( $requested ) && btp_visible_here( $requested ) ) {
		$a = btp_article_data( $requested );
		if ( $a['artikel'] ) {
			return $requested;
		}
	}
	return $fallback_id;
}

function btp_render_article( $post_id, $contact = '' ) {
	$a         = btp_article_data( $post_id );
	$title     = btp_title_for( $post_id );
	$supplier  = btp_supplier_for( $post_id, $title );
	$supplier  = 'Övriga' === $supplier ? '' : $supplier;
	$category  = '' !== $a['kategori'] ? $a['kategori'] : btp_category_for( $post_id );
	$countries = btp_countries_for( $post_id );
	$image     = btp_image_for( $post_id, 'large' );
	$lead      = '' !== $a['ingress'] ? $a['ingress'] : btp_theme_short_text( $post_id );
	$sections  = btp_sections_for( $post_id );
	$sds       = '' !== $a['sds'] ? btp_sds_from_field( $a['sds'] ) : btp_sds_links( $sections['sds'] );
	$contact   = $contact ? $contact : apply_filters( 'bernerturf_produkter_contact_url', home_url( '/sv/kontakt/' ) );
	$back      = apply_filters( 'bernerturf_produkter_catalog_url', '' );

	$allowed = array(
		'br' => array(), 'p' => array(), 'strong' => array(), 'b' => array(), 'em' => array(),
		'ul' => array(), 'li' => array(), 'div' => array(),
		'a'  => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
	);

	ob_start();
	?>
	<div class="btp btp-article" id="produkt" data-product="<?php echo esc_attr( $post_id ); ?>">
		<div class="btp-wrap btp-article__top">
			<p class="btp-article__crumb">
				<?php if ( $back ) : ?><a href="<?php echo esc_url( $back ); ?>"><?php echo esc_html( btp_t( 'products' ) ); ?></a><?php else : ?><?php echo esc_html( btp_t( 'products' ) ); ?><?php endif; ?>
				<span aria-hidden="true">›</span> <b><?php echo esc_html( $title ); ?></b>
			</p>
			<?php if ( $countries ) : ?>
				<div class="btp-article__flags" aria-label="<?php echo esc_attr( btp_t( 'delivered' ) ); ?>">
					<span><?php echo esc_html( btp_t( 'delivered' ) ); ?></span>
					<?php foreach ( $countries as $code ) : ?>
						<?php echo btp_flag( $code, 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<section class="btp-wrap btp-article__hero">
			<div class="btp-article__media">
				<?php if ( $image ) : ?>
					<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" decoding="async">
				<?php endif; ?>
			</div>
			<div class="btp-article__info">
				<p class="btp-article__kicker"><?php echo esc_html( trim( $category . ' · ' . $supplier, ' ·' ) ); ?></p>
				<h2 class="btp-article__title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( $lead ) : ?>
					<p class="btp-article__lead"><?php echo esc_html( $lead ); ?></p>
				<?php endif; ?>
				<div class="btp-article__cta">
					<a class="btp-btn btp-btn--solid" href="<?php echo esc_url( $contact ); ?>"><?php echo esc_html( btp_t( 'quote' ) ); ?></a>
					<a class="btp-btn btp-btn--ghost" href="<?php echo esc_url( $contact ); ?>"><?php echo esc_html( btp_t( 'talk' ) ); ?></a>
				</div>
			</div>
		</section>

		<section class="btp-wrap btp-cols3">
			<div class="btp-col">
				<h3 class="btp-col__title"><?php echo esc_html( btp_t( 'description' ) ); ?></h3>
				<?php if ( '' !== $a['beskrivning'] || ! empty( $a['punkter'] ) ) : ?>
					<?php if ( '' !== $a['beskrivning'] ) : ?><p><?php echo esc_html( $a['beskrivning'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $a['punkter'] ) ) : ?>
						<ul><?php foreach ( $a['punkter'] as $b ) : ?><li><?php echo esc_html( $b ); ?></li><?php endforeach; ?></ul>
					<?php endif; ?>
				<?php else : ?>
					<?php echo wp_kses( wpautop( $sections['besk'] ), $allowed ); ?>
				<?php endif; ?>
			</div>

			<div class="btp-col">
				<h3 class="btp-col__title"><?php echo esc_html( btp_t( 'usage' ) ); ?></h3>
				<?php if ( ! empty( $a['anvandning'] ) ) : ?>
					<?php foreach ( $a['anvandning'] as $heading => $lines ) : ?>
						<div class="btp-block">
							<?php if ( '' !== $heading ) : ?><h4><?php echo esc_html( $heading ); ?></h4><?php endif; ?>
							<?php foreach ( $lines as $line ) : ?><p><?php echo esc_html( $line ); ?></p><?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<?php echo wp_kses( wpautop( $sections['anv'] ), $allowed ); ?>
				<?php endif; ?>
			</div>

			<div class="btp-col">
				<?php if ( ! empty( $a['naring'] ) ) : ?>
					<div class="btp-block">
						<h3 class="btp-col__title"><?php echo esc_html( btp_t( 'nutrients' ) ); ?></h3>
						<p class="btp-nutrients__unit"><?php echo esc_html( btp_t( 'unit' ) ); ?></p>
						<div class="btp-nutrients">
							<?php foreach ( $a['naring'] as $n ) : ?>
								<div class="btp-nutrient btp-tone--<?php echo esc_attr( $n['tone'] ); ?>">
									<span class="btp-nutrient__label"><?php echo esc_html( $n['label'] ); ?></span>
									<span class="btp-nutrient__value"><?php echo esc_html( $n['value'] ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="btp-block">
					<h4><?php echo esc_html( btp_t( 'package' ) ); ?></h4>
					<?php if ( ! empty( $a['forpackning'] ) ) : ?>
						<?php foreach ( $a['forpackning'] as $line ) : ?><p><?php echo esc_html( $line ); ?></p><?php endforeach; ?>
					<?php else : ?>
						<?php echo wp_kses( wpautop( $sections['forp'] ), $allowed ); ?>
					<?php endif; ?>
				</div>

				<div class="btp-block">
					<h4><?php echo esc_html( btp_t( 'sds' ) ); ?></h4>
					<?php if ( $sds ) : ?>
						<div class="btp-sds">
							<?php foreach ( $sds as $text => $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $text ); ?> <span aria-hidden="true">↓</span></a>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p><?php echo esc_html( btp_t( 'sds_missing' ) ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</section>
	</div>
	<?php
	return ob_get_clean();
}
