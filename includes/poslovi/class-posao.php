<?php
/**
 * Ugovor koji svaki dugi posao mora ispuniti.
 *
 * Posao ne zna nista o tome tko ga pokrece — isti kod vrti se i iz admina
 * (AJAX, komad po komad) i iz rasporeda na posluzitelju.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

abstract class Posao {

	/** Jedinstveni kljuc posla. Koristi se kao id u bazi i u URL-u. */
	abstract public function kljuc(): string;

	/** Naziv za korisnika. */
	abstract public function naziv(): string;

	/** Kratko objasnjenje sto posao radi, jezikom netehnicke osobe. */
	abstract public function opis(): string;

	/** Ukupan broj entiteta koje treba obraditi. Racuna se jednom, pri pokretanju. */
	abstract public function ukupno(): int;

	/**
	 * Obradi jedan komad, pocevsi IZA zadanog ID-a.
	 *
	 * VAZNO — ugovor prema pozivatelju:
	 *
	 * 1. Upit MORA biti keyset: `WHERE ID > $zadnji_id ORDER BY ID ASC LIMIT $velicina`.
	 *    Nikad LIMIT/OFFSET. Odmak po broju retka je determinističan samo ako se
	 *    skup ne mijenja tijekom posla — a na zivom shopu se mijenja: netko objavi
	 *    proizvod, skine ga ili doda varijaciju dok posao traje. Brisanje retka
	 *    ispod trenutne pozicije pomakne niz i jedan entitet se nikad ne obradi.
	 *    Kod generatora cjenika to je artikl kojeg tiho nema u datoteci.
	 *
	 * 2. Rezultat mora nositi `zadnji_id` — najveci vidjeni ID. Ako komad nije
	 *    vidio nijedan redak, ostavi ga null: to okviru znaci da je posao gotov.
	 *
	 * 3. Obrada mora biti idempotentna: isti komad smije se obraditi dvaput bez
	 *    stete. Okvir to ne moze zajamciti jer ne zna sto posao radi, ali pri
	 *    prekidu procesa komad se moze ponoviti.
	 *
	 * 4. Entitet koji je namjerno izostavljen prijavljuje se kroz
	 *    `Rezultat_Komada::preskoci()`, s razlogom. Tiho izostavljanje nije opcija.
	 *
	 * @param int $zadnji_id ID nakon kojega se nastavlja. 0 na pocetku.
	 * @param int $velicina  Koliko entiteta obraditi sada.
	 */
	abstract public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada;

	/** Poziva se jednom prije prvog komada. */
	public function prije_pocetka(): void {}

	/** Poziva se jednom nakon zadnjeg komada. */
	public function nakon_zavrsetka(): void {}

	/** Smije li se posao pokrenuti sada. Vrati poruku ako ne smije, inace prazno. */
	public function zapreka(): string {
		return '';
	}

	/**
	 * Kljuc posla ciji ucinak ovaj posao PONISTAVA, ili prazno.
	 *
	 * Veza stoji u okviru, a ne u rucnom uparivanju po imenu, jer pogada SVAKI par
	 * posao/povrat. Kad povrat zavrsi, okvir sam prebaci ponisteni posao u stanje
	 * "gotovo, pa ponisteno" — nijedan povrat to ne mora pamtiti.
	 *
	 * Zasto to uopce treba: 13.9.2026. povrat je vratio 845 artikala, a posao koji
	 * ih je promijenio i dalje je na ekranu stajao kao "gotovo, 845". Izgledalo je
	 * da su vrijednosti upisane; nisu bile. Stanje posla mora odrazavati stanje
	 * PODATAKA, ne samo cinjenicu da je kod jednom prosao.
	 */
	public function ponistava(): string {
		return '';
	}

	/**
	 * Kojoj skupini posao pripada.
	 *
	 * Posao povrata se NE izjasnjava — skupina mu se izvodi iz `ponistava()`, pa se
	 * povrat ne moze zabunom svrstati medu obicne poslove. Jedan izvor, jedna
	 * istina: tko nesto ponistava, taj je u skupini ponistavanja.
	 */
	final public function skupina(): string {
		if ( '' !== $this->ponistava() ) {
			return Config::SKUPINA_VRACANJE;
		}
		return $this->vlastita_skupina();
	}

	/** Skupina za posao koji nista ne ponistava. Zadano: redovan rad. */
	protected function vlastita_skupina(): string {
		return Config::SKUPINA_ODRZAVANJE;
	}

	/**
	 * Mjesto u redoslijedu pripreme. Manji broj ide prvi, 0 znaci nerasporeden.
	 *
	 * Broj je ovdje, a ne u ekranu, jer ekran ne smije znati redoslijed poslova —
	 * inace bi svaki novi modul trazio izmjenu ekrana da bi se uklopio.
	 */
	public function korak(): int {
		return 0;
	}

	/**
	 * Kljuc posla koji mora biti izvrsen prije ovoga, ili prazno.
	 *
	 * Sluzi PRIKAZU: dok preduvjet nije ispunjen, posao je na ekranu onemogucen uz
	 * recenicu cega ceka. Ne zamjenjuje `zapreka()` — ona je ta koja stvarno brani
	 * pokretanje. Ovo je zato da administrator vidi redoslijed prije nego pokusa,
	 * a ne da otkrije poredak kroz poruke o odbijanju.
	 */
	public function preduvjet(): string {
		return '';
	}

	/**
	 * Recenica koja objasnjava cega posao ceka. Prazno = izvedi iz naziva preduvjeta.
	 */
	public function preduvjet_razlog(): string {
		return '';
	}

	/**
	 * Treba li se posao izvrsiti jednom dnevno. Prazno = ne treba.
	 *
	 * Vraca ROK u obliku 'H:i', po vremenu trgovine — ne vrijeme pokretanja.
	 * Pokretanje odreduje cron na posluzitelju i namjerno je ranije; rok je ono
	 * sto propis trazi i sto ekran prikazuje.
	 *
	 * Deklaracija stoji uz posao, a ne u rasporedu, iz istog razloga kao i
	 * `korak()`: novi posao koji treba raditi svaki dan uklopi se sam.
	 */
	public function dnevno(): string {
		return '';
	}
}
