<?php
/**
 * Mapiranje kategorija trgovine na zakonske skupine.
 *
 * ZA OVAJ KATALOG JE PRAZNO — I TO JE TOCAN ISHOD
 *
 * Nijedan artikl nije iz reguliranih skupina (hrana, pice, kozmetika, sredstva za
 * ciscenje, toaletne potrepstine, kucanstvo), pa sve ide u "ostalo". Mehanizam
 * ipak postoji jer je za svakog drugog trgovca to glavni slucaj, a shema cjenika
 * trazi kategoriju kao obvezno polje.
 *
 * Mapiranje se cuva kao opcija, ne u tablici: jedan je po trgovini, mijenja se
 * rijetko i cita se pri svakom artiklu.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Kategorije {

	const OPT_MAPIRANJE = 'mapiranje_kategorija';

	/** @return array<int,string> term_id => kljuc zakonske kategorije */
	public static function mapiranje(): array {
		$v = get_option( Config::option( self::OPT_MAPIRANJE ), array() );
		return is_array( $v ) ? $v : array();
	}

	public static function spremi_mapiranje( array $mapiranje ): void {
		$cisto = array();

		foreach ( $mapiranje as $term_id => $kategorija ) {
			$term_id = (int) $term_id;
			if ( $term_id <= 0 ) {
				continue;
			}
			if ( ! isset( Config::ZAKONSKE_KATEGORIJE[ $kategorija ] ) ) {
				continue;
			}
			$cisto[ $term_id ] = (string) $kategorija;
		}

		update_option( Config::option( self::OPT_MAPIRANJE ), $cisto, false );
	}

	/**
	 * Zakonska kategorija artikla.
	 *
	 * Varijanta nema vlastite kategorije — nasljeduje roditeljeve, pa se gleda
	 * roditelj. Kad artikl pripada u vise mapiranih kategorija, uzima se NAJDUBLJA
	 * u stablu: "Karte za trikove" je odredenija tvrdnja od "Karte", i ako su obje
	 * mapirane, odredenija je bliza istini.
	 *
	 * @return string kljuc kategorije, ili prazno ako nije mapirano
	 */
	public static function za_artikl( int $entity_id ): string {
		$mapiranje = self::mapiranje();

		if ( empty( $mapiranje ) ) {
			return Config::KATEGORIJA_NIJE;
		}

		$post = get_post( $entity_id );
		if ( ! $post ) {
			return Config::KATEGORIJA_NIJE;
		}

		$izvor = ( 'product_variation' === $post->post_type ) ? (int) $post->post_parent : $entity_id;

		$termini = wp_get_post_terms( $izvor, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $termini ) || empty( $termini ) ) {
			return Config::KATEGORIJA_NIJE;
		}

		$najbolja = Config::KATEGORIJA_NIJE;
		$dubina   = -1;

		foreach ( $termini as $term_id ) {
			$term_id = (int) $term_id;
			if ( ! isset( $mapiranje[ $term_id ] ) ) {
				continue;
			}

			$d = count( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
			if ( $d > $dubina ) {
				$dubina   = $d;
				$najbolja = $mapiranje[ $term_id ];
			}
		}

		return $najbolja;
	}

	/**
	 * Kategorije trgovine, s dubinom, za ekran mapiranja.
	 *
	 * @return array<int,array{id:int,naziv:string,dubina:int,broj:int}>
	 */
	public static function stablo(): array {
		$termini = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $termini ) ) {
			return array();
		}

		$redci = array();

		foreach ( $termini as $t ) {
			$redci[] = array(
				'id'     => (int) $t->term_id,
				'naziv'  => (string) $t->name,
				'dubina' => count( get_ancestors( (int) $t->term_id, 'product_cat', 'taxonomy' ) ),
				'broj'   => (int) $t->count,
				'put'    => self::put( (int) $t->term_id ),
			);
		}

		usort(
			$redci,
			function ( $a, $b ) {
				return strcmp( $a['put'], $b['put'] );
			}
		);

		return $redci;
	}

	/** Puni put kategorije, radi sortiranja i citljivosti. */
	private static function put( int $term_id ): string {
		$dijelovi = array();

		foreach ( array_reverse( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) as $predak ) {
			$t = get_term( $predak, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$dijelovi[] = $t->name;
			}
		}

		$t = get_term( $term_id, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) {
			$dijelovi[] = $t->name;
		}

		return implode( ' / ', $dijelovi );
	}

	/** Koliko je kategorija mapirano, od ukupno. */
	public static function napredak(): array {
		return array(
			'mapirano' => count( self::mapiranje() ),
			'ukupno'   => count( self::stablo() ),
		);
	}
}
