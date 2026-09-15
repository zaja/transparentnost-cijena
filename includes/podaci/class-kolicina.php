<?php
/**
 * Neto kolicina: prepoznavanje, pretvorba u kanonsku jedinicu.
 *
 * ZASTO JE OVDJE NAJVISE OPREZA
 *
 * Greska u pretvorbi jedinica ne izgleda kao greska. `250 g` upisano kao `250 kg`
 * daje cijenu po jedinici tisucu puta manju, a broj i dalje izgleda kao broj. U
 * propisanoj objavi to je netocan podatak koji nitko ne primijeti dok ga netko ne
 * usporedi. Zato faktori stoje u Configu na jednom mjestu, a ova klasa ima testove.
 *
 * MULTIPACK
 *
 * "6x0,5 l" je TRI litre, ne pola litre. To je najcesci izvor krive vrijednosti
 * kod parsiranja iz naziva, pa se obrada mnozitelja radi prije svega ostalog.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Kolicina {

	/**
	 * Pretvori kolicinu u kanonsku jedinicu njezine vrste.
	 *
	 * @return array{kolicina:float,jedinica:string}|null null ako jedinica nije poznata
	 */
	public static function u_kanonsku( float $kolicina, string $jedinica ): ?array {
		$jedinica = self::normaliziraj_jedinicu( $jedinica );

		if ( ! isset( Config::JEDINICE[ $jedinica ] ) ) {
			return null;
		}

		$def      = Config::JEDINICE[ $jedinica ];
		$kanonska = Config::KANONSKE_JEDINICE[ $def['vrsta'] ];

		return array(
			'kolicina' => $kolicina * (float) $def['faktor'],
			'jedinica' => $kanonska,
		);
	}

	/**
	 * Svedi zapis jedinice na kljuc iz Configa.
	 *
	 * Prihvaca ono sto ljudi stvarno pisu: velika slova, tocke, "kom.", "m2", "m²",
	 * "lit", "gr". Nepoznato vraca kao ocisceni string, da pozivatelj moze reci sto
	 * nije prepoznao umjesto da tiho vrati nulu.
	 */
	public static function normaliziraj_jedinicu( string $jedinica ): string {
		$j = mb_strtolower( trim( $jedinica ) );
		$j = str_replace( array( '.', ' ', '²', '³' ), array( '', '', '2', '3' ), $j );

		$zamjene = array(
			'gr'      => 'g',
			'grama'   => 'g',
			'gram'    => 'g',
			'kom'     => 'kom',
			'komad'   => 'kom',
			'komada'  => 'kom',
			'kos'     => 'kom',
			'pcs'     => 'kom',
			'lit'     => 'l',
			'litra'   => 'l',
			'litre'   => 'l',
			'lit r'   => 'l',
			'mililitar' => 'ml',
			'kilogram'  => 'kg',
			'metar'   => 'm',
			'm2'      => 'm2',
			'cm2'     => 'cm2',
		);

		return $zamjene[ $j ] ?? $j;
	}

	/**
	 * Rastavi zapis kolicine iz slobodnog teksta.
	 *
	 * Prepoznaje "500 g", "0,75 l", "6x0.5 l", "2 x 250ml", "60cm*40cm".
	 *
	 * Kod dvije dimenzije s istom jedinicom duljine ("60cm*40cm") rezultat je
	 * POVRSINA, ne duljina — to su dimenzije predmeta, a ne dva pakiranja. Zvijezda
	 * i "x" izmedu dva broja S JEDINICAMA znaci dimenzije; "x" ispred jednog broja
	 * s jedinicom znaci multipack.
	 *
	 * @return array{kolicina:float,jedinica:string,mnozitelj:int,vrsta:string}|null
	 */
	public static function razlozi( string $tekst ): ?array {
		$tekst = str_replace( ',', '.', $tekst );

		// 1. Dimenzije: broj+jedinica (x|*) broj+jedinica, ISTE vrste duljine.
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*([a-zA-Z]+)\s*[x×*]\s*(\d+(?:\.\d+)?)\s*([a-zA-Z]+)/u', $tekst, $m ) ) {
			$a = self::u_kanonsku( (float) $m[1], $m[2] );
			$b = self::u_kanonsku( (float) $m[3], $m[4] );

			if ( $a && $b && 'm' === $a['jedinica'] && 'm' === $b['jedinica'] ) {
				return array(
					'kolicina'  => $a['kolicina'] * $b['kolicina'],
					'jedinica'  => 'm2',
					'mnozitelj' => 1,
					'vrsta'     => Config::VRSTA_POVRSINA,
				);
			}
		}

		// 2. Multipack: broj (x|×) broj+jedinica. Mnozitelj mora biti CIJELI broj —
		//    "0,5x2 l" nije pakiranje nego zapis koji ne razumijemo.
		$mnozitelj = 1;
		if ( preg_match( '/(?<!\d)(\d{1,3})\s*[x×]\s*(\d+(?:\.\d+)?)\s*([a-zA-Z]+)/u', $tekst, $m ) ) {
			$mnozitelj = (int) $m[1];
			$broj      = (float) $m[2];
			$jedinica  = $m[3];
		} elseif ( preg_match( '/(\d+(?:\.\d+)?)\s*([a-zA-Z]+)/u', $tekst, $m ) ) {
			$broj     = (float) $m[1];
			$jedinica = $m[2];
		} else {
			return null;
		}

		$kanonska = self::u_kanonsku( $broj, $jedinica );
		if ( null === $kanonska ) {
			return null;
		}

		$def = Config::JEDINICE[ self::normaliziraj_jedinicu( $jedinica ) ];

		return array(
			'kolicina'  => $kanonska['kolicina'] * $mnozitelj,
			'jedinica'  => $kanonska['jedinica'],
			'mnozitelj' => $mnozitelj,
			'vrsta'     => $def['vrsta'],
		);
	}

	/** Vrsta kojoj jedinica pripada, ili prazno. */
	public static function vrsta( string $jedinica ): string {
		$j = self::normaliziraj_jedinicu( $jedinica );
		return Config::JEDINICE[ $j ]['vrsta'] ?? '';
	}

	/** Kako se jedinica pise u cjeniku i na ekranu. */
	public static function oznaka( string $jedinica ): string {
		$j = self::normaliziraj_jedinicu( $jedinica );
		return Config::OZNAKE_JEDINICA[ $j ] ?? $j;
	}

	/** Je li jedinica poznata. */
	public static function poznata( string $jedinica ): bool {
		return isset( Config::JEDINICE[ self::normaliziraj_jedinicu( $jedinica ) ] );
	}
}
