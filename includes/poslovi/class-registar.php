<?php
/**
 * Registar poslova.
 *
 * Moduli koji dolaze prijavljuju svoje poslove kroz filter, pa okvir ne mora
 * znati nista o njima.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Registar {

	/** @var Posao[]|null */
	private static $poslovi = null;

	/** @return Posao[] kljuc => posao */
	public static function svi(): array {
		if ( null !== self::$poslovi ) {
			return self::$poslovi;
		}

		$popis = apply_filters( Config::hook( 'poslovi' ), array( new Poslovi\Prebroji(), new Poslovi\Istekle_Akcije(), new Poslovi\Vrati_Akcije(), new Poslovi\Sidrena_Cijena(), new Poslovi\Sidrena_Novi_Artikli(), new Poslovi\Oscilacija_Rijesena(), new Poslovi\Uvoz_Povijesti(), new Poslovi\Rekonsilijacija(), new Poslovi\Promocija(), new Poslovi\Sidrena_Nakon_Promocije(), new Poslovi\Vrati_Promociju(), new Poslovi\Vrati_Sidrenu_Nakon_Promocije(), new Poslovi\Prikupi_Podatke(), new Poslovi\Oznaci_Neprimjenjivo(), new Poslovi\Marka_Iz_Naziva(), new Poslovi\Kolicina_Kom(), new Poslovi\Generiraj_Cjenik(), new Poslovi\Zaokruzi_Cijene(), new Poslovi\Vrati_Zaokruzivanje(), new Poslovi\Izjava_Trgovca() ) );

		self::$poslovi = array();
		foreach ( $popis as $posao ) {
			if ( $posao instanceof Posao ) {
				self::$poslovi[ $posao->kljuc() ] = $posao;
			}
		}

		return self::$poslovi;
	}

	public static function nadi( string $kljuc ): ?Posao {
		$svi = self::svi();
		return $svi[ $kljuc ] ?? null;
	}
}
