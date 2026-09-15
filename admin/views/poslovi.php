<?php
/**
 * Ekran obrade u pozadini.
 *
 * Nije popis nego redoslijed. Mjerilo: administrator koji je prvi put otvorio
 * ekran, nema konteksta i nema koga pitati, mora znati sto kliknuti prvo.
 *
 * Ekran NE racuna redoslijed — to radi Plan, iz onoga sto poslovi sami o sebi
 * kazu. Ovdje se samo ispisuje, pa novi posao iz modula koji tek dolazi ne trazi
 * izmjenu ovog fajla.
 *
 * @var CJTR\Poslovi\Plan $plan
 * @var string            $obavijest
 * @package CJTR
 */

use CJTR\Admin;
use CJTR\Config;
use CJTR\Poslovi\Pokretac;
use CJTR\Poslovi\Raspored;

defined( 'ABSPATH' ) || exit;

$oznake = array(
	Config::STATUS_CEKA      => __( 'nije pokretano', Config::TEXT_DOMAIN ),
	Config::STATUS_RADI      => __( 'u tijeku', Config::TEXT_DOMAIN ),
	Config::STATUS_PAUZA     => __( 'pauzirano', Config::TEXT_DOMAIN ),
	Config::STATUS_GOTOVO    => __( 'gotovo', Config::TEXT_DOMAIN ),
	Config::STATUS_PREKINUT  => __( 'prekinuto', Config::TEXT_DOMAIN ),
	Config::STATUS_GRESKA    => __( 'zaustavljeno zbog gresaka', Config::TEXT_DOMAIN ),
	Config::STATUS_PONISTENO => __( 'izvrseno, pa ponisteno', Config::TEXT_DOMAIN ),
);

