<?php
/**
 * Upis dodatne cijene iz ljudskog izvora — uvoza, rucnog unosa, izjave.
 *
 * ZASTO ZASEBAN PUT
 *
 * Posao koji utvrduje dodatnu cijenu cita je iz zapisa o cijenama. Ovdje je ne
 * citamo nego je netko DONOSI: iz tablice svog programa, s racuna, ili iz vlastite
 * tvrdnje. To su razliciti izvori i moraju ostati razlikovni — inace za sest
 * mjeseci nitko ne zna je li brojka izmjerena ili pretpostavljena.
 *
 * NULA NE PROLAZI
 *
 * Nula je tvrdnja da cijena iznosi nula, a prazno je izostanak tvrdnje. Kod dodatne
 * cijene je ta razlika cijela poanta. Upis kroz `Db::cijena()` prazno pretvara u
 * pravi NULL, ali NE moze znati da je trgovac u stupac doista upisao `0` — a to je
 * jedini put kojim nula moze uci u bazu. Zato se odbija OVDJE, na ulazu, s
 * objasnjenjem, umjesto da se poslije trazi u provjeri nula.
 *
 * SLABIJI IZVOR NE PREPISUJE JACI
 *
 * Tablica iz ERP-a ne smije pregaziti vrijednost koju je netko provjerio i upisao
 * rukom, ni onu koja je opazena u zapisu. Smjer je uvijek prema gore.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;
use CJTR\Db;
use CJTR\Postavke;

defined( 'ABSPATH' ) || exit;

final class Sidrena_Unos {

	/**
	 * Pretvori zapis cijene iz tablice u broj.
	 *
	 * Prima sto god program izveze: `1.234,56`, `1,234.56`, `12,50 EUR`, `€ 12.50`.
	 * Format se ne bira postavkom nego prepoznaje po tome gdje stoji zadnji
	 * razdjelnik — postavka bi trazila da trgovac zna kako mu izgleda vlastita
	 * datoteka, a on je najcesce nije ni otvorio.
	 *
	 * @return string|null null ako se ne da procitati kao broj
	 */
	public static function broj( string $zapis ): ?string {
		$c = trim( $zapis );

		if ( '' === $c ) {
			return null;
		}

		// Valuta u celiji: makni sve osim znamenki, razdjelnika i minusa.
		$c = preg_replace( '/[^0-9,.\-]/u', '', $c );

		if ( '' === $c || '-' === $c ) {
			return null;
		}

		$zadnja_tocka  = strrpos( $c, '.' );
		$zadnji_zarez  = strrpos( $c, ',' );

		if ( false !== $zadnja_tocka && false !== $zadnji_zarez ) {
			// Oba postoje: zadnji je decimalni, prvi je razdjelnik tisuca.
			if ( $zadnji_zarez > $zadnja_tocka ) {
				$c = str_replace( '.', '', $c );
				$c = str_replace( ',', '.', $c );
			} else {
				$c = str_replace( ',', '', $c );
			}
		} elseif ( false !== $zadnji_zarez ) {
			// Samo zarez. Tri znamenke iza njega znace tisuce (1,234), inace decimale.
			$iza = strlen( $c ) - $zadnji_zarez - 1;
			$c   = ( 3 === $iza && substr_count( $c, ',' ) >= 1 && strlen( $c ) > 4 && false === strpos( $c, '.' ) && preg_match( '/^\-?\d{1,3}(,\d{3})+$/', $c ) )
				? str_replace( ',', '', $c )
				: str_replace( ',', '.', $c );
		}

		if ( ! is_numeric( $c ) ) {
			return null;
		}

		return $c;
	}

	/**
	 * Provjeri vrijednost prije upisa.
	 *
	 * @return array{ok:bool,vrijednost:string,poruka:string}
	 */
	public static function provjeri( string $zapis ): array {
		$broj = self::broj( $zapis );

		if ( null === $broj ) {
			return array(
				'ok'         => false,
				'vrijednost' => '',
				'poruka'     => sprintf(
					/* translators: %s = ono sto je bilo u celiji */
					__( '"%s" se ne moze procitati kao cijena.', Config::TEXT_DOMAIN ),
					mb_substr( $zapis, 0, 40 )
				),
			);
		}

		if ( (float) $broj < 0 ) {
			return array(
				'ok'         => false,
				'vrijednost' => '',
				'poruka'     => __( 'Cijena je negativna. Redak se preskace.', Config::TEXT_DOMAIN ),
			);
		}

		if ( 0.0 === (float) $broj ) {
			return array(
				'ok'         => false,
				'vrijednost' => '',
				'poruka'     => __( 'Cijena je nula. Nula znaci "artikl je besplatan", a prazna celija znaci "ne znamo" — to su razlicite tvrdnje. Ostavite celiju praznu ako vrijednost nemate.', Config::TEXT_DOMAIN ),
			);
		}

		return array(
			'ok'         => true,
			'vrijednost' => $broj,
			'poruka'     => '',
		);
	}

	/**
	 * Upisi dodatnu cijenu.
	 *
	 * @param string $izvor    Jedan od Config::IZVOR_* za sidrenu.
	 * @param string $biljeska Odakle vrijednost dolazi, npr. ime datoteke.
	 * @return array{ok:bool,poruka:string}
	 */
	public static function upisi( int $entity_id, string $vrijednost, string $izvor, string $biljeska = '' ): array {
		global $wpdb;

		$provjera = self::provjeri( $vrijednost );

		if ( ! $provjera['ok'] ) {
			return array(
				'ok'     => false,
				'poruka' => $provjera['poruka'],
			);
		}

		$postojeci = self::postojeci_izvor( $entity_id );

		if ( '' !== $postojeci && ! self::smije_prepisati( $izvor, $postojeci ) ) {
			return array(
				'ok'     => false,
				'poruka' => sprintf(
					/* translators: %s = opis postojeceg izvora */
					__( 'Vec postoji vrijednost iz jaceg izvora (%s) i ne prepisuje se.', Config::TEXT_DOMAIN ),
					Config::OPIS_IZVORA[ $postojeci ]['naslov'] ?? $postojeci
				),
			);
		}

		$tablica = Config::table( Config::TABLE_PODACI );
		$snaga   = ( Config::IZVOR_IZJAVA_TRGOVCA === $izvor ) ? Config::SNAGA_IZJAVA : Config::SNAGA_OPAZENO;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$tablica}`
				  ( entity_id, sidrena_cijena, sidrena_izvor, dokazna_snaga, referentni_datum,
				    sidrena_postavio, sidrena_postavljeno, sidrena_biljeska, zahtijeva_odluku, azurirano )
				 VALUES ( %d, " . Db::cijena( $provjera['vrijednost'] ) . ", %s, %s, %s, %s, %s, %s, 0, %s )
				 ON DUPLICATE KEY UPDATE
				   sidrena_cijena      = VALUES(sidrena_cijena),
				   sidrena_izvor       = VALUES(sidrena_izvor),
				   dokazna_snaga       = VALUES(dokazna_snaga),
				   referentni_datum    = VALUES(referentni_datum),
				   sidrena_postavio    = VALUES(sidrena_postavio),
				   sidrena_postavljeno = VALUES(sidrena_postavljeno),
				   sidrena_biljeska    = VALUES(sidrena_biljeska),
				   zahtijeva_odluku    = 0,
				   azurirano           = VALUES(azurirano)",
				$entity_id,
				$izvor,
				$snaga,
				Postavke::ref_datum( self::kategorija( $entity_id ) ),
				self::tko(),
				current_time( 'mysql', true ),
				mb_substr( $biljeska, 0, 255 ),
				current_time( 'mysql', true )
			) // phpcs:ignore
		);

		return array(
			'ok'     => true,
			'poruka' => '',
		);
	}

	/* ------------------------------------------------------------------- interno */

	private static function postojeci_izvor( int $entity_id ): string {
		global $wpdb;

		$v = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT sidrena_izvor FROM `' . Config::table( Config::TABLE_PODACI ) . '`
				 WHERE entity_id = %d AND sidrena_cijena IS NOT NULL',
				$entity_id
			) // phpcs:ignore
		);

		return ( null === $v ) ? '' : (string) $v;
	}

	/** Smije li novi izvor prepisati postojeci. */
	public static function smije_prepisati( string $novi, string $postojeci ): bool {
		$s_novi      = Config::SNAGA_IZVORA_SIDRENE[ $novi ] ?? 0;
		$s_postojeci = Config::SNAGA_IZVORA_SIDRENE[ $postojeci ] ?? 0;

		// Rucni unos nema snagu u tablici jer nije automatski izvor — on je covjek,
		// i prepisuje ga samo drugi covjek. Uvoz ga ne dira.
		if ( Config::IZVOR_RUCNI_UNOS === $postojeci ) {
			return Config::IZVOR_UVOZ !== $novi && Config::IZVOR_IZJAVA_TRGOVCA !== $novi;
		}

		return $s_novi >= $s_postojeci;
	}

	private static function kategorija( int $entity_id ): string {
		global $wpdb;

		$v = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT zakonska_kategorija FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$entity_id
			) // phpcs:ignore
		);

		return ( null === $v ) ? '' : (string) $v;
	}

	private static function tko(): string {
		$id = get_current_user_id();

		if ( ! $id ) {
			return Config::POSTAVIO_POSAO;
		}

		// Korisnicko IME, ne e-mail ni ime osobe — dovoljno da se zna tko je odlucio.
		$k = get_userdata( $id );

		return $k ? (string) $k->user_login : Config::POSTAVIO_POSAO;
	}
}
