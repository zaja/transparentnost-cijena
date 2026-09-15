<?php
/**
 * Jedan zapis cjenika.
 *
 * JEDNA METODA PO POLJU
 *
 * Ranije je ovdje stajao utipkan popis vrijednosti, pa je dodavanje polja u shemu
 * trazilo izmjenu na dva mjesta — u tablici i ovdje. Sada se svako polje iz
 * `Config::SHEMA_CJENIKA` preslikava u metodu `polje_<kljuc>()`, a `iz()` samo
 * prolazi kroz shemu. Redoslijed, imena i prisutnost polja u datoteci odreduje
 * iskljucivo ta tablica.
 *
 * MALOPRODAJNA CIJENA JE KONACNA CIJENA
 *
 * Ono sto kupac plati, ukljucivo posebni oblik prodaje. Cita se iz mete `_price`,
 * koja je na ovom shopu autoritativna (izmjereno: nula razlika prema `get_price()`).
 * Meta se cita izravno iz istog razloga zbog kojeg postoji straza: `get_price()`
 * prolazi kroz filtere dodataka, pa bi cjenik mogao objaviti cijenu koju neki
 * dodatak prikazuje a blagajna ne naplacuje.
 *
 * PRAZNO NIJE ISTO STO I IZOSTAVLJENO
 *
 *   prazno        — podatak postoji, ali ga ne znamo
 *   izostavljeno  — podatak se za ovaj artikl ne primjenjuje
 *
 * Producent vraca `''` za prvo i `null` za drugo. Samo polja oznacena kao
 * `uvjetno` smiju vratiti `null`.
 *
 * @package CJTR
 */

namespace CJTR\Cjenik;

use CJTR\Config;
use CJTR\Podaci\Cijena_Po_Jedinici;
use CJTR\Podaci\Gtin;
use CJTR\Podaci\Kolicina;
use CJTR\Podaci\Woo_Polja;
use CJTR\Povijest\Vrsta_Prodaje;

defined( 'ABSPATH' ) || exit;

final class Redak {

	/**
	 * Sastavi zapis iz retka upita.
	 *
	 * @return array<string,string|null> kljucevi tocno iz Config::SHEMA_CJENIKA;
	 *                                   null znaci "izostavi", '' znaci "prazno"
	 */
	public static function iz( $r ): array {
		$zapis = array();

		foreach ( array_keys( Config::SHEMA_CJENIKA ) as $kljuc ) {
			$metoda = 'polje_' . $kljuc;

			if ( ! method_exists( __CLASS__, $metoda ) ) {
				// Shema trazi polje za koje nema producenta. Tiho prazno polje bilo bi
				// gore od praznog zapisa — datoteka bi izgledala ispravno.
				$zapis[ $kljuc ] = '';
				continue;
			}

			$v = self::$metoda( $r );

			// Samo uvjetno polje smije nestati. Obvezno polje koje vrati null je
			// greska u producentu, ne izostanak podatka.
			if ( null === $v && ! Config::shema_uvjetno( $kljuc ) ) {
				$v = '';
			}

			$zapis[ $kljuc ] = ( null === $v ) ? null : (string) $v;
		}

		return $zapis;
	}

	/* ============================= POLJA ============================= */

	private static function polje_naziv( $r ): string {
		return (string) $r->naziv;
	}

	private static function polje_sifra( $r ): string {
		return (string) $r->sku;
	}

	/**
	 * Marka: WooCommerceova ako postoji, nasa samo ako ne postoji.
	 *
	 * Cita se u trenutku objave, ne iz kopije — kopija zastari cim netko promijeni
	 * izvornik, i tada bi cjenik objavljivao broj koji u trgovini vise ne stoji.
	 */
	private static function polje_marka( $r ): string {
		return Woo_Polja::vrijedeca(
			(int) $r->entity_id,
			Config::POLJE_MARKA,
			(string) ( $r->marka ?? '' )
		)['vrijednost'];
	}

	/**
	 * Jedinica mjere — samo za robu koja se mjeri.
	 *
	 * Spil karata prodaje se po komadu; njegova jedinica mjere nije podatak koji
	 * nedostaje nego podatak koji ne postoji. Odluka za to polje kaze "ako je
	 * primjenjivo", pa se izostavlja.
	 *
	 * @return string|null
	 */
	private static function polje_jedinica_mjere( $r ) {
		if ( ! self::mjerljiva( $r ) ) {
			return null;
		}

		return Kolicina::oznaka( (string) $r->jedinica_mjere );
	}

