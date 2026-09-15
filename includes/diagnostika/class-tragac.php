<?php
/**
 * Vraca callback na datoteku, pa datoteku na dodatak.
 *
 * Izdvojeno iz provjere sukoba jer ga koristi i Snimatelj, koji radi na frontendu.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Tragac {

	/** Datoteka u kojoj je callback definiran, ili prazno ako se ne da utvrditi. */
	public static function datoteka_funkcije( $funkcija ): string {
		try {
			if ( is_string( $funkcija ) && function_exists( $funkcija ) ) {
				$ref = new \ReflectionFunction( $funkcija );
			} elseif ( $funkcija instanceof \Closure ) {
				$ref = new \ReflectionFunction( $funkcija );
			} elseif ( is_array( $funkcija ) && 2 === count( $funkcija ) ) {
				$ref = new \ReflectionMethod( $funkcija[0], $funkcija[1] );
			} elseif ( is_object( $funkcija ) && method_exists( $funkcija, '__invoke' ) ) {
				$ref = new \ReflectionMethod( $funkcija, '__invoke' );
			} else {
				return '';
			}
			return (string) $ref->getFileName();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Datoteka -> slug i citljivo ime.
	 *
	 * Vraca null SAMO za jezgru i vlastiti dodatak. Callback koji se nije dao
	 * razrijesiti NE nestaje tiho — dobiva oznaku nepoznatog izvora, jer tiho
	 * ispustanje je upravo nacin na koji se sakrije dodatak koji mijenja cijene.
	 *
	 * @return array{slug:string,ime:string}|null
	 */
	public static function datoteka_u_izvor( string $datoteka ): ?array {
		if ( '' === $datoteka ) {
			return array(
				'slug' => 'nepoznato',
				'ime'  => __( 'nepoznat izvor (nije se dao utvrditi)', Config::TEXT_DOMAIN ),
			);
		}

		$datoteka = wp_normalize_path( $datoteka );

		$plugins = wp_normalize_path( WP_PLUGIN_DIR );
		if ( 0 === strpos( $datoteka, $plugins ) ) {
			$slug = strtok( ltrim( substr( $datoteka, strlen( $plugins ) ), '/' ), '/' );

			if ( 0 === strpos( $slug, Config::TEXT_DOMAIN ) ) {
				return null;
			}
			if ( in_array( $slug, Config::IZUZETI_IZVORI, true ) ) {
				return null;
			}
			return array(
				'slug' => $slug,
				'ime'  => self::ime_plugina( $slug ),
			);
		}

		$themes = wp_normalize_path( get_theme_root() );
		if ( 0 === strpos( $datoteka, $themes ) ) {
			$slug = strtok( ltrim( substr( $datoteka, strlen( $themes ) ), '/' ), '/' );
			return array(
				'slug' => 'tema:' . $slug,
				/* translators: %s = naziv teme */
				'ime'  => sprintf( __( 'tema: %s', Config::TEXT_DOMAIN ), $slug ),
			);
		}

		$mu = wp_normalize_path( WPMU_PLUGIN_DIR );
		if ( 0 === strpos( $datoteka, $mu ) ) {
			return array(
				'slug' => 'mu-plugin',
				'ime'  => __( 'obvezni dodatak (mu-plugin)', Config::TEXT_DOMAIN ),
			);
		}

		return null; // jezgra
	}

	public static function ime_plugina( string $slug ): string {
		static $popis = null;
		if ( null === $popis ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$popis = get_plugins();
		}
		foreach ( $popis as $datoteka => $podaci ) {
			if ( strtok( $datoteka, '/' ) === $slug ) {
				return $podaci['Name'];
			}
		}
		return $slug;
	}
}
