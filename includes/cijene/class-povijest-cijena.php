<?php
/**
 * Citanje tude tablice povijesti cijena.
 *
 * U nju se NE pise. Tuda je, vodi je drugi dodatak, i jedino sto radimo je
 * citanje. Sve osobitosti njezine strukture zive ovdje, da ih ostatak koda
 * ne mora poznavati.
 *
 * @package CJTR
 */

namespace CJTR\Cijene;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Povijest_Cijena {

	/** Tablice koje znamo procitati: ime bez prefiksa => dodatak koji ih vodi. */
	const TABLICE = array(
		'price_history' => 'WooCommerce Lowest Price',
	);

	/** @var string|null memoizirano puno ime tablice */
	private static $tablica = null;

	/** Puno ime tablice, ili prazno ako je nema. */
	public static function tablica(): string {
		global $wpdb;

		if ( null !== self::$tablica ) {
			return self::$tablica;
		}

		self::$tablica = '';
		foreach ( array_keys( self::TABLICE ) as $ime ) {
			$puno = $wpdb->prefix . $ime;
			if ( $puno === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $puno ) ) ) {
				self::$tablica = $puno;
				break;
			}
		}

		return self::$tablica;
	}

	public static function postoji(): bool {
		return '' !== self::tablica();
	}

	/**
	 * Sto smo nasli — za carobnjak, prije nego se ista procita.
	 *
	 * Korisnik prvo mora vidjeti OPSEG onoga sto nudimo preuzeti: koliko zapisa, za
	 * koliko artikala, od kad. Ponuda "preuzmi povijest" bez tih brojki trazi pristanak
	 * na nesto sto se ne vidi.
	 *
	 * @return array{ima:bool,dodatak:string,tablica:string,zapisa:int,entiteta:int,od:string}
	 */
	public static function dostupna(): array {
		global $wpdb;

		$t = self::tablica();

		if ( '' === $t ) {
			return array(
				'ima'      => false,
				'dodatak'  => '',
				'tablica'  => '',
				'zapisa'   => 0,
				'entiteta' => 0,
				'od'       => '',
			);
		}

		$ime_bez_prefiksa = substr( $t, strlen( $wpdb->prefix ) );

		$r = $wpdb->get_row( "SELECT COUNT(*) AS n, COUNT(DISTINCT product_id) AS e, MIN(NULLIF(timestamp,0)) AS od FROM `{$t}`" ); // phpcs:ignore

		return array(
			'ima'      => true,
			'dodatak'  => self::TABLICE[ $ime_bez_prefiksa ] ?? $ime_bez_prefiksa,
			'tablica'  => $t,
			'zapisa'   => (int) ( $r->n ?? 0 ),
			'entiteta' => (int) ( $r->e ?? 0 ),
			'od'       => ! empty( $r->od ) ? wp_date( 'j.n.Y.', (int) $r->od ) : '',
		);
	}

	/**
	 * Cijela tuda tablica kao redci za CSV.
	 *
	 * ZASTO SE NUDI IZVOZ PRIJE CITANJA
	 *
	 * Ovo je jedini trenutak u kojem korisnik jos ima OBOJE — i tudi dodatak, i nas.
	 * Obrise li tudi dodatak poslije, njegova povijest ne postoji vise nigdje, a ona
	 * je jedini dokaz koliko je cijena iznosila u proslosti.
	 *
	 * @return array[] prvi redak je zaglavlje
	 */
	public static function izvoz(): array {
		global $wpdb;

		$t = self::tablica();

		if ( '' === $t ) {
			return array();
		}

		$stupci = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`" ); // phpcs:ignore

		if ( empty( $stupci ) ) {
			return array();
		}

		$izlaz = array( $stupci );

		foreach ( (array) $wpdb->get_results( "SELECT * FROM `{$t}` ORDER BY 1 ASC", ARRAY_A ) as $r ) { // phpcs:ignore
			$izlaz[] = array_values( $r );
		}

		return $izlaz;
	}

	/**
	 * Intervali koji pokrivaju dani trenutak, za zadane entitete.
	 *
	 * Semantika tablice: `timestamp` je pocetak intervala, `timestamp_end = 0`
	 * znaci "jos traje". `timestamp = 0` znaci da pocetak NIJE poznat — cijena
	 * jest, ali ne i otkad vrijedi.
	 *
	 * @param int[] $ids
	 * @return array<int,array{cijena:float,pocetak:int,pocetak_pouzdan:bool}>
	 */
	public static function na_trenutak( array $ids, int $t ): array {
		global $wpdb;

		if ( empty( $ids ) || ! self::postoji() ) {
			return array();
		}

		$tablica = self::tablica();
		$u       = implode( ',', array_map( 'intval', $ids ) );

		// Ako entitet ima vise pokrivajucih redaka (ne bi smio, ali tablica nema
		// takvo ogranicenje), uzima se onaj s kasnijim pocetkom — najsvjeziji.
		$redci = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, price, timestamp
				 FROM `{$tablica}`
				 WHERE product_id IN ({$u})
				   AND timestamp <= %d
				   AND ( timestamp_end > %d OR timestamp_end = 0 )
				 ORDER BY product_id ASC, timestamp ASC, price_history_id ASC",
				$t,
				$t
			)
		); // phpcs:ignore

		$out = array();
		foreach ( $redci as $r ) {
			$out[ (int) $r->product_id ] = array(
				'cijena'          => (float) $r->price,
				'pocetak'         => (int) $r->timestamp,
				'pocetak_pouzdan' => ( (int) $r->timestamp ) > 0,
			);
		}

		return $out;
	}

	/**
	 * Najkasnija ranija cijena koja odgovara zadanoj vrijednosti.
	 *
	 * Sluzi kao dokaz da je redovna cijena stvarno postojala prije akcije.
	 * Usporedba je BROJCANA s tolerancijom, jer je stupac `price` tipa double —
	 * usporedba normaliziranih stringova kod doublea nije pouzdana.
	 *
	 * @param int[] $ids
	 * @param array<int,float> $trazene entity_id => vrijednost koja se trazi
	 * @return array<int,int> entity_id => pocetak najkasnijeg takvog intervala
	 */
	public static function potvrda_ranije( array $ids, array $trazene, int $prije_t ): array {
		global $wpdb;

		if ( empty( $ids ) || ! self::postoji() ) {
			return array();
		}

		$tablica = self::tablica();
		$u       = implode( ',', array_map( 'intval', $ids ) );
		$eps     = Config::TOLERANCIJA_POVIJESTI;

		$redci = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, price, MAX(timestamp) AS pocetak
				 FROM `{$tablica}`
				 WHERE product_id IN ({$u}) AND timestamp < %d
				 GROUP BY product_id, price",
				$prije_t
			)
		); // phpcs:ignore

		$out = array();
		foreach ( $redci as $r ) {
			$id = (int) $r->product_id;
			if ( ! isset( $trazene[ $id ] ) ) {
				continue;
			}
			if ( abs( (float) $r->price - (float) $trazene[ $id ] ) > $eps ) {
				continue;
			}
			$pocetak = (int) $r->pocetak;
			if ( ! isset( $out[ $id ] ) || $pocetak > $out[ $id ] ) {
				$out[ $id ] = $pocetak;
			}
		}

		return $out;
	}

	/** Jesu li dvije cijene iz povijesti iste. Brojcano, zbog double stupca. */
	public static function jednako( $a, $b ): bool {
		if ( null === $a || null === $b || '' === $a || '' === $b ) {
			return false;
		}
		return abs( (float) $a - (float) $b ) <= Config::TOLERANCIJA_POVIJESTI;
	}
}
