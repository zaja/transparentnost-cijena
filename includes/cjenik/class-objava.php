<?php
/**
 * Javni pristup cjeniku: stabilna ruta, REST, indeks arhive.
 *
 * LIJENO GENERIRANJE
 *
 * Ako netko zatrazi danasnji cjenik a datoteka nije od danas, generira se prije
 * posluzivanja. To NIJE zamjena za cron — cron je taj koji jamci rok od 8:00.
 * Ovo je jamstvo drugom citatelju: tko god dode, ne dobije jucerasnji cjenik bez
 * da mu itko kaze da je jucerasnji.
 *
 * NE KESIRA SE
 *
 * Ruta cjenika salje `nocache_headers()` i izuzima se iz WP Rocketa. Keširan
 * cjenik je po definiciji jucerasnji, a jucerasnji cjenik objavljen kao danasnji
 * je netocna tvrdnja — ista ona koju cijeli dodatak postoji da sprijeci.
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Objava {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'rute' ) );

		// Ruta cjenika se ne smije kesirati ni u jednom sloju.
		add_filter( 'rocket_cache_reject_uri', array( __CLASS__, 'izuzmi_iz_kesa' ) );
		add_filter( 'rocket_cache_query_strings', '__return_empty_array' );
	}

	public static function rute(): void {
		register_rest_route(
			Config::REST_PROSTOR,
			'/' . Config::REST_RUTA,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'odgovor' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'oblik' => array(
						'default'           => Config::CJENIK_OBLICI[0],
						'validate_callback' => function ( $v ) {
							return in_array( $v, Config::CJENIK_OBLICI, true );
						},
					),
				),
			)
		);

		register_rest_route(
			Config::REST_PROSTOR,
			'/arhiva',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'odgovor_arhive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Danasnji cjenik, u stvarnom vremenu.
	 *
	 * @param \WP_REST_Request $zahtjev
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function odgovor( $zahtjev ) {
		$oblik = (string) $zahtjev->get_param( 'oblik' );

		self::osiguraj_danasnji( $oblik );

		$put = Arhiva::stabilna_putanja( $oblik );

		if ( ! file_exists( $put ) ) {
			return new \WP_Error(
				Config::PREFIX . '_nema_cjenika',
				__( 'Cjenik jos nije generiran.', Config::TEXT_DOMAIN ),
				array( 'status' => 503 )
			);
		}

		nocache_headers();

		$odgovor = new \WP_REST_Response(
			array(
				'oblik'      => $oblik,
				'url'        => Arhiva::stabilni_url( $oblik ),
				'generirano' => wp_date( 'c', (int) filemtime( $put ) ),
				'zapisa'     => self::zadnji( 'zapisa' ),
				'velicina'   => (int) filesize( $put ),
			)
		);

		$odgovor->header( 'Cache-Control', 'no-store, must-revalidate' );

		return $odgovor;
	}

	/** @return \WP_REST_Response */
	public static function odgovor_arhive() {
		$popis = array();

		foreach ( Arhiva::popis() as $d ) {
			$popis[] = array(
				'ime'      => $d['ime'],
				'url'      => $d['url'],
				'oblik'    => $d['oblik'],
				'velicina' => $d['velicina'],
				'datum'    => wp_date( 'c', $d['vrijeme'] ),
			);
		}

		nocache_headers();

		$odgovor = new \WP_REST_Response( array( 'datoteke' => $popis ) );
		$odgovor->header( 'Cache-Control', 'no-store, must-revalidate' );

		return $odgovor;
	}

	/**
	 * Generiraj danasnji cjenik ako ga nema.
	 *
	 * Radi u istom zahtjevu, bez komada: citatelj ceka, ali dobije tocan podatak.
	 * Zastita od istovremenog pokretanja je kratkotrajna brava — bez nje bi deset
	 * istovremenih zahtjeva pokrenulo deset generiranja.
	 */
	public static function osiguraj_danasnji( string $oblik ): void {
		if ( Arhiva::od_danas( $oblik ) ) {
			return;
		}

		$brava = Config::option( 'cjenik_lijeno' );

		if ( get_transient( $brava ) ) {
			return;
		}

		set_transient( $brava, 1, 2 * MINUTE_IN_SECONDS );

		$posao = \CJTR\Poslovi\Registar::nadi( 'generiraj_cjenik' );

		if ( $posao && '' === $posao->zapreka() ) {
			\CJTR\Poslovi\Pokretac::pokreni( 'generiraj_cjenik' );

			// Komadi se guraju ovdje jer citatelj ceka odgovor. Gornja granica je
			// zastita od beskonacne petlje, ne ocekivani broj prolaza.
			for ( $i = 0; $i < 500; $i++ ) {
				\CJTR\Poslovi\Pokretac::obradi_jedan_komad( 'generiraj_cjenik' );

				if ( ! \CJTR\Poslovi\Stanje::ucitaj( 'generiraj_cjenik' )->radi() ) {
					break;
				}
			}
		}

		delete_transient( $brava );
	}

	/** Podatak iz zadnje objave. */
	public static function zadnji( string $kljuc ) {
		$v = get_option( Config::option( Config::OPT_ZADNJI_CJENIK ), array() );
		return is_array( $v ) ? ( $v[ $kljuc ] ?? null ) : null;
	}

	/** WP Rocket ne smije kesirati rutu cjenika. */
	public static function izuzmi_iz_kesa( $uzorci ) {
		$uzorci   = is_array( $uzorci ) ? $uzorci : array();
		$uzorci[] = '/wp-json/' . Config::REST_PROSTOR . '/(.*)';
		return $uzorci;
	}

	/** Adresa REST rute, za prikaz. */
	public static function rest_url( string $oblik ): string {
		return add_query_arg( 'oblik', $oblik, rest_url( Config::REST_PROSTOR . '/' . Config::REST_RUTA ) );
	}
}
