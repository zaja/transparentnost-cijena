<?php
/**
 * Izvoz i uvoz podataka o proizvodima, po SIFRI.
 *
 * ZASTO PO SIFRI, A NE PO ID-U
 *
 * Barkodovi dolaze kao tablica od dobavljaca. Dobavljac zna svoju sifru i mozda
 * nasu, ali ID proizvoda u WordPressu ne zna i nema razloga znati. Uparivanje po
 * ID-u znacilo bi da netko mora rucno prepisati 3623 broja.
 *
 * ISTI STUPCI U OBA SMJERA
 *
 * Izvoz i uvoz dijele zaglavlje. Tablica se preuzme, popuni u proracunskoj
 * tablici i vrati — bez prepisivanja i bez pitanja kojim redom idu stupci.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Izvoz {

	/** Zaglavlje, isto za izvoz i uvoz. */
	const ZAGLAVLJE = array(
		'sifra',
		'entity_id',
		'naziv',
		'tip',
		'barkod',
		'barkod_status',
		'marka',
		'neto_kolicina',
		'jedinica_mjere',
		'zakonska_kategorija',
		'nije_primjenjivo',
		'razlog_nije_primjenjivo',
	);

	/**
	 * Svi redci zivog kataloga, s podacima.
	 *
	 * @return object[]
	 */
	public static function redci(): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );
		$np     = Config::table( Config::TABLE_NEPRIMJENJIVO );

		return (array) $wpdb->get_results(
			"SELECT p.ID AS entity_id,
			        p.post_type,
			        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
			        COALESCE( l.sku, '' ) AS sku,
			        c.barkod, c.barkod_status, c.marka,
			        c.neto_kolicina, c.jedinica_mjere, c.zakonska_kategorija,
			        ( SELECT GROUP_CONCAT( n.polje ORDER BY n.polje SEPARATOR '|' )
			          FROM `{$np}` n WHERE n.entity_id = p.ID ) AS np_polja,
			        ( SELECT n2.razlog FROM `{$np}` n2 WHERE n2.entity_id = p.ID ORDER BY n2.id ASC LIMIT 1 ) AS np_razlog
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
			 LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			 LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
			 WHERE " . Katalog::uvjet() . '
			 ORDER BY p.ID ASC' // phpcs:ignore
		);
	}

	/** Jedan redak CSV-a, redom iz ZAGLAVLJE. */
	public static function redak( $r ): array {
		return array(
			(string) $r->sku,
			(int) $r->entity_id,
			(string) $r->naziv,
			(string) $r->post_type,
			(string) $r->barkod,
			(string) $r->barkod_status,
			(string) $r->marka,
			null === $r->neto_kolicina ? '' : rtrim( rtrim( (string) $r->neto_kolicina, '0' ), '.' ),
			(string) $r->jedinica_mjere,
			(string) $r->zakonska_kategorija,
			(string) $r->np_polja,
			(string) $r->np_razlog,
		);
	}
}
