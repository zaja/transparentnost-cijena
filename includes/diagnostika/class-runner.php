<?php
/**
 * Pokretac provjera i tekstualni ispis.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Runner {

	/** Redoslijed je namjeran — odgovara numeraciji u izvjestaju. */
	private const PROVJERE = array(
		Provjere\Okolina::class,
		Provjere\Zapisivost::class,
		Provjere\Cron::class,
		Provjere\Cpanel_Cron::class,
		Provjere\Kapacitet::class,
		Provjere\Dohvat::class,
		Provjere\Sukobi::class,
		Provjere\Povijest::class,
		Provjere\Prikaz::class,
		Provjere\Racun::class,
		Provjere\Cjenik::class,
	);

	/** @return Rezultat[] */
	public function pokreni(): array {
		$rezultati = array();

		foreach ( self::PROVJERE as $klasa ) {
			/** @var Provjera $provjera */
			$provjera = new $klasa();
			try {
				$rezultati[] = $provjera->izvrsi();
			} catch ( \Throwable $e ) {
				$r = new Rezultat( $klasa );
				$r->status( Rezultat::LOSE )
					->stavka( __( 'Provjera nije uspjela', Config::TEXT_DOMAIN ), $e->getMessage() )
					->znacenje( __( 'Ova provjera se nije mogla izvrsiti. To je greska u dodatku, ne u trgovini.', Config::TEXT_DOMAIN ) );
				$rezultati[] = $r;
			}
		}

		Provjere\Zapisivost::pospremi();

		return $rezultati;
	}

	/** Skupna ocjena — najgori pojedinacni status. */
	public function ukupno( array $rezultati ): string {
		$tezina = array( Rezultat::INFO => 0, Rezultat::OK => 1, Rezultat::UPOZ => 2, Rezultat::LOSE => 3 );
		$najgori = Rezultat::OK;
		foreach ( $rezultati as $r ) {
			if ( ( $tezina[ $r->status ] ?? 0 ) > ( $tezina[ $najgori ] ?? 0 ) ) {
				$najgori = $r->status;
			}
		}
		return $najgori;
	}

	/** @param Rezultat[] $rezultati */
	public function kao_tekst( array $rezultati ): string {
		$l   = array();
		$l[] = sprintf( 'IZVJESTAJ O OKOLINI — %s', get_bloginfo( 'name' ) );
		$l[] = sprintf( 'Adresa: %s', home_url() );
		$l[] = sprintf( 'Izradeno: %s', current_time( 'd.m.Y. H:i' ) );
		$l[] = sprintf( 'Dodatak: %s %s', __( 'Cjenovna transparentnost', Config::TEXT_DOMAIN ), Config::VERSION );
		$l[] = str_repeat( '=', 64 );

		foreach ( $rezultati as $r ) {
			$l[] = '';
			$l[] = sprintf( '[%s] %s', strtoupper( $r->oznaka() ), $r->naslov );
			$l[] = str_repeat( '-', 64 );

			foreach ( $r->stavke as $par ) {
				$l[] = sprintf( '  %-42s %s', $par[0] . ':', $par[1] );
			}

			foreach ( $r->blokovi as $naslov => $sadrzaj ) {
				$l[] = '';
				$l[] = '  ' . $naslov . ':';
				foreach ( preg_split( '/\R/', $sadrzaj ) as $redak ) {
					$l[] = '    ' . $redak;
				}
			}

			if ( '' !== $r->znacenje ) {
				$l[] = '';
				$l[] = '  ' . __( 'Sto to znaci:', Config::TEXT_DOMAIN );
				$l[] = '    ' . wordwrap( $r->znacenje, 60, "\n    " );
			}

			if ( '' !== $r->postupak ) {
				$l[] = '';
				$l[] = '  ' . __( 'Sto uciniti:', Config::TEXT_DOMAIN );
				$l[] = '    ' . wordwrap( $r->postupak, 60, "\n    " );
			}
		}

		$l[] = '';
		$l[] = str_repeat( '=', 64 );
		$l[] = sprintf( '%s: %s', __( 'Ukupna ocjena', Config::TEXT_DOMAIN ), strtoupper( $this->ukupno( $rezultati ) ) );

		return implode( "\n", $l );
	}
}
