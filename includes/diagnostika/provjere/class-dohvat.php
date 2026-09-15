<?php
/**
 * 6. Dohvat izvana: robots.txt i stvarni HTTP dohvat probne datoteke.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Dohvat extends Provjera {

	public function kljuc(): string {
		return 'dohvat';
	}

	public function izvrsi(): Rezultat {
		$r = new Rezultat( __( '6. Dostupnost izvana', Config::TEXT_DOMAIN ) );

		$proba = Zapisivost::proba();

		// --- stvarni dohvat probne datoteke ---
		if ( empty( $proba['upis_ok'] ) ) {
			$r->status( Rezultat::LOSE );
			$r->stavka( __( 'Dohvat probne datoteke', Config::TEXT_DOMAIN ), __( 'nije testiran — datoteka se nije mogla zapisati', Config::TEXT_DOMAIN ) );
			$r->znacenje( __( 'Ne moze se provjeriti je li cjenik dostupan izvana jer se probna datoteka nije mogla ni zapisati. Rijesite prvo tocku 2.', Config::TEXT_DOMAIN ) );
			return $r;
		}

		$odgovor = wp_remote_get(
			$proba['url'],
			array(
				'timeout'   => 15,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		$r->stavka( __( 'Probna adresa', Config::TEXT_DOMAIN ), $proba['url'] );

		if ( is_wp_error( $odgovor ) ) {
			$r->status( Rezultat::UPOZ );
			$r->stavka( __( 'Dohvat', Config::TEXT_DOMAIN ), __( 'nije uspio', Config::TEXT_DOMAIN ) );
			$r->stavka( __( 'Razlog', Config::TEXT_DOMAIN ), $odgovor->get_error_message() );
			$r->znacenje( __( 'Trgovina nije uspjela dohvatiti vlastitu datoteku. Na dijeljenom hostingu to cesto znaci da posluzitelj ne dopusta da sam sebe poziva, a ne da je datoteka nedostupna posjetiteljima. Provjerite rucno.', Config::TEXT_DOMAIN ) );
			$r->postupak( sprintf( __( 'Otvorite ovu adresu u pregledniku: %s — ako se prikaze tekst, sve je u redu.', Config::TEXT_DOMAIN ), $proba['url'] ) );
		} else {
			$kod     = (int) wp_remote_retrieve_response_code( $odgovor );
			$tijelo  = (string) wp_remote_retrieve_body( $odgovor );
			$poklapa = ( trim( $tijelo ) === $proba['sadrzaj'] );

			$r->stavka( __( 'Odgovor', Config::TEXT_DOMAIN ), sprintf( 'HTTP %d', $kod ) );
			$r->stavka( __( 'Sadrzaj se poklapa', Config::TEXT_DOMAIN ), $poklapa );

			if ( 200 === $kod && $poklapa ) {
				$r->status( Rezultat::OK );
				$r->znacenje( __( 'Datoteka zapisana u trgovini dostupna je izvana i sadrzaj je netaknut. Cjenik ce se moci preuzeti.', Config::TEXT_DOMAIN ) );
			} else {
				$r->status( Rezultat::LOSE );
				$r->znacenje( __( 'Datoteka postoji na disku, ali se ne moze preuzeti izvana ili je izmijenjena u prijenosu. Cjenik tako ne bi bio dostupan onima koji ga trebaju preuzeti.', Config::TEXT_DOMAIN ) );
				$r->postupak( __( 'Provjerite blokira li zastita (WAF, sigurnosni plugin) pristup mapi s datotekama, i ne mijenja li mrezni posrednik sadrzaj.', Config::TEXT_DOMAIN ) );
			}
		}

		// --- robots.txt ---
		$this->robots( $r );

		return $r;
	}

	private function robots( Rezultat $r ): void {
		$odgovor = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $odgovor ) ) {
			$r->stavka( __( 'robots.txt', Config::TEXT_DOMAIN ), __( 'nije se mogao dohvatiti', Config::TEXT_DOMAIN ) );
			return;
		}

		$kod    = (int) wp_remote_retrieve_response_code( $odgovor );
		$tijelo = (string) wp_remote_retrieve_body( $odgovor );

		if ( 200 !== $kod ) {
			$r->stavka( __( 'robots.txt', Config::TEXT_DOMAIN ), sprintf( 'HTTP %d', $kod ) );
			return;
		}

		$uploads = wp_parse_url( Config::upload_url(), PHP_URL_PATH );
		$blokiran = false;

		foreach ( preg_split( '/\R/', $tijelo ) as $redak ) {
			if ( ! preg_match( '/^\s*Disallow:\s*(\S+)/i', $redak, $m ) ) {
				continue;
			}
			$pravilo = rtrim( $m[1], '*' );
			if ( '/' === $pravilo || ( '' !== $pravilo && 0 === strpos( (string) $uploads, $pravilo ) ) ) {
				$blokiran = true;
				$r->stavka( __( 'robots.txt blokira', Config::TEXT_DOMAIN ), trim( $redak ) );
			}
		}

		$r->stavka( __( 'robots.txt dopusta mapu s cjenikom', Config::TEXT_DOMAIN ), ! $blokiran );

		if ( $blokiran ) {
			$r->pogorsaj( Rezultat::UPOZ );
			$r->postupak( trim( $r->postupak . ' ' . __( 'Datoteka robots.txt trazi od trazilica i automatskih preuzimaca da preskoce mapu u kojoj ce biti cjenik. Treba je prilagoditi.', Config::TEXT_DOMAIN ) ) );
		}
	}
}
