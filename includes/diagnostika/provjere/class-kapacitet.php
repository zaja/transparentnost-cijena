<?php
/**
 * 5. Kapacitet: koliko kosta dohvat entiteta i koliki komad je siguran.
 *
 * Mjeri se na stvarnom uzorku, pa ekstrapolira. Uzorak je ogranicen da
 * sama dijagnostika ne srusi stranicu na skromnom hostingu.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Katalog;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Kapacitet extends Provjera {

	const UZORAK = 1000;

	public function kljuc(): string {
		return 'kapacitet';
	}

	public function izvrsi(): Rezultat {
		global $wpdb;

		$r = new Rezultat( __( '5. Kapacitet i velicina komada', Config::TEXT_DOMAIN ) );

		$ukupno = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . Katalog::uvjet()
		); // phpcs:ignore

		$uzorak = min( self::UZORAK, max( 1, $ukupno ) );

		$mem_prije = memory_get_usage( false );
		$t0        = microtime( true );

		$redci = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,
				        pm.meta_value AS price,
				        rm.meta_value AS regular_price,
				        sm.meta_value AS sale_price
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_price'
				 LEFT JOIN {$wpdb->postmeta} rm ON rm.post_id = p.ID AND rm.meta_key = '_regular_price'
				 LEFT JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = '_sale_price'
				 WHERE " . Katalog::uvjet() . " LIMIT %d",
				$uzorak
			)
		); // phpcs:ignore

		$trajanje  = microtime( true ) - $t0;
		$mem_delta = max( 0, memory_get_usage( false ) - $mem_prije );
		$dohvaceno = count( $redci );
		unset( $redci );

		$po_entitetu_s = $dohvaceno > 0 ? $trajanje / $dohvaceno : 0;
		$po_entitetu_b = $dohvaceno > 0 ? $mem_delta / $dohvaceno : 0;

		$proc_vrijeme = $po_entitetu_s * $ukupno;
		$proc_mem     = $po_entitetu_b * $ukupno;

		$mem_limit = $this->u_bajtove( (string) ini_get( 'memory_limit' ) );
		$vri_limit = (int) ini_get( 'max_execution_time' );

		$komad = $this->preporuceni_komad( $po_entitetu_s, $po_entitetu_b, $mem_limit, $vri_limit );

		$r->stavka( __( 'Artikala i varijanti ukupno', Config::TEXT_DOMAIN ), number_format_i18n( $ukupno ) );
		$r->stavka( __( 'Izmjereno na uzorku od', Config::TEXT_DOMAIN ), number_format_i18n( $dohvaceno ) );
		$r->stavka( __( 'Trajanje uzorka', Config::TEXT_DOMAIN ), number_format_i18n( $trajanje, 3 ) . ' s' );
		$r->stavka( __( 'Memorija uzorka', Config::TEXT_DOMAIN ), $this->mb( (int) $mem_delta ) );
		$r->stavka( __( 'Procjena za cijeli katalog', Config::TEXT_DOMAIN ), number_format_i18n( $proc_vrijeme, 2 ) . ' s / ' . $this->mb( (int) $proc_mem ) );
		$r->stavka( __( 'Preporucena velicina komada', Config::TEXT_DOMAIN ), number_format_i18n( $komad['velicina'] ) );
		$r->stavka( __( 'Broj komada', Config::TEXT_DOMAIN ), (int) ceil( $ukupno / max( 1, $komad['velicina'] ) ) );
		$r->stavka( __( 'Ogranicenje koje odlucuje', Config::TEXT_DOMAIN ), $komad['razlog'] );

		// Zapamti izmjereno — obrada u pozadini cita ovu vrijednost.
		update_option( Config::option( Config::OPT_VELICINA_KOMADA ), (int) $komad['velicina'], false );

		// Ocjena: bi li cijeli katalog prosao u jednom zahtjevu?
		$stane_memorija = ( $mem_limit < 0 ) || ( $proc_mem < $mem_limit * Config::BATCH_UDIO_MEMORIJE );
		$stane_vrijeme  = ( 0 === $vri_limit ) || ( $proc_vrijeme < $vri_limit * Config::BATCH_UDIO_VREMENA );

		if ( $stane_memorija && $stane_vrijeme ) {
			$r->status( Rezultat::OK );
			$r->znacenje( __( 'Trgovina je dovoljno mala da se cjenik moze izraditi bez problema. Posao ce se svejedno raditi u komadima, jer tako prezivi i ako hosting bude sporiji nego danas.', Config::TEXT_DOMAIN ) );
		} else {
			$r->status( Rezultat::UPOZ );
			$r->znacenje( __( 'Katalog je prevelik da se obradi odjednom na ovom hostingu. Posao ce se zato raditi u komadima, u pozadini. To nije kvar nego nacin rada.', Config::TEXT_DOMAIN ) );
		}

		if ( 0 === $ukupno ) {
			$r->status( Rezultat::UPOZ );
			$r->znacenje( __( 'U trgovini nema objavljenih artikala, pa se kapacitet ne moze izmjeriti.', Config::TEXT_DOMAIN ) );
		}

		return $r;
	}

	/** Najveci komad koji stane i u memoriju i u vrijeme, unutar dopustenog udjela. */
	private function preporuceni_komad( float $po_ent_s, float $po_ent_b, int $mem_limit, int $vri_limit ): array {
		$po_memoriji = PHP_INT_MAX;
		$po_vremenu  = PHP_INT_MAX;

		if ( $mem_limit > 0 && $po_ent_b > 0 ) {
			$po_memoriji = (int) floor( ( $mem_limit * Config::BATCH_UDIO_MEMORIJE ) / $po_ent_b );
		}
		if ( $vri_limit > 0 && $po_ent_s > 0 ) {
			$po_vremenu = (int) floor( ( $vri_limit * Config::BATCH_UDIO_VREMENA ) / $po_ent_s );
		}

		if ( PHP_INT_MAX === $po_memoriji && PHP_INT_MAX === $po_vremenu ) {
			return array(
				'velicina' => Config::BATCH_ZADANO,
				'razlog'   => __( 'nema ogranicenja — uzeta zadana vrijednost', Config::TEXT_DOMAIN ),
			);
		}

		$razlog = $po_memoriji <= $po_vremenu
			? __( 'memorija', Config::TEXT_DOMAIN )
			: __( 'trajanje skripte', Config::TEXT_DOMAIN );

		$velicina = min( $po_memoriji, $po_vremenu );
		$velicina = max( Config::BATCH_MIN, min( Config::BATCH_MAX, $velicina ) );

		return array(
			'velicina' => $velicina,
			'razlog'   => $razlog,
		);
	}
}
