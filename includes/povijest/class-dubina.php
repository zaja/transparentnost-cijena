<?php
/**
 * Koliko daleko unatrag seze evidencija o cijenama.
 *
 * STO OVO JEST, A STO NIJE
 *
 * Ovo je MJERA, ne brava. Sluzi da ekran moze reci koliko se daleko zna, i da
 * trgovac zna hoce li brojka biti puna ili racunata iz kraceg razdoblja.
 *
 * Ranije je bila brava: dok evidencija ne bi pokrila punih trideset dana, uz
 * cijenu se nije prikazivalo nista. To je bila POGRESNA odluka i ispravljena je
 * u 1.2.0 — obrazlozenje nize.
 *
 * ZASTO BRAVA NIJE BILA ISPRAVNA
 *
 * "Najniza cijena u 30 dana" nije tvrdnja da je cijena stara trideset dana. To
 * je najmanja cijena koja je u tom prozoru primijenjena. Ako je jedina koju smo
 * zabiljezili ona od jucer, onda je ona i najmanja u prozoru — druge nije bilo.
 *
 * Brava je uz to bila i stetna u prakticnom smislu: trgovina koja dodatak
 * instalira danas ostajala bi trideset dana BEZ obveznog podatka, i to tiho.
 *
 * STO OSTAJE KAO OGRADA
 *
 * Ako je artikl prije nase prve biljeske bio jeftiniji, to u brojci nije. Zato
 * dok evidencija ne pokrije puni prozor, ekran Stanje to izrijekom pise. Ograda
 * je na ekranu trgovca, ne u sutnji prema kupcu.
 *
 * ZASTO SE ZAPISI S `ts = 0` NE RACUNAJU U DUBINU
 *
 * Dnevna rekonsilijacija i uvezena povijest iz tudeg dodatka znaju imati zapis
 * bez pouzdanog pocetka — vrijednost znamo, ali ne i otkad vrijedi. Takav zapis
 * ULAZI u racun najnize (vrijednost je vrijednost), ali ne dokazuje dubinu: ne
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
	 * Najstariji trenutak do kojeg evidencija POUZDANO seze, kao Unix vrijeme.
	 *
	 * @return int 0 ako nema nijednog zapisa s poznatim pocetkom
	 */
	public static function seze_do(): int {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		$min = $wpdb->get_var( "SELECT MIN(ts) FROM `{$t}` WHERE ts > 0" ); // phpcs:ignore

		return ( null === $min ) ? 0 : (int) $min;
	}

	/**
	 * Ima li evidencija ijedan zapis.
	 *
	 * Razlicito od `seze_do() > 0`: nova instalacija nakon prve rekonsilijacije
	 * ima zapis o svakom artiklu, ali s `ts = 0` — vrijednost se zna, pocetak ne.
	 * Za prikaz je to dovoljno, za dubinu nije.
	 */
	public static function ima_zapisa(): bool {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		return null !== $wpdb->get_var( "SELECT 1 FROM `{$t}` LIMIT 1" ); // phpcs:ignore
	}

	/** Trenutak do kojeg bi morala sezati da prozor bude pun. */
	public static function treba_do(): int {
		return time() - self::DANA * DAY_IN_SECONDS;
	}

	/** Pokriva li evidencija puni prozor od 30 dana. */
	public static function dovoljna(): bool {
		$seze = self::seze_do();

		return $seze > 0 && $seze <= self::treba_do();
	}
}
