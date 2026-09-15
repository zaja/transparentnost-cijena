<?php
/**
 * 3. Automatski poslovi: wp-cron i Action Scheduler.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Cron extends Provjera {

	public function kljuc(): string {
		return 'cron';
	}

	public function izvrsi(): Rezultat {
		$r = new Rezultat( __( '3. Automatski poslovi', Config::TEXT_DOMAIN ) );

		$iskljucen = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$r->stavka( __( 'Ugradeni WordPress raspored iskljucen', Config::TEXT_DOMAIN ), $iskljucen );

		// Je li wp-cron.php uopce dohvatljiv.
		$odgovor = wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			array(
				'timeout'   => 10,
				'blocking'  => true,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $odgovor ) ) {
			$r->stavka( __( 'Poziv rasporeda', Config::TEXT_DOMAIN ), __( 'nije uspio', Config::TEXT_DOMAIN ) );
			$r->stavka( __( 'Razlog', Config::TEXT_DOMAIN ), $odgovor->get_error_message() );
			$dohvatljiv = false;
		} else {
			$kod        = (int) wp_remote_retrieve_response_code( $odgovor );
			$dohvatljiv = ( $kod < 400 );
			$r->stavka( __( 'Poziv rasporeda', Config::TEXT_DOMAIN ), sprintf( 'HTTP %d', $kod ) );
		}

		// Zaostali poslovi u WordPress rasporedu.
		$zaostali = 0;
		$sada     = time();
		foreach ( (array) _get_cron_array() as $ts => $poslovi ) {
			if ( $ts < $sada - 300 ) {
				$zaostali += count( (array) $poslovi );
			}
		}
		$r->stavka( __( 'Poslovi koji kasne vise od 5 minuta', Config::TEXT_DOMAIN ), $zaostali );

		// Action Scheduler.
		$as = $this->action_scheduler();
		$r->stavka( __( 'Action Scheduler', Config::TEXT_DOMAIN ), $as['postoji'] ? __( 'prisutan', Config::TEXT_DOMAIN ) : __( 'nije prisutan', Config::TEXT_DOMAIN ) );
		if ( $as['postoji'] ) {
			$r->stavka( __( 'Poslova na cekanju', Config::TEXT_DOMAIN ), $as['pending'] );
			$r->stavka( __( 'Od toga zakasnjelih', Config::TEXT_DOMAIN ), $as['zakasnjeli'] );
			$r->stavka( __( 'Neuspjelih', Config::TEXT_DOMAIN ), $as['failed'] );
			$r->stavka( __( 'Zadnje uspjesno izvrsenje', Config::TEXT_DOMAIN ), $as['zadnje'] ?: __( 'nema zapisa', Config::TEXT_DOMAIN ) );
		}

		// --- ocjena ostvarivosti roka ---
		$rok = sprintf(
			/* translators: %d = sat */
			__( 'do %d:00 po vremenu trgovine', Config::TEXT_DOMAIN ),
			Config::ROK_OBJAVE_SAT
		);

		if ( $iskljucen ) {
			$r->status( Rezultat::UPOZ );
			$r->znacenje(
				sprintf(
					__( 'Ugradeni WordPress raspored je iskljucen, sto znaci da posao mora pokretati posluzitelj. To je zapravo pouzdanije od ugradenog rasporeda — ali samo ako je taj posao stvarno postavljen. Rok %s ostvariv je jedino uz postavljen posao na posluzitelju.', Config::TEXT_DOMAIN ),
					$rok
				)
			);
			$r->postupak( __( 'Postavite posao na posluzitelju prema gotovom retku iz tocke 4.', Config::TEXT_DOMAIN ) );
			return $r;
		}

		if ( ! $dohvatljiv ) {
			$r->status( Rezultat::LOSE );
			$r->znacenje( __( 'Trgovina ne moze sama pokrenuti svoje zakazane poslove. Cjenik se nece objaviti sam.', Config::TEXT_DOMAIN ) );
			$r->postupak( __( 'Postavite posao na posluzitelju prema gotovom retku iz tocke 4. To zaobilazi problem u cijelosti.', Config::TEXT_DOMAIN ) );
			return $r;
		}

		// Ugradeni raspored radi, ali ovisi o posjetima.
		$r->status( Rezultat::UPOZ );
		$r->znacenje(
			sprintf(
				__( 'Ugradeni WordPress raspored radi, ali se pokrece tek kad netko posjeti stranicu. U ranim jutarnjim satima posjeta obicno nema, pa se posao lako izvrsi prekasno. Rok %s zato nije pouzdano ostvariv samo s ugradenim rasporedom.', Config::TEXT_DOMAIN ),
				$rok
			)
		);
		$r->postupak( __( 'Postavite posao na posluzitelju prema gotovom retku iz tocke 4. Time rok prestaje ovisiti o posjetima.', Config::TEXT_DOMAIN ) );

		if ( $zaostali > 0 || ( $as['postoji'] && $as['zakasnjeli'] > 0 ) ) {
			$r->status( Rezultat::LOSE );
			$r->znacenje( $r->znacenje . ' ' . __( 'Uz to, vec sada postoje poslovi koji kasne — to potvrduje da se raspored ne izvrsava na vrijeme.', Config::TEXT_DOMAIN ) );
		}

		return $r;
	}

	private function action_scheduler(): array {
		global $wpdb;

		$nalaz = array(
			'postoji'    => false,
			'pending'    => 0,
			'zakasnjeli' => 0,
			'failed'     => 0,
			'zadnje'     => '',
		);

		$tablica = $wpdb->prefix . 'actionscheduler_actions';
		if ( $tablica !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tablica ) ) ) {
			return $nalaz;
		}

		$nalaz['postoji'] = true;
		$sada             = gmdate( 'Y-m-d H:i:s' );

		$nalaz['pending']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tablica}` WHERE status = 'pending'" ); // phpcs:ignore
		$nalaz['zakasnjeli'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tablica}` WHERE status = 'pending' AND scheduled_date_gmt < %s", $sada ) ); // phpcs:ignore
		$nalaz['failed']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tablica}` WHERE status = 'failed'" ); // phpcs:ignore
		$nalaz['zadnje']     = (string) $wpdb->get_var( "SELECT MAX(last_attempt_gmt) FROM `{$tablica}` WHERE status = 'complete'" ); // phpcs:ignore

		return $nalaz;
	}
}
