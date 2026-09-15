<?php
/**
 * Zajednicka osnova za provjere.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika;

defined( 'ABSPATH' ) || exit;

abstract class Provjera {

	/** Kljuc provjere, koristi se kao HTML id. */
	abstract public function kljuc(): string;

	/** Izvrsi mjerenje i vrati rezultat. */
	abstract public function izvrsi(): Rezultat;

	/** Bajtovi iz php.ini zapisa tipa "768M". -1 znaci bez ogranicenja. */
	protected function u_bajtove( string $v ): int {
		$v = trim( $v );
		if ( '' === $v ) {
			return 0;
		}
		if ( '-1' === $v ) {
			return -1;
		}
		$zadnji = strtolower( $v[ strlen( $v ) - 1 ] );
		$broj   = (int) $v;
		switch ( $zadnji ) {
			case 'g':
				$broj *= 1024;
				// fall through
			case 'm':
				$broj *= 1024;
				// fall through
			case 'k':
				$broj *= 1024;
		}
		return $broj;
	}

	protected function mb( int $bajtova ): string {
		if ( $bajtova < 0 ) {
			return __( 'bez ogranicenja', \CJTR\Config::TEXT_DOMAIN );
		}
		return number_format_i18n( $bajtova / 1048576, 1 ) . ' MB';
	}
}
