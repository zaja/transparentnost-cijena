<?php
/**
 * JEDINA definicija skupine koju promocija dira.
 *
 * Isti uvjet cita ekran pregleda, izvoz u CSV, posao upisa i posao koji nakon
 * njega upisuje dodatnu cijenu. Da je uvjet napisan na cetiri mjesta, ekran bi
 * jednog dana pokazivao jedan broj a posao mijenjao drugi skup — i to se ne bi
 * primijetilo dok ne bude kasno.
 *
 * STO ULAZI
 *
 * Artikl kod kojeg akcija STVARNO TECE:
 *   - akcijska cijena je postavljena
 *   - cijena koja se naplacuje jednaka je akcijskoj
 *   - akcijska cijena je niza od redovne
 *
 * Sva tri uvjeta zajedno, ne bilo koji od njih. `_price < _regular_price` sam po
 * sebi uhvatio bi i artikl kojem cijenu snizava neki dodatak, a ne akcija.
 * `_sale_price` postavljen sam po sebi uhvatio bi i akciju koja jos nije pocela
 * ili je vec istekla — a kod te se naplacuje redovna cijena, pa bi je promocija
 * pogresno spustila na akcijsku. To je tocno kvar iz B7, samo u drugom smjeru.
 *
 * @package CJTR
 */

namespace CJTR\Cijene;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Promocija_Popis {

	/**
	 * Sastavi upit nad skupinom.
	 *
	 * Umjesto da se FROM i WHERE vracaju kao jedan gotov string koji pozivatelj
	 * onda mora rastavljati, upit se sastavlja ovdje. Pozivatelj daje sto zeli
	 * dodati, a raspored klauzula ostaje na jednom mjestu.
	 *
	 * @param string $select     Stupci, bez rijeci SELECT.
	 * @param string $joinovi    Dodatni spojevi, ili prazno.
	 * @param string $dodatno    Dodatni uvjeti, pocevsi s AND, ili prazno.
	 * @param string $rep        ORDER BY / LIMIT, ili prazno.
	 */
	public static function upit( string $select, string $joinovi = '', string $dodatno = '', string $rep = '' ): string {
		global $wpdb;

		$tol = Config::TOLERANCIJA_POVIJESTI;

		return "SELECT {$select}
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'
			JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_price'
			JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = '_sale_price'
			{$joinovi}
			WHERE " . Katalog::uvjet() . "
			  AND rp.meta_value <> ''
			  AND pr.meta_value <> ''
			  AND sp.meta_value <> ''
			  AND ABS( CAST( pr.meta_value AS DECIMAL(12,4) ) - CAST( sp.meta_value AS DECIMAL(12,4) ) ) < {$tol}
			  AND CAST( pr.meta_value AS DECIMAL(12,4) ) < CAST( rp.meta_value AS DECIMAL(12,4) )
			  {$dodatno}
			{$rep}";
	}

	/** Stupci koje trebaju i pregled i izvoz i provjera nesuglasja. */
	private static function stupci(): string {
		return "p.ID AS entity_id,
		        p.post_type,
		        p.post_status,
		        IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AS roditelj_id,
		        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
		        COALESCE( l.sku, '' ) AS sku,
		        CAST( pr.meta_value AS DECIMAL(12,4) ) AS naplacuje_se,
		        CAST( rp.meta_value AS DECIMAL(12,4) ) AS redovna,
		        CAST( sp.meta_value AS DECIMAL(12,4) ) AS akcijska";
	}

	/** Spojevi na naziv, SKU i nasu tablicu. */
	private static function joinovi( string $vrsta_podataka = 'LEFT' ): string {
		global $wpdb;

		$tablica = Config::table( Config::TABLE_PODACI );

		return "LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
			LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			{$vrsta_podataka} JOIN `{$tablica}` c ON c.entity_id = p.ID";
	}

	public static function broj(): int {
		global $wpdb;
		return (int) $wpdb->get_var( self::upit( 'COUNT(*)' ) ); // phpcs:ignore
	}

	/**
	 * ID-evi jednog komada, keyset paginacijom.
	 *
	 * @return int[]
	 */
	public static function komad( int $zadnji_id, int $velicina ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				self::upit( 'p.ID', '', 'AND p.ID > %d', 'ORDER BY p.ID ASC LIMIT %d' ),
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Puni redci za pregled i izvoz.
	 *
	 * @return object[]
	 */
	public static function redci( int $limit = 0 ): array {
		global $wpdb;

		$rep = 'ORDER BY roditelj_id ASC, p.ID ASC';
		if ( $limit > 0 ) {
			$rep .= ' LIMIT ' . (int) $limit;
		}

		$select = self::stupci() . ',
			c.sidrena_cijena, c.sidrena_izvor, c.zahtijeva_odluku,
			c.sidrena_kandidat_regular, c.sidrena_kandidat_efektivna';

		return (array) $wpdb->get_results( self::upit( $select, self::joinovi(), '', $rep ) ); // phpcs:ignore
	}

	/**
	 * Sazetak za vrh ekrana.
	 *
	 * Klijent odlucuje o PROIZVODIMA, ne o retcima — varijante jednog proizvoda
	 * nisu zasebne odluke. Zato proizvodi idu prvi, a redci kao tehnicka mjera.
	 */
	public static function sazetak(): array {
		global $wpdb;

		$r = $wpdb->get_row(
			self::upit(
				"COUNT(*) AS redaka,
				 COUNT( DISTINCT IF( p.post_type = 'product_variation', p.post_parent, p.ID ) ) AS proizvoda,
				 SUM( p.post_type = 'product_variation' ) AS varijanti,
				 SUM( p.post_type = 'product' ) AS samostalnih"
			) // phpcs:ignore
		);

		// Javno vidljivo = proizvod je objavljen. Varijanta nasljeduje status
		// roditelja, pa se gleda roditeljev.
		$javnih = (int) $wpdb->get_var(
			self::upit(
				"COUNT( DISTINCT IF( p.post_type = 'product_variation', p.post_parent, p.ID ) )",
				'',
				"AND ( p.post_status = 'publish' OR EXISTS (
					SELECT 1 FROM {$wpdb->posts} r WHERE r.ID = p.post_parent AND r.post_status = 'publish' ) )"
			) // phpcs:ignore
		);

