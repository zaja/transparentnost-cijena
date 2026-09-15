<?php
/**
 * Preuzimanje uloge prikaza najnize cijene u 30 dana.
 *
 * ZASTO
 *
 * Drugi dodatak vec ispisuje tu izjavu uz cijenu. Kad bismo ispisali svoju, kupac
 * bi vidio dvije tvrdnje o istoj stvari — sto je gore od bilo koje pojedinacno.
 *
 * Njegovu ne mozemo ostaviti jer je mjerenjem utvrdeno da je cesto netocna:
 * vrijednost se racuna samo u trenutku promjene cijene i nikad se ne osvjezava,
 * a upit koji je racuna strukturno izostavlja trenutno vazecu cijenu. Na 133 od
 * 179 stranica prikazivala je iznos koji nije najniza cijena u 30 dana.
 *
 * Zato preuzimamo ulogu: uklanjamo njegov zahvat i ispisujemo svoju vrijednost,
 * racunatu iz vlastite evidencije.
 *
 * KAD SE NE PREUZIMA
 *
 * Ako vlastita evidencija jos nije popunjena, ne diramo nista — tada bismo
 * uklonili tudu tvrdnju a svoju ne bismo imali cime zamijeniti.
 *
 * @package CJTR
 */

namespace CJTR\Prikaz;

use CJTR\Config;
use CJTR\Diagnostika\Tragac;
use CJTR\Postavke;
use CJTR\Povijest\Zapis;

defined( 'ABSPATH' ) || exit;

final class Preuzimanje_Uloge {

	const HOOK = 'woocommerce_get_price_html';

	/** @var array<int,string> uklonjeni zahvati: prioritet => izvor */
	private static $uklonjeno = array();

	/** @var bool */
	private static $provjereno = false;

	public static function init(): void {
		// Kasno, da su svi dodaci vec registrirali svoje zahvate.
		add_action( 'wp', array( __CLASS__, 'preuzmi' ), 9000 );
		add_action( 'wp_loaded', array( __CLASS__, 'preuzmi' ), 9000 );
	}

	public static function preuzmi(): void {
		/*
		 * Uklanjanje tudeg filtera je ODLUKA VLASNIKA TRGOVINE, ne nasa.
		 *
		 * Mjerenje koje je ovo opravdalo i dalje vrijedi: tuda vrijednost bila je
		 * netocna na 133 od 179 stranica. Ali dodatak koji sam od sebe ugasi dio
		 * drugog dodatka je dodatak kojem se ne vjeruje — i kad ima pravo.
		 *
		 * Zato je zadano: sutimo. Otkrije li se drugi Omnibus dodatak, nasa najniza
		 * cijena je iskljucena i njegova ostaje. Ukljuci li je vlasnik u postavkama,
		 * ovo radi tocno ono sto je radilo i dosad.
		 */
		if ( ! Postavke::najniza_30() ) {
			return;
		}

		if ( self::$provjereno || is_admin() ) {
			return;
		}
		self::$provjereno = true;

		// Bez vlastite evidencije nemamo cime zamijeniti tudu tvrdnju.
		if ( Zapis::broj_zapisa() < 1 ) {
			return;
		}

		global $wp_filter;

		if ( empty( $wp_filter[ self::HOOK ] ) ) {
			return;
		}

		foreach ( $wp_filter[ self::HOOK ]->callbacks as $prioritet => $stavke ) {
			if ( (int) $prioritet >= Prikaz::PRIORITET ) {
				continue;
			}

			foreach ( $stavke as $stavka ) {
				$izvor = self::izvor_najnize_cijene( $stavka['function'] );
				if ( null === $izvor ) {
					continue;
				}

				remove_filter( self::HOOK, $stavka['function'], (int) $prioritet );
				self::$uklonjeno[ (int) $prioritet ] = $izvor;
			}
		}

		if ( ! empty( self::$uklonjeno ) ) {
			update_option( Config::option( 'preuzeta_uloga' ), self::$uklonjeno, false );
		}
	}

	/**
	 * Je li zahvat iz dodatka koji prikazuje najnizu cijenu.
	 *
	 * Prepoznaje se po dodatku iz kojeg dolazi, ne po sadrzaju izlaza — sadrzaj
	 * ovisi o jeziku i o postavkama, pa bi provjera po tekstu bila krhka.
	 *
	 * @return string|null ime dodatka, ili null ako nije nas slucaj
	 */
	private static function izvor_najnize_cijene( $funkcija ): ?string {
		$datoteka = Tragac::datoteka_funkcije( $funkcija );
		if ( '' === $datoteka ) {
			return null;
		}

		$izvor = Tragac::datoteka_u_izvor( $datoteka );
		if ( null === $izvor ) {
			return null;
		}

		// Podudaranje po PREFIKSU — isti dodatak zna zivjeti pod vise imena mape.
		foreach ( Config::DODACI_NAJNIZE_CIJENE as $slug ) {
			if ( 0 === strpos( $izvor['slug'], $slug ) ) {
				return $izvor['ime'];
			}
		}
		return null;
	}

	/** @return array<int,string> */
	public static function uklonjeno(): array {
		$o = get_option( Config::option( 'preuzeta_uloga' ) );
		return is_array( $o ) ? $o : array();
	}
}
