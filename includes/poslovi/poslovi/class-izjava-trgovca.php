<?php
/**
 * Posao: uzmi danasnje cijene kao dodatne, na temelju izjave trgovca.
 *
 * ZASTO OVO MORA POSTOJATI
 *
 * Trgovac bez ikakve povijesti cijena nema odakle utvrditi koliko je artikl stajao
 * na referentni datum. Bez ovog puta dodatak za njega ne radi uopce — a takvih je
 * vecina.
 *
 * ZASTO SE BILJEZI KAO IZJAVA, A NE KAO PODATAK
 *
 * Ne da bismo trgovca zastitili od inspekcije. Nego da za sest mjeseci postoji
 * odgovor na pitanje ODAKLE TA BROJKA.
 *
 * Zapis koji kaze samo `39.90` ne razlikuje tri razlicite stvari: izmjereno iz
 * zapisa o cijenama, uvezeno iz programa, ili pretpostavljeno. Prva se moze
 * obraniti, druga ima izvor, treca je pretpostavka — i trgovac to mora znati
 * PRIJE nego mu netko postavi pitanje.
 *
 * Uz to: cim izjava ima svoj izvor, kasniji uvoz ili izmjerena vrijednost smiju je
 * prepisati bez da se ista izmjereno izgubi. Bez razlikovanja izvora to ne bi bilo
 * sigurno napraviti.
 *
 * DIRA SAMO ONO STO JE PRAZNO
 *
 * Artikl koji vec ima dodatnu cijenu iz bilo kojeg izvora se ne dira. Izjava je
 * najslabiji izvor i ne prepisuje nista.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Katalog;
use CJTR\Podaci\Sidrena_Unos;
use CJTR\Poslovi\Posao_S_Cijenama;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;
use CJTR\Postavke;

defined( 'ABSPATH' ) || exit;

final class Izjava_Trgovca extends Posao_S_Cijenama {

	const OPT_POTVRDA = 'izjava_potvrda';

	public function kljuc(): string {
		return 'izjava_trgovca';
	}

	public function naziv(): string {
		return __( 'Uzmi danasnje cijene kao dodatne', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Upisuje danasnju cijenu kao sidrenu cijenu za artikle kojima je jos nemamo odakle utvrditi. Biljezi se kao vasa izjava, s datumom i imenom — ne kao izmjerena vrijednost, jer to i nije.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 13;
	}

	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}

		$potvrda = self::potvrda();

		if ( empty( $potvrda ) ) {
			return __( 'Potvrda jos nije dana. Otvorite prvo postavljanje, korak "Odakle dolaze sidrene cijene".', Config::TEXT_DOMAIN );
		}

		if ( 0 === $this->ukupno() ) {
			return __( 'Nema artikala bez sidrene cijene — nema sto upisati.', Config::TEXT_DOMAIN );
		}

		return parent::zapreka();
	}

	public function ukupno(): int {
		global $wpdb;

		$t = Config::table( Config::TABLE_PODACI );

		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN `{$t}` c ON c.entity_id = p.ID
			 WHERE " . Katalog::uvjet() . '
			   AND c.sidrena_cijena IS NULL' // phpcs:ignore
		);
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		$t = Config::table( Config::TABLE_PODACI );

		$redci = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS entity_id, mp.meta_value AS cijena
				 FROM {$wpdb->posts} p
				 LEFT JOIN `{$t}` c ON c.entity_id = p.ID
				 LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_price'
				 WHERE " . Katalog::uvjet() . ' AND c.sidrena_cijena IS NULL AND p.ID > %d
				 ORDER BY p.ID ASC
				 LIMIT %d',
				$zadnji_id,
				$velicina
			) // phpcs:ignore
		);

		if ( empty( $redci ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$rez    = Rezultat_Komada::s( 0, null );
		$zadnji = 0;
		$n      = 0;

		$potvrda  = self::potvrda();
		$biljeska = sprintf(
			/* translators: 1: datum potvrde, 2: referentni datum */
			__( 'izjava vlasnika trgovine od %1$s: cijena se nije mijenjala od %2$s', Config::TEXT_DOMAIN ),
			wp_date( 'j.n.Y.', strtotime( (string) ( $potvrda['kad'] ?? '' ) . ' UTC' ) ?: time() ),
			wp_date( 'j.n.Y.', Config::t_ref( Postavke::ref_datum() ) )
		);

		foreach ( $redci as $r ) {
			$id     = (int) $r->entity_id;
			$zadnji = max( $zadnji, $id );

			// Artikl bez cijene se preskace, ne dobiva nulu. Nula bi bila tvrdnja.
			if ( null === $r->cijena || '' === (string) $r->cijena ) {
				$rez->preskoci( $id, __( 'artikl nema cijenu, pa nema sto preuzeti kao dodatnu', Config::TEXT_DOMAIN ) );
				continue;
			}

			$ishod = Sidrena_Unos::upisi( $id, (string) $r->cijena, Config::IZVOR_IZJAVA_TRGOVCA, $biljeska );

			if ( empty( $ishod['ok'] ) ) {
				$rez->preskoci( $id, (string) $ishod['poruka'] );
				continue;
			}

			$n++;
		}

		$rez->obradeno  = $n;
		$rez->zadnji_id = $zadnji ?: null;

		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje = Stanje::ucitaj( $this->kljuc() );

		$stanje->zapisi(
			'info',
			__( 'Upisane vrijednosti nose izvor "izjava vlasnika trgovine". Nadete li poslije tocniji podatak — iz vaseg programa ili iz povijesti cijena — smije ih prepisati.', Config::TEXT_DOMAIN )
		);

		\CJTR\Prikaz\Cache::ocisti();
	}

	/* ------------------------------------------------------------ potvrda */

	public static function potvrda(): array {
		$v = get_option( Config::option( self::OPT_POTVRDA ), array() );

		return is_array( $v ) ? $v : array();
	}

	/** @param int $artikala Koliko ih je bilo u trenutku potvrde. */
	public static function potvrdi( int $artikala ): void {
		$k = get_current_user_id() ? get_userdata( get_current_user_id() ) : null;

		update_option(
			Config::option( self::OPT_POTVRDA ),
			array(
				'kad'      => current_time( 'mysql', true ),
				'tko'      => $k ? (string) $k->user_login : Config::POSTAVIO_POSAO,
				'artikala' => $artikala,
				'datum'    => Postavke::ref_datum(),
			),
			false
		);
	}

	public static function povuci_potvrdu(): void {
		delete_option( Config::option( self::OPT_POTVRDA ) );
	}
}