		return array(
			'proizvoda'   => (int) ( $r->proizvoda ?? 0 ),
			'javnih'      => $javnih,
			'redaka'      => (int) ( $r->redaka ?? 0 ),
			'varijanti'   => (int) ( $r->varijanti ?? 0 ),
			'samostalnih' => (int) ( $r->samostalnih ?? 0 ),
		);
	}

	/**
	 * Dodatna cijena koja ce vrijediti nakon odluke.
	 *
	 * Nije uvijek ista vrijednost: ako je vec upisana, mjerodavna je ona; ako nije,
	 * odluka bira akcijsku cijenu s referentnog datuma, dakle kandidata za
	 * efektivnu cijenu. Izraz stoji ovdje jednom jer ga koriste i provjera
	 * nesuglasja i posao koji dodatnu cijenu upisuje.
	 */
	private const SIDRENA_NAKON_ODLUKE = 'COALESCE( c.sidrena_cijena, c.sidrena_kandidat_efektivna )';

	/** Uvjet: dodatna cijena postoji i RAZLIKUJE se od naplacivane. */
	private static function uvjet_nesuglasja(): string {
		$tol = Config::TOLERANCIJA_POVIJESTI;

		return 'AND ' . self::SIDRENA_NAKON_ODLUKE . ' IS NOT NULL
			AND ABS( ' . self::SIDRENA_NAKON_ODLUKE . " - CAST( pr.meta_value AS DECIMAL(12,4) ) ) > {$tol}";
	}

	/**
	 * Artikli kod kojih promocija NE BI bila u skladu s dodatnom cijenom.
	 *
	 * Promocija tvrdi: cijena koja se naplacuje jest redovna cijena. Kod artikla
	 * kojem dodatna cijena RAZLIKUJE se od naplacivane, na referentni datum je
	 * vrijedilo nesto trece — pa te dvije tvrdnje ne stoje jedna uz drugu. Takve
	 * treba rijesiti zasebno, ne tiho zajedno s ostalima.
	 *
	 * Usporeduje se s vrijednoscu koja ce vrijediti NAKON odluke, ne samo s vec
	 * upisanom. Da se gledala samo upisana, provjera bi na ovom shopu vratila nulu
	 * iz pogresnog razloga — nijedan artikl iz skupine je jos nema — i stvorila
	 * dojam da je provjerena.
	 *
	 * @return object[]
	 */
	public static function nesuglasni(): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			self::upit(
				self::stupci() . ', ' . self::SIDRENA_NAKON_ODLUKE . ' AS sidrena_nakon_odluke,
				 c.sidrena_cijena, c.sidrena_kandidat_efektivna, c.sidrena_izvor',
				self::joinovi( 'INNER' ),
				self::uvjet_nesuglasja(),
				'ORDER BY roditelj_id ASC, p.ID ASC'
			) // phpcs:ignore
		);
	}

	public static function broj_nesuglasnih(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			self::upit( 'COUNT(*)', self::joinovi( 'INNER' ), self::uvjet_nesuglasja() ) // phpcs:ignore
		);
	}

	/** Je li dani artikl nesuglasan s dodatnom cijenom. */
	public static function nesuglasan( int $entity_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				self::upit( 'COUNT(*)', self::joinovi( 'INNER' ), 'AND p.ID = %d ' . self::uvjet_nesuglasja() ),
				$entity_id
			) // phpcs:ignore
		);
	}

	/**
	 * Artikli za koje dodatna cijena ne postoji ni kao vrijednost ni kao kandidat.
	 *
	 * Nisu nesuglasni — kod njih nema proturjecja, nego podatka jos nema. Broj se
	 * prikazuje odvojeno da nula nesuglasnih ne znaci "sve provjereno" kad dio
	 * skupine nije imao s cime biti usporeden.
	 */
	public static function broj_bez_podatka(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			self::upit(
				'COUNT(*)',
				self::joinovi(),
				'AND ( c.entity_id IS NULL OR ' . self::SIDRENA_NAKON_ODLUKE . ' IS NULL )'
			) // phpcs:ignore
		);
	}
}
