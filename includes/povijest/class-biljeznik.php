<?php
/**
 * Biljeznik: hvata promjene cijena i upisuje ih u vlastitu povijest.
 *
 * TRI SLOJA, arhitektura je u ANALIZA.md sekcija L.
 *
 * Sloj 1 — `updated_post_meta` / `added_post_meta` / `deleted_post_meta` na tri
 *   cjenovna kljuca. PRIMARNI sloj. Hvata sve sto ide kroz WordPress meta API:
 *   spremanje proizvoda, `wc_scheduled_sales`, izravan `update_post_meta`, uvoz.
 *
 *   NAMJERNO se NE vezemo na `woocommerce_update_product`. WooCommerce ga sam
 *   zaobilazi: `wc_apply_sale_state_for_product()` (wc-product-functions.php:644-674)
 *   nakon `$product->save()` jos jednom pise `_price` izravno u metu, uz vlastiti
 *   komentar da `save()` to ne odradi. Tko slusa taj hook vidi staro stanje.
 *
 * Sloj 2 — `woocommerce_product_object_updated_props` SAMO za kontekst. Ne stvara
 *   redak; obogacuje onaj koji ce zapisati sloj 1.
 *
 * Sloj 3 — dnevna rekonsilijacija, zaseban posao.
 *
 * ZASTO SE PISE TEK NA KRAJU ZAHTJEVA
 *
 * `updated_post_meta` okida se i kad je vrijednost nepromijenjena, a
 * `WC_Product_Variable_Data_Store_CPT::sync_price()` radi `delete_post_meta` pa
 * `add_post_meta` — dakle metu nakratko ukloni. Kad bismo pisali odmah, zabiljezili
 * bismo pad na nulu i hrpu duplikata. Zato hookovi samo OZNACE entitet kao
 * promijenjen, a stvarno stanje se procita jednom, na kraju zahtjeva.
 *
 * @package CJTR
 */

namespace CJTR\Povijest;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Biljeznik {

	/** Cjenovne mete koje pratimo. */
	private const METE = array( '_price', '_regular_price', '_sale_price' );

	/** @var array<int,bool> entiteti promijenjeni u ovom zahtjevu */
	private static $prljavi = array();

	/** @var array<int,array> kontekst iz sloja 2 */
	private static $kontekst = array();

	/** @var bool */
	private static $zakazano = false;

	/** @var string|null okidac koji nadglasava automatski prepoznat */
	private static $okidac = null;

	/**
	 * Izvedi operaciju tako da nastali zapisi nose zadani okidac.
	 *
	 * Postoji jer isti mehanizam moze biti pokrenut iz razlicitih razloga, a razlog
	 * se iz same promjene mete ne vidi. Promocija akcijske cijene u redovnu mijenja
	 * `_regular_price` jednako kao i obicno uredivanje — razliku zna samo pozivatelj.
	 * Bez ovoga bi zapis tvrdio da je cijena promijenjena, a nije: naplacuje se ista
	 * vrijednost, samo se sada zove redovnom.
	 *
	 * Zapisi se prazne PRIJE vracanja okidaca, da se ne prenesu na sljedeci posao
	 * u istom zahtjevu. Vracanje je u `finally` — iznimka usred operacije ne smije
	 * ostaviti okidac postavljenim.
	 *
	 * @param callable $operacija
	 * @return mixed sto vrati operacija
	 */
	public static function pod_okidacem( string $okidac, callable $operacija ) {
		$prethodni    = self::$okidac;
		self::$okidac = $okidac;

		try {
			return $operacija();
		} finally {
			self::obradi_prljave();
			self::$okidac = $prethodni;
		}
	}

