<?php
/**
 * 4. Gotov redak za cPanel cron, s preracunom u zonu posluzitelja.
 *
 * Posao mora pasti prije roka po vremenu TRGOVINE, a cPanel ga izvrsava po vremenu
 * POSLUZITELJA. Uz to, zona trgovine ima ljetno i zimsko vrijeme, a zona posluzitelja
 * ne mora. Zato se racuna za oba doba godine i uzima ranije od dvoje — tako je posao
 * siguran cijelu godinu, bez dirianja dvaput godisnje.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Cpanel_Cron extends Provjera {

	/** Minuta u satu. Namjerno nije 0 — u puni sat se gura pola interneta. */
	const MINUTA = 7;

	public function kljuc(): string {
		return 'cpanel-cron';
	}

	public function izvrsi(): Rezultat {
		$r    = new Rezultat( __( '4. Gotov redak za automatski posao (cPanel)', Config::TEXT_DOMAIN ) );
		$zone = Zone::izmjeri();

		$ciljni_sat = Config::ROK_OBJAVE_SAT - Config::ROK_REZERVA_SATI;
		$izracun    = $this->izracunaj( $zone, $ciljni_sat );

		$r->stavka( __( 'Rok za objavu (vrijeme trgovine)', Config::TEXT_DOMAIN ), sprintf( '%02d:00', Config::ROK_OBJAVE_SAT ) );
		$r->stavka( __( 'Sigurnosna rezerva', Config::TEXT_DOMAIN ), sprintf( '%d h', Config::ROK_REZERVA_SATI ) );
		$r->stavka( __( 'Ciljano vrijeme (vrijeme trgovine)', Config::TEXT_DOMAIN ), sprintf( '%02d:%02d', $ciljni_sat, self::MINUTA ) );
		$r->stavka( __( 'Zona posluzitelja', Config::TEXT_DOMAIN ), $zone['sustav'] );
		$r->stavka( __( 'Kako je utvrdena', Config::TEXT_DOMAIN ), $zone['sustav_izvor'] );

		// Tablica preracuna, da se vidi odakle broj.
		$redci = array();
		foreach ( $izracun['probe'] as $p ) {
			$redci[] = sprintf(
				'  %-8s  trgovina %s %s  ->  posluzitelj %s %s',
				$p['oznaka'],
				$p['trgovina_vrijeme'],
				$p['trgovina_kratica'],
				$p['posluzitelj_vrijeme'],
				$p['posluzitelj_kratica']
			);
		}
		$r->blok(
			__( 'Preracun', Config::TEXT_DOMAIN ),
			implode( "\n", $redci ) . "\n" . sprintf(
				/* translators: %s = vrijeme */
				__( '  uzima se ranije od dvoje: %s po vremenu posluzitelja', Config::TEXT_DOMAIN ),
				$izracun['odabrano']
			)
		);

		$url     = site_url( 'wp-cron.php?doing_wp_cron' );
		$redak   = sprintf( '%d %d * * * wget -q -O /dev/null "%s" >/dev/null 2>&1', self::MINUTA, $izracun['sat'], $url );
		$redak_c = sprintf( '%d %d * * * curl -s "%s" >/dev/null 2>&1', self::MINUTA, $izracun['sat'], $url );

		$r->blok( __( 'Redak za cPanel (wget)', Config::TEXT_DOMAIN ), $redak );
		$r->blok( __( 'Ako wget ne postoji, ovaj (curl)', Config::TEXT_DOMAIN ), $redak_c );

		$r->stavka( __( 'Posao ce se izvrsiti u', Config::TEXT_DOMAIN ), sprintf( '%02d:%02d %s', $izracun['sat'], self::MINUTA, __( 'po vremenu posluzitelja', Config::TEXT_DOMAIN ) ) );
		$r->stavka( __( 'Sto je po vremenu trgovine', Config::TEXT_DOMAIN ), $izracun['ishod_opis'] );

		if ( 'pretpostavka (nije utvrdeno)' === $zone['sustav_izvor'] || false !== strpos( $zone['sustav_izvor'], 'pretpostavka' ) ) {
			$r->status( Rezultat::UPOZ );
			$r->znacenje( __( 'Vrijeme posluzitelja nije se dalo pouzdano utvrditi, pa je racunato uz pretpostavku da je posluzitelj na svjetskom vremenu (UTC). To je najcesci slucaj na dijeljenom hostingu, ali treba provjeriti.', Config::TEXT_DOMAIN ) );
			$r->postupak( __( 'U cPanelu otvorite Cron Jobs — pri vrhu pise trenutno vrijeme posluzitelja. Ako se razlikuje od vremena navedenog gore, javite nam pa cemo redak preracunati.', Config::TEXT_DOMAIN ) );
		} else {
			$r->status( Rezultat::OK );
			$r->znacenje( __( 'Ovaj redak treba upisati u cPanel, u dio Cron Jobs. Time trgovina prestaje ovisiti o posjetima i posao se izvrsava svaki dan u isto vrijeme, prije roka, i ljeti i zimi.', Config::TEXT_DOMAIN ) );
			$r->postupak( __( 'U cPanelu otvorite Cron Jobs, odaberite Add New Cron Job, zalijepite redak i spremite. Usporedite vrijeme posluzitelja koje cPanel pokazuje s vremenom navedenim gore — ako se razlikuju, javite nam.', Config::TEXT_DOMAIN ) );
		}

		return $r;
	}

	/**
	 * Nade sat po vremenu posluzitelja koji je siguran u oba doba godine.
	 */
	private function izracunaj( array $zone, int $ciljni_sat ): array {
		$wp_tz     = $zone['wp_tz'];
		$sustav_tz = $zone['sustav_tz'] ?: new \DateTimeZone( 'UTC' );
		$godina    = (int) gmdate( 'Y' );

		$probe = array();
		foreach (
			array(
				'zima'  => sprintf( '%d-01-15', $godina ),
				'ljeto' => sprintf( '%d-07-15', $godina ),
			) as $oznaka => $datum
		) {
			$u_trgovini = new \DateTimeImmutable(
				sprintf( '%s %02d:%02d:00', $datum, $ciljni_sat, self::MINUTA ),
				$wp_tz
			);
			$na_posluzitelju = $u_trgovini->setTimezone( $sustav_tz );

			$probe[ $oznaka ] = array(
				'oznaka'              => $oznaka,
				'trgovina_vrijeme'    => $u_trgovini->format( 'H:i' ),
				'trgovina_kratica'    => $u_trgovini->format( 'T' ),
				'posluzitelj_vrijeme' => $na_posluzitelju->format( 'H:i' ),
				'posluzitelj_kratica' => $na_posluzitelju->format( 'T' ),
				'minute'              => (int) $na_posluzitelju->format( 'H' ) * 60 + (int) $na_posluzitelju->format( 'i' ),
				'sat'                 => (int) $na_posluzitelju->format( 'H' ),
			);
		}

		// Ranije od dvoje je sigurno u oba doba godine.
		$odabrana = $probe['zima']['minute'] <= $probe['ljeto']['minute'] ? $probe['zima'] : $probe['ljeto'];

		// Sto taj sat znaci po vremenu trgovine, u oba doba godine.
		$opis = array();
		foreach ( $probe as $oznaka => $p ) {
			$na_posluzitelju = new \DateTimeImmutable(
				sprintf( '%s %02d:%02d:00', sprintf( '%d-%s-15', $godina, 'zima' === $oznaka ? '01' : '07' ), $odabrana['sat'], self::MINUTA ),
				$sustav_tz
			);
			$u_trgovini = $na_posluzitelju->setTimezone( $wp_tz );
			$opis[]     = sprintf( '%s %s', $u_trgovini->format( 'H:i' ), $oznaka );
		}

		return array(
			'probe'      => $probe,
			'sat'        => $odabrana['sat'],
			'odabrano'   => sprintf( '%02d:%02d', $odabrana['sat'], self::MINUTA ),
			'ishod_opis' => implode( ' / ', $opis ),
		);
	}
}
