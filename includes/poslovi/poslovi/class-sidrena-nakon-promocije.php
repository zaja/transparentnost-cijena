<?php
/**
 * Posao: upisi dodatnu cijenu artiklima kojima je akcija pretvorena u redovnu.
 *
 * ZASTO JE ZASEBAN KORAK
 *
 * Promocija mijenja podatke o proizvodu. Ovo upisuje tvrdnju o proslosti. Da su
 * spojeni, jedan neuspio dio srusio bi i drugi, a ishod bi ovisio o tome gdje je
 * stao. Ovako se svaki moze pokrenuti, provjeriti i ponoviti zasebno.
 *
 * STO UPISUJE
 *
 * Dodatna cijena = cijena koja se naplacuje. Nakon promocije to je ujedno i
 * redovna cijena artikla, pa vise nema dva kandidata izmedu kojih se bira.
 *
 * STO NE RADI
 *
 * Ne pretpostavlja da se ta cijena poklapa sa zapisom za referentni datum.
 * Provjerava. Ne poklapaju li se, artikl se NE dira nego se prijavljuje — isto
 * nacelo kao kod posla koji zatvara oscilirajuce cijene. Odluka vlasnika kaze
 * kako se cijena zove, ne koliko je iznosila u proslosti; to je pitanje za zapis.
 *
 * POVRATNO
 *
 * Upis brise OBA kandidata za dodatnu cijenu, dakle unistava podatak koji se ne
 * da ponovno izvesti bez ponovnog prolaska kroz zapise. Zato se cijeli zatecen
 * redak prvo sprema u trag. Ne uspije li zapis, redak se NE dira. Vracanje radi
 * posao "Vrati dodatnu cijenu nakon promocije".
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Cijene\Povijest_Cijena;
use CJTR\Config;
use CJTR\Db;
use CJTR\Postavke;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Sidrena_Nakon_Promocije extends Posao {

	/** Operacija ciji trag odreduje skupinu. */
	private const IZVOR = 'promocija';

	/** @var int|null */
	private $t_ref = null;

	public function kljuc(): string {
		return 'sidrena_nakon_promocije';
	}

	public function naziv(): string {
		return __( 'Utvrdi sidrenu cijenu za ukinute akcije', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Artiklima kojima je akcija ukinuta upisuje sidrenu cijenu i skida ih s popisa za odluku. Ovo je drugi dio ukidanja akcija — bez njega ti artikli ostaju bez sidrene cijene, pa se ona kupcu nece prikazati. Prije upisa provjerava slaze li se sa zapisom za referentni datum; ako se ne slaze, artikl ne dira nego prijavljuje.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}
		if ( 0 === Trag::broj_nevracenih( self::IZVOR ) ) {
			return __( 'Nema izvedene promocije — posao jos nije pokretan, ili je promocija vec vracena.', Config::TEXT_DOMAIN );
		}
		if ( ! Povijest_Cijena::postoji() ) {
			return __( 'U trgovini ne postoji zapis o proslim cijenama, pa se upisana vrijednost ne bi imala cime provjeriti.', Config::TEXT_DOMAIN );
		}
		return '';
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 8;
	}

	public function preduvjet(): string {
		return 'promocija';
	}

	public function preduvjet_razlog(): string {
		return __( 'Akcije moraju prvo biti ukinute — ovaj posao upisuje sidrenu cijenu upravo tim artiklima.', Config::TEXT_DOMAIN );
	}

	public function ukupno(): int {
		return Trag::broj_nevracenih( self::IZVOR );
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		// Skupina se cita iz traga promocije, ne iz stanja proizvoda: nakon
		// promocije artikl vise ne izgleda kao da je bio na akciji, pa ga uvjet
		// koji je odabrao promocija vise ne bi nasao.
		//
		// Samo NEVRACENI tragovi. Tragovi ranije promocije koja je u meduvremenu
		// vracena opisuju stanje koje vise ne postoji; da ih posao gleda, nakon
		// ciklusa promocija-povrat-promocija obradivao bi svaki artikl dvaput i
		// prijavljivao polovicu kao preskocenu.
		$zapisi = Trag::nevraceni( self::IZVOR, $zadnji_id, $velicina );

		if ( empty( $zapisi ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids    = array();
		$zadnji = 0;
		foreach ( $zapisi as $z ) {
			$ids[]  = (int) $z->entity_id;
			$zadnji = (int) $z->id;
		}

		$rez       = Rezultat_Komada::s( 0, $zadnji );
		$intervali = Povijest_Cijena::na_trenutak( $ids, $this->t_ref() );
		$postojeci = $this->postojeci( $ids );
		$upisanih  = 0;

		foreach ( $ids as $id ) {
			if ( $this->jedan( $id, $intervali, $postojeci, $rez ) ) {
				$upisanih++;
			}
		}

		$rez->obradeno = $upisanih;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

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

		// Samoprovjera: nula u stupcu koji smije biti prazan potpis je greske
		// `prepare('%s', null)`. Posao je provjerava na sebi, a ne ceka da je netko
		// primijeti na ekranu — ta se greska u ovom projektu vracala tri puta.
		foreach ( Db::provjeri_nule() as $nalaz ) {
			$stanje->zapisi(
				'greska',
				sprintf(
					/* translators: 1: stupac, 2: tablica, 3: koliko, 4: primjeri ID-eva */
					__( 'Provjera nula: stupac %1$s u tablici %2$s ima %3$d redaka s nulom (npr. %4$s). Nula ondje nije podatak nego pogreska pri upisu.', Config::TEXT_DOMAIN ),
					$nalaz['stupac'],
					$nalaz['tablica'],
					$nalaz['koliko'],
					$nalaz['primjeri']
				)
			);
		}
	}

	/* --------------------------------------------------------------- interno */

	/** @return bool je li upisano */
	private function jedan( int $id, array $intervali, array $postojeci, Rezultat_Komada $rez ): bool {
		$redak = $postojeci[ $id ] ?? null;

		if ( null === $redak ) {
			$rez->preskoci( $id, __( 'artikl nema redak u tablici podataka', Config::TEXT_DOMAIN ) );
			return false;
		}

		// Ljudski unos i vec utvrdena vrijednost imaju prednost pred poslom.
		if ( '' !== (string) $redak->sidrena_postavio
			&& ! in_array( (string) $redak->sidrena_postavio, Config::POSTAVIO_PREPISIVO, true ) ) {
			$rez->preskoci( $id, __( 'vrijednost je unio covjek, posao je ne dira', Config::TEXT_DOMAIN ) );
			return false;
		}

		if ( in_array( (string) $redak->sidrena_izvor, Config::IZVORI_KOJE_POSAO_NE_PREPISUJE, true ) ) {
			$rez->preskoci( $id, __( 'sidrena cijena je vec utvrdena i ne prepisuje se', Config::TEXT_DOMAIN ) );
			return false;
		}

		$naplacuje = $this->naplacuje_se( $id );
		if ( null === $naplacuje ) {
			$rez->greska(
				sprintf(
					/* translators: %d = ID artikla */
					__( 'ID %d: cijena koja se naplacuje nije postavljena.', Config::TEXT_DOMAIN ),
					$id
				)
			);
			return false;
		}

		// Kontrola da je promocija doista izvedena: redovna cijena mora biti ta ista.
		$redovna = $this->redovna( $id );
		if ( null === $redovna || abs( $redovna - $naplacuje ) > Config::TOLERANCIJA_POVIJESTI ) {
			$rez->greska(
				sprintf(
					/* translators: %d = ID artikla */
					__( 'ID %d: redovna cijena nije jednaka naplacivanoj — promocija za ovaj artikl nije izvedena.', Config::TEXT_DOMAIN ),
					$id
				)
			);
			return false;
		}

		if ( ! isset( $intervali[ $id ] ) ) {
			$rez->greska(
				sprintf(
					/* translators: %d = ID artikla */
					__( 'ID %d: nema zapisa o cijeni na referentni datum, pa se upisana vrijednost ne moze potvrditi.', Config::TEXT_DOMAIN ),
					$id
				)
			);
			return false;
		}

		$iz_povijesti = (float) $intervali[ $id ]['cijena'];

		// Kljucna provjera. Odluka vlasnika kaze kako se cijena zove, ne koliko je
		// iznosila na referentni datum — to kaze zapis, i ako se ne slazu, upis bi
		// bio tvrdnja koju nista ne podupire.
		if ( abs( $iz_povijesti - $naplacuje ) > Config::TOLERANCIJA_POVIJESTI ) {
			$rez->greska(
				sprintf(
					/* translators: 1: ID, 2: cijena iz zapisa, 3: naplacivana cijena */
					__( 'ID %1$d: cijena iz zapisa (%2$s) ne odgovara naplacivanoj (%3$s) — artikl nije diran.', Config::TEXT_DOMAIN ),
					$id,
					wc_format_localized_price( $iz_povijesti ),
					wc_format_localized_price( $naplacuje )
				)
			);
			return false;
		}

		// Trag PRIJE upisa: brisu se oba kandidata, a oni se ne mogu ponovno izvesti
		// bez novog prolaska kroz zapise o cijenama.
		$trag_id = Trag::zapisi(
			$id,
			$this->kljuc(),
			$this->snimi_redak( $id ),
			__( 'sidrena cijena se upisuje iz odluke vlasnika, a kandidati se brisu', Config::TEXT_DOMAIN )
		);

		if ( 0 === $trag_id ) {
			$rez->preskoci( $id, __( 'zatecen redak se nije mogao zapisati u trag, pa nije diran', Config::TEXT_DOMAIN ) );
			return false;
		}

		$this->upisi( $id, $naplacuje, (bool) $intervali[ $id ]['pocetak_pouzdan'] );
		return true;
	}

	/**
	 * Stupci koje upis mijenja — i koje povrat mora vratiti.
	 *
	 * Popis stoji na jednom mjestu jer ga koriste i snimanje u trag i vracanje.
	 * Da je napisan dvaput, dodavanje stupca u upis ostavilo bi povrat nepotpunim
	 * a da to nista ne prijavi.
	 */
	public const STUPCI = array(
		'sidrena_cijena',
		'sidrena_kandidat_regular',
		'sidrena_kandidat_efektivna',
		'zahtijeva_odluku',
		'bio_na_akciji',
		'pocetak_pouzdan',
		'sidrena_izvor',
		'dokazna_snaga',
		'sidrena_postavio',
		'sidrena_postavljeno',
		'sidrena_biljeska',
	);

	/** Zatecen redak, samo oni stupci koje upis dira. */
	private function snimi_redak( int $id ): array {
		global $wpdb;

		$stupci = implode( ', ', array_map( function ( $s ) {
			return '`' . preg_replace( '/[^a-z_]/', '', $s ) . '`';
		}, self::STUPCI ) );

		$r = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$stupci} FROM `" . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$id
			), // phpcs:ignore
			ARRAY_A
		);

		return is_array( $r ) ? $r : array();
	}

	private function upisi( int $id, float $cijena, bool $pocetak_pouzdan ): void {
		global $wpdb;

		$sada    = current_time( 'mysql', true );
		$tablica = Config::table( Config::TABLE_PODACI );

		$biljeska = __( 'vlasnik trgovine potvrdio je da je cijena koja se naplacuje redovna cijena; akcija je ukinuta, a zapis za referentni datum kaze istu vrijednost', Config::TEXT_DOMAIN );

		// Kandidati se brisu — nakon odluke vise nema dvojbe izmedu dvije vrijednosti.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$tablica}` SET
					sidrena_cijena             = " . Db::cijena( $cijena ) . ",
					sidrena_kandidat_regular   = NULL,
					sidrena_kandidat_efektivna = NULL,
					zahtijeva_odluku           = 0,
					bio_na_akciji              = 0,
					pocetak_pouzdan            = %d,
					sidrena_izvor              = %s,
					dokazna_snaga              = %s,
					sidrena_postavio           = %s,
					sidrena_postavljeno        = %s,
					sidrena_biljeska           = %s,
					azurirano                  = %s
				 WHERE entity_id = %d",
				(int) $pocetak_pouzdan,
				Config::IZVOR_ODLUKA_POTVRDENA_POVIJESCU,
				Config::SNAGA_OPAZENO,
				Config::POSTAVIO_POSAO,
				$sada,
				$biljeska,
				$sada,
				$id
			) // phpcs:ignore
		);
	}

	/**
	 * Postojeci retci tablice podataka, za cijeli komad odjednom.
	 *
	 * @param int[] $ids
	 * @return array<int,object>
	 */
	private function postojeci( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$u = implode( ',', array_map( 'intval', $ids ) );

		$out = array();
		foreach ( (array) $wpdb->get_results(
			'SELECT entity_id, sidrena_postavio, sidrena_izvor
			 FROM `' . Config::table( Config::TABLE_PODACI ) . "` WHERE entity_id IN ({$u})"
		) as $r ) { // phpcs:ignore
			$out[ (int) $r->entity_id ] = $r;
		}

		return $out;
	}

	/** Cijena koja se naplacuje, iz mete, bez filtera. */
	private function naplacuje_se( int $id ): ?float {
		$v = get_post_meta( $id, '_price', true );
		return ( '' === $v || null === $v ) ? null : (float) $v;
	}

	private function redovna( int $id ): ?float {
		$v = get_post_meta( $id, '_regular_price', true );
		return ( '' === $v || null === $v ) ? null : (float) $v;
	}

	private function t_ref(): int {
		if ( null === $this->t_ref ) {
			$this->t_ref = Config::t_ref( Postavke::ref_datum() );
		}
		return $this->t_ref;
	}
}
