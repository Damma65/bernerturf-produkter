<?php
/**
 * Katalogen: rubrikfält med sök, sidofilter och produktkort.
 * Allt renderas på servern; produkter.js sköter bara filtreringen i webbläsaren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_flag( $code, $size = 20 ) {
	$names = btp_countries();
	return sprintf(
		'<span class="btp-flag btp-flag--%1$s" style="width:%2$dpx;height:%2$dpx" title="%3$s" role="img" aria-label="%3$s"></span>',
		esc_attr( strtolower( $code ) ),
		absint( $size ),
		esc_attr( isset( $names[ $code ] ) ? $names[ $code ] : $code )
	);
}

function btp_supplier_class( $name ) {
	$known = array( 'barenbrug', 'syngenta', 'indigrow', 'haifa', 'dcm' );
	$slug  = btp_supplier_info( $name )['slug'];
	return in_array( $slug, $known, true ) ? $slug : 'ovriga';
}

/**
 * Egen färg från leverantörens fält "btp_farg" (om satt), som inline-stil.
 */
function btp_supplier_style( $name, $mode ) {
	$color = sanitize_hex_color( btp_supplier_info( $name )['color'] );
	if ( ! $color ) {
		return '';
	}
	return 'dot' === $mode ? 'background:' . $color : 'background:' . $color . ';color:#fff';
}

/**
 * Länk till produktsidan: ny produktsida om den är påslagen, annars temats vanliga sida.
 */
function btp_product_link( $p, $article_page ) {
	if ( empty( $p['artikel'] ) || btp_single_template_on() ) {
		return $p['url'];
	}
	$base = $article_page ? $article_page : get_permalink();
	return add_query_arg( 'produkt', $p['id'], $base ) . '#produkt';
}

