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

	/** @var \WC_Product|null|false objekt tekuceg zapisa; false = pokusano i nema ga */
	private static $proizvod = null;

	/**
	 * Objekt proizvoda za tekuci zapis, ili null.
	 *
	 * Cita se lijeno: zapis kojemu ne treba (a vecina polja ne treba) ne placa
	 * dohvat.
	 *
	 * @return \WC_Product|null
	 */
	private static function proizvod( $r ) {
		if ( null === self::$proizvod ) {
			self::$proizvod = function_exists( 'wc_get_product' )
				? ( wc_get_product( (int) $r->entity_id ) ?: false )
				: false;
		}

		return ( false === self::$proizvod ) ? null : self::$proizvod;
	}

	/**
	 * Sastavi zapis iz retka upita.
	 *
	 * @return array<string,string|null> kljucevi tocno iz Config::SHEMA_CJENIKA;
	 *                                   null znaci "izostavi", '' znaci "prazno"
	 */
	public static function iz( $r ): array {
		$zapis = array();

		// Objekt proizvoda treba trima poljima (naziv varijacije, porez). Dohvaca se
		// jednom po zapisu i zaboravlja — generator ide u komadima od 200, pa u
		// memoriji nikad ne stoji vise od jednog komada.
		self::$proizvod = null;

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

	/**
	 * Naziv artikla — kod varijacije s obiljezjima po kojima se prepoznaje.
	 *
	 * WooCommerce varijaciji NE mijenja naslov: majica S, M i L sve tri nose ime
	 * roditelja. U cjeniku to znaci vise redaka istog imena s razlicitim cijenama —
	 * citatelj ne moze znati koji je koji, a to je 55 % ove datoteke (izmjereno:
	 * 1894 od 3417 zapisa).
	 *
	 * Obiljezja se dopisuju samo kad ih ima. Varijacija bez ijednog razlikovnog
	 * obiljezja ostaje na imenu roditelja — dopisati praznu zagradu ne bi pomoglo.
	 */
	private static function polje_naziv( $r ): string {
		$naziv = (string) $r->naziv;

		if ( 'product_variation' !== (string) $r->post_type ) {
			return $naziv;
		}

		if ( ! function_exists( 'wc_get_formatted_variation' ) ) {
			return $naziv;
		}

		/*
		 * Obiljezja se citaju IZ META, ne iz objekta proizvoda.
		 *
		 * `wc_get_formatted_variation()` prima i polje: sam razrijesi slug termina u
		 * ime i kljuc atributa u natpis. Objekt proizvoda za to nije potreban, a
		 * njegov dohvat je jedini skup dio — s njim generiranje traje 15 s, bez njega
		 * sekundu. Meta cijelog komada je vec u predmemoriji (vidi Izvor::komad).
		 */
		$atributi = array();

		foreach ( (array) get_post_meta( (int) $r->entity_id ) as $kljuc => $vrijednosti ) {
			if ( 0 !== strpos( $kljuc, 'attribute_' ) ) {
				continue;
			}

			$atributi[ $kljuc ] = is_array( $vrijednosti ) ? (string) reset( $vrijednosti ) : (string) $vrijednosti;
		}

		if ( empty( $atributi ) ) {
			return $naziv;
		}

		$obiljezja = wc_get_formatted_variation( $atributi, true, true );

		return ( '' === trim( (string) $obiljezja ) ) ? $naziv : $naziv . ' — ' . $obiljezja;
	}

	/**
	 * Sifra artikla.
	 *
	 * Obvezan podatak. Artikl bez sifre i dalje mora biti jednoznacno oznacen u
	 * datoteci koju cita stroj — prazno polje znaci da se redak ne moze povezati ni
	 * s cim. Interni ID je jedina oznaka koja sigurno postoji.
	 *
	 * Oblik `ID-<roditelj>-<artikl>` da se vidi da nije trgovceva sifra nego nasa
	 * zamjena, i da se varijacije istog proizvoda drze zajedno.
	 */
	private static function polje_sifra( $r ): string {
		$sku = trim( (string) $r->sku );

		if ( '' !== $sku ) {
			return $sku;
		}

		$roditelj = (int) ( $r->post_parent ?? 0 );

		return 'ID-' . ( $roditelj > 0 ? $roditelj . '-' : '' ) . (int) $r->entity_id;
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

		$cijena = self::novac( self::s_porezom( $r, $r->price ) );

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
		return self::novac( self::s_porezom( $r, $r->price ) );
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
		return self::novac( self::s_porezom( $r, $r->sidrena_cijena ) );
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
	 * Iznos s porezom — onaj koji kupac stvarno plati.
	 *
	 * ZASTO SE NE SMIJE OBJAVITI `_price` KAKAV JEST
	 *
	 * WooCommerce cijene sprema onako kako ih je trgovac unio: sa PDV-om ili bez
	 * njega, ovisno o postavci. Na trgovini koja ih unosi BEZ poreza `_price` je
	 * neto iznos — a kupac na blagajni plati bruto. Objaviti neto znacilo bi u
	 * propisanoj datoteci navesti cijenu koja se ne naplacuje.
	 *
	 * Na trgovini koja cijene unosi s porezom pretvorba ne mijenja nista, pa se
	 * poziva bezuvjetno: postavka se moze promijeniti, a uvjet koji bi je citao
	 * morao bi se pamtiti na tri mjesta.
	 *
	 * Vrijedi za SVE iznose u datoteci, ne samo za maloprodajnu — sidrena cijena i
	 * cijena po jedinici mjere moraju biti na istoj osnovi, inace se usporeduju
	 * dvije razlicite stvari.
	 *
	 * NA NETO TRGOVINI OVO ZAOKRUZUJE, I TO JE ISPRAVNO
	 *
	 * WooCommerce bruto iznos racuna i zaokruzuje na dvije decimale — a upravo taj
	 * zaokruzen iznos kupac i plati. To nije tiho popravljanje nase brojke nego
	 * cijena s blagajne. Nalaz o cijenama s vise decimala i dalje cita katalog, ne
	 * objavljenu vrijednost, pa se problem u podacima time ne sakriva.
	 *
	 * @return string|null
	 */
	private static function s_porezom( $r, $vrijednost ) {
		if ( null === $vrijednost || '' === $vrijednost ) {
			return $vrijednost;
		}

		/*
		 * Trgovina koja cijene unosi S porezom vec ima bruto iznos u `_price` — nema
		 * se sto pretvarati. Preskace se prije dohvata objekta proizvoda, jer je taj
		 * dohvat jedini skup dio: s njim generiranje traje 15 s, bez njega 1 s.
		 *
		 * Ne gleda se kupceva porezna zona namjerno. Cjenik je izjava trgovine o
		 * njezinoj cijeni, ne racun za odredenog kupca.
		 */
		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			return $vrijednost;
		}

		$p = self::proizvod( $r );

		if ( ! $p || ! function_exists( 'wc_get_price_including_tax' ) ) {
			return $vrijednost;
		}

		return (string) wc_get_price_including_tax(
			$p,
			array(
				'qty'   => 1,
				'price' => $vrijednost,
			)
		);
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
