<?php
/**
 * Carobnjak za prvo postavljanje.
 *
 * Svaki korak kaze ZASTO, ne samo sto. "Unesite referentni datum" je naredba koju
 * korisnik izvrsi ne znajuci sto radi; pitanje na koje se moze odgovoriti daje
 * tocan odgovor i kad se trgovina razlikuje od pretpostavljene.
 *
 * @var int    $korak
 * @var array  $koraci
 * @var string $obavijest
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Carobnjak;
use CJTR\Config;
use CJTR\Nalazi\Nalazi;
use CJTR\Postavke;
use CJTR\Preuzimanje;

defined( 'ABSPATH' ) || exit;

$meta = $koraci[ $korak ] ?? array(
	'naslov' => '',
	'zasto'  => '',
);
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) . ' ' . Config::css( 'carobnjak' ) ); ?>">

	<h1><?php esc_html_e( 'Prvo postavljanje', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<ol class="<?php echo esc_attr( Config::css( 'koraci' ) ); ?>">
		<?php foreach ( $koraci as $broj => $k ) : ?>
			<li class="<?php echo esc_attr( Config::css( $broj === $korak ? 'korak-tekuci' : ( $broj < $korak ? 'korak-gotov' : 'korak-ceka' ) ) ); ?>">
				<?php echo esc_html( $k['naslov'] ); ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( 'kartica-glavna' ) ); ?>">

		<h2><?php echo esc_html( $meta['naslov'] ); ?></h2>

		<p class="<?php echo esc_attr( Config::css( 'zasto' ) ); ?>">
			<?php echo esc_html( $meta['zasto'] ); ?>
		</p>

		<?php if ( Carobnjak::KORAK_POSLUZITELJ === $korak ) : ?>

			<div class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
				<strong><?php esc_html_e( 'Prvo ono sto vas vjerojatno zanima:', Config::TEXT_DOMAIN ); ?></strong>
				<?php esc_html_e( 'ovaj dodatak ne mijenja vase cijene. Ni jednu, nikad. Ne mijenja ni nazive, opise, kategorije ni zalihe — pise iskljucivo u vlastite tablice. Obrisete li ga, trgovina je tocno onakva kakva je bila prije.', Config::TEXT_DOMAIN ); ?>
				<br>
				<?php esc_html_e( 'Ono sto radi: cita vase cijene, vodi evidenciju o njima, i objavljuje datoteku koju propis trazi.', Config::TEXT_DOMAIN ); ?>
			</div>

			<?php
			$provjere = array(
				array(
					'naziv'  => __( 'Mozemo zapisati datoteku', Config::TEXT_DOMAIN ),
					'ok'     => \CJTR\Diagnostika\Rezultat::LOSE !== ( new \CJTR\Diagnostika\Provjere\Zapisivost() )->izvrsi()->status,
					'ako_ne' => __( 'Mapa wp-content/uploads nije otvorena za pisanje. Zatrazite od hostinga dozvolu 755.', Config::TEXT_DOMAIN ),
				),
				array(
					'naziv'  => __( 'Obrada u pozadini radi', Config::TEXT_DOMAIN ),
					'ok'     => null !== \CJTR\Poslovi\Registar::nadi( 'prebroji' ),
					'ako_ne' => __( 'Javite nam — bez toga se katalog ne moze obraditi u komadima.', Config::TEXT_DOMAIN ),
				),
				array(
					'naziv'  => __( 'Nista drugo ne mijenja cijene u hodu', Config::TEXT_DOMAIN ),
					'ok'     => \CJTR\Cijene\Straza::provjeri( false )->prolazi,
					'ako_ne' => __( 'Drugi dodatak mijenja cijenu izmedu baze i kupca. Dok je tako, objavljeni cjenik ne bi govorio istinu.', Config::TEXT_DOMAIN ),
				),
			);
			$sve_ok = true;
			?>

			<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
				<tbody>
					<?php foreach ( $provjere as $p ) : ?>
						<?php $sve_ok = $sve_ok && $p['ok']; ?>
						<tr>
							<th scope="row"><?php echo esc_html( $p['naziv'] ); ?></th>
							<td>
								<?php if ( $p['ok'] ) : ?>
									<span class="<?php echo esc_attr( Config::css( 'znak-ok' ) ); ?>" aria-hidden="true"></span>
									<?php esc_html_e( 'u redu', Config::TEXT_DOMAIN ); ?>
								<?php else : ?>
									<span class="<?php echo esc_attr( Config::css( 'znak-lose' ) ); ?>" aria-hidden="true"></span>
									<?php echo esc_html( $p['ako_ne'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! $sve_ok ) : ?>
				<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
					<?php esc_html_e( 'Mozete nastaviti, ali ovo treba rijesiti prije nego cjenik izade prvi put.', Config::TEXT_DOMAIN ); ?>
				</p>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'carobnjak' ) ); ?>
				<button class="button button-primary" name="radnja" value="dalje">
					<?php esc_html_e( 'Dalje', Config::TEXT_DOMAIN ); ?>
				</button>
			</form>

		<?php elseif ( Carobnjak::KORAK_CRON === $korak ) : ?>

			<?php $redak = Nalazi::cpanel_redak(); ?>

			<?php if ( Nalazi::cron_radi() ) : ?>
				<p>
					<span class="<?php echo esc_attr( Config::css( 'znak-ok' ) ); ?>" aria-hidden="true"></span>
					<?php esc_html_e( 'Vec je postavljeno. Nemate sto raditi.', Config::TEXT_DOMAIN ); ?>
				</p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Sto uciniti:', Config::TEXT_DOMAIN ); ?></strong>
					<?php esc_html_e( 'U cPanelu otvorite Cron Jobs, kliknite Add New Cron Job, zalijepite ovaj redak i spremite.', Config::TEXT_DOMAIN ); ?>
				</p>

				<textarea class="<?php echo esc_attr( Config::css( 'nalaz-doslovno' ) ); ?>" rows="2" readonly
					onclick="this.select();"><?php echo esc_textarea( $redak ); ?></textarea>

				<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
					<?php esc_html_e( 'Redak je vec preracunat za vremensku zonu vaseg posluzitelja, i za ljeto i za zimu. Odmah ispod naslova cPanel pise trenutno vrijeme posluzitelja — ako se jako razlikuje od vremena u retku, javite nam.', Config::TEXT_DOMAIN ); ?>
				</p>

				<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
					<?php esc_html_e( 'Ovo je jedini korak koji se ne moze odraditi iz WordPressa. Mozete ga preskociti i vratiti se poslije — cjenik ce do tada izlaziti neredovito.', Config::TEXT_DOMAIN ); ?>
				</p>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'carobnjak' ) ); ?>
				<button class="button" name="radnja" value="natrag"><?php esc_html_e( 'Natrag', Config::TEXT_DOMAIN ); ?></button>
				<button class="button button-primary" name="radnja" value="dalje"><?php esc_html_e( 'Dalje', Config::TEXT_DOMAIN ); ?></button>
			</form>

		<?php elseif ( Carobnjak::KORAK_DATUM === $korak ) : ?>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'carobnjak' ) ); ?>

				<p class="<?php echo esc_attr( Config::css( 'pitanje' ) ); ?>">
					<label>
						<input type="checkbox" name="ima_regulirane" value="1"
							<?php checked( (bool) Postavke::daj( Postavke::IMA_REGULIRANE ) ); ?>>
						<strong><?php esc_html_e( 'Prodajete li hranu, pice, kozmetiku, sredstva za ciscenje, toaletne potrepstine ili proizvode za kucanstvo?', Config::TEXT_DOMAIN ); ?></strong>
					</label>
				</p>

				<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
					<?php esc_html_e( 'Za te skupine vrijedi raniji datum nego za sve ostalo. Prodajete li i jedno i drugo, imate dva datuma — a to se lako previdi jer nista na to ne upozorava.', Config::TEXT_DOMAIN ); ?>
				</p>

				<table class="<?php echo esc_attr( Config::css( 'postavke' ) ); ?>">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Za vecinu proizvoda', Config::TEXT_DOMAIN ); ?></th>
							<td>
								<input type="date" name="<?php echo esc_attr( Postavke::REF_OSTALO ); ?>"
									value="<?php echo esc_attr( (string) Postavke::daj( Postavke::REF_OSTALO ) ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Za skupine iz pitanja', Config::TEXT_DOMAIN ); ?></th>
							<td>
								<input type="date" name="<?php echo esc_attr( Postavke::REF_REGULIRANE ); ?>"
									value="<?php echo esc_attr( (string) Postavke::daj( Postavke::REF_REGULIRANE ) ); ?>">
							</td>
						</tr>
					</tbody>
				</table>

				<p>
					<button class="button" name="radnja" value="natrag"><?php esc_html_e( 'Natrag', Config::TEXT_DOMAIN ); ?></button>
					<button class="button button-primary" name="radnja" value="datum"><?php esc_html_e( 'Spremi i dalje', Config::TEXT_DOMAIN ); ?></button>
				</p>
			</form>

		<?php else : ?>

			<?php
			$tuda      = Carobnjak::tuda_povijest();
			$bez       = Carobnjak::bez_dodatne();
			$ref_datum = wp_date( 'j.n.Y.', Config::t_ref( Postavke::ref_datum() ) );
			?>

			<?php if ( $bez < 1 ) : ?>
				<p>
					<span class="<?php echo esc_attr( Config::css( 'znak-ok' ) ); ?>" aria-hidden="true"></span>
					<?php esc_html_e( 'Svi artikli vec imaju sidrenu cijenu. Nemate sto raditi.', Config::TEXT_DOMAIN ); ?>
				</p>
			<?php else : ?>

				<p>
					<?php
					printf(
						/* translators: 1: broj artikala, 2: referentni datum */
						esc_html__( 'Za %1$s artikala jos ne znamo koliko je cijena iznosila %2$s. Postoje tri nacina da to rijesite; mozete ih i kombinirati.', Config::TEXT_DOMAIN ),
						esc_html( number_format_i18n( $bez ) ),
						esc_html( $ref_datum )
					);
					?>
				</p>

				<?php /* --- a) tuda povijest --- */ ?>
				<?php if ( $tuda['ima'] ) : ?>
					<div class="<?php echo esc_attr( Config::css( 'ponuda' ) ); ?>">
						<h3><?php esc_html_e( 'Nasli smo povijest cijena iz drugog dodatka', Config::TEXT_DOMAIN ); ?></h3>

						<p>
							<?php
							printf(
								/* translators: 1: ime dodatka, 2: broj zapisa, 3: broj artikala, 4: datum */
								esc_html__( 'Dodatak %1$s vodi %2$s zapisa o cijenama, za %3$s artikala, od %4$s. Iz toga se sidrena cijena moze izmjeriti, a ne pretpostaviti.', Config::TEXT_DOMAIN ),
								esc_html( $tuda['dodatak'] ),
								esc_html( number_format_i18n( $tuda['zapisa'] ) ),
								esc_html( number_format_i18n( $tuda['entiteta'] ) ),
								esc_html( $tuda['od'] )
							);
							?>
						</p>

						<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
							<strong><?php esc_html_e( 'Prvo preuzmite tu tablicu kao datoteku.', Config::TEXT_DOMAIN ); ?></strong>
							<?php esc_html_e( 'Preporucujemo to bez obzira na to sto odlucite dalje. Tablica pripada drugom dodatku — nestane li on, nestaje i ona, a ona je jedini dokaz koliko je cijena iznosila u proslosti. Ovo je jedini trenutak u kojem imate oboje.', Config::TEXT_DOMAIN ); ?>
						</p>

						<p>
							<a class="button" href="<?php echo esc_url( Preuzimanje::url( Preuzimanje::POPIS_TUDA_POVIJEST ) ); ?>">
								<?php esc_html_e( 'Preuzmi kao datoteku', Config::TEXT_DOMAIN ); ?>
							</a>
						</p>

						<form method="post">
							<?php wp_nonce_field( Config::nonce( 'carobnjak' ) ); ?>
							<button class="button button-primary" name="radnja" value="preuzmi_povijest">
								<?php esc_html_e( 'Preuzmi povijest u nas dodatak', Config::TEXT_DOMAIN ); ?>
							</button>
						</form>
					</div>
				<?php endif; ?>

				<?php /* --- b) uvoz iz programa --- */ ?>
				<div class="<?php echo esc_attr( Config::css( 'ponuda' ) ); ?>">
					<h3><?php esc_html_e( 'Uvoz iz vaseg programa', Config::TEXT_DOMAIN ); ?></h3>
					<p>
						<?php esc_html_e( 'Ako program koji vodi vasu robu zna izvesti cijene na odredeni datum, to je najtocniji izvor. Uvoz prima tablicu sa stupcem za sidrenu cijenu.', Config::TEXT_DOMAIN ); ?>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI ) ); ?>">
							<?php esc_html_e( 'Otvori uvoz', Config::TEXT_DOMAIN ); ?>
						</a>
					</p>
				</div>

				<?php /* --- c) izjava trgovca --- */ ?>
				<div class="<?php echo esc_attr( Config::css( 'ponuda' ) . ' ' . Config::css( 'ponuda-izjava' ) ); ?>">
					<h3><?php esc_html_e( 'Nemate nista od toga', Config::TEXT_DOMAIN ); ?></h3>

					<p>
						<?php
						printf(
							/* translators: 1: broj artikala, 2: datum */
							esc_html__( 'Mozete potvrditi da su danasnje cijene ujedno i cijene od %2$s. To je VASA IZJAVA, ne nas izracun — dodatak nema nacin provjeriti je li tocna.', Config::TEXT_DOMAIN ),
							esc_html( number_format_i18n( $bez ) ),
							esc_html( $ref_datum )
						);
						?>
					</p>

					<form method="post">
						<?php wp_nonce_field( Config::nonce( 'carobnjak' ) ); ?>

						<p class="<?php echo esc_attr( Config::css( 'izjava-tekst' ) ); ?>">
							<label>
								<input type="checkbox" name="potvrdujem" value="1">
								<?php
								printf(
									/* translators: 1: broj artikala, 2: datum */
									esc_html__( 'Potvrdujem da se cijene ovih %1$s artikala nisu mijenjale od %2$s do danas.', Config::TEXT_DOMAIN ),
									esc_html( number_format_i18n( $bez ) ),
									esc_html( $ref_datum )
								);
								?>
							</label>
						</p>

						<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
							<?php esc_html_e( 'Biljezimo tko je i kada potvrdio. Za artikle kod kojih to ne vrijedi cijenu mozete upisati pojedinacno ili uvesti iz datoteke — prije ili poslije ove potvrde. Nadete li poslije tocniji podatak, on ovu vrijednost smije prepisati.', Config::TEXT_DOMAIN ); ?>
						</p>

						<button class="button button-primary" name="radnja" value="izjava">
							<?php esc_html_e( 'Upisi danasnje cijene kao dodatne', Config::TEXT_DOMAIN ); ?>
						</button>
					</form>
				</div>

			<?php endif; ?>

			<form method="post" class="<?php echo esc_attr( Config::css( 'carobnjak-kraj' ) ); ?>">
				<?php wp_nonce_field( Config::nonce( 'carobnjak' ) ); ?>
				<button class="button" name="radnja" value="natrag"><?php esc_html_e( 'Natrag', Config::TEXT_DOMAIN ); ?></button>
				<button class="button button-primary" name="radnja" value="zavrsi">
					<?php esc_html_e( 'Gotovo — otvori Stanje', Config::TEXT_DOMAIN ); ?>
				</button>
			</form>

		<?php endif; ?>
	</div>

	<?php Admin::opseg(); ?>
</div>
