<?php
/**
 * Komadna roba: artikli kojima je neto kolicina "1 kom".
 *
 * ZASTO JE OVO NAJVREDNIJA OPERACIJA U MODULU
 *
 * Neto kolicina je popunjena na 7 od 3623 artikala. Katalog je pritom prakticki u
 * cijelosti komadna roba — spil karata je 1 komad, poker set 1 komad, majica 1
 * komad. Za njih je cijena po jedinici mjere jednaka maloprodajnoj cijeni, sto je
 * tocno i po propisu dovoljno.
 *
 * Jedna operacija tako rjesava polje koje bi inace trazilo 3400 rucnih unosa.
 *
 * ZASTO IPAK TREBA POTVRDA
 *
 * Zato sto "1 kom" nije tocno za sve. `100 zetona Monte Carlo` je pakiranje od sto
 * komada; `6 spilova MODIANO karata` je sest spilova. Upisati im "1 kom" znacilo
 * bi objaviti cijenu po komadu koja je sto odnosno sest puta veca od stvarne.
 *
 * Zato posao artikle s brojem u nazivu NE POPUNJAVA nego izdvaja na pregled.
 *
 * STO SE NE RACUNA KAO KOLICINA
 *
 * Postotak (`100% plastica`), broj unutar imena marke (`Theory 11`), godina
 * (`2026`) i redni broj (`No. 3`, `AUTOBIKE NO 1`). To su mehanicki objasnjivi
 * razredi laznih pogodaka i njihovo iskljucivanje ne skriva nijednu stvarnu
 * kolicinu — samo cini popis za pregled upotrebljivim.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Komadna_Roba {

	/** Kolicina koja se upisuje. */
	const KOLICINA = 1.0;
	const JEDINICA = 'kom';

	/**
	 * Nosi li naziv nesto sto bi moglo biti kolicina.
	 *
	 * @return string|null prepoznat broj, ili null
	 */
	public static function broj_u_nazivu( string $naziv ): ?string {
		$cist = (string) $naziv;

		// 1. Postotak nije kolicina: "100% plastica".
		$cist = preg_replace( '/\d+\s*%/u', ' ', $cist );

		// 2. Broj unutar imena marke nije kolicina: "Theory 11".
		foreach ( Config::MARKE_RJECNIK as $oblici ) {
			foreach ( $oblici as $oblik ) {
				$cist = str_ireplace( $oblik, ' ', $cist );
			}
		}

		// 3. Godina nije kolicina.
		$cist = preg_replace( '/(?<!\d)(19|20)\d{2}(?!\d)/u', ' ', $cist );

		// 4. Redni broj nije kolicina: "No. 3", "NO 1", "br. 2".
		$cist = preg_replace( '/\b(no|nr|br)\.?\s*\d+/ui', ' ', $cist );

		if ( preg_match( '/(?<![\p{L}\d.,])([1-9]\d{0,4})(?![\p{L}\d.,])/u', $cist, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Artikli koji jos nemaju neto kolicinu i nisu oznaceni kao neprimjenjivi.
	 *
	 * @return object[]
	 */
	public static function kandidati( int $nakon_id = 0, int $koliko = 0 ): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );
		$np     = Config::table( Config::TABLE_NEPRIMJENJIVO );
		$polje  = Config::POLJE_KOLICINA;

		$rep = ' ORDER BY p.ID ASC';
		if ( $koliko > 0 ) {
			$rep .= ' LIMIT ' . (int) $koliko;
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS entity_id,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
				 WHERE " . Katalog::uvjet() . " AND p.ID > %d
				   AND ( c.neto_kolicina IS NULL OR c.jedinica_mjere IS NULL OR c.jedinica_mjere = '' )
				   AND NOT EXISTS ( SELECT 1 FROM `{$np}` n WHERE n.entity_id = p.ID AND n.polje = %s )
				 " . $rep,
				$nakon_id,
				$polje
			) // phpcs:ignore
		);
	}

	/**
	 * Artikli koje posao NE dira, poredani po PROMETU.
	 *
	 * Redoslijed je isti kao na ekranu potpunosti i iz istog razloga: popis od 189
	 * nitko ne obraduje odozgo prema dolje nego dok se ne umori. Ako se stane na
	 * pola, stalo se na onome sto se najvise prodaje.
	 *
	 * PRIVATNOST: iz narudzbi se cita samo zbroj po artiklu. Nijedno ime, adresa,
	 * e-mail ni broj narudzbe ne izlazi iz ovog upita.
	 *
	 * @return object[] svaki s dodanim poljem `broj` (sto je prepoznato u nazivu)
	 */
	public static function iznimke(): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );
		$np     = Config::table( Config::TABLE_NEPRIMJENJIVO );
		$oi     = $wpdb->prefix . 'woocommerce_order_items';
		$oim    = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$polje  = Config::POLJE_KOLICINA;

		$redci = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS entity_id,
				        p.post_type,
				        p.post_status,
				        IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AS roditelj_id,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				        COALESCE( l.sku, '' ) AS sku,
				        COALESCE( pr.promet, 0 ) AS promet,
				        pm.meta_value AS cijena
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
				 LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_price'
				 LEFT JOIN (
					 SELECT pid.meta_value AS pid, SUM( CAST( tot.meta_value AS DECIMAL(14,2) ) ) AS promet
					 FROM `{$oi}` i
					 JOIN `{$oim}` pid ON pid.order_item_id = i.order_item_id AND pid.meta_key = '_product_id'
					 JOIN `{$oim}` tot ON tot.order_item_id = i.order_item_id AND tot.meta_key = '_line_total'
					 WHERE i.order_item_type = 'line_item'
					 GROUP BY pid.meta_value
				 ) pr ON pr.pid = IF( p.post_type = 'product_variation', p.post_parent, p.ID )
				 WHERE " . Katalog::uvjet() . "
				   AND ( c.neto_kolicina IS NULL OR c.jedinica_mjere IS NULL OR c.jedinica_mjere = '' )
				   AND NOT EXISTS ( SELECT 1 FROM `{$np}` n WHERE n.entity_id = p.ID AND n.polje = %s )
				 ORDER BY promet DESC, p.ID ASC",
				$polje
			) // phpcs:ignore
		);

		$izlaz = array();

		foreach ( $redci as $r ) {
			$broj = self::broj_u_nazivu( (string) $r->naziv );

			// Preostaju samo oni koje posao preskace. Ostalo je vec dobilo kolicinu.
			if ( null === $broj ) {
				continue;
			}

			$r->broj = $broj;
			$izlaz[] = $r;
		}

		return $izlaz;
	}

	/**
	 * Probni prolaz: koliko bi dobilo "1 kom", a koliko ide na pregled.
	 *
	 * @return array{komadna:int,iznimke:int,ukupno:int,primjeri:array}
	 */
	public static function probni_prolaz(): array {
		$komadna  = 0;
		$iznimke  = 0;
		$primjeri = array();

		foreach ( self::kandidati() as $r ) {
			$broj = self::broj_u_nazivu( (string) $r->naziv );

			if ( null === $broj ) {
				$komadna++;
				continue;
			}

			$iznimke++;

			if ( count( $primjeri ) < 60 ) {
				$primjeri[] = array(
					'entity_id' => (int) $r->entity_id,
					'naziv'     => (string) $r->naziv,
					'broj'      => $broj,
				);
			}
		}

		return array(
			'komadna'  => $komadna,
			'iznimke'  => $iznimke,
			'ukupno'   => $komadna + $iznimke,
			'primjeri' => $primjeri,
		);
	}
}
