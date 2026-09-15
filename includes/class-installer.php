<?php
/**
 * Aktivacija, shema i migracija naslijedenih tablica.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function aktivacija(): void {
		self::migriraj();
		update_option( Config::option( Config::OPT_AKTIVIRANO ), current_time( 'mysql' ) );
	}

	public static function deaktivacija(): void {
		// Namjerno prazno: tablice i podaci ostaju. Brisanje ide preko uninstall.php.
	}

	/** Pokrece se i pri aktivaciji i pri svakom ucitavanju ako je verzija sheme starija. */
	public static function migriraj(): void {
		$trenutna = (int) get_option( Config::option( Config::OPT_DB_VERSION ), 0 );

		if ( $trenutna >= Config::DB_VERSION ) {
			return;
		}

		self::preimenuj_naslijedene();
		self::kreiraj_tablice();
		self::ukloni_napustene_stupce();

		update_option( Config::option( Config::OPT_DB_VERSION ), Config::DB_VERSION );
	}

	/**
	 * Preimenuje tablice iz faze prije plugina u imena s prefiksom.
	 *
	 * Bez gubitka podataka: RENAME TABLE cuva sadrzaj i indekse. Preimenuje se samo
	 * ako naslijedena tablica postoji A nova jos ne postoji — tako je operacija
	 * idempotentna i ne moze prepisati vec migrirane podatke.
	 *
	 * @return array<string,string> izvjestaj: staro_ime => ishod
	 */
	public static function preimenuj_naslijedene(): array {
		global $wpdb;
		$izvjestaj = array();

		foreach ( Config::NASLIJEDENE_TABLICE as $staro => $logicko ) {
			$staro_puno = Config::naslijedena_table( $staro );
			$novo_puno  = Config::table( $logicko );

			$staro_postoji = self::tablica_postoji( $staro_puno );
			$novo_postoji  = self::tablica_postoji( $novo_puno );

			if ( ! $staro_postoji ) {
				$izvjestaj[ $staro_puno ] = $novo_postoji ? 'vec migrirano' : 'nema je';
				continue;
			}

			if ( $novo_postoji ) {
				// Obje postoje — ne diramo nista, to trazi ljudsku odluku.
				$izvjestaj[ $staro_puno ] = 'SUKOB: postoje i stara i nova, nista nije promijenjeno';
				continue;
			}

			$prije = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$staro_puno}`" ); // phpcs:ignore
			$ok    = false !== $wpdb->query( "RENAME TABLE `{$staro_puno}` TO `{$novo_puno}`" ); // phpcs:ignore

			if ( ! $ok ) {
				$izvjestaj[ $staro_puno ] = 'GRESKA: ' . $wpdb->last_error;
				continue;
			}

			$poslije = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$novo_puno}`" ); // phpcs:ignore
			$izvjestaj[ $staro_puno ] = sprintf(
				'preimenovano u %s (%d redaka prije, %d poslije)%s',
				$novo_puno,
				$prije,
				$poslije,
				$prije === $poslije ? '' : ' — NEPODUDARANJE!'
			);
		}

		return $izvjestaj;
	}

	/** Kreira tablice ako ne postoje. Ne dira postojece podatke. */
	private static function kreiraj_tablice(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate  = $wpdb->get_charset_collate();
		$podaci   = Config::table( Config::TABLE_PODACI );
		$np       = Config::table( Config::TABLE_NEPRIMJENJIVO );
		$sukobi   = Config::table( Config::TABLE_SUKOBI );
		$snapshot = Config::table( Config::TABLE_SNAPSHOT );

		dbDelta(
			"CREATE TABLE {$podaci} (
				entity_id                  BIGINT UNSIGNED NOT NULL,
				barkod                     VARCHAR(20)   NULL,
				barkod_izvor               VARCHAR(40)   NULL,
				barkod_status              VARCHAR(20)   NULL,
				marka                      VARCHAR(100)  NULL,
				marka_izvor                VARCHAR(40)   NULL,
				neto_kolicina              DECIMAL(12,4) NULL,
				jedinica_mjere             VARCHAR(10)   NULL,
				kolicina_izvor             VARCHAR(40)   NULL,
				zakonska_kategorija        VARCHAR(20)   NULL,
				kategorija_izvor           VARCHAR(40)   NULL,
				sidrena_cijena             DECIMAL(12,4) NULL,
				sidrena_kandidat_regular   DECIMAL(12,4) NULL,
				sidrena_kandidat_efektivna DECIMAL(12,4) NULL,
				zahtijeva_odluku           TINYINT(1)    NOT NULL DEFAULT 0,
				sidrena_izvor              VARCHAR(40)   NULL,
				opazeno_na_datum           DATE          NULL,
				dokazna_snaga              VARCHAR(20)   NULL,
				pocetak_pouzdan            TINYINT(1)    NOT NULL DEFAULT 1,
				bio_na_akciji              TINYINT(1)    NOT NULL DEFAULT 0,
				referentni_datum           DATE          NOT NULL,
				sidrena_postavio           VARCHAR(60)   NULL,
				sidrena_postavljeno        DATETIME      NULL,
				sidrena_biljeska           VARCHAR(255)  NULL,
				azurirano                  DATETIME      NOT NULL,
				PRIMARY KEY  (entity_id),
				KEY idx_izvor (sidrena_izvor),
				KEY idx_odluka (zahtijeva_odluku),
				KEY idx_barkod (barkod),
				KEY idx_marka (marka)
			) {$collate};"
		);

		$poslovi = Config::table( Config::TABLE_POSLOVI );
		$log     = Config::table( Config::TABLE_POSAO_LOG );

		dbDelta(
			"CREATE TABLE {$poslovi} (
				kljuc          VARCHAR(64)  NOT NULL,
				status         VARCHAR(20)  NOT NULL,
				ukupno         INT UNSIGNED NOT NULL DEFAULT 0,
				obradeno       INT UNSIGNED NOT NULL DEFAULT 0,
				preskoceno     INT UNSIGNED NOT NULL DEFAULT 0,
				gresaka        INT UNSIGNED NOT NULL DEFAULT 0,
				zadnji_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
				zakljucano_do  DATETIME     NULL,
				pokrenuto      DATETIME     NULL,
				azurirano      DATETIME     NULL,
				zavrseno       DATETIME     NULL,
				ponisteno      DATETIME     NULL,
				ponistio       VARCHAR(64)  NULL,
				poruka         VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (kljuc)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				kljuc    VARCHAR(64)  NOT NULL,
				razina   VARCHAR(20)  NOT NULL,
				odmak    INT UNSIGNED NOT NULL DEFAULT 0,
				poruka   VARCHAR(500) NOT NULL DEFAULT '',
				zapisano DATETIME     NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_kljuc (kljuc)
			) {$collate};"
		);

		$trag     = Config::table( Config::TABLE_TRAG );
		$povijest = Config::table( Config::TABLE_POVIJEST );

		dbDelta(
			"CREATE TABLE {$povijest} (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_id       BIGINT UNSIGNED NOT NULL,
				regular_price   DECIMAL(12,4) NULL,
				sale_price      DECIMAL(12,4) NULL,
				price           DECIMAL(12,4) NULL,
				ts              BIGINT UNSIGNED NOT NULL DEFAULT 0,
				ts_end          BIGINT UNSIGNED NOT NULL DEFAULT 0,
				okidac          VARCHAR(20)  NOT NULL,
				vrsta_pop       VARCHAR(20)  NOT NULL DEFAULT 'nema',
				pocetak_pouzdan TINYINT(1)   NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY idx_entitet (entity_id, ts_end),
				KEY idx_ts (ts)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$trag} (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_id    BIGINT UNSIGNED NOT NULL,
				operacija    VARCHAR(40)  NOT NULL,
				stanje_prije LONGTEXT     NULL,
				razlog       VARCHAR(255) NOT NULL DEFAULT '',
				zapisano     DATETIME     NOT NULL,
				vraceno      DATETIME     NULL,
				PRIMARY KEY  (id),
				KEY idx_operacija (operacija, entity_id),
				KEY idx_vraceno (vraceno)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$np} (
				id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_id BIGINT UNSIGNED NOT NULL,
				polje     VARCHAR(30)  NOT NULL,
				razlog    VARCHAR(255) NOT NULL DEFAULT '',
				automatski TINYINT(1)  NOT NULL DEFAULT 0,
				tko       VARCHAR(60)  NULL,
				kad       DATETIME     NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idx_entitet_polje (entity_id, polje),
				KEY idx_polje (polje)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$sukobi} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_id  BIGINT UNSIGNED NOT NULL,
				polje      VARCHAR(30)  NOT NULL,
				izvor_a    VARCHAR(40)  NOT NULL,
				vrijednost_a VARCHAR(255) NOT NULL DEFAULT '',
				izvor_b    VARCHAR(40)  NOT NULL,
				vrijednost_b VARCHAR(255) NOT NULL DEFAULT '',
				upisan     VARCHAR(40)  NOT NULL DEFAULT '',
				rijesen    DATETIME     NULL,
				zapisano   DATETIME     NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idx_entitet_polje (entity_id, polje),
				KEY idx_rijesen (rijesen)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$snapshot} (
				entity_id                    BIGINT UNSIGNED NOT NULL,
				snapshot_datum               DATE          NOT NULL,
				regular_price                DECIMAL(12,4) NULL,
				price                        DECIMAL(12,4) NULL,
				trajanje_tekuce_cijene_dana  INT           NULL,
				ts_pocetka_tekuceg_intervala DATETIME      NULL,
				ts                           DATETIME      NOT NULL,
				PRIMARY KEY  (entity_id, snapshot_datum)
			) {$collate};"
		);
	}

	/**
	 * Ukloni stupce koje shema vise ne koristi.
	 *
	 * `dbDelta` dodaje i mijenja stupce, ali NIKAD ne brise — napusten stupac ostane
	 * u tablici zauvijek. Sam po sebi ne smeta, ali `barkod_np` koji vise nitko ne
	 * pise izgleda kao podatak, a nije: "nije primjenjivo" se od verzije 8 vodi u
	 * vlastitoj tablici, s razlogom i potpisom. Stupac koji tiho stoji na nuli
	 * tvrdio bi da nijedan artikl nema tu oznaku.
	 *
	 * Brise se samo ako je stupac PRAZAN u smislu da nijedan redak nema vrijednost
	 * razlicitu od zadane — inace bi se gubio podatak.
	 *
	 * @return string[] sto je uklonjeno
	 */
	public static function ukloni_napustene_stupce(): array {
		global $wpdb;

		$napusteni = array(
			Config::TABLE_PODACI => array( 'barkod_np', 'marka_np', 'kolicina_np' ),
		);

		$uklonjeni = array();

		foreach ( $napusteni as $logicka => $stupci ) {
			$tablica = Config::table( $logicka );

			if ( ! self::tablica_postoji( $tablica ) ) {
				continue;
			}

			foreach ( $stupci as $stupac ) {
				$stupac = preg_replace( '/[^a-z_]/', '', $stupac );

				$postoji = $wpdb->get_var(
					$wpdb->prepare( "SHOW COLUMNS FROM `{$tablica}` LIKE %s", $stupac ) // phpcs:ignore
				);

				if ( ! $postoji ) {
					continue;
				}

				// Ne brise se stupac koji nosi podatke.
				$sa_vrijednoscu = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM `{$tablica}` WHERE `{$stupac}` <> 0" // phpcs:ignore
				);

				if ( $sa_vrijednoscu > 0 ) {
					continue;
				}

				$wpdb->query( "ALTER TABLE `{$tablica}` DROP COLUMN `{$stupac}`" ); // phpcs:ignore
				$uklonjeni[] = $tablica . '.' . $stupac;
			}
		}

		return $uklonjeni;
	}

	public static function tablica_postoji( string $puno_ime ): bool {
		global $wpdb;
		$nadeno = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $puno_ime ) );
		return $nadeno === $puno_ime;
	}
}
