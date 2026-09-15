<?php
/**
 * Rucno pokretanje iz admina — komad po komad, bez cekanja na raspored.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	public static function init(): void {
		add_action( 'wp_ajax_' . Config::hook( Config::AJAX_KOMAD ), array( __CLASS__, 'obradi' ) );
	}

	public static function obradi(): void {
		check_ajax_referer( Config::nonce( Config::AJAX_KOMAD ), 'nonce' );

		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_send_json_error( array( 'poruka' => __( 'Nemate ovlasti.', Config::TEXT_DOMAIN ) ), 403 );
		}

		$kljuc = isset( $_POST['kljuc'] ) ? sanitize_key( wp_unslash( $_POST['kljuc'] ) ) : '';
		if ( '' === $kljuc || ! Registar::nadi( $kljuc ) ) {
			wp_send_json_error( array( 'poruka' => __( 'Nepoznat posao.', Config::TEXT_DOMAIN ) ), 400 );
		}

		$s = Pokretac::obradi_jedan_komad( $kljuc );

		wp_send_json_success(
			array(
				'status'    => $s->status,
				'obradeno'  => $s->obradeno,
				'ukupno'    => $s->ukupno,
				'postotak'  => $s->postotak(),
				'gresaka'   => $s->gresaka,
				'preostalo' => $s->preostalo_s(),
				'radi'      => $s->radi(),
				'poruka'    => $s->poruka,
			)
		);
	}
}
