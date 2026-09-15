<?php
/**
 * Posao: generiraj i objavi cjenik.
 *
 * SPAJA SVE PRETHODNE MODULE
 *
 * Cijene (modul 2), dodatne cijene (modul 3), podaci o proizvodima (modul 1),
 * okvir za obradu u komadima (modul 5), redak za cron (modul 6). Ovaj posao ih
 * pretvara u datoteku.
 *
 * RADI I S NEPOTPUNIM PODACIMA
 *
 * Barkod i kategorija su jos na nuli. Polje bez vrijednosti izlazi PRAZNO, nikad
 * izostavljeno — cekati potpune podatke znacilo bi ne objaviti nista, a propis ne
 * poznaje ispriku "jos skupljamo".
 *
 * STRAZA PRIJE SVEGA
 *
 * Ako neki dodatak trenutno mijenja cijene, datoteka se NE generira. Objaviti
 * cijene koje se ne naplacuju gore je od neobjavljivanja: prvo je neistinita
 * tvrdnja, drugo je propust u roku.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Cjenik\Arhiva;
use CJTR\Cjenik\Ime;
use CJTR\Cjenik\Izvor;
use CJTR\Cjenik\Nedosljednosti;
use CJTR\Cjenik\Pisac;
use CJTR\Cjenik\Redak;
use CJTR\Cjenik\Samoprovjera;
use CJTR\Config;
use CJTR\Poslovi\Posao_S_Cijenama;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;

defined( 'ABSPATH' ) || exit;

final class Generiraj_Cjenik extends Posao_S_Cijenama {

	/** Opcija u kojoj stoje putanje datoteka koje se trenutno pisu. */
	const OPT_U_TIJEKU = 'cjenik_u_tijeku';

	public function kljuc(): string {
		return 'generiraj_cjenik';
	}

	public function naziv(): string {
		return __( 'Objavi dnevni cjenik', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Sastavlja propisanu datoteku sa svim artiklima i cijenama, provjerava je, pa tek onda zamjenjuje jucerasnju. Radi se samo od sebe svaki dan; rucno pokretanje treba samo ako nesto provjeravate ili ste upravo mijenjali cijene.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_ODRZAVANJE;
	}

	public function preduvjet(): string {
		return 'sidrena_cijena';
	}

	public function preduvjet_razlog(): string {
		return __( 'Sidrena cijena je obvezan podatak u cjeniku — bez nje bi datoteka izasla s praznim stupcem za svaki artikl.', Config::TEXT_DOMAIN );
	}

	/**
	 * Rok je 8:00 po vremenu trgovine.
	 *
	 * Cron se namjerno pokrece ranije (dijagnostika racuna 6:07 po trgovini), da
	 * posao od 3417 zapisa ima vremena zavrsiti i da preostane prostor ako jedan
	 * prolaz zakaze.
	 */
	public function dnevno(): string {
		return sprintf( '%02d:00', Config::ROK_OBJAVE_SAT );
	}

	public function ukupno(): int {
		return Izvor::ukupno();
	}

	/**
	 * Otvori privremene datoteke.
	 *
	 * Pise se u `.privremeno`, a objavljuje tek nakon provjere. Dok se pise, stara
	 * datoteka ostaje na svom mjestu i posluzuje se.
	 */
	public function prije_pocetka(): void {
		$dir   = trailingslashit( Arhiva::direktorij() );
		$staze = array();

		foreach ( Config::CJENIK_OBLICI as $oblik ) {
			$staze[ $oblik ] = $dir . 'privremeno-' . wp_generate_password( 8, false ) . '.' . $oblik;
		}

		update_option( Config::option( self::OPT_U_TIJEKU ), $staze, false );

		$zaglavlje = array(
			'generirano' => wp_date( 'c' ),
			'objekt'     => Config::OBJEKT_OZNAKA,
			'adresa'     => Config::objekt_adresa(),
			'valuta'     => get_woocommerce_currency(),
		);

		/*
		 * Zaglavlje se ispise i datoteka se OSTAVLJA OTVORENOM u smislu sadrzaja:
		 * korijenski element XML-a zatvara se tek na kraju, u `nakon_zavrsetka()`.
		 *
		 * Prva verzija je ovdje zvala `zatvori()`, sto je odmah ispisalo `</cjenik>`,
		 * pa su svi zapisi zavrsili IZA korijena. CSV to nije primijetio jer nema
		 * korijen — prosao je ispravno sa svih 3417 zapisa, dok je XML bio neparsabilan.
		 * Samoprovjera je to uhvatila i datoteku nije objavila; da provjere nema,
		 * objavljen bi bio pokvaren XML uz ispravan CSV.
		 */
		foreach ( $staze as $oblik => $put ) {
			$pisac = new Pisac( $oblik, $put );
			$pisac->otvori( $zaglavlje );
			$pisac->odvoji();
		}
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$redci = Izvor::komad( $zadnji_id, $velicina );

		if ( empty( $redci ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$staze   = (array) get_option( Config::option( self::OPT_U_TIJEKU ), array() );
		$zadnji  = 0;
		$zapisa  = 0;
		$rez     = Rezultat_Komada::s( 0, null );

		// Datoteke se otvaraju u nadopunjavanju: posao se vrti komad po komad, a
		// svaki komad je zaseban zahtjev.
		$rukovatelji = array();
		foreach ( $staze as $oblik => $put ) {
			$rukovatelji[ $oblik ] = fopen( $put, 'a' );
		}

		foreach ( $redci as $r ) {
			$id     = (int) $r->entity_id;
			$zadnji = max( $zadnji, $id );

			$zapis = Redak::iz( $r );

			if ( '' === $zapis['maloprodajna_cijena'] ) {
				$rez->preskoci( $id, __( 'nema maloprodajne cijene — artikl ne moze u cjenik', Config::TEXT_DOMAIN ) );
				continue;
			}

			foreach ( $rukovatelji as $oblik => $h ) {
				if ( $h ) {
					$this->zapisi_u( $h, $oblik, $zapis );
				}
			}

			$zapisa++;
		}

		foreach ( $rukovatelji as $h ) {
			if ( $h ) {
				fclose( $h );
			}
		}

		$rez->obradeno  = $zapisa;
		$rez->zadnji_id = $zadnji ?: null;
		return $rez;
	}

	/**
	 * Zatvori datoteke, provjeri ih, i tek onda objavi.
	 */
	public function nakon_zavrsetka(): void {
		/*
		 * Stanje se UCITAVA, ne stvara prazno.
		 *
		 * Prva verzija je radila `new Stanje()` samo radi zapisnika, sto je bezopasno
		 * dok se ne pozove `spremi()` — a na putu neuspjeha se pozivalo. Prazan objekt
		 * ima status `ceka` i obradeno 0, pa je spremanje obrisalo stvarni rezultat:
		 * posao koji je obradio 3417 zapisa prikazao bi se kao da nije ni pokrenut.
		 *
		 * Ista pouka kao 13.9. kod ponistenih poslova: stanje mora odrazavati stanje
		 * podataka, a ne ono sto je zadnji pisac slucajno imao u memoriji.
		 */
		$stanje = Stanje::ucitaj( $this->kljuc() );

		$staze = (array) get_option( Config::option( self::OPT_U_TIJEKU ), array() );

		if ( empty( $staze ) ) {
			$stanje->zapisi( 'greska', __( 'Nema privremenih datoteka — generiranje nije ni pocelo.', Config::TEXT_DOMAIN ) );
			return;
		}

		$ocekivano = (int) $stanje->obradeno;

		// Zatvori XML korijenom.
		foreach ( $staze as $oblik => $put ) {
			if ( Config::CJENIK_XML === $oblik && file_exists( $put ) ) {
				file_put_contents( $put, '</' . Config::CJENIK_KORIJEN . ">\n", FILE_APPEND );
			}
		}

		$sve_prolazi = true;

		foreach ( $staze as $oblik => $put ) {
			$nalaz = Samoprovjera::provjeri( $put, $oblik, $ocekivano );

			if ( $nalaz['ok'] ) {
				$stanje->zapisi(
					'info',
					sprintf(
						/* translators: 1: oblik, 2: broj zapisa */
						__( 'Provjera %1$s: u redu, %2$d zapisa.', Config::TEXT_DOMAIN ),
						strtoupper( $oblik ),
						$nalaz['zapisa']
					)
				);
				continue;
			}

			$sve_prolazi = false;

			foreach ( $nalaz['greske'] as $greska ) {
				$stanje->zapisi( 'greska', sprintf( '%s: %s', strtoupper( $oblik ), $greska ) );
			}
		}

		if ( ! $sve_prolazi ) {
			foreach ( $staze as $put ) {
				if ( file_exists( $put ) ) {
					wp_delete_file( $put );
				}
			}

			delete_option( Config::option( self::OPT_U_TIJEKU ) );

			$stanje->zapisi(
				'greska',
				__( 'Datoteka NIJE objavljena. Jucerasnja ostaje na snazi — stari cjenik je bolji od pokvarenog. Provjerite sto je puklo prije iduceg roka.', Config::TEXT_DOMAIN )
			);

			$stanje->poruka = __( 'Provjera nije prosla, cjenik nije objavljen.', Config::TEXT_DOMAIN );
			$stanje->spremi();
			return;
		}

		$broj     = Ime::sljedeci_broj();
		$sada     = time();
		$objave   = array();

		foreach ( $staze as $oblik => $put ) {
			$ime = Ime::datoteka( $oblik, $broj, $sada );

			if ( Arhiva::objavi( $put, $ime, $oblik ) ) {
				$objave[ $oblik ] = Arhiva::stabilni_url( $oblik );
				$stanje->zapisi(
					'info',
					sprintf(
						/* translators: 1: ime datoteke, 2: url */
						__( 'Objavljeno: %1$s — stabilna poveznica %2$s', Config::TEXT_DOMAIN ),
						$ime,
						Arhiva::stabilni_url( $oblik )
					)
				);
				continue;
			}

			$stanje->zapisi( 'greska', sprintf( __( 'Datoteka %s se nije mogla objaviti.', Config::TEXT_DOMAIN ), $ime ) );
		}

		delete_option( Config::option( self::OPT_U_TIJEKU ) );

		update_option(
			Config::option( Config::OPT_ZADNJI_CJENIK ),
			array(
				'kad'    => $sada,
				'zapisa' => $ocekivano,
				'broj'   => $broj,
				'url'    => $objave,
			),
			false
		);

		$this->prijavi_nepravilnosti( $stanje );

		Arhiva::zapisi_indeks();

		$obrisano = Arhiva::pospremi();
		if ( ! empty( $obrisano ) ) {
			// Indeks se pise ponovno jer je pospremanje moglo ukloniti datoteke.
			Arhiva::zapisi_indeks();

			$stanje->zapisi(
				'info',
				sprintf(
					/* translators: 1: broj datoteka, 2: dana */
					__( 'Iz arhive uklonjeno %1$d datoteka starijih od %2$d dana.', Config::TEXT_DOMAIN ),
					count( $obrisano ),
					Config::CJENIK_ARHIVA_DANA
				)
			);
		}
	}

	/* --------------------------------------------------------------- interno */

	/**
	 * Sto je s datotekom u redu, ali u KATALOGU nije.
	 *
	 * Ovo nisu greske generiranja nego nalazi o podacima. Prijavljuju se ovdje jer
	 * je cjenik jedino mjesto gdje se vide zajedno — i jer bi inace prosli tiho.
	 */
	private function prijavi_nepravilnosti( Stanje $stanje ): void {
		$bez = Izvor::bez_cijene();

		if ( ! empty( $bez ) ) {
			$stanje->zapisi(
				'upozorenje',
				sprintf(
					/* translators: 1: broj artikala, 2: primjeri */
					__( '%1$d artikala je objavljeno i vidljivo kupcima, ali nema cijenu — nisu u cjeniku. Primjeri: %2$s.', Config::TEXT_DOMAIN ),
					count( $bez ),
					implode( ', ', array_slice( wp_list_pluck( $bez, 'entity_id' ), 0, 5 ) )
				)
			);
		}

		$decimale = Izvor::previse_decimala();

		if ( ! empty( $decimale ) ) {
			$stanje->zapisi(
				'upozorenje',
				sprintf(
					/* translators: 1: broj artikala, 2: najvise decimala */
					__( '%1$d cijena ima vise od %2$d decimale. Generator ih NE zaokruzuje — izvozi ih kakve jesu. Zaokruzivanje je zaseban posao nad katalogom.', Config::TEXT_DOMAIN ),
					count( $decimale ),
					Config::CJENIK_MAX_DECIMALA
				)
			);
		}

		$n = Nedosljednosti::prebroji();

		/*
		 * Najozbiljniji nalaz ide zasebno i prvi. "87 cijena s vise decimala" je
		 * tvrdnja o podacima i cita se kao sitnica; "49 artikala u objavljenoj
		 * datoteci ima dvije razlicite brojke za istu cijenu" je tvrdnja o datoteci.
		 */
		if ( $n['dvije_cijene'] > 0 ) {
			$stanje->zapisi(
				'greska',
				sprintf(
					/* translators: 1: broj artikala, 2: primjeri */
					__( 'OZBILJNO: %1$d artikala u objavljenoj datoteci ima DVIJE RAZLICITE BROJKE za istu cijenu — maloprodajnu i cijenu po jedinici mjere, a prodaju se po komadu pa moraju biti jednake. Primjeri: %2$s. Rjesava se zaokruzivanjem kataloga PRIJE objave, ne u generatoru.', Config::TEXT_DOMAIN ),
					$n['dvije_cijene'],
					implode( '; ', array_slice( $n['primjeri_dvije'], 0, 5 ) )
				)
			);
		}

		if ( $n['mp_veca_od_redovne'] > 0 ) {
			$stanje->zapisi(
				'greska',
				sprintf(
					/* translators: 1: broj artikala, 2: primjeri */
					__( '%1$d artikala ima maloprodajnu cijenu VECU od redovne. Primjeri: %2$s.', Config::TEXT_DOMAIN ),
					$n['mp_veca_od_redovne'],
					implode( '; ', array_slice( $n['primjeri_mp'], 0, 5 ) )
				)
			);
		}

		if ( $n['dodatna_veca_od_redovne'] > 0 ) {
			$stanje->zapisi(
				'info',
				sprintf(
					/* translators: %d = broj artikala */
					__( '%d artikala ima sidrenu cijenu visu od redovne — ti su artikli poskupjeli. Nije greska, ali je razlog zbog kojeg se sidrena cijena kupcu ne smije prikazati precrtano.', Config::TEXT_DOMAIN ),
					$n['dodatna_veca_od_redovne']
				)
			);
		}
	}

	/** @param resource $h */
	private function zapisi_u( $h, string $oblik, array $zapis ): void {
		if ( Config::CJENIK_CSV === $oblik ) {
			// CSV je mreza: svaki redak ima sve stupce. Neprimjenjivo polje izlazi
			// kao prazna celija — razliku prema "ne znamo" nosi XML.
			$redak = array();
			foreach ( array_keys( Config::shema() ) as $kljuc ) {
				$redak[] = (string) ( $zapis[ $kljuc ] ?? '' );
			}
			fputcsv( $h, $redak );
			return;
		}

		fwrite( $h, "\t<" . Config::CJENIK_ARTIKL . ">\n" );

		foreach ( Config::shema() as $kljuc => $element ) {
			// null = ne primjenjuje se za ovaj artikl, pa elementa NEMA.
			if ( ! array_key_exists( $kljuc, $zapis ) || null === $zapis[ $kljuc ] ) {
				continue;
			}

			$v = html_entity_decode( (string) $zapis[ $kljuc ], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$v = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v );

			fwrite(
				$h,
				"\t\t<" . $element . '>' . htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</' . $element . ">\n"
			);
		}

		fwrite( $h, "\t</" . Config::CJENIK_ARTIKL . ">\n" );
	}
}
