<?php
/**
 * Posao: jednokratno popuni marku iz naziva, uz obaveznu reviziju.
 *
 * ZASTO POSTOJI
 *
 * Marka je 0 % popunjena, a rjecnik prepoznaje marku u 363 od 691 proizvoda
 * (52,5 %). Rucno bi to bilo 363 pretrazivanja i upisa. Posao ih odradi odjednom.
 *
 * ZASTO IDE NA REVIZIJU
 *
 * Naziv `Bicycle karte "52 PROOF" by Ellusionist` sadrzi dva imena: proizvodaca
 * spila i izdavaca dizajna. U cjenik ide samo jedno, a stroj ne zna koje. Zato
 * posao upisuje s izvorom `naziv`, a ne `rucno` — to ga cini vrijednoscu koju
 * kasniji unos slobodno prepisuje i koju ekran prikazuje kao "treba provjeriti".
 *
 * Artikle kojima je naziv dao VISE marki posao izdvaja u zapisnik: kod njih je
 * greska najvjerojatnija.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Katalog;
use CJTR\Podaci\Marke;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;

defined( 'ABSPATH' ) || exit;

final class Marka_Iz_Naziva extends Posao {

	/** Opcija u kojoj stoji broj artikala s vise prepoznatih marki. */
	const OPT_DVOJBENI = 'marka_dvojbeni';

	public function kljuc(): string {
		return 'marka_iz_naziva';
	}

	public function naziv(): string {
		return __( 'Prepoznaj marku iz naziva artikla', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Prolazi kroz nazive artikala i prepoznaje poznate marke. Popunjava ono sto moze i pritom razlicita pisanja istog brenda svodi na jedno (COPAG i Copag postaju Copag). Prepoznato TREBA PROVJERITI — naziv zna sadrzavati i proizvodaca i izdavaca, a u cjenik ide samo jedan.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 11;
	}

	public function preduvjet(): string {
		return 'prikupi_podatke';
	}

	public function preduvjet_razlog(): string {
		return __( 'Prvo se pokupi marka iz podataka koje trgovina vec ima — ona je pouzdanija od pogadanja iz naziva.', Config::TEXT_DOMAIN );
	}

	public function ukupno(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . Katalog::uvjet() ); // phpcs:ignore
	}

	public function prije_pocetka(): void {
		update_option( Config::option( self::OPT_DVOJBENI ), array(), false );
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		$tablica = Config::table( Config::TABLE_PODACI );

		// Samo artikli kojima marka jos nije poznata, ili je postavljena iz naziva
		// (pa se smije osvjeziti). Rucni unos i CSV se ne diraju.
		$redci = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_parent, c.marka, c.marka_izvor
				 FROM {$wpdb->posts} p
				 LEFT JOIN `{$tablica}` c ON c.entity_id = p.ID
				 WHERE " . Katalog::uvjet() . ' AND p.ID > %d
				 ORDER BY p.ID ASC LIMIT %d',
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		if ( empty( $redci ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$zadnji    = 0;
		$upisanih  = 0;
		$rez       = Rezultat_Komada::s( 0, null );
		$dvojbeni  = (array) get_option( Config::option( self::OPT_DVOJBENI ), array() );

		foreach ( $redci as $r ) {
			$id     = (int) $r->ID;
			$zadnji = max( $zadnji, $id );

			if ( in_array( (string) $r->marka_izvor, array( Config::IZVOR_PODATKA_RUCNO, Config::IZVOR_PODATKA_CSV ), true ) ) {
				$rez->preskoci( $id, __( 'marku je unio covjek, posao je ne dira', Config::TEXT_DOMAIN ) );
				continue;
			}

			// Jaci strojni izvor (taksonomija, atribut, meta) ne prepisuje se
			// pogadanjem iz naziva.
			$snaga_zateceno = Config::SNAGA_IZVORA_PODATKA[ (string) $r->marka_izvor ] ?? 0;
			if ( $snaga_zateceno > Config::SNAGA_IZVORA_PODATKA[ Config::IZVOR_PODATKA_NAZIV ] ) {
				continue;
			}

			$naslov = (string) $r->post_title;
			if ( '' === trim( $naslov ) && $r->post_parent ) {
				$roditelj = get_post( (int) $r->post_parent );
				$naslov   = $roditelj ? $roditelj->post_title : '';
			}

			$sve = Marke::sve_iz_naziva( $naslov );

			if ( empty( $sve ) ) {
				continue;
			}

			if ( count( $sve ) > 1 ) {
				$dvojbeni[ $id ] = $sve;
				$rez->preskoci(
					$id,
					sprintf(
						/* translators: %s = popis marki */
						__( 'naziv sadrzi vise marki (%s) — treba odluciti koja je proizvodac', Config::TEXT_DOMAIN ),
						implode( ', ', $sve )
					)
				);
				continue;
			}

			$this->upisi( $id, $sve[0] );
			$upisanih++;
		}

		update_option( Config::option( self::OPT_DVOJBENI ), $dvojbeni, false );

		$rez->obradeno  = $upisanih;
		$rez->zadnji_id = $zadnji ?: null;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

		$dvojbeni = (array) get_option( Config::option( self::OPT_DVOJBENI ), array() );

		$stanje->zapisi(
			'upozorenje',
			__( 'Prepoznate marke TREBA PROVJERITI. Rjecnik gleda samo naziv i ne razlikuje proizvodaca od izdavaca. Na ekranu "Podaci o proizvodima" filtrirajte po izvoru "naziv".', Config::TEXT_DOMAIN )
		);

		if ( ! empty( $dvojbeni ) ) {
			$stanje->zapisi(
				'upozorenje',
				sprintf(
					/* translators: %d = broj artikala */
					__( 'Kod %d artikala naziv sadrzi vise marki i nijedna nije upisana — te treba rijesiti rucno.', Config::TEXT_DOMAIN ),
					count( $dvojbeni )
				)
			);
		}
	}

	/* --------------------------------------------------------------- interno */

	private function upisi( int $id, string $marka ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . Config::table( Config::TABLE_PODACI ) . '`
				 SET marka = %s, marka_izvor = %s, azurirano = %s
				 WHERE entity_id = %d',
				$marka,
				Config::IZVOR_PODATKA_NAZIV,
				current_time( 'mysql', true ),
				$id
			) // phpcs:ignore
		);
	}
}
