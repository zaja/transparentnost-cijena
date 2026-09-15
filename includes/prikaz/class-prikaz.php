<?php
/**
 * Zakacivanje dodatne cijene uz prikaz cijene proizvoda.
 *
 * OPSEG
 *
 * Dodatak radi iskljucivo s cijenama unesenima u proizvod. Cijene utipkane u
 * sadrzaj stranica — baneri, elementi graditelja stranica, opisi, odredisne
 * stranice — odgovornost su vlasnika trgovine; dodatak ih ne dira i ne trazi.
 *
 * PRIORITET 1100
 *
 * Mjesto 1000 na `woocommerce_get_price_html` zauzima dodatak za najnizu cijenu.
 * Trenutno radi u nacinu u kojem cijenu propusta nepromijenjenu, ali to ovisi o
 * retku u wp-config.php koji vlasnik moze promijeniti. Nas filter zato ide IZA
 * njega, da ga promjena te postavke ne moze prepisati.
 *
 * @package CJTR
 */

namespace CJTR\Prikaz;

use CJTR\Config;
use CJTR\Postavke;
use CJTR\Povijest\Dubina;
use CJTR\Povijest\Zapis;

defined( 'ABSPATH' ) || exit;

final class Prikaz {

	/** Iza svih zatecenih zahvata u prikaz cijene. */
	const PRIORITET = 1100;

	/** @var array<int,array|null> procitano u ovom zahtjevu */
	private static $predmemorija = array();

	/**
	 * Koliko je puta u ovom zahtjevu prikaz doista ispisan.
	 *
	 * Broji se da bi se znalo treba li rezerva. Nula na stranici koja prikazuje
	 * cijene znaci da tema ide mimo `get_price_html()` — vidi `Prikaz\Rezerva`.
	 */
	private static $ispisano = 0;

	public static function ispisano(): int {
		return self::$ispisano;
	}

	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'uz_cijenu' ), self::PRIORITET, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'stilovi' ) );
	}

	/**
	 * @param string      $html
	 * @param \WC_Product $proizvod
	 */
	public static function uz_cijenu( $html, $proizvod ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}
		if ( ! is_object( $proizvod ) || ! method_exists( $proizvod, 'get_id' ) ) {
			return $html;
		}

		$podaci = self::podaci( $proizvod );
		if ( null === $podaci ) {
			return $html;
		}

		$dodatak = Render::html( $podaci );

		if ( '' === $dodatak ) {
			return $html;
		}

		self::$ispisano++;

		return $html . $dodatak;
	}

	/**
	 * Sto se prikazuje za dani proizvod.
	 *
	 * @return array|null null ako nema sto prikazati
	 */
	private static function podaci( $proizvod ): ?array {
		$id = (int) $proizvod->get_id();

		if ( array_key_exists( $id, self::$predmemorija ) ) {
			return self::$predmemorija[ $id ];
		}

		$tip = method_exists( $proizvod, 'get_type' ) ? $proizvod->get_type() : '';

		$podaci = ( 'variable' === $tip )
			? self::za_varijabilni( $proizvod )
			: self::za_jedan( $id, $proizvod );

		self::$predmemorija[ $id ] = $podaci;
		return $podaci;
	}

	private static function za_jedan( int $id, $proizvod ): ?array {
		$sidrena = Sidrena::za( $id );

		/*
		 * Najniza u 30 dana ima smisla samo kad se oglasava snizenje. Bez akcije bi
		 * bila jednaka danasnjoj cijeni i samo bi zatrpala prikaz.
		 *
		 * Uz to dva uvjeta koji se ticu ISTINITOSTI, ne korisnosti:
		 *
		 *   - postavka: ako drugi dodatak vec prikazuje istu tvrdnju, sutimo. Dvije
		 *     tvrdnje o istoj stvari gore su od nijedne, jer kupac ne zna kojoj vjerovati.
		 *
		 *   - dubina: dodatak instaliran prije deset dana ne smije tvrditi da zna
		 *     najnizu u trideset. To nije priblizno tocno nego netocno, i to bas ono
		 *     sto propis trazi da bude tocno. Mjeri se po artiklu, jer je i propis
		 *     po artiklu — proizvod u prodaji krace od 30 dana ima kraci prozor.
		 *
		 * Dodatna cijena se ovime NE dira ni u jednom slucaju.
		 */
		$najniza = null;
		if ( Postavke::najniza_30()
			&& method_exists( $proizvod, 'is_on_sale' ) && $proizvod->is_on_sale()
			&& Dubina::pokriven( $id ) ) {
			$najniza = Zapis::najniza( $id, Dubina::DANA );
		}

		if ( null === $sidrena && null === $najniza ) {
			return null;
		}

		return array(
			'sidrena'    => $sidrena,
			'najniza_30' => $najniza,
			'ref_datum'  => Postavke::ref_datum( self::kategorija( $id ) ),
		);
	}

	/**
	 * Varijabilni roditelj, bez odabrane varijante.
	 *
	 * ODLUKA: prikazuje se samo ako SVE varijante imaju istu dodatnu cijenu.
	 *
	 * Raspon se ne prikazuje jer "dodatna cijena od 5 do 12 eura" nije tvrdnja ni
	 * o jednom artiklu — kupac kupuje jednu varijantu, ne raspon. Kad se varijante
	 * razlikuju, prikaz se pojavljuje tek pri odabiru, isto kao i sama cijena.
	 */
	private static function za_varijabilni( $proizvod ): ?array {
		$djeca = method_exists( $proizvod, 'get_children' ) ? (array) $proizvod->get_children() : array();
		if ( empty( $djeca ) ) {
			return null;
		}

		$sidrene = Sidrena::za_vise( array_map( 'intval', $djeca ) );

		// Nedostaje li ijednoj varijanti, roditelj suti — tvrdnja bi vrijedila
		// samo za dio varijanti, a kupac ne vidi za koje.
		if ( count( $sidrene ) !== count( $djeca ) ) {
			return null;
		}

		$razlicite = array_unique( array_map( function ( $v ) {
			return round( (float) $v, 4 );
		}, $sidrene ) );

		if ( count( $razlicite ) !== 1 ) {
			return null;
		}

		return array(
			'sidrena'    => (float) reset( $razlicite ),
			'najniza_30' => null,
			'ref_datum'  => Postavke::ref_datum(),
		);
	}

	/**
	 * Zakonska kategorija artikla, radi ispravnog referentnog datuma.
	 *
	 * Trgovina koja prodaje i hranu i sve ostalo ima DVA referentna datuma, pa uz
	 * cijenu mora stajati onaj koji vrijedi za taj artikl. Pogresan datum nije
	 * kozmeticka greska nego netocna tvrdnja na stranici proizvoda.
	 */
	private static function kategorija( int $id ): string {
		global $wpdb;

		$v = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT zakonska_kategorija FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$id
			) // phpcs:ignore
		);

		return ( null === $v ) ? '' : (string) $v;
	}

	/**
	 * Isprazni predmemoriju ovog zahtjeva.
	 *
	 * Postoji zbog mjerenja: provjera ispituje isti artikl u vise stanja zaredom, a
	 * bez ovoga bi svako sljedece mjerenje dobilo odgovor prvoga. U normalnom radu
	 * se ne poziva — unutar jednog zahtjeva stanje artikla se ne mijenja.
	 */
	public static function zaboravi(): void {
		self::$predmemorija = array();
	}

	public static function stilovi(): void {
		wp_enqueue_style(
			Config::css( 'prikaz' ),
			CJTR_URL . 'assets/prikaz.css',
			array(),
			Config::VERSION
		);
	}
}
