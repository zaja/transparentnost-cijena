<?php
/**
 * Posao: pokupi barkod, marku i kolicinu iz svega sto trgovina vec ima.
 *
 * STO RADI
 *
 * Za svaki artikl prode lanac izvora (vidi `Podaci\Izvori`) i upise najjaci
 * nalaz. Ne izmislja nista — samo skuplja ono sto je vec negdje zapisano, ali
 * razbacano po meti, taksonomijama i atributima.
 *
 * Gdje se dva izvora razilaze, upise jaci a razliku ZAPISE. Tiho biranje sakrilo
 * bi kriv podatak i izgubilo tocan.
 *
 * STO NE RADI
 *
 * Ne dira rucni unos. Vrijednost koju je upisao covjek jaca je od svega sto stroj
 * moze izvesti, pa se preskace.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Db;
use CJTR\Katalog;
use CJTR\Podaci\Gtin;
use CJTR\Podaci\Izvori;
use CJTR\Podaci\Kolicina;
use CJTR\Podaci\Marke;
use CJTR\Podaci\Sukobi_Podataka;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;

defined( 'ABSPATH' ) || exit;

final class Prikupi_Podatke extends Posao {

	public function kljuc(): string {
		return 'prikupi_podatke';
	}

	public function naziv(): string {
		return __( 'Pokupi podatke koje trgovina vec ima', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Prolazi kroz artikle i skuplja barkod, marku i neto kolicinu iz svega sto je vec negdje zapisano — polja WooCommercea, podatke drugih dodataka, atribute i nazive. Nista ne izmislja. Gdje dva izvora kazu razlicito, upisuje jaci i biljezi razliku da je mozete pogledati.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 9;
	}

	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}
		return '';
	}

	public function ukupno(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . Katalog::uvjet() ); // phpcs:ignore
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT p.ID FROM ' . $wpdb->posts . ' p WHERE ' . Katalog::uvjet() . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d',
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		if ( empty( $ids ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids       = array_map( 'intval', $ids );
		$rez       = Rezultat_Komada::s( 0, max( $ids ) );
		$obradenih = 0;

		foreach ( $ids as $id ) {
			if ( $this->jedan( $id, $rez ) ) {
				$obradenih++;
			}
		}

		$rez->obradeno = $obradenih;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

		$sukoba = Sukobi_Podataka::broj();

		if ( $sukoba > 0 ) {
			$stanje->zapisi(
				'upozorenje',
				sprintf(
					/* translators: %d = broj artikala */
					__( 'Kod %d artikala dva izvora kazu razlicito. Upisan je jaci, a razlike su na ekranu "Podaci o proizvodima". Dok se ne pogledaju, dio podataka je mozda kriv.', Config::TEXT_DOMAIN ),
					$sukoba
				)
			);
		}

		foreach ( Db::provjeri_nule() as $nalaz ) {
			$stanje->zapisi(
				'greska',
				sprintf(
					/* translators: 1: stupac, 2: tablica, 3: koliko */
					__( 'Provjera nula: stupac %1$s u tablici %2$s ima %3$d redaka s nulom. Nula ondje nije podatak nego pogreska pri upisu.', Config::TEXT_DOMAIN ),
					$nalaz['stupac'],
					$nalaz['tablica'],
					$nalaz['koliko']
				)
			);
		}
	}

	/* --------------------------------------------------------------- interno */

	private function jedan( int $id, Rezultat_Komada $rez ): bool {
		$promjene = array();

		foreach ( array( Config::POLJE_BARKOD, Config::POLJE_MARKA, Config::POLJE_KOLICINA ) as $polje ) {
			$upisano = $this->za_polje( $id, $polje, $rez );
			if ( ! empty( $upisano ) ) {
				$promjene = array_merge( $promjene, $upisano );
			}
		}

		if ( empty( $promjene ) ) {
			return false;
		}

		$this->upisi( $id, $promjene );
		return true;
	}

	/**
	 * Odluci sto upisati za jedno polje.
	 *
	 * @return array<string,mixed> stupac => vrijednost, prazno ako nema sto upisati
	 */
	private function za_polje( int $id, string $polje, Rezultat_Komada $rez ): array {
		// Rucni unos se ne dira.
		if ( $this->rucno_uneseno( $id, $polje ) ) {
			return array();
		}

		$odluka = Izvori::odluci( $id, $polje );

		if ( null === $odluka ) {
			Sukobi_Podataka::ocisti( $id, $polje );
			return array();
		}

		if ( null !== $odluka['sukob'] ) {
			Sukobi_Podataka::zapisi( $id, $polje, $odluka['sukob'], $odluka['izvor'] );
		} else {
			Sukobi_Podataka::ocisti( $id, $polje );
		}

		switch ( $polje ) {
			case Config::POLJE_BARKOD:
				return $this->barkod( $odluka, $rez, $id );

			case Config::POLJE_MARKA:
				return array(
					'marka'       => Marke::kanonski( $odluka['vrijednost'] ),
					'marka_izvor' => $odluka['izvor'],
				);

			case Config::POLJE_KOLICINA:
				$razlozena = Kolicina::razlozi( $odluka['vrijednost'] );
				if ( null === $razlozena ) {
					return array();
				}
				return array(
					'neto_kolicina'  => $razlozena['kolicina'],
					'jedinica_mjere' => $razlozena['jedinica'],
					'kolicina_izvor' => $odluka['izvor'],
				);
		}

		return array();
	}

	/**
	 * Barkod se upisuje SA STATUSOM, i kad status nije "valjan".
	 *
	 * Neispravan kod koji vec stoji u trgovini ne brise se nego se oznacava — brisan
	 * bi nestao bez traga, a trgovac ga mora vidjeti da bi ga ispravio. U katalogu
	 * su tri takva zapisa (`40`, `20`, `100`).
	 */
	private function barkod( array $odluka, Rezultat_Komada $rez, int $id ): array {
		$cist   = Gtin::ocisti( $odluka['vrijednost'] );
		$status = Gtin::status( $cist );

		if ( Config::GTIN_NEISPRAVAN === $status ) {
			$rez->preskoci(
				$id,
				sprintf(
					/* translators: %s = zapis barkoda */
					__( 'zateceni barkod "%s" ne prolazi kontrolnu znamenku — upisan je i oznacen, ali ne ide u cjenik', Config::TEXT_DOMAIN ),
					$cist
				)
			);
		}

		return array(
			'barkod'        => Gtin::u_ean13( $cist ),
			'barkod_izvor'  => $odluka['izvor'],
			'barkod_status' => $status,
		);
	}

	private function rucno_uneseno( int $id, string $polje ): bool {
		global $wpdb;

		$stupac = preg_replace( '/[^a-z_]/', '', Config::POLJA[ $polje ]['izvor'] );

		$izvor = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `' . $stupac . '` FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$id
			) // phpcs:ignore
		);

		return in_array(
			(string) $izvor,
			array( Config::IZVOR_PODATKA_RUCNO, Config::IZVOR_PODATKA_CSV ),
			true
		);
	}

	/** @param array<string,mixed> $promjene */
	private function upisi( int $id, array $promjene ): void {
		global $wpdb;

		$tablica = Config::table( Config::TABLE_PODACI );
		$sada    = current_time( 'mysql', true );

		$postavke = array();
		$vrijed   = array();

		foreach ( $promjene as $stupac => $vrijednost ) {
			$cist = preg_replace( '/[^a-z_]/', '', $stupac );

			// NULL kao literal — kroz prepare('%s', null) postao bi prazan string,
			// a u DECIMAL stupcu prazan string postaje nula.
			if ( null === $vrijednost ) {
				$postavke[] = "`{$cist}` = NULL";
				continue;
			}

			$postavke[] = "`{$cist}` = %s";
			$vrijed[]   = $vrijednost;
		}

		$postavke[] = '`azurirano` = %s';
		$vrijed[]   = $sada;
		$vrijed[]   = $id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$tablica}` SET " . implode( ', ', $postavke ) . ' WHERE entity_id = %d',
				$vrijed
			) // phpcs:ignore
		);
	}
}