$napredak = $plan->napredak_pripreme();
$gotova   = $plan->priprema_gotova();
?>
<div class="wrap <?php echo esc_attr( Config::css( 'wrap' ) ); ?>">

	<h1><?php esc_html_e( 'Obrada u pozadini', Config::TEXT_DOMAIN ); ?></h1>

	<?php if ( '' !== $obavijest ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $obavijest ); ?></p></div>
	<?php endif; ?>

	<?php // Jedan redak na vrhu: sto je SLJEDECE. Jedna recenica, ne popis. ?>
	<div class="<?php echo esc_attr( Config::css( 'sljedece' ) . ' ' . Config::css( $gotova ? 'sljedece-gotovo' : 'sljedece-radi' ) ); ?>">
		<p class="<?php echo esc_attr( Config::css( 'sljedece-tekst' ) ); ?>">
			<?php echo esc_html( $plan->sljedeci_korak() ); ?>
		</p>
		<?php if ( ! $gotova ) : ?>
			<p class="<?php echo esc_attr( Config::css( 'sljedece-brojac' ) ); ?>">
				<?php
				printf(
					/* translators: 1: gotovih koraka, 2: ukupno koraka */
					esc_html__( 'Priprema: %1$d od %2$d koraka.', Config::TEXT_DOMAIN ),
					(int) $napredak['gotovih'],
					(int) $napredak['ukupno']
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<?php foreach ( Config::SKUPINE as $skupina => $meta ) : ?>
		<?php
		$poslovi_skupine = $plan->skupina( $skupina );

		if ( empty( $poslovi_skupine ) ) {
			continue;
		}

		// Ponistavanje se ne nudi onome tko ga ne treba.
		if ( Config::SKUPINA_VRACANJE === $skupina && ! $plan->ima_sto_vratiti() ) {
			continue;
		}
		?>

		<?php if ( $meta['skriveno'] ) : ?>
			<details class="<?php echo esc_attr( Config::css( 'skupina' ) . ' ' . Config::css( 'skupina-napredno' ) ); ?>">
				<summary>
					<strong><?php esc_html_e( 'Napredno', Config::TEXT_DOMAIN ); ?></strong>
					<?php echo ' — ' . esc_html( $meta['naslov'] ); ?>
				</summary>
				<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>"><?php echo esc_html( $meta['uvod'] ); ?></p>
		<?php else : ?>
			<div class="<?php echo esc_attr( Config::css( 'skupina' ) ); ?>">
				<h2 class="<?php echo esc_attr( Config::css( 'skupina-naslov' ) ); ?>"><?php echo esc_html( $meta['naslov'] ); ?></h2>
				<p class="<?php echo esc_attr( Config::css( 'skupina-uvod' ) ); ?>"><?php echo esc_html( $meta['uvod'] ); ?></p>
		<?php endif; ?>

			<?php
			$broj = 0;
			foreach ( $poslovi_skupine as $kljuc => $posao ) {
				if ( Config::SKUPINA_PRIPREMA === $skupina ) {
					$broj++;
				}

				// Odradena priprema se sazima — sklanja se s puta, ali ne nestaje.
				$sazeto = ( Config::SKUPINA_PRIPREMA === $skupina )
					&& $plan->odraden( $kljuc )
					&& ! $plan->stanje( $kljuc )->radi();

				require CJTR_DIR . 'admin/views/dijelovi/posao.php';
			}
			?>

		<?php if ( $meta['skriveno'] ) : ?>
			</details>
		<?php else : ?>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>

	<p class="<?php echo esc_attr( Config::css( 'uvod' ) ); ?>">
		<?php
		printf(
			/* translators: %1$s = nacin rasporeda, %2$d = velicina komada */
			esc_html__( 'Dugi poslovi rade u malim komadima da ne opterete posluzitelj. Raspored: %1$s. Velicina komada: %2$d artikala odjednom.', Config::TEXT_DOMAIN ),
			esc_html( Raspored::nacin() ),
			(int) Pokretac::velicina_komada()
		);
		?>
	</p>

	<?php Admin::opseg(); ?>
</div>

<script>
( function () {
	var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var akcija  = <?php echo wp_json_encode( Config::hook( Config::AJAX_KOMAD ) ); ?>;
	var nonce   = <?php echo wp_json_encode( wp_create_nonce( Config::nonce( Config::AJAX_KOMAD ) ) ); ?>;
	var oznake  = <?php echo wp_json_encode( $oznake ); ?>;

	document.querySelectorAll( '.<?php echo esc_js( Config::css( 'posao' ) ); ?>' ).forEach( function ( kartica ) {
		var status = kartica.querySelector( '[data-uloga="status"]' );
		if ( ! status || status.textContent.trim() !== oznake.radi ) { return; }
		vrti( kartica, kartica.dataset.kljuc );
	} );

	// Rucni pokretac: gura komad po komad iz preglednika. Radi i kad raspored
	// na posluzitelju ne radi — zato postoji.
	function vrti( kartica, kljuc ) {
		var tijelo = new URLSearchParams();
		tijelo.append( 'action', akcija );
		tijelo.append( 'nonce', nonce );
		tijelo.append( 'kljuc', kljuc );

		fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: tijelo } )
			.then( function ( o ) { return o.json(); } )
			.then( function ( o ) {
				if ( ! o || ! o.success ) { return; }
				var d = o.data;
				var traka = kartica.querySelector( '[data-uloga="traka"]' );
				var nap   = kartica.querySelector( '[data-uloga="napredak"]' );
				var st    = kartica.querySelector( '[data-uloga="status"]' );

				if ( traka ) { traka.style.width = d.postotak + '%'; }
				if ( nap ) { nap.textContent = d.obradeno + ' / ' + d.ukupno + ' (' + d.postotak + ' %)'; }
				if ( st ) { st.textContent = oznake[ d.status ] || d.status; }

				if ( d.radi ) {
					window.setTimeout( function () { vrti( kartica, kljuc ); }, 300 );
				} else {
					window.location.reload();
				}
			} )
			.catch( function () { /* raspored na posluzitelju preuzima dalje */ } );
	}
}() );
</script>
