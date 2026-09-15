<?php
/**
 * Provjera: racunaju li pretvorbe jedinica i barkod ispravno.
 *
 * ZASTO JE OVO DIJAGNOSTIKA, A NE VANJSKI TEST
 *
 * Produkcija je na shared hostingu bez SSH pristupa. Test koji se pokrece iz
 * terminala ondje ne postoji, pa bi jedina provjera bila "radilo je kod nas".
 * Ovako vlasnik trgovine sam vidi prolaze li racuni na njegovom posluzitelju,
 * s njegovom verzijom PHP-a i njegovim postavkama zaokruzivanja.
 *
 * STO SE PROVJERAVA
 *
 * Pretvorbe jedinica (gdje greska od faktora 1000 ne izgleda kao greska),
 * multipack (gdje "6x0,5 l" lako postane 0,5 l), kontrolna znamenka barkoda i
 * cijena po jedinici mjere.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Cijene\Zaokruzivanje;
use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;
use CJTR\Podaci\Cijena_Po_Jedinici;
use CJTR\Podaci\Gtin;
use CJTR\Podaci\Kolicina;

defined( 'ABSPATH' ) || exit;

final class Racun extends Provjera {

	public function kljuc(): string {
		return 'racun';
	}

	public function izvrsi(): Rezultat {
		$r = new Rezultat( __( 'Racuni: jedinice, barkod, cijena po jedinici', Config::TEXT_DOMAIN ) );

		$r->znacenje(
			__( 'Greska u pretvorbi jedinica ne izgleda kao greska — 250 g upisano kao 250 kg daje cijenu tisucu puta manju, a broj i dalje izgleda kao broj. Ove provjere hvataju upravo takve slucajeve.', Config::TEXT_DOMAIN )
		);

		$skupine = array(
			__( 'Pretvorba jedinica', Config::TEXT_DOMAIN )     => $this->jedinice(),
			__( 'Multipack i dimenzije', Config::TEXT_DOMAIN )  => $this->razlaganje(),
			__( 'Kontrolna znamenka barkoda', Config::TEXT_DOMAIN ) => $this->barkod(),
			__( 'Cijena po jedinici mjere', Config::TEXT_DOMAIN )   => $this->cijena(),
			__( 'Svodenje na dvije decimale', Config::TEXT_DOMAIN ) => $this->zaokruzivanje(),
		);

		$palo   = array();
		$ukupno = 0;

		foreach ( $skupine as $naziv => $ishodi ) {
			$prosli = 0;
			foreach ( $ishodi as $ishod ) {
				$ukupno++;
				if ( $ishod['ok'] ) {
					$prosli++;
				} else {
					$palo[] = $naziv . ': ' . $ishod['opis'];
				}
			}

			$r->stavka(
				$naziv,
				sprintf(
					/* translators: 1: proslo, 2: ukupno */
					__( '%1$d od %2$d prolazi', Config::TEXT_DOMAIN ),
					$prosli,
					count( $ishodi )
				)
			);
		}

		if ( ! empty( $palo ) ) {
			$r->blok( __( 'Sto ne prolazi', Config::TEXT_DOMAIN ), implode( "\n", $palo ) );

			return $r->status( Rezultat::LOSE )
				->postupak(
					sprintf(
						/* translators: 1: palo, 2: ukupno */
						__( '%1$d od %2$d provjera ne prolazi. Cjenik se NE smije objaviti dok se to ne rijesi — cijena po jedinici mjere bila bi netocna.', Config::TEXT_DOMAIN ),
						count( $palo ),
						$ukupno
					)
				);
		}

		return $r->status( Rezultat::OK )
			->stavka( __( 'Ukupno', Config::TEXT_DOMAIN ), sprintf( __( 'svih %d provjera prolazi', Config::TEXT_DOMAIN ), $ukupno ) );
	}

	/* --------------------------------------------------------------- interno */

	/** @return array<int,array{ok:bool,opis:string}> */
	private function jedinice(): array {
		// ulaz, jedinica, ocekivana kolicina u kanonskoj, ocekivana kanonska jedinica
		$slucajevi = array(
			array( 250, 'g', 0.25, 'kg' ),
			array( 1, 'kg', 1.0, 'kg' ),
			array( 1500, 'g', 1.5, 'kg' ),
			array( 500, 'mg', 0.0005, 'kg' ),
			array( 2, 't', 2000.0, 'kg' ),
			array( 750, 'ml', 0.75, 'l' ),
			array( 33, 'cl', 0.33, 'l' ),
			array( 5, 'dl', 0.5, 'l' ),
			array( 2, 'l', 2.0, 'l' ),
			array( 60, 'cm', 0.6, 'm' ),
			array( 120, 'mm', 0.12, 'm' ),
			array( 3, 'm', 3.0, 'm' ),
			// Zapisi kakve ljudi stvarno upisuju.
			array( 250, 'GR', 0.25, 'kg' ),
			array( 250, ' g ', 0.25, 'kg' ),
			array( 1, 'litra', 1.0, 'l' ),
			array( 5, 'komada', 5.0, 'kom' ),
		);

		$ishodi = array();

		foreach ( $slucajevi as $s ) {
			list( $broj, $jedinica, $ocekivana, $ocekivana_jed ) = $s;

			$dobiveno = Kolicina::u_kanonsku( (float) $broj, $jedinica );

			$ok = $dobiveno
				&& $dobiveno['jedinica'] === $ocekivana_jed
				&& abs( $dobiveno['kolicina'] - $ocekivana ) < 0.0000001;

			$ishodi[] = array(
				'ok'   => $ok,
				'opis' => sprintf(
					'%s %s -> ocekivano %s %s, dobiveno %s',
					$broj,
					trim( $jedinica ),
					$ocekivana,
					$ocekivana_jed,
					$dobiveno ? $dobiveno['kolicina'] . ' ' . $dobiveno['jedinica'] : 'nista'
				),
			);
		}

		// Nepoznata jedinica mora vratiti null, a ne tiho nulu.
		$ishodi[] = array(
			'ok'   => null === Kolicina::u_kanonsku( 5, 'bala' ),
			'opis' => 'nepoznata jedinica "bala" mora vratiti nista, ne nulu',
		);

		return $ishodi;
	}

	/** @return array<int,array{ok:bool,opis:string}> */
	private function razlaganje(): array {
		$slucajevi = array(
			// tekst, ocekivana kolicina, ocekivana jedinica
			array( '6x0,5 l', 3.0, 'l' ),
			array( '6 x 0.5 l', 3.0, 'l' ),
			array( '2x250ml', 0.5, 'l' ),
			array( '4 × 100 g', 0.4, 'kg' ),
			array( '500 g', 0.5, 'kg' ),
			array( '0,75 l', 0.75, 'l' ),
			array( '60cm*40cm', 0.24, 'm2' ),
			array( '120cm', 1.2, 'm' ),
		);

		$ishodi = array();

		foreach ( $slucajevi as $s ) {
			list( $tekst, $ocekivana, $ocekivana_jed ) = $s;

			$d  = Kolicina::razlozi( $tekst );
			$ok = $d && $d['jedinica'] === $ocekivana_jed && abs( $d['kolicina'] - $ocekivana ) < 0.0000001;

			$ishodi[] = array(
				'ok'   => $ok,
				'opis' => sprintf(
					'"%s" -> ocekivano %s %s, dobiveno %s',
					$tekst,
					$ocekivana,
					$ocekivana_jed,
					$d ? round( $d['kolicina'], 6 ) . ' ' . $d['jedinica'] : 'nista'
				),
			);
		}

		// Multipack se NE smije progutati: 6x0,5 l mora biti razlicito od 0,5 l.
		$a = Kolicina::razlozi( '6x0,5 l' );
		$b = Kolicina::razlozi( '0,5 l' );
		$ishodi[] = array(
			'ok'   => $a && $b && $a['kolicina'] > $b['kolicina'],
			'opis' => 'multipack mora dati vise od pojedinacnog pakiranja',
		);

		return $ishodi;
	}

	/** @return array<int,array{ok:bool,opis:string}> */
	private function barkod(): array {
		$valjani = array(
			'4006381333931',   // EAN-13
			'5901234123457',   // EAN-13
			'96385074',        // EAN-8
			'036000291452',    // UPC-A
			'10614141000415',  // ITF-14
		);

		$neispravni = array( '4006381333930', '5901234123456', '40', '20', '100', '1234567890123' );

		$ishodi = array();

		foreach ( $valjani as $g ) {
			$ishodi[] = array(
				'ok'   => Gtin::valjan( $g ),
				'opis' => sprintf( '"%s" mora proci kontrolnu znamenku', $g ),
			);
		}

		foreach ( $neispravni as $g ) {
			$ishodi[] = array(
				'ok'   => ! Gtin::valjan( $g ),
				'opis' => sprintf( '"%s" NE smije proci kontrolnu znamenku', $g ),
			);
		}

		// UPC-A u EAN-13: vodeca nula ne smije pokvariti kontrolnu znamenku.
		$ishodi[] = array(
			'ok'   => '0036000291452' === Gtin::u_ean13( '036000291452' ) && Gtin::valjan( Gtin::u_ean13( '036000291452' ) ),
			'opis' => 'UPC-A prosiren vodecom nulom mora ostati valjan',
		);

		// Statusi.
		$statusi = array(
			'4006381333931'  => Config::GTIN_VALJAN,
			'10614141000415' => Config::GTIN_TRANSPORTNI,
			'4006381333930'  => Config::GTIN_NEISPRAVAN,
		);

		foreach ( $statusi as $g => $ocekivan ) {
			$dobiven  = Gtin::status( (string) $g );
			$ishodi[] = array(
				'ok'   => $dobiven === $ocekivan,
				'opis' => sprintf( '"%s" -> ocekivano %s, dobiveno %s', $g, $ocekivan, $dobiven ),
			);
		}

		// Interni prefiks: sastavi valjan kod koji pocinje s 20.
		$tijelo   = '200123456789';
		$interni  = $tijelo . Gtin::kontrolna_znamenka( $tijelo );
		$ishodi[] = array(
			'ok'   => Config::GTIN_INTERNI === Gtin::status( $interni ),
			'opis' => sprintf( 'kod s prefiksom 20 ("%s") mora biti oznacen kao interni', $interni ),
		);

		// Kandidati iz teksta: valjan se nalazi, izmisljen se ne.
		$nadeni   = Gtin::kandidati( 'Artikl 4006381333931 sifra 1234567890123 datum 20240115' );
		$ishodi[] = array(
			'ok'   => in_array( '4006381333931', $nadeni, true ) && ! in_array( '1234567890123', $nadeni, true ),
			'opis' => 'iz teksta se izvlaci samo niz koji prolazi kontrolnu znamenku',
		);

		// Barkod se NE generira: klasa ne smije imati takvu metodu.
		$ishodi[] = array(
			'ok'   => ! method_exists( Gtin::class, 'generiraj' ) && ! method_exists( Gtin::class, 'stvori' ),
			'opis' => 'klasa ne smije imati metodu koja stvara barkod',
		);

		return $ishodi;
	}

	/** @return array<int,array{ok:bool,opis:string}> */
	private function cijena(): array {
		$slucajevi = array(
			// cijena, kolicina, jedinica, ocekivani iznos, ocekivana jedinica
			array( 2.50, 500, 'g', 5.00, 'kg' ),
			array( 2.50, 0.5, 'kg', 5.00, 'kg' ),
			array( 1.20, 330, 'ml', 3.64, 'l' ),
			array( 9.99, 1, 'l', 9.99, 'l' ),
			array( 12.00, 120, 'cm', 10.00, 'm' ),
			array( 5.00, 5, 'kom', 1.00, 'kom' ),
		);

		$ishodi = array();

		foreach ( $slucajevi as $s ) {
			list( $cijena, $kolicina, $jedinica, $ocekivani, $ocekivana_jed ) = $s;

			$d  = Cijena_Po_Jedinici::izracunaj( (float) $cijena, (float) $kolicina, $jedinica );
			$ok = $d && $d['jedinica'] === $ocekivana_jed && abs( $d['iznos'] - $ocekivani ) < 0.005;

			$ishodi[] = array(
				'ok'   => $ok,
				'opis' => sprintf(
					'%s EUR za %s %s -> ocekivano %s/%s, dobiveno %s',
					$cijena,
					$kolicina,
					$jedinica,
					$ocekivani,
					$ocekivana_jed,
					$d ? $d['iznos'] . '/' . $d['jedinica'] : 'nista'
				),
			);
		}

		// Nula i negativno ne racunaju se, a ne daju beskonacnost.
		foreach ( array( array( 0.0, 500.0, 'g' ), array( 2.5, 0.0, 'g' ), array( -1.0, 500.0, 'g' ) ) as $s ) {
			$ishodi[] = array(
				'ok'   => null === Cijena_Po_Jedinici::izracunaj( $s[0], $s[1], $s[2] ),
				'opis' => sprintf( 'cijena %s / kolicina %s mora vratiti nista', $s[0], $s[1] ),
			);
		}

		// Gubitak preciznosti kod jeftinog artikla velike mase se PRIJAVLJUJE.
		$d        = Cijena_Po_Jedinici::izracunaj( 0.99, 25, 'kg' );
		$ishodi[] = array(
			'ok'   => $d && $d['gubi_preciznost'],
			'opis' => '0,99 EUR za 25 kg mora biti oznaceno kao gubitak preciznosti',
		);

		// Kod obicnog artikla se NE prijavljuje.
		$d        = Cijena_Po_Jedinici::izracunaj( 2.50, 500, 'g' );
		$ishodi[] = array(
			'ok'   => $d && ! $d['gubi_preciznost'],
			'opis' => '2,50 EUR za 500 g ne smije biti oznaceno kao gubitak preciznosti',
		);

		return $ishodi;
	}

	/**
	 * Politika zaokruzivanja.
	 *
	 * Dva su nacina da ovo tiho pode po zlu, i oba mijenjaju cijenu koju kupac
	 * placa: racunanje preko `floor($v * 100) / 100`, koje kod 5.10 vrati 5.09, i
	 * oduzimanje u pomicnom zarezu, koje 3.849 - 3.84 prikaze kao 0,0090 pa se
	 * zbroj popisa ne slaze sa zbrojem na ekranu.
	 *
	 * @return array<int,array{ok:bool,opis:string}>
	 */
	private function zaokruzivanje(): array {
		$ishodi = array();

		// vrijednost, ocekivano poslije, ocekivana razlika
		$slucajevi = array(
			array( '3.849', '3.84', '0.009' ),
			array( '18.44847', '18.44', '0.00847' ),
			array( '3.8490', '3.84', '0.0090' ),
			array( '5.10', '5.10', '' ),
			array( '5.1', '5.10', '' ),
			array( '7', '7.00', '' ),
			array( '0.999', '0.99', '0.009' ),
		);

		foreach ( $slucajevi as $s ) {
			list( $ulaz, $ocekivano, $razlika ) = $s;

			$dobiveno = Zaokruzivanje::primijeni( $ulaz );
			$dobivena = Zaokruzivanje::razlika( $ulaz );

			$ishodi[] = array(
				'ok'   => $dobiveno === $ocekivano && $dobivena === $razlika,
				'opis' => sprintf(
					'%s -> ocekivano %s (razlika %s), dobiveno %s (razlika %s)',
					$ulaz,
					$ocekivano,
					'' === $razlika ? 'nema' : $razlika,
					null === $dobiveno ? 'nista' : $dobiveno,
					'' === $dobivena ? 'nema' : $dobivena
				),
			);
		}

		// Sto nije cijena, ne dira se.
		foreach ( array( '', 'abc', '-2.345' ) as $ulaz ) {
			$ishodi[] = array(
				'ok'   => null === Zaokruzivanje::primijeni( $ulaz ),
				'opis' => sprintf( '"%s" nije cijena i mora vratiti nista', $ulaz ),
			);
		}

		// Politika smije samo snizivati. Jedan protuprimjer znaci da posao ne smije
		// na produkciju.
		$podigla = array();
		foreach ( array( '5.10', '5.109', '0.005', '12.999', '99.9999', '1.005', '0.1', '2.675' ) as $ulaz ) {
			$novo = Zaokruzivanje::primijeni( $ulaz );
			if ( null !== $novo && (float) $novo > (float) $ulaz ) {
				$podigla[] = $ulaz . ' -> ' . $novo;
			}
		}

		$ishodi[] = array(
			'ok'   => empty( $podigla ),
			'opis' => empty( $podigla )
				? 'nijedna vrijednost ne raste nakon primjene politike'
				: 'PORASLE: ' . implode( ', ', $podigla ),
		);

		return $ishodi;
	}
}
