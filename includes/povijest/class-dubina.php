<?php
/**
 * Koliko daleko unatrag seze evidencija o cijenama.
 *
 * ZASTO SE TO UOPCE MJERI
 *
 * Dodatak instaliran prije deset dana ne smije tvrditi da zna najnizu cijenu u
 * trideset. To nije priblizno tocna tvrdnja nego netocna — i bas ona koju propis
 * trazi da bude tocna.
 *
 * Ista disciplina kao kod dodatne cijene: ne tvrdi ono sto ne znas.
 *
 * MJERI SE PO ARTIKLU, JER JE I PROPIS PO ARTIKLU
 *
 * Za proizvod koji se prodaje krace od trideset dana istice se najniza cijena od
 * kad je u prodaji. Zato uvjet nije "imamo trideset dana podataka" nego "nasa
 * evidencija seze barem do trenutka od kojeg se za TAJ artikl racuna".
 *
 * ZASTO SE ZAPISI S `ts = 0` NE RACUNAJU
 *
 * Uvezena povijest iz tudeg dodatka zna imati zapis bez pouzdanog pocetka —
 * vrijednost znamo, ali ne i otkad vrijedi. Takav zapis ne dokazuje dubinu: ne
 * moze reci je li cijena prije njega bila niza.
 *
 * @package CJTR
 */

namespace CJTR\Povijest;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Dubina {

	/** Koliko dana unatrag propis trazi. */
	const DANA = 30;

	/**
	 * Najstariji trenutak do kojeg evidencija seze, kao Unix vrijeme.
	 *
	 * @return int 0 ako evidencije nema
	 */
	public static function seze_do(): int {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		$min = $wpdb->get_var( "SELECT MIN(ts) FROM `{$t}` WHERE ts > 0" ); // phpcs:ignore

		return ( null === $min ) ? 0 : (int) $min;
	}

	/** Trenutak do kojeg bi morala sezati da se smije tvrditi najniza u 30 dana. */
	public static function treba_do(): int {
		return time() - self::DANA * DAY_IN_SECONDS;
	}

	/** Je li evidencija dovoljno duboka za bilo koji artikl. */
	public static function dovoljna(): bool {
		$seze = self::seze_do();

		return $seze > 0 && $seze <= self::treba_do();
	}

	/**
	 * Kad prikaz moze poceti, ako danas jos ne moze.
	 *
	 * @return int Unix vrijeme, ili 0 ako vec moze ili evidencije uopce nema
	 */
	public static function pocinje(): int {
		$seze = self::seze_do();

		if ( 0 === $seze || self::dovoljna() ) {
			return 0;
		}

		return $seze + self::DANA * DAY_IN_SECONDS;
	}

	/** Koliko dana jos treba cekati. */
	public static function dana_do_pocetka(): int {
		$p = self::pocinje();

		if ( 0 === $p ) {
			return 0;
		}

		return (int) max( 0, ceil( ( $p - time() ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Smije li se za ovaj artikl tvrditi najniza cijena u 30 dana.
	 *
	 * Artikl uveden prije pet dana ima prozor od pet dana, i nasa ga evidencija
	 * pokriva cim smo ga vidjeli od pocetka.
	 */
	public static function pokriven( int $entity_id ): bool {
		global $wpdb;

		$granica = self::treba_do();

		$nastao = get_post_time( 'U', true, $entity_id );
		if ( $nastao && $nastao > $granica ) {
			$granica = (int) $nastao;
		}

		$t = Config::table( Config::TABLE_POVIJEST );

		$ima = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM `{$t}` WHERE entity_id = %d AND ts > 0 AND ts <= %d LIMIT 1",
				$entity_id,
				$granica
			) // phpcs:ignore
		);

		return null !== $ima;
	}
}
