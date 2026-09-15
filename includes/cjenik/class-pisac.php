<?php
/**
 * Pisac datoteke cjenika — XML i CSV.
 *
 * PISE SE U TOKU, NE U MEMORIJI
 *
 * Katalog od 3417 zapisa stane u memoriju, ali generator mora raditi i na trgovini
 * s deset puta vise artikala, na dijeljenom hostingu s ogranicenjem od 128 MB.
 * Zapis se ispise i zaboravi.
 *
 * PISE SE U PRIVREMENU DATOTEKU
 *
 * Gotova datoteka zamjenjuje jucerasnju TEK nakon samoprovjere. Dok se pise, stara
 * ostaje na svom mjestu i posluzuje se — jucerasnji cjenik je bolji od pokvarenog,
 * a polovicno napisan je najgori od svih.
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Pisac {

	/** @var resource|null */
	private $rukovatelj = null;

	/** @var string */
	private $oblik;

	/** @var string */
	private $putanja;

	/** @var int */
	private $zapisa = 0;

	public function __construct( string $oblik, string $putanja ) {
		$this->oblik   = $oblik;
		$this->putanja = $putanja;
	}

	/** Otvori datoteku i ispisi zaglavlje. */
	public function otvori( array $zaglavlje ): bool {
		$this->rukovatelj = fopen( $this->putanja, 'w' );

		if ( ! $this->rukovatelj ) {
			return false;
		}

		if ( Config::CJENIK_XML === $this->oblik ) {
			fwrite( $this->rukovatelj, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" );
			fwrite( $this->rukovatelj, '<' . Config::CJENIK_KORIJEN );

			foreach ( $zaglavlje as $kljuc => $vrijednost ) {
				fwrite( $this->rukovatelj, ' ' . $kljuc . '="' . esc_attr( (string) $vrijednost ) . '"' );
			}

			fwrite( $this->rukovatelj, ">\n" );
			return true;
		}

		// CSV: ZAGLAVLJE U PRVOM RETKU, bez iznimke. Redak koji pocinje s "#"
		// proracunske tablice ucitaju kao podatke i tablica se raspadne.
		fputcsv( $this->rukovatelj, array_values( Config::shema() ) );
		return true;
	}

	/** @param array<string,string> $zapis */
	public function zapisi( array $zapis ): void {
		if ( ! $this->rukovatelj ) {
			return;
		}

		if ( Config::CJENIK_XML === $this->oblik ) {
			fwrite( $this->rukovatelj, "\t<" . Config::CJENIK_ARTIKL . ">\n" );

			foreach ( Config::shema() as $kljuc => $element ) {
				$v = $zapis[ $kljuc ] ?? '';

				// null = polje se za ovaj artikl ne primjenjuje, pa ga NEMA.
				// Prazan element bi tvrdio da podatak postoji a ne znamo ga.
				if ( null === $v ) {
					continue;
				}

				fwrite(
					$this->rukovatelj,
					"\t\t<" . $element . '>' . self::xml_tekst( (string) $v ) . '</' . $element . ">\n"
				);
			}

			fwrite( $this->rukovatelj, "\t</" . Config::CJENIK_ARTIKL . ">\n" );
			$this->zapisa++;
			return;
		}

		// CSV je mreza: redak ne smije imati manje stupaca od zaglavlja, pa se
		// "ne primjenjuje" i "ne znamo" ovdje ne razlikuju. Tu razliku nosi XML.
		$redak = array();
		foreach ( array_keys( Config::shema() ) as $kljuc ) {
			$redak[] = (string) ( $zapis[ $kljuc ] ?? '' );
		}

		fputcsv( $this->rukovatelj, $redak );
		$this->zapisa++;
	}

	/**
	 * Zatvori rukovatelj, ali NE i korijenski element.
	 *
	 * Posao se vrti komad po komad, svaki u zasebnom zahtjevu. Korijen se zatvara
	 * samo jednom, na kraju cijelog posla — zatvoren prerano, svi bi zapisi zavrsili
	 * izvan njega i datoteka se ne bi parsirala.
	 */
	public function odvoji(): void {
		if ( $this->rukovatelj ) {
			fclose( $this->rukovatelj );
			$this->rukovatelj = null;
		}
	}

	public function zatvori(): void {
		if ( ! $this->rukovatelj ) {
			return;
		}

		if ( Config::CJENIK_XML === $this->oblik ) {
			fwrite( $this->rukovatelj, '</' . Config::CJENIK_KORIJEN . ">\n" );
		}

		fclose( $this->rukovatelj );
		$this->rukovatelj = null;
	}

	public function zapisa(): int {
		return $this->zapisa;
	}

	public function putanja(): string {
		return $this->putanja;
	}

	/**
	 * Tekst u XML-u.
	 *
	 * Nazivi artikala sadrze navodnike, ampersande i HTML entitete iz WordPressa
	 * (`&#8230;`). Bez dekodiranja pa ponovnog kodiranja u datoteku bi otislo
	 * `&amp;#8230;`, sto nije ni znak ni entitet nego smece.
	 */
	private static function xml_tekst( string $v ): string {
		$v = html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Kontrolni znakovi nisu dopusteni u XML-u 1.0 ni kao entiteti.
		$v = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v );

		return htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
