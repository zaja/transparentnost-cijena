<?php
/**
 * Posao: vrati dodatne cijene koje je upisao korak nakon promocije.
 *
 * ZASTO ZASEBAN POSAO, A NE DIO POVRATA CIJENA
 *
 * Promocija i upis dodatne cijene dva su koraka i mogu se pokrenuti neovisno.
 * Kad bi ih jedan povrat vracao zajedno, ishod bi ovisio o tome je li drugi
 * korak uopce bio pokrenut — a upravo takvu ovisnost o redoslijedu projekt je
 * vec jednom platio kod oscilirajucih cijena.
 *
 * STO VRACA
 *
 * Cijeli zatecen redak tablice podataka, ukljucujuci OBA kandidata za dodatnu
 * cijenu koje je upis obrisao. Kandidati su ondje bitni: bez njih se artikl ne
 * bi vratio na popis za odluku nego bi ostao bez ijedne vrijednosti.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Vrati_Sidrenu_Nakon_Promocije extends Posao {

	/** Operacija ciji se trag vraca. */
	private const IZVOR = 'sidrena_nakon_promocije';

	public function kljuc(): string {
		return 'vrati_sidrenu_nakon_promocije';
	}

	public function naziv(): string {
		return __( 'Ponisti: sidrenu cijenu za ukinute akcije', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Vraca sidrene cijene i kandidate onakve kakvi su bili prije nego sto ih je upisao drugi dio ukidanja akcija. Artikli se time vracaju na popis za odluku.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( 0 === Trag::broj_nevracenih( self::IZVOR ) ) {
			return __( 'Nema nicega za vratiti — korak upisa sidrene cijene jos nije pokretan ili je sve vec vraceno.', Config::TEXT_DOMAIN );
		}
		return '';
	}

	public function ukupno(): int {
		return Trag::broj_nevracenih( self::IZVOR );
	}

	/** Okvir na temelju ovoga sam prebaci ponisteni posao u stanje "gotovo, pa ponisteno". */
	public function ponistava(): string {
		return self::IZVOR;
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$zapisi = Trag::nevraceni( self::IZVOR, $zadnji_id, $velicina );

		if ( empty( $zapisi ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$rez      = Rezultat_Komada::s( 0, null );
		$zadnji   = 0;
		$vracenih = 0;

		foreach ( $zapisi as $zapis ) {
			$zadnji = (int) $zapis->id;

			$stanje = Trag::stanje( $zapis );
			if ( empty( $stanje ) ) {
				$rez->greska(
					sprintf(
						/* translators: %d = ID zapisa */
						__( 'Zapis %d nema citljivo stanje, preskocen.', Config::TEXT_DOMAIN ),
						$zadnji
					)
				);
				continue;
			}

			if ( $this->vrati( (int) $zapis->entity_id, $stanje, $rez ) ) {
				$vracenih++;
			}

			Trag::oznaci_vracenim( $zadnji );
		}

		$rez->obradeno  = $vracenih;
		$rez->zadnji_id = $zadnji;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

		$stanje->zapisi(
			'info',
			__( 'Sidrene cijene su vracene na stanje prije odluke. Cijene proizvoda NISU dirane — njih vraca posao "Vrati promovirane akcije".', Config::TEXT_DOMAIN )
		);

		\CJTR\Prikaz\Cache::ocisti();
	}

	/* --------------------------------------------------------------- interno */

	private function vrati( int $id, array $stanje, Rezultat_Komada $rez ): bool {
		global $wpdb;

		$tablica = Config::table( Config::TABLE_PODACI );

		// Popis stupaca dolazi iz posla koji je pisao — jedan izvor za oba smjera.
		$postavke = array();
		$vrijed   = array();

		foreach ( Sidrena_Nakon_Promocije::STUPCI as $stupac ) {
			if ( ! array_key_exists( $stupac, $stanje ) ) {
				continue;
			}

			$cist = preg_replace( '/[^a-z_]/', '', $stupac );
			$v    = $stanje[ $stupac ];

			// NULL se upisuje kao literal NULL. Kroz prepare('%s', null) postao bi
			// prazan string, a u DECIMAL stupcu prazan string postaje nula — greska
			// koja se u ovom projektu vratila tri puta.
			if ( null === $v ) {
				$postavke[] = "`{$cist}` = NULL";
				continue;
			}

			$postavke[] = "`{$cist}` = %s";
			$vrijed[]   = $v;
		}

		if ( empty( $postavke ) ) {
			$rez->preskoci( $id, __( 'zapis ne sadrzi nijedan poznat stupac', Config::TEXT_DOMAIN ) );
			return false;
		}

		$postavke[] = '`azurirano` = %s';
		$vrijed[]   = current_time( 'mysql', true );
		$vrijed[]   = $id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$tablica}` SET " . implode( ', ', $postavke ) . ' WHERE entity_id = %d',
				$vrijed
			) // phpcs:ignore
		);

		return $this->provjeri( $id, $stanje, $rez );
	}

	/**
	 * Je li vraceno tocno ono sto pise u tragu.
	 *
	 * Povrat koji ne vraca istu vrijednost nije povrat, pa se to ne pretpostavlja
	 * nego cita natrag iz baze.
	 */
	private function provjeri( int $id, array $stanje, Rezultat_Komada $rez ): bool {
		global $wpdb;

		$stupci = implode( ', ', array_map( function ( $s ) {
			return '`' . preg_replace( '/[^a-z_]/', '', $s ) . '`';
		}, Sidrena_Nakon_Promocije::STUPCI ) );

		$sada = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$stupci} FROM `" . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$id
			), // phpcs:ignore
			ARRAY_A
		);

		if ( ! is_array( $sada ) ) {
			$rez->greska(
				sprintf(
					/* translators: %d = ID artikla */
					__( 'ID %d: redak se nakon vracanja nije mogao procitati.', Config::TEXT_DOMAIN ),
					$id
				)
			);
			return false;
		}

		foreach ( Sidrena_Nakon_Promocije::STUPCI as $stupac ) {
			if ( ! array_key_exists( $stupac, $stanje ) ) {
				continue;
			}

			$ocekivano = $stanje[ $stupac ];
			$dobiveno  = $sada[ $stupac ] ?? null;

			if ( null === $ocekivano || null === $dobiveno ) {
				if ( $ocekivano !== $dobiveno ) {
					$rez->greska(
						sprintf(
							/* translators: 1: ID, 2: stupac, 3: dobiveno, 4: ocekivano */
							__( 'ID %1$d: %2$s je "%3$s" umjesto "%4$s".', Config::TEXT_DOMAIN ),
							$id,
							$stupac,
							( null === $dobiveno ) ? 'prazno' : $dobiveno,
							( null === $ocekivano ) ? 'prazno' : $ocekivano
						)
					);
					return false;
				}
				continue;
			}

			// Brojcana usporedba gdje su obje vrijednosti brojevi: DECIMAL vraca
			// '4.7200' ondje gdje je u tragu '4.72', a to je ista vrijednost.
			$isto = ( is_numeric( $ocekivano ) && is_numeric( $dobiveno ) )
				? ( abs( (float) $ocekivano - (float) $dobiveno ) <= Config::TOLERANCIJA_POVIJESTI )
				: ( (string) $ocekivano === (string) $dobiveno );

			if ( ! $isto ) {
				$rez->greska(
					sprintf(
						/* translators: 1: ID, 2: stupac, 3: dobiveno, 4: ocekivano */
						__( 'ID %1$d: %2$s je "%3$s" umjesto "%4$s".', Config::TEXT_DOMAIN ),
						$id,
						$stupac,
						$dobiveno,
						$ocekivano
					)
				);
				return false;
			}
		}

		return true;
	}
}
