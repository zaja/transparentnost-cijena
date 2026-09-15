<?php
/**
 * Rezultat jedne provjere.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Rezultat {

	const OK    = 'ok';
	const UPOZ  = 'upozorenje';
	const LOSE  = 'lose';
	const INFO  = 'info';

	/** @var string */
	public $naslov;

	/** @var string */
	public $status = self::INFO;

	/**
	 * @var array<int,array{0:string,1:string}> lista parova [naziv, vrijednost]
	 *
	 * Namjerno lista, ne mapa: isti dodatak legitimno se pojavljuje u vise skupina
	 * (npr. i mijenja prikaz i moze mijenjati vrijednost). S mapom bi drugi zapis
	 * tiho prebrisao prvi i brojka u zaglavlju ne bi odgovarala popisu.
	 */
	public $stavke = array();

	/** @var string sto nalaz znaci, jezikom netehnicke osobe */
	public $znacenje = '';

	/** @var string sto uciniti; prazno ako nema sto */
	public $postupak = '';

	/** @var string[] blokovi za kopiranje (npr. cron redak) */
	public $blokovi = array();

	public function __construct( string $naslov ) {
		$this->naslov = $naslov;
	}

	public function stavka( string $naziv, $vrijednost ): self {
		$this->stavke[] = array(
			$naziv,
			is_bool( $vrijednost ) ? ( $vrijednost ? 'da' : 'ne' ) : (string) $vrijednost,
		);
		return $this;
	}

	public function status( string $status ): self {
		$this->status = $status;
		return $this;
	}

	/** Postavi status samo ako je ozbiljniji od trenutnog. */
	public function pogorsaj( string $status ): self {
		$tezina = array( self::INFO => 0, self::OK => 1, self::UPOZ => 2, self::LOSE => 3 );
		if ( ( $tezina[ $status ] ?? 0 ) > ( $tezina[ $this->status ] ?? 0 ) ) {
			$this->status = $status;
		}
		return $this;
	}

	public function znacenje( string $t ): self {
		$this->znacenje = $t;
		return $this;
	}

	public function postupak( string $t ): self {
		$this->postupak = $t;
		return $this;
	}

	public function blok( string $naslov, string $sadrzaj ): self {
		$this->blokovi[ $naslov ] = $sadrzaj;
		return $this;
	}

	public function oznaka(): string {
		switch ( $this->status ) {
			case self::OK:
				return __( 'u redu', Config::TEXT_DOMAIN );
			case self::UPOZ:
				return __( 'pripazite', Config::TEXT_DOMAIN );
			case self::LOSE:
				return __( 'treba rijesiti', Config::TEXT_DOMAIN );
			default:
				return __( 'podatak', Config::TEXT_DOMAIN );
		}
	}
}
