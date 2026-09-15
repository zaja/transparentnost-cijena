<?php
/**
 * Posao: upisi "1 kom" artiklima koji se prodaju po komadu.
 *
 * STO RJESAVA
 *
 * Neto kolicina je popunjena na 7 od 3623 artikala, a katalog je prakticki u
 * cijelosti komadna roba. Jedna operacija rjesava polje koje bi inace trazilo
 * oko 3400 rucnih unosa.
 *
 * Za komadnu robu je cijena po jedinici mjere jednaka maloprodajnoj cijeni. To
 * nije zaobilaznica: cijena po jedinici mjere propisana je za robu koja se prodaje
 * po masi, obujmu, duzini ili povrsini. Spil karata se prodaje po komadu, pa mu je
 * jedinica mjere "kom" — i tocna vrijednost je upravo njegova cijena.
 *
 * ZASTO IPAK TRAZI POTVRDU
 *
 * "1 kom" nije tocno za sve. `100 zetona Monte Carlo` je sto komada, `6 spilova`
 * je sest. Upisati im "1 kom" znacilo bi objaviti cijenu po komadu sto odnosno
 * sest puta vecu od stvarne. Posao te artikle NE POPUNJAVA nego izdvaja na pregled
 * — i ne da se pokrenuti dok se popis ne vidi i ne potvrdi.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Podaci\Komadna_Roba;
use CJTR\Podaci\Zapis_Podataka;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;

defined( 'ABSPATH' ) || exit;

final class Kolicina_Kom extends Posao {

	/** Opcija u kojoj stoji potvrda s ekrana. */
	const OPT_POTVRDA = 'kolicina_kom_potvrda';

	public function kljuc(): string {
		return 'kolicina_kom';
	}

	public function naziv(): string {
		return __( 'Upisi "1 kom" artiklima koji se prodaju po komadu', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Vecina artikala prodaje se po komadu — spil karata, poker set, majica. Njima je neto kolicina "1 kom", a cijena po jedinici mjere jednaka je cijeni artikla. Posao to upisuje odjednom. Artikle kojima naziv sadrzi broj (npr. "100 zetona") NE dira nego izdvaja na pregled, jer njima "1 kom" ne bi bilo tocno.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 12;
	}

	public function preduvjet(): string {
		return 'prikupi_podatke';
	}

	public function preduvjet_razlog(): string {
		return __( 'Prvo se pokupi ono sto trgovina vec ima — inace bi se preko poznate kolicine upisalo "1 kom".', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		$potvrda = self::potvrda();

		if ( empty( $potvrda ) ) {
			return __( 'Potvrda jos nije dana. Otvorite "Podaci o proizvodima", pogledajte probni prolaz i potvrdite.', Config::TEXT_DOMAIN );
		}

		$sada = Komadna_Roba::probni_prolaz();

		if ( (int) ( $potvrda['komadna'] ?? -1 ) !== $sada['komadna'] ) {
			return sprintf(
				/* translators: 1: broj pri potvrdi, 2: broj sada */
				__( 'Skup se promijenio otkad je potvrda dana: potvrdeno je %1$d artikala, sada ih je %2$d. Pogledajte i potvrdite ponovno.', Config::TEXT_DOMAIN ),
				(int) $potvrda['komadna'],
				$sada['komadna']
			);
		}

		if ( 0 === $sada['komadna'] ) {
			return __( 'Nema artikala kojima bi se upisalo "1 kom".', Config::TEXT_DOMAIN );
		}

		return '';
	}

	public function ukupno(): int {
		$p = Komadna_Roba::probni_prolaz();
		return $p['ukupno'];
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$kandidati = Komadna_Roba::kandidati( $zadnji_id, $velicina );

		if ( empty( $kandidati ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$zadnji   = 0;
		$upisanih = 0;
		$rez      = Rezultat_Komada::s( 0, null );

		foreach ( $kandidati as $r ) {
			$id     = (int) $r->entity_id;
			$zadnji = max( $zadnji, $id );

			$broj = Komadna_Roba::broj_u_nazivu( (string) $r->naziv );

			if ( null !== $broj ) {
				$rez->preskoci(
					$id,
					sprintf(
						/* translators: %s = broj iz naziva */
						__( 'naziv sadrzi broj (%s) — mozda je pakiranje od vise komada, treba pogledati', Config::TEXT_DOMAIN ),
						$broj
					)
				);
				continue;
			}

			$ishod = Zapis_Podataka::upisi(
				$id,
				Config::POLJE_KOLICINA,
				array(
					'kolicina' => (string) Komadna_Roba::KOLICINA,
					'jedinica' => Komadna_Roba::JEDINICA,
				),
				Config::IZVOR_PODATKA_RUCNO
			);

			if ( ! $ishod['ok'] ) {
				$rez->greska( sprintf( 'ID %d: %s', $id, $ishod['poruka'] ) );
				continue;
			}

			$upisanih++;
		}

		$rez->obradeno  = $upisanih;
		$rez->zadnji_id = $zadnji ?: null;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

		$preostalo = Komadna_Roba::probni_prolaz();

		if ( $preostalo['iznimke'] > 0 ) {
			$stanje->zapisi(
				'upozorenje',
				sprintf(
					/* translators: %d = broj artikala */
					__( '%d artikala nije dobilo kolicinu jer im naziv sadrzi broj. Njima "1 kom" mozda nije tocno — pogledajte popis na ekranu "Podaci o proizvodima" i upisite rucno.', Config::TEXT_DOMAIN ),
					$preostalo['iznimke']
				)
			);
		}

		\CJTR\Prikaz\Cache::ocisti();
	}

	/* ------------------------------------------------------------ potvrda */

	public static function potvrda(): array {
		$v = get_option( Config::option( self::OPT_POTVRDA ), array() );
		return is_array( $v ) ? $v : array();
	}

	public static function potvrdi(): void {
		$p = Komadna_Roba::probni_prolaz();

		update_option(
			Config::option( self::OPT_POTVRDA ),
			array(
				'kad'     => current_time( 'mysql', true ),
				'komadna' => $p['komadna'],
				'iznimke' => $p['iznimke'],
			),
			false
		);
	}

	public static function povuci_potvrdu(): void {
		delete_option( Config::option( self::OPT_POTVRDA ) );
	}
}
