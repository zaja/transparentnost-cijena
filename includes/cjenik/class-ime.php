<?php
/**
 * Ime datoteke cjenika.
 *
 * ODLUKA TRAZI PET DIJELOVA
 *
 * Oblik prodajnog objekta, adresu objekta, oznaku objekta, broj pohrane i
 * vremensku oznaku. Ta su polja pisana za FIZICKU prodavaonicu, pa za webshop
 * treba prijevod — svaki je obrazlozen u ANALIZA.md, sekcija V.
 *
 * VREMENSKA OZNAKA IDE KROZ wp_date()
 *
 * Ne kroz `date()`. Na produkciji je PHP na UTC, a trgovina na Europe/Zagreb —
 * izmjereno, razlika je DVA sata ljeti, ne nula. Datoteka generirana u 23:10 po
 * UTC-u nastaje u 01:10 SLJEDECEG DANA po vremenu trgovine, pa bi `date()` dao
 * ime s krivim datumom. Cjenik koji u imenu nosi jucerasnji datum nije arhiva
 * nego greska.
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Ime {

	/**
	 * Ime datoteke za zadani trenutak.
	 *
	 * @param int|null $kada Unix vrijeme; null = sada.
	 */
	public static function datoteka( string $oblik, int $broj_pohrane, ?int $kada = null ): string {
		$kada = $kada ?? time();

		return implode(
			'_',
			array(
				self::ocisti( Config::OBJEKT_OBLIK ),
				self::ocisti( Config::objekt_adresa() ),
				self::ocisti( Config::OBJEKT_OZNAKA ),
				(string) $broj_pohrane,
				wp_date( 'Ymd_Hi', $kada ),
			)
		) . '.' . $oblik;
	}

	/**
	 * Ime datoteke koja uvijek nosi danasnji cjenik.
	 *
	 * Postoji jer poveznica mora biti STABILNA — netko je jednom zapise i ocekuje
	 * da i sutra vodi na tekuci cjenik. Datirane datoteke ostaju uz nju, za arhivu.
	 *
	 * Simbolicka poveznica se NE koristi: na dijelu dijeljenog hostinga ne radi, a
	 * kad ne radi, ne javi gresku nego tiho posluzi nista.
	 */
	public static function stabilna( string $oblik ): string {
		return 'cjenik-danas.' . $oblik;
	}

	/** Datum iz imena datirane datoteke, ili prazno. */
	public static function datum_iz_imena( string $ime ): string {
		if ( preg_match( '/_(\d{8})_\d{4}\./', $ime, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Znakovi koje ime datoteke smije nositi.
	 *
	 * Tocke iz domene postaju crtice: `poker.normalno.org` u imenu datoteke ima tri
	 * tocke, a citatelj (i dio alata) zadnju procita kao pocetak nastavka.
	 */
	private static function ocisti( string $dio ): string {
		$dio = strtolower( $dio );
		$dio = preg_replace( '/[^a-z0-9]+/', '-', $dio );
		return trim( $dio, '-' );
	}

	/** Sljedeci broj pohrane. Raste sa svakom objavljenom datotekom. */
	public static function sljedeci_broj(): int {
		$broj = (int) get_option( Config::option( Config::OPT_BROJ_POHRANE ), 0 ) + 1;
		update_option( Config::option( Config::OPT_BROJ_POHRANE ), $broj, false );
		return $broj;
	}

	public static function trenutni_broj(): int {
		return (int) get_option( Config::option( Config::OPT_BROJ_POHRANE ), 0 );
	}
}
