<?php
/**
 * Ekran ARTIKLI — uvoz na vrhu, rucna dopuna ispod.
 *
 * Redoslijed nije kozmeticki. Iznad crte je ono sto rjesava tisuce artikala, ispod
 * ono sto rjesava desetak. Raniji ekran ih je prikazivao kao ravnopravne, pa je
 * najsporiji put izgledao kao glavni — a tri tisuce barkodova nitko nece utipkati.
 *
 * @var array      $sazetak
 * @var array      $po_poljima
 * @var object[]   $redci
 * @var array      $neprimjenjivo
 * @var string     $filtar
 * @var int        $stranica
 * @var int        $po_stranici
 * @var int        $ukupno_redaka
 * @var array|null $uvoz
 * @var array|null $mapiranje
 * @var string     $obavijest
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Config;
use CJTR\Podaci\Uvoz;
use CJTR\Preuzimanje;

defined( 'ABSPATH' ) || exit;

/** Polja koja uvoz zna primiti, imenima koja citatelj razumije. */
$polja_uvoza = array(
	'sifra'               => __( 'Sifra artikla (obvezno)', Config::TEXT_DOMAIN ),
	'barkod'              => __( 'Barkod', Config::TEXT_DOMAIN ),
	'marka'               => __( 'Marka', Config::TEXT_DOMAIN ),
	'sidrena_cijena'      => __( 'Sidrena cijena', Config::TEXT_DOMAIN ),
	'neto_kolicina'       => __( 'Neto kolicina (nije obvezna)', Config::TEXT_DOMAIN ),
	'jedinica_mjere'      => __( 'Jedinica mjere (nije obvezna)', Config::TEXT_DOMAIN ),
	'zakonska_kategorija' => __( 'Skupina proizvoda (nije obvezna)', Config::TEXT_DOMAIN ),
);
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Artikli', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<?php // --- koliko je spremno --- ?>
	<div class="<?php echo esc_attr( Config::css( 'sazetak' ) . ' ' . Config::css( $sazetak['spremno'] >= $sazetak['ukupno'] ? 'sazetak-ok' : 'sazetak-radi' ) ); ?>">
		<p class="<?php echo esc_attr( Config::css( 'sazetak-tekst' ) ); ?>">
			<?php
			printf(
				/* translators: 1: spremno, 2: ukupno */
				esc_html__( 'Za cjenik je spremno %1$s od %2$s artikala i varijanti.', Config::TEXT_DOMAIN ),
				esc_html( number_format_i18n( $sazetak['spremno'] ) ),
				esc_html( number_format_i18n( $sazetak['ukupno'] ) )
			);
			?>
		</p>
		<p class="<?php echo esc_attr( Config::css( 'sazetak-sitno' ) ); ?>">
			<?php foreach ( $po_poljima as $kljuc => $p ) : ?>
				<?php if ( $p['nedostaje'] < 1 ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<a href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI, array( 'filtar' => $kljuc ) ) ); ?>">
					<?php
					printf(
						/* translators: 1: naziv polja, 2: koliko nedostaje */
						esc_html__( '%1$s: nedostaje %2$s', Config::TEXT_DOMAIN ),
						esc_html( $p['naziv'] ),
						esc_html( number_format_i18n( $p['nedostaje'] ) )
					);
					?>
				</a>
			<?php endforeach; ?>
		</p>
	</div>

	<?php /* ================== POSEBNI OBLICI PRODAJE ================== */ ?>
	<?php if ( ! empty( $posebna ) ) : ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Kako se zove vasa akcija', Config::TEXT_DOMAIN ); ?></h2>

			<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
				<?php
				printf(
					/* translators: %s = broj artikala */
					esc_html__( 'Ovih %s artikala trenutno se prodaje ispod redovne cijene. Cjenik mora reci kako se taj oblik prodaje zove, a to nije uvijek isto: rasprodaja i sezonsko snizenje pravno se razlikuju od akcije. Bez vaseg izbora pise "akcija" — to je definicija prodaje po nizoj cijeni i najcesci slucaj.', Config::TEXT_DOMAIN ),
					esc_html( number_format_i18n( count( $posebna ) ) )
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'artikli' ) ); ?>

				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<thead>
						<tr>
							<th scope="col"><input type="checkbox" onclick="this.closest('table').querySelectorAll('input[name^=odabrani]').forEach(function(k){k.checked=this.checked;}.bind(this));"></th>
							<th scope="col"><?php esc_html_e( 'Artikl', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Cijena', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traje', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Oblik prodaje', Config::TEXT_DOMAIN ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $posebna as $r ) : ?>
							<?php
							$id       = (int) $r->entity_id;
							$izabrana = in_array( (string) $r->vrsta_pop, CJTR\Povijest\Vrsta_Prodaje::RUCNE, true )
								? (string) $r->vrsta_pop
								: Config::POP_AKCIJSKA;
							$dana     = CJTR\Povijest\Vrsta_Prodaje::trajanje_dana( $id );
							$upoz     = ( Config::POP_SEZONSKO === $izabrana ) ? CJTR\Povijest\Vrsta_Prodaje::upozorenja( $id ) : array();
							?>
							<tr>
								<td><input type="checkbox" name="odabrani[]" value="<?php echo $id; ?>"></td>
								<td>
									<?php echo esc_html( mb_substr( (string) $r->naziv, 0, 45 ) ); ?>
									<span class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
										<?php echo esc_html( '' !== $r->sku ? $r->sku : '#' . $id ); ?>
									</span>
								</td>
								<td class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
									<?php
									printf(
										'%s → %s',
										esc_html( number_format_i18n( (float) $r->regular_price, 2 ) ),
										esc_html( number_format_i18n( (float) $r->price, 2 ) )
									);
									?>
								</td>
								<td class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
									<?php
									echo esc_html(
										( null === $dana )
											? __( 'ne zna se', Config::TEXT_DOMAIN )
											: sprintf( _n( '%d dan', '%d dana', (int) $dana, Config::TEXT_DOMAIN ), (int) $dana )
									);
									?>
								</td>
								<td>
									<select name="oblik[<?php echo $id; ?>]">
										<?php foreach ( Config::IZBOR_POSEBNOG_OBLIKA as $kljuc => $natpis ) : ?>
											<option value="<?php echo esc_attr( $kljuc ); ?>" <?php selected( $izabrana, $kljuc ); ?>>
												<?php echo esc_html( $natpis ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<?php foreach ( $upoz as $u ) : ?>
										<div class="<?php echo esc_attr( Config::css( 'upozorenje' ) ); ?>">⚠ <?php echo esc_html( $u ); ?></div>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<label>
						<?php esc_html_e( 'Za oznacene postavi:', Config::TEXT_DOMAIN ); ?>
						<select name="skupni_oblik">
							<option value=""><?php esc_html_e( '— ne mijenjaj —', Config::TEXT_DOMAIN ); ?></option>
							<?php foreach ( Config::IZBOR_POSEBNOG_OBLIKA as $kljuc => $natpis ) : ?>
								<option value="<?php echo esc_attr( $kljuc ); ?>"><?php echo esc_html( $natpis ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="button button-primary" name="radnja" value="oblik_prodaje">
						<?php esc_html_e( 'Spremi', Config::TEXT_DOMAIN ); ?>
					</button>
				</p>

				<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
					<?php
					printf(
						/* translators: 1: najdulje dana, 2: najvise puta godisnje */
						esc_html__( 'Izbor vrijedi za tekuce razdoblje — promijeni li se cijena, vraca se na zadano. Sezonsko snizenje smije trajati najdulje %1$d dana i najvise %2$d puta godisnje; ako to prekoracite, upozorit cemo vas, ali vas necemo sprijeciti.', Config::TEXT_DOMAIN ),
						(int) Config::SEZONSKO_MAX_DANA,
						(int) Config::SEZONSKO_MAX_GODISNJE
					);
					?>
				</p>
			</form>
		</div>
	<?php endif; ?>

	<?php /* ============================ UVOZ ============================ */ ?>
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( 'kartica-glavna' ) ); ?>">
		<h2><?php esc_html_e( 'Uvoz iz tablice', Config::TEXT_DOMAIN ); ?></h2>

		<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
			<?php esc_html_e( 'Ovo je najbrzi put. Barkodovi, marke i kolicine obicno vec postoje u programu koji vodi vasu robu — izvezite ih kao tablicu i ucitajte ovdje. Datoteka se UVIJEK prvo procita i pokaze sto bi se dogodilo; upisuje se tek nakon vase potvrde.', Config::TEXT_DOMAIN ); ?>
		</p>

		<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
			<?php esc_html_e( 'Uvoz pise u vlastite tablice dodatka, ne u vase proizvode. Cijene, nazivi, opisi i zalihe ostaju netaknuti. Ako barkod ili marka vec stoje u WooCommerceu, uvoz ih ne prepisuje — one vrijede.', Config::TEXT_DOMAIN ); ?>
		</p>

		<?php if ( empty( $mapiranje ) && empty( $uvoz ) ) : ?>

			<?php // Korak 1: odabir datoteke. ?>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( Config::nonce( 'artikli' ) ); ?>
				<input type="file" name="csv" accept=".csv,text/csv" required>
				<button class="button button-primary" name="radnja" value="ucitaj">
					<?php esc_html_e( 'Ucitaj datoteku', Config::TEXT_DOMAIN ); ?>
				</button>
			</form>

			<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
				<?php esc_html_e( 'Prihvaca se CSV. Prazna celija znaci "ne diraj", ne "obrisi" — tablica od dobavljaca nosi samo ono sto on zna.', Config::TEXT_DOMAIN ); ?>
				<a href="<?php echo esc_url( Preuzimanje::url( Preuzimanje::POPIS_PODACI ) ); ?>">
					<?php esc_html_e( 'Preuzmi predlozak s vasim artiklima', Config::TEXT_DOMAIN ); ?>
				</a>
			</p>

			<hr class="<?php echo esc_attr( Config::css( 'tanka' ) ); ?>">

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'artikli' ) ); ?>
				<button class="button" name="radnja" value="pokupi">
					<?php esc_html_e( 'Pokupi sto trgovina vec ima', Config::TEXT_DOMAIN ); ?>
				</button>
				<span class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
					<?php esc_html_e( 'Trazi barkode, marke i kolicine koje su vec negdje u WooCommerceu — u polju za barkod, u taksonomiji marki, u atributima. Nista ne prepisuje.', Config::TEXT_DOMAIN ); ?>
				</span>
			</form>

		<?php elseif ( ! empty( $mapiranje ) ) : ?>

			<?php // Korak 2: povezivanje stupaca. ?>
			<h3><?php esc_html_e( 'Povezite stupce', Config::TEXT_DOMAIN ); ?></h3>

			<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
				<?php esc_html_e( 'Vas program izvozi stupce svojim imenima i to je u redu. Recite nam koji je koji. Ono sto smo prepoznali vec je odabrano.', Config::TEXT_DOMAIN ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'artikli' ) ); ?>
				<input type="hidden" name="uvoz_datoteka" value="<?php echo esc_attr( $mapiranje['putanja'] ); ?>">
				<input type="hidden" name="uvoz_ime" value="<?php echo esc_attr( $mapiranje['ime'] ); ?>">

				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Podatak', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Stupac u vasoj datoteci', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Prvi redak', Config::TEXT_DOMAIN ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $polja_uvoza as $polje => $naziv ) : ?>
							<?php $odabran = $mapiranje['pogodeno'][ $polje ] ?? ''; ?>
							<tr>
								<th scope="row"><?php echo esc_html( $naziv ); ?></th>
								<td>
									<select name="karta[<?php echo esc_attr( $polje ); ?>]" data-polje="<?php echo esc_attr( $polje ); ?>">
										<option value=""><?php esc_html_e( '— nema u datoteci —', Config::TEXT_DOMAIN ); ?></option>
										<?php foreach ( $mapiranje['stupci'] as $i => $ime_stupca ) : ?>
											<option value="<?php echo (int) $i; ?>" <?php selected( (string) $odabran, (string) $i ); ?>>
												<?php echo esc_html( ( '' !== trim( $ime_stupca ) ) ? $ime_stupca : sprintf( __( 'stupac %d', Config::TEXT_DOMAIN ), $i + 1 ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
									<?php echo esc_html( ( '' !== (string) $odabran && isset( $mapiranje['primjer'][ (int) $odabran ] ) ) ? $mapiranje['primjer'][ (int) $odabran ] : '—' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button class="button button-primary" name="radnja" value="probni">
						<?php esc_html_e( 'Pokazi sto bi se promijenilo', Config::TEXT_DOMAIN ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI ) ); ?>">
						<?php esc_html_e( 'Odustani', Config::TEXT_DOMAIN ); ?>
					</a>
				</p>
			</form>

		<?php else : ?>

			<?php // Korak 3: probni prolaz. ?>
			<h3><?php esc_html_e( 'Sto bi se promijenilo', Config::TEXT_DOMAIN ); ?></h3>

			<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
				<tbody>
					<?php foreach ( $uvoz['sazetak'] as $ishod => $koliko ) : ?>
						<?php if ( 0 === (int) $koliko ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<?php echo esc_html( 'ukupno' === $ishod ? __( 'Redaka u datoteci', Config::TEXT_DOMAIN ) : Uvoz::opis_ishoda( $ishod ) ); ?>
							</th>
							<td><?php echo esc_html( number_format_i18n( (int) $koliko ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$problemi = array();
			foreach ( $uvoz['redci'] as $r ) {
				if ( 'upisat_ce_se' !== $r['ishod'] && 'nema_promjena' !== $r['ishod'] ) {
					$problemi[] = $r;
				}
			}
			?>

			<?php if ( ! empty( $problemi ) ) : ?>
				<details open>
					<summary><?php esc_html_e( 'Redci koji nece proci', Config::TEXT_DOMAIN ); ?></summary>
					<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Redak', Config::TEXT_DOMAIN ); ?></th>
								<th scope="col"><?php esc_html_e( 'Sifra', Config::TEXT_DOMAIN ); ?></th>
								<th scope="col"><?php esc_html_e( 'Zasto', Config::TEXT_DOMAIN ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $problemi, 0, 30 ) as $r ) : ?>
								<tr>
									<td><?php echo (int) $r['broj']; ?></td>
									<td><code><?php echo esc_html( $r['sifra'] ); ?></code></td>
									<td><?php echo esc_html( '' !== $r['poruka'] ? $r['poruka'] : Uvoz::opis_ishoda( $r['ishod'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $problemi ) > 30 ) : ?>
						<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
							<?php
							printf(
								/* translators: 1: prikazano, 2: ukupno */
								esc_html__( 'Prikazano prvih %1$d od %2$d.', Config::TEXT_DOMAIN ),
								30,
								count( $problemi )
							);
							?>
						</p>
					<?php endif; ?>
				</details>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'artikli' ) ); ?>
				<input type="hidden" name="uvoz_datoteka" value="<?php echo esc_attr( $uvoz['putanja'] ); ?>">
				<input type="hidden" name="uvoz_ime" value="<?php echo esc_attr( $uvoz['ime'] ?? '' ); ?>">
				<?php foreach ( (array) ( $uvoz['karta'] ?? array() ) as $polje => $i ) : ?>
					<input type="hidden" name="karta[<?php echo esc_attr( $polje ); ?>]" value="<?php echo esc_attr( $i ); ?>">
				<?php endforeach; ?>
				<button class="button button-primary" name="radnja" value="uvezi">
					<?php
					printf(
						/* translators: %d = broj redaka */
						esc_html__( 'Upisi %d redaka', Config::TEXT_DOMAIN ),
						(int) ( $uvoz['sazetak']['upisat_ce_se'] ?? 0 )
					);
					?>
				</button>
				<a class="button" href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI ) ); ?>">
					<?php esc_html_e( 'Odustani', Config::TEXT_DOMAIN ); ?>
				</a>
			</form>

		<?php endif; ?>
	</div>

	<hr class="<?php echo esc_attr( Config::css( 'granica' ) ); ?>">

	<?php /* ======================= RUCNA DOPUNA ======================= */ ?>
	<h2><?php esc_html_e( 'Rucna dopuna', Config::TEXT_DOMAIN ); ?></h2>

	<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
		<?php esc_html_e( 'Za pojedinacne artikle i ispravke. Poredano po prometu, pa ako stanete na pola, stali ste na pravom mjestu.', Config::TEXT_DOMAIN ); ?>
	</p>

	<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
		<?php esc_html_e( 'Ista polja postoje i na samom proizvodu: otvorite proizvod i potrazite karticu "Podaci za cjenik", uz Opcenito, Inventar i Otpremu. Ondje su i polja koja ovdje nema — jedinica mjere, oblik prodaje i oznaka "ne odnosi se" po polju. Poveznica "otvori proizvod" u svakom retku vodi ravno onamo.', Config::TEXT_DOMAIN ); ?>
	</p>

	<p class="<?php echo esc_attr( Config::css( 'filtri' ) ); ?>">
		<a class="<?php echo esc_attr( '' === $filtar ? Config::css( 'filtar-aktivan' ) : '' ); ?>"
			href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI ) ); ?>">
			<?php esc_html_e( 'sve', Config::TEXT_DOMAIN ); ?>
		</a>
		<?php foreach ( $po_poljima as $kljuc => $p ) : ?>
			&middot;
			<a class="<?php echo esc_attr( $filtar === $kljuc ? Config::css( 'filtar-aktivan' ) : '' ); ?>"
				href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI, array( 'filtar' => $kljuc ) ) ); ?>">
				<?php echo esc_html( mb_strtolower( $p['naziv'] ) ); ?>
			</a>
		<?php endforeach; ?>
	</p>

	<?php if ( empty( $redci ) ) : ?>
		<p><?php esc_html_e( 'Nema artikala kojima nesto nedostaje. Gotovo.', Config::TEXT_DOMAIN ); ?></p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( Config::nonce( 'artikli' ) ); ?>

			<table class="<?php echo esc_attr( Config::css( 'stavke' ) . ' ' . Config::css( 'unos' ) ); ?>">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Artikl', Config::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Barkod', Config::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Marka', Config::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kolicina', Config::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ne odnosi se', Config::TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $redci as $r ) : ?>
						<?php $id = (int) $r->entity_id; ?>
						<tr>
							<td>
								<?php echo esc_html( mb_substr( (string) $r->naziv, 0, 55 ) ); ?>
								<span class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
									<?php echo esc_html( '' !== $r->sku ? $r->sku : '#' . $id ); ?>
								</span>
								<?php
								// Poveznica na KONKRETAN proizvod, ne opca uputa. Ovdje se ureduje
								// nekoliko polja; sve ostalo o artiklu je ondje.
								$veza = CJTR\Podaci\Woo_Polja::gdje_se_mijenja( $id, Config::POLJE_BARKOD );
								?>
								<?php if ( '' !== $veza ) : ?>
									<a class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>" href="<?php echo esc_url( $veza ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'otvori proizvod', Config::TEXT_DOMAIN ); ?>
									</a>
								<?php endif; ?>
							</td>
							<td>
								<input type="text" name="r[<?php echo $id; ?>][barkod]"
									value="<?php echo esc_attr( (string) $r->barkod ); ?>" size="14">
							</td>
							<td>
								<input type="text" name="r[<?php echo $id; ?>][marka]"
									value="<?php echo esc_attr( (string) $r->marka ); ?>" size="14">
							</td>
							<td>
								<input type="text" name="r[<?php echo $id; ?>][neto_kolicina]"
									value="<?php echo esc_attr( (string) $r->neto_kolicina ); ?>" size="6">
								<input type="text" name="r[<?php echo $id; ?>][jedinica_mjere]"
									value="<?php echo esc_attr( (string) $r->jedinica_mjere ); ?>" size="4">
							</td>
							<td class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
								<?php foreach ( Config::POLJA as $polje => $meta ) : ?>
									<label>
										<input type="checkbox" name="np[<?php echo $id; ?>][]" value="<?php echo esc_attr( $polje ); ?>"
											<?php checked( isset( $neprimjenjivo[ $id ][ $polje ] ) ); ?>>
										<?php echo esc_html( mb_strtolower( $meta['naziv'] ) ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<label>
					<?php esc_html_e( 'Razlog za "ne odnosi se":', Config::TEXT_DOMAIN ); ?>
					<input type="text" name="np_razlog" size="60"
						placeholder="<?php esc_attr_e( 'npr. vlastita proizvodnja, barkod nije dodijeljen', Config::TEXT_DOMAIN ); ?>">
				</label>
			</p>
			<p class="<?php echo esc_attr( Config::css( 'sitno' ) ); ?>">
				<?php esc_html_e( 'Oznaka bez razloga nije odluka nego pogadanje, pa se ne prima. Razlog, vase ime i datum ostaju zapisani.', Config::TEXT_DOMAIN ); ?>
			</p>

			<p>
				<button class="button button-primary" name="radnja" value="spremi">
					<?php esc_html_e( 'Spremi', Config::TEXT_DOMAIN ); ?>
				</button>
			</p>
		</form>

		<?php
		$stranica_ukupno = (int) ceil( $ukupno_redaka / $po_stranici );
		?>
		<?php if ( $stranica_ukupno > 1 ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'stranicenje' ) ); ?>">
				<?php
				printf(
					/* translators: 1: trenutna, 2: ukupno */
					esc_html__( 'Stranica %1$d od %2$d', Config::TEXT_DOMAIN ),
					(int) $stranica,
					(int) $stranica_ukupno
				);
				?>
				<?php if ( $stranica > 1 ) : ?>
					<a href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI, array( 'filtar' => $filtar, 'stranica' => $stranica - 1 ) ) ); ?>">
						<?php esc_html_e( 'natrag', Config::TEXT_DOMAIN ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $stranica < $stranica_ukupno ) : ?>
					<a href="<?php echo esc_url( Admin::url( Admin::STRANICA_ARTIKLI, array( 'filtar' => $filtar, 'stranica' => $stranica + 1 ) ) ); ?>">
						<?php esc_html_e( 'dalje', Config::TEXT_DOMAIN ); ?>
					</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php Admin::opseg(); ?>
</div>
