<?php
/**
 * Snimatelj hookova s frontenda.
 *
 * ZASTO POSTOJI
 *
 * Dijagnostika se vrti u wp-adminu, a `$wp_filter` ondje NIJE isti kao na
 * frontendu. Velik broj dodataka svoje filtere registrira unutar `if ( ! is_admin() )`,
 * pa ih pregled iz admina jednostavno ne vidi. Za straznju provjeru cijena to je
 * ozbiljno: dodatak koji stvarno mijenja cijenu kupcu bio bi nevidljiv upravo
 * ondje gdje ga trazimo.
 *
 * Rjesenje: na obicnom posjetu frontendu zabiljezimo tko je zakacen na hookove
 * cijene i spremimo to u opciju. Dijagnostika onda spaja ono sto vidi sada (admin)
 * s onime sto je vidjeno ondje gdje kupac gleda.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Snimatelj {

	public static function init(): void {
		// Kasni prioritet: svi dodaci su vec registrirali svoje hookove.
		add_action( 'wp', array( __CLASS__, 'snimi' ), 9999 );
	}

	/** Snima samo na frontendu i samo ako je snimka zastarjela. */
	public static function snimi(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! self::zastarjela() ) {
			return;
		}

		$snimka = array(
			'kad'     => time(),
			'hookovi' => array(),
		);

		global $wp_filter;

		foreach ( array_keys( Config::HOOKOVI_CIJENE ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $prioritet => $stavke ) {
				foreach ( $stavke as $stavka ) {
					$snimka['hookovi'][] = array(
						'hook'      => $hook,
						'prioritet' => (int) $prioritet,
						'datoteka'  => Tragac::datoteka_funkcije( $stavka['function'] ),
					);
				}
			}
		}

		update_option( Config::option( Config::OPT_SNIMKA_HOOKOVA ), $snimka, false );
	}

	public static function snimka(): ?array {
		$s = get_option( Config::option( Config::OPT_SNIMKA_HOOKOVA ) );
		return is_array( $s ) && isset( $s['hookovi'] ) ? $s : null;
	}

	public static function zastarjela(): bool {
		$s = self::snimka();
		if ( ! $s ) {
			return true;
		}
		return ( time() - (int) $s['kad'] ) > Config::SNIMKA_VRIJEDI_SATI * HOUR_IN_SECONDS;
	}

	/**
	 * Zatrazi snimku tako da sami posjetimo naslovnicu.
	 *
	 * Na dijeljenom hostingu petlja prema sebi ne mora raditi; tada snimka
	 * jednostavno izostane i dijagnostika to prijavi umjesto da tvrdi da je
	 * sve pregledano.
	 */
	public static function zatrazi(): bool {
		$odgovor = wp_remote_get(
			add_query_arg( Config::hook( 'snimi' ), time(), home_url( '/' ) ),
			array(
				'timeout'   => 15,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		return ! is_wp_error( $odgovor ) && 200 === (int) wp_remote_retrieve_response_code( $odgovor );
	}

	public static function starost_sati(): ?float {
		$s = self::snimka();
		if ( ! $s ) {
			return null;
		}
		return round( ( time() - (int) $s['kad'] ) / HOUR_IN_SECONDS, 1 );
	}
}
