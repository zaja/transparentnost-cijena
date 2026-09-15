<?php
/**
 * Posao: prenesi postojecu povijest cijena u vlastitu tablicu.
 *
 * Jednokratno. Tudi izvor se SAMO CITA — u njega se ne pise i ne mijenja mu se
 * struktura.
 *
 * STO SE GUBI I ZASTO JE TO U REDU
 *
 * Izvor biljezi samo efektivnu cijenu, pa `regular_price` ostaje prazan. To nije
 * propust uvoza nego granica izvora — i upravo razlog zbog kojeg vlastita povijest
 * postoji. Zapisi nose okidac 'uvoz' i `pocetak_pouzdan = 0`, jer se pocetak
 * intervala u izvoru ne moze uvijek potvrditi.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Cijene\Povijest_Cijena;
use CJTR\Config;
use CJTR\Db;
use CJTR\Katalog;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;

defined( 'ABSPATH' ) || exit;

final class Uvoz_Povijesti extends Posao {

	public function kljuc(): string {
		return 'uvoz_povijesti';
	}

	public function naziv(): string {
		return __( 'Preuzmi povijest cijena iz postojeceg dodatka', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Prenosi zapise o proslim cijenama iz dodatka koji ih je dosad vodio, da sidrena cijena ima iz cega biti utvrdena. Bez toga bi povijest pocela od danas i sidrena cijena za proteklo razdoblje ne bi se mogla dokazati. Postojeci zapisi se samo citaju i ostaju netaknuti.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( ! Povijest_Cijena::postoji() ) {
			return __( 'U trgovini ne postoji zapis o proslim cijenama koji bi se prenio.', Config::TEXT_DOMAIN );
		}
		if ( $this->vec_uvezeno() > 0 ) {
			return __( 'Povijest je vec prenesena. Ponovni prijenos bi udvostrucio zapise.', Config::TEXT_DOMAIN );
		}
		return '';
	}

	/** Ide OD KATALOGA — povijest sadrzi i obrisane artikle koji nam ne trebaju. */
	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 2;
	}

	public function preduvjet(): string {
		return 'prebroji';
	}

	public function ukupno(): int {
		return Katalog::broj();
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		$entiteti = Katalog::komad( $zadnji_id, $velicina );
		if ( empty( $entiteti ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids = array();
		foreach ( $entiteti as $e ) {
			$ids[] = (int) $e->ID;
		}

		$rez     = Rezultat_Komada::s( 0, max( $ids ) );
		$izvor   = Povijest_Cijena::tablica();
		$u       = implode( ',', $ids );
		$tablica = Config::table( Config::TABLE_POVIJEST );

		$redci = (array) $wpdb->get_results(
			"SELECT product_id, price, timestamp, timestamp_end
			 FROM `{$izvor}`
			 WHERE product_id IN ({$u})
			 ORDER BY product_id ASC, timestamp ASC, price_history_id ASC" // phpcs:ignore
		);

		if ( empty( $redci ) ) {
			// Nijedan entitet iz ovog komada nema povijest — nije greska.
			return $rez;
		}

		$preneseno = 0;
		$vrijednosti = array();

		foreach ( $redci as $r ) {
			$vrijednosti[] = sprintf(
				'( %d, NULL, NULL, %s, %d, %d, %s, %s, 0 )',
				(int) $r->product_id,
				Db::cijena( $r->price ),
				(int) $r->timestamp,
				(int) $r->timestamp_end,
				$wpdb->prepare( '%s', Config::OKIDAC_UVOZ ),
				$wpdb->prepare( '%s', Config::POP_NEMA )
			);
			$preneseno++;
		}

		foreach ( array_chunk( $vrijednosti, 200 ) as $dio ) {
			$wpdb->query(
				"INSERT INTO `{$tablica}`
					( entity_id, regular_price, sale_price, price, ts, ts_end, okidac, vrsta_pop, pocetak_pouzdan )
				 VALUES " . implode( ',', $dio ) // phpcs:ignore
			);
		}

		$rez->obradeno = count( $entiteti );

		if ( $preneseno > 0 ) {
			$rez->greske = array(); // uvoz nema gresaka po entitetu
		}

		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new \CJTR\Poslovi\Stanje();
		$stanje->kljuc = $this->kljuc();

		$stanje->zapisi(
			'info',
			sprintf(
				/* translators: 1: preneseno zapisa, 2: entiteta */
				__( 'Preneseno %1$d zapisa za %2$d artikala. Redovna cijena je prazna jer je izvor ne biljezi.', Config::TEXT_DOMAIN ),
				\CJTR\Povijest\Zapis::broj_zapisa(),
				\CJTR\Povijest\Zapis::broj_entiteta()
			)
		);
	}

	private function vec_uvezeno(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . Config::table( Config::TABLE_POVIJEST ) . '` WHERE okidac = %s',
				Config::OKIDAC_UVOZ
			) // phpcs:ignore
		);
	}
}
