<?php
/**
 * Posao: zatvori artikle kojima je cijena oscilirala, nakon sto je oscilacija
 * uklonjena i redovna cijena uskladena.
 *
 * KAD SE KORISTI
 *
 * Artikl je bio u skupini "cijena oscilira": akcija mu je zavrsila, akcijska
 * cijena ostala postavljena, pa mu je cijena svaki dan skakala gore-dolje. Zapis
 * u povijesti za referentni datum zato je ovisio o trenutku i nije se mogao
 * uzeti kao dodatna cijena.
 *
 * Nakon sto se oscilacija ukloni i redovna cijena postavi na onu koja se stvarno
 * naplacivala, dvojba nestaje: povijest i redovna cijena kazu isto. Ovaj posao
 * to provjerava i tek onda upisuje.
 *
 * STO POSAO NE RADI
 *
 * Ne pretpostavlja da se poklapaju. Ako se cijena iz povijesti i redovna cijena
 * razlikuju, artikl se NE dira nego se prijavljuje — jer tada pretpostavka na
 * kojoj posao pociva ne vrijedi.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Cijene\Povijest_Cijena;
use CJTR\Config;
use CJTR\Db;
use CJTR\Poslovi\Posao_S_Cijenama;
use CJTR\Poslovi\Rezultat_Komada;

defined( 'ABSPATH' ) || exit;

final class Oscilacija_Rijesena extends Posao_S_Cijenama {

	/** @var int|null */
	private $t_ref = null;

	public function kljuc(): string {
		return 'oscilacija_rijesena';
	}

	public function naziv(): string {
		return __( 'Dovrsi artikle kojima je cijena skakala', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Artiklima kojima je cijena skakala gore-dolje zapis za referentni datum ovisio je o trenutku, pa im sidrena cijena nije upisana. Nakon sto je skakanje zaustavljeno, ovaj posao provjerava slazu li se zapis i redovna cijena — i ako se slazu, upisuje sidrenu cijenu. Ako se ne slazu, artikl ne dira nego prijavljuje.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( ! Povijest_Cijena::postoji() ) {
			return __( 'U trgovini ne postoji zapis o proslim cijenama.', Config::TEXT_DOMAIN );
		}
		return parent::zapreka();
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 5;
	}

	public function preduvjet(): string {
		return 'sidrena_cijena';
	}

	public function preduvjet_razlog(): string {
		return __( 'Sidrena cijena mora prvo biti utvrdena za sve artikle — tek tada se zna koji su ostali nerijeseni.', Config::TEXT_DOMAIN );
	}

	public function ukupno(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE sidrena_izvor = %s',
				Config::IZVOR_ARTEFAKT_OSCILACIJE
			) // phpcs:ignore
		);
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		// Skupina se nalazi po izvoru, ne po popisu ID-eva — isti slucaj moze
		// nastati kod bilo kojeg korisnika i kod bilo kojeg artikla.
		$redci = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT entity_id, sidrena_postavio
				 FROM `' . Config::table( Config::TABLE_PODACI ) . '`
				 WHERE sidrena_izvor = %s AND entity_id > %d
				 ORDER BY entity_id ASC
				 LIMIT %d',
				Config::IZVOR_ARTEFAKT_OSCILACIJE,
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		if ( empty( $redci ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids = array();
		foreach ( $redci as $r ) {
			$ids[] = (int) $r->entity_id;
		}

		$rez       = Rezultat_Komada::s( 0, max( $ids ) );
		$intervali = Povijest_Cijena::na_trenutak( $ids, $this->t_ref() );
		$jos_titra = $this->jos_oscilira( $ids );
		$upisanih  = 0;

		foreach ( $redci as $r ) {
			$id = (int) $r->entity_id;

			if ( ! in_array( (string) $r->sidrena_postavio, Config::POSTAVIO_PREPISIVO, true )
				&& '' !== (string) $r->sidrena_postavio ) {
				$rez->preskoci( $id, __( 'vrijednost je unio covjek, posao je ne dira', Config::TEXT_DOMAIN ) );
				continue;
			}

			// 1. Oscilacija mora biti uklonjena. Dok traje, redovna cijena nije
			//    stabilna pa se ni usporedba s njom ne moze uzeti kao potvrda.
			if ( isset( $jos_titra[ $id ] ) ) {
				$rez->greska(
					sprintf(
						/* translators: %d = ID artikla */
						__( 'ID %d: cijena jos uvijek oscilira — prvo treba pokrenuti ciscenje isteklih akcija.', Config::TEXT_DOMAIN ),
						$id
					)
				);
				continue;
			}

			// 2. Mora postojati zapis za referentni datum.
			if ( ! isset( $intervali[ $id ] ) ) {
				$rez->greska(
					sprintf(
						/* translators: %d = ID artikla */
						__( 'ID %d: nema zapisa o cijeni na referentni datum.', Config::TEXT_DOMAIN ),
						$id
					)
				);
				continue;
			}

			$iz_povijesti = (float) $intervali[ $id ]['cijena'];
			$redovna      = $this->redovna( $id );

			if ( null === $redovna ) {
				$rez->greska(
					sprintf(
						/* translators: %d = ID artikla */
						__( 'ID %d: redovna cijena nije postavljena.', Config::TEXT_DOMAIN ),
						$id
					)
				);
				continue;
			}

			// 3. Kljucna provjera: poklapaju li se. Ako ne, pretpostavka ne vrijedi.
			if ( abs( $iz_povijesti - $redovna ) > Config::TOLERANCIJA_POVIJESTI ) {
				$rez->greska(
					sprintf(
						/* translators: 1: ID, 2: cijena iz zapisa, 3: redovna cijena */
						__( 'ID %1$d: cijena iz zapisa (%2$s) ne odgovara redovnoj cijeni (%3$s) — artikl nije diran.', Config::TEXT_DOMAIN ),
						$id,
						wc_format_localized_price( $iz_povijesti ),
						wc_format_localized_price( $redovna )
					)
				);
				continue;
			}

			$this->upisi( $id, $iz_povijesti, $intervali[ $id ]['pocetak_pouzdan'] );
			$upisanih++;
		}

		$rez->obradeno = $upisanih;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new \CJTR\Poslovi\Stanje();
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
	}

	/* --------------------------------------------------------------- interno */

	/**
	 * Artikli kojima cijena i dalje oscilira.
	 *
	 * Isti uvjet koji koristi posao ciscenja: akcijska cijena postavljena, a datum
	 * zavrsetka akcije prosao.
	 *
	 * @param int[] $ids
	 * @return array<int,bool>
	 */
	private function jos_oscilira( array $ids ): array {
		global $wpdb;

		$u    = implode( ',', array_map( 'intval', $ids ) );
		$sada = time();

		$nadeni = (array) $wpdb->get_col(
			"SELECT sp.post_id
			 FROM {$wpdb->postmeta} sp
			 JOIN {$wpdb->postmeta} dt ON dt.post_id = sp.post_id AND dt.meta_key = '_sale_price_dates_to'
			 WHERE sp.post_id IN ({$u})
			   AND sp.meta_key = '_sale_price' AND sp.meta_value <> ''
			   AND dt.meta_value <> '' AND CAST(dt.meta_value AS UNSIGNED) > 0
			   AND CAST(dt.meta_value AS UNSIGNED) < {$sada}"
		); // phpcs:ignore

		$out = array();
		foreach ( $nadeni as $id ) {
			$out[ (int) $id ] = true;
		}
		return $out;
	}

	/** Redovna cijena iz mete, bez filtera. */
	private function redovna( int $id ): ?float {
		$proizvod = wc_get_product( $id );
		if ( ! $proizvod ) {
			return null;
		}
		$v = $proizvod->get_regular_price( 'edit' );
		return ( '' === $v || null === $v ) ? null : (float) $v;
	}

	private function upisi( int $id, float $cijena, bool $pocetak_pouzdan ): void {
		global $wpdb;

		$sada    = current_time( 'mysql', true );
		$tablica = Config::table( Config::TABLE_PODACI );

		$biljeska = __( 'oscilacija cijene je uklonjena, a redovna cijena uskladena s onom koja je vrijedila na referentni datum; obje vrijednosti se poklapaju', Config::TEXT_DOMAIN );

		// Kandidati se brisu jer vise nema dvojbe — ostala je jedna vrijednost.
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
				Config::IZVOR_POVIJEST_POTVRDENA_REDOVNOM,
				Config::SNAGA_OPAZENO,
				Config::POSTAVIO_POSAO,
				$sada,
				$biljeska,
				$sada,
				$id
			) // phpcs:ignore
		);
	}

	private function t_ref(): int {
		if ( null === $this->t_ref ) {
			$this->t_ref = Config::t_ref( Config::REF_DATUM_OSTALO );
		}
		return $this->t_ref;
	}
}
