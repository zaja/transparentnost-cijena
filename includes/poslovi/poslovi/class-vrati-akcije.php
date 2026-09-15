<?php
/**
 * Posao: vrati akcijske podatke koje je posao "Ocisti istekle akcije" uklonio.
 *
 * Postoji zato sto brisanje tudih podataka bez povrata nije prihvatljivo. Ako se
 * ispostavi da je akcijska meta bila namjerna, ovdje se vraca tocno onakva kakva
 * je bila, iz traga zapisanog prije brisanja.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Vrati_Akcije extends Posao {

	/** Operacija ciji se trag vraca. */
	private const IZVOR = 'istekle_akcije';

	public function kljuc(): string {
		return 'vrati_akcije';
	}

	public function naziv(): string {
		return __( 'Ponisti: zaustavljanje skakanja cijena', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Vraca akcijske podatke koje je posao "Zaustavi cijene koje skacu gore-dolje" uklonio, tocno onakve kakvi su bili. Koristi se samo ako se ispostavi da je uklonjeno nesto sto je bilo namjerno.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}
		if ( 0 === Trag::broj_nevracenih( self::IZVOR ) ) {
			return __( 'Nema nicega za vratiti — posao ciscenja jos nije pokretan ili je sve vec vraceno.', Config::TEXT_DOMAIN );
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

		$zadnji    = 0;
		$vracenih  = 0;
		$rez       = Rezultat_Komada::s( 0, null );

		foreach ( $zapisi as $zapis ) {
			$zadnji = (int) $zapis->id;

			$proizvod = wc_get_product( (int) $zapis->entity_id );
			if ( ! $proizvod ) {
				$rez->preskoci( (int) $zapis->entity_id, __( 'artikl vise ne postoji', Config::TEXT_DOMAIN ) );
				Trag::oznaci_vracenim( $zadnji );
				continue;
			}

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

			// Vracamo kroz isti sloj kojim je i uklonjeno, da se sve izvedene
			// vrijednosti presloze jednako kao sto su bile.
			//
			// Datumi se vracaju iz SIROVOG vremenskog ziga. Formatirani datum bi
			// izgubio doba dana i skratio akciju za gotovo cijeli dan.
			$proizvod->set_sale_price( (string) ( $stanje['sale'] ?? '' ) );
			$proizvod->set_date_on_sale_from( ! empty( $stanje['from_ts'] ) ? (int) $stanje['from_ts'] : null );
			$proizvod->set_date_on_sale_to( ! empty( $stanje['to_ts'] ) ? (int) $stanje['to_ts'] : null );
			$proizvod->save();

			// Provjeri da je vraceno tocno ono sto je bilo — i cijena i datumi.
			$svjez = wc_get_product( (int) $zapis->entity_id );
			if ( $svjez ) {
				foreach ( $this->odstupanja( $svjez, $stanje ) as $opis ) {
					$rez->greska(
						sprintf(
							/* translators: 1: ID, 2: opis odstupanja */
							__( 'ID %1$d: vraceno stanje ne odgovara zapisanom — %2$s', Config::TEXT_DOMAIN ),
							(int) $zapis->entity_id,
							$opis
						)
					);
				}
			}

			Trag::oznaci_vracenim( $zadnji );
			$vracenih++;
		}

		$rez->obradeno  = $vracenih;
		$rez->zadnji_id = $zadnji;
		return $rez;
	}

	/**
	 * Razlike izmedu vracenog i zapisanog stanja.
	 *
	 * @return string[] prazno ako je sve vraceno vjerno
	 */
	private function odstupanja( $proizvod, array $stanje ): array {
		$greske = array();

		$sada = (string) $proizvod->get_price( 'edit' );
		if ( isset( $stanje['price'] ) && '' !== $sada
			&& abs( (float) $sada - (float) $stanje['price'] ) > Config::TOLERANCIJA_POVIJESTI ) {
			$greske[] = sprintf( 'cijena %s umjesto %s', $sada, $stanje['price'] );
		}

		$parovi = array(
			'from_ts' => $proizvod->get_date_on_sale_from( 'edit' ),
			'to_ts'   => $proizvod->get_date_on_sale_to( 'edit' ),
		);

		foreach ( $parovi as $kljuc => $datum ) {
			$ocekivano = (int) ( $stanje[ $kljuc ] ?? 0 );
			$dobiveno  = $datum ? (int) $datum->getTimestamp() : 0;
			if ( $ocekivano !== $dobiveno ) {
				$greske[] = sprintf( '%s %d umjesto %d', $kljuc, $dobiveno, $ocekivano );
			}
		}

		return $greske;
	}
}
