<?php
/**
 * Citanje dodatne cijene za prikaz.
 *
 * PRAZNO JE PRAZNO
 *
 * Dodatna cijena koja ceka odluku, ceka rucni unos ili ne postoji jer je artikl
 * nastao nakon referentnog datuma — NE prikazuje se. Nikad "0,00 €": nula je
 * tvrdnja da je cijena bila nula, a to nije istina ni za jedan od tih slucajeva.
 *
 * @package CJTR
 */

namespace CJTR\Prikaz;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Sidrena {

	/** @var array<int,float|null> */
	private static $predmemorija = array();

	/** Zaboravi procitano — vidi `Prikaz::zaboravi()`. */
	public static function zaboravi(): void {
		self::$predmemorija = array();
	}

	/** Dodatna cijena jednog entiteta, ili null ako se ne prikazuje. */
	public static function za( int $entity_id ): ?float {
		if ( array_key_exists( $entity_id, self::$predmemorija ) ) {
			return self::$predmemorija[ $entity_id ];
		}

		global $wpdb;

		$v = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT sidrena_cijena FROM `' . Config::table( Config::TABLE_PODACI ) . '`
				 WHERE entity_id = %d AND sidrena_cijena IS NOT NULL',
				$entity_id
			) // phpcs:ignore
		);

		self::$predmemorija[ $entity_id ] = ( null === $v ) ? null : (float) $v;
		return self::$predmemorija[ $entity_id ];
	}

	/**
	 * Dodatne cijene za vise entiteta odjednom.
	 *
	 * Vraca SAMO one koje postoje — pozivatelj po broju rezultata vidi nedostaje li
	 * kojem entitetu.
	 *
	 * @param int[] $ids
	 * @return array<int,float>
	 */
	public static function za_vise( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$u = implode( ',', array_map( 'intval', $ids ) );

		$redci = $wpdb->get_results(
			'SELECT entity_id, sidrena_cijena
			 FROM `' . Config::table( Config::TABLE_PODACI ) . "`
			 WHERE entity_id IN ({$u}) AND sidrena_cijena IS NOT NULL" // phpcs:ignore
		);

		$out = array();
		foreach ( $redci as $r ) {
			$out[ (int) $r->entity_id ]             = (float) $r->sidrena_cijena;
			self::$predmemorija[ (int) $r->entity_id ] = (float) $r->sidrena_cijena;
		}
		return $out;
	}
}
