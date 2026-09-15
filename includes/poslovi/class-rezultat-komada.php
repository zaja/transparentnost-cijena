<?php
/**
 * Sto je jedan komad napravio.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

defined( 'ABSPATH' ) || exit;

final class Rezultat_Komada {

	/** @var int koliko je entiteta stvarno obradeno */
	public $obradeno = 0;

	/**
	 * Najveci ID koji je ovaj komad vidio. Postaje pocetak iduceg komada.
	 * Ostaje null ako komad nije vidio nijedan redak — tada je posao gotov.
	 *
	 * @var int|null
	 */
	public $zadnji_id = null;

	/**
	 * Entiteti koje je posao NAMJERNO preskocio, s razlogom.
	 * entity_id => razlog
	 *
	 * Za generator cjenika preskocen entitet znaci artikl kojeg nema u propisanoj
	 * datoteci. To se ne smije dogoditi neopazeno, pa okvir preskocene zbraja
	 * odvojeno od obradenih i prikazuje ih prije nego ijedna datoteka izade.
	 *
	 * @var array<int,string>
	 */
	public $preskoceno = array();

	/** @var string[] greske unutar ovog komada; komad se zbog njih ne prekida */
	public $greske = array();

	public static function s( int $obradeno, ?int $zadnji_id = null ): self {
		$r            = new self();
		$r->obradeno  = $obradeno;
		$r->zadnji_id = $zadnji_id;
		return $r;
	}

	public function preskoci( int $entity_id, string $razlog ): self {
		$this->preskoceno[ $entity_id ] = $razlog;
		return $this;
	}

	public function greska( string $poruka ): self {
		$this->greske[] = $poruka;
		return $this;
	}

	/** Je li komad vidio ijedan redak. Prazan komad znaci kraj posla. */
	public function prazan(): bool {
		return null === $this->zadnji_id;
	}
}
