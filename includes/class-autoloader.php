<?php
/**
 * Autoloader bez vanjskih ovisnosti.
 *
 * CJTR\Foo_Bar                      -> includes/class-foo-bar.php
 * CJTR\Diagnostika\Runner           -> includes/diagnostika/class-runner.php
 * CJTR\Diagnostika\Provjere\Cron    -> includes/diagnostika/provjere/class-cron.php
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	/** @var string */
	private static $baza = '';

	public static function registriraj( string $baza ): void {
		self::$baza = trailingslashit( $baza );
		spl_autoload_register( array( __CLASS__, 'ucitaj' ) );
	}

	public static function ucitaj( string $klasa ): void {
		if ( 0 !== strpos( $klasa, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relativno = substr( $klasa, strlen( __NAMESPACE__ ) + 1 );
		$dijelovi  = explode( '\\', $relativno );
		$ime       = array_pop( $dijelovi );

		$putanja = self::$baza;
		foreach ( $dijelovi as $dio ) {
			$putanja .= strtolower( str_replace( '_', '-', $dio ) ) . '/';
		}
		$putanja .= 'class-' . strtolower( str_replace( '_', '-', $ime ) ) . '.php';

		if ( is_readable( $putanja ) ) {
			require_once $putanja;
		}
	}
}