	/**
	 * Cijena za jedinicu mjere — samo za robu koja se mjeri.
	 *
	 * Kod komadne robe ta je brojka jednaka maloprodajnoj cijeni i ne kaze nista.
	 * Gore od toga: bila je izvor 49 zapisa koji sami sebi proturjece, jer se dvije
	 * jednake vrijednosti zaokruze razlicito.
	 *
	 * @return string|null
	 */
	private static function polje_cijena_za_jedinicu( $r ) {
		if ( ! self::mjerljiva( $r ) ) {
			return null;
		}

		$cijena = self::novac( $r->price );

		if ( '' === $cijena ) {
			return '';
		}

		$izracun = Cijena_Po_Jedinici::izracunaj(
			(float) $cijena,
			(float) $r->neto_kolicina,
			(string) $r->jedinica_mjere
		);

		if ( ! $izracun ) {
			return '';
		}

		return number_format( $izracun['iznos'], Config::DECIMALA_CIJENE_PO_JEDINICI, '.', '' );
	}

	private static function polje_maloprodajna_cijena( $r ): string {
		return self::novac( $r->price );
	}

	/**
	 * Je li maloprodajna cijena primijenjena tijekom posebnog oblika prodaje.
	 *
	 * Utvrduje se iz podataka: naplacuje se manje od redovne cijene. Redovna cijena
	 * sama vise nije polje cjenika, ali ostaje mjerilo za ovu tvrdnju.
	 */
	private static function polje_posebni_oblik( $r ): string {
		return ( Config::POP_NEMA === self::vrsta( $r ) ) ? Config::NE : Config::DA;
	}

	/**
	 * Naziv posebnog oblika prodaje.
	 *
	 * "Akcija" se utvrdi iz podataka. Rasprodaju i sezonsko snizenje zna samo
	 * trgovac — dodatak ih ne pogada, jer bi pogodena vrsta u propisanoj objavi
	 * bila tvrdnja bez pokrica.
	 */
	private static function polje_naziv_posebnog_oblika( $r ): string {
		$vrsta = self::vrsta( $r );

		if ( Config::POP_NEMA === $vrsta ) {
			return '';
		}

		return Config::NAZIV_POSEBNOG_OBLIKA[ $vrsta ] ?? $vrsta;
	}

	private static function polje_sidrena_cijena( $r ): string {
		return self::novac( $r->sidrena_cijena );
	}

	/**
	 * Barkod ide u cjenik samo ako je valjan kod PRODAJNE jedinice.
	 *
	 * Interni kod nije globalno jedinstven, a transportni opisuje kutiju. Oba su
	 * ispravna, ali objavljena uz cijenu jednog komada tvrde nesto netocno — pa
	 * izlaze prazna, kao i barkod koji ne prolazi kontrolnu znamenku.
	 */
	/**
	 * Barkod — s jednom granicom, istom za nasu i za WooCommerceovu vrijednost.
	 *
	 * SPORNO SE OBJAVLJUJE, NEMOGUCE NE
	 *
	 * Vrijednost ispravne duljine koja pada na kontrolnoj znamenki je sporna: moguce
	 * je da je broj pravi a jedna znamenka krivo prepisana. Presutjeti je znacilo bi
	 * da trgovac vidi svoj barkod u proizvodu, a u objavi ga nema — bez objasnjenja.
	 * Objavljuje se, a nalaz o njoj govori glasno.
	 *
	 * Vrijednost od dvije znamenke nije sporna nego NEMOGUCA. Objaviti je znaci
	 * tvrditi da je barkod tog artikla "40" — neistinita tvrdnja u propisanoj
	 * datoteci, ista kategorija kao izmisljen GTIN.
	 *
	 * `interni` i `transportni` ne izlaze iako su valjani: prvi nije globalno
	 * jedinstven, drugi opisuje kutiju a ne komad.
	 */
	private static function polje_barkod( $r ): string {
		$woo = Woo_Polja::barkod( (int) $r->entity_id );

		if ( '' !== $woo ) {
			return Gtin::smije_u_cjenik( Gtin::status( $woo ) ) ? $woo : '';
		}

		$nas = (string) $r->barkod;

		if ( '' === $nas ) {
			return '';
		}

		// Nas status je vec izracunat pri upisu; racuna se ponovno samo ako ga nema.
		$status = (string) ( $r->barkod_status ?? '' );

		if ( '' === $status ) {
			$status = Gtin::status( $nas );
		}

		return Gtin::smije_u_cjenik( $status ) ? $nas : '';
	}

	/**
	 * Dostupno ili nedostupno.
	 *
	 * Roba na cekanju racuna se kao DOSTUPNA — obrazlozenje je uz
	 * `Config::CEKANJE_JE_DOSTUPNO`. Nepoznato stanje zaliha je takoder dostupno:
	 * WooCommerce bez vodenja zaliha prodaje sve sto je objavljeno.
	 */
	private static function polje_dostupnost( $r ): string {
		$stanje = (string) ( $r->stock_status ?? '' );

		if ( 'outofstock' === $stanje ) {
			return Config::NEDOSTUPNO;
		}

		if ( 'onbackorder' === $stanje ) {
			return Config::CEKANJE_JE_DOSTUPNO ? Config::DOSTUPNO : Config::NEDOSTUPNO;
		}

		return Config::DOSTUPNO;
	}

