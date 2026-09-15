<?php
/**
 * Testni posao: prebroji zive entitete.
 *
 * Namjerno bezopasan — ne pise nista u katalog. Sluzi samo da se vidi da okvir
 * radi na stvarnom broju entiteta, prije nego mu se povjeri posao koji mijenja
 * podatke.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Katalog;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;

defined( 'ABSPATH' ) || exit;

final class Prebroji extends Posao {

	public function kljuc(): string {
		return 'prebroji';
	}

	public function naziv(): string {
		return __( 'Provjeri radi li obrada na ovom posluzitelju', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Prode kroz sve artikle i samo ih prebroji. Ne mijenja nista. Pokrenite ovo prvo — ako zavrsi, ostali poslovi ce raditi; ako zapne, javit ce zasto prije nego itko dira cijene.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 1;
	}

	public function ukupno(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $this->uvjet( 'SELECT COUNT(*)' ) ); // phpcs:ignore
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		// Keyset, ne OFFSET — vidi ugovor u Posao::obradi().
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				$this->uvjet( 'SELECT p.ID' ) . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d',
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		if ( empty( $ids ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids = array_map( 'intval', $ids );

		$broj = (int) get_option( Config::option( 'prebrojano' ), 0 );
		if ( 0 === $zadnji_id ) {
			$broj = 0;
		}
		update_option( Config::option( 'prebrojano' ), $broj + count( $ids ), false );

		return Rezultat_Komada::s( count( $ids ), max( $ids ) );
	}

	public function nakon_zavrsetka(): void {
		$broj = (int) get_option( Config::option( 'prebrojano' ), 0 );
		update_option( Config::option( 'prebrojano_rezultat' ), $broj, false );
	}

	private function uvjet( string $select ): string {
		global $wpdb;
		return "{$select} FROM {$wpdb->posts} p WHERE " . Katalog::uvjet();
	}
}
