<?php
/**
 * Datoteke cjenika na disku: mjesto, arhiva, stabilna poveznica, indeks.
 *
 * ZASTO BAS uploads
 *
 * Datoteka mora biti javno dohvatljiva, a `uploads` je jedini direktorij za koji
 * se zna da je dostupan izvana i zapisiv na dijeljenom hostingu. Izmjereno: HTTP
 * 200, `robots.txt` ne brani.
 *
 * ZASTO NE SIMBOLICKA POVEZNICA
 *
 * Stabilna poveznica na danasnji cjenik je KOPIJA, ne simlink. Na dijelu
 * dijeljenog hostinga simlink ne radi — i kad ne radi, ne javi gresku nego tiho
 * posluzi nista. Kopija od 300 KB je jeftinija od tihog izostanka.
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Arhiva {

	/** Direktorij u koji se pise. Stvara ga ako ga nema. */
	public static function direktorij(): string {
		$uploads = wp_upload_dir();
		$put     = trailingslashit( $uploads['basedir'] ) . Config::UPLOAD_PODDIR;

		if ( ! is_dir( $put ) ) {
			wp_mkdir_p( $put );
		}

		return $put;
	}

	public static function url(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . Config::UPLOAD_PODDIR;
	}

	/** Puna putanja stabilne datoteke za dani oblik. */
	public static function stabilna_putanja( string $oblik ): string {
		return trailingslashit( self::direktorij() ) . Ime::stabilna( $oblik );
	}

	public static function stabilni_url( string $oblik ): string {
		return trailingslashit( self::url() ) . Ime::stabilna( $oblik );
	}

	/**
	 * Je li stabilna datoteka od DANAS, po vremenu trgovine.
	 *
	 * Usporeduje se datum po `wp_date()`, ne `date()`: na produkciji je PHP na UTC
	 * a trgovina na Europe/Zagreb, pa bi se datoteka nastala nakon 22:00 UTC
	 * racunala kao jucerasnja iako je po vremenu trgovine danasnja.
	 */
	public static function od_danas( string $oblik ): bool {
		$put = self::stabilna_putanja( $oblik );

		if ( ! file_exists( $put ) ) {
			return false;
		}

		return wp_date( 'Ymd', (int) filemtime( $put ) ) === wp_date( 'Ymd' );
	}

	/**
	 * Objavi gotovu datoteku: spremi datiranu i osvjezi stabilnu.
	 *
	 * Premjestanje je `rename()` unutar istog direktorija, sto je na svim uobicajenim
	 * datotecnim sustavima atomarno — citatelj vidi ili staru ili novu datoteku,
	 * nikad polovicno napisanu.
	 */
	public static function objavi( string $privremena, string $ime_datirane, string $oblik ): bool {
		$dir      = trailingslashit( self::direktorij() );
		$datirana = $dir . $ime_datirane;

		if ( ! rename( $privremena, $datirana ) ) {
			return false;
		}

		// Stabilna je kopija. Simlink na dijelu hostinga tiho ne radi.
		$stabilna = self::stabilna_putanja( $oblik );
		$privremeni_cilj = $stabilna . '.novo';

		if ( ! copy( $datirana, $privremeni_cilj ) ) {
			return false;
		}

		return rename( $privremeni_cilj, $stabilna );
	}

	/**
	 * Datirane datoteke, najnovije prvo.
	 *
	 * @return array<int,array{ime:string,url:string,velicina:int,vrijeme:int,oblik:string}>
	 */
	public static function popis(): array {
		$dir = trailingslashit( self::direktorij() );
		$out = array();

		foreach ( (array) glob( $dir . '*.{xml,csv}', GLOB_BRACE ) as $put ) {
			$ime = basename( $put );

			// Stabilne datoteke nisu arhiva nego pokazivac na tekuce stanje.
			if ( 0 === strpos( $ime, 'cjenik-danas.' ) ) {
				continue;
			}

			// Datoteka koja se upravo pise nije objavljena i ne smije se ponuditi —
			// tko bi je preuzeo, dobio bi polovicu cjenika bez ijednog upozorenja.
			if ( 0 === strpos( $ime, 'privremeno-' ) ) {
				continue;
			}

			$out[] = array(
				'ime'      => $ime,
				'url'      => trailingslashit( self::url() ) . $ime,
				'velicina' => (int) filesize( $put ),
				'vrijeme'  => (int) filemtime( $put ),
				'oblik'    => pathinfo( $ime, PATHINFO_EXTENSION ),
				'datum'    => Ime::datum_iz_imena( $ime ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['vrijeme'] <=> $a['vrijeme'];
			}
		);

		return $out;
	}

	/**
	 * Obrisi datoteke starije od propisanog broja dana.
	 *
	 * Racuna se po datumu IZ IMENA, ne po vremenu izmjene. Vrijeme izmjene se na
	 * seljenju i sigurnosnim kopijama zna promijeniti, a datum u imenu je onaj koji
	 * je datoteka tvrdila kad je nastala.
	 *
	 * @return string[] obrisano
	 */
	public static function pospremi(): array {
		$granica  = wp_date( 'Ymd', time() - ( Config::CJENIK_ARHIVA_DANA * DAY_IN_SECONDS ) );
		$obrisano = array();

		foreach ( self::popis() as $d ) {
			$datum = $d['datum'];

			if ( '' === $datum ) {
				continue;
			}

			if ( $datum >= $granica ) {
				continue;
			}

			$put = trailingslashit( self::direktorij() ) . $d['ime'];

			if ( wp_delete_file( $put ) || ! file_exists( $put ) ) {
				$obrisano[] = $d['ime'];
			}
		}

		return $obrisano;
	}

	/**
	 * Indeks arhive kao staticka HTML stranica.
	 *
	 * Pise se u isti direktorij kao i datoteke, pa je dostupna bez WordPressa —
	 * inspektor koji dobije poveznicu vidi sve dostupne datoteke i kad je stranica
	 * u kvaru. Stranica je STATICKA i zato se ne kesira niti trosi PHP.
	 */
	public static function zapisi_indeks(): bool {
		$datoteke = self::popis();

		$redci = '';

		foreach ( $datoteke as $d ) {
			$redci .= sprintf(
				"<tr><td><a href=\"%s\">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>\n",
				esc_url( $d['url'] ),
				esc_html( $d['ime'] ),
				esc_html( strtoupper( $d['oblik'] ) ),
				esc_html( size_format( $d['velicina'] ) ),
				esc_html( wp_date( 'd.m.Y. H:i', $d['vrijeme'] ) )
			);
		}

		$tekuce = '';

		foreach ( Config::CJENIK_OBLICI as $oblik ) {
			if ( ! file_exists( self::stabilna_putanja( $oblik ) ) ) {
				continue;
			}

			$tekuce .= sprintf(
				"<li><a href=\"%s\">%s</a> — %s</li>\n",
				esc_url( self::stabilni_url( $oblik ) ),
				esc_html( Ime::stabilna( $oblik ) ),
				esc_html__( 'uvijek tekuci cjenik, stabilna poveznica', Config::TEXT_DOMAIN )
			);
		}

		$html = '<!doctype html>
<html lang="hr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . esc_html__( 'Cjenik — arhiva', Config::TEXT_DOMAIN ) . '</title>
<style>
body{font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:28px;color:#1d2327;background:#fff}
h1{font-size:22px;margin:0 0 4px}
p{color:#50575e;max-width:60em}
table{border-collapse:collapse;width:100%;max-width:60em;margin-top:14px}
th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #e6e6e6;font-size:14px}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970}
ul{padding-left:18px}
</style></head><body>
<h1>' . esc_html__( 'Cjenik', Config::TEXT_DOMAIN ) . '</h1>
<p>' . esc_html(
			sprintf(
				/* translators: %d = broj dana */
				__( 'Dnevne datoteke cijena. Arhiva se cuva %d dana.', Config::TEXT_DOMAIN ),
				Config::CJENIK_ARHIVA_DANA
			)
		) . '</p>
<h2>' . esc_html__( 'Tekuci cjenik', Config::TEXT_DOMAIN ) . '</h2>
<ul>' . $tekuce . '</ul>
<h2>' . esc_html__( 'Arhiva', Config::TEXT_DOMAIN ) . '</h2>
<table><thead><tr>
<th>' . esc_html__( 'Datoteka', Config::TEXT_DOMAIN ) . '</th>
<th>' . esc_html__( 'Oblik', Config::TEXT_DOMAIN ) . '</th>
<th>' . esc_html__( 'Velicina', Config::TEXT_DOMAIN ) . '</th>
<th>' . esc_html__( 'Objavljeno', Config::TEXT_DOMAIN ) . '</th>
</tr></thead><tbody>
' . $redci . '</tbody></table>
</body></html>
';

		return false !== file_put_contents( trailingslashit( self::direktorij() ) . 'index.html', $html );
	}

	public static function indeks_url(): string {
		return trailingslashit( self::url() ) . 'index.html';
	}

	/** Kad je stabilna datoteka zadnji put osvjezena, ili 0. */
	public static function zadnja_objava(): int {
		$put = self::stabilna_putanja( Config::CJENIK_OBLICI[0] );
		return file_exists( $put ) ? (int) filemtime( $put ) : 0;
	}
}
