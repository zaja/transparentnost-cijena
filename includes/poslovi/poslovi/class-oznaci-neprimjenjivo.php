<?php
/**
 * Posao: oznaci skupine kod kojih polje objektivno ne postoji.
 *
 * ZASTO AUTOMATSKI
 *
 * Varijabilni roditelj nema vlastiti barkod jer se ne prodaje sam — u cjenik idu
 * njegove varijante. Usluga nema neto kolicinu. To nisu rubni slucajevi nego
 * skupine koje se u svakoj trgovini ponavljaju, a rucno bi ih trebalo oznaciti
 * stotinama klikova. Na ovom katalogu samo varijabilnih roditelja ima 183.
 *
 * STO SE NE DOGADA
 *
 * Automatsko pravilo NIKAD ne prepisuje ljudsku odluku. Covjek koji je stajao pred
 * artiklom zna vise od pravila, pa ako je vec oznacio ili skinuo oznaku, posao to
 * ostavlja kako jest.
 *
 * Svako oznacavanje nosi razlog — oznaka bez razloga nije odluka koju se pred
 * inspekcijom moze obraniti nego pogadanje zapisano kao cinjenica.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Katalog;
use CJTR\Podaci\Neprimjenjivo;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;

defined( 'ABSPATH' ) || exit;

final class Oznaci_Neprimjenjivo extends Posao {

	public function kljuc(): string {
		return 'oznaci_neprimjenjivo';
	}

	public function naziv(): string {
		return __( 'Oznaci artikle kojima podatak ne postoji', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Neki artikli objektivno nemaju barkod ili neto kolicinu — usluge, virtualni proizvodi, i varijabilni proizvodi kojima u cjenik idu varijante. Posao ih oznacava kao "nije primjenjivo", sa zapisanim razlogom, da vas popis zadataka ne trazi podatak koji ne postoji. Vase vlastite oznake ne dira.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 10;
	}

	public function preduvjet(): string {
		return 'prikupi_podatke';
	}

	public function preduvjet_razlog(): string {
		return __( 'Prvo se pokupi ono sto trgovina vec ima — inace bi se kao "nema" oznacilo ono sto je samo jos nepronadeno.', Config::TEXT_DOMAIN );
	}

	public function ukupno(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . Katalog::uvjet() ); // phpcs:ignore
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT p.ID FROM ' . $wpdb->posts . ' p WHERE ' . Katalog::uvjet() . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d',
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		if ( empty( $ids ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids = array_map( 'intval', $ids );
		$rez = Rezultat_Komada::s( 0, max( $ids ) );

		$pogodeni  = $this->pogodeni_pravilima( $ids );
		$oznacenih = 0;

		foreach ( $ids as $id ) {
			if ( empty( $pogodeni[ $id ] ) ) {
				continue;
			}

			$nesto = false;

			foreach ( $pogodeni[ $id ] as $pravilo ) {
				foreach ( $pravilo['polja'] as $polje ) {
					if ( Neprimjenjivo::oznaci( $id, $polje, $pravilo['razlog'], true ) ) {
						$nesto = true;
					}
				}
			}

			if ( $nesto ) {
				$oznacenih++;
			}
		}

		$rez->obradeno = $oznacenih;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

		foreach ( array_keys( Config::POLJA ) as $polje ) {
			$n = Neprimjenjivo::broj( $polje );
			if ( $n > 0 ) {
				$stanje->zapisi(
					'info',
					sprintf(
						/* translators: 1: naziv polja, 2: broj artikala */
						__( '%1$s: %2$d artikala oznaceno kao "nije primjenjivo".', Config::TEXT_DOMAIN ),
						Config::POLJA[ $polje ]['naziv'],
						$n
					)
				);
			}
		}
	}

	/* --------------------------------------------------------------- interno */

	/**
	 * Koja pravila pogadaju koje artikle iz komada.
	 *
	 * Svako pravilo je JEDAN upit nad cijelim komadom, ne upit po artiklu. Na 3623
	 * retka i cetiri pravila razlika je izmedu 16 upita i 14 000.
	 *
	 * @param int[] $ids
	 * @return array<int,array<int,array{polja:string[],razlog:string}>>
	 */
	private function pogodeni_pravilima( array $ids ): array {
		global $wpdb;

		$u     = implode( ',', array_map( 'intval', $ids ) );
		$izlaz = array();

		foreach ( Neprimjenjivo::pravila() as $pravilo ) {
			$nadeni = (array) $wpdb->get_col(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID IN ({$u}) AND ( " . $pravilo['uvjet'] . ' )' // phpcs:ignore
			);

			foreach ( $nadeni as $id ) {
				$izlaz[ (int) $id ][] = array(
					'polja'  => $pravilo['polja'],
					'razlog' => $pravilo['razlog'],
				);
			}
		}

		return $izlaz;
	}
}
