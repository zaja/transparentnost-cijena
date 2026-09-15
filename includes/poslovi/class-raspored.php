<?php
/**
 * Zakazivanje iduceg komada.
 *
 * Action Scheduler ako postoji, inace wp-cron. Ni jedno ni drugo nije jedini
 * put — admin moze vrtjeti posao rucno preko AJAX-a i kad raspored ne radi.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Raspored {

	public static function init(): void {
		add_action( Config::hook( Config::HOOK_KOMAD ), array( __CLASS__, 'izvrsi' ), 10, 1 );

		/*
		 * Dnevni okidac.
		 *
		 * Vjesa se na `wp_loaded`, a ne na `admin_init`: cron na posluzitelju poziva
		 * `wp-cron.php`, sto NIJE admin zahtjev. Zakacen na admin, posao bi se
		 * pokretao samo kad netko otvori upravljacku plocu — a cjenik mora izaci do
		 * 8:00 i kad nitko ne radi.
		 */
		add_action( Config::hook( self::HOOK_DNEVNO ), array( __CLASS__, 'dnevni_prolaz' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'osiguraj_dnevni' ) );
	}

	/** Ime hooka dnevnog prolaza. */
	const HOOK_DNEVNO = 'dnevni_prolaz';

	/** Zakazi dnevni prolaz ako vec nije zakazan. */
	public static function osiguraj_dnevni(): void {
		if ( wp_next_scheduled( Config::hook( self::HOOK_DNEVNO ) ) ) {
			return;
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', Config::hook( self::HOOK_DNEVNO ) );
	}

	/**
	 * Pokreni poslove koji se izvrsavaju dnevno, a danas jos nisu.
	 *
	 * Provjerava se SATNO, ne dnevno. Cron na dijeljenom hostingu zna preskociti
	 * prolaz — ako se to dogodi u 6:07, satni prolaz ce posao pokrenuti u 7:07 i
	 * rok od 8:00 je i dalje ispunjen. Dnevni raspored bi cekao sutra.
	 *
	 * "Danas" se racuna po vremenu TRGOVINE. PHP je na produkciji na UTC, pa bi
	 * `date()` u 23:10 tvrdio da je jos jucer.
	 */
	public static function dnevni_prolaz(): void {
		$danas = wp_date( 'Ymd' );

		/*
		 * Biljezimo da se posluzitelj javio.
		 *
		 * Ekran Stanje ne moze izravno provjeriti je li netko u cPanelu upisao cron
		 * redak — moze samo vidjeti javlja li se nesto redovito. Zato se trenutak
		 * zapisuje ovdje: prode li dan bez javljanja, dnevno pokretanje ocito ne radi.
		 */
		update_option( Config::option( 'zadnji_cron' ), time(), false );

		foreach ( Registar::svi() as $kljuc => $posao ) {
			if ( '' === $posao->dnevno() ) {
				continue;
			}

			$stanje = Stanje::ucitaj( $kljuc );

			if ( $stanje->radi() ) {
				continue;
			}

			if ( $stanje->zavrseno && wp_date( 'Ymd', strtotime( $stanje->zavrseno . ' UTC' ) ) === $danas ) {
				continue;
			}

			if ( '' !== $posao->zapreka() ) {
				continue;
			}

			Pokretac::pokreni( $kljuc );
		}
	}

	/** Zakazi obradu iduceg komada sto prije. */
	public static function zakazi( string $kljuc ): void {
		if ( self::vec_zakazano( $kljuc ) ) {
			return;
		}

		if ( self::ima_action_scheduler() ) {
			as_enqueue_async_action( Config::hook( Config::HOOK_KOMAD ), array( $kljuc ), Config::PREFIX );
			return;
		}

		wp_schedule_single_event( time() + 5, Config::hook( Config::HOOK_KOMAD ), array( $kljuc ) );
		self::gurni_wp_cron();
	}

	public static function otkazi( string $kljuc ): void {
		if ( self::ima_action_scheduler() ) {
			as_unschedule_all_actions( Config::hook( Config::HOOK_KOMAD ), array( $kljuc ), Config::PREFIX );
		}
		wp_clear_scheduled_hook( Config::hook( Config::HOOK_KOMAD ), array( $kljuc ) );
	}

	/** Poziva raspored. Jedina razlika prema AJAX-u je tko je pozvao. */
	public static function izvrsi( string $kljuc ): void {
		Pokretac::obradi_jedan_komad( $kljuc );
	}

	public static function ima_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	public static function nacin(): string {
		return self::ima_action_scheduler()
			? __( 'Action Scheduler', Config::TEXT_DOMAIN )
			: __( 'ugradeni WordPress raspored', Config::TEXT_DOMAIN );
	}

	/**
	 * Ima li vec ZAKAZAN komad na cekanju.
	 *
	 * Gleda se SAMO stanje "pending", ne i "in-progress". Razlika je lomila lanac:
	 * `as_has_scheduled_action()` vraca istinu i za akciju koja se UPRAVO IZVRSAVA,
	 * a idući komad zakazuje se iz te iste akcije. Rezultat: zakazivanje se
	 * preskace kao suvisno, akcija zavrsi, i ne ostane nista zakazano — posao
	 * zauvijek stoji na "radi", zapet na prvom komadu.
	 *
	 * Kroz admin se to nije vidjelo jer ondje komade gura preglednik u petlji. Pod
	 * cronom, gdje nitko ne gura, posao nikad ne bi zavrsio — a cjenik mora izaci
	 * do 8:00 bez da itko gleda. Nadeno 14.9.2026. pri testiranju modula 4.
	 */
	private static function vec_zakazano( string $kljuc ): bool {
		if ( self::ima_action_scheduler() && function_exists( 'as_get_scheduled_actions' ) ) {
			$nadene = as_get_scheduled_actions(
				array(
					'hook'     => Config::hook( Config::HOOK_KOMAD ),
					'args'     => array( $kljuc ),
					'group'    => Config::PREFIX,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			);

			return ! empty( $nadene );
		}

		return (bool) wp_next_scheduled( Config::hook( Config::HOOK_KOMAD ), array( $kljuc ) );
	}

	/** Nezahtjevan poticaj wp-cronu; ne cekamo odgovor. */
	private static function gurni_wp_cron(): void {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}
}
