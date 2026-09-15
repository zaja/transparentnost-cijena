<?php
/**
 * Rezervni prikaz: kad tema cijenu ispise mimo `woocommerce_get_price_html`.
 *
 * ZASTO POSTOJI
 *
 * Nas filter pokriva sve sto prode kroz `get_price_html()` — stranicu artikla,
 * mreze, pretragu, povezane proizvode, widgete, odabranu varijaciju. To je
 * vecina tema i sve sto WooCommerce crta sam.
 *
 * Ali ne sve. Graditelji stranica i teme koje cijenu slazu same ispisuju iznos
 * bez tog filtera, a blok "All Products" cijenu uopce ne crta na posluzitelju —
 * slaze je u pregledniku iz Store API-ja. Na takvoj trgovini sidrena cijena
 * naprosto NE IZADE, i to bez ijedne greske, poruke ili traga.
 *
 * Tiho izostajanje obveznog prikaza gore je od svake greske: greska se primijeti.
 *
 * RADI SAMO KAD TREBA
 *
 * Skripta se ucitava iskljucivo kad je nas filter na toj stranici ispisao NULA
 * puta. Trgovina na kojoj sve radi ne dobiva ni bajt viska, ni jedan dodatni
 * zahtjev. To je i mjerilo: rezerva koja se ukljuci ondje gdje nije potrebna
 * dvaput ispisuje istu tvrdnju.
 *
 * NE POGADA, NEGO PITA
 *
 * Preglednik salje ID-eve artikala koje vidi na stranici, a posluzitelj vraca
 * isti HTML koji bi ispisao i filter — iz `Render`, istim putem i s istim
 * uvjetima. Dvije izvedbe iste tvrdnje razisle bi se, a razlika bi se vidjela
 * tek u nadzoru.
 *
 * @package CJTR
 */

namespace CJTR\Prikaz;

use CJTR\Config;
use CJTR\Postavke;

defined( 'ABSPATH' ) || exit;

final class Rezerva {

	/** AJAX akcija. */
	const AKCIJA = 'sidrena_za_artikle';

	/**
	 * Najvise artikala po zahtjevu.
	 *
	 * Mreza od sto proizvoda je vec neuobicajeno velika stranica. Granica postoji
	 * da jedan zahtjev ne moze zatraziti cijeli katalog.
	 */
	const MAX = 100;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'mozda_ucitaj' ), 100 );

		add_action( 'wp_ajax_' . Config::hook( self::AKCIJA ), array( __CLASS__, 'odgovori' ) );
		add_action( 'wp_ajax_nopriv_' . Config::hook( self::AKCIJA ), array( __CLASS__, 'odgovori' ) );
	}

	/**
	 * Ucitaj skriptu samo ako je filter ostao prazan.
	 *
	 * Prioritet 100 na `wp_enqueue_scripts` je prerano da bi se znalo je li filter
	 * radio — cijene se crtaju poslije. Zato se skripta REGISTRIRA ovdje, a u red
	 * stavlja tek u podnozju, kad je brojac konacan.
	 */
	public static function mozda_ucitaj(): void {
		if ( is_admin() || ! Postavke::js_rezerva() ) {
			return;
		}

		wp_register_script(
			Config::css( 'rezerva' ),
			CJTR_URL . 'assets/rezerva.js',
			array(),
			Config::VERSION,
			true
		);

		add_action( 'wp_footer', array( __CLASS__, 'u_podnozju' ), 5 );
	}

	/** U podnozju se zna je li filter isao. */
	public static function u_podnozju(): void {
		if ( Prikaz::ispisano() > 0 ) {
			return;
		}

		wp_localize_script(
			Config::css( 'rezerva' ),
			'cjtrRezerva',
			array(
				'url'    => admin_url( 'admin-ajax.php' ),
				'akcija' => Config::hook( self::AKCIJA ),
				'razred' => Config::css( 'dodatne' ),
				'max'    => self::MAX,
			)
		);

		wp_enqueue_script( Config::css( 'rezerva' ) );
	}

	/**
	 * Vrati HTML za trazene artikle.
	 *
	 * Bez noncea namjerno: sve sto se ovdje vraca stoji i na stranici artikla, za
	 * svakoga. Nonce bi ovdje bio obred, a ne zastita — i pokvario bi stranice u
	 * predmemoriji, gdje je nonce iz HTML-a odavno istekao.
	 */
	public static function odgovori(): void {
		$sirovo = isset( $_POST['artikli'] ) ? wp_unslash( $_POST['artikli'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$ids = array_slice(
			array_unique(
				array_filter(
					array_map( 'absint', explode( ',', (string) $sirovo ) )
				)
			),
			0,
			self::MAX
		);

		if ( empty( $ids ) ) {
			wp_send_json_success( array() );
		}

		$izlaz = array();

		foreach ( $ids as $id ) {
			$html = self::za_artikl( $id );

			if ( '' !== $html ) {
				$izlaz[ $id ] = $html;
			}
		}

		wp_send_json_success( $izlaz );
	}

	/**
	 * HTML za jedan artikl — istim putem kojim ide i filter.
	 *
	 * Prolazi kroz `Prikaz::uz_cijenu()` s praznim ulazom, pa vrijede tocno isti
	 * uvjeti: varijabilni roditelj bez sloge medu varijantama i dalje suti, artikl
	 * bez sidrene cijene i dalje ne daje nista.
	 */
	private static function za_artikl( int $id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$proizvod = wc_get_product( $id );

		if ( ! $proizvod ) {
			return '';
		}

		return (string) Prikaz::uz_cijenu( '', $proizvod );
	}
}
