<?php
/**
 * NAPREDNO — sve sto administrator ne treba svaki dan.
 *
 * Nije skladiste nego raspucaj: svaka stavka kaze cemu sluzi, jer onaj tko je
 * otvori vec ne zna gdje je ono sto trazi.
 *
 * @var string $obavijest
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Carobnjak;
use CJTR\Config;
use CJTR\Nalazi\Nalazi;
use CJTR\Poslovi\Registar;
use CJTR\Poslovi\Stanje;

defined( 'ABSPATH' ) || exit;

$u_tijeku = 0;
foreach ( Registar::svi() as $kljuc => $posao ) {
	if ( Stanje::ucitaj( $kljuc )->radi() ) {
		$u_tijeku++;
	}
}
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Napredno', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
		<?php esc_html_e( 'Ovdje je sve sto ne treba svaki dan. Ako ste dosli jer nesto ne radi, prvo pogledajte Stanje — ondje pise sto i zasto.', Config::TEXT_DOMAIN ); ?>
	</p>

	<div class="<?php echo esc_attr( Config::css( 'napredno-popis' ) ); ?>">

		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><a href="<?php echo esc_url( Admin::url( Admin::STRANICA_POSLOVI ) ); ?>"><?php esc_html_e( 'Obrada u pozadini', Config::TEXT_DOMAIN ); ?></a></h2>
			<p>
				<?php esc_html_e( 'Sto se trenutno obraduje, koliko je gotovo, i zapisnik svakog prolaza. Ovdje se posao moze pokrenuti ponovno ili ponistiti.', Config::TEXT_DOMAIN ); ?>
				<?php if ( $u_tijeku > 0 ) : ?>
					<strong>
						<?php
						printf(
							/* translators: %d = broj poslova */
							esc_html( _n( 'Trenutno radi %d obrada.', 'Trenutno rade %d obrade.', $u_tijeku, Config::TEXT_DOMAIN ) ),
							(int) $u_tijeku
						);
						?>
					</strong>
				<?php endif; ?>
			</p>
		</div>

		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><a href="<?php echo esc_url( Admin::url( Admin::STRANICA_DIJAGNOSTIKA ) ); ?>"><?php esc_html_e( 'Dijagnostika', Config::TEXT_DOMAIN ); ?></a></h2>
			<p>
				<?php esc_html_e( 'Jedanaest provjera na vasem posluzitelju: okolina, vremenske zone, kapacitet, dostupnost izvana, tko jos dira cijene, i racuni koje dodatak sam nad sobom provjerava. Otvorite kad nesto ne radi a ne zna se zasto — ili kad vam to zatrazimo.', Config::TEXT_DOMAIN ); ?>
			</p>
			<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
				<?php esc_html_e( 'Ondje je i gotov redak za dnevno pokretanje, ako ga treba ponovno preracunati.', Config::TEXT_DOMAIN ); ?>
			</p>
		</div>

		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><a href="<?php echo esc_url( Admin::url( Admin::STRANICA_PREGLED ) ); ?>"><?php esc_html_e( 'Odakle dolazi svaka sidrena cijena', Config::TEXT_DOMAIN ); ?></a></h2>
			<p>
				<?php esc_html_e( 'Razrada po izvorima: koja je vrijednost izmjerena iz zapisa o cijenama, koja uvezena, koja upisana rukom, a koja je vasa izjava. Odgovara na pitanje "odakle ta brojka" za svaki artikl.', Config::TEXT_DOMAIN ); ?>
			</p>
		</div>

		<?php if ( null !== Registar::nadi( 'promocija' ) ) : ?>
			<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
				<h2><a href="<?php echo esc_url( Admin::url( Admin::STRANICA_PROMOCIJA ) ); ?>"><?php esc_html_e( 'Akcijska cijena u redovnu', Config::TEXT_DOMAIN ); ?></a></h2>
				<p>
					<?php esc_html_e( 'Pregled artikala kojima akcija traje toliko dugo da je zapravo postala redovna cijena. Ekran nista ne mijenja — pokazuje sto bi se promijenilo i trazi potvrdu.', Config::TEXT_DOMAIN ); ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Ponovi prvo postavljanje', Config::TEXT_DOMAIN ); ?></h2>
			<p>
				<?php esc_html_e( 'Prolazi kroz iste cetiri stavke kao pri instalaciji. Nista ne brise — samo ponovno postavlja pitanja. Korisno kad se trgovina proda, administrator promijeni, ili se pocne prodavati nesto drugo.', Config::TEXT_DOMAIN ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'napredno' ) ); ?>
				<button class="button" name="radnja" value="ponovi_carobnjaka">
					<?php esc_html_e( 'Otvori prvo postavljanje', Config::TEXT_DOMAIN ); ?>
				</button>
				<?php if ( Carobnjak::gotov() ) : ?>
					<span class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
						<?php esc_html_e( 'Vec je jednom prodeno.', Config::TEXT_DOMAIN ); ?>
					</span>
				<?php endif; ?>
			</form>
		</div>

		<?php if ( ! Nalazi::cron_radi() ) : ?>
			<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
				<h2><?php esc_html_e( 'Redak za dnevno pokretanje', Config::TEXT_DOMAIN ); ?></h2>
				<p><?php esc_html_e( 'Zalijepite ga u cPanel, u dio Cron Jobs.', Config::TEXT_DOMAIN ); ?></p>
				<textarea class="<?php echo esc_attr( Config::css( 'nalaz-doslovno' ) ); ?>" rows="2" readonly
					onclick="this.select();"><?php echo esc_textarea( Nalazi::cpanel_redak() ); ?></textarea>
			</div>
		<?php endif; ?>
	</div>

	<p class="<?php echo esc_attr( Config::css( 'podnozje-veze' ) ); ?>">
		<a href="<?php echo esc_url( Admin::url( Admin::STRANICA_STANJE ) ); ?>"><?php esc_html_e( 'Natrag na stanje', Config::TEXT_DOMAIN ); ?></a>
	</p>

	<?php Admin::opseg(); ?>
</div>
