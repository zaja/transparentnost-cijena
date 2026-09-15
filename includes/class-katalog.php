<?php
/**
 * Jedna definicija toga sto je ziv entitet.
 *
 * Uvjet se ne kopira po modulima. Prije je bio prepisan u sest upita i svaka je
 * kopija bila prilika da se razidu — npr. da jedan modul uracuna varijacije
 * privatnih artikala a drugi ne.
 *
 * VAZNO: sve krece OD KATALOGA. Povijest cijena sadrzi i obrisane artikle (na
 * produkciji oko 630) koji u cjenik ne smiju uci. Zato se nikad ne krece od
 * povijesti prema katalogu.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Katalog {

	/** Statusi artikla koji se smatraju zivima. */
	const STATUSI = array( 'publish', 'private' );

	/** SQL uvjet, bez rijeci WHERE. Alias tablice posts mora biti `p`. */
	public static function uvjet(): string {
		global $wpdb;

		$statusi = "'" . implode( "','", self::STATUSI ) . "'";

		// Zagrade oko cjeline su obavezne — bez njih se dodatni AND uvjet veze
		// samo uz zadnji OR ogranak i upit tiho propusti pola kataloga.
		return "( ( p.post_type = 'product' AND p.post_status IN ({$statusi}) )
		       OR ( p.post_type = 'product_variation' AND p.post_parent IN (
		              SELECT ID FROM {$wpdb->posts}
		              WHERE post_type = 'product' AND post_status IN ({$statusi}) ) ) )";
	}

	/** Koliko ih ima. */
	public static function broj(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . self::uvjet() // phpcs:ignore
		);
	}

	/**
	 * Komad entiteta, keyset paginacijom.
	 *
	 * Nikad LIMIT/OFFSET: na zivom shopu se katalog mijenja tijekom posla, pa bi
	 * brisanje retka ispod trenutne pozicije pomaknulo niz i jedan entitet se
	 * nikad ne bi obradio. Kod cjenika je to artikl kojeg tiho nema u datoteci.
	 *
	 * @param int      $zadnji_id ID iza kojega se nastavlja; 0 na pocetku.
	 * @param int      $velicina  Koliko ih dohvatiti.
	 * @param string[] $stupci    Dodatni stupci iz posts tablice.
	 * @return object[]
	 */
	public static function komad( int $zadnji_id, int $velicina, array $stupci = array() ): array {
		global $wpdb;

		$dodatni = '';
		foreach ( $stupci as $s ) {
			$dodatni .= ', p.' . preg_replace( '/[^a-z_]/', '', $s );
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_parent, p.post_date{$dodatni}
				 FROM {$wpdb->posts} p
				 WHERE " . self::uvjet() . ' AND p.ID > %d
				 ORDER BY p.ID ASC
				 LIMIT %d',
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);
	}
}
