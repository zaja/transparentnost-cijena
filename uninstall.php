<?php
/**
 * Brisanje pri deinstalaciji.
 *
 * Tablice s podacima o sidrenim cijenama NE brisemo automatski — to su dokazni
 * podaci o cijenama na referentni datum i njihov gubitak se ne moze popraviti.
 * Brisu se samo opcije.
 *
 * @package CJTR
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-config.php';

foreach ( array(
	CJTR\Config::OPT_DB_VERSION,
	CJTR\Config::OPT_AKTIVIRANO,
	CJTR\Config::OPT_ZADNJA_DIJAG,
) as $opcija ) {
	delete_option( CJTR\Config::option( $opcija ) );
}
