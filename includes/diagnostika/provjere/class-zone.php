<?php
/**
 * Mjerenje vremenskih zona — zajednicko za provjeru okoline i za cPanel cron.
 *
 * WordPress u wp-settings.php forsira date_default_timezone_set('UTC'), pa PHP-ova
 * zadana zona NE govori nista o zoni posluzitelja. Zonu sustava zato trazimo
 * izravno, s vise izvora i jasnom oznakom pouzdanosti.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Zone {

	/** @var array|null memoizacija unutar jednog zahtjeva */
	private static $cache = null;

	public static function izmjeri(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		global $wpdb;

		$wp_tz     = wp_timezone();
		$sada      = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$wp_offset = $wp_tz->getOffset( $sada );

		list( $sustav_tz, $sustav_izvor ) = self::zona_sustava();
		$sustav_offset = $sustav_tz ? $sustav_tz->getOffset( $sada ) : 0;

		// MySQL: @@session.time_zone je cesto 'SYSTEM', pa mjerimo stvarni pomak.
		$mysql_naziv  = (string) $wpdb->get_var( 'SELECT @@session.time_zone' );
		$mysql_offset = (int) $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())' );

		self::$cache = array(
			'wp_tz'           => $wp_tz,
			'wp_naziv'        => $wp_tz->getName(),
			'wp_offset_s'     => $wp_offset,
			'wp_offset_h'     => self::sati( $wp_offset ),
			'php'             => (string) date_default_timezone_get(),
			'sustav_tz'       => $sustav_tz,
			'sustav'          => $sustav_tz ? $sustav_tz->getName() : __( 'nije utvrdena', Config::TEXT_DOMAIN ),
			'sustav_izvor'    => $sustav_izvor,
			'sustav_offset_s' => $sustav_offset,
			'sustav_offset_h' => self::sati( $sustav_offset ),
			'mysql'           => $mysql_naziv,
			'mysql_offset_s'  => $mysql_offset,
			'mysql_offset_h'  => self::sati( $mysql_offset ),
			'razlika_s'       => $wp_offset - $sustav_offset,
			'razlika_h'       => self::sati( $wp_offset - $sustav_offset ),
		);

		return self::$cache;
	}

	/**
	 * Zona posluzitelja, najbolji dostupan izvor.
	 *
	 * @return array{0:\DateTimeZone|null,1:string}
	 */
	private static function zona_sustava(): array {
		// 1) /etc/timezone — Debian/Ubuntu
		if ( is_readable( '/etc/timezone' ) ) {
			$naziv = trim( (string) @file_get_contents( '/etc/timezone' ) );
			$tz    = self::napravi( $naziv );
			if ( $tz ) {
				return array( $tz, '/etc/timezone' );
			}
		}

		// 2) /etc/localtime kao symlink na zoneinfo — RHEL/CentOS/cPanel
		if ( is_link( '/etc/localtime' ) ) {
			$meta = (string) @readlink( '/etc/localtime' );
			if ( preg_match( '#zoneinfo/(.+)$#', $meta, $m ) ) {
				$tz = self::napravi( $m[1] );
				if ( $tz ) {
					return array( $tz, '/etc/localtime' );
				}
			}
		}

		// 3) php.ini date.timezone — ne mora odgovarati sustavu, ali je indikacija
		$ini = (string) ini_get( 'date.timezone' );
		if ( '' !== $ini ) {
			$tz = self::napravi( $ini );
			if ( $tz ) {
				return array( $tz, 'php.ini date.timezone' );
			}
		}

		// 4) Pretpostavka: UTC. Najcesce tocna na shared hostingu, ali je pretpostavka.
		return array( new \DateTimeZone( 'UTC' ), __( 'pretpostavka (nije utvrdeno)', Config::TEXT_DOMAIN ) );
	}

	private static function napravi( string $naziv ): ?\DateTimeZone {
		if ( '' === $naziv ) {
			return null;
		}
		try {
			return new \DateTimeZone( $naziv );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function sati( int $sekundi ): string {
		$znak = $sekundi < 0 ? '-' : '+';
		$aps  = abs( $sekundi );
		return sprintf( '%s%02d:%02d', $znak, intdiv( $aps, 3600 ), intdiv( $aps % 3600, 60 ) );
	}
}
