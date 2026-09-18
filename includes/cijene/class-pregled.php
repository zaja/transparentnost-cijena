<?php
/**
 * Stanje dodatnih cijena — jedan izvor za zapisnik posla i za ekran pregleda.
 *
 * Cita se UVIJEK iz baze, ne iz spremljenog sazetka. Sazetak pamti samo kad je
 * posao zadnji put isao; brojke se racunaju svjeze, jer se u meduvremenu mogao
 * dogoditi rucni unos ili odluka klijenta.
 *
 * @package CJTR
 */

namespace CJTR\Cijene;

use CJTR\Config;
use CJTR\Db;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Pregled {

	/** Opcija u kojoj stoji kad je posao zadnji put zavrsio. */
	const OPT_SAZETAK = 'sidrena_sazetak';

	/**
	 * Puno stanje.
	 *
	 * @return array{
	 *   izvori:array, ukupno_upisano:int, ukupno_katalog:int, zbroj_se_slaze:bool,
	 *   bez_pocetka:int, ceka_odluku:int, ceka_unos:int, ceka_pocetnu:int, nule:array, zadnje:string
	 * }
	 */
	public static function stanje(): array {
		global $wpdb;

		$tablica = Config::table( Config::TABLE_PODACI );

		$redci = (array) $wpdb->get_results(
			"SELECT sidrena_izvor,
			        COUNT(*) AS n,
			        SUM( sidrena_cijena IS NOT NULL ) AS s_cijenom,
			        SUM( zahtijeva_odluku ) AS odluka,
			        SUM( pocetak_pouzdan = 0 ) AS bez_pocetka
			 FROM `{$tablica}`
			 GROUP BY sidrena_izvor" // phpcs:ignore
		);

		$izvori      = array();
		$ukupno      = 0;
		$bez_pocetka = 0;

		foreach ( $redci as $r ) {
			$izvor              = (string) $r->sidrena_izvor;
			$izvori[ $izvor ]   = array(
				'n'           => (int) $r->n,
				's_cijenom'   => (int) $r->s_cijenom,
				'odluka'      => (int) $r->odluka,
				'bez_pocetka' => (int) $r->bez_pocetka,
				'naslov'      => Config::OPIS_IZVORA[ $izvor ]['naslov'] ?? $izvor,
				'opis'        => Config::OPIS_IZVORA[ $izvor ]['opis'] ?? '',
			);
			$ukupno            += (int) $r->n;
			$bez_pocetka       += (int) $r->bez_pocetka;
		}

		// Redoslijed kakav je u Configu — najprije rijeseno, pa ono sto treba raditi.
		$poredani = array();
		foreach ( array_keys( Config::OPIS_IZVORA ) as $izvor ) {
			if ( isset( $izvori[ $izvor ] ) ) {
				$poredani[ $izvor ] = $izvori[ $izvor ];
				unset( $izvori[ $izvor ] );
			}
		}
		$poredani += $izvori; // nepoznati izvori na kraj, da se ne izgube

		$katalog = Katalog::broj();

		return array(
			'izvori'         => $poredani,
			'ukupno_upisano' => $ukupno,
			'ukupno_katalog' => $katalog,
			'zbroj_se_slaze' => ( $ukupno === $katalog ),
			'bez_pocetka'    => $bez_pocetka,
			'ceka_odluku'    => self::broj_izvora( $poredani, array( Config::IZVOR_TRAZI_ODLUKU, Config::IZVOR_ARTEFAKT_OSCILACIJE ) ),
			'ceka_unos'      => self::broj_izvora( $poredani, array( Config::IZVOR_RUCNI_UNOS ) ),
			// Vlastita brojka, jer je i lijek drugi: ovdje trgovac zna i datum i
			// cijenu koju je formirao, pa se ne trazi u povijesti nego se upisuje.
			'ceka_pocetnu'   => self::broj_izvora( $poredani, array( Config::IZVOR_NAKON_REF_DATUMA ) ),
			'nule'           => Db::provjeri_nule(),
			'zadnje'         => self::zadnje_pokretanje(),
		);
	}

	/**
	 * Artikli koji cekaju odluku, s OBA kandidata.
	 *
	 * Popis postoji da bi odluka imala gdje biti donesena. Dok ga nije bilo, nalaz
	 * je govorio "pogledajte popis i odlucite", a gumb je vodio na ekran s posve
	 * drugom tablicom — onom o nazivu tekuce akcije. Dva razlicita pitanja koja su
	 * izgledala kao jedno.
	 *
	 * @return object[]
	 */
	public static function ceka_odluku( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.entity_id,
				        c.sidrena_kandidat_regular   AS redovna,
				        c.sidrena_kandidat_efektivna AS akcijska,
				        c.referentni_datum,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				        COALESCE( l.sku, '' ) AS sku
				   FROM `{$podaci}` c
				   JOIN {$wpdb->posts} p ON p.ID = c.entity_id
			  LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
			  LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
				  WHERE c.sidrena_izvor = %s
				    AND c.sidrena_kandidat_regular IS NOT NULL
				    AND c.sidrena_kandidat_efektivna IS NOT NULL
			   ORDER BY c.entity_id ASC
				  LIMIT %d OFFSET %d",
				Config::IZVOR_TRAZI_ODLUKU,
				$limit,
				$offset
			) // phpcs:ignore
		);
	}

	/** Koliko ih ukupno ceka odluku. */
	public static function broj_ceka_odluku(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . Config::table( Config::TABLE_PODACI ) . '`
				 WHERE sidrena_izvor = %s
				   AND sidrena_kandidat_regular IS NOT NULL
				   AND sidrena_kandidat_efektivna IS NOT NULL',
				Config::IZVOR_TRAZI_ODLUKU
			) // phpcs:ignore
		);
	}

	/** Redovi zapisnika, onim redoslijedom kojim idu u log posla. */
	public static function redci_zapisnika(): array {
		$s = self::stanje();
		$l = array();

		foreach ( $s['izvori'] as $izvor => $d ) {
			$l[] = sprintf( '%s: %d', $izvor, $d['n'] );
		}

		$l[] = sprintf(
			/* translators: 1: zbroj skupina, 2: broj artikala u trgovini */
			__( 'kontrolni zbroj: %1$d od %2$d artikala u trgovini', Config::TEXT_DOMAIN ),
			$s['ukupno_upisano'],
			$s['ukupno_katalog']
		);

		if ( ! $s['zbroj_se_slaze'] ) {
			$l[] = sprintf(
				/* translators: %d = razlika */
				__( 'PAZNJA: zbroj se NE slaze, razlika je %d', Config::TEXT_DOMAIN ),
				abs( $s['ukupno_katalog'] - $s['ukupno_upisano'] )
			);
		}

		$l[] = sprintf(
			/* translators: %d = broj */
			__( 'cijena poznata, ali ne i otkad vrijedi: %d', Config::TEXT_DOMAIN ),
			$s['bez_pocetka']
		);

		$l[] = empty( $s['nule'] )
			? __( 'provjera praznih polja: prosla', Config::TEXT_DOMAIN )
			: sprintf(
				/* translators: %s = opis nalaza */
				__( 'provjera praznih polja: PALA — %s', Config::TEXT_DOMAIN ),
				Db::opis_nula( $s['nule'] )
			);

		return $l;
	}

	public static function zapamti_pokretanje(): void {
		update_option(
			Config::option( self::OPT_SAZETAK ),
			array( 'kad' => current_time( 'mysql', true ) ),
			false
		);
	}

	public static function zadnje_pokretanje(): string {
		$s = get_option( Config::option( self::OPT_SAZETAK ) );
		return is_array( $s ) && ! empty( $s['kad'] ) ? (string) $s['kad'] : '';
	}

	private static function broj_izvora( array $izvori, array $kljucevi ): int {
		$n = 0;
		foreach ( $kljucevi as $k ) {
			$n += $izvori[ $k ]['n'] ?? 0;
		}
		return $n;
	}
}
