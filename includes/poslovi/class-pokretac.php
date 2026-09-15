<?php
/**
 * Pokretac — jedini kod koji stvarno obraduje komad.
 *
 * Zovu ga dva pokretaca: AJAX iz admina i raspored na posluzitelju. Namjerno
 * je isti kod, da se ne moze dogoditi da rucno pokretanje radi nesto drugo
 * od automatskog.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Pokretac {

	/** Pokreni posao od pocetka. */
	public static function pokreni( string $kljuc ): Stanje {
		$posao = Registar::nadi( $kljuc );
		if ( ! $posao ) {
			$s          = new Stanje();
			$s->kljuc   = $kljuc;
			$s->status  = Config::STATUS_GRESKA;
			$s->poruka  = __( 'Posao ne postoji.', Config::TEXT_DOMAIN );
			return $s;
		}

		$zapreka = $posao->zapreka();
		if ( '' !== $zapreka ) {
			// Zapreka NIJE greska. Posao koji se ne treba pokretati vec je mozda
			// uspjesno zavrsio — upisivanje greske preko toga izbrisalo bi zapis
			// da je prosao. Zato se status ne dira, samo se vrati poruka.
			$s         = Stanje::ucitaj( $kljuc );
			$s->poruka = $zapreka;
			return $s;
		}

		$s = Stanje::ucitaj( $kljuc );

		// Ponovno pokretanje ponistenog posla: ako je prijasnji rezultat ponisten,
		// to mora ostati zapisano — ali u ZAPISNIKU, ne u stanju. Stanje opisuje
		// sadasnjost, a nova izvedba je zamjenjuje. Ostavljen datum ponistenja uz
		// svjeze "gotovo" bio bi ista vrsta zamke koju ovo rjesava, samo obrnuta.
		$bio_ponisten = $s->ponisten();
		$ponisteno_ts = $s->ponisteno;

		$s->ocisti_log();

		if ( $bio_ponisten ) {
			$s->zapisi(
				'info',
				sprintf(
					/* translators: %s = datum ranijeg ponistenja */
					__( 'Raniji rezultat ovog posla ponisten je %s. Pokrece se iznova.', Config::TEXT_DOMAIN ),
					$ponisteno_ts ? wp_date( 'd.m.Y. H:i', strtotime( $ponisteno_ts . ' UTC' ) ) : '?'
				)
			);
		}

		$s->status        = Config::STATUS_RADI;
		$s->ukupno        = $posao->ukupno();
		$s->obradeno      = 0;
		$s->preskoceno    = 0;
		$s->gresaka       = 0;
		$s->zadnji_id     = 0;
		$s->poruka        = '';
		$s->zakljucano_do = null;
		$s->pokrenuto     = current_time( 'mysql', true );
		$s->zavrseno      = null;
		$s->ponisteno     = null;
		$s->ponistio      = null;
		$s->spremi();

		$posao->prije_pocetka();
		$s->zapisi( 'info', sprintf( __( 'Pokrenuto. Artikala i varijanti za obradu: %d.', Config::TEXT_DOMAIN ), $s->ukupno ) );

		if ( 0 === $s->ukupno ) {
			return self::zavrsi( $s, $posao );
		}

		Raspored::zakazi( $kljuc );

		return $s;
	}

	public static function pauziraj( string $kljuc ): Stanje {
		$s = Stanje::ucitaj( $kljuc );
		if ( $s->radi() ) {
			$s->status = Config::STATUS_PAUZA;
			$s->spremi();
			$s->zapisi( 'info', __( 'Pauzirano.', Config::TEXT_DOMAIN ) );
		}
		Raspored::otkazi( $kljuc );
		return $s;
	}

	public static function nastavi( string $kljuc ): Stanje {
		$s = Stanje::ucitaj( $kljuc );
		if ( Config::STATUS_PAUZA === $s->status ) {
			$s->status        = Config::STATUS_RADI;
			$s->zakljucano_do = null;
			$s->spremi();
			$s->zapisi( 'info', sprintf( __( 'Nastavljeno od %d.', Config::TEXT_DOMAIN ), $s->obradeno ) );
			Raspored::zakazi( $kljuc );
		}
		return $s;
	}

	public static function prekini( string $kljuc ): Stanje {
		$s              = Stanje::ucitaj( $kljuc );
		$s->status      = Config::STATUS_PREKINUT;
		$s->zavrseno    = current_time( 'mysql', true );
		$s->zakljucano_do = null;
		$s->spremi();
		$s->zapisi( 'info', sprintf( __( 'Prekinuto na %d od %d.', Config::TEXT_DOMAIN ), $s->obradeno, $s->ukupno ) );
		Raspored::otkazi( $kljuc );
		return $s;
	}

	/**
	 * Obradi TOCNO JEDAN komad. Ovo je srce okvira.
	 *
	 * Vraca stanje nakon obrade. Ako posao nije u stanju rada ili ga je vec
	 * preuzeo drugi proces, vraca zateceno stanje bez obrade.
	 */
	public static function obradi_jedan_komad( string $kljuc ): Stanje {
		$posao = Registar::nadi( $kljuc );
		$s     = Stanje::ucitaj( $kljuc );

		if ( ! $posao || ! $s->radi() ) {
			return $s;
		}

		// Zastita od dvostrukog pokretanja.
		if ( ! $s->preuzmi() ) {
			return $s;
		}

		$velicina = self::velicina_komada();

		try {
			$rezultat = $posao->obradi( $s->zadnji_id, $velicina );
		} catch ( \Throwable $e ) {
			$s->gresaka++;
			$s->zapisi( 'greska', sprintf( __( 'Komad iza ID %d: %s', Config::TEXT_DOMAIN ), $s->zadnji_id, $e->getMessage() ) );
			$s->otkljucaj();

			if ( $s->gresaka >= Config::MAX_GRESAKA ) {
				$s->status   = Config::STATUS_GRESKA;
				$s->poruka   = __( 'Previse gresaka zaredom. Posao je zaustavljen.', Config::TEXT_DOMAIN );
				$s->zavrseno = current_time( 'mysql', true );
				$s->spremi();
				Raspored::otkazi( $kljuc );
			} else {
				$s->spremi();
			}
			return Stanje::ucitaj( $kljuc );
		}

		foreach ( $rezultat->greske as $g ) {
			$s->gresaka++;
			$s->zapisi( 'greska', $g );
		}

		foreach ( $rezultat->preskoceno as $entity_id => $razlog ) {
			$s->preskoceno++;
			$s->zapisi( 'preskoceno', sprintf( __( 'ID %1$d preskocen: %2$s', Config::TEXT_DOMAIN ), $entity_id, $razlog ) );
		}

		$s->obradeno += $rezultat->obradeno;

		// Kursor se pomice na najveci vidjeni ID. Prazan komad znaci kraj — ne
		// oslanjamo se na brojac, jer se katalog tijekom posla moze promijeniti.
		if ( ! $rezultat->prazan() ) {
			$s->zadnji_id = (int) $rezultat->zadnji_id;
		}

		$s->spremi();
		$s->otkljucaj();

		if ( $rezultat->prazan() ) {
			return self::zavrsi( Stanje::ucitaj( $kljuc ), $posao );
		}

		Raspored::zakazi( $kljuc );

		return Stanje::ucitaj( $kljuc );
	}

	private static function zavrsi( Stanje $s, Posao $posao ): Stanje {
		// Samo jedan proces smije zavrsiti posao. Bez ovoga dva procesa koja su
		// oba dosla do praznog komada zapisu dvostruki zapisnik i dvaput izvrse
		// zavrsni posao — sto je kod posla koji nesto pokrece stvarna steta.
		if ( ! $s->preuzmi_zavrsetak( Config::STATUS_GOTOVO ) ) {
			return Stanje::ucitaj( $s->kljuc );
		}

		$posao->nakon_zavrsetka();
		$s = Stanje::ucitaj( $s->kljuc );
		$s->zapisi(
			'info',
			sprintf(
				/* translators: 1: obradeno, 2: preskoceno, 3: gresaka */
				__( 'Gotovo. Obradeno %1$d, preskoceno %2$d, gresaka %3$d.', Config::TEXT_DOMAIN ),
				$s->obradeno,
				$s->preskoceno,
				$s->gresaka
			)
		);

		// Ako se ukupno i vidjeno ne poklapaju, to mora ostati zapisano.
		$razlika = $s->neobjasnjeno();
		if ( $razlika > 0 ) {
			$s->zapisi(
				'upozorenje',
				sprintf(
					/* translators: 1: razlika, 2: ukupno */
					__( 'PAZNJA: %1$d od %2$d artikala nije ni obradeno ni preskoceno. Katalog se vjerojatno promijenio tijekom posla. Provjerite prije nego se rezultat koristi.', Config::TEXT_DOMAIN ),
					$razlika,
					$s->ukupno
				)
			);
			$s->poruka = sprintf(
				/* translators: %d = broj entiteta */
				__( '%d artikala nije objasnjeno — vidi zapisnik.', Config::TEXT_DOMAIN ),
				$razlika
			);
			$s->spremi();
		}

		self::ponisti_izvor( $s, $posao );

		Raspored::otkazi( $s->kljuc );

		return $s;
	}

	/**
	 * Ako je ovo bio posao povrata, prebaci ponisteni posao u trece stanje.
	 *
	 * Okvir to radi sam, na temelju `Posao::ponistava()`. Da je prepusteno svakom
	 * povratu posebno, jedan bi se jednom zaboravio — a posljedica je ekran koji
	 * tvrdi da je gotovo ono cega vise nema.
	 */
	private static function ponisti_izvor( Stanje $s, Posao $posao ): void {
		$izvor = $posao->ponistava();
		if ( '' === $izvor ) {
			return;
		}

		// Ponistava se samo ako je povrat doista nesto vratio. Povrat koji je prosao
		// prazan nije promijenio stanje podataka, pa ga ne smije ni prijaviti.
		if ( $s->obradeno <= 0 ) {
			return;
		}

		if ( ! Stanje::ponisti( $izvor, $s->kljuc ) ) {
			return;
		}

		$ponisteni        = new Stanje();
		$ponisteni->kljuc = $izvor;
		$ponisteni->zapisi(
			'upozorenje',
			sprintf(
				/* translators: 1: naziv posla povrata, 2: broj entiteta */
				__( 'Ucinak ovog posla PONISTEN je poslom "%1$s" — vraceno %2$d artikala. Rezultat iznad vise ne opisuje stanje podataka. Zeli li se ponovno, posao treba pokrenuti iznova.', Config::TEXT_DOMAIN ),
				$posao->naziv(),
				$s->obradeno
			)
		);

		$s->zapisi(
			'info',
			sprintf(
				/* translators: %s = kljuc ponistenog posla */
				__( 'Posao "%s" oznacen je kao ponisten — njegov rezultat vise ne opisuje stanje podataka.', Config::TEXT_DOMAIN ),
				$izvor
			)
		);
	}

	/** Velicina komada koju je izmjerila dijagnostika, uz sigurne granice. */
	public static function velicina_komada(): int {
		$v = (int) get_option( Config::option( Config::OPT_VELICINA_KOMADA ), 0 );
		if ( $v <= 0 ) {
			$v = Config::BATCH_ZADANO;
		}
		return max( Config::BATCH_MIN, min( Config::BATCH_MAX, $v ) );
	}
}
