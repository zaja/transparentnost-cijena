<?php
/**
 * Posao: utvrdi dodatnu (sidrenu) cijenu na referentni datum.
 *
 * Za svaki artikl u trgovini trazi u povijesti cijena koja je cijena vrijedila na
 * kraju referentnog dana. Ono sto nade upisuje kao opazanje; ono sto ne nade
 * ostavlja praznim i prijavljuje, umjesto da procjenjuje.
 *
 * SMJER: od kataloga prema povijesti, nikad obrnuto. Povijest sadrzi i artikle
 * kojih u trgovini vise nema i oni u cjenik ne smiju uci.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Cijene\Povijest_Cijena;
use CJTR\Config;
use CJTR\Db;
use CJTR\Cijene\Pregled;
use CJTR\Preuzimanje;
use CJTR\Katalog;
use CJTR\Postavke;
use CJTR\Povijest\Zapis;
use CJTR\Poslovi\Posao_S_Cijenama;
use CJTR\Poslovi\Rezultat_Komada;

defined( 'ABSPATH' ) || exit;

final class Sidrena_Cijena extends Posao_S_Cijenama {

	/** @var int|null memoizirani referentni trenutak */
	private $t_ref = null;

	/** @var array<int,string> zakonska skupina po entitetu, za tekuci komad */
	private $kategorije = array();

	public function kljuc(): string {
		return 'sidrena_cijena';
	}

	public function naziv(): string {
		return __( 'Utvrdi sidrenu cijenu za sve artikle', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return sprintf(
			/* translators: %s = referentni datum */
			__( 'Za svaki artikl trazi koja je cijena vrijedila %s i upisuje je kao sidrenu cijenu. Artikle koji su tada bili na akciji ostavlja za odluku, a one bez zapisa za rucni unos. Cijene u trgovini se NE mijenjaju.', Config::TEXT_DOMAIN ),
			wp_date( 'd.m.Y.', $this->t_ref() )
		);
	}

	public function zapreka(): string {
		if ( ! Povijest_Cijena::postoji() ) {
			return __( 'U trgovini ne postoji zapis o proslim cijenama, pa se sidrena cijena ne moze utvrditi iz podataka.', Config::TEXT_DOMAIN );
		}
		// Straza nad dinamickim cijenama.
		return parent::zapreka();
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 4;
	}

	public function preduvjet(): string {
		return 'uvoz_povijesti';
	}

	public function preduvjet_razlog(): string {
		return __( 'Treba povijest cijena — bez nje se nema odakle utvrditi koja je cijena vrijedila na referentni datum.', Config::TEXT_DOMAIN );
	}

	public function ukupno(): int {
		return Katalog::broj();
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$entiteti = Katalog::komad( $zadnji_id, $velicina );

		if ( empty( $entiteti ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids = array();
		foreach ( $entiteti as $e ) {
			$ids[] = (int) $e->ID;
		}

		$rez = Rezultat_Komada::s( 0, max( $ids ) );

		/*
		 * REFERENTNI DATUM NIJE JEDAN ZA CIJELU TRGOVINU.
		 *
		 * Trgovina koja prodaje i hranu i ostalo ima dva, pa se komad grupira po
		 * datumu i povijest se cita jednom po skupini. Ranije je ovdje stajala
		 * konstanta, dok je prikaz uz cijenu vec citao postavku po skupini
		 * proizvoda — brojka i natpis uz nju mogli su tvrditi razlicito.
		 */
		$this->ucitaj_kategorije( $ids );

		$intervali = array();
		$t_po_datumu = array();
		foreach ( $this->po_ref_datumu( $ids ) as $datum => $skupina ) {
			$t_po_datumu[ $datum ] = Config::t_ref( $datum );
			$intervali            += Povijest_Cijena::na_trenutak( $skupina, $t_po_datumu[ $datum ] );
		}

		$mete = $this->mete( $ids );

		// Za akcijske entitete trazimo potvrdu da je redovna cijena stvarno
		// postojala prije akcije — inace je "redovna" samo trenutna vrijednost mete.
		$trazene = array();
		foreach ( $entiteti as $e ) {
			$id = (int) $e->ID;
			if ( ! isset( $intervali[ $id ], $mete[ $id ]['regular'] ) ) {
				continue;
			}
			if ( $intervali[ $id ]['cijena'] < (float) $mete[ $id ]['regular'] - Config::TOLERANCIJA_POVIJESTI ) {
				$trazene[ $id ] = (float) $mete[ $id ]['regular'];
			}
		}
		$potvrde = array();
		foreach ( $this->po_ref_datumu( $ids ) as $datum => $skupina ) {
			$njihove  = array_intersect_key( $trazene, array_flip( $skupina ) );
			$potvrde += Povijest_Cijena::potvrda_ranije( $skupina, $njihove, $t_po_datumu[ $datum ] );
		}

		$postavio  = $this->tko_je_postavio( $ids );
		$artefakti = $this->artefakti_oscilacije( $ids );
		$izvori    = $this->zateceni_izvori( $ids );

		$upisanih = 0;
		foreach ( $entiteti as $e ) {
			$zapis = $this->klasificiraj( $e, $intervali, $mete, $potvrde, $postavio, $artefakti, $izvori );

			if ( null === $zapis ) {
				$rez->preskoci( (int) $e->ID, __( 'rucni unos ili odluka klijenta imaju prednost, redak nije diran', Config::TEXT_DOMAIN ) );
				continue;
			}

			$this->upisi( $zapis );
			$upisanih++;
		}

		$rez->obradeno = $upisanih;
		return $rez;
	}

	/* ------------------------------------------------------------ klasifikacija */

	/**
	 * Odluci sto je sidrena cijena za jedan entitet.
	 *
	 * @return array|null null ako se redak ne smije dirati
	 */
	private function klasificiraj( $e, array $intervali, array $mete, array $potvrde, array $postavio, array $artefakti, array $izvori ): ?array {
		$id = (int) $e->ID;

		if ( ! $this->smijem_pisati( $id, $postavio ) ) {
			return null;
		}

		// Vec utvrdeno i potvrdeno dvjema neovisnim potvrdama — ponovno izvodenje
		// dalo bi istu vrijednost, ali bi izbrisalo zapis kako je utvrdena.
		if ( isset( $izvori[ $id ] ) && in_array( $izvori[ $id ], Config::IZVORI_KOJE_POSAO_NE_PREPISUJE, true ) ) {
			return null;
		}

		$ref_datum = $this->ref_datum_za( $id );

		$zapis = array(
			'entity_id'        => $id,
			'sidrena_cijena'   => null,
			'kandidat_regular' => null,
			'kandidat_efekt'   => null,
			'zahtijeva_odluku' => 0,
			'bio_na_akciji'    => 0,
			'izvor'            => Config::IZVOR_RUCNI_UNOS,
			'snaga'            => Config::SNAGA_NEMA,
			'pocetak_pouzdan'  => 1,
			'ref_datum'        => $ref_datum,
			'biljeska'         => '',
		);

		/*
		 * 1. Artikl uveden NAKON referentnog datuma.
		 *
		 * Cijene s referentnog datuma nema jer artikla tada nije bilo — ali sidrena
		 * cijena postoji: to je cijena po kojoj je prvi put uvrsten u ponudu, uz
		 * datum kad je formirana. Obrazlozenje i podrijetlo tumacenja stoje uz
		 * `Config::IZVOR_PRVA_CIJENA`.
		 *
		 * Referentni datum tog RETKA postaje datum uvodenja. Zato ga upisujemo, a
		 * prikaz i cjenik ga citaju odande umjesto opceg.
		 */
		if ( Config::nastao_nakon_ref_datuma( (string) $e->post_date, $ref_datum ) ) {
			$prvi = Zapis::prvi( $id );

			if ( $prvi && null !== $prvi->price ) {
				$zapis['sidrena_cijena'] = (float) $prvi->price;
				$zapis['izvor']          = Config::IZVOR_PRVA_CIJENA;
				$zapis['snaga']          = Config::SNAGA_OPAZENO;
				$zapis['ref_datum']      = wp_date( 'Y-m-d', (int) $prvi->ts );
				$zapis['biljeska']       = sprintf(
					/* translators: %s = datum uvodenja */
					__( 'prva cijena po kojoj je artikl ponuden, formirana %s', Config::TEXT_DOMAIN ),
					wp_date( 'd.m.Y.', (int) $prvi->ts )
				);
				return $zapis;
			}

			/*
			 * Uveden nakon referentnog datuma, a pocetnu cijenu nemamo zabiljezenu —
			 * artikl je usao izmedu referentnog datuma i instalacije dodatka. Datum
			 * znamo, cijenu ne. To ceka trgovcev unos, jer ju je on formirao.
			 */
			$zapis['izvor']     = Config::IZVOR_NAKON_REF_DATUMA;
			$zapis['snaga']     = Config::SNAGA_NEMA;
			$zapis['ref_datum'] = wp_date( 'Y-m-d', (int) strtotime( (string) $e->post_date ) );
			$zapis['biljeska']  = sprintf(
				/* translators: %s = datum uvodenja */
				__( 'uveden %s, nakon referentnog datuma; pocetnu cijenu nismo zabiljezili', Config::TEXT_DOMAIN ),
				wp_date( 'd.m.Y.', strtotime( (string) $e->post_date ) )
			);
			return $zapis;
		}

		// 2. Nema zapisa u povijesti za referentni datum.
		if ( ! isset( $intervali[ $id ] ) ) {
			$zapis['biljeska'] = __( 'nema zapisa o cijeni na referentni datum', Config::TEXT_DOMAIN );
			return $zapis;
		}

		$interval = $intervali[ $id ];
		$regular  = isset( $mete[ $id ]['regular'] ) && '' !== $mete[ $id ]['regular']
			? (float) $mete[ $id ]['regular']
			: null;

		$zapis['pocetak_pouzdan'] = $interval['pocetak_pouzdan'] ? 1 : 0;

		$na_akciji = ( null !== $regular )
			&& ( $interval['cijena'] < $regular - Config::TOLERANCIJA_POVIJESTI );

		// 3a. Cijena oscilira zbog istekle akcijske mete — zapis na referentni datum
		//     je artefakt te oscilacije, ne stvarna akcija. Odluka je cinjenicna,
		//     ne poslovna, pa ide u vlastitu skupinu.
		if ( $na_akciji && isset( $artefakti[ $id ] ) ) {
			$zapis['bio_na_akciji']    = 0;
			$zapis['zahtijeva_odluku'] = 1;
			$zapis['kandidat_regular'] = $regular;
			$zapis['kandidat_efekt']   = $interval['cijena'];
			$zapis['izvor']            = Config::IZVOR_ARTEFAKT_OSCILACIJE;
			$zapis['snaga']            = Config::SNAGA_NEMA;
			$zapis['biljeska']         = sprintf(
				/* translators: %s = datum isteka akcije */
				__( 'cijena oscilira jer je akcija istekla %s a akcijska cijena ostala postavljena; zapis na referentni datum je artefakt, a ne akcija', Config::TEXT_DOMAIN ),
				wp_date( 'd.m.Y.', $artefakti[ $id ] )
			);
			return $zapis;
		}

		// 3b. Bio na akciji — konacnu cijenu NE upisujemo. Oba kandidata i odluka.
		if ( $na_akciji ) {
			$zapis['bio_na_akciji']    = 1;
			$zapis['zahtijeva_odluku'] = 1;
			$zapis['kandidat_regular'] = $regular;
			$zapis['kandidat_efekt']   = $interval['cijena'];
			$zapis['izvor']            = Config::IZVOR_TRAZI_ODLUKU;
			$zapis['snaga']            = Config::SNAGA_OPAZENO;
			$zapis['biljeska']         = isset( $potvrde[ $id ] )
				? sprintf(
					/* translators: %s = datum */
					__( 'na akciji; redovna cijena potvrdena zapisom od %s', Config::TEXT_DOMAIN ),
					wp_date( 'd.m.Y.', $potvrde[ $id ] )
				)
				: __( 'na akciji; redovna cijena NIJE potvrdena ranijim zapisom', Config::TEXT_DOMAIN );
			return $zapis;
		}

		// 4. Nije bio na akciji — opazena cijena JEST dodatna cijena.
		$zapis['sidrena_cijena'] = $interval['cijena'];
		$zapis['izvor']          = Config::IZVOR_POVIJEST;
		$zapis['snaga']          = Config::SNAGA_OPAZENO;
		$zapis['biljeska']       = $interval['pocetak_pouzdan']
			? sprintf(
				/* translators: %s = datum */
				__( 'cijena na snazi od %s', Config::TEXT_DOMAIN ),
				wp_date( 'd.m.Y.', $interval['pocetak'] )
			)
			: __( 'cijena je poznata, ali ne i otkad vrijedi', Config::TEXT_DOMAIN );

		return $zapis;
	}

	/**
	 * Smije li posao dirati ovaj redak.
	 *
	 * Rucni unos i odluka klijenta imaju prednost. Posao prepisuje samo ono sto
	 * je sam ili neki drugi automat upisao.
	 */
	private function smijem_pisati( int $id, array $postavio ): bool {
		if ( ! isset( $postavio[ $id ] ) || '' === $postavio[ $id ] ) {
			return true;
		}
		return in_array( $postavio[ $id ], Config::POSTAVIO_PREPISIVO, true );
	}

	/**
	 * Entiteti kojima cijena oscilira zbog istekle akcijske mete.
	 *
	 * Isti uvjet koji koristi posao ciscenja: akcijska cijena postavljena, a datum
	 * zavrsetka akcije prosao. WooCommerce ih svaki dan dira dvaput, pa im zapis u
	 * povijesti ovisi o trenutku.
	 *
	 * @param int[] $ids
	 * @return array<int,int> entity_id => datum isteka akcije
	 */
	private function artefakti_oscilacije( array $ids ): array {
		global $wpdb;

		$u    = implode( ',', array_map( 'intval', $ids ) );
		$sada = time();

		$redci = $wpdb->get_results(
			"SELECT sp.post_id, dt.meta_value AS istekla
			 FROM {$wpdb->postmeta} sp
			 JOIN {$wpdb->postmeta} dt ON dt.post_id = sp.post_id AND dt.meta_key = '_sale_price_dates_to'
			 WHERE sp.post_id IN ({$u})
			   AND sp.meta_key = '_sale_price' AND sp.meta_value <> ''
			   AND dt.meta_value <> '' AND CAST(dt.meta_value AS UNSIGNED) > 0
			   AND CAST(dt.meta_value AS UNSIGNED) < {$sada}"
		); // phpcs:ignore

		$out = array();
		foreach ( $redci as $r ) {
			$out[ (int) $r->post_id ] = (int) $r->istekla;
		}
		return $out;
	}

	/**
	 * Zatecen izvor po entitetu, za cijeli komad odjednom.
	 *
	 * @param int[] $ids
	 * @return array<int,string>
	 */
	private function zateceni_izvori( array $ids ): array {
		global $wpdb;

		$u = implode( ',', array_map( 'intval', $ids ) );

		$redci = $wpdb->get_results(
			'SELECT entity_id, sidrena_izvor
			 FROM `' . Config::table( Config::TABLE_PODACI ) . "`
			 WHERE entity_id IN ({$u})"
		); // phpcs:ignore

		$out = array();
		foreach ( $redci as $r ) {
			$out[ (int) $r->entity_id ] = (string) $r->sidrena_izvor;
		}
		return $out;
	}

	/**
	 * Tko je postavio vrijednost, za cijeli komad odjednom.
	 *
	 * @param int[] $ids
	 * @return array<int,string>
	 */
	private function tko_je_postavio( array $ids ): array {
		global $wpdb;

		$u = implode( ',', array_map( 'intval', $ids ) );

		$redci = $wpdb->get_results(
			'SELECT entity_id, sidrena_postavio
			 FROM `' . Config::table( Config::TABLE_PODACI ) . "`
			 WHERE entity_id IN ({$u})"
		); // phpcs:ignore

		$out = array();
		foreach ( $redci as $r ) {
			$out[ (int) $r->entity_id ] = (string) $r->sidrena_postavio;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ upis */

	private function upisi( array $z ): void {
		global $wpdb;

		$sada    = current_time( 'mysql', true );
		$tablica = Config::table( Config::TABLE_PODACI );

		$vrijednosti = implode(
			',',
			array(
				(int) $z['entity_id'],
				Db::cijena( $z['sidrena_cijena'] ),
				Db::cijena( $z['kandidat_regular'] ),
				Db::cijena( $z['kandidat_efekt'] ),
				(int) $z['zahtijeva_odluku'],
				Db::tekst( $z['izvor'] ),
				Db::tekst( $z['ref_datum'] ),
				Db::tekst( $z['snaga'] ),
				(int) $z['pocetak_pouzdan'],
				(int) $z['bio_na_akciji'],
				Db::tekst( $z['ref_datum'] ),
				Db::tekst( Config::POSTAVIO_POSAO ),
				Db::tekst( $sada ),
				Db::tekst( $z['biljeska'] ),
				Db::tekst( $sada ),
			)
		);

		$wpdb->query(
			"INSERT INTO `{$tablica}`
				( entity_id, sidrena_cijena, sidrena_kandidat_regular, sidrena_kandidat_efektivna,
				  zahtijeva_odluku, sidrena_izvor, opazeno_na_datum, dokazna_snaga, pocetak_pouzdan,
				  bio_na_akciji, referentni_datum, sidrena_postavio, sidrena_postavljeno,
				  sidrena_biljeska, azurirano )
			 VALUES ( {$vrijednosti} )
			 ON DUPLICATE KEY UPDATE
				sidrena_cijena             = VALUES(sidrena_cijena),
				sidrena_kandidat_regular   = VALUES(sidrena_kandidat_regular),
				sidrena_kandidat_efektivna = VALUES(sidrena_kandidat_efektivna),
				zahtijeva_odluku           = VALUES(zahtijeva_odluku),
				sidrena_izvor              = VALUES(sidrena_izvor),
				opazeno_na_datum           = VALUES(opazeno_na_datum),
				dokazna_snaga              = VALUES(dokazna_snaga),
				pocetak_pouzdan            = VALUES(pocetak_pouzdan),
				bio_na_akciji              = VALUES(bio_na_akciji),
				referentni_datum           = VALUES(referentni_datum),
				sidrena_postavio           = VALUES(sidrena_postavio),
				sidrena_postavljeno        = VALUES(sidrena_postavljeno),
				sidrena_biljeska           = VALUES(sidrena_biljeska),
				azurirano                  = VALUES(azurirano)" // phpcs:ignore
		);
	}



	/**
	 * Po zavrsetku: raspodjela po skupinama u zapisnik.
	 *
	 * Brojka "obradeno 3636" ne kaze je li rezultat ispravan — raspodjela po
	 * skupinama kaze. Zato ide u zapisnik, koji ostaje vidljiv i kasnije.
	 */
	public function nakon_zavrsetka(): void {
		$stanje        = new \CJTR\Poslovi\Stanje();
		$stanje->kljuc = $this->kljuc();

		foreach ( Pregled::redci_zapisnika() as $redak ) {
			$stanje->zapisi( 'info', $redak );
		}

		$zastita = Preuzimanje::zasticeno();
		if ( ! empty( $zastita['provjereno'] ) && empty( $zastita['zasticen'] ) ) {
			$stanje->zapisi( 'greska', $zastita['razlog'] );
		}

		Pregled::zapamti_pokretanje();

		// Dodatna cijena se ispisuje u HTML stranice, pa je dodaci za predmemoriju
		// spreme. WooCommerce cisti predmemoriju na spremanje proizvoda — a ovo
		// nije spremanje proizvoda. Bez ovoga bi posjetitelji danima vidjeli staro.
		$ocisceno = \CJTR\Prikaz\Cache::ocisti();
		if ( ! empty( $ocisceno ) ) {
			$stanje->zapisi(
				'info',
				sprintf(
					/* translators: %s = popis dodataka */
					__( 'Ociscena predmemorija stranica: %s', Config::TEXT_DOMAIN ),
					implode( ', ', $ocisceno )
				)
			);
		}
	}

	/* --------------------------------------------------------------- pomocno */

	/** Opci referentni datum trgovine — za opis posla i za artikle bez skupine. */
	private function t_ref(): int {
		if ( null === $this->t_ref ) {
			$this->t_ref = Config::t_ref( Postavke::ref_datum() );
		}
		return $this->t_ref;
	}

	/**
	 * Zakonske skupine za komad entiteta.
	 *
	 * Ucitava se jednom po komadu; bez toga bi `ref_datum_za()` isao u bazu po
	 * svakom artiklu.
	 *
	 * @param int[] $ids
	 */
	private function ucitaj_kategorije( array $ids ): void {
		global $wpdb;

		$this->kategorije = array();

		if ( empty( $ids ) ) {
			return;
		}

		$u = implode( ',', array_map( 'intval', $ids ) );

		foreach ( (array) $wpdb->get_results(
			'SELECT entity_id, zakonska_kategorija FROM `' . Config::table( Config::TABLE_PODACI ) . "`
			 WHERE entity_id IN ({$u})" // phpcs:ignore
		) as $r ) {
			$this->kategorije[ (int) $r->entity_id ] = (string) $r->zakonska_kategorija;
		}
	}

	/** Referentni datum koji vrijedi za ovaj artikl, po njegovoj skupini. */
	private function ref_datum_za( int $id ): string {
		return Postavke::ref_datum( $this->kategorije[ $id ] ?? '' );
	}

	/**
	 * Entiteti komada grupirani po referentnom datumu.
	 *
	 * Trgovina bez reguliranih skupina dobiva jednu skupinu i sve radi kao prije.
	 *
	 * @param int[] $ids
	 * @return array<string,int[]>
	 */
	private function po_ref_datumu( array $ids ): array {
		$skupine = array();

		foreach ( $ids as $id ) {
			$skupine[ $this->ref_datum_za( (int) $id ) ][] = (int) $id;
		}

		return $skupine;
	}

	/**
	 * Cjenovne mete za komad entiteta.
	 *
	 * @param int[] $ids
	 * @return array<int,array{price:string,regular:string}>
	 */
	private function mete( array $ids ): array {
		global $wpdb;

		$u = implode( ',', array_map( 'intval', $ids ) );

		$redci = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ({$u}) AND meta_key IN ('_price','_regular_price')"
		); // phpcs:ignore

		$out = array();
		foreach ( $redci as $r ) {
			$kljuc = ( '_price' === $r->meta_key ) ? 'price' : 'regular';
			$out[ (int) $r->post_id ][ $kljuc ] = (string) $r->meta_value;
		}

		return $out;
	}
}
