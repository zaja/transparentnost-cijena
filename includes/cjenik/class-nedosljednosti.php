<?php
/**
 * Zapisi koji sami sebi proturjece.
 *
 * ZASTO ZASEBNO OD "87 CIJENA S VISE DECIMALA"
 *
 * "87 cijena ima vise od dvije decimale" je tvrdnja o podacima i lako se procita
 * kao sitnica. "49 artikala u objavljenoj datoteci ima dvije razlicite brojke za
 * istu cijenu" je tvrdnja o DATOTECI, i to je ono sto bi inspektoru prvo zapelo
 * za oko.
 *
 * Primjer iz stvarne datoteke, artikl 334:
 *
 *   maloprodajna_cijena        3.849
 *   cijena_za_jedinicu_mjere   3.85
 *
 * Artikl se prodaje po komadu, pa te dvije brojke MORAJU biti jednake. Nisu, jer
 * maloprodajna izlazi neizmijenjena a cijena po jedinici se racuna i zaokruzuje.
 *
 * NE POPRAVLJA SE OVDJE
 *
 * Generator ne zaokruzuje — to je politika nad katalogom, zaseban posao. Ovdje se
 * samo imenuje ono sto ce citatelj datoteke ionako vidjeti.
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;
use CJTR\Podaci\Kolicina;

defined( 'ABSPATH' ) || exit;

final class Nedosljednosti {

	/**
	 * Prodi kroz sve zapise i prebroj proturjecja.
	 *
	 * Cita se kroz `Redak::iz()`, dakle isto sto ide u datoteku — a ne iz baze
	 * izravno. Provjera koja gleda drugi izvor od onoga sto se objavljuje provjerava
	 * nesto drugo.
	 *
	 * @return array{
	 *     zapisa:int,
	 *     dvije_cijene:int, primjeri_dvije:array,
	 *     mp_veca_od_redovne:int, primjeri_mp:array,
	 *     dodatna_vise_decimala:int,
	 *     redovna_vise_decimala:int,
	 *     dodatna_veca_od_redovne:int
	 * }
	 */
	public static function prebroji(): array {
		$n = array(
			'zapisa'                  => 0,
			'dvije_cijene'            => 0,
			'primjeri_dvije'          => array(),
			'mp_veca_od_redovne'      => 0,
			'primjeri_mp'             => array(),
			'dodatna_vise_decimala'   => 0,
			'redovna_vise_decimala'   => 0,
			'dodatna_veca_od_redovne' => 0,
		);

		$zadnji = 0;

		while ( true ) {
			$komad = Izvor::komad( $zadnji, 500 );

			if ( empty( $komad ) ) {
				break;
			}

			foreach ( $komad as $r ) {
				$zadnji = max( $zadnji, (int) $r->entity_id );
				$n['zapisa']++;

				self::jedan( Redak::iz( $r ), $r, $n );
			}
		}

		return $n;
	}

	/**
	 * Vrijednost polja iz zapisa, kao string.
	 *
	 * Zapis moze nositi `null` — to znaci da se polje za taj artikl ne primjenjuje.
	 * Polje moze i potpuno nedostajati, jer shema vise nije ista kao prije. Oboje se
	 * ovdje svodi na prazno: nedosljednost izmedu dviju brojki ne postoji ako jedne
	 * od njih nema.
	 */
	private static function polje( array $z, string $kljuc ): string {
		return ( ! array_key_exists( $kljuc, $z ) || null === $z[ $kljuc ] ) ? '' : (string) $z[ $kljuc ];
	}

	/** @param array $n prosljeduje se referencom */
	private static function jedan( array $z, $r, array &$n ): void {
		$id = (int) $r->entity_id;
		$mp  = self::polje( $z, 'maloprodajna_cijena' );
		$cpj = self::polje( $z, 'cijena_za_jedinicu' );
		$red = self::polje( $z, 'redovna_cijena' );
		$dod = self::polje( $z, 'sidrena_cijena' );

		/*
		 * Kad je neto kolicina TOCNO jedna kanonska jedinica — 1 kom, 1 kg, 1 l —
		 * cijena po jedinici mjere mora biti jednaka maloprodajnoj. Usporeduje se
		 * ZAPIS, ne vrijednost: "3.849" i "3.85" su blizu kao brojevi, ali u datoteci
		 * stoje kao dvije razlicite tvrdnje o istoj cijeni.
		 *
		 * Pravilo je opcenitije nego ranije, i namjerno. Prva verzija je gledala samo
		 * "kom", a NN 101/2026 za komadnu robu to polje uopce vise ne objavljuje — pa
		 * bi provjera tiho prestala vrijediti. Artikl od 1 kg s cijenom na pet
		 * decimala nosi tocno isti problem i dalje se objavljuje.
		 */
		$kanonska = ( null === $r->neto_kolicina || '' === (string) $r->jedinica_mjere )
			? null
			: Kolicina::u_kanonsku( (float) $r->neto_kolicina, (string) $r->jedinica_mjere );

		$jedna_jedinica = $kanonska && abs( $kanonska['kolicina'] - 1.0 ) < 0.000001;

		if ( $jedna_jedinica && '' !== $cpj && '' !== $mp && $mp !== $cpj ) {

			$n['dvije_cijene']++;

			if ( count( $n['primjeri_dvije'] ) < 10 ) {
				$n['primjeri_dvije'][] = sprintf( '#%d: %s / %s', $id, $mp, $cpj );
			}
		}

		if ( '' !== $mp && '' !== $red && (float) $mp > (float) $red + Config::TOLERANCIJA_POVIJESTI ) {
			$n['mp_veca_od_redovne']++;

			if ( count( $n['primjeri_mp'] ) < 10 ) {
				$n['primjeri_mp'][] = sprintf( '#%d: %s > %s', $id, $mp, $red );
			}
		}

		if ( '' !== $dod && self::previse_decimala( $dod ) ) {
			$n['dodatna_vise_decimala']++;
		}

		if ( '' !== $red && self::previse_decimala( $red ) ) {
			$n['redovna_vise_decimala']++;
		}

		// Dodatna veca od redovne NIJE greska — artikl je poskupio. Broji se jer je
		// upravo to razlog zbog kojeg se dodatna cijena ne smije prikazati precrtano
		// (vidi N1): precrtana brojka bi tvrdila da je artikl pojeftinio.
		if ( '' !== $dod && '' !== $red && (float) $dod > (float) $red + Config::TOLERANCIJA_POVIJESTI ) {
			$n['dodatna_veca_od_redovne']++;
		}
	}

	private static function previse_decimala( string $v ): bool {
		$tocka = strpos( $v, '.' );

		if ( false === $tocka ) {
			return false;
		}

		return ( strlen( $v ) - $tocka - 1 ) > Config::CJENIK_MAX_DECIMALA;
	}
}
