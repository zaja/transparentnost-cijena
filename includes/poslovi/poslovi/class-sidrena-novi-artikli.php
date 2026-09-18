<?php
/**
 * Posao: sidrena cijena za artikle koji su u trgovinu usli poslije.
 *
 * ZASTO POSTOJI KAO ZASEBAN POSAO
 *
 * "Utvrdi sidrenu cijenu za sve artikle" je korak pripreme. Prode katalog jednom
 * i stane. Artikl koji nastane POSLIJE toga nema retka ni o cemu — ni cijene, ni
 * oznake, ni traga da ga je itko pogledao.
 *
 * Posljedica je bila tisina koja izgleda kao uredno stanje: uz cijenu se ne
 * prikazuje nista, u cjeniku polje ostaje prazno, a nijedan ekran to ne javlja.
 * Trgovac koji svaki tjedan doda dvadeset artikala tako svaki tjedan dobiva
 * dvadeset praznih polja u obveznoj objavi, i ne sazna.
 *
 * Ovaj posao ide SAMO za onima kojih u nasoj tablici jos nema. Na trgovini u
 * kojoj se nista nije mijenjalo ne radi nista i zavrsi odmah.
 *
 * STO UPISUJE
 *
 * Artikl uveden nakon referentnog datuma → sidrena cijena je prva cijena po kojoj
 * je ponuden, a referentni datum tog retka je datum kad je formirana. Puno
 * obrazlozenje i podrijetlo tumacenja stoje uz `Config::IZVOR_PRVA_CIJENA`.
 *
 * Artikl stariji od referentnog datuma, a bez retka, nije slucaj za ovaj posao:
 * njemu sidrenu cijenu treba potraziti u povijesti, sto radi veliki posao. Ovdje
 * dobiva redak koji kaze da ceka, da ne bi ostao nevidljiv.
 *
 * ZASTO NE CEKA SUTRA UJUTRO
 *
 * Dnevni prolaz je mreza, ne glavni put. Artikl objavljen u podne inace ne bi imao
 * sidrenu cijenu do 05:30 sljedeceg dana — obvezni podatak bi na stranici falio
 * pola dana, i to bez ijedne poruke.
 *
 * Zato se isti racun radi i ODMAH, na kraju zahtjeva u kojem je artikl nastao.
 * Kasni se za biljeznikom (`shutdown` 5), jer se prva cijena cita iz zapisa koji
 * on upravo pise. Kosta jedan upit, i to samo u zahtjevu koji je stvorio proizvod.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Db;
use CJTR\Katalog;
use CJTR\Postavke;
use CJTR\Povijest\Zapis;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;

defined( 'ABSPATH' ) || exit;

final class Sidrena_Novi_Artikli extends Posao {

	/** @var array<int,bool> artikli nastali u ovom zahtjevu */
	private static $novi = array();

	/** @var bool */
	private static $zakazano = false;

	public static function init(): void {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'zapamti' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'zapamti' ) );
	}

	/**
	 * Zapamti novi artikl; racun ide na kraj zahtjeva.
	 *
	 * @param int $id
	 */
	public static function zapamti( $id ): void {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return;
		}

		self::$novi[ $id ] = true;

		if ( self::$zakazano ) {
			return;
		}

		self::$zakazano = true;

		// Prioritet 15: biljeznik pise na 5, a nama treba zapis koji on upisuje.
		add_action( 'shutdown', array( __CLASS__, 'odmah' ), 15 );
	}

	/** Klasificiraj artikle nastale u ovom zahtjevu. */
	public static function odmah(): void {
		$novi       = array_keys( self::$novi );
		self::$novi = array();

		foreach ( $novi as $id ) {
			self::za_artikl( (int) $id );
		}
	}

	/**
	 * Sidrena cijena za jedan artikl koji jos nema redak.
	 *
	 * @return bool je li redak nastao
	 */
	public static function za_artikl( int $id ): bool {
		global $wpdb;

		$nastao = get_post_field( 'post_date', $id );

		if ( ! $nastao ) {
			return false;
		}

		$ima = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$id
			) // phpcs:ignore
		);

		if ( null !== $ima ) {
			return false;
		}

		return self::upisi( $id, (string) $nastao, Postavke::ref_datum(), Zapis::prvi( $id ) );
	}

	public function kljuc(): string {
		return 'sidrena_novi';
	}

	public function naziv(): string {
		return __( 'Sidrena cijena za novododane artikle', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Prolazi samo kroz artikle koje sidrena cijena jos nije obuhvatila — obicno one dodane u zadnjih nekoliko dana. Artiklu uvedenom nakon referentnog datuma upisuje prvu cijenu po kojoj je ponuden, uz datum kad je formirana. Radi se samo od sebe jednom dnevno. Cijene u trgovini se NE mijenjaju.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_ODRZAVANJE;
	}

	/**
	 * Poslije rekonsilijacije, prije objave cjenika.
	 *
	 * Rekonsilijacija u 05:00 zabiljezi zatecene cijene artikala koji su promakli
	 * sloju 1. Tek nakon nje se za njih moze reci koja im je prva cijena.
	 */
	public function dnevno(): string {
		return '05:30';
	}

	public function ukupno(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $this->upit( 'COUNT(*)' ) ); // phpcs:ignore
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		$redci = (array) $wpdb->get_results(
			$wpdb->prepare(
				$this->upit( 'p.ID, p.post_date' ) . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d',
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		if ( empty( $redci ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids = array();
		foreach ( $redci as $r ) {
			$ids[] = (int) $r->ID;
		}

		$rez = Rezultat_Komada::s( count( $redci ), max( $ids ) );

		// Prvi zapisi za cijeli komad odjednom — inace jedan upit po artiklu.
		$prvi = Zapis::prvi_za( $ids );

		/*
		 * Artikl bez retka nema ni zakonsku skupinu, pa vrijedi opci datum. To nije
		 * propust: skupina se dodjeljuje poslije, a kad se dodijeli, veliki posao
		 * redak ionako prepisuje.
		 */
		$ref_opci = Postavke::ref_datum();

		foreach ( $redci as $r ) {
			self::upisi( (int) $r->ID, (string) $r->post_date, $ref_opci, $prvi[ (int) $r->ID ] ?? null );
		}

		return $rez;
	}

	/* --------------------------------------------------------------- pomocno */

	/** Artikli iz kataloga kojih u nasoj tablici jos nema. */
	private function upit( string $select ): string {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );

		return "SELECT {$select}
			FROM {$wpdb->posts} p
			LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
			WHERE " . Katalog::uvjet() . '
			  AND c.entity_id IS NULL';
	}

	/**
	 * @param object|null $prvi prvi zapis o cijeni, ako ga ima
	 */
	private static function upisi( int $id, string $nastao, string $ref_opci, $prvi ): bool {
		global $wpdb;

		$sada = current_time( 'mysql', true );

		$cijena    = null;
		$izvor     = Config::IZVOR_RUCNI_UNOS;
		$snaga     = Config::SNAGA_NEMA;
		$ref_datum = $ref_opci;
		$biljeska  = __( 'artikl jos nije obuhvacen utvrdivanjem sidrene cijene', Config::TEXT_DOMAIN );

		if ( Config::nastao_nakon_ref_datuma( $nastao, $ref_opci ) ) {
			if ( $prvi && null !== $prvi->price ) {
				$cijena    = (float) $prvi->price;
				$izvor     = Config::IZVOR_PRVA_CIJENA;
				$snaga     = Config::SNAGA_OPAZENO;
				$ref_datum = wp_date( 'Y-m-d', (int) $prvi->ts );
				$biljeska  = sprintf(
					/* translators: %s = datum uvodenja */
					__( 'prva cijena po kojoj je artikl ponuden, formirana %s', Config::TEXT_DOMAIN ),
					wp_date( 'd.m.Y.', (int) $prvi->ts )
				);
			} else {
				$izvor     = Config::IZVOR_NAKON_REF_DATUMA;
				$ref_datum = wp_date( 'Y-m-d', (int) strtotime( $nastao ) );
				$biljeska  = sprintf(
					/* translators: %s = datum uvodenja */
					__( 'uveden %s, nakon referentnog datuma; pocetnu cijenu nismo zabiljezili', Config::TEXT_DOMAIN ),
					wp_date( 'd.m.Y.', strtotime( $nastao ) )
				);
			}
		}

		$tablica = Config::table( Config::TABLE_PODACI );

		/*
		 * INSERT IGNORE, ne REPLACE: ako je redak u meduvremenu nastao (veliki posao,
		 * rucni unos), on je stariji izvor istine i ne smije se prepisati.
		 */
		$wpdb->query(
			"INSERT IGNORE INTO `{$tablica}`
				( entity_id, sidrena_cijena, sidrena_izvor, opazeno_na_datum, dokazna_snaga,
				  referentni_datum, sidrena_postavio, sidrena_postavljeno, sidrena_biljeska, azurirano )
			 VALUES ( " . implode(
				',',
				array(
					$id,
					Db::cijena( $cijena ),
					Db::tekst( $izvor ),
					Db::tekst( $ref_datum ),
					Db::tekst( $snaga ),
					Db::tekst( $ref_datum ),
					Db::tekst( Config::POSTAVIO_POSAO ),
					Db::tekst( $sada ),
					Db::tekst( $biljeska ),
					Db::tekst( $sada ),
				)
			) . ' )' // phpcs:ignore
		);

		return $wpdb->rows_affected > 0;
	}
}
