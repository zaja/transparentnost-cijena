<?php
/**
 * Obrada koju gura promet — rezerva za trgovinu bez crona.
 *
 * ZASTO POSTOJI
 *
 * Cron redak u cPanelu jedini je korak prvog postavljanja koji se ne moze
 * odraditi iz WordPressa. Trgovac koji do njega ne dode — nema pristup, hosting
 * ga ne nudi, ili je naprosto preskocio korak — ostaje bez cjenika, a da mu
 * nista ne kaze da se to dogada.
 *
 * Ovo ga ne rjesava nego ublazava: kad rok prode a posao stoji, iduci posjet
 * trgovini gurne nekoliko komada. Trgovina s prometom tako izade i bez crona.
 *
 * NIJE ZAMJENA ZA CRON, I TAKO SE I PONASA
 *
 * Dan bez ijednog posjeta ostaje bez cjenika. Posjet koji posluzi predmemoriju
 * (LiteSpeed, Cloudflare, WP Rocket) do PHP-a uopce ne dode. Zato nalaz o
 * nepostavljenom cronu OSTAJE i kad ovo radi — inace bi trgovac zakljucio da mu
 * cron ne treba, a treba mu.
 *
 * POSLIJE ODGOVORA, NIKAD PRIJE
 *
 * Kupac ne smije cekati na nas. Posao se gura u `register_shutdown_function`, a
 * gdje PHP-FPM to nudi, veza se prije toga zatvori — pa preglednik dobije
 * stranicu i ne zna da se iza nje jos nesto radi.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;
use CJTR\Postavke;

defined( 'ABSPATH' ) || exit;

final class Promet {

	/**
	 * Koliko sekundi smije trajati guranje nakon odgovora.
	 *
	 * Namjerno kratko. Posjetitelj je otisao, ali proces jos drzi PHP radnika, a
	 * na dijeljenom hostingu ih je malo. Cjenik od 3417 zapisa izade u nekoliko
	 * posjeta; trgovina koja ih nema ionako ne moze racunati na ovaj put.
	 */
	const SEKUNDI = 5;

	/** Da ne gura svaki posjet, nego otprilike svaki N-ti. Jeftino i dovoljno. */
	const SVAKI_N_TI = 3;

	/** @var bool u ovom zahtjevu smo vec odlucili */
	private static $odluceno = false;

	public static function init(): void {
		// `wp_loaded` je najranija tocka na kojoj su svi dodaci ucitani, a jos
		// nista nije ispisano.
		add_action( 'wp_loaded', array( __CLASS__, 'mozda_gurni' ), 20 );
	}

	/**
	 * Treba li ovaj zahtjev gurnuti obradu.
	 *
	 * Sve provjere su namjerno jeftine: ovo se izvodi na SVAKOM posjetu, pa
	 * najskuplja stvar koja se smije dogoditi na zahtjevu koji nema sto raditi je
	 * jedan pogled u opciju.
	 */
	public static function mozda_gurni(): void {
		if ( self::$odluceno ) {
			return;
		}
		self::$odluceno = true;

		if ( ! Postavke::promet_gura() ) {
			return;
		}

		// Admin, AJAX, REST, cron i CLI imaju svoje putove. Ovo je za posjetitelja.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Prijavljeni korisnik obicno ne vidi predmemoriju, pa bi njegovi posjeti
		// nosili teret koji bi inace bio raspodijeljen. I gore: administrator bi
		// cekao na spremanje dok se u pozadini vrti cjenik.
		if ( is_user_logged_in() ) {
			return;
		}

		if ( ! self::red_je_na_nas() ) {
			return;
		}

		$kljuc = self::posao_koji_ceka();

		if ( '' === $kljuc ) {
			return;
		}

		register_shutdown_function( array( __CLASS__, 'gurni' ), $kljuc );
	}

	/**
	 * Otprilike svaki N-ti posjet.
	 *
	 * Bez ovoga bi svaki posjet u minuti prije dovrsetka posla pokusao preuzeti
	 * isti posao, svaki bi pao na zakljucavanju, i svaki bi za to platio jedan
	 * upit. Ovako plati svaki treci.
	 */
	private static function red_je_na_nas(): bool {
		return 0 === wp_rand( 0, self::SVAKI_N_TI - 1 );
	}

	/**
	 * Koji posao ceka na guranje, ako ijedan.
	 *
	 * Dva slucaja, i oba su ista stvar iz perspektive posjetitelja:
	 *
	 *   - posao je zapoceo i stao (cron ga je pokrenuo pa se ugasio)
	 *   - posao nije ni zapoceo, a rok je prosao
	 *
	 * @return string kljuc posla, ili prazno
	 */
	private static function posao_koji_ceka(): string {
		foreach ( Registar::svi() as $kljuc => $posao ) {
			if ( '' === $posao->dnevno() ) {
				continue;
			}

			$stanje = Stanje::ucitaj( $kljuc );

			if ( $stanje->radi() ) {
				return $kljuc;
			}
		}

		// Nijedan ne radi — je li rok prosao a danasnjeg posla jos nema.
		if ( time() < Postavke::ocekivano_do() ) {
			return '';
		}

		$danas = wp_date( 'Ymd' );

		foreach ( Registar::svi() as $kljuc => $posao ) {
			if ( '' === $posao->dnevno() ) {
				continue;
			}

			$stanje = Stanje::ucitaj( $kljuc );

			if ( $stanje->zavrseno && wp_date( 'Ymd', strtotime( $stanje->zavrseno . ' UTC' ) ) === $danas ) {
				continue;
			}

			if ( '' !== $posao->zapreka() ) {
				continue;
			}

			return $kljuc;
		}

		return '';
	}

	/**
	 * Guranje nakon poslanog odgovora.
	 *
	 * Posao koji jos nije pokrenut pokrece se; posao koji radi nastavlja. Staje na
	 * vremenskom budzetu, kad posao zavrsi, ili kad komad nista ne obradi — tri
	 * razloga, i svaki od njih znaci da se dalje nema sto raditi SADA.
	 */
	public static function gurni( string $kljuc ): void {
		self::zatvori_vezu();

		$stanje = Stanje::ucitaj( $kljuc );

		if ( ! $stanje->radi() ) {
			$stanje = Pokretac::pokreni( $kljuc );

			if ( ! $stanje->radi() ) {
				return;
			}
		}

		$do = time() + self::SEKUNDI;

		while ( time() < $do ) {
			$prije = (int) $stanje->obradeno;
			$stanje = Pokretac::obradi_jedan_komad( $kljuc );

			if ( ! $stanje->radi() ) {
				break;
			}

			// Komad koji nista nije pomaknuo znaci da je posao preuzeo netko drugi
			// ili da vise nema sto raditi. Vrtjeti se u prazno do isteka budzeta
			// drzalo bi PHP radnika bez ikakve koristi.
			if ( (int) $stanje->obradeno === $prije ) {
				break;
			}
		}
	}

	/**
	 * Zatvori vezu prema pregledniku, ako se dade.
	 *
	 * Bez ovoga preglednik drzi otvorenu vezu dok se posao vrti — stranica je
	 * ispisana, ali kotacic se okrece i posjetitelj misli da se nesto ucitava.
	 */
	private static function zatvori_vezu(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return;
		}

		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
			return;
		}

		// Bez FPM-a: barem oslobodi izlazne meduspremnike.
		while ( ob_get_level() > 0 ) {
			@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		flush();
	}
}
