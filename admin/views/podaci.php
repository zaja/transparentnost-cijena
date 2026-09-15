<?php
/**
 * Ekran: podaci o proizvodima.
 *
 * MJERILO
 *
 * Katalog ima 3623 retka i sve je prazno. Ovaj ekran odlucuje je li dodatak
 * upotrebljiv — ne tocnost obrade, nego koliko brzo administrator dode od nule do
 * potpunog kataloga.
 *
 * Zato: unos U RETKU (bez otvaranja proizvoda), filtriran po onome sto nedostaje,
 * i poredan po PROMETU — ako se stane na pola, stalo se na pravom mjestu.
 *
 * @var array    $sazetak
 * @var array    $po_poljima
 * @var object[] $redci
 * @var string   $filtar
 * @var int      $stranica
 * @var int      $ukupno_redaka
 * @var object[] $sukobi
 * @var array    $duplikati
 * @var string   $obavijest
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Config;
use CJTR\Podaci\Cijena_Po_Jedinici;
use CJTR\Podaci\Gtin;
use CJTR\Podaci\Marke;
use CJTR\Preuzimanje;

defined( 'ABSPATH' ) || exit;

$po_stranici = 50;
$stranica_url = function ( $n ) use ( $filtar ) {
	return add_query_arg(
		array(
			'page'     => Config::stranica( Admin::STRANICA_PODACI ),
			'filtar'   => $filtar,
			'stranica' => (int) $n,
		),
		admin_url( 'admin.php' )
	);
};
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Podaci o proizvodima', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<?php // Jedan broj na vrhu. Sve ostalo je razrada. ?>
	<div class="<?php echo esc_attr( Config::css( 'sljedece' ) . ' ' . Config::css( $sazetak['spremno'] === $sazetak['ukupno'] ? 'sljedece-gotovo' : 'sljedece-radi' ) ); ?>">
		<p class="<?php echo esc_attr( Config::css( 'sljedece-tekst' ) ); ?>">
			<?php
			printf(
				/* translators: 1: spremno, 2: ukupno */
				esc_html__( '%1$s od %2$s artikala spremno za cjenik.', Config::TEXT_DOMAIN ),
				esc_html( number_format_i18n( $sazetak['spremno'] ) ),
				esc_html( number_format_i18n( $sazetak['ukupno'] ) )
			);
			?>
		</p>
		<p class="<?php echo esc_attr( Config::css( 'sljedece-brojac' ) ); ?>">
			<?php esc_html_e( 'Spreman znaci: svako obvezno polje je ili popunjeno ili oznaceno kao "nije primjenjivo", s razlogom.', Config::TEXT_DOMAIN ); ?>
		</p>
	</div>

	<!-- razrada po poljima -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Po poljima', Config::TEXT_DOMAIN ); ?></h2>

		<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Polje', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"><?php esc_html_e( 'Popunjeno', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"><?php esc_html_e( 'Nije primjenjivo', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"><?php esc_html_e( 'Nedostaje', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $po_poljima as $kljuc => $p ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $p['naziv'] ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $p['popunjeno'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $p['neprimjenjivo'] ) ); ?></td>
						<td>
							<?php if ( $p['nedostaje'] > 0 ) : ?>
								<strong class="<?php echo esc_attr( Config::css( 'gresaka' ) ); ?>">
									<?php echo esc_html( number_format_i18n( $p['nedostaje'] ) ); ?>
								</strong>
							<?php else : ?>
								<?php echo esc_html( '0' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $p['nedostaje'] > 0 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => Config::stranica( Admin::STRANICA_PODACI ), 'filtar' => $kljuc ), admin_url( 'admin.php' ) ) ); ?>">
									<?php esc_html_e( 'popuni', Config::TEXT_DOMAIN ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		/*
		 * Upute stoje UZ GUMB, ne u datoteci. Datoteka pocinje zaglavljem, bez
		 * iznimke: redak koji pocinje s "#" Excel i LibreOffice ne prepoznaju kao
		 * komentar nego kao podatke, pa se tablica raspadne — a i nas vlastiti
		 * uvoznik trazi "sifra" u prvom retku.
		 */
		?>
		<p>
			<a class="button" href="<?php echo esc_url( Preuzimanje::url( Preuzimanje::POPIS_PODACI ) ); ?>">
				<?php esc_html_e( 'Preuzmi popis (CSV)', Config::TEXT_DOMAIN ); ?>
			</a>
		</p>
		<p class="<?php echo esc_attr( Config::css( 'upute' ) ); ?>">
			<?php esc_html_e( 'Datoteka ima iste stupce kao i uvoz, pa se moze ispuniti i vratiti natrag. Uparuje se po stupcu "sifra". Prazna celija znaci "ne diraj", ne "obrisi". Stupci "naziv", "tip" i "barkod_status" sluze samo za snalazenje — uvoz ih ne cita.', Config::TEXT_DOMAIN ); ?>
		</p>
	</div>

	<?php // Neslaganja i duplikati — mali brojevi, ali svaki je vjerojatna greska. ?>
	<?php if ( ! empty( $sukobi ) || ! empty( $duplikati ) ) : ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( 'status-lose' ) ); ?>">
			<h2>
				<span class="<?php echo esc_attr( Config::css( 'znak' ) ); ?>" aria-hidden="true"></span>
				<?php esc_html_e( 'Treba pogledati', Config::TEXT_DOMAIN ); ?>
			</h2>

			<?php if ( ! empty( $duplikati ) ) : ?>
				<p><strong><?php esc_html_e( 'Isti barkod na vise artikala', Config::TEXT_DOMAIN ); ?></strong> —
					<?php esc_html_e( 'barkod je jedinstven po proizvodu, pa je ovo gotovo uvijek greska unosa.', Config::TEXT_DOMAIN ); ?></p>
				<ul>
					<?php foreach ( array_slice( $duplikati, 0, 20 ) as $d ) : ?>
						<li><code><?php echo esc_html( $d->barkod ); ?></code> — <?php echo esc_html( $d->artikli ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $sukobi ) ) : ?>
				<p><strong><?php esc_html_e( 'Dva izvora kazu razlicito', Config::TEXT_DOMAIN ); ?></strong> —
					<?php esc_html_e( 'upisan je jaci izvor, ali jedan od njih je pogresan.', Config::TEXT_DOMAIN ); ?></p>
				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<tbody>
						<?php foreach ( array_slice( $sukobi, 0, 20 ) as $s ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $s->naziv ); ?> <code>#<?php echo (int) $s->entity_id; ?></code></th>
								<td>
									<?php echo esc_html( Config::POLJA[ $s->polje ]['naziv'] ?? $s->polje ); ?>:
									<strong><?php echo esc_html( $s->izvor_a ); ?></strong> = <?php echo esc_html( $s->vrijednost_a ); ?>
									&nbsp;&ne;&nbsp;
									<?php echo esc_html( $s->izvor_b ); ?> = <?php echo esc_html( $s->vrijednost_b ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- unos u retku -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Unos', Config::TEXT_DOMAIN ); ?></h2>

		<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
			<?php esc_html_e( 'Poredano po prometu artikla — ako stanete na pola, stali ste na onome sto se najvise prodaje. Promet se cita zbirno iz narudzbi; podaci o kupcima se ne diraju.', Config::TEXT_DOMAIN ); ?>
		</p>

		<p class="<?php echo esc_attr( Config::css( 'filtri' ) ); ?>">
			<?php
			$filtri = array( '' => __( 'sve sto nije potpuno', Config::TEXT_DOMAIN ) );
			foreach ( Config::POLJA as $k => $meta ) {
				$filtri[ $k ] = sprintf( __( 'nedostaje: %s', Config::TEXT_DOMAIN ), mb_strtolower( $meta['naziv'] ) );
			}
			foreach ( $filtri as $k => $oznaka ) :
				$aktivan = ( (string) $k === (string) $filtar );
				?>
				<a class="<?php echo esc_attr( Config::css( 'filtar' ) . ( $aktivan ? ' ' . Config::css( 'filtar-aktivan' ) : '' ) ); ?>"
					href="<?php echo esc_url( add_query_arg( array( 'page' => Config::stranica( Admin::STRANICA_PODACI ), 'filtar' => $k ), admin_url( 'admin.php' ) ) ); ?>">
					<?php echo esc_html( $oznaka ); ?>
				</a>
			<?php endforeach; ?>
		</p>

		<?php if ( empty( $redci ) ) : ?>
			<p><strong><?php esc_html_e( 'Nema artikala koji odgovaraju filtru. Gotovo.', Config::TEXT_DOMAIN ); ?></strong></p>
		<?php else : ?>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'podaci' ) ); ?>
				<input type="hidden" name="filtar" value="<?php echo esc_attr( $filtar ); ?>">
				<input type="hidden" name="stranica" value="<?php echo (int) $stranica; ?>">

				<table class="<?php echo esc_attr( Config::css( 'stavke' ) . ' ' . Config::css( 'unos' ) ); ?>">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Artikl', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Barkod', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Marka', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Neto kolicina', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Cijena po jed.', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Nije primjenjivo', Config::TEXT_DOMAIN ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $redci as $r ) : ?>
							<?php
							$id       = (int) $r->entity_id;
							$po_jed   = Cijena_Po_Jedinici::za_artikl( $id );
							$np       = $neprimjenjivo[ $id ] ?? array();
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $r->roditelj_id ) ); ?>" target="_blank"><?php echo esc_html( mb_substr( (string) $r->naziv, 0, 46 ) ); ?></a>
									<br>
									<small>
										<code>#<?php echo (int) $id; ?></code>
										<?php if ( '' !== (string) $r->sku ) : ?>
											<?php echo esc_html( $r->sku ); ?>
										<?php endif; ?>
										<?php if ( (float) $r->promet > 0 ) : ?>
											· <?php echo esc_html( number_format_i18n( (float) $r->promet, 0 ) . ' ' . get_woocommerce_currency() ); ?>
										<?php endif; ?>
									</small>
								</td>

								<td>
									<input type="text" name="r[<?php echo (int) $id; ?>][barkod]" inputmode="numeric"
										value="<?php echo esc_attr( (string) $r->barkod ); ?>" size="15"
										<?php disabled( isset( $np[ Config::POLJE_BARKOD ] ) ); ?>>
									<?php if ( '' !== (string) $r->barkod_status && Config::GTIN_VALJAN !== $r->barkod_status ) : ?>
										<br><small class="<?php echo esc_attr( Config::css( 'gresaka' ) ); ?>"><?php echo esc_html( $r->barkod_status ); ?></small>
									<?php endif; ?>
								</td>

								<td>
									<input type="text" name="r[<?php echo (int) $id; ?>][marka]" list="<?php echo esc_attr( Config::css( 'marke' ) ); ?>"
										value="<?php echo esc_attr( (string) $r->marka ); ?>" size="14"
										<?php disabled( isset( $np[ Config::POLJE_MARKA ] ) ); ?>>
								</td>

								<td>
									<input type="text" name="r[<?php echo (int) $id; ?>][kolicina]" size="6"
										value="<?php echo esc_attr( null === $r->neto_kolicina ? '' : rtrim( rtrim( (string) $r->neto_kolicina, '0' ), '.' ) ); ?>"
										<?php disabled( isset( $np[ Config::POLJE_KOLICINA ] ) ); ?>>
									<select name="r[<?php echo (int) $id; ?>][jedinica]" <?php disabled( isset( $np[ Config::POLJE_KOLICINA ] ) ); ?>>
										<option value=""></option>
										<?php foreach ( Config::JEDINICE as $j => $def ) : ?>
											<option value="<?php echo esc_attr( $j ); ?>" <?php selected( (string) $r->jedinica_mjere, $j ); ?>><?php echo esc_html( $j ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>

								<td>
									<?php if ( $po_jed ) : ?>
										<?php echo esc_html( $po_jed['zapis'] ); ?>
										<?php if ( $po_jed['gubi_preciznost'] ) : ?>
											<br><small class="<?php echo esc_attr( Config::css( 'upozorenje' ) ); ?>"><?php esc_html_e( 'zaokruzivanje mijenja vrijednost vise od 1 %', Config::TEXT_DOMAIN ); ?></small>
										<?php endif; ?>
									<?php else : ?>
										<small>&mdash;</small>
									<?php endif; ?>

									<?php
									/*
									 * Dostavna tezina kao PITANJE, ne kao upis. Nije izvor neto
									 * kolicine — vidi Izvori::prijedlog_iz_tezine() — ali kad je
									 * artikl doista roba koja se prodaje po masi, brojka je vec tu
									 * i ne treba je traziti drugdje.
									 */
									$prijedlog = ( null === $r->neto_kolicina )
										? \CJTR\Podaci\Izvori::prijedlog_iz_tezine( $id )
										: null;
									?>
									<?php if ( $prijedlog ) : ?>
										<br><small class="<?php echo esc_attr( Config::css( 'prijedlog' ) ); ?>">
											<?php
											printf(
												/* translators: %s = tezina s jedinicom */
												esc_html__( 'dostavna tezina je %s — je li to neto kolicina?', Config::TEXT_DOMAIN ),
												esc_html( $prijedlog['vrijednost'] )
											);
											?>
										</small>
									<?php endif; ?>
								</td>

								<td class="<?php echo esc_attr( Config::css( 'np' ) ); ?>">
									<?php foreach ( array( Config::POLJE_BARKOD, Config::POLJE_MARKA, Config::POLJE_KOLICINA ) as $polje ) : ?>
										<label title="<?php echo esc_attr( isset( $np[ $polje ] ) ? $np[ $polje ]->razlog : '' ); ?>">
											<input type="checkbox" name="np[<?php echo (int) $id; ?>][]" value="<?php echo esc_attr( $polje ); ?>"
												<?php checked( isset( $np[ $polje ] ) ); ?>>
											<?php echo esc_html( mb_substr( Config::POLJA[ $polje ]['naziv'], 0, 6 ) ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<datalist id="<?php echo esc_attr( Config::css( 'marke' ) ); ?>">
					<?php foreach ( Marke::popis() as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>"></option>
					<?php endforeach; ?>
				</datalist>

				<p>
					<label>
						<?php esc_html_e( 'Razlog za "nije primjenjivo" (obavezno ako oznacavate):', Config::TEXT_DOMAIN ); ?><br>
						<input type="text" name="np_razlog" size="70"
							placeholder="<?php esc_attr_e( 'npr. vlastita proizvodnja, barkod nije dodijeljen', Config::TEXT_DOMAIN ); ?>">
					</label>
				</p>

				<p>
					<button class="button button-primary" name="radnja" value="spremi"><?php esc_html_e( 'Spremi ovu stranicu', Config::TEXT_DOMAIN ); ?></button>
				</p>
			</form>

			<?php
			$stranica_broj = max( 1, (int) $stranica );
			$stranica_max  = max( 1, (int) ceil( $ukupno_redaka / $po_stranici ) );
			?>
			<p class="<?php echo esc_attr( Config::css( 'napredak' ) ); ?>">
				<?php
				printf(
					/* translators: 1: stranica, 2: ukupno stranica, 3: ukupno artikala */
					esc_html__( 'Stranica %1$d od %2$d — ukupno %3$s artikala u ovom filtru.', Config::TEXT_DOMAIN ),
					$stranica_broj,
					$stranica_max,
					esc_html( number_format_i18n( $ukupno_redaka ) )
				);
				?>
				<?php if ( $stranica_broj > 1 ) : ?>
					<a href="<?php echo esc_url( $stranica_url( $stranica_broj - 1 ) ); ?>">&larr; <?php esc_html_e( 'prethodna', Config::TEXT_DOMAIN ); ?></a>
				<?php endif; ?>
				<?php if ( $stranica_broj < $stranica_max ) : ?>
					<a href="<?php echo esc_url( $stranica_url( $stranica_broj + 1 ) ); ?>"><?php esc_html_e( 'sljedeca', Config::TEXT_DOMAIN ); ?> &rarr;</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>

	<!-- komadna roba -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Neto kolicina za komadnu robu', Config::TEXT_DOMAIN ); ?></h2>

		<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
			<?php esc_html_e( 'Vecina artikala prodaje se po komadu — spil karata, poker set, majica. Njima je neto kolicina "1 kom", a cijena po jedinici mjere jednaka je cijeni artikla. Ovo rjesava polje odjednom, umjesto tisucama rucnih unosa.', Config::TEXT_DOMAIN ); ?>
		</p>

		<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dobit ce "1 kom"', Config::TEXT_DOMAIN ); ?></th>
					<td><strong><?php echo esc_html( number_format_i18n( $kom['komadna'] ) ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ide na pregled (naziv sadrzi broj)', Config::TEXT_DOMAIN ); ?></th>
					<td><strong><?php echo esc_html( number_format_i18n( $kom['iznimke'] ) ); ?></strong></td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $kom['primjeri'] ) ) : ?>
			<details>
				<summary><?php esc_html_e( 'Artikli koje NECE dirati', Config::TEXT_DOMAIN ); ?></summary>
				<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
					<?php esc_html_e( 'Kod njih "1 kom" mozda nije tocno: "100 zetona" je sto komada, "6 spilova" je sest. Upisite im kolicinu rucno u tablici iznad. Dio je i lazna uzbuna — broj u nazivu koji nije kolicina.', Config::TEXT_DOMAIN ); ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( Preuzimanje::url( Preuzimanje::POPIS_IZNIMKE_KOLICINE ) ); ?>">
						<?php esc_html_e( 'Preuzmi popis za pregled (CSV)', Config::TEXT_DOMAIN ); ?>
					</a>
				</p>
				<p class="<?php echo esc_attr( Config::css( 'upute' ) ); ?>">
					<?php esc_html_e( 'Poredano po prometu — pocnite od vrha. Ispunite stupce "neto_kolicina" i "jedinica_mjere" pa datoteku vratite kroz uvoz ispod. Stupac "broj_iz_naziva" pokazuje sto je prepoznato u nazivu; dio toga nije kolicina nego naziv ili nominala.', Config::TEXT_DOMAIN ); ?>
				</p>

				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Broj', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Artikl', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Promet', Config::TEXT_DOMAIN ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( \CJTR\Podaci\Komadna_Roba::iznimke(), 0, 40 ) as $x ) : ?>
							<tr>
								<th scope="row"><code><?php echo esc_html( $x->broj ); ?></code></th>
								<td><?php echo esc_html( mb_substr( (string) $x->naziv, 0, 60 ) ); ?> <code>#<?php echo (int) $x->entity_id; ?></code></td>
								<td><?php echo esc_html( (float) $x->promet > 0 ? number_format_i18n( (float) $x->promet, 0 ) . ' ' . get_woocommerce_currency() : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>

		<?php if ( empty( $kom_potvrda ) ) : ?>
			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'podaci' ) ); ?>
				<button class="button button-primary" name="radnja" value="potvrdi_kom">
					<?php
					printf(
						/* translators: %s = broj artikala */
						esc_html__( 'Potvrdujem za %s artikala', Config::TEXT_DOMAIN ),
						esc_html( number_format_i18n( $kom['komadna'] ) )
					);
					?>
				</button>
			</form>
			<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
				<?php esc_html_e( 'Nakon potvrde posao se pokrece na stranici Obrada.', Config::TEXT_DOMAIN ); ?>
			</p>
		<?php else : ?>
			<p>
				<strong><?php esc_html_e( 'Potvrdeno.', Config::TEXT_DOMAIN ); ?></strong>
				<?php esc_html_e( 'Posao se moze pokrenuti na stranici Obrada.', Config::TEXT_DOMAIN ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'podaci' ) ); ?>
				<button class="button" name="radnja" value="povuci_kom"><?php esc_html_e( 'Povuci potvrdu', Config::TEXT_DOMAIN ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<!-- uvoz iz CSV-a -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Uvoz iz tablice', Config::TEXT_DOMAIN ); ?></h2>

		<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
			<?php esc_html_e( 'Barkodovi obicno dolaze kao tablica od dobavljaca. Uparuje se po sifri artikla. Datoteka se UVIJEK prvo procita i prikaze sto bi se dogodilo — upisuje se tek nakon vase potvrde. Prazna celija znaci "ne diraj", ne "obrisi".', Config::TEXT_DOMAIN ); ?>
		</p>

		<?php if ( ! empty( $uvoz ) ) : ?>
			<h3><?php esc_html_e( 'Probni prolaz', Config::TEXT_DOMAIN ); ?></h3>

			<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
				<tbody>
					<?php foreach ( $uvoz['sazetak'] as $ishod => $koliko ) : ?>
						<?php if ( 'ukupno' === $ishod ) : ?>
							<tr><th scope="row"><?php esc_html_e( 'Redaka u datoteci', Config::TEXT_DOMAIN ); ?></th><td><?php echo esc_html( number_format_i18n( $koliko ) ); ?></td></tr>
						<?php else : ?>
							<tr>
								<th scope="row"><?php echo esc_html( \CJTR\Podaci\Uvoz::opis_ishoda( $ishod ) ); ?></th>
								<td><?php echo esc_html( number_format_i18n( $koliko ) ); ?></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$problemi = array_filter(
				$uvoz['redci'],
				function ( $r ) {
					return 'upisat_ce_se' !== $r['ishod'];
				}
			);
			?>

			<?php if ( ! empty( $problemi ) ) : ?>
				<p><strong><?php esc_html_e( 'Redci koji NECE biti upisani:', Config::TEXT_DOMAIN ); ?></strong></p>
				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<tbody>
						<?php foreach ( array_slice( $problemi, 0, 30 ) as $r ) : ?>
							<tr>
								<th scope="row"><?php printf( esc_html__( 'redak %d', Config::TEXT_DOMAIN ), (int) $r['broj'] ); ?>
									<code><?php echo esc_html( $r['sifra'] ); ?></code></th>
								<td><?php echo esc_html( $r['poruka'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ( $uvoz['sazetak']['upisat_ce_se'] ?? 0 ) > 0 ) : ?>
				<form method="post">
					<?php wp_nonce_field( Config::nonce( 'podaci' ) ); ?>
					<input type="hidden" name="uvoz_datoteka" value="<?php echo esc_attr( $uvoz['putanja'] ); ?>">
					<button class="button button-primary" name="radnja" value="uvezi">
						<?php
						printf(
							/* translators: %d = broj redaka */
							esc_html__( 'Upisi %d redaka', Config::TEXT_DOMAIN ),
							(int) $uvoz['sazetak']['upisat_ce_se']
						);
						?>
					</button>
				</form>
			<?php endif; ?>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( Config::nonce( 'podaci' ) ); ?>
			<p>
				<input type="file" name="csv" accept=".csv,text/csv" required>
				<button class="button" name="radnja" value="probni"><?php esc_html_e( 'Procitaj i pokazi sto bi se dogodilo', Config::TEXT_DOMAIN ); ?></button>
			</p>
		</form>
	</div>

	<!-- mapiranje kategorija -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Zakonske kategorije', Config::TEXT_DOMAIN ); ?></h2>

		<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
			<?php
			printf(
				/* translators: 1: mapirano, 2: ukupno */
				esc_html__( 'Povezite kategorije svoje trgovine s propisanim skupinama. Mapirano: %1$d od %2$d. Kategorije koje ne pripadaju nijednoj reguliranoj skupini ostavite praznima.', Config::TEXT_DOMAIN ),
				(int) $kategorije_napredak['mapirano'],
				(int) $kategorije_napredak['ukupno']
			);
			?>
		</p>

		<form method="post">
			<?php wp_nonce_field( Config::nonce( 'podaci' ) ); ?>
			<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
				<tbody>
					<?php foreach ( $kategorije as $k ) : ?>
						<tr>
							<th scope="row">
								<?php echo esc_html( str_repeat( '— ', (int) $k['dubina'] ) . $k['naziv'] ); ?>
								<small>(<?php echo (int) $k['broj']; ?>)</small>
							</th>
							<td>
								<select name="kat[<?php echo (int) $k['id']; ?>]">
									<option value=""><?php esc_html_e( '— nije regulirana skupina —', Config::TEXT_DOMAIN ); ?></option>
									<?php foreach ( Config::ZAKONSKE_KATEGORIJE as $kljuc => $naziv ) : ?>
										<option value="<?php echo esc_attr( $kljuc ); ?>" <?php selected( $mapiranje[ $k['id'] ] ?? '', $kljuc ); ?>>
											<?php echo esc_html( $naziv ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p><button class="button" name="radnja" value="kategorije"><?php esc_html_e( 'Spremi mapiranje', Config::TEXT_DOMAIN ); ?></button></p>
		</form>
	</div>

	<?php Admin::opseg(); ?>
</div>