function btp_render_catalog( $atts ) {
	$products  = btp_get_products();
	$countries = btp_countries();
	$land      = strtoupper( sanitize_text_field( $atts['land'] ) );
	if ( 'AUTO' === $land ) {
		$land = btp_visitor_country();
	}
	$land      = isset( $countries[ $land ] ) ? $land : '';
	$article_page = esc_url_raw( (string) $atts['artikelsida'] );

	$order      = array();
	foreach ( array_keys( btp_category_table() ) as $key ) {
		$order[] = btp_category_label( $key );
	}
	$used_cats  = array_values( array_filter( array_unique( wp_list_pluck( $products, 'category' ) ) ) );
	usort( $used_cats, function ( $a, $b ) use ( $order ) {
		$ia = array_search( $a, $order, true );
		$ib = array_search( $b, $order, true );
		$ia = false === $ia ? 99 : $ia;
		$ib = false === $ib ? 99 : $ib;
		return $ia === $ib ? strcmp( $a, $b ) : $ia - $ib;
	} );
	$categories = $used_cats;
	$used_sups  = array_unique( wp_list_pluck( $products, 'supplier' ) );
	$js_strings = array(
		'names'       => btp_countries(),
		'from'        => btp_t( 'from' ),
		'in'          => btp_t( 'in' ),
		'approved_in' => btp_t( 'approved_in' ),
	);

	ob_start();
	?>
	<div class="btp btp-catalog" data-btp-catalog data-land="<?php echo esc_attr( $land ); ?>" data-strings="<?php echo esc_attr( wp_json_encode( $js_strings ) ); ?>">
		<header class="btp-hero">
			<div class="btp-wrap btp-hero__inner">
				<div class="btp-hero__text">
					<p class="btp-kicker"><?php echo esc_html( btp_t( 'products' ) ); ?></p>
					<h1 class="btp-hero__title"><?php echo esc_html( $atts['rubrik'] ); ?></h1>
					<?php if ( '' !== trim( $atts['ingress'] ) ) : ?><p class="btp-hero__lead"><?php echo esc_html( $atts['ingress'] ); ?></p><?php endif; ?>
				</div>
				<label class="btp-search">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
					<span class="btp-sr"><?php echo esc_html( btp_t( 'search' ) ); ?></span>
					<input type="search" data-btp-search placeholder="<?php echo esc_attr( btp_t( 'search_ph' ) ); ?>" autocomplete="off">
				</label>
			</div>
		</header>

		<div class="btp-wrap btp-layout">
			<?php
			$chev  = '<svg class="fx-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
			$check = '<span class="fx-box" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="M5 12l5 5 9-10"/></svg></span>';
			?>
			<aside class="btp-filters fx" aria-label="<?php echo esc_attr( btp_t( 'filter' ) ); ?>">
				<div class="fx-head">
					<span class="fx-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg><?php echo esc_html( btp_t( 'filter' ) ); ?></span>
					<button type="button" class="fx-clear" data-btp-reset><?php echo esc_html( btp_t( 'clear' ) ); ?></button>
				</div>

				<details class="fx-group" open>
					<summary><span><?php echo esc_html( btp_t( 'supplier' ) ); ?></span><?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></summary>
					<div class="fx-list">
						<?php foreach ( btp_suppliers() as $s ) : ?>
							<?php
							if ( ! $s['soon'] && ! in_array( $s['name'], $used_sups, true ) ) {
								continue;
							}
							?>
							<button type="button" class="fx-row" data-btp-filter="sup" data-value="<?php echo esc_attr( $s['name'] ); ?>" <?php disabled( $s['soon'] ); ?> aria-pressed="false">
								<?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
								<span class="btp-dot btp-dot--<?php echo esc_attr( btp_supplier_class( $s['name'] ) ); ?>" style="<?php echo esc_attr( btp_supplier_style( $s['name'], 'dot' ) ); ?>"></span>
								<span class="fx-label"><?php echo esc_html( 'ovriga' === $s['slug'] ? btp_t( 'others' ) : $s['name'] ); ?></span>
								<?php if ( $s['soon'] ) : ?>
									<span class="fx-soon"><?php echo esc_html( btp_t( 'soon' ) ); ?></span>
								<?php else : ?>
									<span class="fx-count" data-btp-count></span>
								<?php endif; ?>
							</button>
						<?php endforeach; ?>
					</div>
				</details>

				<details class="fx-group" open>
					<summary><span><?php echo esc_html( btp_t( 'category' ) ); ?></span><?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
					<div class="fx-list">
						<?php foreach ( $categories as $cat ) : ?>
							<button type="button" class="fx-row" data-btp-filter="cat" data-value="<?php echo esc_attr( $cat ); ?>" aria-pressed="false">
								<?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="fx-label"><?php echo esc_html( $cat ); ?></span>
								<span class="fx-count" data-btp-count></span>
							</button>
						<?php endforeach; ?>
					</div>
				</details>

				<details class="fx-group" open>
					<summary><span><?php echo esc_html( btp_t( 'approved' ) ); ?></span><?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
					<p class="fx-hint"><?php echo esc_html( btp_t( 'approved_hint' ) ); ?></p>
					<div class="fx-market">
						<?php foreach ( $countries as $code => $name ) : ?>
							<button type="button" class="fx-mkt" data-btp-filter="land" data-value="<?php echo esc_attr( $code ); ?>" aria-pressed="<?php echo $land === $code ? 'true' : 'false'; ?>">
								<?php echo btp_flag( $code, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in btp_flag(). ?>
								<span class="fx-mkt__txt"><b><?php echo esc_html( $name ); ?></b><small><?php echo esc_html( $code ); ?></small></span>
							</button>
						<?php endforeach; ?>
					</div>
				</details>
			</aside>

			<div class="btp-results">
				<div class="btp-results__bar">
					<p class="btp-results__count" aria-live="polite"><b data-btp-total><?php echo count( $products ); ?></b> <?php echo esc_html( btp_t( 'count' ) ); ?> <span data-btp-summary></span></p>
					<div class="btp-tools">
						<button type="button" class="btp-switch" role="switch" aria-checked="false" data-btp-text>
							<span class="btp-switch__track" aria-hidden="true"><span class="btp-switch__knob"></span></span>
							<?php echo esc_html( btp_t( 'infotext' ) ); ?>
						</button>
						<div class="btp-cols" role="group" aria-label="<?php echo esc_attr( btp_t( 'per_row' ) ); ?>">
							<span><?php echo esc_html( btp_t( 'per_row' ) ); ?></span>
							<div class="btp-cols__btns">
								<?php foreach ( array( 3, 4, 5, 6 ) as $n ) : ?>
									<button type="button" data-btp-cols="<?php echo esc_attr( $n ); ?>" aria-pressed="<?php echo 4 === $n ? 'true' : 'false'; ?>"><?php echo esc_html( $n ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="btp-sort" role="group" aria-label="Sortering">
							<button type="button" data-btp-sort="name" aria-pressed="true"><?php echo esc_html( btp_t( 'sort_az' ) ); ?></button>
							<button type="button" data-btp-sort="sup" aria-pressed="false"><?php echo esc_html( btp_t( 'supplier' ) ); ?></button>
						</div>
					</div>
				</div>

				<div class="btp-grid" data-btp-grid data-btp-shelves style="--btp-cols:4">
					<?php foreach ( $products as $p ) : ?>
						<?php
						$link   = btp_product_link( $p, $article_page );
						$search = strtolower( $p['title'] . ' ' . $p['text'] . ' ' . $p['supplier'] . ' ' . $p['category'] );
						?>
						<article class="btp-card" data-sup="<?php echo esc_attr( $p['supplier'] ); ?>" data-cat="<?php echo esc_attr( $p['category'] ); ?>" data-land="<?php echo esc_attr( implode( ' ', $p['countries'] ) ); ?>" data-name="<?php echo esc_attr( strtolower( $p['title'] ) ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
							<div class="btp-card__media">
								<?php if ( $p['image'] ) : ?>
									<img src="<?php echo esc_url( $p['image'] ); ?>" alt="<?php echo esc_attr( $p['title'] ); ?>" loading="lazy" decoding="async">
								<?php endif; ?>
								<?php if ( 'Övriga' !== $p['supplier'] ) : ?><span class="btp-badge btp-badge--<?php echo esc_attr( btp_supplier_class( $p['supplier'] ) ); ?>"><?php echo esc_html( $p['supplier'] ); ?></span><?php endif; ?>
							</div>
							<div class="btp-card__body">
								<?php if ( $p['category'] ) : ?>
									<p class="btp-card__cat"><?php echo esc_html( $p['category'] ); ?></p>
								<?php endif; ?>
								<h3 class="btp-card__title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $p['title'] ); ?></a></h3>
								<?php if ( $p['text'] ) : ?>
									<p class="btp-card__text" hidden><?php echo esc_html( wp_trim_words( $p['text'], 22, '…' ) ); ?></p>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
				<p class="btp-empty" data-btp-empty hidden><?php echo esc_html( btp_t( 'empty' ) ); ?></p>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
