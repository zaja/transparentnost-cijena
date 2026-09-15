<?php
/**
 * Stanje posla — u BAZI, nikad u memoriji.
 *
 * Ako PHP padne na timeoutu ili proces bude ubijen, posao se nastavlja tocno
 * tamo gdje je stao jer je odmak zapisan nakon svakog komada.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Stanje {

	public $kljuc          = '';
	public $status         = Config::STATUS_CEKA;
	public $ukupno         = 0;
	public $obradeno       = 0;
	public $preskoceno     = 0;
	public $gresaka        = 0;

	/** Kursor: ID iza kojega se nastavlja. Keyset, ne odmak po broju retka. */
	public $zadnji_id      = 0;
	public $zakljucano_do  = null;
	public $pokrenuto      = null;
	public $azurirano      = null;
	public $zavrseno       = null;
	public $poruka         = '';

	/** Kad je ucinak posla ponisten povratom. null = nije ponisten. */
	public $ponisteno      = null;

	/** Tko je ponistio — kljuc posla povrata. */
	public $ponistio       = null;

	private static function tablica(): string {
		return Config::table( Config::TABLE_POSLOVI );
	}

	private static function tablica_log(): string {
		return Config::table( Config::TABLE_POSAO_LOG );
	}

	/** Ucitaj stanje, ili vrati prazno ako posao jos nije pokretan. */
	public static function ucitaj( string $kljuc ): self {
		global $wpdb;

		$red = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM `' . self::tablica() . '` WHERE kljuc = %s', $kljuc ) // phpcs:ignore
		);

		$s        = new self();
		$s->kljuc = $kljuc;

		if ( ! $red ) {
			return $s;
		}

		$s->status        = $red->status;
		$s->ukupno        = (int) $red->ukupno;
		$s->obradeno      = (int) $red->obradeno;
		$s->preskoceno    = (int) $red->preskoceno;
		$s->gresaka       = (int) $red->gresaka;
		$s->zadnji_id     = (int) $red->zadnji_id;
		$s->zakljucano_do = $red->zakljucano_do;
		$s->pokrenuto     = $red->pokrenuto;
		$s->azurirano     = $red->azurirano;
		$s->zavrseno      = $red->zavrseno;
		$s->ponisteno     = $red->ponisteno ?? null;
		$s->ponistio      = $red->ponistio ?? null;
		$s->poruka        = (string) $red->poruka;

		return $s;
	}

	public function spremi(): void {
		global $wpdb;

		$podaci = array(
			'kljuc'         => $this->kljuc,
			'status'        => $this->status,
			'ukupno'        => $this->ukupno,
			'obradeno'      => $this->obradeno,
			'preskoceno'    => $this->preskoceno,
			'gresaka'       => $this->gresaka,
			'zadnji_id'     => $this->zadnji_id,
			'zakljucano_do' => $this->zakljucano_do,
			'pokrenuto'     => $this->pokrenuto,
			'azurirano'     => current_time( 'mysql', true ),
			'zavrseno'      => $this->zavrseno,
			'ponisteno'     => $this->ponisteno,
			'ponistio'      => $this->ponistio,
			'poruka'        => $this->poruka,
		);

		$postoji = $wpdb->get_var(
			$wpdb->prepare( 'SELECT kljuc FROM `' . self::tablica() . '` WHERE kljuc = %s', $this->kljuc ) // phpcs:ignore
		);

		if ( $postoji ) {
			$wpdb->update( self::tablica(), $podaci, array( 'kljuc' => $this->kljuc ) );
		} else {
			$wpdb->insert( self::tablica(), $podaci );
		}
	}

	/**
	 * Atomarno preuzmi pravo na obradu iduceg komada.
	 *
	 * Zastita od dvostrukog pokretanja: UPDATE uspije samo ako posao nije vec
	 * zakljucan, ili ako je zakljucavanje isteklo. Oslanja se na to da je
	 * UPDATE ... WHERE atomaran, pa dva istovremena procesa ne mogu oba proci.
	 */
	public function preuzmi(): bool {
		global $wpdb;

		$sada = current_time( 'mysql', true );
		$do   = gmdate( 'Y-m-d H:i:s', time() + Config::ZAKLJUCAJ_SEKUNDI );

		$pogodenih = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::tablica() . '`
				 SET zakljucano_do = %s, azurirano = %s
				 WHERE kljuc = %s
				   AND status = %s
				   AND ( zakljucano_do IS NULL OR zakljucano_do < %s )',
				$do,
				$sada,
				$this->kljuc,
				Config::STATUS_RADI,
				$sada
			) // phpcs:ignore
		);

		if ( $pogodenih ) {
			$this->zakljucano_do = $do;
			return true;
		}
		return false;
	}

	/**
	 * Atomarno preuzmi pravo da posao ZAVRSIS.
	 *
	 * Zakljucavanje stiti obradu komada, ali se otpusta prije zavrsetka. Zato dva
	 * procesa mogu oba vidjeti prazan komad i oba krenuti zavrsavati — sto znaci
	 * dvostruki zapisnik i dvostruko izvrsen zavrsni posao. Prijelaz iz rada u
	 * gotovo zato ide jednim UPDATE-om s uvjetom: tko ga dobije, taj zavrsava.
	 */
	public function preuzmi_zavrsetak( string $novi_status ): bool {
		global $wpdb;

		$pogodenih = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::tablica() . '`
				 SET status = %s, zavrseno = %s, zakljucano_do = NULL, azurirano = %s
				 WHERE kljuc = %s AND status = %s',
				$novi_status,
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$this->kljuc,
				Config::STATUS_RADI
			) // phpcs:ignore
		);

		if ( $pogodenih ) {
			$this->status = $novi_status;
			return true;
		}
		return false;
	}

	public function otkljucaj(): void {
		global $wpdb;
		$wpdb->update(
			self::tablica(),
			array(
				'zakljucano_do' => null,
				'azurirano'     => current_time( 'mysql', true ),
			),
			array( 'kljuc' => $this->kljuc )
		);
		$this->zakljucano_do = null;
	}

	/** Vidjeno = obradeno + namjerno preskoceno. */
	public function vidjeno(): int {
		return $this->obradeno + $this->preskoceno;
	}

	public function postotak(): float {
		if ( $this->ukupno <= 0 ) {
			return 0.0;
		}
		return min( 100.0, round( 100 * $this->vidjeno() / $this->ukupno, 1 ) );
	}

	/**
	 * Koliko entiteta nije objasnjeno.
	 *
	 * `ukupno` je procjena s pocetka posla; na zivom shopu se katalog u meduvremenu
	 * moze promijeniti, pa razlika ne mora znaciti gresku. Ali MORA biti vidljiva
	 * prije nego ijedna datoteka izade — kod generatora je svaki neobjasnjen
	 * entitet artikl kojeg nema u propisanom cjeniku.
	 */
	public function neobjasnjeno(): int {
		return max( 0, $this->ukupno - $this->vidjeno() );
	}

	/** Procjena preostalog vremena u sekundama, ili null ako se jos ne da procijeniti. */
	public function preostalo_s(): ?int {
		if ( ! $this->pokrenuto || $this->vidjeno() <= 0 || $this->ukupno <= 0 ) {
			return null;
		}
		$proteklo = time() - (int) strtotime( $this->pokrenuto . ' UTC' );
		if ( $proteklo <= 0 ) {
			return null;
		}
		$po_entitetu = $proteklo / max( 1, $this->vidjeno() );
		return (int) round( $po_entitetu * max( 0, $this->ukupno - $this->vidjeno() ) );
	}

	public function radi(): bool {
		return Config::STATUS_RADI === $this->status;
	}

	public function zavrsen(): bool {
		return in_array(
			$this->status,
			array( Config::STATUS_GOTOVO, Config::STATUS_PREKINUT, Config::STATUS_PONISTENO ),
			true
		);
	}

	public function ponisten(): bool {
		return Config::STATUS_PONISTENO === $this->status;
	}

	/**
	 * Zabiljezi da je ucinak posla ponisten povratom.
	 *
	 * Zapis o izvrsenju se NE brise: `zavrseno` ostaje, a uz njega stoji kad je i
	 * cime ponisteno. Tako se iz jednog retka procita cijeli slijed — izvrseno pa
	 * vraceno — a ne samo posljednji dogadaj.
	 *
	 * Ne dira posao koji nikad nije zavrsio: ponistiti se moze samo ucinak koji je
	 * postojao.
	 *
	 * @param string $kljuc     Posao ciji se ucinak ponistava.
	 * @param string $povratnik Kljuc posla koji ponistava.
	 * @return bool je li stanje promijenjeno
	 */
	public static function ponisti( string $kljuc, string $povratnik ): bool {
		global $wpdb;

		$sada = current_time( 'mysql', true );

		$pogodenih = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::tablica() . '`
				 SET status = %s, ponisteno = %s, ponistio = %s, azurirano = %s
				 WHERE kljuc = %s AND zavrseno IS NOT NULL AND status <> %s',
				Config::STATUS_PONISTENO,
				$sada,
				$povratnik,
				$sada,
				$kljuc,
				Config::STATUS_RADI
			) // phpcs:ignore
		);

		return $pogodenih > 0;
	}

	/* ------------------------------------------------------------------ log */

	public function zapisi( string $razina, string $poruka ): void {
		global $wpdb;

		$wpdb->insert(
			self::tablica_log(),
			array(
				'kljuc'    => $this->kljuc,
				'razina'   => $razina,
				'odmak'    => $this->vidjeno(),
				'poruka'   => mb_substr( $poruka, 0, 500 ),
				'zapisano' => current_time( 'mysql', true ),
			)
		);

		// Drzi log ogranicenim — na shared hostingu prostor nije besplatan.
		$suvisak = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . self::tablica_log() . '` WHERE kljuc = %s', $this->kljuc ) // phpcs:ignore
		) - Config::MAX_LOG_REDAKA;

		if ( $suvisak > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM `' . self::tablica_log() . '` WHERE kljuc = %s ORDER BY id ASC LIMIT %d',
					$this->kljuc,
					$suvisak
				) // phpcs:ignore
			);
		}
	}

	/** @return object[] */
	public function log( int $koliko = 50 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . self::tablica_log() . '` WHERE kljuc = %s ORDER BY id DESC LIMIT %d',
				$this->kljuc,
				$koliko
			) // phpcs:ignore
		);
	}

	public function ocisti_log(): void {
		global $wpdb;
		$wpdb->delete( self::tablica_log(), array( 'kljuc' => $this->kljuc ) );
	}
}
