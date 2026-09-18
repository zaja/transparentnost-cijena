<?php
/**
 * Plugin Name:       Cjenovna transparentnost
 * Plugin URI:        https://github.com/zaja/transparentnost-cijena
 * Description:       Uskladenje WooCommerce trgovine s propisima o kontroli cijena — dnevna objava cjenika i isticanje sidrene cijene. Ne mijenja cijene ni druge podatke o proizvodu; pise iskljucivo u vlastite tablice. Sve operacije rade iz WordPress admina, bez pristupa terminalu.
 * Version:           1.3.2
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 * Author:            Goran Zajec
 * Author URI:        https://svejedobro.hr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cjenovna-transparentnost
 * Domain Path:       /languages
 *
 * @package CJTR
 */

defined( 'ABSPATH' ) || exit;

/** Apsolutni put i URL ovog plugina. Jedina dva mjesta koja znaju gdje smo. */
define( 'CJTR_FILE', __FILE__ );
define( 'CJTR_DIR', plugin_dir_path( __FILE__ ) );
define( 'CJTR_URL', plugin_dir_url( __FILE__ ) );

require_once CJTR_DIR . 'includes/class-autoloader.php';
CJTR\Autoloader::registriraj( CJTR_DIR . 'includes' );

register_activation_hook( __FILE__, array( 'CJTR\\Installer', 'aktivacija' ) );
register_deactivation_hook( __FILE__, array( 'CJTR\\Installer', 'deaktivacija' ) );

add_action( 'plugins_loaded', array( 'CJTR\\Plugin', 'pokreni' ) );

/*
 * Izjava o kompatibilnosti s HPOS-om i blokovima kosarice.
 *
 * Dodatak ne dira narudzbe ni kosaricu — ni jedan upit, ni jedan hook. Ali bez
 * ove izjave WooCommerce 8+ na stranici dodataka pise upozorenje o
 * nekompatibilnosti, koje trgovca plasi bez razloga i tjera ga da odustane od
 * spremanja narudzbi u vlastite tablice.
 *
 * Izjava se daje na `before_woocommerce_init`, jer poslije toga WooCommerce
 * vise ne slusa.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $znacajka ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $znacajka, CJTR_FILE, true );
		}
	}
);
