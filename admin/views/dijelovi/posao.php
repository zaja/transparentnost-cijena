<?php
/**
 * Jedna kartica posla.
 *
 * Dva oblika. SAZETI je za odradenu pripremu: naziv, jedan redak i sekundarni
 * gumb, sve ostalo sklopljeno. Odraden korak se mora skloniti s puta, a ne
 * nestati — tko ga treba provjeriti, otvori ga.
 *
 * @var CJTR\Poslovi\Posao $posao
 * @var CJTR\Poslovi\Plan  $plan
 * @var array              $oznake
 * @var bool               $sazeto
 * @var int                $broj    Redni broj u pripremi, 0 = ne prikazuje se
 * @package CJTR
 */

use CJTR\Config;
use CJTR\Poslovi\Registar;

defined( 'ABSPATH' ) || exit;

$kljuc   = $posao->kljuc();
$s       = $plan->stanje( $kljuc );
$radi    = $s->radi();
$ceka    = $plan->ceka( $kljuc );
$zapreka = $posao->zapreka();
$moze    = $plan->moze( $kljuc );

/*
 * Primarni gumb dobiva samo posao koji je dio puta prema naprijed — priprema i
 * ponistavanje, koje se otvara namjerno. Redovan rad se u svom uvodu izricito
 * opisuje kao nesto sto ne treba pokretati rucno, pa bi ondje primarni gumb
 * proturjecio recenici tik iznad sebe.
 */
$primarni = ( Config::SKUPINA_ODRZAVANJE !== $posao->skupina() ) ? ' button-primary' : '';

