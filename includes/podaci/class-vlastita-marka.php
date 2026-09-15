<?php
/**
 * Vlastita marka: skupni upis marke trgovca na odabranu skupinu artikala.
 *
 * ZASTO OVO NIJE "NIJE PRIMJENJIVO"
 *
 * Artikl vlastite proizvodnje NEMA praznu marku — ima marku trgovca. Tako i lanci
 * objavljuju svoje proizvode: private label nosi ime trgovca ili zasebnog brenda.
 * Oznaciti ga kao "marka nije primjenjiva" bila bi neistinita tvrdnja, i k tome
 * ona koju cjenik ne bi mogao objaviti.
 *
 * ZASTO SE IME NE MOZE POGODITI
 *
 * Trgovac moze birati naziv trgovine, zaseban brend za majice, ili nesto trece.
 * To je poslovna odluka i dodatak je ne donosi — nudi operaciju, a ime upisuje
 * covjek.
 *
 * OPSEG SE BIRA, NE PRETPOSTAVLJA
 *
 * Skupina se zadaje kategorijom trgovine. Varijacije idu s roditeljem: u cjenik
 * ide varijacija, pa bi bez njih operacija pokrila roditelja kojeg nitko ne kupuje
 * i promasila 2950 redaka koji se stvarno prodaju.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Vlastita_Marka {

	/**
	 * Artikli u zadanim kategorijama kojima marka jos nije rucno unesena.
	 *
	 * @param int[] $kategorije term_id kategorija trgovine
	 * @return object[]
	 */
	public static function kandidati( array $kategorije, int $nakon_id = 0, int $koliko = 0 ): array {
		global $wpdb;

		$kategorije = array_filter( array_map( 'intval', $kategorije ) );

		if ( empty( $kategorije ) ) {
			return array();
		}

		$podaci = Config::table( Config::TABLE_PODACI );
		$u      = implode( ',', $kategorije );

		$rep = ' ORDER BY p.ID ASC';
		if ( $koliko > 0 ) {
			$rep .= ' LIMIT ' . (int) $koliko;
		}

		// Kategorija se gleda na PROIZVODU; varijacija je nasljeduje od roditelja.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS entity_id,
				        p.post_type,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				        c.marka, c.marka_izvor
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
				 WHERE " . Katalog::uvjet() . " AND p.ID > %d
				   AND EXISTS (
				       SELECT 1 FROM {$wpdb->term_relationships} tr
				       JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				       WHERE tt.taxonomy = 'product_cat'
				         AND tt.term_id IN ({$u})
				         AND tr.object_id = IF( p.post_type = 'product_variation', p.post_parent, p.ID )
				   )
				   AND ( c.marka_izvor IS NULL OR c.marka_izvor NOT IN ( %s, %s ) )
				 " . $rep,
				$nakon_id,
				Config::IZVOR_PODATKA_RUCNO,
				Config::IZVOR_PODATKA_CSV
			) // phpcs:ignore
		);
	}

	/**
	 * Koliko bi artikala i varijacija operacija zahvatila.
	 *
	 * @param int[] $kategorije
	 * Razdvaja PRAZNE od onih koji vec nose vrijednost izvedenu strojno. Razlika je
	 * bitna prije odluke: prvo je popunjavanje, drugo je prepisivanje — a prepisati
	 * marku koju je stroj procitao iz naziva moze biti i ispravak i steta, ovisno o
	 * tome je li naziv govorio istinu.
	 *
	 * @return array{proizvoda:int,varijacija:int,ukupno:int,prazni:int,prepisuje:int,primjeri_prepisa:array}
	 */
	public static function opseg( array $kategorije ): array {
		$proizvoda  = 0;
		$varijacija = 0;
		$prazni     = 0;
		$prepisuje  = 0;
		$primjeri   = array();

		foreach ( self::kandidati( $kategorije ) as $r ) {
			if ( 'product_variation' === $r->post_type ) {
				$varijacija++;
			} else {
				$proizvoda++;
			}

			if ( '' === (string) $r->marka ) {
				$prazni++;
				continue;
			}

			$prepisuje++;

			if ( count( $primjeri ) < 20 ) {
				$primjeri[] = array(
					'entity_id' => (int) $r->entity_id,
					'naziv'     => (string) $r->naziv,
					'marka'     => (string) $r->marka,
					'izvor'     => (string) $r->marka_izvor,
				);
			}
		}

		return array(
			'proizvoda'        => $proizvoda,
			'varijacija'       => $varijacija,
			'ukupno'           => $proizvoda + $varijacija,
			'prazni'           => $prazni,
			'prepisuje'        => $prepisuje,
			'primjeri_prepisa' => $primjeri,
		);
	}
}
