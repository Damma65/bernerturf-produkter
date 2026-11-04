<?php
/**
 * Rutan "Berner Turf – ny produktsida" på redigeringssidan för varje produkt i WP-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function btp_add_meta_box() {
	add_meta_box( 'btp-produktsida', 'Berner Turf – ny produktsida', 'btp_render_meta_box', 'berner-products', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'btp_add_meta_box' );

function btp_admin_field_help() {
	return array(
		'btp_kategori'    => 'T.ex. "Biostimulant". Tomt = kategorin från produktens kategorier.',
		'btp_ingress'     => 'Texten under produktnamnet.',
		'btp_beskrivning' => 'Stycke överst under fliken Beskrivning.',
		'btp_punkter'     => 'En punkt per rad.',
		'btp_naring'      => 'En rad per ämne: namn | värde | färg (grön, ljusgrön, beige eller grå). Exempel: K | 6,6 | grön',
		'btp_anvandning'  => 'Rad som börjar med # blir rubrik. Exempel: # Appliceringsmängd',
		'btp_forpackning' => 'En förpackning per rad, t.ex. 20 påsar/låda.',
	);
}

function btp_render_meta_box( $post ) {
	wp_nonce_field( 'btp_save_' . $post->ID, 'btp_nonce' );
	$defaults = btp_builtin_defaults();
	$d        = isset( $defaults[ $post->ID ] ) ? $defaults[ $post->ID ] : array();
	$help     = btp_admin_field_help();
	$imported = (string) get_post_meta( $post->ID, 'btp_import_at', true );
	if ( '' !== $imported ) {
		btp_render_imported_box( $post, $imported );
		return;
	}
	$artikel  = get_post_meta( $post->ID, 'btp_artikel', true );
	$checked  = ( '' === $artikel || false === $artikel ) ? ! empty( $d['artikel'] ) : (bool) $artikel;
	?>
	<style>
		.btp-admin { display: grid; gap: 16px; max-width: 900px; }
		.btp-admin label { display: block; font-weight: 600; margin-bottom: 4px; }
		.btp-admin textarea, .btp-admin input[type=text] { width: 100%; }
		.btp-admin .btp-help { color: #646970; font-size: 12px; margin: 4px 0 0; }
		.btp-admin .btp-toggle { display: flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 12px; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; }
	</style>
	<div class="btp-admin">
		<label class="btp-toggle"><input type="checkbox" name="btp_artikel" value="1" <?php checked( $checked ); ?>> Visa den nya produktsidan för den här produkten (katalogkortet länkar dit)</label>
		<?php foreach ( btp_meta_fields() as $key => $cfg ) : ?>
			<?php
			if ( 'boolean' === $cfg['type'] ) {
				continue;
			}
			$short = substr( $key, 4 );
			$value = (string) get_post_meta( $post->ID, $key, true );
			$ph    = isset( $d[ $short ] ) ? $d[ $short ] : '';
			$rows  = in_array( $key, array( 'btp_kategori' ), true ) ? 0 : ( in_array( $key, array( 'btp_punkter', 'btp_anvandning', 'btp_naring' ), true ) ? 6 : 3 );
			?>
			<div>
				<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $cfg['label'] ); ?></label>
				<?php if ( 0 === $rows ) : ?>
					<input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $ph ); ?>">
				<?php else : ?>
					<textarea id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="<?php echo esc_attr( $rows ); ?>" placeholder="<?php echo esc_attr( $ph ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
				<?php endif; ?>
				<?php if ( isset( $help[ $key ] ) ) : ?><p class="btp-help"><?php echo esc_html( $help[ $key ] ); ?></p><?php endif; ?>
			</div>
		<?php endforeach; ?>
		<p class="btp-help">Tomma fält visar produktens befintliga text. Ändringar syns direkt efter att du sparat – ingen ny pluginversion behövs.</p>
	</div>
	<?php
}

/**
 * Produkter som kommer från Excel: visa vad som gäller, ändringar görs i Excel.
 */
function btp_render_imported_box( $post, $imported ) {
	$url  = admin_url( 'edit.php?post_type=berner-products&page=btp-import' );
	$vis  = btp_visibility( $post->ID );
	$vis  = empty( $vis ) ? '–' : implode( ', ', $vis );
	$rows = array();
	foreach ( btp_languages() as $l => $name ) {
		$parts = array();
		foreach ( btp_text_fields() as $f => $label ) {
			$v = get_post_meta( $post->ID, 'btp_' . $f . '_' . $l, true );
			if ( '' !== (string) $v ) {
				$parts[] = $label . ( get_post_meta( $post->ID, 'btp_' . $f . '_' . $l . '_ai', true ) ? ' (Claude)' : '' );
			}
		}
		$rows[ $name ] = $parts ? implode( ', ', $parts ) : '–';
	}
	?>
	<div style="padding:12px 14px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:4px;max-width:900px">
		<p style="margin-top:0"><strong>Styrs från Excel.</strong> Senaste import: <?php echo esc_html( $imported ); ?>. Ändra texter, bilder, säkerhetsdatablad och synlighet i Excel-filen och importera på nytt – ändringar här skrivs över.</p>
		<p><strong>Visas på:</strong> <?php echo esc_html( $vis ); ?></p>
		<table class="widefat striped" style="max-width:860px"><tbody>
			<?php foreach ( $rows as $name => $txt ) : ?>
				<tr><th style="width:110px"><?php echo esc_html( $name ); ?></th><td><?php echo esc_html( $txt ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>
		<p style="margin-bottom:0"><a class="button" href="<?php echo esc_url( $url ); ?>">Till importen</a></p>
	</div>
	<?php
}

function btp_save_meta_box( $post_id ) {
	if ( '' !== (string) get_post_meta( $post_id, 'btp_import_at', true ) ) {
		return;
	}
	if ( ! isset( $_POST['btp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['btp_nonce'] ) ), 'btp_save_' . $post_id ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	update_post_meta( $post_id, 'btp_artikel', ! empty( $_POST['btp_artikel'] ) ? 1 : 0 );
	foreach ( btp_meta_fields() as $key => $cfg ) {
		if ( 'boolean' === $cfg['type'] ) {
			continue;
		}
		$value = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
add_action( 'save_post_berner-products', 'btp_save_meta_box' );
