<?php
/**
 * Provjera datoteke PRIJE nego zamijeni jucerasnju.
 *
 * ZASTO POSTOJI
 *
 * WordPress i WooCommerce se na produkciji azuriraju automatski, i to preko vecih
 * verzija. Generator ovisi o API-ju koji se moze promijeniti bez najave, a cjenik
 * se objavljuje svaki dan u 8:00 bez da ga itko gleda. Bez ove provjere prvi znak
 * da je nesto puklo bio bi poziv inspekcije.
 *
 * PRAVILO: JUCERASNJI CJENIK JE BOLJI OD POKVARENOG
 *
 * Ako provjera padne, nova datoteka se odbacuje, stara ostaje na svom mjestu, a
 * kvar se prijavljuje. Objavljena datoteka s praznim cijenama gora je od datoteke
 * koja je jedan dan stara — prva tvrdi nesto neistinito, druga samo kasni.
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Samoprovjera {

	/**
	 * Provjeri napisanu datoteku.
	 *
	 * @param string $putanja      Privremena datoteka.
	 * @param string $oblik        xml ili csv.
	 * @param int    $ocekivano    Koliko zapisa mora biti unutra.
	 * @return array{ok:bool,greske:string[],zapisa:int}
	 */
	public static function provjeri( string $putanja, string $oblik, int $ocekivano ): array {
		$greske = array();

		if ( ! file_exists( $putanja ) || ! is_readable( $putanja ) ) {
			return array(
				'ok'     => false,
				'greske' => array( __( 'Datoteka nije nastala ili se ne moze procitati.', Config::TEXT_DOMAIN ) ),
				'zapisa' => 0,
			);
		}

		if ( filesize( $putanja ) < 32 ) {
			return array(
				'ok'     => false,
				'greske' => array( __( 'Datoteka je prazna.', Config::TEXT_DOMAIN ) ),
				'zapisa' => 0,
			);
		}

		$nalaz = ( Config::CJENIK_XML === $oblik )
			? self::procitaj_xml( $putanja )
			: self::procitaj_csv( $putanja );

		if ( ! empty( $nalaz['greska'] ) ) {
			return array(
				'ok'     => false,
				'greske' => array( $nalaz['greska'] ),
				'zapisa' => 0,
			);
		}

		$zapisa = $nalaz['zapisa'];

		// 1. Broj redaka odgovara broju entiteta koji su trebali uci.
		if ( $zapisa !== $ocekivano ) {
			$greske[] = sprintf(
				/* translators: 1: nadeno, 2: ocekivano */
				__( 'Datoteka ima %1$d zapisa, a trebala bi imati %2$d.', Config::TEXT_DOMAIN ),
				$zapisa,
				$ocekivano
			);
		}

		// 2. Svaki zapis ima SVA polja iz sheme, makar prazna.
		if ( ! empty( $nalaz['nepotpuni'] ) ) {
			$greske[] = sprintf(
				/* translators: 1: broj zapisa, 2: primjer */
				__( '%1$d zapisa nema sva polja (npr. %2$s).', Config::TEXT_DOMAIN ),
				count( $nalaz['nepotpuni'] ),
				implode( ', ', array_slice( $nalaz['nepotpuni'], 0, 3 ) )
			);
		}

		// 3. Nijedna maloprodajna cijena nije prazna ni nula.
		if ( $nalaz['bez_cijene'] > 0 ) {
			$greske[] = sprintf(
				/* translators: %d = broj zapisa */
				__( '%d zapisa ima praznu ili nultu maloprodajnu cijenu. Cjenik bez cijene nije cjenik.', Config::TEXT_DOMAIN ),
				$nalaz['bez_cijene']
			);
		}

		return array(
			'ok'     => empty( $greske ),
			'greske' => $greske,
			'zapisa' => $zapisa,
		);
	}

	/**
	 * Procitaj XML u toku.
	 *
	 * `XMLReader` cita element po element i ne drzi cijelu datoteku u memoriji —
	 * `simplexml_load_file` bi na trgovini s 50 000 artikala pojeo memorijsko
	 * ogranicenje dijeljenog hostinga upravo u trenutku provjere.
	 */
	private static function procitaj_xml( string $putanja ): array {
		if ( ! class_exists( '\XMLReader' ) ) {
			return array( 'greska' => __( 'XMLReader nije dostupan, datoteka se ne moze provjeriti.', Config::TEXT_DOMAIN ) );
		}

		$citac = new \XMLReader();

		$prijasnje = libxml_use_internal_errors( true );

		if ( ! $citac->open( $putanja ) ) {
			libxml_use_internal_errors( $prijasnje );
			return array( 'greska' => __( 'XML se ne moze otvoriti.', Config::TEXT_DOMAIN ) );
		}

		$zapisa     = 0;
		$nepotpuni  = array();
		$bez_cijene = 0;
		/*
		 * Provjeravaju se samo OBVEZNA polja.
		 *
		 * Uvjetno polje smije nedostajati — za spil karata jedinica mjere se ne
		 * primjenjuje i njegov element ne postoji. Provjera koja to ne zna odbila bi
		 * objaviti ispravnu datoteku, i upravo se to i dogodilo pri prvoj izmjeni
		 * sheme: zadrzala je jucerasnju i prijavila 3414 "nepotpunih" zapisa.
		 */
		$polja = array();
		foreach ( Config::shema() as $kljuc => $element ) {
			if ( ! Config::shema_uvjetno( $kljuc ) ) {
				$polja[] = $element;
			}
		}

		while ( $citac->read() ) {
			if ( \XMLReader::ELEMENT !== $citac->nodeType || Config::CJENIK_ARTIKL !== $citac->name ) {
				continue;
			}

			$xml = $citac->readOuterXml();
			$el  = simplexml_load_string( $xml );

			if ( false === $el ) {
				$citac->close();
				libxml_use_internal_errors( $prijasnje );
				return array( 'greska' => __( 'XML se ne parsira — datoteka je pokvarena.', Config::TEXT_DOMAIN ) );
			}

			$zapisa++;

			foreach ( $polja as $ime ) {
				if ( ! isset( $el->$ime ) ) {
					$nepotpuni[] = '#' . $zapisa . '/' . $ime;
					break;
				}
			}

			$cijena = isset( $el->{ Config::shema()['maloprodajna_cijena'] } )
				? (string) $el->{ Config::shema()['maloprodajna_cijena'] }
				: '';

			if ( '' === $cijena || 0.0 === (float) $cijena ) {
				$bez_cijene++;
			}
		}

		$citac->close();

		$greske_xml = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prijasnje );

		if ( ! empty( $greske_xml ) ) {
			return array( 'greska' => __( 'XML sadrzi greske pri parsiranju.', Config::TEXT_DOMAIN ) );
		}

		return array(
			'zapisa'     => $zapisa,
			'nepotpuni'  => $nepotpuni,
			'bez_cijene' => $bez_cijene,
		);
	}

	private static function procitaj_csv( string $putanja ): array {
		$h = fopen( $putanja, 'r' );

		if ( ! $h ) {
			return array( 'greska' => __( 'CSV se ne moze otvoriti.', Config::TEXT_DOMAIN ) );
		}

		$zaglavlje = fgetcsv( $h );
		$ocekivano = array_values( Config::shema() );

		if ( $zaglavlje !== $ocekivano ) {
			fclose( $h );
			return array( 'greska' => __( 'CSV nema ocekivano zaglavlje u prvom retku.', Config::TEXT_DOMAIN ) );
		}

		$stupac_cijene = array_search( 'maloprodajna_cijena', array_keys( Config::shema() ), true );

		$zapisa     = 0;
		$nepotpuni  = array();
		$bez_cijene = 0;

		while ( ( $redak = fgetcsv( $h ) ) !== false ) {
			if ( array( null ) === $redak ) {
				continue;
			}

			$zapisa++;

			if ( count( $redak ) !== count( $ocekivano ) ) {
				$nepotpuni[] = '#' . $zapisa;
				continue;
			}

			$cijena = (string) ( $redak[ $stupac_cijene ] ?? '' );

			if ( '' === $cijena || 0.0 === (float) $cijena ) {
				$bez_cijene++;
			}
		}

		fclose( $h );

		return array(
			'zapisa'     => $zapisa,
			'nepotpuni'  => $nepotpuni,
			'bez_cijene' => $bez_cijene,
		);
	}
}
