<?php
/**
 * Polja na ekranu proizvoda i na svakoj varijaciji.
 *
 * ZASTO I NA VARIJACIJI
 *
 * U cjenik ide varijacija, ne varijabilni roditelj: majica S i majica XXL dvije su
 * prodajne jedinice s razlicitim barkodom. Polja samo na roditelju znacila bi da
 * se 2950 varijacija ne moze popuniti nigdje osim skupnim uredivanjem.
 *
 * Marka se na varijaciji NE prikazuje — nasljeduje se od roditelja po definiciji,
 * pa bi polje ondje nudilo da se upise nesto sto je vec odredeno drugdje.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;
use CJTR\Povijest\Vrsta_Prodaje;

defined( 'ABSPATH' ) || exit;

final class Polja_Proizvoda {

	public static function init(): void {
		// Proizvod: vlastita kartica, da se ne mijesa s WooCommerceovim poljima.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'kartica' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel_proizvoda' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'spremi_proizvod' ) );

		// Varijacija: polja unutar postojeceg obrasca varijacije.
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'polja_varijacije' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'spremi_varijaciju' ), 10, 2 );
	}

	public static function kartica( array $kartice ): array {
		/*
		 * Ime kartice mora reci STO JE UNUTRA, ne kojem dodatku pripada.
		 *
		 * Zvala se "Cjenik" — i korisnik koji je sudjelovao u cijelom razvoju nije
		 * znao da postoji. "Cjenik" je ime ishoda, a covjek koji trazi gdje se upisuje
		 * barkod ne trazi ishod nego polje.
		 */
		$kartice[ Config::PREFIX ] = array(
			'label'    => __( 'Podaci za cjenik', Config::TEXT_DOMAIN ),
			'target'   => Config::css( 'panel' ),
			'class'    => array(),
			'priority' => 65,
		);

		return $kartice;
	}

	public static function panel_proizvoda(): void {
		global $post;

		$id = (int) $post->ID;

		// Preuzeo li WooCommerce polje u meduvremenu, nasa vrijednost odlazi sada —
		// da administrator ne gleda podatak koji se vise ne objavljuje.
		Woo_Polja::uskladi( $id );
		?>
		<div id="<?php echo esc_attr( Config::css( 'panel' ) ); ?>" class="panel woocommerce_options_panel">
			<div class="options_group">
				<p class="form-field">
					<strong><?php esc_html_e( 'Zasto ova kartica postoji:', Config::TEXT_DOMAIN ); ?></strong>
					<?php esc_html_e( 'propis trazi podatke koje WooCommerce ne vodi za svaki zapis — barkod po varijanti, marku uz svaki redak, vrstu snizenja. Ovdje ih ima gdje upisati.', Config::TEXT_DOMAIN ); ?>
				</p>

				<p class="form-field">
					<?php esc_html_e( 'Dodatak ne mijenja vase cijene ni bilo koji drugi podatak o proizvodu. Pise iskljucivo u vlastite tablice; obrisete li ga, trgovina je ista kao prije.', Config::TEXT_DOMAIN ); ?>
				</p>

				<p class="form-field">
					<?php esc_html_e( 'Prazno polje je zadatak; ako podatak objektivno ne postoji, oznacite "nije primjenjivo" i upisite razlog.', Config::TEXT_DOMAIN ); ?>
				</p>

				<?php
				self::polje_barkod( $id );
				self::polje_marka( $id );
				self::polje_kolicina( $id );
				self::prikaz_po_jedinici( $id );
				self::polje_oblika_prodaje( $id );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Polja varijacije.
	 *
	 * @param int      $petlja    Redni broj u obrascu.
	 * @param array    $podaci    Podaci varijacije.
	 * @param \WP_Post $varijacija
	 */
	public static function polja_varijacije( $petlja, $podaci, $varijacija ): void {
		$id    = (int) $varijacija->ID;
		$redak = Zapis_Podataka::procitaj( $id );
		$np    = Neprimjenjivo::za_vise( array( $id ) )[ $id ] ?? array();
		?>
		<div class="form-row form-row-full">
			<strong><?php esc_html_e( 'Podaci za cjenik', Config::TEXT_DOMAIN ); ?></strong>
		</div>

		<p class="form-row form-row-first">
			<label><?php esc_html_e( 'Barkod (GTIN/EAN)', Config::TEXT_DOMAIN ); ?></label>
			<input type="text" name="<?php echo esc_attr( Config::PREFIX ); ?>_barkod[<?php echo (int) $petlja; ?>]"
				value="<?php echo esc_attr( $redak->barkod ?? '' ); ?>" inputmode="numeric"
				<?php disabled( isset( $np[ Config::POLJE_BARKOD ] ) ); ?>>
			<?php self::napomena_statusa( $redak->barkod_status ?? '' ); ?>
		</p>

		<p class="form-row form-row-last">
			<label><?php esc_html_e( 'Neto kolicina', Config::TEXT_DOMAIN ); ?></label>
			<input type="text" name="<?php echo esc_attr( Config::PREFIX ); ?>_kolicina[<?php echo (int) $petlja; ?>]"
				value="<?php echo esc_attr( self::kolicina_za_prikaz( $redak ) ); ?>" size="6"
				<?php disabled( isset( $np[ Config::POLJE_KOLICINA ] ) ); ?>>
			<select name="<?php echo esc_attr( Config::PREFIX ); ?>_jedinica[<?php echo (int) $petlja; ?>]"
				<?php disabled( isset( $np[ Config::POLJE_KOLICINA ] ) ); ?>>
				<option value=""></option>
				<?php foreach ( Config::JEDINICE as $j => $def ) : ?>
					<option value="<?php echo esc_attr( $j ); ?>" <?php selected( $redak->jedinica_mjere ?? '', $j ); ?>><?php echo esc_html( $j ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php self::polje_oblika_prodaje( $id, (int) $petlja ); ?>
		<?php
	}

	/**
	 * Izbor posebnog oblika prodaje.
	 *
	 * Pojavljuje se SAMO kad se artikl stvarno prodaje ispod redovne cijene. Izbor
	 * ponuden artiklu koji nije ni na kakvoj prodaji trazi odgovor na pitanje koje
	 * ne postoji, a zapisan bi bio tvrdnja bez pokrica.
	 *
	 * @param int|null $petlja Redni broj varijacije, ili null za sam proizvod.
	 */
	private static function polje_oblika_prodaje( int $id, ?int $petlja = null ): void {
		$trenutna = Vrsta_Prodaje::izabrana( $id );
		$na_akciji = Vrsta_Prodaje::je_u_posebnoj_prodaji( $id );

		if ( ! $na_akciji ) {
			return;
		}

		$ime = Config::PREFIX . '_oblik_prodaje';
		if ( null !== $petlja ) {
			$ime .= '[' . $petlja . ']';
		}

		$dana        = Vrsta_Prodaje::trajanje_dana( $id );
		$upozorenja  = ( Config::POP_SEZONSKO === $trenutna ) ? Vrsta_Prodaje::upozorenja( $id ) : array();
		$razred_reda = ( null === $petlja ) ? 'form-field' : 'form-row form-row-full';
		?>
		<p class="<?php echo esc_attr( $razred_reda ); ?>">
			<label><?php esc_html_e( 'Oblik prodaje', Config::TEXT_DOMAIN ); ?></label>
			<select name="<?php echo esc_attr( $ime ); ?>">
				<?php foreach ( Config::IZBOR_POSEBNOG_OBLIKA as $kljuc => $natpis ) : ?>
					<option value="<?php echo esc_attr( $kljuc ); ?>" <?php selected( $trenutna ?: Config::POP_AKCIJSKA, $kljuc ); ?>>
						<?php echo esc_html( $natpis ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="description">
				<?php
				esc_html_e( 'Ovaj se artikl trenutno prodaje ispod redovne cijene, pa cjenik mora reci kako se taj oblik prodaje zove.', Config::TEXT_DOMAIN );
				if ( null !== $dana ) {
					echo ' ';
					printf(
						/* translators: %d = broj dana */
						esc_html__( 'Traje %d dana.', Config::TEXT_DOMAIN ),
						(int) $dana
					);
				}
				echo ' ';
				esc_html_e( 'Izbor vrijedi za tekuce razdoblje — promijeni li se cijena, vraca se na zadano.', Config::TEXT_DOMAIN );
				?>
			</span>
		</p>

		<?php foreach ( $upozorenja as $u ) : ?>
			<p class="<?php echo esc_attr( $razred_reda ); ?>">
				<span class="description" style="color:#996800;">⚠ <?php echo esc_html( $u ); ?></span>
			</p>
		<?php endforeach; ?>
		<?php
	}

	public static function spremi_proizvod( $post_id ): void {
		$id = (int) $post_id;

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return;
		}

		// Nonce provjerava WooCommerce prije nego pozove ovaj hook.
		self::spremi_polja(
			$id,
			isset( $_POST[ Config::PREFIX . '_barkod' ] ) ? sanitize_text_field( wp_unslash( $_POST[ Config::PREFIX . '_barkod' ] ) ) : null,
			isset( $_POST[ Config::PREFIX . '_marka' ] ) ? sanitize_text_field( wp_unslash( $_POST[ Config::PREFIX . '_marka' ] ) ) : null,
			isset( $_POST[ Config::PREFIX . '_kolicina' ] ) ? sanitize_text_field( wp_unslash( $_POST[ Config::PREFIX . '_kolicina' ] ) ) : null,
			isset( $_POST[ Config::PREFIX . '_jedinica' ] ) ? sanitize_text_field( wp_unslash( $_POST[ Config::PREFIX . '_jedinica' ] ) ) : null
		);

		if ( isset( $_POST[ Config::PREFIX . '_oblik_prodaje' ] ) ) {
			Vrsta_Prodaje::postavi( $id, sanitize_key( wp_unslash( $_POST[ Config::PREFIX . '_oblik_prodaje' ] ) ) );
		}

		// Ako je administrator u istom spremanju popunio WooCommerceovo polje, nase
		// odlazi odmah — ne ceka da netko ponovno otvori karticu.
		Woo_Polja::uskladi( $id );
	}

	public static function spremi_varijaciju( $varijacija_id, $petlja ): void {
		$id = (int) $varijacija_id;

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return;
		}

		$daj = function ( $kljuc ) use ( $petlja ) {
			$polje = Config::PREFIX . '_' . $kljuc;
			if ( ! isset( $_POST[ $polje ][ $petlja ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return null;
			}
			return sanitize_text_field( wp_unslash( $_POST[ $polje ][ $petlja ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		};

		self::spremi_polja( $id, $daj( 'barkod' ), null, $daj( 'kolicina' ), $daj( 'jedinica' ) );

		$oblik = $daj( 'oblik_prodaje' );
		if ( null !== $oblik ) {
			Vrsta_Prodaje::postavi( $id, sanitize_key( $oblik ) );
		}

		Woo_Polja::uskladi( $id );
	}

	/* --------------------------------------------------------------- interno */

	private static function spremi_polja( int $id, ?string $barkod, ?string $marka, ?string $kolicina, ?string $jedinica ): void {
		if ( null !== $barkod ) {
			Zapis_Podataka::upisi( $id, Config::POLJE_BARKOD, array( 'barkod' => $barkod ), Config::IZVOR_PODATKA_RUCNO );
		}

		if ( null !== $marka ) {
			Zapis_Podataka::upisi( $id, Config::POLJE_MARKA, array( 'marka' => $marka ), Config::IZVOR_PODATKA_RUCNO );
		}

		if ( null !== $kolicina || null !== $jedinica ) {
			Zapis_Podataka::upisi(
				$id,
				Config::POLJE_KOLICINA,
				array(
					'kolicina' => (string) $kolicina,
					'jedinica' => (string) $jedinica,
				),
				Config::IZVOR_PODATKA_RUCNO
			);
		}
	}

	private static function polje_barkod( int $id ): void {
		$redak = Zapis_Podataka::procitaj( $id );
		$np    = Neprimjenjivo::za_vise( array( $id ) )[ $id ] ?? array();

		if ( Woo_Polja::woo_ima( $id, Config::POLJE_BARKOD ) ) {
			self::polje_iz_wooa( $id, Config::POLJE_BARKOD, __( 'Barkod (GTIN/EAN)', Config::TEXT_DOMAIN ) );
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'                => Config::PREFIX . '_barkod',
				'label'             => __( 'Barkod (GTIN/EAN)', Config::TEXT_DOMAIN ),
				'value'             => (string) ( $redak->barkod ?? '' ),
				'description'       => __( 'Prepisite s pakiranja. Barkod se NIKAD ne izmislja — izmisljen broj gotovo je sigurno dodijeljen drugoj firmi.', Config::TEXT_DOMAIN ),
				'desc_tip'          => true,
				'custom_attributes' => isset( $np[ Config::POLJE_BARKOD ] ) ? array( 'disabled' => 'disabled' ) : array(),
			)
		);

		self::napomena_statusa( $redak->barkod_status ?? '' );

		$nas = (string) ( $redak->barkod ?? '' );
		if ( '' !== $nas && ! Gtin::smije_u_cjenik( Gtin::status( $nas ) ) ) {
			?>
			<p class="form-field">
				<span class="description" style="color:#b32d2e;">
					<strong><?php esc_html_e( 'Ne objavljuje se u cjeniku.', Config::TEXT_DOMAIN ); ?></strong>
					<?php echo esc_html( Gtin::objasnjenje( Gtin::status( $nas ) ) ); ?>
				</span>
			</p>
			<?php
		}

		self::napomena_pravog_mjesta( Config::POLJE_BARKOD );
	}

	private static function polje_marka( int $id ): void {
		$redak = Zapis_Podataka::procitaj( $id );

		if ( Woo_Polja::woo_ima( $id, Config::POLJE_MARKA ) ) {
			self::polje_iz_wooa( $id, Config::POLJE_MARKA, __( 'Marka', Config::TEXT_DOMAIN ) );
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'          => Config::PREFIX . '_marka',
				'label'       => __( 'Marka', Config::TEXT_DOMAIN ),
				'value'       => (string) ( $redak->marka ?? '' ),
				'description' => self::opis_izvora( $redak->marka_izvor ?? '' ),
				'desc_tip'    => true,
			)
		);

		self::napomena_pravog_mjesta( Config::POLJE_MARKA );
	}

	/**
	 * Polje cija vrijednost dolazi iz WooCommercea.
	 *
	 * Neuredivo, jer se ovdje i ne sprema. Prikazuje se u trenutku crtanja, ne iz
	 * kopije — pa je uvijek ono sto stvarno stoji u proizvodu.
	 */
	private static function polje_iz_wooa( int $id, string $polje, string $natpis ): void {
		$vrijednost  = Woo_Polja::vrijedeca( $id, $polje )['vrijednost'];
		$gdje        = Woo_Polja::gdje_se_mijenja( $id, $polje );
		$naslijedena = ( Config::POLJE_MARKA === $polje ) && Woo_Polja::marka_naslijedena( $id );
		?>
		<?php
		// Barkod koji strukturno NE MOZE biti barkod ne objavljuje se, i to mora
		// pisati ovdje — administrator ga vidi u proizvodu i razumno pretpostavlja
		// da je u datoteci. Tiho izostavljanje je iznenadenje mjesecima poslije.
		$status  = ( Config::POLJE_BARKOD === $polje ) ? Gtin::status( $vrijednost ) : '';
		$u_cjenik = ( '' === $status ) || Gtin::smije_u_cjenik( $status );
		?>
		<p class="form-field">
			<label><?php echo esc_html( $natpis ); ?></label>
			<input type="text" value="<?php echo esc_attr( $vrijednost ); ?>" readonly disabled>
			<span class="description">
				<?php
				if ( $naslijedena ) {
					esc_html_e( 'Iz WooCommercea, s proizvoda. Marka se postavlja na proizvodu, ne po varijanti — sve varijante dijele istu.', Config::TEXT_DOMAIN );
				} else {
					printf(
						/* translators: %s = gdje se mijenja */
						esc_html__( 'Iz WooCommercea. Mijenja se ondje gdje je i upisano: %s.', Config::TEXT_DOMAIN ),
						esc_html( Woo_Polja::ime_mjesta( $polje ) )
					);
				}

				if ( '' !== $gdje ) {
					printf(
						' <a href="%s">%s</a>',
						esc_url( $gdje ),
						esc_html__( 'Otvori', Config::TEXT_DOMAIN )
					);
				}
				?>
			</span>
		</p>

		<?php if ( ! $u_cjenik ) : ?>
			<p class="form-field">
				<span class="description" style="color:#b32d2e;">
					<strong><?php esc_html_e( 'Ne objavljuje se u cjeniku.', Config::TEXT_DOMAIN ); ?></strong>
					<?php echo esc_html( Gtin::objasnjenje( $status ) ); ?>
					<?php esc_html_e( 'U objavljenoj datoteci polje za barkod ostaje prazno, a ne popunjeno ovom vrijednoscu.', Config::TEXT_DOMAIN ); ?>
				</span>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Recenica ispod uredivog polja: pravo mjesto je u WooCommerceu.
	 *
	 * Stoji i kad je nase polje prazno, jer je tada jedina prilika da se to kaze
	 * PRIJE nego administrator upise vrijednost koju ce mu poslije nesto prepisati.
	 * Iznenadenje se sprjecava unaprijed ili se ne sprjecava.
	 */
	private static function napomena_pravog_mjesta( string $polje ): void {
		?>
		<p class="form-field">
			<span class="description">
				<?php
				printf(
					/* translators: %s = gdje je pravo mjesto */
					esc_html__( 'Pravo mjesto za ovaj podatak je %s. Ovdje ga upisujete samo ako ga ondje nemate — i ako se ondje poslije popuni, ova vrijednost se brise i vrijedi WooCommerceova.', Config::TEXT_DOMAIN ),
					esc_html( Woo_Polja::ime_mjesta( $polje ) )
				);
				?>
			</span>
		</p>
		<?php
	}

	private static function polje_kolicina( int $id ): void {
		$redak = Zapis_Podataka::procitaj( $id );

		woocommerce_wp_text_input(
			array(
				'id'          => Config::PREFIX . '_kolicina',
				'label'       => __( 'Neto kolicina', Config::TEXT_DOMAIN ),
				'value'       => self::kolicina_za_prikaz( $redak ),
				'description' => __( 'Kolicina u pakiranju. Iz nje i cijene racuna se cijena po jedinici mjere.', Config::TEXT_DOMAIN ),
				'desc_tip'    => true,
			)
		);

		$opcije = array( '' => '' );
		foreach ( Config::JEDINICE as $j => $def ) {
			$opcije[ $j ] = $j;
		}

		woocommerce_wp_select(
			array(
				'id'      => Config::PREFIX . '_jedinica',
				'label'   => __( 'Jedinica mjere', Config::TEXT_DOMAIN ),
				'value'   => (string) ( $redak->jedinica_mjere ?? '' ),
				'options' => $opcije,
			)
		);
	}

	private static function prikaz_po_jedinici( int $id ): void {
		$po_jed = Cijena_Po_Jedinici::za_artikl( $id );

		if ( ! $po_jed ) {
			return;
		}

		printf(
			'<p class="form-field"><label>%s</label><span>%s</span></p>',
			esc_html__( 'Cijena po jedinici mjere', Config::TEXT_DOMAIN ),
			esc_html( $po_jed['zapis'] )
		);

		if ( $po_jed['gubi_preciznost'] ) {
			printf(
				'<p class="form-field"><span class="%s">%s</span></p>',
				esc_attr( Config::css( 'upozorenje' ) ),
				esc_html__( 'Zaokruzivanje na dvije decimale mijenja ovu vrijednost za vise od 1 %. Brojka se ne mijenja, ali je vrijedi znati.', Config::TEXT_DOMAIN )
			);
		}
	}

	private static function napomena_statusa( string $status ): void {
		if ( '' === $status || Config::GTIN_VALJAN === $status ) {
			return;
		}

		printf(
			'<p class="form-field"><span class="%s">%s</span></p>',
			esc_attr( Config::css( 'gresaka' ) ),
			esc_html( Gtin::objasnjenje( $status ) )
		);
	}

	private static function kolicina_za_prikaz( $redak ): string {
		if ( ! $redak || null === $redak->neto_kolicina ) {
			return '';
		}

		return rtrim( rtrim( (string) $redak->neto_kolicina, '0' ), '.' );
	}

	private static function opis_izvora( string $izvor ): string {
		if ( Config::IZVOR_PODATKA_NAZIV === $izvor ) {
			return __( 'Prepoznato iz naziva artikla — PROVJERITE. Naziv zna sadrzavati i proizvodaca i izdavaca.', Config::TEXT_DOMAIN );
		}

		if ( Config::IZVOR_PODATKA_RODITELJ === $izvor ) {
			return __( 'Naslijedeno od nadredenog proizvoda.', Config::TEXT_DOMAIN );
		}

		return __( 'Proizvodac ili brend artikla.', Config::TEXT_DOMAIN );
	}
}
