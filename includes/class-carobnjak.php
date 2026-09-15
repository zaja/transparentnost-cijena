<?php
/**
 * Carobnjak za prvo postavljanje.
 *
 * MJERILO
 *
 * Netko tko je danas instalirao dodatak mora doci od nule do objavljenog cjenika
 * bez citanja dokumentacije i bez ijednog pitanja. Mora li otvoriti dokumentaciju,
 * sucelje nije gotovo.
 *
 * SVAKI KORAK KAZE ZASTO, NE SAMO STO
 *
 * "Unesite referentni datum" je naredba koju korisnik izvrsi ne znajuci sto radi, i
 * pogrijesi cim se nesto razlikuje od pretpostavljenog. "Propis trazi da uz cijenu
 * stoji koliko je artikl stajao na odredeni dan; za vecinu proizvoda to je 10.9.2026."
 * je isto pitanje na koje se moze odgovoriti.
 *
 * NESTAJE, ALI SE NE GUBI
 *
 * Nakon zavrsetka izlazi iz izbornika. Ostaje dostupan iz Naprednog, jer se trgovina
 * proda, administrator promijeni, i netko za godinu dana treba ponoviti isti put.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Carobnjak {

	const OPT_GOTOV = 'carobnjak_gotov';
	const OPT_KORAK = 'carobnjak_korak';

	const KORAK_POSLUZITELJ = 1;
	const KORAK_CRON        = 2;
	const KORAK_DATUM       = 3;
	const KORAK_CIJENE      = 4;

	const ZADNJI = self::KORAK_CIJENE;

	public static function gotov(): bool {
		return (bool) get_option( Config::option( self::OPT_GOTOV ), false );
	}

	public static function zavrsi(): void {
		update_option( Config::option( self::OPT_GOTOV ), true, false );
	}

	/** Ponovno otvaranje iz Naprednog. */
	public static function ponovi(): void {
		delete_option( Config::option( self::OPT_GOTOV ) );
		self::spremi_korak( self::KORAK_POSLUZITELJ );
	}

	public static function korak(): int {
		$k = (int) get_option( Config::option( self::OPT_KORAK ), self::KORAK_POSLUZITELJ );

		return max( self::KORAK_POSLUZITELJ, min( self::ZADNJI, $k ) );
	}

	public static function spremi_korak( int $korak ): void {
		update_option( Config::option( self::OPT_KORAK ), max( 1, min( self::ZADNJI, $korak ) ), false );
	}

	/** @return array<int,array{naslov:string,zasto:string}> */
	public static function koraci(): array {
		return array(
			self::KORAK_POSLUZITELJ => array(
				'naslov' => __( 'Radi li sve na vasem posluzitelju', Config::TEXT_DOMAIN ),
				'zasto'  => __( 'Cjenik nastaje kao datoteka i objavljuje se svaki dan sam od sebe. Prije nego to obecamo, provjeravamo moze li vas posluzitelj to izvesti — da se ne pokaze tek za mjesec dana da ne moze.', Config::TEXT_DOMAIN ),
			),
			self::KORAK_CRON        => array(
				'naslov' => __( 'Dnevno pokretanje', Config::TEXT_DOMAIN ),
				'zasto'  => __( 'WordPress sam po sebi nista ne pokrece u odredeni sat — ceka da netko posjeti trgovinu. Na trgovini s nekoliko posjeta dnevno to znaci da cjenik izade kad izade. Ovo je jedini korak koji se ne moze odraditi iz WordPressa.', Config::TEXT_DOMAIN ),
			),
			self::KORAK_DATUM       => array(
				'naslov' => __( 'Koji je vas referentni datum', Config::TEXT_DOMAIN ),
				'zasto'  => __( 'Uz cijenu se mora istaknuti koliko je artikl stajao na jedan odredeni dan. Taj dan nije isti za sve proizvode, i to je najcesci nacin da se pogrijesi — trgovac koji prodaje i hranu i sve ostalo ima dva datuma, a najcesce za to ne zna.', Config::TEXT_DOMAIN ),
			),
			self::KORAK_CIJENE      => array(
				'naslov' => __( 'Odakle dolaze sidrene cijene', Config::TEXT_DOMAIN ),
				'zasto'  => __( 'Ovo je jedini korak koji se ne moze preskociti. Bez sidrenih cijena dodatak nema sto prikazati ni objaviti — sve ostalo je priprema za ovo.', Config::TEXT_DOMAIN ),
			),
		);
	}

	/**
	 * Koliko artikala jos nema dodatnu cijenu.
	 *
	 * Cita se u koraku 4, za izjavu trgovca: brojka mora biti u recenici koju
	 * potvrduje, a ne negdje drugdje na ekranu.
	 */
	public static function bez_dodatne(): int {
		global $wpdb;

		$t = Config::table( Config::TABLE_PODACI );

		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN `{$t}` c ON c.entity_id = p.ID
			 WHERE " . Katalog::uvjet() . '
			   AND ( c.sidrena_cijena IS NULL )' // phpcs:ignore
		);
	}

	/**
	 * Je li pronadena tuda tablica povijesti cijena.
	 *
	 * @return array{ima:bool,dodatak:string,zapisa:int,entiteta:int,od:string}
	 */
	public static function tuda_povijest(): array {
		$prazno = array(
			'ima'      => false,
			'dodatak'  => '',
			'zapisa'   => 0,
			'entiteta' => 0,
			'od'       => '',
		);

		$nadeno = Cijene\Povijest_Cijena::dostupna();

		if ( empty( $nadeno['ima'] ) ) {
			return $prazno;
		}

		return array(
			'ima'      => true,
			'dodatak'  => (string) ( $nadeno['dodatak'] ?? '' ),
			'zapisa'   => (int) ( $nadeno['zapisa'] ?? 0 ),
			'entiteta' => (int) ( $nadeno['entiteta'] ?? 0 ),
			'od'       => (string) ( $nadeno['od'] ?? '' ),
		);
	}
}