	/**
	 * Redovna cijena — postoji, ali nije u shemi.
	 *
	 * NN 101/2026 je ne trazi. Metoda ostaje jer je dodavanje polja sada jedan redak
	 * u `Config::SHEMA_CJENIKA`, pa trgovac koji je zeli objaviti ne treba kod.
	 */
	private static function polje_redovna_cijena( $r ): string {
		return self::novac( $r->regular_price );
	}

	/* ============================ POMOCNO ============================ */

	/**
	 * Mjeri li se ovaj artikl, ili se prodaje po komadu.
	 *
	 * Mjerljiv je onaj koji ima neto kolicinu u jedinici koja nije komad. Bez
	 * kolicine ili s jedinicom "kom" cijena po jedinici mjere nije primjenjiva.
	 */
	private static function mjerljiva( $r ): bool {
		$jedinica = (string) ( $r->jedinica_mjere ?? '' );

		if ( '' === $jedinica || null === $r->neto_kolicina || '' === (string) $r->neto_kolicina ) {
			return false;
		}

		if ( (float) $r->neto_kolicina <= 0 ) {
			return false;
		}

		return Config::VRSTA_KOMAD !== ( Config::JEDINICE[ $jedinica ]['vrsta'] ?? Config::VRSTA_KOMAD );
	}

	/**
	 * Vrsta posebnog oblika prodaje, ili POP_NEMA.
	 *
	 * DVA KORAKA, I REDOSLIJED JE BITAN
	 *
	 * 1. Prodaje li se artikl ispod redovne cijene? To je CINJENICA iz cijena i
	 *    odlucuje hoce li polje uopce biti popunjeno. Zapis u povijesti tu nema
	 *    zadnju rijec — moze biti nepotpun, a obvezno polje ne smije izaci prazno
	 *    zato sto se nesto nije zabiljezilo.
	 *
	 * 2. Ako da, kako se taj oblik ZOVE? Tu ima zadnju rijec trgovac: rasprodaju i
	 *    sezonsko snizenje zna samo on, i njegov izbor stoji na tekucem razdoblju.
	 *    Bez izbora ostaje 'akcijska' — definicija prodaje po nizoj cijeni.
	 */
	private static function vrsta( $r ): string {
		$cijena  = self::novac( $r->price );
		$redovna = self::novac( $r->regular_price );

		if ( '' === $cijena || '' === $redovna ) {
			return Config::POP_NEMA;
		}

		if ( (float) $cijena >= (float) $redovna - Config::TOLERANCIJA_POVIJESTI ) {
			return Config::POP_NEMA;
		}

		$izabrana = (string) ( $r->vrsta_pop ?? '' );

		if ( in_array( $izabrana, Vrsta_Prodaje::RUCNE, true ) ) {
			return $izabrana;
		}

		return Config::POP_AKCIJSKA;
	}

	/**
	 * Iznos u novcu: NAJMANJE dvije decimale, nikad zaokruzen.
	 *
	 * Razlika prema `broj()` nije kozmeticka. Prva verzija je i za novac skidala
	 * nule s kraja, pa je isti artikl u istoj datoteci imao `maloprodajna_cijena`
	 * 9.2 i `cijena_za_jedinicu_mjere` 9.20 — dvije zapisane vrijednosti za istu
	 * cijenu. Izmjereno: 1725 takvih zapisa, i nijedan nije bio problem podataka
	 * nego ovog formatiranja.
	 *
	 * Nadopunjavanje do dvije decimale ne mijenja vrijednost. Zaokruzivanja NEMA:
	 * `3.849` ostaje `3.849` i prijavljuje se, jer generator ne smije tiho popraviti
	 * ono sto je politika nad katalogom.
	 */
	private static function novac( $vrijednost ): string {
		if ( null === $vrijednost || '' === $vrijednost ) {
			return '';
		}

		$s = self::broj( $vrijednost );

		if ( '' === $s ) {
			return '';
		}

		$tocka    = strpos( $s, '.' );
		$decimala = ( false === $tocka ) ? 0 : strlen( $s ) - $tocka - 1;

		if ( $decimala >= Config::CJENIK_MAX_DECIMALA ) {
			return $s;
		}

		return number_format( (float) $s, Config::CJENIK_MAX_DECIMALA, '.', '' );
	}

	/**
	 * Broj onakav kakav je u bazi, bez zaokruzivanja.
	 *
	 * Suvisne nule s kraja se skidaju jer ih je upisao DECIMAL(12,4), ne trgovac:
	 * "1.0000" i "1" su ista vrijednost, a prva u datoteci izgleda kao preciznost
	 * koje nema.
	 */
	private static function broj( $vrijednost ): string {
		if ( null === $vrijednost || '' === $vrijednost ) {
			return '';
		}

		$s = (string) $vrijednost;

		if ( false === strpos( $s, '.' ) ) {
			return $s;
		}

		$s = rtrim( rtrim( $s, '0' ), '.' );

		return '' === $s ? '0' : $s;
	}
}
