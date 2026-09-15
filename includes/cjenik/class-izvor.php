<?php
/**
 * Tko ulazi u cjenik, i kojim redom.
 *
 * VARIJABILNI RODITELJ NE ULAZI
 *
 * Prodaje se varijacija, ne roditelj. Da roditelj ude, isti bi artikl bio u
 * datoteci dvaput — jednom kao raspon cijene, jednom kao stvarna cijena. Odluka
 * je vec donesena i zapisana: 183 roditelja nose oznaku "nije primjenjivo".
 *
 * ARTIKL BEZ CIJENE NE ULAZI, ALI SE PRIJAVLJUJE
 *
 * Maloprodajna cijena je obvezan podatak; artikl koji je nema ne moze biti u
 * cjeniku. Ali izostavljanje se NE smije dogoditi tiho — na ovom katalogu takvih
 * je 23, i svaki je otvoreno pitanje (objavljen je i vidljiv, a nema cijenu).
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Izvor {

	/**
	 * Sastavi upit nad entitetima koji ulaze u cjenik.
	 *
	 * @param string $dodatno Dodatni uvjeti, pocevsi s AND.
	 * @param string $rep     ORDER BY / LIMIT.
	 */
	private static function upit( string $select, string $dodatno = '', string $rep = '' ): string {
		global $wpdb;

		$podaci   = Config::table( Config::TABLE_PODACI );
		$np       = Config::table( Config::TABLE_NEPRIMJENJIVO );
		$povijest = Config::table( Config::TABLE_POVIJEST );

		return "SELECT {$select}
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
			LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
			LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_price'
			LEFT JOIN {$wpdb->postmeta} mr ON mr.post_id = p.ID AND mr.meta_key = '_regular_price'
			LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_stock_status'
			LEFT JOIN `{$povijest}` h ON h.entity_id = p.ID AND h.ts_end = 0
			WHERE " . Katalog::uvjet() . "
			  AND NOT EXISTS (
			      SELECT 1 FROM `{$np}` n
			      WHERE n.entity_id = p.ID AND n.polje = '" . esc_sql( Config::POLJE_KATEGORIJA ) . "'
			        AND n.razlog LIKE '%varijabiln%' )
			  {$dodatno}
			{$rep}";
	}

	/** Stupci koje Redak ocekuje. */
	private static function stupci(): string {
		return "p.ID AS entity_id,
			p.post_type,
			COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
			COALESCE( l.sku, '' ) AS sku,
			mp.meta_value AS price,
			mr.meta_value AS regular_price,
			c.marka, c.barkod, c.barkod_status,
			c.neto_kolicina, c.jedinica_mjere,
			c.zakonska_kategorija, c.sidrena_cijena,
			COALESCE( l.stock_status, ms.meta_value, 'instock' ) AS stock_status,
			h.vrsta_pop";
	}

	/** Uvjet: artikl ima cijenu. */
	private static function ima_cijenu(): string {
		return "AND mp.meta_value IS NOT NULL AND mp.meta_value <> ''";
	}

	public static function ukupno(): int {
		global $wpdb;
		return (int) $wpdb->get_var( self::upit( 'COUNT(*)', self::ima_cijenu() ) ); // phpcs:ignore
	}

	/**
	 * Jedan komad, keyset paginacijom.
	 *
	 * @return object[]
	 */
	public static function komad( int $zadnji_id, int $velicina ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				self::upit(
					self::stupci(),
					self::ima_cijenu() . ' AND p.ID > %d',
					'ORDER BY p.ID ASC LIMIT %d'
				),
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);
	}

	/**
	 * Artikli koji su objavljeni i vidljivi, a nemaju cijenu.
	 *
	 * Ne ulaze u cjenik. Prijavljuju se jer je svaki od njih otvoreno pitanje.
	 *
	 * @return object[]
	 */
	public static function bez_cijene(): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			self::upit(
				"p.ID AS entity_id, COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				 COALESCE( l.sku, '' ) AS sku",
				"AND ( mp.meta_value IS NULL OR mp.meta_value = '' )",
				'ORDER BY p.ID ASC'
			) // phpcs:ignore
		);
	}

	/**
	 * Cijene s vise decimala nego sto cjenik smije nositi.
	 *
	 * Generator ih ne popravlja — samo prijavljuje. Zaokruzivanje je politika koja
	 * se primjenjuje na katalog, a ne nusprodukt izvoza.
	 *
	 * @return object[]
	 */
	public static function previse_decimala(): array {
		global $wpdb;

		$uzorak = '\\.[0-9]{' . ( Config::CJENIK_MAX_DECIMALA + 1 ) . ',}';

		return (array) $wpdb->get_results(
			self::upit(
				"p.ID AS entity_id, COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				 COALESCE( l.sku, '' ) AS sku, mp.meta_value AS price",
				self::ima_cijenu() . " AND mp.meta_value REGEXP '" . $uzorak . "'",
				'ORDER BY p.ID ASC'
			) // phpcs:ignore
		);
	}
}
