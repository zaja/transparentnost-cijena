<?php
/**
 * Ekran pregleda stanja dodatnih cijena.
 *
 * @var array  $stanje
 * @var string $obavijest
 * @var array  $zaok_promjene
 * @var array|null $zaok_ucinak
 * @var array  $zaok_potvrda
 * @var int    $zaok_broj
 * @package CJTR
 */

use CJTR\Config;
use CJTR\Preuzimanje;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Sidrene cijene', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
		<?php
		printf(
			/* translators: %s = referentni datum */
			esc_html__( 'Stanje na dan kada se utvrduje sidrena cijena: %s. Ova stranica samo prikazuje stanje i ne mijenja nista.', Config::TEXT_DOMAIN ),
			esc_html( wp_date( 'd.m.Y.', Config::t_ref( Config::REF_DATUM_OSTALO ) ) )
		);
		?>
	</p>

	<?php if ( '' === $stanje['zadnje'] ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Sidrene cijene jos nisu utvrdene. Pokrenite posao "Utvrdi sidrenu cijenu" na stranici Obrada.', Config::TEXT_DOMAIN ); ?></p>
		</div>
	<?php else : ?>
		<p class="<?php echo esc_attr( Config::css( 'napredak' ) ); ?>">
			<?php
			printf(
				/* translators: %s = datum i vrijeme */
				esc_html__( 'Zadnji put utvrdeno: %s', Config::TEXT_DOMAIN ),
				esc_html( wp_date( 'd.m.Y. H:i', strtotime( $stanje['zadnje'] . ' UTC' ) ) )
			);
			?>
		</p>
	<?php endif; ?>

	<!-- kontrolni zbroj -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( $stanje['zbroj_se_slaze'] ? 'status-ok' : 'status-lose' ) ); ?>">
		<h2>
			<span class="<?php echo esc_attr( Config::css( 'znak' ) ); ?>" aria-hidden="true"></span>
			<?php esc_html_e( 'Kontrolni zbroj', Config::TEXT_DOMAIN ); ?>
		</h2>
		<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Artikala i varijanti u trgovini', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stanje['ukupno_katalog'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Obradeno i razvrstano', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stanje['ukupno_upisano'] ) ); ?></td>
				</tr>
				<?php if ( ! $stanje['zbroj_se_slaze'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Razlika', Config::TEXT_DOMAIN ); ?></th>
						<td><strong><?php echo esc_html( number_format_i18n( abs( $stanje['ukupno_katalog'] - $stanje['ukupno_upisano'] ) ) ); ?></strong></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cijena poznata, ali ne i otkad vrijedi', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stanje['bez_pocetka'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Provjera praznih polja', Config::TEXT_DOMAIN ); ?></th>
					<td>
						<?php if ( empty( $stanje['nule'] ) ) : ?>
							<?php esc_html_e( 'prosla', Config::TEXT_DOMAIN ); ?>
						<?php else : ?>
							<strong class="<?php echo esc_attr( Config::css( 'gresaka' ) ); ?>">
								<?php echo esc_html( CJTR\Db::opis_nula( $stanje['nule'] ) ); ?>
							</strong>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! $stanje['zbroj_se_slaze'] ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'postupak' ) ); ?>">
				<strong><?php esc_html_e( 'Sto uciniti:', Config::TEXT_DOMAIN ); ?></strong>
				<?php esc_html_e( 'Broj razvrstanih artikala ne odgovara broju artikala u trgovini. To se dogodi ako je katalog mijenjan nakon zadnjeg utvrdivanja. Pokrenite posao ponovno.', Config::TEXT_DOMAIN ); ?>
			</p>
		<?php endif; ?>
	</div>

	<!-- raspodjela -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Raspodjela po skupinama', Config::TEXT_DOMAIN ); ?></h2>

		<?php if ( empty( $stanje['izvori'] ) ) : ?>
			<p><?php esc_html_e( 'Jos nema podataka.', Config::TEXT_DOMAIN ); ?></p>
		<?php else : ?>
			<?php foreach ( $stanje['izvori'] as $izvor => $d ) : ?>
				<div class="<?php echo esc_attr( Config::css( 'skupina' ) ); ?>">
					<h3>
						<?php echo esc_html( $d['naslov'] ); ?>
						<span class="<?php echo esc_attr( Config::css( 'oznaka' ) ); ?>">
							<?php
							printf(
								/* translators: %s = broj artikala */
								esc_html__( '%s artikala', Config::TEXT_DOMAIN ),
								esc_html( number_format_i18n( $d['n'] ) )
							);
							?>
						</span>
					</h3>
					<p><?php echo esc_html( $d['opis'] ); ?></p>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<!-- sto treba napraviti -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( ( $stanje['ceka_odluku'] + $stanje['ceka_unos'] ) > 0 ? 'status-upozorenje' : 'status-ok' ) ); ?>">
		<h2>
			<span class="<?php echo esc_attr( Config::css( 'znak' ) ); ?>" aria-hidden="true"></span>
			<?php esc_html_e( 'Sto jos treba napraviti', Config::TEXT_DOMAIN ); ?>
		</h2>
		<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ceka vasu odluku', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stanje['ceka_odluku'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ceka rucni unos', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stanje['ceka_unos'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- popisi -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Popisi za preuzimanje', Config::TEXT_DOMAIN ); ?></h2>
		<p><?php esc_html_e( 'Popisi se sastavljaju u trenutku preuzimanja i ne stoje na posluzitelju. Moze ih preuzeti samo prijavljeni korisnik s ovlastima.', Config::TEXT_DOMAIN ); ?></p>

		<p class="<?php echo esc_attr( Config::css( 'radnje' ) ); ?>">
			<?php foreach ( Config::RADNI_POPISI as $izvor => $ime ) : ?>
				<?php $koliko = Preuzimanje::broj( $izvor ); ?>
				<?php if ( $koliko > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( Preuzimanje::url( $izvor ) ); ?>">
						<?php
						printf(
							/* translators: 1: naziv popisa, 2: broj redaka */
							esc_html__( '%1$s (%2$s)', Config::TEXT_DOMAIN ),
							esc_html( Config::OPIS_IZVORA[ $izvor ]['naslov'] ?? $ime ),
							esc_html( number_format_i18n( $koliko ) )
						);
						?>
					</a>
				<?php else : ?>
					<span class="button disabled" aria-disabled="true">
						<?php
						printf(
							/* translators: %s = naziv popisa */
							esc_html__( '%s — prazno', Config::TEXT_DOMAIN ),
							esc_html( Config::OPIS_IZVORA[ $izvor ]['naslov'] ?? $ime )
						);
						?>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
		</p>
	</div>


	<?php
	/*
	 * Svodenje na dvije decimale. Prethodi cjeniku i zato stoji iznad njega:
	 * dok se ne izvede, objavljena datoteka za isti artikl nosi dvije razlicite
	 * brojke — maloprodajnu 3.849 i cijenu po jedinici 3.85.
	 */
	?>
	<?php if ( ! empty( $zaok_promjene ) || ! empty( $zaok_potvrda ) ) : ?>
		<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
			<h2><?php esc_html_e( 'Cijene s vise od dvije decimale', Config::TEXT_DOMAIN ); ?></h2>

			<?php if ( empty( $zaok_promjene ) ) : ?>
				<p><?php esc_html_e( 'Vise nema takvih cijena. Posao je odraden.', Config::TEXT_DOMAIN ); ?></p>
			<?php else : ?>
				<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
					<?php esc_html_e( 'Ostatak preracuna iz kuna. Zbog njih cjenik za isti artikl objavljuje dvije razlicite brojke. Svode se na dvije decimale, uvijek prema dolje — cijena se time samo snizuje, i to za manje od centa. Ovaj ekran nista ne mijenja; posao se pokrece na stranici Obrada, nakon potvrde.', Config::TEXT_DOMAIN ); ?>
				</p>

				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Artikala koje posao dira', Config::TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (int) $zaok_broj ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ukupno snizenje po komadu', Config::TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (float) $zaok_ucinak['zbroj_po_komadu'], 4 ) . ' ' . get_woocommerce_currency() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Procijenjen ucinak na godinu', Config::TEXT_DOMAIN ); ?></th>
							<td>
								<?php
								printf(
									/* translators: 1: iznos, 2: valuta, 3: artikala, 4: komada */
									esc_html__( '%1$s %2$s manje prihoda (%3$s artikala, %4$s prodanih komada u 12 mjeseci)', Config::TEXT_DOMAIN ),
									esc_html( number_format_i18n( (float) $zaok_ucinak['godisnje'], 2 ) ),
									esc_html( get_woocommerce_currency() ),
									esc_html( number_format_i18n( (int) $zaok_ucinak['prodavanih'] ) ),
									esc_html( number_format_i18n( (int) $zaok_ucinak['komada'] ) )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>

				<p>
					<a class="button" href="<?php echo esc_url( Preuzimanje::url( Preuzimanje::POPIS_ZAOKRUZIVANJE ) ); ?>">
						<?php esc_html_e( 'Preuzmi cijeli popis (CSV)', Config::TEXT_DOMAIN ); ?>
					</a>
				</p>

				<h3><?php esc_html_e( 'Probni prolaz', Config::TEXT_DOMAIN ); ?></h3>

				<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Artikl', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Polje', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Prije', Config::TEXT_DOMAIN ); ?></th>
							<th scope="col"><?php esc_html_e( 'Poslije', Config::TEXT_DOMAIN ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $zaok_promjene as $z ) : ?>
							<?php foreach ( $z['polja'] as $polje => $par ) : ?>
								<tr>
									<td><?php echo esc_html( mb_substr( $z['naziv'], 0, 50 ) ); ?> <code>#<?php echo (int) $z['entity_id']; ?></code></td>
									<td><code><?php echo esc_html( $polje ); ?></code></td>
									<td><?php echo esc_html( $par[0] ); ?></td>
									<td><strong><?php echo esc_html( $par[1] ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( count( $zaok_promjene ) >= 40 ) : ?>
					<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
						<?php esc_html_e( 'Prikazano prvih 40 artikala. Cijeli popis je u CSV-u — skracivanje je ovdje samo radi brzine ekrana, posao obraduje sve.', Config::TEXT_DOMAIN ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( empty( $zaok_potvrda ) ) : ?>
				<?php if ( ! empty( $zaok_promjene ) ) : ?>
					<form method="post">
						<?php wp_nonce_field( Config::nonce( 'zaokruzivanje' ) ); ?>
						<button class="button button-primary" name="radnja" value="potvrdi_zaokruzivanje">
							<?php
							printf(
								/* translators: %s = broj artikala */
								esc_html__( 'Potvrdujem za %s artikala', Config::TEXT_DOMAIN ),
								esc_html( number_format_i18n( (int) $zaok_broj ) )
							);
							?>
						</button>
					</form>
					<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>">
						<?php esc_html_e( 'Posao se ne moze pokrenuti dok potvrda nije dana. Potvrda se odnosi na skup prikazan iznad; promijeni li se broj artikala, trazit ce se ponovno.', Config::TEXT_DOMAIN ); ?>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p>
					<strong><?php esc_html_e( 'Potvrdeno.', Config::TEXT_DOMAIN ); ?></strong>
					<?php
					printf(
						/* translators: 1: datum, 2: broj artikala */
						esc_html__( '%1$s za %2$s artikala. Posao se moze pokrenuti na stranici Obrada.', Config::TEXT_DOMAIN ),
						esc_html( wp_date( 'd.m.Y. H:i', strtotime( $zaok_potvrda['kad'] . ' UTC' ) ) ),
						esc_html( number_format_i18n( (int) $zaok_potvrda['artikala'] ) )
					);
					?>
				</p>
				<form method="post">
					<?php wp_nonce_field( Config::nonce( 'zaokruzivanje' ) ); ?>
					<button class="button" name="radnja" value="povuci_zaokruzivanje"><?php esc_html_e( 'Povuci potvrdu', Config::TEXT_DOMAIN ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	/*
	 * Cjenik. Stoji na ekranu pregleda, a ne na vlastitom: to je ISHOD svega
	 * ostalog, pa pripada uz stanje dodatnih cijena, ne u zaseban kutak.
	 */
	$cjenik_zadnji = get_option( Config::option( Config::OPT_ZADNJI_CJENIK ), array() );
	$cjenik_ima    = is_array( $cjenik_zadnji ) && ! empty( $cjenik_zadnji['kad'] );
	$cjenik_danas  = CJTR\Cjenik\Arhiva::od_danas( Config::CJENIK_OBLICI[0] );
	$cron_ok       = (bool) wp_next_scheduled( Config::hook( CJTR\Poslovi\Raspored::HOOK_DNEVNO ) );
	?>
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( $cjenik_danas ? 'status-ok' : 'status-lose' ) ); ?>">
		<h2>
			<span class="<?php echo esc_attr( Config::css( 'znak' ) ); ?>" aria-hidden="true"></span>
			<?php esc_html_e( 'Dnevni cjenik', Config::TEXT_DOMAIN ); ?>
		</h2>

		<?php if ( ! $cjenik_ima ) : ?>
			<p><?php esc_html_e( 'Cjenik jos nije generiran. Pokrenite posao "Objavi dnevni cjenik" na stranici Obrada.', Config::TEXT_DOMAIN ); ?></p>
		<?php else : ?>
			<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Zadnji put objavljeno', Config::TEXT_DOMAIN ); ?></th>
						<td>
							<?php echo esc_html( wp_date( 'd.m.Y. H:i', (int) $cjenik_zadnji['kad'] ) ); ?>
							<?php if ( ! $cjenik_danas ) : ?>
								<strong class="<?php echo esc_attr( Config::css( 'gresaka' ) ); ?>">
									<?php esc_html_e( '— nije od danas', Config::TEXT_DOMAIN ); ?>
								</strong>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Artikala u datoteci', Config::TEXT_DOMAIN ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $cjenik_zadnji['zapisa'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rok objave', Config::TEXT_DOMAIN ); ?></th>
						<td>
							<?php
							printf(
								/* translators: %d = sat */
								esc_html__( 'svaki dan do %02d:00 po vremenu trgovine', Config::TEXT_DOMAIN ),
								(int) Config::ROK_OBJAVE_SAT
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Javne poveznice', Config::TEXT_DOMAIN ); ?></th>
						<td>
							<?php foreach ( Config::CJENIK_OBLICI as $oblik ) : ?>
								<a href="<?php echo esc_url( CJTR\Cjenik\Arhiva::stabilni_url( $oblik ) ); ?>" target="_blank"><?php echo esc_html( strtoupper( $oblik ) ); ?></a>
							<?php endforeach; ?>
							&middot;
							<a href="<?php echo esc_url( CJTR\Cjenik\Arhiva::indeks_url() ); ?>" target="_blank"><?php esc_html_e( 'arhiva', Config::TEXT_DOMAIN ); ?></a>
							&middot;
							<a href="<?php echo esc_url( CJTR\Cjenik\Objava::rest_url( Config::CJENIK_OBLICI[0] ) ); ?>" target="_blank"><?php esc_html_e( 'REST', Config::TEXT_DOMAIN ); ?></a>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Samoprovjera', Config::TEXT_DOMAIN ); ?></th>
						<td>
							<?php
							$cjenik_provjera = ( new CJTR\Diagnostika\Provjere\Cjenik() )->izvrsi();
							echo esc_html(
								CJTR\Diagnostika\Rezultat::OK === $cjenik_provjera->status
									? __( 'prosla', Config::TEXT_DOMAIN )
									: __( 'NIJE prosla — vidi Dijagnostiku', Config::TEXT_DOMAIN )
							);
							?>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! $cron_ok ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'zapreka' ) ); ?>">
				<strong><?php esc_html_e( 'Automatsko pokretanje nije postavljeno.', Config::TEXT_DOMAIN ); ?></strong>
				<?php esc_html_e( 'Bez njega cjenik izlazi samo kad ga netko rucno pokrene. Gotov redak za posluzitelj je na stranici Dijagnostika.', Config::TEXT_DOMAIN ); ?>
			</p>
		<?php endif; ?>
	</div>

	<?php CJTR\Admin::opseg(); ?>
</div>
