<?php
/**
 * Ekran dijagnostike.
 *
 * @var CJTR\Diagnostika\Rezultat[] $rezultati
 * @var string                      $tekst
 * @package CJTR
 */

use CJTR\Config;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

$runner_ukupno = ( new CJTR\Diagnostika\Runner() )->ukupno( $rezultati );

$naslovi = array(
	Rezultat::OK   => __( 'Sve je spremno', Config::TEXT_DOMAIN ),
	Rezultat::UPOZ => __( 'Radi, ali ima stavki za srediti', Config::TEXT_DOMAIN ),
	Rezultat::LOSE => __( 'Ima stavki koje treba rijesiti', Config::TEXT_DOMAIN ),
	Rezultat::INFO => __( 'Izmjereno', Config::TEXT_DOMAIN ),
);
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Dijagnostika okoline', Config::TEXT_DOMAIN ); ?></h1>

	<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
		<?php esc_html_e( 'Ova stranica samo mjeri i javlja. Ne mijenja nista u trgovini i ne dira cijene.', Config::TEXT_DOMAIN ); ?>
	</p>

	<div class="<?php echo esc_attr( Config::css( 'skupno' ) . ' ' . Config::css( 'status-' . $runner_ukupno ) ); ?>">
		<strong><?php echo esc_html( $naslovi[ $runner_ukupno ] ?? '' ); ?></strong>
	</div>

	<?php foreach ( $rezultati as $r ) : ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( 'status-' . $r->status ) ); ?>">

			<h2>
				<span class="<?php echo esc_attr( Config::css( 'znak' ) ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $r->naslov ); ?>
				<span class="<?php echo esc_attr( Config::css( 'oznaka' ) ); ?>"><?php echo esc_html( $r->oznaka() ); ?></span>
			</h2>

			<?php if ( ! empty( $r->stavke ) ) : ?>
				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<tbody>
					<?php foreach ( $r->stavke as $par ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $par[0] ); ?></th>
							<td><?php echo esc_html( $par[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php foreach ( $r->blokovi as $naslov => $sadrzaj ) : ?>
				<div class="<?php echo esc_attr( Config::css( 'blok' ) ); ?>">
					<h3><?php echo esc_html( $naslov ); ?></h3>
					<pre><?php echo esc_html( $sadrzaj ); ?></pre>
				</div>
			<?php endforeach; ?>

			<?php if ( '' !== $r->znacenje ) : ?>
				<p class="<?php echo esc_attr( Config::css( 'znacenje' ) ); ?>">
					<strong><?php esc_html_e( 'Sto to znaci:', Config::TEXT_DOMAIN ); ?></strong>
					<?php echo esc_html( $r->znacenje ); ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $r->postupak ) : ?>
				<p class="<?php echo esc_attr( Config::css( 'postupak' ) ); ?>">
					<strong><?php esc_html_e( 'Sto uciniti:', Config::TEXT_DOMAIN ); ?></strong>
					<?php echo esc_html( $r->postupak ); ?>
				</p>
			<?php endif; ?>

		</div>
	<?php endforeach; ?>

	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Izvjestaj za slanje', Config::TEXT_DOMAIN ); ?></h2>
		<p><?php esc_html_e( 'Pritisnite gumb pa zalijepite sadrzaj u poruku.', Config::TEXT_DOMAIN ); ?></p>
		<p>
			<button type="button" class="button button-primary" id="<?php echo esc_attr( Config::css( 'kopiraj' ) ); ?>">
				<?php esc_html_e( 'Kopiraj izvjestaj', Config::TEXT_DOMAIN ); ?>
			</button>
			<span id="<?php echo esc_attr( Config::css( 'kopirano' ) ); ?>" class="<?php echo esc_attr( Config::css( 'kopirano' ) ); ?>" role="status"></span>
		</p>
		<textarea id="<?php echo esc_attr( Config::css( 'izvjestaj' ) ); ?>" rows="14" readonly><?php echo esc_textarea( $tekst ); ?></textarea>
	</div>


	<?php CJTR\Admin::opseg(); ?>
</div>

<script>
( function () {
	var gumb = document.getElementById( '<?php echo esc_js( Config::css( 'kopiraj' ) ); ?>' );
	var polje = document.getElementById( '<?php echo esc_js( Config::css( 'izvjestaj' ) ); ?>' );
	var poruka = document.getElementById( '<?php echo esc_js( Config::css( 'kopirano' ) ); ?>' );
	if ( ! gumb || ! polje ) { return; }

	gumb.addEventListener( 'click', function () {
		var gotovo = function () {
			poruka.textContent = <?php echo wp_json_encode( __( 'Kopirano.', Config::TEXT_DOMAIN ) ); ?>;
			window.setTimeout( function () { poruka.textContent = ''; }, 4000 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( polje.value ).then( gotovo, function () {
				polje.select(); document.execCommand( 'copy' ); gotovo();
			} );
		} else {
			polje.select(); document.execCommand( 'copy' ); gotovo();
		}
	} );
}() );
</script>
