<?php
/**
 * Ekran pregleda prije promocije akcijskih cijena u redovne.
 *
 * KORAK 1. Ovaj ekran NE MIJENJA NISTA. Pokazuje sto bi se promijenilo i trazi
 * potvrdu. Upis radi posao na ekranu obrade, i to tek nakon sto potvrda postoji.
 *
 * @var array    $sazetak
 * @var object[] $redci
 * @var object[] $nesuglasni
 * @var int      $bez_podatka
 * @var array    $potvrda
 * @var string   $obavijest
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Config;
use CJTR\Preuzimanje;

defined( 'ABSPATH' ) || exit;

$prikazano = 100;
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Akcijska cijena u redovnu', Config::TEXT_DOMAIN ); ?></h1>

	<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
		<?php esc_html_e( 'Ovaj ekran ne mijenja nista. Pokazuje koje bi se cijene promijenile i trazi potvrdu. Nakon potvrde posao se moze pokrenuti na stranici Obrada.', Config::TEXT_DOMAIN ); ?>
	</p>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<!-- sazetak: proizvodi prvi, redci kao tehnicka mjera -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Opseg', Config::TEXT_DOMAIN ); ?></h2>

		<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Proizvoda o kojima se odlucuje', Config::TEXT_DOMAIN ); ?></th>
					<td><strong><?php echo esc_html( number_format_i18n( $sazetak['proizvoda'] ) ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Od toga javno vidljivih', Config::TEXT_DOMAIN ); ?></th>
					<td><strong><?php echo esc_html( number_format_i18n( $sazetak['javnih'] ) ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Varijanti unutar tih proizvoda', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $sazetak['varijanti'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Proizvoda bez varijanti', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $sazetak['samostalnih'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redaka koje posao mijenja', Config::TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $sazetak['redaka'] ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
			<?php esc_html_e( 'Odluka se donosi o proizvodima. Varijante jednog proizvoda nisu zasebne odluke — mijenjaju se zajedno s njim, i zato ih je vise nego proizvoda.', Config::TEXT_DOMAIN ); ?>
		</p>
	</div>

	<!-- artikli kod kojih promocija ne bi bila u skladu s dodatnom cijenom -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( empty( $nesuglasni ) ? 'status-ok' : 'status-lose' ) ); ?>">
		<h2>
			<span class="<?php echo esc_attr( Config::css( 'znak' ) ); ?>" aria-hidden="true"></span>
			<?php esc_html_e( 'Slaganje s sidrenom cijenom', Config::TEXT_DOMAIN ); ?>
		</h2>

		<?php if ( empty( $nesuglasni ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s = broj artikala */
					esc_html__( 'Kod svih %s artikala cijena koja se naplacuje jednaka je sidrenoj cijeni za referentni datum. Promocija je s njom u skladu.', Config::TEXT_DOMAIN ),
					esc_html( number_format_i18n( $sazetak['redaka'] ) )
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s = broj artikala */
					esc_html__( '%s artikala ima sidrenu cijenu koja se razlikuje od one koja se naplacuje. Kod njih promocija ne bi bila u skladu s sidrenom cijenom — na referentni datum je vrijedilo nesto trece. Posao ih ne dira nego preskace, i treba ih rijesiti zasebno.', Config::TEXT_DOMAIN ),
					esc_html( number_format_i18n( count( $nesuglasni ) ) )
				);
				?>
			</p>

			<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Artikl', Config::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Naplacuje se', Config::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sidrena cijena', Config::TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $nesuglasni, 0, 50 ) as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r->naziv ); ?> <code>#<?php echo (int) $r->entity_id; ?></code></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r->naplacuje_se ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r->sidrena_nakon_odluke ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $bez_podatka > 0 ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
				<?php
				printf(
					/* translators: %s = broj artikala */
					esc_html__( 'Uz to, %s artikala nema ni upisanu sidrenu cijenu ni kandidata za nju. Oni nisu nesuglasni — kod njih podatka jos nema, pa nisu imali s cime biti usporedeni.', Config::TEXT_DOMAIN ),
					esc_html( number_format_i18n( $bez_podatka ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<!-- cijene prije i poslije -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) ); ?>">
		<h2><?php esc_html_e( 'Cijene prije i poslije', Config::TEXT_DOMAIN ); ?></h2>

		<p>
			<?php esc_html_e( 'Cijena koju kupac placa ostaje ista. Mijenja se redovna cijena i nestaje oznaka akcije.', Config::TEXT_DOMAIN ); ?>
			<a class="button" href="<?php echo esc_url( Preuzimanje::url( Preuzimanje::POPIS_PROMOCIJA ) ); ?>">
				<?php esc_html_e( 'Preuzmi cijeli popis (CSV)', Config::TEXT_DOMAIN ); ?>
			</a>
		</p>

		<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Artikl', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"><?php esc_html_e( 'Naplacuje se', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"><?php esc_html_e( 'Redovna prije', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"><?php esc_html_e( 'Redovna poslije', Config::TEXT_DOMAIN ); ?></th>
					<th scope="col"><?php esc_html_e( 'Akcijska poslije', Config::TEXT_DOMAIN ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $redci, 0, $prikazano ) as $r ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $r->naziv ); ?>
							<code>#<?php echo (int) $r->entity_id; ?></code>
							<?php if ( 'product_variation' === $r->post_type ) : ?>
								<em><?php esc_html_e( '(varijanta)', Config::TEXT_DOMAIN ); ?></em>
							<?php endif; ?>
						</td>
						<td><strong><?php echo wp_kses_post( wc_price( (float) $r->naplacuje_se ) ); ?></strong></td>
						<td><?php echo wp_kses_post( wc_price( (float) $r->redovna ) ); ?></td>
						<td><strong><?php echo wp_kses_post( wc_price( (float) $r->naplacuje_se ) ); ?></strong></td>
						<td><em><?php esc_html_e( 'uklonjena', Config::TEXT_DOMAIN ); ?></em></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( count( $redci ) > $prikazano ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'napomena' ) ); ?>">
				<?php
				printf(
					/* translators: 1: prikazano, 2: ukupno */
					esc_html__( 'Prikazano prvih %1$s od %2$s redaka. Cijeli popis je u CSV-u — skracivanje je ovdje samo radi brzine ekrana, posao obraduje sve.', Config::TEXT_DOMAIN ),
					esc_html( number_format_i18n( $prikazano ) ),
					esc_html( number_format_i18n( count( $redci ) ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<!-- potvrda -->
	<div class="<?php echo esc_attr( Config::css( 'kartica' ) . ' ' . Config::css( empty( $potvrda ) ? 'status-lose' : 'status-ok' ) ); ?>">
		<h2>
			<span class="<?php echo esc_attr( Config::css( 'znak' ) ); ?>" aria-hidden="true"></span>
			<?php esc_html_e( 'Potvrda', Config::TEXT_DOMAIN ); ?>
		</h2>

		<?php if ( empty( $potvrda ) ) : ?>
			<p>
				<?php esc_html_e( 'Posao se ne moze pokrenuti dok potvrda nije dana. Potvrda se odnosi na skup prikazan iznad; promijeni li se broj artikala, trazit ce se ponovno.', Config::TEXT_DOMAIN ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'promocija' ) ); ?>
				<button class="button button-primary" name="radnja" value="potvrdi">
					<?php
					printf(
						/* translators: %s = broj proizvoda */
						esc_html__( 'Potvrdujem za %s proizvoda', Config::TEXT_DOMAIN ),
						esc_html( number_format_i18n( $sazetak['proizvoda'] ) )
					);
					?>
				</button>
			</form>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: 1: datum, 2: proizvoda, 3: redaka */
					esc_html__( 'Potvrdeno %1$s za %2$s proizvoda (%3$s redaka). Posao se moze pokrenuti na stranici Obrada.', Config::TEXT_DOMAIN ),
					esc_html( wp_date( 'd.m.Y. H:i', strtotime( $potvrda['kad'] . ' UTC' ) ) ),
					esc_html( number_format_i18n( $potvrda['proizvoda'] ) ),
					esc_html( number_format_i18n( $potvrda['redaka'] ) )
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( Config::nonce( 'promocija' ) ); ?>
				<button class="button" name="radnja" value="povuci">
					<?php esc_html_e( 'Povuci potvrdu', Config::TEXT_DOMAIN ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<?php Admin::opseg(); ?>
</div>
