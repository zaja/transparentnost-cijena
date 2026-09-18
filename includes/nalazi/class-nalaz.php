<?php
/**
 * Jedan nalaz — problem opisan jezikom onoga tko ga ima.
 *
 * ZASTO NALAZ, A NE POSAO
 *
 * Administrator ne otvara admin misleci "pokrenuo bih promociju". Otvara ga
 * misleci "moram se uskladiti, sto mi fali". Popis operacija odgovara na pitanje
 * koje nitko nije postavio.
 *
 * Nalaz nosi isti posao ispod sebe, istu potvrdu i isti povrat. Razlika je u tome
 * sto se zna zasto bi se to kliknulo.
 *
 * KAD GUMB POSTOJI, A KAD NE
 *
 * Gumb "Rijesi ovo" postoji samo kad oboje vrijedi:
 *
 *   1. postoji REGISTRIRAN posao koji to rjesava, i
 *   2. rjesenje NIJE trgovceva odluka.
 *
 * Drugi uvjet je vazniji. Dodatak koji sam odluci da akcija vise nije akcija, ili
 * da cijena od sada ima dvije decimale, donio je poslovnu odluku umjesto vlasnika
 * trgovine. Takav nalaz nudi popis i objasnjenje — i nista vise.
 *
 * Prvi uvjet dolazi besplatno: u javnom paketu ti poslovi nisu registrirani, pa
 * isti nalaz ondje sam od sebe ostane bez gumba.
 *
 * @package CJTR
 */

namespace CJTR\Nalazi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Nalaz {

	/** Bez ovoga obveza nije ispunjena. */
	const ZAPREKA = 'zapreka';

	/** Objavljuje se nesto netocno ili nepotpuno. */
	const VAZNO = 'vazno';

	/** Vrijedi napraviti, ne gori. */
	const SAVJET = 'savjet';

	/** Redoslijed vaznosti. Ekran ih slaze po ovome, ne po redoslijedu izvodenja. */
	const REDOSLIJED = array( self::ZAPREKA, self::VAZNO, self::SAVJET );

	/** @var string */
	public $kljuc;

	/** @var string */
	public $vaznost = self::VAZNO;

	/** @var string naslov s brojkom koja nesto znaci */
	public $naslov = '';

	/** @var string sto to znaci za kupca ili za propis */
	public $objasnjenje = '';

	/** @var string sto uciniti, kad dodatak to ne moze sam */
	public $postupak = '';

	/** @var string kljuc posla koji ovo rjesava, ili prazno */
	public $posao = '';

	/** @var string natpis na gumbu */
	public $gumb = '';

	/** @var string adresa ekrana na kojem se rjesava, ili prazno */
	public $ekran = '';

	/** @var string natpis na poveznici prema ekranu */
	public $ekran_natpis = '';

	/** @var string adresa za preuzimanje popisa, ili prazno */
	public $popis = '';

	/** @var string natpis na poveznici popisa */
	public $popis_natpis = '';

	/** @var string[] kljucevi nalaza koje ovaj potiskuje */
	public $potiskuje = array();

	/** @var string doslovni tekst koji se prikazuje umjesto gumba (npr. cron redak) */
	public $doslovno = '';

	/** @var string naslov iznad doslovnog teksta */
	public $doslovno_naslov = '';

	public function __construct( string $kljuc ) {
		$this->kljuc        = $kljuc;
		$this->popis_natpis = __( 'Pogledaj popis', Config::TEXT_DOMAIN );
	}

	/* ------------------------------------------------------------- slaganje */

	public function vaznost( string $v ): self {
		$this->vaznost = $v;
		return $this;
	}

	public function naslov( string $v ): self {
		$this->naslov = $v;
		return $this;
	}

	public function objasnjenje( string $v ): self {
		$this->objasnjenje = $v;
		return $this;
	}

	public function postupak( string $v ): self {
		$this->postupak = $v;
		return $this;
	}

	/**
	 * Posao koji ovo rjesava.
	 *
	 * Ne provjerava se ovdje je li registriran — to radi `moze_rijesiti()` u
	 * trenutku prikaza, jer se registar moze razlikovati od paketa do paketa.
	 */
	public function rjesava( string $kljuc_posla, string $gumb ): self {
		$this->posao = $kljuc_posla;
		$this->gumb  = $gumb;
		return $this;
	}

	public function ekran( string $url, string $natpis ): self {
		$this->ekran        = $url;
		$this->ekran_natpis = $natpis;
		return $this;
	}

	public function popis( string $url, string $natpis = '' ): self {
		$this->popis = $url;
		if ( '' !== $natpis ) {
			$this->popis_natpis = $natpis;
		}
		return $this;
	}

	public function potiskuje( string ...$kljucevi ): self {
		$this->potiskuje = array_merge( $this->potiskuje, $kljucevi );
		return $this;
	}

	public function doslovno( string $naslov, string $tekst ): self {
		$this->doslovno_naslov = $naslov;
		$this->doslovno        = $tekst;
		return $this;
	}

	/* -------------------------------------------------------------- citanje */

	/**
	 * Smije li se ponuditi gumb za rjesavanje.
	 *
	 * TRI UVJETA, I SVA TRI SU NUZNA
	 *
	 * 1. Nalaz uopce imenuje posao.
	 * 2. Taj je posao registriran u ovom paketu.
	 * 3. Posao se u ovom trenutku MOZE pokrenuti — nema zapreku.
	 *
	 * Treci je dodan nakon sto se pokazalo sto se bez njega dogada: nalaz o cijenama
	 * s vise od dvije decimale nudio je gumb "Svedi na dvije decimale", posao je
	 * trazio potvrdu koja jos nije dana, i klik nije mijenjao nista. Nalaz bi se
	 * pojavio ponovno, jednak kao prije.
	 *
	 * Gumb koji ne radi gori je od gumba kojeg nema: prvi put izgleda kao kvar
	 * dodatka, drugi put kao da problem nije stvaran.
	 *
	 * Nalaz ostaje u oba slucaja — problem je stvaran i bez nas. Mijenja se samo to
	 * nudi li se radnja ili se kaze sto je prije nje potrebno.
	 */
	public function moze_rijesiti(): bool {
		return '' === $this->zapreka_posla() && '' !== $this->posao;
	}

	/**
	 * Zasto se posao ne moze pokrenuti sada, ili prazno.
	 *
	 * Vraca prazno i kad posla uopce nema — "nema zapreke" nije isto sto i "moze se
	 * rijesiti", pa se ta dva pitanja postavljaju odvojeno.
	 */
	public function zapreka_posla(): string {
		if ( '' === $this->posao ) {
			return '';
		}

		$posao = \CJTR\Poslovi\Registar::nadi( $this->posao );

		if ( null === $posao ) {
			return '';
		}

		return (string) $posao->zapreka();
	}

	public function tezina(): int {
		$i = array_search( $this->vaznost, self::REDOSLIJED, true );
		return ( false === $i ) ? count( self::REDOSLIJED ) : (int) $i;
	}
}
