<?php
/**
 * Citanje i pisanje vlastite povijesti cijena.
 *
 * Intervalna semantika, ista kao kod tudeg izvora:
 *   ts      — pocetak intervala
 *   ts_end  — 0 znaci "jos traje"
 *
 * Razlika prema tudem izvoru: biljeze se SVE TRI cijene. Zbog toga se redovna
 * cijena na bilo koji datum moze PROCITATI, a ne rekonstruirati.
 *
 * @package CJTR
 */

namespace CJTR\Povijest;

use CJTR\Config;
use CJTR\Db;

defined( 'ABSPATH' ) || exit;

final class Zapis {

	private static function tablica(): string {
		return Config::table( Config::TABLE_POVIJEST );
	}

	/**
	 * Otvoreni interval jednog entiteta, ili null.
	 *
	 * @return object|null
	 */
	public static function otvoreni( int $entity_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . self::tablica() . '` WHERE entity_id = %d AND ts_end = 0 ORDER BY id DESC LIMIT 1',
				$entity_id
			) // phpcs:ignore
		);
	}

	/**
	 * Otvoreni intervali za vise entiteta odjednom.
	 *
	 * @param int[] $ids
	 * @return array<int,object>
	 */
	public static function otvoreni_za( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$u = implode( ',', array_map( 'intval', $ids ) );

		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM `' . self::tablica() . "` WHERE entity_id IN ({$u}) AND ts_end = 0" ) as $r ) { // phpcs:ignore
			$out[ (int) $r->entity_id ] = $r;
		}
		return $out;
	}

	/**
	 * Zapisi novo stanje, ako se razlikuje od otvorenog intervala.
	 *
	 * Zatvara prethodni interval i otvara novi. Ako se nista nije promijenilo,
	 * NE pise nista — `updated_post_meta` se okida i kad je vrijednost ista, a
	 * `sync_price()` radi delete pa add, pa bi bez ove usporedbe nastali duplikati
	 * i lazni pad na nulu.
	 *
	 * @param array $stanje regular, sale, price, vrsta_pop
	 * @return bool je li nesto zapisano
	 */
	public static function zabiljezi( int $entity_id, array $stanje, string $okidac, ?int $kad = null ): bool {
		global $wpdb;

		$kad = $kad ?? time();

		$novo = array(
			'regular' => self::norm( $stanje['regular'] ?? null ),
			'sale'    => self::norm( $stanje['sale'] ?? null ),
			'price'   => self::norm( $stanje['price'] ?? null ),
		);

		// Stanje bez ijedne cijene nije promjena nego brisanje mete usred
		// sinkronizacije. Takvo se ne biljezi.
		if ( null === $novo['price'] && null === $novo['regular'] && null === $novo['sale'] ) {
			return false;
		}

		$otvoreni = self::otvoreni( $entity_id );

		if ( $otvoreni && self::isto( $otvoreni, $novo ) ) {
			return false;
		}

		$tablica = self::tablica();

		if ( $otvoreni ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$tablica}` SET ts_end = %d WHERE id = %d AND ts_end = 0",
					$kad,
					(int) $otvoreni->id
				) // phpcs:ignore
			);
		}

		$pouzdan = in_array( $okidac, Config::OKIDACI_BEZ_POUZDANOG_POCETKA, true ) ? 0 : 1;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$tablica}`
					( entity_id, regular_price, sale_price, price, ts, ts_end, okidac, vrsta_pop, pocetak_pouzdan )
				 VALUES ( %d, " . Db::cijena( $novo['regular'] ) . ', ' . Db::cijena( $novo['sale'] ) . ', ' . Db::cijena( $novo['price'] ) . ', %d, 0, %s, %s, %d )',
				$entity_id,
				$pouzdan ? $kad : 0,
				$okidac,
				$stanje['vrsta_pop'] ?? Config::POP_NEMA,
				$pouzdan
			) // phpcs:ignore
		);

		return true;
	}

	/**
	 * Najniza EFEKTIVNA cijena u zadanom broju dana unatrag.
	 *
	 * Ovo je brojka koju modul prikaza treba, a koju postojeci izvor na produkciji
	 * ne racuna. Uzimaju se svi intervali koji se preklapaju s prozorom, ukljucujuci
	 * onaj koji jos traje.
	 *
	 * @return float|null null ako za entitet nema nijednog zapisa u prozoru
	 */
	public static function najniza( int $entity_id, int $dana = 30, ?int $do = null ): ?float {
		global $wpdb;

		$do  = $do ?? time();
		$od  = $do - ( $dana * DAY_IN_SECONDS );

		$v = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(price) FROM `' . self::tablica() . '`
				 WHERE entity_id = %d
				   AND price IS NOT NULL
				   AND ts <= %d
				   AND ( ts_end = 0 OR ts_end >= %d )',
				$entity_id,
				$do,
				$od
			) // phpcs:ignore
		);

		return ( null === $v ) ? null : (float) $v;
	}

	/**
	 * Najnize cijene za vise entiteta odjednom.
	 *
	 * Postoji zbog varijabilnog roditelja: njegovih dvadeset varijanti ne smije
	 * znaciti dvadeset upita na svakoj stranici mreze. Isti prozor i isti uvjeti
	 * kao u `najniza()`, samo grupirano.
	 *
	 * @param int[] $ids
	 * @return array<int,float> samo oni koji u prozoru imaju zapis
	 */
	public static function najnize_za( array $ids, int $dana = 30, ?int $do = null ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$do = $do ?? time();
		$od = $do - ( $dana * DAY_IN_SECONDS );
		$u  = implode( ',', array_map( 'intval', $ids ) );

		$redci = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT entity_id, MIN(price) AS najniza
				 FROM `' . self::tablica() . "`
				 WHERE entity_id IN ({$u})
				   AND price IS NOT NULL
				   AND ts <= %d
				   AND ( ts_end = 0 OR ts_end >= %d )
				 GROUP BY entity_id",
				$do,
				$od
			) // phpcs:ignore
		);

		$out = array();
		foreach ( (array) $redci as $r ) {
			if ( null !== $r->najniza ) {
				$out[ (int) $r->entity_id ] = (float) $r->najniza;
			}
		}
		return $out;
	}

	/**
	 * Interval koji pokriva zadani trenutak.
	 *
	 * @return object|null
	 */
	public static function na_trenutak( int $entity_id, int $t ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . self::tablica() . '`
				 WHERE entity_id = %d AND ts <= %d AND ( ts_end > %d OR ts_end = 0 )
				 ORDER BY ts DESC, id DESC LIMIT 1',
				$entity_id,
				$t,
				$t
			) // phpcs:ignore
		);
	}

	/** Koliko dugo traje otvoreni interval, u danima. null ako pocetak nije poznat. */
	public static function trajanje_dana( $otvoreni ): ?int {
		if ( ! $otvoreni || empty( $otvoreni->ts ) || ! $otvoreni->pocetak_pouzdan ) {
			return null;
		}
		return (int) floor( ( time() - (int) $otvoreni->ts ) / DAY_IN_SECONDS );
	}

	public static function broj_zapisa(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . self::tablica() . '`' ); // phpcs:ignore
	}

	public static function broj_entiteta(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT entity_id) FROM `' . self::tablica() . '`' ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ */

	private static function norm( $v ): ?float {
		if ( null === $v || '' === $v ) {
			return null;
		}
		return round( (float) $v, 4 );
	}

	/** Usporedba je BROJCANA — decimalni zapis iste vrijednosti moze se razlikovati. */
	private static function isto( $redak, array $novo ): bool {
		foreach ( array( 'regular' => 'regular_price', 'sale' => 'sale_price', 'price' => 'price' ) as $k => $stupac ) {
			$stari = ( null === $redak->$stupac ) ? null : (float) $redak->$stupac;
			$nov   = $novo[ $k ];

			if ( null === $stari && null === $nov ) {
				continue;
			}
			if ( null === $stari || null === $nov ) {
				return false;
			}
			if ( abs( $stari - $nov ) > Config::TOLERANCIJA_POVIJESTI ) {
				return false;
			}
		}
		return true;
	}
}
