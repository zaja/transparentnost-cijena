<?php
/**
 * 8. Stanje povijesti cijena.
 *
 * Sidrena cijena je tvrdnja o proslosti. Da bi bila opazanje a ne procjena, mora
 * postojati zapis koji pokriva referentni datum. Ovdje se mjeri postoji li takav
 * zapis i koliko vrijedi — bez slanja izvoda baze bilo kome.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Katalog;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Povijest extends Provjera {

	/** Tablice povijesti cijena koje znamo procitati. Sve su tude — samo citamo. */
	private const TABLICE = array(
		'price_history' => 'WooCommerce Lowest Price',
	);

	public function kljuc(): string {
		return 'povijest';
	}

	public function izvrsi(): Rezultat {
		global $wpdb;

		$r = new Rezultat( __( '8. Povijest cijena', Config::TEXT_DOMAIN ) );

		$tablica = $this->nadi_tablicu();

		if ( ! $tablica ) {
			$r->status( Rezultat::LOSE );
			$r->stavka( __( 'Tablica povijesti cijena', Config::TEXT_DOMAIN ), __( 'ne postoji', Config::TEXT_DOMAIN ) );
			$r->znacenje( __( 'U trgovini ne postoji zapis o tome kakve su cijene bile u proslosti. Bez njega se sidrena cijena za referentni datum ne moze utvrditi iz podataka, nego se mora unijeti rucno za svaki artikl.', Config::TEXT_DOMAIN ) );
			$r->postupak( __( 'Ako je dodatak koji vodi povijest cijena ranije bio aktivan pa uklonjen, njegova tablica mozda jos postoji u bazi — vrijedi provjeriti prije nego se krene s rucnim unosom.', Config::TEXT_DOMAIN ) );
			return $r;
		}

		$puno = $wpdb->prefix . $tablica;
		$r->stavka( __( 'Tablica', Config::TEXT_DOMAIN ), $puno );
		$r->stavka( __( 'Vodi je', Config::TEXT_DOMAIN ), self::TABLICE[ $tablica ] );

		$redaka    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$puno}`" ); // phpcs:ignore
		$proizvoda = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM `{$puno}`" ); // phpcs:ignore

		$r->stavka( __( 'Zapisa ukupno', Config::TEXT_DOMAIN ), number_format_i18n( $redaka ) );
		$r->stavka( __( 'Artikala u povijesti', Config::TEXT_DOMAIN ), number_format_i18n( $proizvoda ) );

		if ( 0 === $redaka ) {
			$r->status( Rezultat::LOSE );
			$r->znacenje( __( 'Tablica postoji ali je prazna. Povijest cijena se ne vodi, pa se sidrena cijena mora unijeti rucno.', Config::TEXT_DOMAIN ) );
			return $r;
		}

		$this->najnoviji( $r, $puno );
		$this->pokrivenost( $r, $puno );
		$this->obrisani( $r, $puno, $proizvoda );
		$this->struktura( $r, $puno );

		return $r;
	}

	/* ------------------------------------------------------------------ */

	private function nadi_tablicu(): ?string {
		global $wpdb;

		foreach ( array_keys( self::TABLICE ) as $ime ) {
			$puno = $wpdb->prefix . $ime;
			if ( $puno === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $puno ) ) ) {
				return $ime;
			}
		}
		return null;
	}

	private function najnoviji( Rezultat $r, string $puno ): void {
		global $wpdb;

		$ts = (int) $wpdb->get_var( "SELECT MAX(timestamp) FROM `{$puno}`" ); // phpcs:ignore

		if ( $ts <= 0 ) {
			$r->stavka( __( 'Najnoviji zapis', Config::TEXT_DOMAIN ), __( 'nema datiranog zapisa', Config::TEXT_DOMAIN ) );
			$r->pogorsaj( Rezultat::UPOZ );
			return;
		}

		$dana = (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );

		$r->stavka(
			__( 'Najnoviji zapis', Config::TEXT_DOMAIN ),
			sprintf(
				/* translators: 1: datum i vrijeme, 2: broj dana */
				__( '%1$s (prije %2$d dana)', Config::TEXT_DOMAIN ),
				wp_date( 'd.m.Y. H:i', $ts ),
				$dana
			)
		);

		// Ako se povijest ne dopunjava, dodatak koji je vodi vjerojatno vise ne radi.
		if ( $dana > 30 ) {
			$r->pogorsaj( Rezultat::UPOZ );
			$r->postupak( __( 'Povijest cijena se dulje vrijeme ne dopunjava. Provjerite radi li jos dodatak koji ju vodi — bez njega se nove promjene cijena ne biljeze.', Config::TEXT_DOMAIN ) );
		}
	}

	private function pokrivenost( Rezultat $r, string $puno ): void {
		global $wpdb;

		$datum = Config::REF_DATUM_OSTALO;
		$t     = Config::t_ref( $datum );

		$r->stavka(
			__( 'Referentni datum', Config::TEXT_DOMAIN ),
			sprintf(
				/* translators: 1: datum, 2: vrijeme */
				__( '%1$s, stanje na kraju dana (%2$s)', Config::TEXT_DOMAIN ),
				wp_date( 'd.m.Y.', $t ),
				wp_date( 'H:i', $t )
			)
		);

		// VAZNO: broji se OD KATALOGA prema povijesti, nikad obrnuto.
		// Povijest sadrzi i obrisane artikle, pa bi brojanje iz nje dalo broj veci
		// od kataloga i lazni dojam potpune pokrivenosti.
		$mjera = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   COUNT(*) AS zivih,
				   SUM( EXISTS (
				     SELECT 1 FROM `{$puno}` h
				     WHERE h.product_id = p.ID
				       AND h.timestamp <= %d
				       AND ( h.timestamp_end > %d OR h.timestamp_end = 0 ) ) ) AS ima_zapis,
				   SUM( EXISTS (
				     SELECT 1 FROM `{$puno}` h2
				     WHERE h2.product_id = p.ID
				       AND h2.timestamp = 0
				       AND ( h2.timestamp_end > %d OR h2.timestamp_end = 0 ) ) ) AS bez_pocetka
				 FROM {$wpdb->posts} p
				 WHERE " . Katalog::uvjet(),
				$t,
				$t,
				$t
			)
		); // phpcs:ignore

		$zivih   = (int) $mjera->zivih;
		$pokriva = (int) $mjera->ima_zapis;
		$seed    = (int) $mjera->bez_pocetka;

		$r->stavka( __( 'Artikala u trgovini sada', Config::TEXT_DOMAIN ), number_format_i18n( $zivih ) );
		$r->stavka(
			__( 'Ima zapis za referentni datum', Config::TEXT_DOMAIN ),
			sprintf(
				/* translators: 1: broj, 2: postotak */
				__( '%1$s (%2$s %%)', Config::TEXT_DOMAIN ),
				number_format_i18n( $pokriva ),
				number_format_i18n( $zivih > 0 ? round( 100 * $pokriva / $zivih, 1 ) : 0, 1 )
			)
		);

		$nedostaje = max( 0, $zivih - $pokriva );
		if ( $nedostaje > 0 ) {
			$r->stavka(
				__( 'Nema zapisa — treba rucni unos', Config::TEXT_DOMAIN ),
				number_format_i18n( $nedostaje )
			);
		}

		$r->stavka(
			__( 'Od toga bez poznatog pocetka', Config::TEXT_DOMAIN ),
			sprintf(
				/* translators: %s = broj */
				__( '%s — cijena je poznata, ali ne i otkad vrijedi', Config::TEXT_DOMAIN ),
				number_format_i18n( $seed )
			)
		);

		if ( $zivih > 0 && $pokriva >= $zivih * 0.95 ) {
			$r->pogorsaj( Rezultat::OK );
			$r->znacenje( __( 'Za gotovo sve artikle postoji zapis o cijeni na referentni datum. Sidrena cijena se moze utvrditi iz podataka, a ne procjenom.', Config::TEXT_DOMAIN ) );
		} else {
			$r->pogorsaj( Rezultat::UPOZ );
			$r->znacenje( __( 'Za dio artikala ne postoji zapis o cijeni na referentni datum. Za njih ce sidrenu cijenu trebati unijeti rucno.', Config::TEXT_DOMAIN ) );
		}
	}

	/**
	 * Artikli kojih u povijesti ima, a u trgovini vise ne postoje.
	 *
	 * Vazno za modul koji gradi snapshot: mora ici OD KATALOGA prema povijesti.
	 * Obrnutim smjerom bi u cjenik usli artikli kojih nema u prodaji.
	 */
	private function obrisani( Rezultat $r, string $puno, int $u_povijesti ): void {
		global $wpdb;

		$postojeci = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT h.product_id)
			 FROM `{$puno}` h
			 JOIN {$wpdb->posts} p ON p.ID = h.product_id"
		); // phpcs:ignore

		$obrisanih = max( 0, $u_povijesti - $postojeci );

		$r->stavka(
			__( 'Obrisani artikli u povijesti', Config::TEXT_DOMAIN ),
			sprintf(
				/* translators: %s = broj */
				__( '%s — postoje u zapisu, u trgovini ih vise nema', Config::TEXT_DOMAIN ),
				number_format_i18n( $obrisanih )
			)
		);

		if ( $obrisanih > 0 ) {
			$r->postupak(
				trim(
					$r->postupak . ' ' . __( 'Povijest sadrzi i artikle kojih u trgovini vise nema. Cjenik se gradi od popisa artikala prema povijesti, nikad obrnuto, pa oni u njega nece uci.', Config::TEXT_DOMAIN )
				)
			);
		}
	}

	/**
	 * Struktura tablice — dvije stvari koje mijenjaju nacin rada s njom.
	 */
	private function struktura( Rezultat $r, string $puno ): void {
		global $wpdb;

		$stupci = (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$puno}`" ); // phpcs:ignore
		$tip    = '';
		foreach ( $stupci as $s ) {
			if ( 'price' === $s->Field ) { // phpcs:ignore
				$tip = strtolower( (string) $s->Type ); // phpcs:ignore
			}
		}

		$engine = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$puno
			)
		); // phpcs:ignore

		$r->stavka( __( 'Zapis cijene', Config::TEXT_DOMAIN ), $tip ?: __( 'nepoznato', Config::TEXT_DOMAIN ) );
		$r->stavka( __( 'Nacin pohrane', Config::TEXT_DOMAIN ), $engine ?: __( 'nepoznato', Config::TEXT_DOMAIN ) );

		if ( false !== strpos( $tip, 'double' ) || false !== strpos( $tip, 'float' ) ) {
			$r->stavka(
				__( '   posljedica', Config::TEXT_DOMAIN ),
				sprintf(
					/* translators: %s = tolerancija */
					__( 'cijene se usporeduju brojcano, s tolerancijom %s', Config::TEXT_DOMAIN ),
					Config::TOLERANCIJA_POVIJESTI
				)
			);
		}

		if ( 'MyISAM' === $engine ) {
			$r->stavka(
				__( '   napomena', Config::TEXT_DOMAIN ),
				__( 'tablica ne podrzava transakcije; u nju se ne pise, samo se cita', Config::TEXT_DOMAIN )
			);
		}
	}

	private function zivih_entiteta(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . Katalog::uvjet()
		); // phpcs:ignore
	}
}
