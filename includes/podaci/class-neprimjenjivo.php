<?php
/**
 * "Nije primjenjivo" — s razlogom, potpisom i datumom.
 *
 * ZASTO NIJE OBICNA ZASTAVICA
 *
 * Prazno-jer-nije-uneseno i prazno-jer-objektivno-ne-postoji dvije su razlicite
 * stvari. Prvo je ZADATAK: netko mora otici do police i prepisati barkod. Drugo je
 * ODLUKA trgovca: majica vlastite proizvodnje nema GTIN jer ga nitko nikad nije
 * dodijelio, i to nije propust nego cinjenica.
 *
 * Razlika je bitna na dva mjesta. U izvjestaju o nepotpunosti: bez nje popis
 * zadataka nikad ne dode do nule i prestane se citati. I pred inspekcijom: odluka
 * se moze obraniti, ali samo ako uz nju stoji razlog, tko ju je donio i kada.
 * Zastavica bez toga je pogadanje zabiljezeno kao cinjenica.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Neprimjenjivo {

	private static function tablica(): string {
		return Config::table( Config::TABLE_NEPRIMJENJIVO );
	}

	/**
	 * Oznaci polje kao neprimjenjivo.
	 *
	 * @param bool $automatski Je li oznaku postavilo pravilo, a ne covjek.
	 */
	public static function oznaci( int $entity_id, string $polje, string $razlog, bool $automatski = false, ?string $tko = null ): bool {
		global $wpdb;

		if ( ! isset( Config::POLJA[ $polje ] ) ) {
			return false;
		}

		if ( '' === trim( $razlog ) ) {
			// Oznaka bez razloga nije odluka nego pogadanje. Ne prima se.
			return false;
		}

		$tko = $tko ?? ( $automatski ? Config::POSTAVIO_POSAO : self::trenutni_korisnik() );

		$podaci = array(
			'entity_id'  => $entity_id,
			'polje'      => $polje,
			'razlog'     => mb_substr( $razlog, 0, 255 ),
			'automatski' => $automatski ? 1 : 0,
			'tko'        => $tko,
			'kad'        => current_time( 'mysql', true ),
		);

		$postoji = self::zapis( $entity_id, $polje );

		if ( $postoji ) {
			// Ljudska odluka se NE prepisuje automatskim pravilom. Pravilo zna manje
			// od covjeka koji je stajao pred artiklom.
			if ( $automatski && ! (int) $postoji->automatski ) {
				return false;
			}
			$wpdb->update( self::tablica(), $podaci, array( 'id' => (int) $postoji->id ) );
			return true;
		}

		return (bool) $wpdb->insert( self::tablica(), $podaci );
	}

	/** Skini oznaku. */
	public static function skini( int $entity_id, string $polje ): void {
		global $wpdb;

		$wpdb->delete(
			self::tablica(),
			array(
				'entity_id' => $entity_id,
				'polje'     => $polje,
			)
		);
	}

	/** @return object|null */
	public static function zapis( int $entity_id, string $polje ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . self::tablica() . '` WHERE entity_id = %d AND polje = %s',
				$entity_id,
				$polje
			) // phpcs:ignore
		);
	}

	public static function oznaceno( int $entity_id, string $polje ): bool {
		return null !== self::zapis( $entity_id, $polje );
	}

	/**
	 * Sve oznake za vise artikala odjednom.
	 *
	 * @param int[] $ids
	 * @return array<int,array<string,object>> entity_id => polje => zapis
	 */
	public static function za_vise( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$u   = implode( ',', array_map( 'intval', $ids ) );
		$out = array();

		foreach ( (array) $wpdb->get_results( 'SELECT * FROM `' . self::tablica() . "` WHERE entity_id IN ({$u})" ) as $r ) { // phpcs:ignore
			$out[ (int) $r->entity_id ][ $r->polje ] = $r;
		}

		return $out;
	}

	public static function broj( string $polje ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . self::tablica() . '` WHERE polje = %s', $polje ) // phpcs:ignore
		);
	}

	/**
	 * Skupine koje se oznacavaju automatski, s razlogom.
	 *
	 * SQL uvjet mora vratiti `p.ID`. Pravila su ovdje, a ne rasuta po poslovima, da
	 * se vidi cijeli popis odjednom — svako od njih je tvrdnja pred inspekcijom.
	 *
	 * @return array<string,array{polja:string[],razlog:string,uvjet:string}>
	 */
	public static function pravila(): array {
		global $wpdb;

		$sva_polja = array_keys( Config::POLJA );

		return array(
			'usluga' => array(
				'polja'  => array( Config::POLJE_BARKOD, Config::POLJE_KOLICINA ),
				'razlog' => __( 'Usluga nema pakiranje ni barkod — nije roba.', Config::TEXT_DOMAIN ),
				'uvjet'  => "p.post_type = 'product' AND EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr
					JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug IN ('service','booking','appointment') )",
			),
			'virtualni' => array(
				'polja'  => array( Config::POLJE_BARKOD, Config::POLJE_KOLICINA ),
				'razlog' => __( 'Virtualni proizvod nema fizicko pakiranje ni barkod.', Config::TEXT_DOMAIN ),
				'uvjet'  => "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} v
					WHERE v.post_id = p.ID AND v.meta_key = '_virtual' AND v.meta_value = 'yes' )",
			),
			'varijabilni_roditelj' => array(
				'polja'  => $sva_polja,
				'razlog' => __( 'Varijabilni proizvod se ne prodaje sam — u cjenik idu njegove varijante, svaka sa svojim podacima.', Config::TEXT_DOMAIN ),
				'uvjet'  => "p.post_type = 'product' AND EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr
					JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug = 'variable' )",
			),
			'rinfuza' => array(
				'polja'  => array( Config::POLJE_BARKOD ),
				'razlog' => __( 'Roba u rinfuzi nema barkod prodajne jedinice — vaze se na mjestu prodaje.', Config::TEXT_DOMAIN ),
				'uvjet'  => "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} r
					WHERE r.post_id = p.ID AND r.meta_key = '_cjtr_rinfuza' AND r.meta_value = 'yes' )",
			),
		);
	}

	private static function trenutni_korisnik(): string {
		$id = get_current_user_id();
		if ( ! $id ) {
			return Config::POSTAVIO_POSAO;
		}

		// Zapisuje se korisnicko IME, ne e-mail ni ime osobe — dovoljno da se zna
		// tko je odlucio, bez nosenja osobnih podataka kroz izvjestaje.
		$k = get_userdata( $id );
		return $k ? (string) $k->user_login : Config::POSTAVIO_POSAO;
	}
}
