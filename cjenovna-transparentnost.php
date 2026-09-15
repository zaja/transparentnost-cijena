<?php
/**
 * Plugin Name:       Cjenovna transparentnost
 * Plugin URI:        https://example.org/cjenovna-transparentnost
 * Description:       Uskladenje WooCommerce trgovine s propisima o kontroli cijena — dnevna objava cjenika i isticanje dodatne (sidrene) cijene. Sve operacije rade iz WordPress admina, bez pristupa terminalu.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            —
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
