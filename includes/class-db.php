<?php
/**
 * Pisanje u bazu — jedno mjesto za vrijednosti koje smiju biti prazne.
 *
 * ZASTO POSTOJI
 *
 * `$wpdb->prepare( '%s', null )` ne daje SQL NULL nego PRAZAN STRING, koji u
 * DECIMAL koloni postane 0. Nula je tvrdnja da cijena iznosi nula; prazno je
 * izostanak tvrdnje. Kod dodatne cijene je ta razlika cijela poanta.
 *
 * Ta greska je napravljena DVAPUT — jednom u skripti za snapshot, jednom u
 * modulu 3 — iako je bila poznata. Zato vise ne ovisi o pamcenju: postoji jedna
 * funkcija za upis i provjera koja pukne ako se nula ipak pojavi.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Db {

	/**
	 * Cijena za upis u SQL: pravi NULL ili broj.
	 *
	 * Koristi se SVUGDJE gdje se pise cijena koja smije biti prazna. Vrijednost
	 * se ugraduje kao literal, pa se ne smije koristiti za korisnicki unos —
	 * ali cijena to nikad nije, uvijek je izvedena iz baze ili izracuna.
	 */
	public static function cijena( $v ): string {
		if ( null === $v || '' === $v ) {
			return 'NULL';
		}
		return (string) round( (float) $v, 4 );
	}

	/** Cijeli broj koji smije biti prazan. */
	public static function cijeli( $v ): string {
		if ( null === $v || '' === $v ) {
			return 'NULL';
		}
		return (string) (int) $v;
	}

	/** Tekst za upis: pravi NULL ili pripremljena vrijednost. */
	public static function tekst( $v ): string {
		global $wpdb;

		if ( null === $v ) {
			return 'NULL';
		}
		return $wpdb->prepare( '%s', (string) $v );
	}

	/**
	 * Provjeri da nijedno polje koje smije biti prazno nije zavrsilo kao nula.
	 *
	 * Nula u tim stupcima nije legitimna vrijednost — nijedan artikl nema dodatnu
	 * cijenu od 0, ni neto kolicinu 0. Pojavi li se, to je potpis ove greske.
	 *
	 * @return array<int,array{tablica:string,stupac:string,koliko:int,primjeri:string}>
	 */
	public static function provjeri_nule(): array {
		global $wpdb;

		$nalazi = array();

		foreach ( Config::POLJA_KOJA_SMIJU_BITI_PRAZNA as $logicka => $stupci ) {
			$tablica = Config::table( $logicka );

			if ( ! Installer::tablica_postoji( $tablica ) ) {
				continue;
			}

			foreach ( $stupci as $stupac ) {
				$stupac = preg_replace( '/[^a-z_]/', '', $stupac );

				$koliko = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM `{$tablica}` WHERE `{$stupac}` = 0" // phpcs:ignore
				);

				if ( 0 === $koliko ) {
					continue;
				}

				$primjeri = (array) $wpdb->get_col(
					"SELECT entity_id FROM `{$tablica}` WHERE `{$stupac}` = 0 LIMIT 5" // phpcs:ignore
				);

				$nalazi[] = array(
					'tablica'  => $tablica,
					'stupac'   => $stupac,
					'koliko'   => $koliko,
					'primjeri' => implode( ', ', $primjeri ),
				);
			}
		}

		return $nalazi;
	}

	/** Citljiv opis nalaza, za zapisnik posla. */
	public static function opis_nula( array $nalazi ): string {
		$dijelovi = array();
		foreach ( $nalazi as $n ) {
			$dijelovi[] = sprintf(
				/* translators: 1: stupac, 2: broj redaka, 3: primjeri ID-eva */
				__( '%1$s: %2$d redaka ima nulu umjesto prazne vrijednosti (npr. %3$s)', Config::TEXT_DOMAIN ),
				$n['stupac'],
				$n['koliko'],
				$n['primjeri']
			);
		}
		return implode( '; ', $dijelovi );
	}
}
