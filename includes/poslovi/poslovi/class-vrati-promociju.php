<?php
/**
 * Posao: vrati akcijske cijene koje je promocija pretvorila u redovne.
 *
 * Postoji iz istog razloga kao i povrat ociscenih akcija: operacija koja mijenja
 * podatke na 845 artikala bez puta natrag nije prihvatljiva, bez obzira na to
 * koliko je odluka koja je izazvala sigurna.
 *
 * Vraca sve cetiri mete tocno onakve kakve su bile, iz traga zapisanog prije
 * promjene, i provjerava je li vraceno ono sto pise u tragu.
 *
 * DODATNA CIJENA SE NE VRACA OVDJE
 *
 * Ovaj posao vraca stanje proizvoda. Dodatnu cijenu upisao je zaseban posao i
 * vraca je zaseban posao — spajanjem bi povrat cijena ovisio o tome je li drugi
 * posao uopce pokrenut. Sto treba jos poduzeti, posao ispise u zapisnik.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;
use CJTR\Povijest\Biljeznik;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Vrati_Promociju extends Posao {

	/** Operacija ciji se trag vraca. Isti kljuc kojim je promocija pisala. */
	private const IZVOR = 'promocija';

	public function kljuc(): string {
		return 'vrati_promociju';
	}

	public function naziv(): string {
		return __( 'Ponisti: ukidanje trajnih akcija', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Vraca redovnu i akcijsku cijenu te datume akcije onakve kakvi su bili prije ukidanja. Koristi se ako se odluka o ukidanju akcija opozove.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}
		if ( 0 === Trag::broj_nevracenih( self::IZVOR ) ) {
			return __( 'Nema nicega za vratiti — promocija jos nije pokretana ili je sve vec vraceno.', Config::TEXT_DOMAIN );
		}
		return '';
	}

	public function ukupno(): int {
		return Trag::broj_nevracenih( self::IZVOR );
	}

	/** Okvir na temelju ovoga sam prebaci ponisteni posao u stanje "gotovo, pa ponisteno". */
	public function ponistava(): string {
		return self::IZVOR;
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$zapisi = Trag::nevraceni( self::IZVOR, $zadnji_id, $velicina );

		if ( empty( $zapisi ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$rez    = Rezultat_Komada::s( 0, null );
		$zadnji = 0;

		$vracenih = Biljeznik::pod_okidacem(
			Config::OKIDAC_PROMOCIJA,
			function () use ( $zapisi, $rez, &$zadnji ) {
				$n = 0;
				foreach ( $zapisi as $zapis ) {
					$zadnji = (int) $zapis->id;
					if ( $this->jedan( $zapis, $rez ) ) {
						$n++;
					}
				}
				return $n;
			}
		);

		$rez->obradeno  = (int) $vracenih;
		$rez->zadnji_id = $zadnji;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

		// Potvrda se povlaci: skup se vratio u stanje prije odluke, pa potvrda koja
		// se odnosila na izvedenu promociju vise nije tocna.
		Promocija::povuci_potvrdu();

		$stanje->zapisi(
			'info',
			__( 'Potvrda za promociju je povucena. Sidrene cijene upisane nakon promocije NISU dirane: njihova vrijednost i dalje je tocno opazanje s referentnog datuma, ali zabiljezen razlog ("odluka vlasnika") vise ne stoji. Zeli li se i to vratiti, pokrenite posao "Vrati sidrenu cijenu nakon promocije".', Config::TEXT_DOMAIN )
		);

		\CJTR\Prikaz\Cache::ocisti();
	}

	/* --------------------------------------------------------------- interno */

	private function jedan( $zapis, Rezultat_Komada $rez ): bool {
		$id = (int) $zapis->entity_id;

		$proizvod = wc_get_product( $id );
		if ( ! $proizvod ) {
			$rez->preskoci( $id, __( 'artikl vise ne postoji', Config::TEXT_DOMAIN ) );
			Trag::oznaci_vracenim( (int) $zapis->id );
			return false;
		}

		$stanje = Trag::stanje( $zapis );
		if ( empty( $stanje ) ) {
			$rez->greska(
				sprintf(
					/* translators: %d = ID zapisa */
					__( 'Zapis %d nema citljivo stanje, preskocen.', Config::TEXT_DOMAIN ),
					(int) $zapis->id
				)
			);
			return false;
		}

		// Redovna cijena ide PRVA, akcijska za njom: WooCommerce pri spremanju
		// izvodi efektivnu cijenu iz obje, pa obrnut redoslijed u istom spremanju
		// ne bi nista promijenio — ali ovako je namjera citljiva.
		$proizvod->set_regular_price( (string) ( $stanje['regular'] ?? '' ) );
		$proizvod->set_sale_price( (string) ( $stanje['sale'] ?? '' ) );
		$proizvod->set_date_on_sale_from( ! empty( $stanje['from_ts'] ) ? (int) $stanje['from_ts'] : null );
		$proizvod->set_date_on_sale_to( ! empty( $stanje['to_ts'] ) ? (int) $stanje['to_ts'] : null );
		$proizvod->save();

		$svjez = wc_get_product( $id );
		if ( $svjez ) {
			foreach ( $this->odstupanja( $svjez, $stanje ) as $opis ) {
				$rez->greska(
					sprintf(
						/* translators: 1: ID, 2: opis odstupanja */
						__( 'ID %1$d: vraceno stanje ne odgovara zapisanom — %2$s', Config::TEXT_DOMAIN ),
						$id,
						$opis
					)
				);
			}
		}

		Trag::oznaci_vracenim( (int) $zapis->id );
		return true;
	}

	/**
	 * Razlike izmedu vracenog i zapisanog stanja.
	 *
	 * Provjeravaju se sve cetiri mete, ne samo cijena: promocija je uklonila i
	 * datume, pa povrat koji ih ne vrati nije povrat.
	 *
	 * @return string[]
	 */
	private function odstupanja( $proizvod, array $stanje ): array {
		$greske = array();

		$parovi = array(
			'price'   => (string) $proizvod->get_price( 'edit' ),
			'regular' => (string) $proizvod->get_regular_price( 'edit' ),
			'sale'    => (string) $proizvod->get_sale_price( 'edit' ),
		);

		foreach ( $parovi as $kljuc => $sada ) {
			$ocekivano = (string) ( $stanje[ $kljuc ] ?? '' );

			if ( '' === $ocekivano || '' === $sada ) {
				if ( $ocekivano !== $sada ) {
					$greske[] = sprintf( '%s je "%s" umjesto "%s"', $kljuc, $sada, $ocekivano );
				}
				continue;
			}

			if ( abs( (float) $sada - (float) $ocekivano ) > Config::TOLERANCIJA_POVIJESTI ) {
				$greske[] = sprintf( '%s je %s umjesto %s', $kljuc, $sada, $ocekivano );
			}
		}

		$datumi = array(
			'from_ts' => $proizvod->get_date_on_sale_from( 'edit' ),
			'to_ts'   => $proizvod->get_date_on_sale_to( 'edit' ),
		);

		foreach ( $datumi as $kljuc => $datum ) {
			$ocekivano = (int) ( $stanje[ $kljuc ] ?? 0 );
			$dobiveno  = $datum ? (int) $datum->getTimestamp() : 0;
			if ( $ocekivano !== $dobiveno ) {
				$greske[] = sprintf( '%s %d umjesto %d', $kljuc, $dobiveno, $ocekivano );
			}
		}

		return $greske;
	}
}