	public static function init(): void {
		// Sloj 1 — primarni.
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_promijenjena' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_promijenjena' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'meta_promijenjena' ), 10, 3 );

		// Sloj 2 — samo kontekst.
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'kontekst' ), 10, 2 );
	}

	/**
	 * Sloj 1: oznaci entitet kao promijenjen.
	 *
	 * @param int|array $meta_id
	 * @param int       $post_id
	 * @param string    $meta_key
	 */
	public static function meta_promijenjena( $meta_id, $post_id, $meta_key ): void {
		if ( ! in_array( $meta_key, self::METE, true ) ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		self::$prljavi[ $post_id ] = true;
		self::zakazi_obradu();
	}

	/**
	 * Sloj 2: zapamti kontekst, bez pisanja.
	 *
	 * Objekt dolazi izravno, pa ga NE citamo ponovno iz baze — to je greska koju
	 * radi postojeci dodatak i zbog koje mu povijest kasni jedan ciklus.
	 *
	 * @param \WC_Product $proizvod
	 * @param string[]    $svojstva
	 */
	public static function kontekst( $proizvod, $svojstva ): void {
		if ( ! is_object( $proizvod ) || ! method_exists( $proizvod, 'get_id' ) ) {
			return;
		}

		$id = (int) $proizvod->get_id();
		if ( $id <= 0 ) {
			return;
		}

		self::$kontekst[ $id ] = array(
			'tip'       => method_exists( $proizvod, 'get_type' ) ? $proizvod->get_type() : '',
			'na_akciji' => method_exists( $proizvod, 'is_on_sale' ) ? (bool) $proizvod->is_on_sale( 'edit' ) : null,
			'svojstva'  => (array) $svojstva,
		);
	}

	private static function zakazi_obradu(): void {
		if ( self::$zakazano ) {
			return;
		}
		self::$zakazano = true;
		add_action( 'shutdown', array( __CLASS__, 'obradi_prljave' ), 5 );
	}

	/**
	 * Na kraju zahtjeva: procitaj stvarno stanje i zapisi ako se promijenilo.
	 */
	public static function obradi_prljave(): void {
		if ( empty( self::$prljavi ) ) {
			return;
		}

		$prljavi       = array_keys( self::$prljavi );
		self::$prljavi = array();

		foreach ( $prljavi as $id ) {
			$stanje = self::procitaj( $id );
			if ( null === $stanje ) {
				continue;
			}

			// Pozivatelj koji zna razlog ima prednost pred automatskim prepoznavanjem.
			$okidac = self::$okidac ?? ( isset( self::$kontekst[ $id ] ) ? Config::OKIDAC_CRUD : Config::OKIDAC_META );

			Zapis::zabiljezi( $id, $stanje, $okidac );
		}
	}

	/**
	 * Stvarno stanje cijena entiteta, izravno iz mete.
	 *
	 * Cita se meta, ne `$product->get_price()`, jer nas zanima sto je ZAPISANO,
	 * a ne sto bi neki dodatak prikazao.
	 *
	 * @return array|null null ako entitet nije proizvod ni varijanta
	 */
	private static function procitaj( int $id ): ?array {
		$tip = get_post_type( $id );
		if ( 'product' !== $tip && 'product_variation' !== $tip ) {
			return null;
		}

		$regular = get_post_meta( $id, '_regular_price', true );
		$sale    = get_post_meta( $id, '_sale_price', true );
		$price   = get_post_meta( $id, '_price', true );

		return array(
			'regular'   => ( '' === $regular ) ? null : $regular,
			'sale'      => ( '' === $sale ) ? null : $sale,
			'price'     => ( '' === $price ) ? null : $price,
			'vrsta_pop' => self::vrsta_pop( $id, $regular, $sale, $price ),
		);
	}

	/**
	 * Vrsta posebne prodaje, koliko se da utvrditi iz podataka.
	 *
	 * 'akcijska' se utvrdi: akcijska cijena postoji i niza je od redovne, a
	 * efektivna cijena joj odgovara. Rasprodaju i sezonsko snizenje zna samo
	 * trgovac — plugin ih ne pogada nego ostavlja 'nema' dok ih netko ne upise.
	 *
	 * JAVNA JE JER JE ISTI RACUN TREBAO I DRUGDJE
	 *
	 * Rekonsilijacija je dugo upisivala tvrdo 'nema' za svaki interval koji otkrije,
	 * pa je zapis o akciji koju je ona uhvatila izgledao kao da akcije nije ni bilo.
	 * Dok se vrsta nije nigdje koristila, to nije smetalo; otkad je naziv posebnog
	 * oblika prodaje OBVEZNO polje objave, smeta.
	 */
	public static function vrsta_pop( int $id, $regular, $sale, $price ): string {
		if ( '' === $sale || null === $sale ) {
			return Config::POP_NEMA;
		}
		if ( '' === $regular || null === $regular ) {
			return Config::POP_NEMA;
		}
		if ( (float) $sale >= (float) $regular ) {
			return Config::POP_NEMA;
		}
		// Akcijska cijena postoji, ali se ne naplacuje — akcija jos nije pocela
		// ili je vec zavrsila.
		if ( '' !== $price && abs( (float) $price - (float) $sale ) > Config::TOLERANCIJA_POVIJESTI ) {
			return Config::POP_NEMA;
		}
		return Config::POP_AKCIJSKA;
	}
}
