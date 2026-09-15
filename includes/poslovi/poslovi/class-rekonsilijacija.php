<?php
/**
 * Posao: dnevna rekonsilijacija vlastite povijesti.
 *
 * SLOJ 3 od tri (ANALIZA.md, sekcija L).
 *
 * Sloj 1 hvata sve sto ide kroz WordPress meta API. Ne hvata izravan SQL,
 * `$wpdb->update`, alate za migraciju baze ni obnovu iz sigurnosne kopije.
 * Ovaj posao je mreza ispod toga: usporedi zapisano stanje s onim u povijesti
 * i gdje se razlikuje upise redak.
 *
 * Sto NE hvata: promjenu koja se dogodi i vrati izmedu dva ciklusa. To je
 * preostali rizik nakon sva tri sloja i jedini koji ostaje.
 *
 * Zapisi nose `pocetak_pouzdan = 0`: zna se samo da je promjena nastupila negdje
 * izmedu proslog i ovog ciklusa, ne kad tocno.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Katalog;
use CJTR\Povijest\Biljeznik;
use CJTR\Povijest\Zapis;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;

defined( 'ABSPATH' ) || exit;

final class Rekonsilijacija extends Posao {

	public function kljuc(): string {
		return 'rekonsilijacija';
	}

	public function naziv(): string {
		return __( 'Uhvati promjene cijena koje su promakle', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Usporeduje cijene u trgovini s vlastitom evidencijom i dopisuje ono cega u njoj nema. Pokriva izmjene napravljene mimo uobicajenog puta — izravno u bazi, uvozom ili masovnim uredivanjem. Radi se samo od sebe jednom dnevno; rucno pokretanje treba samo ako nesto provjeravate.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_ODRZAVANJE;
	}

	/** Dnevno, prije generiranja cjenika — evidencija mora biti svjeza kad cjenik izade. */
	public function dnevno(): string {
		return '05:00';
	}

	public function ukupno(): int {
		return Katalog::broj();
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$entiteti = Katalog::komad( $zadnji_id, $velicina );
		if ( empty( $entiteti ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids = array();
		foreach ( $entiteti as $e ) {
			$ids[] = (int) $e->ID;
		}

		$rez      = Rezultat_Komada::s( count( $entiteti ), max( $ids ) );
		$otvoreni = Zapis::otvoreni_za( $ids );
		$mete     = $this->mete( $ids );
		$nadeno   = 0;

		foreach ( $ids as $id ) {
			$m = $mete[ $id ] ?? array();

			/*
			 * Vrsta se IZVODI, ne upisuje tvrdo.
			 *
			 * Ranije je ovdje stajalo `POP_NEMA` za svaki interval, pa je akcija koju
			 * je ovaj posao uhvatio u zapisu izgledala kao da je nije ni bilo. Dok se
			 * vrsta nigdje nije koristila, to nije smetalo — otkad je naziv posebnog
			 * oblika prodaje obvezno polje objave, smeta.
			 */
			$stanje = array(
				'regular'   => $m['_regular_price'] ?? null,
				'sale'      => $m['_sale_price'] ?? null,
				'price'     => $m['_price'] ?? null,
			);

			$stanje['vrsta_pop'] = Biljeznik::vrsta_pop(
				$id,
				$stanje['regular'],
				$stanje['sale'],
				$stanje['price']
			);

			// Bez ijedne cijene nema se sto usporediti.
			if ( null === $stanje['price'] && null === $stanje['regular'] ) {
				continue;
			}

			if ( Zapis::zabiljezi( $id, $stanje, Config::OKIDAC_RECONCILE ) ) {
				$nadeno++;
			}
		}

		if ( $nadeno > 0 ) {
			$this->zapisi_nalaz( $nadeno, (int) $zadnji_id );
		}

		return $rez;
	}

	/**
	 * @param int[] $ids
	 * @return array<int,array<string,string|null>>
	 */
	private function mete( array $ids ): array {
		global $wpdb;

		$u = implode( ',', array_map( 'intval', $ids ) );

		$redci = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ({$u})
			   AND meta_key IN ('_price','_regular_price','_sale_price')" // phpcs:ignore
		);

		$out = array();
		foreach ( $redci as $r ) {
			$out[ (int) $r->post_id ][ $r->meta_key ] = ( '' === $r->meta_value ) ? null : $r->meta_value;
		}
		return $out;
	}

	private function zapisi_nalaz( int $koliko, int $od ): void {
		$stanje        = new \CJTR\Poslovi\Stanje();
		$stanje->kljuc = $this->kljuc();
		$stanje->zapisi(
			'info',
			sprintf(
				/* translators: 1: broj, 2: ID od kojega je komad krenuo */
				__( 'Dopisano %1$d zapisa koji su promakli (komad iza ID %2$d).', Config::TEXT_DOMAIN ),
				$koliko,
				$od
			)
		);
	}
}
