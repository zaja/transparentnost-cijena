<?php
/**
 * Trag: stanje prije operacije koja brise ili prepisuje tude podatke.
 *
 * Pravilo projekta, isto kao kod sidrene cijene: operacija koja unistava dokaz
 * mora prvo ostaviti trag. Meta koja izgleda kao kvar moze biti namjerna, a bez
 * traga se to vise ne moze provjeriti ni vratiti.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Trag {

	private static function tablica(): string {
		return Config::table( Config::TABLE_TRAG );
	}

	/**
	 * Zapisi stanje prije promjene.
	 *
	 * @param int    $entity_id Artikl ili varijanta.
	 * @param string $operacija Kljuc operacije, npr. kljuc posla.
	 * @param array  $stanje    Stanje prije, kakvo jest.
	 * @param string $razlog    Zasto se dira.
	 * @return int ID zapisa, 0 ako nije uspjelo.
	 */
	public static function zapisi( int $entity_id, string $operacija, array $stanje, string $razlog = '' ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			self::tablica(),
			array(
				'entity_id'    => $entity_id,
				'operacija'    => $operacija,
				'stanje_prije' => wp_json_encode( $stanje ),
				'razlog'       => mb_substr( $razlog, 0, 255 ),
				'zapisano'     => current_time( 'mysql', true ),
			)
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Zapisi koje jos nisu vraceni, za danu operaciju.
	 *
	 * Ime govori STANJE, ne namjeru: te zapise cita i posao koji vraca i posao koji
	 * samo treba znati na koje je artikle zadnja izvedba operacije djelovala. Zvali
	 * se "za vracanje", drugi bi pozivatelj izgledao kao greska.
	 *
	 * @return object[]
	 */
	public static function nevraceni( string $operacija, int $nakon_id = 0, int $koliko = 100 ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . self::tablica() . '`
				 WHERE operacija = %s AND vraceno IS NULL AND id > %d
				 ORDER BY id ASC LIMIT %d',
				$operacija,
				$nakon_id,
				$koliko
			) // phpcs:ignore
		);
	}

	public static function broj_nevracenih( string $operacija ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . self::tablica() . '` WHERE operacija = %s AND vraceno IS NULL',
				$operacija
			) // phpcs:ignore
		);
	}

	public static function oznaci_vracenim( int $id ): void {
		global $wpdb;

		$wpdb->update(
			self::tablica(),
			array( 'vraceno' => current_time( 'mysql', true ) ),
			array( 'id' => $id )
		);
	}

	/** Dekodirano stanje iz zapisa. */
	public static function stanje( $zapis ): array {
		$p = json_decode( (string) $zapis->stanje_prije, true );
		return is_array( $p ) ? $p : array();
	}

	public static function ukupno( string $operacija ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . self::tablica() . '` WHERE operacija = %s',
				$operacija
			) // phpcs:ignore
		);
	}
}
