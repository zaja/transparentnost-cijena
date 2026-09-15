<?php
/**
 * Potpunost kataloga — jedan broj, pa razrada.
 *
 * MJERILO MODULA
 *
 * Izmjereno stanje prije: barkod 0 %, marka 0 %, neto kolicina 0,14 %. Ovaj ekran
 * je jedino mjesto gdje se vidi mice li se to. Generator cjenika bez ovih podataka
 * nema sto objaviti, pa je "X od Y artikala spremno" jedina brojka koja kaze koliko
 * je projekt daleko.
 *
 * SPREMAN ZNACI: SVAKO POLJE JE ILI POPUNJENO ILI OZNACENO KAO NEPRIMJENJIVO
 *
 * Oznaka "nije primjenjivo" racuna se kao rijeseno. Bez toga popis zadataka nikad
 * ne dode do nule i prestane se citati — a majica vlastite proizvodnje nikad nece
 * dobiti barkod jer joj ga nitko nije dodijelio.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Potpunost {

	/**
	 * Polja koja se racunaju u "spremno za cjenik".
	 *
	 * Popis obveznih podataka propisan je, i vec se jednom promijenio. Zato se ovdje
	 * ne nabraja nego cita iz `Config::POLJA`: polje s oznakom `nikad` ostaje u
	 * dodatku i dalje se moze unijeti, ali njegov izostanak nije manjak.
	 *
	 * Kategorija je poseban slucaj — obvezna je samo za trgovinu koja prodaje nesto
	 * iz reguliranih skupina, jer ondje odreduje koji referentni datum vrijedi.
	 *
	 * @return string[]
	 */
	public static function obvezna_polja(): array {
		$izlaz = array();

		foreach ( Config::POLJA as $polje => $meta ) {
			$kada = $meta['obvezno'] ?? Config::OBVEZNO_UVIJEK;

			if ( Config::OBVEZNO_NIKAD === $kada ) {
				continue;
			}

			if ( Config::OBVEZNO_AKO_REGULIRANE === $kada
				&& ! \CJTR\Postavke::daj( \CJTR\Postavke::IMA_REGULIRANE ) ) {
				continue;
			}

			$izlaz[] = $polje;
		}

		return $izlaz;
	}

	/**
	 * SQL izraz: je li polje rijeseno za taj redak.
	 *
	 * Rijeseno = ima vrijednost u SVIM svojim stupcima, ili nosi oznaku "nije
	 * primjenjivo". Polje `kolicina` ima dva stupca i oba moraju biti popunjena —
	 * kolicina bez jedinice mjere je broj bez znacenja.
	 */
	private static function uvjet_rijeseno( string $polje ): string {
		$np = Config::table( Config::TABLE_NEPRIMJENJIVO );

		$uvjeti = array();
		foreach ( Config::POLJA[ $polje ]['stupci'] as $stupac ) {
			$stupac   = preg_replace( '/[^a-z_]/', '', $stupac );
			$uvjeti[] = "( c.`{$stupac}` IS NOT NULL AND c.`{$stupac}` <> '' )";
		}

		$popunjeno = '( ' . implode( ' AND ', $uvjeti ) . ' )';

		$oznaceno = "EXISTS ( SELECT 1 FROM `{$np}` n
			WHERE n.entity_id = p.ID AND n.polje = '" . esc_sql( $polje ) . "' )";

		return "( {$popunjeno} OR {$oznaceno} )";
	}

	/**
	 * Sastavi upit nad zivim katalogom.
	 *
	 * Upit se sastavlja ovdje, a ne vraca kao gotov FROM+WHERE koji pozivatelj onda
	 * dopunjuje — spoj zapisan iza WHERE klauzule nije valjan SQL. Ista greska vec
	 * je jednom napravljena u `Cijene\Promocija_Popis` i ondje rijesena na isti
	 * nacin; ovdje stoji zato da se ne ponovi treci put.
	 *
	 * @param string $select  Stupci, bez rijeci SELECT.
	 * @param string $joinovi Dodatni spojevi, ili prazno.
	 * @param string $dodatno Dodatni uvjeti, pocevsi s AND, ili prazno.
	 * @param string $rep     ORDER BY / LIMIT, ili prazno.
	 */
	private static function upit( string $select, string $joinovi = '', string $dodatno = '', string $rep = '' ): string {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );

		return "SELECT {$select}
			FROM {$wpdb->posts} p
			LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
			{$joinovi}
			WHERE " . Katalog::uvjet() . "
			  {$dodatno}
			{$rep}";
	}

	/**
	 * Jedan broj na vrhu: koliko je artikala spremno za cjenik.
	 *
	 * @return array{spremno:int,ukupno:int,postotak:float}
	 */
	public static function sazetak(): array {
		global $wpdb;

		$uvjeti = array();
		foreach ( self::obvezna_polja() as $polje ) {
			$uvjeti[] = self::uvjet_rijeseno( $polje );
		}

		$svi = implode( ' AND ', $uvjeti );

		$r = $wpdb->get_row(
			self::upit( 'COUNT(*) AS ukupno, SUM( ' . $svi . ' ) AS spremno' ) // phpcs:ignore
		);

		$ukupno  = (int) ( $r->ukupno ?? 0 );
		$spremno = (int) ( $r->spremno ?? 0 );

		return array(
			'spremno'  => $spremno,
			'ukupno'   => $ukupno,
			'postotak' => $ukupno > 0 ? round( 100 * $spremno / $ukupno, 1 ) : 0.0,
		);
	}

	/**
	 * Razrada po poljima.
	 *
	 * @return array<string,array{popunjeno:int,neprimjenjivo:int,nedostaje:int,ukupno:int,postotak:float}>
	 */
	public static function po_poljima(): array {
		global $wpdb;

		$np     = Config::table( Config::TABLE_NEPRIMJENJIVO );
		$izlaz  = array();

		foreach ( Config::POLJA as $polje => $meta ) {
			$uvjeti = array();
			foreach ( $meta['stupci'] as $stupac ) {
				$stupac   = preg_replace( '/[^a-z_]/', '', $stupac );
				$uvjeti[] = "( c.`{$stupac}` IS NOT NULL AND c.`{$stupac}` <> '' )";
			}
			$popunjeno = '( ' . implode( ' AND ', $uvjeti ) . ' )';

			$oznaceno = "EXISTS ( SELECT 1 FROM `{$np}` n
				WHERE n.entity_id = p.ID AND n.polje = '" . esc_sql( $polje ) . "' )";

			$r = $wpdb->get_row(
				self::upit(
					"COUNT(*) AS ukupno,
					 SUM( {$popunjeno} AND NOT {$oznaceno} ) AS popunjeno,
					 SUM( {$oznaceno} ) AS neprimjenjivo"
				) // phpcs:ignore
			);

			$ukupno = (int) ( $r->ukupno ?? 0 );
			$pop    = (int) ( $r->popunjeno ?? 0 );
			$npr    = (int) ( $r->neprimjenjivo ?? 0 );

			$izlaz[ $polje ] = array(
				'naziv'         => $meta['naziv'],
				'popunjeno'     => $pop,
				'neprimjenjivo' => $npr,
				'nedostaje'     => max( 0, $ukupno - $pop - $npr ),
				'ukupno'        => $ukupno,
				'postotak'      => $ukupno > 0 ? round( 100 * ( $pop + $npr ) / $ukupno, 1 ) : 0.0,
			);
		}

		return $izlaz;
	}

	/**
	 * Artikli kojima nesto nedostaje, poredani po PROMETU.
	 *
	 * Redoslijed nije kozmeticki. Katalog od 3623 retka nitko ne obraduje odozgo
	 * prema dolje; obraduje se dok se ne umori. Zato prvo idu artikli koji donose
	 * najvise — ako se stane na pola, stalo se na pravom mjestu.
	 *
	 * PRIVATNOST: iz narudzbi se cita SAMO zbroj po artiklu. Nijedno ime, adresa,
	 * e-mail ni broj narudzbe ne izlazi iz ovog upita.
	 *
	 * @param string $polje Prazno = bilo koje polje nedostaje.
	 * @return object[]
	 */
	public static function nepotpuni( string $polje = '', int $limit = 100, int $odmak = 0 ): array {
		global $wpdb;

		$polja = ( '' !== $polje && isset( Config::POLJA[ $polje ] ) )
			? array( $polje )
			: self::obvezna_polja();

		$nedostaje = array();
		foreach ( $polja as $p ) {
			$nedostaje[] = 'NOT ' . self::uvjet_rijeseno( $p );
		}

		$oi  = $wpdb->prefix . 'woocommerce_order_items';
		$oim = $wpdb->prefix . 'woocommerce_order_itemmeta';

		$joinovi = "LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
			LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			LEFT JOIN (
				SELECT pid.meta_value AS pid, SUM( CAST( tot.meta_value AS DECIMAL(14,2) ) ) AS promet
				FROM `{$oi}` i
				JOIN `{$oim}` pid ON pid.order_item_id = i.order_item_id AND pid.meta_key = '_product_id'
				JOIN `{$oim}` tot ON tot.order_item_id = i.order_item_id AND tot.meta_key = '_line_total'
				WHERE i.order_item_type = 'line_item'
				GROUP BY pid.meta_value
			) pr ON pr.pid = IF( p.post_type = 'product_variation', p.post_parent, p.ID )";

		$select = "p.ID AS entity_id,
			p.post_type,
			p.post_status,
			IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AS roditelj_id,
			COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
			COALESCE( l.sku, '' ) AS sku,
			c.barkod, c.barkod_status, c.marka, c.neto_kolicina, c.jedinica_mjere,
			c.zakonska_kategorija,
			COALESCE( pr.promet, 0 ) AS promet";

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				self::upit(
					$select,
					$joinovi,
					'AND ( ' . implode( ' OR ', $nedostaje ) . ' )',
					'ORDER BY promet DESC, p.ID ASC LIMIT %d OFFSET %d'
				),
				$limit,
				$odmak
			) // phpcs:ignore
		);
	}

	public static function broj_nepotpunih( string $polje = '' ): int {
		global $wpdb;

		$polja = ( '' !== $polje && isset( Config::POLJA[ $polje ] ) )
			? array( $polje )
			: self::obvezna_polja();

		$nedostaje = array();
		foreach ( $polja as $p ) {
			$nedostaje[] = 'NOT ' . self::uvjet_rijeseno( $p );
		}

		return (int) $wpdb->get_var(
			self::upit( 'COUNT(*)', '', 'AND ( ' . implode( ' OR ', $nedostaje ) . ' )' ) // phpcs:ignore
		);
	}

	/**
	 * Barkodovi koji NE MOGU biti barkod, i oni koji su sporni.
	 *
	 * Gleda i nasu tablicu i WooCommerceovo polje, jer se objavljuje ono sto vrijedi,
	 * a ne ono sto je nase. WooCommerceova vrijednost cita se iz `wc_product_meta_lookup`:
	 * izmjereno, `postmeta` je za nju prazan.
	 *
	 * @return array{nemoguci:object[],sporni:object[]}
	 */
	public static function barkodi_s_problemom(): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );

		$redci = (array) $wpdb->get_results(
			"SELECT p.ID AS entity_id,
			        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
			        COALESCE( l.sku, '' ) AS sku,
			        c.barkod AS nas,
			        l.global_unique_id AS woo
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
			 LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
			 LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			 WHERE " . Katalog::uvjet() . "
			   AND ( ( c.barkod IS NOT NULL AND c.barkod <> '' )
			      OR ( l.global_unique_id IS NOT NULL AND l.global_unique_id <> '' ) )
			 ORDER BY p.ID ASC" // phpcs:ignore
		);

		$izlaz = array(
			'nemoguci' => array(),
			'sporni'   => array(),
		);

		foreach ( $redci as $r ) {
			// WooCommerceova vrijednost ima prednost — ona je ta koja bi se objavila.
			$vrijednost = ( '' !== (string) $r->woo ) ? (string) $r->woo : (string) $r->nas;
			$r->iz_wooa = ( '' !== (string) $r->woo );
			$r->barkod  = $vrijednost;
			$r->status  = Gtin::status( $vrijednost );

			if ( Config::GTIN_NEMOGUC === $r->status ) {
				$izlaz['nemoguci'][] = $r;
			} elseif ( Config::GTIN_NEISPRAVAN === $r->status ) {
				$izlaz['sporni'][] = $r;
			}
		}

		return $izlaz;
	}

	/**
	 * Barkodovi koji se ponavljaju.
	 *
	 * Barkod je jedinstven po proizvodu. Isti na dva artikla gotovo je uvijek
	 * greska unosa — najcesce kopiran redak u tablici dobavljaca.
	 *
	 * @return object[]
	 */
	public static function duplikati_barkoda(): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );

		return (array) $wpdb->get_results(
			"SELECT c.barkod, COUNT(*) AS koliko, GROUP_CONCAT( c.entity_id ORDER BY c.entity_id SEPARATOR ', ' ) AS artikli
			 FROM `{$podaci}` c
			 JOIN {$wpdb->posts} p ON p.ID = c.entity_id
			 WHERE c.barkod IS NOT NULL AND c.barkod <> ''
			   AND " . Katalog::uvjet() . '
			 GROUP BY c.barkod
			 HAVING koliko > 1
			 ORDER BY koliko DESC' // phpcs:ignore
		);
	}

	/**
	 * Barkodovi koji ne smiju u javni cjenik, po statusu.
	 *
	 * @return array<string,int>
	 */
	public static function barkodi_po_statusu(): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );

		$izlaz = array();

		foreach ( (array) $wpdb->get_results(
			"SELECT c.barkod_status AS status, COUNT(*) AS n
			 FROM `{$podaci}` c
			 JOIN {$wpdb->posts} p ON p.ID = c.entity_id
			 WHERE c.barkod IS NOT NULL AND c.barkod <> ''
			   AND " . Katalog::uvjet() . '
			 GROUP BY c.barkod_status' // phpcs:ignore
		) as $r ) {
			$izlaz[ (string) $r->status ] = (int) $r->n;
		}

		return $izlaz;
	}
}
