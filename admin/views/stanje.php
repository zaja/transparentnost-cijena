<?php
/**
 * Ekran STANJE — zadani ekran.
 *
 * Odgovara na jedno pitanje: radi li sve.
 *
 * Kad radi, ekran je kratak i dosadan. To je namjerno: administrator koji svaki
 * dan vidi puno ekran prestane ga citati, pa mu promakne i dan kad nesto ne radi.
 *
 * @var CJTR\Nalazi\Nalaz[] $nalazi
 * @var array  $cjenik
 * @var bool   $danas
 * @var bool   $ceka
 * @var int    $sidrene
 * @var int    $katalog
 * @var string $obavijest
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Cjenik\Arhiva;
use CJTR\Config;
use CJTR\Nalazi\Nalaz;
use CJTR\Postavke;

defined( 'ABSPATH' ) || exit;

$ima_cjenik = is_array( $cjenik ) && ! empty( $cjenik['kad'] );
$sve_radi   = empty( $nalazi );
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Stanje', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<?php // --- jedna recenica na vrhu: radi li sve --- ?>
	<div class="<?php echo esc_attr( Config::css( 'sazetak' ) . ' ' . Config::css( $sve_radi ? 'sazetak-ok' : 'sazetak-radi' ) ); ?>">
		<p class="<?php echo esc_attr( Config::css( 'sazetak-tekst' ) ); ?>">
			<?php
			if ( $sve_radi ) {
				esc_html_e( 'Sve radi. Nema nista za napraviti.', Config::TEXT_DOMAIN );
			} else {
				$zapreka = 0;
				foreach ( $nalazi as $n ) {
					if ( Nalaz::ZAPREKA === $n->vaznost ) {
						$zapreka++;
					}
				}

				if ( $zapreka > 0 ) {
					printf(
						/* translators: %d = broj stvari koje zaustavljaju */
						esc_html( _n( 'Jedna stvar zaustavlja objavu cjenika.', '%d stvari zaustavljaju objavu cjenika.', $zapreka, Config::TEXT_DOMAIN ) ),
						(int) $zapreka
					);
				} else {
					printf(
						/* translators: %d = broj nalaza */
						esc_html( _n( 'Cjenik izlazi. Jedna stvar vrijedi pogledati.', 'Cjenik izlazi. %d stvari vrijedi pogledati.', count( $nalazi ), Config::TEXT_DOMAIN ) ),
						count( $nalazi )
					);
				}
			}
			?>
		</p>
	</div>

	<?php // --- tri statusna retka: cjenik, adresa, prikaz --- ?>
	<table class="<?php echo esc_attr( Config::css( 'stanje-redci' ) ); ?>">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cjenik', Config::TEXT_DOMAIN ); ?></th>
				<td>
					<?php if ( $danas ) : ?>
						<span class="<?php echo esc_attr( Config::css( 'znak-ok' ) ); ?>" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: 1: vrijeme, 2: broj artikala */
							esc_html__( 'objavljen jutros u %1$s, %2$s artikala', Config::TEXT_DOMAIN ),
							esc_html( wp_date( 'H:i', (int) $cjenik['kad'] ) ),
							esc_html( number_format_i18n( (int) ( $cjenik['zapisa'] ?? 0 ) ) )
						);
						?>
					<?php elseif ( $ceka ) : ?>
						<?php
						// Prije zakazanog vremena ovo NIJE problem nego cekanje. Crveni
						// redak u pet ujutro nauci administratora da crveno ne znaci nista.
						printf(
							/* translators: %s = vrijeme */
							esc_html__( 'objava je zakazana za %s', Config::TEXT_DOMAIN ),
							esc_html( wp_date( 'H:i', Postavke::ocekivano_do() ) )
						);
						?>
						<?php if ( $ima_cjenik ) : ?>
							<span class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
								<?php
								printf(
									/* translators: %s = datum i vrijeme */
									esc_html__( '(zadnji je od %s)', Config::TEXT_DOMAIN ),
									esc_html( wp_date( 'j.n. H:i', (int) $cjenik['kad'] ) )
								);
								?>
							</span>
						<?php endif; ?>
					<?php else : ?>
						<span class="<?php echo esc_attr( Config::css( 'znak-lose' ) ); ?>" aria-hidden="true"></span>
						<?php esc_html_e( 'nije objavljen danas', Config::TEXT_DOMAIN ); ?>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( $ima_cjenik ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Javna adresa', Config::TEXT_DOMAIN ); ?></th>
					<td>
						<?php
						// Adresa mora biti i klikabilna i kopirljiva: jedni je otvaraju da
						// provjere radi li, drugi je salju dalje.
						$adresa = Arhiva::stabilni_url( Config::CJENIK_OBLICI[0] );
						?>
						<input
							type="text"
							class="<?php echo esc_attr( Config::css( 'adresa' ) ); ?>"
							value="<?php echo esc_attr( $adresa ); ?>"
							readonly
							onclick="this.select();"
						>
						<a href="<?php echo esc_url( $adresa ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'otvori', Config::TEXT_DOMAIN ); ?>
						</a>
						<?php foreach ( array_slice( Config::CJENIK_OBLICI, 1 ) as $oblik ) : ?>
							&middot;
							<a href="<?php echo esc_url( Arhiva::stabilni_url( $oblik ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( strtoupper( $oblik ) ); ?>
							</a>
						<?php endforeach; ?>
						&middot;
						<a href="<?php echo esc_url( Arhiva::indeks_url() ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'arhiva', Config::TEXT_DOMAIN ); ?>
						</a>
					</td>
				</tr>
			<?php endif; ?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Sidrena cijena', Config::TEXT_DOMAIN ); ?></th>
				<td>
					<?php if ( $sidrene > 0 ) : ?>
						<span class="<?php echo esc_attr( Config::css( $sidrene >= $katalog ? 'znak-ok' : 'znak-djelomicno' ) ); ?>" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: 1: koliko ih ima, 2: ukupno */
							esc_html__( 'prikazuje se na %1$s od %2$s artikala i varijanti', Config::TEXT_DOMAIN ),
							esc_html( number_format_i18n( $sidrene ) ),
							esc_html( number_format_i18n( $katalog ) )
						);
						?>
					<?php else : ?>
						<span class="<?php echo esc_attr( Config::css( 'znak-lose' ) ); ?>" aria-hidden="true"></span>
						<?php esc_html_e( 'jos se ne prikazuje ni na jednom artiklu', Config::TEXT_DOMAIN ); ?>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Dnevno pokretanje', Config::TEXT_DOMAIN ); ?></th>
				<td>
					<?php if ( CJTR\Nalazi\Nalazi::cron_radi() ) : ?>
						<span class="<?php echo esc_attr( Config::css( 'znak-ok' ) ); ?>" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: %s = vrijeme */
							esc_html__( 'radi, svaki dan do %s', Config::TEXT_DOMAIN ),
							esc_html( sprintf( '%02d:00', Postavke::rok_objave_sat() ) )
						);
						?>
					<?php else : ?>
						<span class="<?php echo esc_attr( Config::css( 'znak-lose' ) ); ?>" aria-hidden="true"></span>
						<?php esc_html_e( 'nije postavljeno — vidi nize', Config::TEXT_DOMAIN ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<?php // --- nalazi --- ?>
	<?php if ( ! $sve_radi ) : ?>
		<h2 class="<?php echo esc_attr( Config::css( 'nalazi-naslov' ) ); ?>">
			<?php esc_html_e( 'Sto treba rijesiti', Config::TEXT_DOMAIN ); ?>
		</h2>

		<div class="<?php echo esc_attr( Config::css( 'nalazi' ) ); ?>">
			<?php foreach ( $nalazi as $nalaz ) : ?>
				<?php require CJTR_DIR . 'admin/views/dijelovi/nalaz.php'; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p class="<?php echo esc_attr( Config::css( 'podnozje-veze' ) ); ?>">
		<a href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI ) ); ?>"><?php esc_html_e( 'Artikli', Config::TEXT_DOMAIN ); ?></a>
		&middot;
		<a href="<?php echo esc_url( Admin::url( Admin::STRANICA_POSTAVKE ) ); ?>"><?php esc_html_e( 'Postavke', Config::TEXT_DOMAIN ); ?></a>
		&middot;
		<a href="<?php echo esc_url( Admin::url( Admin::STRANICA_NAPREDNO ) ); ?>"><?php esc_html_e( 'Napredno', Config::TEXT_DOMAIN ); ?></a>
	</p>

	<?php Admin::opseg(); ?>
</div>
