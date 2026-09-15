<?php
/**
 * Ciscenje predmemorije stranica nakon promjene dodatnih cijena.
 *
 * ZASTO JE POTREBNO
 *
 * Dodatna cijena se ispisuje u HTML stranice, pa je dodaci za predmemoriju
 * spreme zajedno s ostatkom. WooCommerce sam cisti predmemoriju kad se spremi
 * proizvod — ali dodatnu cijenu ne mijenja spremanje proizvoda nego nas posao.
 * Bez ovoga bi posjetitelji jos danima vidjeli staru vrijednost.
 *
 * Ne oslanjamo se na jedan dodatak: pozivaju se svi poznati nacini, a oni koji
 * nisu prisutni jednostavno ne postoje pa se preskacu.
 *
 * @package CJTR
 */

namespace CJTR\Prikaz;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Cache {

	/**
	 * Ocisti sve sto se da ocistiti.
	 *
	 * @return string[] imena dodataka cija je predmemorija ocisceana
	 */
	public static function ocisti(): array {
		$ocisceno = array();

		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$ocisceno[] = 'WP Rocket';
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$ocisceno[] = 'W3 Total Cache';
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$ocisceno[] = 'WP Super Cache';
		}

		// LiteSpeed
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$ocisceno[] = 'LiteSpeed Cache';
		}

		// WooCommerce vlastiti prijelazni podaci o cijenama.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
			$ocisceno[] = 'WooCommerce';
		}

		/**
		 * Za dodatke koje ne poznajemo.
		 */
		do_action( Config::hook( 'ocisti_predmemoriju' ) );

		return $ocisceno;
	}
}
