<?php
/**
 * Nalaz straze nad dinamickim cijenama.
 *
 * @package CJTR
 */

namespace CJTR\Cijene;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Nalaz {

	/** @var bool smije li posao koji cita cijene poceti */
	public $prolazi = false;

	/** @var int koliko entiteta iz uzorka ima razlicitu cijenu */
	public $razlika = 0;

	/** @var int velicina uzorka */
	public $uzorak = 0;

	/** @var string[] */
	public $primjeri = array();

	/** @var array<int,array{dodatak:string,koliko:int,gdje:string}> */
	public $pravila = array();

	/** @var string datum i vrijeme provjere, UTC */
	public $provjereno = '';

	public function zastario(): bool {
		if ( '' === $this->provjereno ) {
			return true;
		}
		$kad = strtotime( $this->provjereno . ' UTC' );
		return ( time() - $kad ) > Straza::VRIJEDI_SATI * HOUR_IN_SECONDS;
	}

	/** Zasto posao ne smije poceti. Prazno ako smije. */
	public function zapreka(): string {
		if ( $this->prolazi ) {
			return '';
		}

		$dijelovi = array();

		foreach ( $this->pravila as $p ) {
			$dijelovi[] = sprintf(
				/* translators: 1: dodatak, 2: koliko pravila, 3: gdje se nalaze */
				__( '%1$s ima %2$d aktivnih pravila (%3$s)', Config::TEXT_DOMAIN ),
				$p['dodatak'],
				$p['koliko'],
				$p['gdje']
			);
		}

		if ( $this->razlika > 0 ) {
			$dijelovi[] = sprintf(
				/* translators: 1: broj razlika, 2: velicina uzorka */
				__( 'kod %1$d od %2$d provjerenih artikala cijena u bazi nije ona koja se primjenjuje', Config::TEXT_DOMAIN ),
				$this->razlika,
				$this->uzorak
			);
		}

		return sprintf(
			/* translators: %s = popis nalaza */
			__( 'Cijene se mijenjaju u hodu: %s. Dok je tako, objavljeni cjenik ne bi sadrzavao cijene koje se stvarno naplacuju, pa posao nije pokrenut.', Config::TEXT_DOMAIN ),
			implode( '; ', $dijelovi )
		);
	}

	public function kao_polje(): array {
		return array(
			'prolazi'    => $this->prolazi,
			'razlika'    => $this->razlika,
			'uzorak'     => $this->uzorak,
			'primjeri'   => $this->primjeri,
			'pravila'    => $this->pravila,
			'provjereno' => $this->provjereno,
		);
	}

	public static function iz_polja( array $p ): self {
		$n             = new self();
		$n->prolazi    = ! empty( $p['prolazi'] );
		$n->razlika    = (int) ( $p['razlika'] ?? 0 );
		$n->uzorak     = (int) ( $p['uzorak'] ?? 0 );
		$n->primjeri   = (array) ( $p['primjeri'] ?? array() );
		$n->pravila    = (array) ( $p['pravila'] ?? array() );
		$n->provjereno = (string) ( $p['provjereno'] ?? '' );
		return $n;
	}
}
