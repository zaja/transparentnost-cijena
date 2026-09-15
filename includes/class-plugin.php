<?php
/**
 * Bootstrap.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function pokreni(): void {
		// Migracija se provjerava pri svakom ucitavanju jer plugin moze biti
		// azuriran preko ZIP-a, bez okidanja aktivacije.
		if ( is_admin() ) {
			Installer::migriraj();
		}

		load_plugin_textdomain(
			Config::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( CJTR_FILE ) ) . '/languages'
		);

		// Raspored se registrira UVIJEK — komad se moze izvrsiti i iz crona,
		// dakle izvan admina.
		// Biljeznik radi UVIJEK — cijena se moze promijeniti i izvan admina
		// (wc_scheduled_sales, REST, uvoz).
		Povijest\Biljeznik::init();

		// Prikaz radi na frontendu i u AJAX odgovorima za varijante.
		Prikaz\Prikaz::init();
		Prikaz\Preuzimanje_Uloge::init();

		Poslovi\Raspored::init();

		// Snimatelj radi na frontendu — ondje gdje se dio dodataka uopce registrira.
		Diagnostika\Snimatelj::init();

		Preuzimanje::init();
		Cjenik\Objava::init();

		if ( is_admin() ) {
			Poslovi\Ajax::init();
			Admin::init();
			Podaci\Polja_Proizvoda::init();
		}
	}

	/** Radi li WooCommerce i je li dovoljno nov. */
	public static function woo_dostupan(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, Config::MIN_WC, '>=' );
	}
}
