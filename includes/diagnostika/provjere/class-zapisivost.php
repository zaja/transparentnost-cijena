<?php
/**
 * 2. Zapisivost i javna dostupnost direktorija za cjenik.
 *
 * Ne oslanjamo se na is_writable() — na shared hostingu zna lagati zbog
 * open_basedir, ACL-ova i mount opcija. Zato stvarni upis, pa stvarni dohvat.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Zapisivost extends Provjera {

	/** @var array|null dijeli se s provjerom Dohvat da se proba ne radi dvaput */
	private static $zadnja_proba = null;

	public function kljuc(): string {
		return 'zapisivost';
	}

	public function izvrsi(): Rezultat {
		$r = new Rezultat( __( '2. Zapisivanje datoteka', Config::TEXT_DOMAIN ) );

		$dir = Config::upload_dir();
		$r->stavka( __( 'Direktorij', Config::TEXT_DOMAIN ), $dir );
		$r->stavka( __( 'Javna adresa', Config::TEXT_DOMAIN ), Config::upload_url() );

		$proba = self::proba();

		$r->stavka( __( 'Direktorij postoji', Config::TEXT_DOMAIN ), $proba['dir_ok'] );
		$r->stavka( __( 'Upis probne datoteke', Config::TEXT_DOMAIN ), $proba['upis_ok'] );
		$r->stavka( __( 'Citanje natrag s diska', Config::TEXT_DOMAIN ), $proba['citanje_ok'] );

		if ( ! $proba['dir_ok'] || ! $proba['upis_ok'] || ! $proba['citanje_ok'] ) {
			$r->status( Rezultat::LOSE );
			$r->znacenje( __( 'Trgovina ne moze zapisati datoteku cjenika na disk. Bez toga se cjenik ne moze objaviti.', Config::TEXT_DOMAIN ) );
			$r->postupak( __( 'Zatrazite od hostinga da mapa za datoteke (uploads) bude zapisiva za WordPress. U cPanelu je to obicno dozvola 755 na mapi wp-content/uploads.', Config::TEXT_DOMAIN ) );
			if ( ! empty( $proba['greska'] ) ) {
				$r->stavka( __( 'Poruka o gresci', Config::TEXT_DOMAIN ), $proba['greska'] );
			}
			return $r;
		}

		$r->status( Rezultat::OK );
		$r->znacenje( __( 'Trgovina moze zapisati datoteku cjenika na disk. Je li ta datoteka dostupna izvana, provjerava se u tocki 6.', Config::TEXT_DOMAIN ) );

		return $r;
	}

	/**
	 * Napravi probnu datoteku i vrati nalaz. Rezultat se pamti unutar zahtjeva
	 * da provjera 6 moze dohvatiti istu datoteku bez ponovnog upisa.
	 */
	public static function proba(): array {
		if ( null !== self::$zadnja_proba ) {
			return self::$zadnja_proba;
		}

		$dir     = Config::upload_dir();
		$sadrzaj = Config::PREFIX . '-proba-' . wp_generate_password( 12, false );
		$ime     = 'proba-' . wp_generate_password( 8, false ) . '.txt';
		$put     = trailingslashit( $dir ) . $ime;

		$nalaz = array(
			'dir_ok'     => false,
			'upis_ok'    => false,
			'citanje_ok' => false,
			'put'        => $put,
			'url'        => trailingslashit( Config::upload_url() ) . $ime,
			'sadrzaj'    => $sadrzaj,
			'greska'     => '',
		);

		if ( ! wp_mkdir_p( $dir ) ) {
			$nalaz['greska'] = __( 'ne mogu stvoriti direktorij', Config::TEXT_DOMAIN );
			self::$zadnja_proba = $nalaz;
			return $nalaz;
		}
		$nalaz['dir_ok'] = true;

		$upisano = @file_put_contents( $put, $sadrzaj ); // phpcs:ignore
		if ( false === $upisano ) {
			$nalaz['greska'] = __( 'upis nije uspio', Config::TEXT_DOMAIN );
			self::$zadnja_proba = $nalaz;
			return $nalaz;
		}
		$nalaz['upis_ok'] = true;

		$procitano = @file_get_contents( $put ); // phpcs:ignore
		$nalaz['citanje_ok'] = ( $procitano === $sadrzaj );

		self::$zadnja_proba = $nalaz;
		return $nalaz;
	}

	/** Obrisi probnu datoteku. Zove runner na kraju svih provjera. */
	public static function pospremi(): void {
		if ( null === self::$zadnja_proba ) {
			return;
		}
		if ( ! empty( self::$zadnja_proba['upis_ok'] ) && file_exists( self::$zadnja_proba['put'] ) ) {
			@unlink( self::$zadnja_proba['put'] ); // phpcs:ignore
		}
	}
}
