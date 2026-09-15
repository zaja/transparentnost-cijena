<?php
/**
 * Oznacavanje sumnjivo dugih akcija.
 *
 * STO OVO JEST I STO NIJE
 *
 * Plugin OZNACAVA artikle kod kojih akcija traje neobicno dugo i trazi potvrdu
 * trgovca. NIKAD ne prekvalificira sam. Da sam odluci, donio bi pravnu odluku
 * umjesto trgovca — a to nije njegovo.
 *
 * Prag (Config::PRAG_SUMNJIVO_DUGE_AKCIJE) NIJE pravna granica. Ne postoji propis
 * koji kaze da akcija dulja od 90 dana prestaje biti akcija. To je prag za
 * oznacavanje, izabran tako da uhvati ono sto vrijedi pogledati.
 *
 * STO SE MJERI
 *
 * Koliko dugo je TEKUCA CIJENA NEPROMIJENJENA — ne koliko je akcija zakazana da
 * traje. Datum pocetka akcije je namjera; trajanje nepromijenjene cijene je
 * cinjenica. Kod nekog drugog trgovca to nije isto: akcija moze biti zakazana na
 * godinu dana, a cijena se usput mijenjati nekoliko puta.
 *
 * @package CJTR
 */

namespace CJTR\Povijest;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Duge_Akcije {

	/**
	 * Artikli kojima akcijska cijena traje dulje od praga.
	 *
	 * @param int|null $prag_dana null = uzmi iz Configa
	 * @return array<int,object>
	 */
	public static function oznaceni( ?int $prag_dana = null ): array {
		global $wpdb;

		$prag     = $prag_dana ?? Config::PRAG_SUMNJIVO_DUGE_AKCIJE;
		$granica  = time() - ( $prag * DAY_IN_SECONDS );
		$povijest = Config::table( Config::TABLE_POVIJEST );

		// Kljucno: uvjet je na `ts` OTVORENOG intervala — dakle koliko dugo je
		// cijena nepromijenjena. Datum pocetka akcije se namjerno ne gleda.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.entity_id,
				        h.regular_price,
				        h.sale_price,
				        h.price,
				        h.ts,
				        h.pocetak_pouzdan,
				        FLOOR( ( %d - h.ts ) / %d ) AS dana,
				        p.post_type,
				        IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AS roditelj_id,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				        COALESCE( l.sku, '' ) AS sku
				 FROM `{$povijest}` h
				 JOIN {$wpdb->posts} p ON p.ID = h.entity_id
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = h.entity_id
				 WHERE h.ts_end = 0
				   AND h.pocetak_pouzdan = 1
				   AND h.ts > 0
				   AND h.ts < %d
				   AND h.vrsta_pop <> %s
				   AND " . Katalog::uvjet() . '
				 ORDER BY dana DESC, h.entity_id ASC',
				time(),
				DAY_IN_SECONDS,
				$granica,
				Config::POP_NEMA
			) // phpcs:ignore
		);
	}

	public static function broj( ?int $prag_dana = null ): int {
		return count( self::oznaceni( $prag_dana ) );
	}

	/**
	 * Koliko ih ima, ali s nepoznatim pocetkom intervala.
	 *
	 * Te ne mozemo ni oznaciti ni osloboditi — ne zna se koliko dugo cijena traje.
	 * Broj mora biti vidljiv da se ne pomisli kako ih nema.
	 */
	public static function bez_poznatog_pocetka(): int {
		global $wpdb;

		$povijest = Config::table( Config::TABLE_POVIJEST );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM `{$povijest}` h
				 JOIN {$wpdb->posts} p ON p.ID = h.entity_id
				 WHERE h.ts_end = 0
				   AND ( h.pocetak_pouzdan = 0 OR h.ts = 0 )
				   AND h.vrsta_pop <> %s
				   AND " . Katalog::uvjet(),
				Config::POP_NEMA
			) // phpcs:ignore
		);
	}
}
