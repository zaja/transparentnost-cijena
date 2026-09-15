<?php
/**
 * Postavke koje trgovac mijenja.
 *
 * ZASTO SADA POSTOJE, A PRIJE NISU
 *
 * Dok je dodatak radio za jednu trgovinu, konstanta je bila ispravan izbor: jedna
 * vrijednost, jedno mjesto, nemoguce je razici se. Cim postoji druga trgovina, ista
 * konstanta postaje tvrdnja o tudoj trgovini koju nitko nije provjerio.
 *
 * ODNOS PREMA KONSTANTAMA
 *
 * Konstanta u `Config` ostaje kao ZADANA vrijednost, ne nestaje. Trgovina koja nista
 * ne postavi radi tocno kao i dosad. Postavka je odstupanje od zadanog, i vidi se
 * da je odstupanje.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Postavke {

	/** Referentni datum za opce proizvode. */
	const REF_OSTALO = 'ref_datum_ostalo';

	/** Referentni datum za regulirane skupine. */
	const REF_REGULIRANE = 'ref_datum_regulirane';

	/** Prodaje li trgovina proizvode iz reguliranih skupina. */
	const IMA_REGULIRANE = 'ima_regulirane';

	/** Prikazuje li se najniza cijena u 30 dana. */
	const NAJNIZA_30 = 'najniza_30';

	/** Naziv tvrtke, za cjenik. */
	const NAZIV_TVRTKE = 'naziv_tvrtke';

	/** Oznaka prodajnog mjesta, za ime datoteke. */
	const OZNAKA_OBJEKTA = 'oznaka_objekta';

	/** Sat do kojeg cjenik mora izaci, po vremenu trgovine. */
	const ROK_OBJAVE = 'rok_objave';

	/** Smije li promet gurati obradu kad cron ne radi. */
	const PROMET_GURA = 'promet_gura';

	/** Smije li se prikaz sidrene cijene dopisati JavaScriptom kad ga tema preskoci. */
	const JS_REZERVA = 'js_rezerva';

	/**
	 * Zadane vrijednosti — jedno mjesto, da se ne pogadaju po kodu.
	 *
	 * `najniza_30` nema zadanu vrijednost ovdje nego se racuna: zadano je
	 * ISKLJUCENO ako je otkriven drugi dodatak koji vec prikazuje istu tvrdnju.
	 * Dvije precrtane cijene su gore od nijedne.
	 */
	private static function zadano(): array {
		return array(
			self::REF_OSTALO      => Config::REF_DATUM_OSTALO,
			self::REF_REGULIRANE  => Config::REF_DATUM_REGULIRANE,
			self::IMA_REGULIRANE  => false,
			self::NAJNIZA_30      => ! self::ima_drugi_omnibus(),
			self::NAZIV_TVRTKE    => get_bloginfo( 'name' ),
			self::OZNAKA_OBJEKTA  => Config::OBJEKT_OZNAKA,
			self::ROK_OBJAVE      => Config::ROK_OBJAVE_SAT,
			self::PROMET_GURA     => true,
			self::JS_REZERVA      => true,
		);
	}

	/** @return mixed */
	public static function daj( string $ime ) {
		$zadano = self::zadano();

		if ( ! array_key_exists( $ime, $zadano ) ) {
			return null;
		}

		$v = get_option( Config::option( $ime ), null );

		return ( null === $v ) ? $zadano[ $ime ] : $v;
	}

	public static function spremi( string $ime, $vrijednost ): void {
		if ( ! array_key_exists( $ime, self::zadano() ) ) {
			return;
		}
		update_option( Config::option( $ime ), $vrijednost, false );
	}

	/** Je li vrijednost postavljena rucno, ili je jos zadana. */
	public static function postavljeno( string $ime ): bool {
		return null !== get_option( Config::option( $ime ), null );
	}

	/* ------------------------------------------------------- izvedene vrijednosti */

	/**
	 * Referentni datum za jednu zakonsku kategoriju.
	 *
	 * Dva datuma postoje jer ih propis razlikuje, a ne zato sto je tako zgodno.
	 * Trgovac koji prodaje i hranu i sve ostalo ima OBA, i to je najcesci nacin da
	 * se pogrijesi — zato carobnjak pita, umjesto da pretpostavi.
	 */
	public static function ref_datum( string $kategorija = '' ): string {
		if ( '' !== $kategorija
			&& self::daj( self::IMA_REGULIRANE )
			&& in_array( $kategorija, Config::REGULIRANE_SKUPINE, true ) ) {
			return (string) self::daj( self::REF_REGULIRANE );
		}

		return (string) self::daj( self::REF_OSTALO );
	}

	/** Prikazuje li se najniza cijena u 30 dana. */
	public static function najniza_30(): bool {
		return (bool) self::daj( self::NAJNIZA_30 );
	}

	public static function rok_objave_sat(): int {
		return (int) self::daj( self::ROK_OBJAVE );
	}

	/** Smije li promet gurati obradu. Zadano da — trgovina bez crona inace ne izade. */
	public static function promet_gura(): bool {
		return (bool) self::daj( self::PROMET_GURA );
	}

	/** Smije li JavaScript dopisati prikaz ondje gdje ga tema nije ispisala. */
	public static function js_rezerva(): bool {
		return (bool) self::daj( self::JS_REZERVA );
	}

	/**
	 * Drugi dodatak koji vec prikazuje najnizu cijenu u 30 dana.
	 *
	 * @return string ime dodatka, ili prazno
	 */
	public static function drugi_omnibus(): string {
		foreach ( Config::DODACI_NAJNIZE_CIJENE as $slug ) {
			foreach ( self::aktivni_dodaci() as $put ) {
				if ( 0 === strpos( $put, $slug ) ) {
					return self::ime_dodatka( $put );
				}
			}
		}

		return '';
	}

	public static function ima_drugi_omnibus(): bool {
		return '' !== self::drugi_omnibus();
	}

	/**
	 * Vrijeme kad se cjenik ocekuje, po vremenu trgovine.
	 *
	 * Rok je 8:00, ali posao se zakazuje s rezervom — pa "nije objavljen" prije tog
	 * trenutka nije nalaz nego cekanje. Razlika je cijela poanta: administrator koji
	 * u 5 ujutro vidi crveni redak nauci da crveno ne znaci nista.
	 */
	public static function ocekivano_do(): int {
		$sat = self::rok_objave_sat() - Config::ROK_REZERVA_SATI;

		$danas = wp_date( 'Y-m-d' );
		$ts    = strtotime( sprintf( '%s %02d:00:00', $danas, max( 0, $sat ) ) );

		// `strtotime` racuna u zoni PHP-a (UTC), a nama treba zona trgovine.
		$tz = wp_timezone();
		$d  = new \DateTimeImmutable( sprintf( '%s %02d:00:00', $danas, max( 0, $sat ) ), $tz );

		return $d->getTimestamp() ?: (int) $ts;
	}

	/* ------------------------------------------------------------------- interno */

	/** @return string[] */
	private static function aktivni_dodaci(): array {
		$aktivni = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$aktivni = array_merge( $aktivni, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return $aktivni;
	}

	private static function ime_dodatka( string $put ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$podaci = get_plugin_data( WP_PLUGIN_DIR . '/' . $put, false, false );

		return ! empty( $podaci['Name'] ) ? (string) $podaci['Name'] : dirname( $put );
	}
}
