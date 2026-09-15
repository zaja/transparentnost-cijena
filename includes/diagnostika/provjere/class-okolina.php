<?php
/**
 * 1. Okolina: verzije, limiti, vremenske zone.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Okolina extends Provjera {

	public function kljuc(): string {
		return 'okolina';
	}

	public function izvrsi(): Rezultat {
		global $wpdb;

		$r = new Rezultat( __( '1. Okolina', Config::TEXT_DOMAIN ) );
		$r->status( Rezultat::OK );

		$mem = $this->u_bajtove( (string) ini_get( 'memory_limit' ) );
		$vri = (int) ini_get( 'max_execution_time' );

		$r->stavka( __( 'PHP', Config::TEXT_DOMAIN ), PHP_VERSION );
		$r->stavka( __( 'Ogranicenje memorije', Config::TEXT_DOMAIN ), $this->mb( $mem ) );
		$r->stavka(
			__( 'Najdulje trajanje skripte', Config::TEXT_DOMAIN ),
			0 === $vri ? __( 'bez ogranicenja', Config::TEXT_DOMAIN ) : $vri . ' s'
		);
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$r->stavka( __( 'Web posluzitelj', Config::TEXT_DOMAIN ), '' !== $server ? $server : __( 'nepoznat (mjereno izvan web zahtjeva)', Config::TEXT_DOMAIN ) );
		$r->stavka( __( 'Baza', Config::TEXT_DOMAIN ), $wpdb->db_version() );
		$r->stavka( __( 'WordPress', Config::TEXT_DOMAIN ), get_bloginfo( 'version' ) );
		$r->stavka( __( 'WooCommerce', Config::TEXT_DOMAIN ), defined( 'WC_VERSION' ) ? WC_VERSION : __( 'nije aktivan', Config::TEXT_DOMAIN ) );

		// --- vremenske zone ---
		$zone = Zone::izmjeri();

		$r->stavka( __( 'Zona trgovine (WordPress)', Config::TEXT_DOMAIN ), $zone['wp_naziv'] . ' (' . $zone['wp_offset_h'] . ')' );
		$r->stavka( __( 'Zona PHP-a', Config::TEXT_DOMAIN ), $zone['php'] );
		$r->stavka( __( 'Zona posluzitelja', Config::TEXT_DOMAIN ), $zone['sustav'] . ' (' . $zone['sustav_offset_h'] . ')' );
		$r->stavka( __( 'Zona baze', Config::TEXT_DOMAIN ), $zone['mysql'] . ' (' . $zone['mysql_offset_h'] . ')' );
		$r->stavka( __( 'Razlika trgovina - posluzitelj', Config::TEXT_DOMAIN ), $zone['razlika_h'] );

		// --- ocjene ---
		if ( ! defined( 'WC_VERSION' ) ) {
			$r->pogorsaj( Rezultat::LOSE );
		}
		if ( $mem > 0 && $mem < 128 * 1048576 ) {
			$r->pogorsaj( Rezultat::UPOZ );
		}
		if ( 0 !== $zone['razlika_s'] ) {
			$r->pogorsaj( Rezultat::UPOZ );
		}

		$r->znacenje(
			__( 'Ovdje se vidi na kakvom posluzitelju trgovina radi. Najvaznija je zadnja stavka: ako se vrijeme trgovine i vrijeme posluzitelja razlikuju, svaki posao zakazan na odredeni sat izvrsit ce se u krivo doba dana ako se ta razlika ne uracuna.', Config::TEXT_DOMAIN )
		);

		if ( 0 !== $zone['razlika_s'] ) {
			$r->postupak(
				sprintf(
					/* translators: %s = razlika u satima */
					__( 'Posluzitelj i trgovina nisu u istoj vremenskoj zoni (razlika: %s). To nije kvar, ali znaci da se vrijeme za automatski posao mora preracunati. Preracun je vec napravljen u tocki 4.', Config::TEXT_DOMAIN ),
					$zone['razlika_h']
				)
			);
		}

		return $r;
	}
}
