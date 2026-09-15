<?php
/**
 * Ekran POSTAVKE.
 *
 * Svaka postavka nosi recenicu o tome sto mijenja. Postavka bez objasnjenja je
 * prekidac koji nitko ne dira jer ne zna sto ce se dogoditi.
 *
 * @var string $obavijest
 * @var string $omnibus   Ime drugog dodatka koji prikazuje najnizu cijenu, ili prazno.
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Config;
use CJTR\Postavke;
use CJTR\Povijest\Dubina;

defined( 'ABSPATH' ) || exit;

$ima_regulirane = (bool) Postavke::daj( Postavke::IMA_REGULIRANE );
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Postavke', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( Config::nonce( 'postavke' ) ); ?>

		<?php /* ---------------------- referentni datum ---------------------- */ ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Referentni datum', Config::TEXT_DOMAIN ); ?></h2>

			<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
				<?php esc_html_e( 'Uz cijenu se mora istaknuti koliko je artikl stajao na jedan odredeni dan. Taj dan nije isti za sve proizvode.', Config::TEXT_DOMAIN ); ?>
			</p>

			<table class="<?php echo esc_attr( Config::css( 'postavke' ) ); ?>">
				<tbody>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( Postavke::REF_OSTALO ); ?>">
								<?php esc_html_e( 'Za vecinu proizvoda', Config::TEXT_DOMAIN ); ?>
							</label>
						</th>
						<td>
							<input type="date" id="<?php echo esc_attr( Postavke::REF_OSTALO ); ?>"
								name="<?php echo esc_attr( Postavke::REF_OSTALO ); ?>"
								value="<?php echo esc_attr( (string) Postavke::daj( Postavke::REF_OSTALO ) ); ?>">
							<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
								<?php esc_html_e( 'Zadano je ono sto propis odreduje za opce proizvode. Mijenjajte samo ako znate da za vas vrijedi drugi datum.', Config::TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Djelatnost', Config::TEXT_DOMAIN ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Postavke::IMA_REGULIRANE ); ?>" value="1"
									<?php checked( $ima_regulirane ); ?>>
								<?php esc_html_e( 'Prodajem hranu, pice, kozmetiku, sredstva za ciscenje, toaletne potrepstine ili proizvode za kucanstvo', Config::TEXT_DOMAIN ); ?>
							</label>
							<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
								<?php esc_html_e( 'Za te skupine vrijedi RANIJI datum. Prodajete li i njih i ostalo, imate dva datuma — to je najcesci nacin da se pogrijesi, jer trgovac za drugi datum najcesce ne zna.', Config::TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( Postavke::REF_REGULIRANE ); ?>">
								<?php esc_html_e( 'Za te skupine', Config::TEXT_DOMAIN ); ?>
							</label>
						</th>
						<td>
							<input type="date" id="<?php echo esc_attr( Postavke::REF_REGULIRANE ); ?>"
								name="<?php echo esc_attr( Postavke::REF_REGULIRANE ); ?>"
								value="<?php echo esc_attr( (string) Postavke::daj( Postavke::REF_REGULIRANE ) ); ?>">
							<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
								<?php esc_html_e( 'Koristi se samo ako je kvadratic iznad oznacen. Koji je artikl u kojoj skupini odreduje se na ekranu Artikli.', Config::TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php /* ------------------- najniza cijena u 30 dana ------------------- */ ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Najniza cijena u 30 dana', Config::TEXT_DOMAIN ); ?></h2>

			<?php if ( '' !== $omnibus ) : ?>
				<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
					<?php
					printf(
						/* translators: %s = ime dodatka */
						esc_html__( 'Najnizu cijenu u 30 dana vec prikazuje dodatak %s. Nas prikaz je zato zadano iskljucen — kupac koji vidi dvije tvrdnje o istoj stvari ne zna kojoj vjerovati, a to je gore nego nijedna.', Config::TEXT_DOMAIN ),
						esc_html( $omnibus )
					);
					?>
				</p>
			<?php endif; ?>

			<label>
				<input type="checkbox" name="<?php echo esc_attr( Postavke::NAJNIZA_30 ); ?>" value="1"
					<?php checked( Postavke::najniza_30() ); ?>>
				<?php esc_html_e( 'Prikazuj najnizu cijenu u 30 dana uz cijenu artikla', Config::TEXT_DOMAIN ); ?>
			</label>

			<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
				<?php if ( Dubina::dovoljna() ) : ?>
					<?php
					printf(
						/* translators: 1: datum, 2: broj dana */
						esc_html__( 'Nasa evidencija seze do %1$s, dakle pokriva puni prozor od %2$d dana.', Config::TEXT_DOMAIN ),
						esc_html( wp_date( 'j.n.Y.', Dubina::seze_do() ) ),
						(int) Dubina::DANA
					);
					?>
				<?php elseif ( Dubina::ima_zapisa() ) : ?>
					<?php
					/*
					 * Ne pise se "ne prikazuje se" nego DOKLE se zna.
					 *
					 * Prikaz se ne ceka: najniza u 30 dana je najmanja cijena koja je u
					 * prozoru primijenjena, a ako je jedina zabiljezena ona od jucer,
					 * onda je ona i najmanja. Ograda je u drugoj recenici i vrijedi
					 * samo dok prozor nije pun.
					 */
					if ( Dubina::seze_do() > 0 ) {
						printf(
							/* translators: %s = datum */
							esc_html__( 'Nasa evidencija seze do %s i prozor jos nije pun. Prikazuje se najmanja cijena koju imamo zabiljezenu — ako je artikl prije toga bio jeftiniji, toga u njoj nema.', Config::TEXT_DOMAIN ),
							esc_html( wp_date( 'j.n.Y.', Dubina::seze_do() ) )
						);
					} else {
						esc_html_e( 'Prikazuje se najmanja cijena koju imamo zabiljezenu. Otkad tocno vrijedi jos se ne zna, pa se ne moze reci koliko daleko unatrag sezemo.', Config::TEXT_DOMAIN );
					}
					?>
				<?php else : ?>
					<?php esc_html_e( 'Evidencija o cijenama jos je prazna, pa se nema sto prikazati. Prve zatecene cijene biljeze se pri sljedecoj dnevnoj provjeri.', Config::TEXT_DOMAIN ); ?>
				<?php endif; ?>
			</p>
		</div>

		<?php /* ---------------------- podaci o trgovcu ---------------------- */ ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Podaci o trgovcu', Config::TEXT_DOMAIN ); ?></h2>

			<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
				<?php esc_html_e( 'Ulaze u ime objavljene datoteke. Adresa se cita iz same trgovine i ne upisuje se rucno.', Config::TEXT_DOMAIN ); ?>
			</p>

			<table class="<?php echo esc_attr( Config::css( 'postavke' ) ); ?>">
				<tbody>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( Postavke::NAZIV_TVRTKE ); ?>"><?php esc_html_e( 'Naziv tvrtke', Config::TEXT_DOMAIN ); ?></label>
						</th>
						<td>
							<input type="text" id="<?php echo esc_attr( Postavke::NAZIV_TVRTKE ); ?>"
								name="<?php echo esc_attr( Postavke::NAZIV_TVRTKE ); ?>" size="40"
								value="<?php echo esc_attr( (string) Postavke::daj( Postavke::NAZIV_TVRTKE ) ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( Postavke::OZNAKA_OBJEKTA ); ?>"><?php esc_html_e( 'Oznaka prodajnog mjesta', Config::TEXT_DOMAIN ); ?></label>
						</th>
						<td>
							<input type="text" id="<?php echo esc_attr( Postavke::OZNAKA_OBJEKTA ); ?>"
								name="<?php echo esc_attr( Postavke::OZNAKA_OBJEKTA ); ?>" size="12"
								value="<?php echo esc_attr( (string) Postavke::daj( Postavke::OZNAKA_OBJEKTA ) ); ?>">
							<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
								<?php esc_html_e( 'Kratka oznaka, npr. WEB1. Imate li vise prodajnih mjesta, svako ima svoju.', Config::TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Adresa prodajnog mjesta', Config::TEXT_DOMAIN ); ?></th>
						<td>
							<code><?php echo esc_html( Config::objekt_adresa() ); ?></code>
							<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
								<?php esc_html_e( 'Cita se iz adrese trgovine. Prodajno mjesto webshopa je web adresa, ne sjediste tvrtke — na sjedistu kupac ne moze nista kupiti.', Config::TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php /* --------------------- vrijeme dnevne objave --------------------- */ ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Vrijeme dnevne objave', Config::TEXT_DOMAIN ); ?></h2>

			<label>
				<?php esc_html_e( 'Cjenik mora biti objavljen svaki dan do', Config::TEXT_DOMAIN ); ?>
				<input type="number" name="<?php echo esc_attr( Postavke::ROK_OBJAVE ); ?>" min="0" max="23" step="1"
					value="<?php echo (int) Postavke::rok_objave_sat(); ?>" size="2">
				<?php esc_html_e( 'sati, po vremenu trgovine', Config::TEXT_DOMAIN ); ?>
			</label>

			<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
				<?php
				printf(
					/* translators: 1: rezerva u satima, 2: vrijeme pokretanja */
					esc_html__( 'Posao se pokrece %1$d sata ranije, dakle u %2$s, da ostane vremena ako nesto zapne. Promijenite li ovo, redak za posluzitelj treba preracunati — novi stoji pod Napredno.', Config::TEXT_DOMAIN ),
					(int) Config::ROK_REZERVA_SATI,
					esc_html( wp_date( 'H:i', Postavke::ocekivano_do() ) )
				);
				?>
			</p>
		</div>

		<?php /* ------------------------ rezerve ------------------------ */ ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Rezerve', Config::TEXT_DOMAIN ); ?></h2>

			<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
				<?php esc_html_e( 'Dvije stvari koje rade u pozadini kad uobicajeni put zakaze. Ostavite ih ukljucene osim ako imate razlog.', Config::TEXT_DOMAIN ); ?>
			</p>

			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Postavke::PROMET_GURA ); ?>" value="1"
						<?php checked( Postavke::promet_gura() ); ?>>
					<?php esc_html_e( 'Ako dnevno pokretanje zakaze, neka obradu gurnu posjeti trgovini', Config::TEXT_DOMAIN ); ?>
				</label>
				<span class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
					<?php esc_html_e( 'Radi nakon sto je stranica vec poslana, pa kupac ne ceka. NIJE zamjena za dnevno pokretanje: dan bez ijednog posjeta ostaje bez cjenika, a posjet koji posluzi predmemoriju do nas uopce ne dode.', Config::TEXT_DOMAIN ); ?>
				</span>
			</p>

			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Postavke::JS_REZERVA ); ?>" value="1"
						<?php checked( Postavke::js_rezerva() ); ?>>
					<?php esc_html_e( 'Dopisi sidrenu cijenu i ondje gdje je tema nije ispisala', Config::TEXT_DOMAIN ); ?>
				</label>
				<span class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
					<?php esc_html_e( 'Neke teme i graditelji stranica cijenu crtaju na svoj nacin, pa je ne mozemo dopuniti uobicajenim putem. Tada se dopisuje u pregledniku. Ucitava se samo na stranicama na kojima uobicajeni put nije radio — ako sve radi, ovo se nikad ne pokrene.', Config::TEXT_DOMAIN ); ?>
				</span>
			</p>
		</div>

		<p>
			<button class="button button-primary" name="radnja" value="spremi">
				<?php esc_html_e( 'Spremi postavke', Config::TEXT_DOMAIN ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( Admin::url( Admin::STRANICA_STANJE ) ); ?>">
				<?php esc_html_e( 'Natrag na stanje', Config::TEXT_DOMAIN ); ?>
			</a>
		</p>
	</form>

	<?php Admin::opseg(); ?>
</div>