$klase = array( Config::css( 'kartica' ), Config::css( 'posao' ) );
if ( $sazeto ) {
	$klase[] = Config::css( 'posao-sazeto' );
}
if ( '' !== $ceka ) {
	$klase[] = Config::css( 'posao-ceka' );
}
?>
<div class="<?php echo esc_attr( implode( ' ', $klase ) ); ?>" data-kljuc="<?php echo esc_attr( $kljuc ); ?>">

	<h2 class="<?php echo esc_attr( Config::css( 'posao-zaglavlje' ) ); ?>">
		<?php if ( $broj > 0 ) : ?>
			<span class="<?php echo esc_attr( Config::css( 'korak' ) . ' ' . Config::css( $sazeto ? 'korak-gotov' : 'korak-ceka' ) ); ?>" aria-hidden="true">
				<?php echo $sazeto ? '&#10003;' : (int) $broj; ?>
			</span>
		<?php endif; ?>
		<span class="<?php echo esc_attr( Config::css( 'posao-naziv' ) ); ?>"><?php echo esc_html( $posao->naziv() ); ?></span>
		<?php
		/*
		 * U sazetom obliku kvacica vec kaze da je gotovo — oznaka "gotovo" uz nju
		 * ponavlja istu obavijest i samo trosi redak. Sazima se samo posao koji JEST
		 * gotov, pa ondje druge vrijednosti i nema.
		 *
		 * Oznaka ipak ostaje u dokumentu, skrivena, jer je cita skripta koja prati
		 * napredak — uklonjena bi tiho prekinula osvjezavanje.
		 */
		?>
		<span class="<?php echo esc_attr( Config::css( 'oznaka' ) . ' ' . Config::css( 'oznaka-' . $s->status ) . ( $sazeto ? ' screen-reader-text' : '' ) ); ?>" data-uloga="status">
			<?php echo esc_html( $oznake[ $s->status ] ?? $s->status ); ?>
		</span>
	</h2>

	<?php if ( $sazeto ) : ?>

		<?php
		/*
		 * Odradena priprema: kvacica, ime, datum. Nista vise.
		 *
		 * Opis posla ovdje vise nema svrhu — administrator ga je procitao PRIJE nego
		 * ga je pokrenuo. Zapisnik i mogucnost ponovnog pokretanja idu iza
		 * "Pojedinosti", jer ih treba tek onaj tko nesto provjerava.
		 */
		?>
		<p class="<?php echo esc_attr( Config::css( 'izvrseno' ) ); ?>">
			<?php
			printf(
				/* translators: %s = datum i vrijeme */
				esc_html__( 'Izvrseno %s.', Config::TEXT_DOMAIN ),
				esc_html( wp_date( 'd.m.Y. H:i', strtotime( (string) $s->zavrseno . ' UTC' ) ) )
			);
			?>
			<?php if ( $s->gresaka > 0 ) : ?>
				<span class="<?php echo esc_attr( Config::css( 'gresaka' ) ); ?>">
					<?php printf( esc_html__( 'gresaka: %d', Config::TEXT_DOMAIN ), (int) $s->gresaka ); ?>
				</span>
			<?php endif; ?>
		</p>

		<details class="<?php echo esc_attr( Config::css( 'log' ) ); ?>">
			<summary><?php esc_html_e( 'Pojedinosti', Config::TEXT_DOMAIN ); ?></summary>

			<p class="<?php echo esc_attr( Config::css( 'posao-opis' ) ); ?>"><?php echo esc_html( $posao->opis() ); ?></p>

			<p class="<?php echo esc_attr( Config::css( 'izvrseno' ) ); ?>">
				<?php
				printf(
					/* translators: 1: obradeno, 2: preskoceno, 3: gresaka */
					esc_html__( 'Obradeno %1$s, preskoceno %2$s, gresaka %3$s.', Config::TEXT_DOMAIN ),
					esc_html( number_format_i18n( $s->obradeno ) ),
					esc_html( number_format_i18n( $s->preskoceno ) ),
					esc_html( number_format_i18n( $s->gresaka ) )
				);
				?>
			</p>

			<?php require CJTR_DIR . 'admin/views/dijelovi/zapisnik.php'; ?>

			<?php
			/*
			 * Ponovno pokretanje je legitimno, ali rijetko. Kao gumb bi u nizu od
			 * sedam odradenih koraka izgledalo kao sedam poziva na akciju, a nijedan
			 * se ne bi trebao pritisnuti. Zato poveznica, i to iza "Pojedinosti".
			 */
			?>
			<form method="post" class="<?php echo esc_attr( Config::css( 'radnje' ) . ' ' . Config::css( 'radnje-sporedno' ) ); ?>">
				<?php wp_nonce_field( Config::nonce( 'posao' ) ); ?>
				<input type="hidden" name="kljuc" value="<?php echo esc_attr( $kljuc ); ?>">
				<button class="button-link" name="radnja" value="pokreni" <?php disabled( ! $moze ); ?>>
					<?php esc_html_e( 'Pokreni ovaj korak ponovno', Config::TEXT_DOMAIN ); ?>
				</button>
			</form>
		</details>

	<?php else : ?>

		<p class="<?php echo esc_attr( Config::css( 'posao-opis' ) ); ?>"><?php echo esc_html( $posao->opis() ); ?></p>

		<?php if ( '' !== $ceka ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'ceka' ) ); ?>">
				<strong><?php esc_html_e( 'Jos nije na redu.', Config::TEXT_DOMAIN ); ?></strong>
				<?php echo esc_html( $ceka ); ?>
			</p>
		<?php elseif ( '' !== $zapreka && ! $radi ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'zapreka' ) ); ?>">
				<strong><?php esc_html_e( 'Ne treba pokretati:', Config::TEXT_DOMAIN ); ?></strong>
				<?php echo esc_html( $zapreka ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $s->zavrseno ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'izvrseno' ) ); ?>">
				<?php
				printf(
					/* translators: 1: datum, 2: obradeno, 3: preskoceno, 4: gresaka */
					esc_html__( 'Zadnji put izvrseno %1$s — obradeno %2$s, preskoceno %3$s, gresaka %4$s.', Config::TEXT_DOMAIN ),
					esc_html( wp_date( 'd.m.Y. H:i', strtotime( $s->zavrseno . ' UTC' ) ) ),
					esc_html( number_format_i18n( $s->obradeno ) ),
					esc_html( number_format_i18n( $s->preskoceno ) ),
					esc_html( number_format_i18n( $s->gresaka ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		/*
		 * Ponisteni posao. Zapis o izvrsenju gore OSTAJE — brisanje traga unistava
		 * dokaz. Ali uz njega mora stajati da rezultat vise ne opisuje stanje
		 * podataka, jer je upravo izostanak te recenice jednom odveo na krivi trag.
		 */
		?>
		<?php if ( $s->ponisten() ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'ponisteno' ) ); ?>">
				<strong><?php esc_html_e( 'Taj je rezultat ponisten.', Config::TEXT_DOMAIN ); ?></strong>
				<?php
				$povratnik = Registar::nadi( (string) $s->ponistio );

				printf(
					/* translators: 1: datum ponistenja, 2: naziv posla povrata */
					esc_html__( 'Vraceno %1$s poslom "%2$s". Brojke iznad vise ne opisuju stanje podataka — ako rezultat treba, posao se mora pokrenuti ponovno.', Config::TEXT_DOMAIN ),
					esc_html( $s->ponisteno ? wp_date( 'd.m.Y. H:i', strtotime( $s->ponisteno . ' UTC' ) ) : '' ),
					esc_html( $povratnik ? $povratnik->naziv() : (string) $s->ponistio )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( $radi ) : ?>
			<div class="<?php echo esc_attr( Config::css( 'traka' ) ); ?>">
				<div class="<?php echo esc_attr( Config::css( 'traka-ispuna' ) ); ?>"
					data-uloga="traka" style="width: <?php echo esc_attr( $s->postotak() ); ?>%"></div>
			</div>

			<p class="<?php echo esc_attr( Config::css( 'napredak' ) ); ?>">
				<span data-uloga="napredak">
					<?php
					printf(
						/* translators: 1: obradeno, 2: ukupno, 3: postotak */
						esc_html__( '%1$s od %2$s (%3$s %%)', Config::TEXT_DOMAIN ),
						esc_html( number_format_i18n( $s->obradeno ) ),
						esc_html( number_format_i18n( $s->ukupno ) ),
						esc_html( number_format_i18n( $s->postotak(), 1 ) )
					);
					?>
				</span>
				<span data-uloga="preostalo">
					<?php
					$preostalo = $s->preostalo_s();
					if ( null !== $preostalo ) {
						printf( ' — ' . esc_html__( 'preostalo oko %s', Config::TEXT_DOMAIN ), esc_html( human_time_diff( time(), time() + $preostalo ) ) );
					}
					?>
				</span>
				<?php if ( $s->gresaka > 0 ) : ?>
					<span class="<?php echo esc_attr( Config::css( 'gresaka' ) ); ?>">
						<?php printf( esc_html__( 'gresaka: %d', Config::TEXT_DOMAIN ), (int) $s->gresaka ); ?>
					</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $s->poruka ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'postupak' ) ); ?>"><?php echo esc_html( $s->poruka ); ?></p>
		<?php endif; ?>

		<form method="post" class="<?php echo esc_attr( Config::css( 'radnje' ) ); ?>">
			<?php wp_nonce_field( Config::nonce( 'posao' ) ); ?>
			<input type="hidden" name="kljuc" value="<?php echo esc_attr( $kljuc ); ?>">

			<?php if ( ! $radi && Config::STATUS_PAUZA !== $s->status ) : ?>
				<?php // Posao koji jos nije na redu ili se ne treba pokretati je ONEMOGUCEN. ?>
				<button class="button<?php echo esc_attr( $primarni ); ?>" name="radnja" value="pokreni" <?php disabled( ! $moze ); ?>>
					<?php
					if ( $s->ponisten() ) {
						esc_html_e( 'Pokreni iznova', Config::TEXT_DOMAIN );
					} elseif ( $s->zavrseno ) {
						esc_html_e( 'Pokreni ponovno', Config::TEXT_DOMAIN );
					} else {
						esc_html_e( 'Pokreni', Config::TEXT_DOMAIN );
					}
					?>
				</button>
			<?php endif; ?>

			<?php if ( $radi ) : ?>
				<button class="button" name="radnja" value="pauziraj"><?php esc_html_e( 'Pauziraj', Config::TEXT_DOMAIN ); ?></button>
			<?php endif; ?>

			<?php if ( Config::STATUS_PAUZA === $s->status ) : ?>
				<button class="button button-primary" name="radnja" value="nastavi"><?php esc_html_e( 'Nastavi', Config::TEXT_DOMAIN ); ?></button>
			<?php endif; ?>

			<?php if ( $radi || Config::STATUS_PAUZA === $s->status ) : ?>
				<button class="button" name="radnja" value="prekini"><?php esc_html_e( 'Prekini', Config::TEXT_DOMAIN ); ?></button>
			<?php endif; ?>
		</form>

		<?php require CJTR_DIR . 'admin/views/dijelovi/zapisnik.php'; ?>

	<?php endif; ?>

</div>
