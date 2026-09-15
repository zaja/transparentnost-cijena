<?php
/**
 * Barkod: provjera, normalizacija, prepoznavanje — nikad izmisljanje.
 *
 * BARKOD SE NE GENERIRA
 *
 * Izmisljen GTIN nije prazno polje s brojkom umjesto praznine. On je broj koji je
 * vrlo vjerojatno VEC DODIJELJEN nekoj drugoj firmi, jer se GTIN-ovi dodjeljuju iz
 * globalnog registra. U javnoj datoteci koja se objavljuje po propisu takav je
 * podatak gori od praznog polja: prazno polje kaze "ne znamo", izmisljen broj tvrdi
 * nesto neistinito o tudem proizvodu.
 *
 * Ova klasa zato samo CITA i PROVJERAVA. Nema metode koja stvara barkod.
 *
 * STO SE SMIJE
 *
 * Naci postojeci niz znamenki u nazivu, opisu ili meti i provjeriti mu kontrolnu
 * znamenku. Ako prolazi, vrlo je vjerojatno rijec o pravom barkodu — slucajan niz
 * od 13 znamenki prolazi kontrolu u jednom od deset slucajeva.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Gtin {

	/**
	 * Ocisti zapis: makni sve osim znamenki.
	 *
	 * Barkodovi iz tablica dobavljaca dolaze s razmacima, crticama i apostrofima.
	 * Vodece nule se NE smiju izgubiti, pa se radi sa stringom, nikad s brojem.
	 */
	public static function ocisti( $vrijednost ): string {
		return preg_replace( '/\D+/', '', (string) $vrijednost );
	}

	/**
	 * Kontrolna znamenka po GS1 pravilu (mod 10).
	 *
	 * Racuna se ZDESNA: znamenke se od zadnje prema prvoj mnoze naizmjence s 3 i 1.
	 * Racunanje slijeva radi za EAN-13 i ITF-14, ali daje krivi rezultat za EAN-8 i
	 * UPC-A, jer im je broj znamenki paran odnosno neparan drugacije. Zdesna vrijedi
	 * za sve cetiri duljine — zato je tako i napisano.
	 *
	 * @param string $bez_kontrolne Znamenke BEZ zadnje.
	 */
	public static function kontrolna_znamenka( string $bez_kontrolne ): int {
		$zbroj = 0;
		$tezina = 3;

		for ( $i = strlen( $bez_kontrolne ) - 1; $i >= 0; $i-- ) {
			$zbroj += (int) $bez_kontrolne[ $i ] * $tezina;
			$tezina = ( 3 === $tezina ) ? 1 : 3;
		}

		return ( 10 - ( $zbroj % 10 ) ) % 10;
	}

	/** Prolazi li zapis provjeru kontrolne znamenke. */
	public static function valjan( string $gtin ): bool {
		$gtin = self::ocisti( $gtin );

		if ( ! in_array( strlen( $gtin ), Config::GTIN_DULJINE, true ) ) {
			return false;
		}

		$tijelo    = substr( $gtin, 0, -1 );
		$kontrolna = (int) substr( $gtin, -1 );

		return self::kontrolna_znamenka( $tijelo ) === $kontrolna;
	}

	/**
	 * UPC-A (12 znamenki) u EAN-13, vodecom nulom.
	 *
	 * Kontrolna znamenka se NE mijenja: vodeca nula ne utjece na nju jer zdesna
	 * pada na tezinu koja je mnozi s nulom. To je i razlog zasto je pretvorba
	 * bezopasna i zasto se smije raditi bez ponovnog racunanja.
	 *
	 * Ostale duljine se vracaju nepromijenjene.
	 */
	public static function u_ean13( string $gtin ): string {
		$gtin = self::ocisti( $gtin );
		return ( 12 === strlen( $gtin ) ) ? '0' . $gtin : $gtin;
	}

	/**
	 * Smije li zapis s ovim statusom u objavljeni cjenik.
	 *
	 * Jedno mjesto na kojem se odlucuje, pa generator, kartica i nalaz ne mogu doci
	 * do razlicitog odgovora.
	 */
	public static function smije_u_cjenik( string $status ): bool {
		return in_array( $status, Config::GTIN_U_CJENIK, true );
	}

	/**
	 * Sto je ovaj zapis.
	 *
	 * Vraca jedan od Config::GTIN_* ishoda. Duplikat se ovdje NE moze utvrditi —
	 * za to treba cijeli katalog, pa to radi Barkod_Pregled.
	 */
	public static function status( string $gtin ): string {
		$sirovo = (string) $gtin;
		$gtin   = self::ocisti( $gtin );

		/*
		 * Prvo pitanje nije "je li tocan" nego "moze li uopce biti barkod".
		 *
		 * Pogresna duljina, znakovi koji nisu znamenke i sve nule nisu sporne
		 * vrijednosti nego strukturno nemoguce. Razlika je bitna jer odlucuje hoce
		 * li vrijednost izaci u propisanu objavu.
		 */
		if ( '' === $gtin || $gtin !== trim( $sirovo ) ) {
			return Config::GTIN_NEMOGUC;
		}

		if ( ! in_array( strlen( $gtin ), Config::GTIN_DULJINE, true ) ) {
			return Config::GTIN_NEMOGUC;
		}

		if ( 0 === (int) $gtin ) {
			return Config::GTIN_NEMOGUC;
		}

		if ( ! self::valjan( $gtin ) ) {
			return Config::GTIN_NEISPRAVAN;
		}

		// ITF-14 je kod TRANSPORTNOG pakiranja (kutija, paleta), ne prodajne
		// jedinice. Ispravan je, ali opisuje drugu stvar — objavljen uz cijenu
		// jednog komada tvrdio bi da se prodaje cijela kutija.
		if ( 14 === strlen( $gtin ) ) {
			return Config::GTIN_TRANSPORTNI;
		}

		$ean = self::u_ean13( $gtin );

		if ( 13 === strlen( $ean ) && in_array( substr( $ean, 0, 2 ), Config::GTIN_INTERNI_PREFIKSI, true ) ) {
			return Config::GTIN_INTERNI;
		}

		return Config::GTIN_VALJAN;
	}

	/** Smije li zapis s ovim statusom ici u javni cjenik. */
	public static function za_objavu( string $status ): bool {
		return Config::GTIN_VALJAN === $status;
	}

	/**
	 * Nadi kandidate za barkod u slobodnom tekstu.
	 *
	 * Traze se nizovi tocno onih duljina koje GTIN moze imati, i svaki se provjerava
	 * kontrolnom znamenkom. Niz koji ne prolazi se odbacuje — bez te provjere ovo bi
	 * hvatalo datume, sifre i brojeve telefona.
	 *
	 * Granica `(?<!\d)` i `(?!\d)` stoji zato da se iz niza od 20 znamenki ne izvuce
	 * bilo kojih 13 uzastopnih.
	 *
	 * @return string[] jedinstveni kandidati, normalizirani na EAN-13
	 */
	public static function kandidati( string $tekst ): array {
		if ( '' === trim( $tekst ) ) {
			return array();
		}

		$duljine = Config::GTIN_DULJINE;
		rsort( $duljine );

		$nadeni = array();

		foreach ( $duljine as $duljina ) {
			if ( ! preg_match_all( '/(?<!\d)(\d{' . (int) $duljina . '})(?!\d)/', $tekst, $m ) ) {
				continue;
			}

			foreach ( $m[1] as $kandidat ) {
				if ( ! self::valjan( $kandidat ) ) {
					continue;
				}
				$ean            = self::u_ean13( $kandidat );
				$nadeni[ $ean ] = true;
			}
		}

		/*
		 * Natrag u stringove. PHP numericki string kao kljuc polja tiho pretvara u
		 * cijeli broj, pa bi `array_keys()` vratio mjesavinu: "4006381333931" kao
		 * int, a "0036000291452" (vodeca nula nije kanonski broj) kao string.
		 * Barkod se od pocetka do kraja vodi kao string — cim postane broj, prva
		 * vodeca nula je izgubljena.
		 */
		return array_map( 'strval', array_keys( $nadeni ) );
	}

	/** Objasnjenje statusa, jezikom netehnicke osobe. */
	public static function objasnjenje( string $status ): string {
		$tekstovi = array(
			Config::GTIN_VALJAN      => __( 'Ispravan barkod prodajne jedinice.', Config::TEXT_DOMAIN ),
			Config::GTIN_NEISPRAVAN  => __( 'Duljina je ispravna, ali ne prolazi provjeru kontrolne znamenke. Moguce je da je broj pravi a jedna znamenka krivo prepisana, pa se objavljuje — provjerite ga.', Config::TEXT_DOMAIN ),
			Config::GTIN_NEMOGUC     => __( 'Ovo ne moze biti barkod: nijedan GTIN nema tu duljinu ili te znakove. Ne objavljuje se, jer bi objava tvrdila nesto neistinito.', Config::TEXT_DOMAIN ),
			Config::GTIN_INTERNI     => __( 'Interni kod koji trgovac dodjeljuje sam. Ispravan je, ali nije globalno jedinstven, pa u javnoj datoteci tvrdi nesto sto ne moze.', Config::TEXT_DOMAIN ),
			Config::GTIN_TRANSPORTNI => __( 'Kod transportnog pakiranja (kutije), ne pojedinacnog artikla. Uz cijenu jednog komada bio bi netocan.', Config::TEXT_DOMAIN ),
			Config::GTIN_DUPLIKAT    => __( 'Isti barkod stoji na vise artikala. Barkod je jedinstven po proizvodu, pa je ovo gotovo uvijek greska unosa.', Config::TEXT_DOMAIN ),
		);

		return $tekstovi[ $status ] ?? $status;
	}
}
