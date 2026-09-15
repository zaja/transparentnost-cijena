<?php
/**
 * Posao: vrati cijene na vrijednosti prije zaokruzivanja.
 *
 * Postoji iz istog razloga kao i ostali povrati: operacija koja mijenja cijene na
 * cijelom katalogu bez puta natrag nije prihvatljiva, bez obzira na to koliko je
 * politika koja je izazvala dogovorena.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Db;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;
use CJTR\Povijest\Biljeznik;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Vrati_Zaokruzivanje extends Posao {

	private const IZVOR = 'zaokruzi_cijene';

	public function kljuc(): string {
		return 'vrati_zaokruzivanje';
	}

	public function naziv(): string {
		return __( 'Ponisti: svodenje cijena na dvije decimale', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Vraca cijene i sidrene cijene na vrijednosti kakve su bile prije svodenja na dvije decimale.', Config::TEXT_DOMAIN );
	}

	public function ponistava(): string {
		return self::IZVOR;
	}

	public function zapreka(): string {
		if ( 0 === Trag::broj_nevracenih( self::IZVOR ) ) {
			return __( 'Nema nicega za vratiti — zaokruzivanje jos nije pokretano ili je sve vec vraceno.', Config::TEXT_DOMAIN );
		}
		return '';
	}

	public function ukupno(): int {
		return Trag::broj_nevracenih( self::IZVOR );
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$zapisi = Trag::nevraceni( self::IZVOR, $zadnji_id, $velicina );

		if ( empty( $zapisi ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$rez    = Rezultat_Komada::s( 0, null );
		$zadnji = 0;

		$vracenih = Biljeznik::pod_okidacem(
			Config::OKIDAC_ZAOKRUZIVANJE,
			function () use ( $zapisi, $rez, &$zadnji ) {
				$n = 0;

				foreach ( $zapisi as $zapis ) {
					$zadnji = (int) $zapis->id;

					if ( $this->jedan( $zapis, $rez ) ) {
						$n++;
					}

					Trag::oznaci_vracenim( (int) $zapis->id );
				}

				return $n;
			}
		);

		$rez->obradeno  = (int) $vracenih;
		$rez->zadnji_id = $zadnji ?: null;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		Zaokruzi_Cijene::povuci_potvrdu();

		$stanje = Stanje::ucitaj( $this->kljuc() );
		$stanje->zapisi(
			'upozorenje',
			__( 'Cijene su vracene na vrijednosti s vise od dvije decimale. Cjenik ce ponovno objavljivati dvije razlicite brojke za istu cijenu dok se zaokruzivanje ne izvede.', Config::TEXT_DOMAIN )
		);

		\CJTR\Prikaz\Cache::ocisti();
	}

	/* --------------------------------------------------------------- interno */

	private function jedan( $zapis, Rezultat_Komada $rez ): bool {
		global $wpdb;

		$id     = (int) $zapis->entity_id;
		$stanje = Trag::stanje( $zapis );

		if ( empty( $stanje ) || ! isset( $stanje['meta'] ) ) {
			$rez->greska(
				sprintf(
					/* translators: %d = ID zapisa */
					__( 'Zapis %d nema citljivo stanje, preskocen.', Config::TEXT_DOMAIN ),
					(int) $zapis->id
				)
			);
			return false;
		}

		foreach ( (array) $stanje['meta'] as $kljuc => $vrijednost ) {
			if ( ! in_array( $kljuc, Config::ZAOKRUZIVANJE_META, true ) ) {
				continue;
			}

			if ( '' === (string) $vrijednost ) {
				delete_post_meta( $id, $kljuc );
				continue;
			}

			update_post_meta( $id, $kljuc, (string) $vrijednost );
		}

		$sidrena = $stanje['sidrena'] ?? null;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . Config::table( Config::TABLE_PODACI ) . '`
				 SET sidrena_cijena = ' . Db::cijena( $sidrena ) . ', azurirano = %s
				 WHERE entity_id = %d',
				current_time( 'mysql', true ),
				$id
			) // phpcs:ignore
		);

		return true;
	}
}
