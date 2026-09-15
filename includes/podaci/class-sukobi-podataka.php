<?php
/**
 * Zapis neslaganja izmedu izvora istog podatka.
 *
 * Kad `_gtin` kaze jedno a `global_unique_id` drugo, jedan od njih je pogresan i
 * trgovac to mora znati. Upisuje se jaci, ali razlika ostaje zabiljezena — tiho
 * biranje bi kriv podatak sakrilo, a tocan izgubilo.
 *
 * Ime klase nosi "podataka" jer `Diagnostika\Sukobi` vec postoji i znaci nesto
 * drugo: sukobe medu DODACIMA na hookovima cijene.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Sukobi_Podataka {

	private static function tablica(): string {
		return Config::table( Config::TABLE_SUKOBI );
	}

	/**
	 * Zabiljezi neslaganje. Jedan zapis po artiklu i polju — novi prepisuje stari,
	 * jer opisuje isto mjesto, samo svjeziji nalaz.
	 */
	public static function zapisi( int $entity_id, string $polje, array $sukob, string $upisan ): void {
		global $wpdb;

		$podaci = array(
			'entity_id'    => $entity_id,
			'polje'        => $polje,
			'izvor_a'      => (string) $sukob['izvor_a'],
			'vrijednost_a' => mb_substr( (string) $sukob['vrijednost_a'], 0, 255 ),
			'izvor_b'      => (string) $sukob['izvor_b'],
			'vrijednost_b' => mb_substr( (string) $sukob['vrijednost_b'], 0, 255 ),
			'upisan'       => $upisan,
			'rijesen'      => null,
			'zapisano'     => current_time( 'mysql', true ),
		);

		$postoji = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM `' . self::tablica() . '` WHERE entity_id = %d AND polje = %s',
				$entity_id,
				$polje
			) // phpcs:ignore
		);

		if ( $postoji ) {
			$wpdb->update( self::tablica(), $podaci, array( 'id' => (int) $postoji ) );
			return;
		}

		$wpdb->insert( self::tablica(), $podaci );
	}

	/** Nema vise neslaganja za ovo polje — makni zapis. */
	public static function ocisti( int $entity_id, string $polje ): void {
		global $wpdb;

		$wpdb->delete(
			self::tablica(),
			array(
				'entity_id' => $entity_id,
				'polje'     => $polje,
			)
		);
	}

	public static function oznaci_rijesenim( int $id ): void {
		global $wpdb;
		$wpdb->update( self::tablica(), array( 'rijesen' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	public static function broj( ?string $polje = null ): int {
		global $wpdb;

		if ( null === $polje ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . self::tablica() . '` WHERE rijesen IS NULL' ); // phpcs:ignore
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . self::tablica() . '` WHERE polje = %s AND rijesen IS NULL', $polje ) // phpcs:ignore
		);
	}

	/** @return object[] */
	public static function nerijeseni( int $koliko = 500 ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, COALESCE( NULLIF( p.post_title, "" ), par.post_title ) AS naziv
				 FROM `' . self::tablica() . "` s
				 JOIN {$wpdb->posts} p ON p.ID = s.entity_id
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 WHERE s.rijesen IS NULL
				 ORDER BY s.polje ASC, s.entity_id ASC
				 LIMIT %d",
				$koliko
			) // phpcs:ignore
		);
	}
}
