<?php
/**
 * Marke: prepoznavanje iz naziva i svodenje razlicitih pisanja na jedno.
 *
 * RJECNIK JE POMOC, NE ODLUKA
 *
 * Sve sto izade iz rjecnika ide na reviziju. Razlog je mjerljiv: naziv
 * `Bicycle karte "GREEN REVERSED BACK" by MagicMakers` sadrzi dva imena, a samo je
 * jedno proizvodac spila — drugo je izdavac dizajna. Stroj tu razliku ne vidi.
 *
 * Izmjereno na ovom katalogu: rjecnik pokriva 363 od 691 proizvoda (52,5 %).
 * Preostalih 328 traze rucni unos ili oznaku "vlastita marka".
 *
 * NORMALIZACIJA
 *
 * Isti brend se u katalogu pise na vise nacina — izmjereno: `Copag` 19 puta,
 * `COPAG` 14 puta. Bez svodenja na jedan oblik cjenik bi tvrdio da su to dvije
 * razlicite marke, a filtri i usporedbe bi se raspali.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Marke {

	/** @var array<string,string>|null mala slova varijante => kanonski oblik */
	private static $karta = null;

	/**
	 * Svedi zapis marke na kanonski oblik iz rjecnika.
	 *
	 * Nepoznata marka se vraca ocisena, ali NEPROMIJENJENA — trgovac smije imati
	 * marku koje u rjecniku nema, i nju ne smijemo preimenovati.
	 */
	public static function kanonski( string $marka ): string {
		$cist = trim( preg_replace( '/\s+/u', ' ', $marka ) );

		if ( '' === $cist ) {
			return '';
		}

		$karta = self::karta();
		$kljuc = mb_strtolower( $cist );

		return $karta[ $kljuc ] ?? $cist;
	}

	/**
	 * Nadi marku u nazivu artikla.
	 *
	 * Trazi se cijela rijec, ne podniz: bez granice bi `KEM` pogadao unutar rijeci
	 * `kemijska`. Kod visecjlanih imena granica se trazi samo na krajevima.
	 *
	 * Kad naziv sadrzi vise marki, uzima se ona koja se pojavljuje PRVA — u ovom
	 * katalogu proizvodac stoji na pocetku, a izdavac dizajna iza. To je heuristika
	 * i zato ishod ide na reviziju.
	 *
	 * @return string|null kanonski oblik, ili null ako nema pogotka
	 */
	public static function iz_naziva( string $naziv ): ?string {
		if ( '' === trim( $naziv ) ) {
			return null;
		}

		$najranija = null;
		$pozicija  = PHP_INT_MAX;

		foreach ( Config::MARKE_RJECNIK as $kanonski => $oblici ) {
			foreach ( $oblici as $oblik ) {
				if ( ! preg_match( '/(?<![\p{L}\d])' . preg_quote( $oblik, '/' ) . '(?![\p{L}\d])/ui', $naziv, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				$p = (int) $m[0][1];
				if ( $p < $pozicija ) {
					$pozicija  = $p;
					$najranija = $kanonski;
				}
			}
		}

		return $najranija;
	}

	/**
	 * Nosi li naziv vise od jedne marke.
	 *
	 * Takav artikl je najvjerojatniji kandidat za pogresku, pa se u izvjestaju
	 * izdvaja: `Bicycle ... by MagicMakers` ima proizvodaca i izdavaca, a u cjenik
	 * ide samo jedan.
	 *
	 * @return string[] kanonski oblici, redom pojavljivanja
	 */
	public static function sve_iz_naziva( string $naziv ): array {
		$nadene = array();

		foreach ( Config::MARKE_RJECNIK as $kanonski => $oblici ) {
			foreach ( $oblici as $oblik ) {
				if ( preg_match( '/(?<![\p{L}\d])' . preg_quote( $oblik, '/' ) . '(?![\p{L}\d])/ui', $naziv, $m, PREG_OFFSET_CAPTURE ) ) {
					$p = (int) $m[0][1];
					if ( ! isset( $nadene[ $kanonski ] ) || $p < $nadene[ $kanonski ] ) {
						$nadene[ $kanonski ] = $p;
					}
				}
			}
		}

		asort( $nadene );
		return array_keys( $nadene );
	}

	/** Postoji li marka u rjecniku. */
	public static function poznata( string $marka ): bool {
		return isset( self::karta()[ mb_strtolower( trim( $marka ) ) ] );
	}

	/** Kanonska imena iz rjecnika, za padajuci izbornik. */
	public static function popis(): array {
		return array_keys( Config::MARKE_RJECNIK );
	}

	/** @return array<string,string> */
	private static function karta(): array {
		if ( null !== self::$karta ) {
			return self::$karta;
		}

		self::$karta = array();

		foreach ( Config::MARKE_RJECNIK as $kanonski => $oblici ) {
			self::$karta[ mb_strtolower( $kanonski ) ] = $kanonski;
			foreach ( $oblici as $oblik ) {
				self::$karta[ mb_strtolower( $oblik ) ] = $kanonski;
			}
		}

		return self::$karta;
	}
}
