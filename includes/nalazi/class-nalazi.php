<?php
/**
 * Svi nalazi na jednom mjestu.
 *
 * Popis je namjerno u jednoj datoteci, a ne razbijen po klasama: svaki redak je
 * tvrdnja koju administrator procita kao svoj problem, i mora se moci procitati
 * cijeli popis odjednom. Isti razlog zbog kojeg su pravila "nije primjenjivo"
 * na jednom mjestu.
 *
 * TRI PRAVILA KOJA VRIJEDE ZA SVE
 *
 * 1. Nalaz koji ne postoji se ne spominje. Nema "0 problema ove vrste".
 * 2. Nalaz koji je uzrok POTISKUJE nalaz koji je posljedica — inace administrator
 *    vidi dva problema ondje gdje je jedan.
 * 3. Nijedna brojka u naslovu nije utipkana. Prag, rok i granica citaju se odande
 *    gdje zive, jer bi se inace razisli i nitko to ne bi primijetio.
 *
 * @package CJTR
 */

namespace CJTR\Nalazi;

use CJTR\Admin;
use CJTR\Cijene\Pregled;
use CJTR\Cijene\Promocija_Popis;
use CJTR\Cijene\Straza;
use CJTR\Cijene\Zaokruzivanje;
use CJTR\Cjenik\Arhiva;
use CJTR\Cjenik\Izvor;
use CJTR\Cjenik\Nedosljednosti;
use CJTR\Config;
use CJTR\Db;
use CJTR\Podaci\Potpunost;
use CJTR\Podaci\Sukobi_Podataka;
use CJTR\Postavke;
use CJTR\Poslovi\Raspored;
use CJTR\Poslovi\Registar;
use CJTR\Poslovi\Stanje;
use CJTR\Povijest\Duge_Akcije;
use CJTR\Povijest\Dubina;
use CJTR\Preuzimanje;

defined( 'ABSPATH' ) || exit;

final class Nalazi {

	/** Koliko sati posao smije stajati na "u tijeku" prije nego je to nalaz. */
	const SATI_ZAPELO = 6;

	/** @var Nalaz[]|null */
	private static $predmemorija = null;

	/**
	 * Svi nalazi, poredani po vaznosti, bez potisnutih.
	 *
	 * @return Nalaz[]
	 */
	public static function svi(): array {
		if ( null !== self::$predmemorija ) {
			return self::$predmemorija;
		}

		$nadeni = array();

		foreach ( self::izvori() as $metoda ) {
			$n = self::$metoda();
			if ( $n instanceof Nalaz ) {
				$nadeni[ $n->kljuc ] = $n;
			}
		}

		// Uzrok potiskuje posljedicu. Radi se NAKON prikupljanja, jer se potiskuje
		// samo ono sto je i naslo — nalaz koji ionako nije nastao nema sto potisnuti.
		foreach ( $nadeni as $n ) {
			foreach ( $n->potiskuje as $kljuc ) {
				unset( $nadeni[ $kljuc ] );
			}
		}

		uasort(
			$nadeni,
			static function ( Nalaz $a, Nalaz $b ) {
				return $a->tezina() <=> $b->tezina();
			}
		);

		self::$predmemorija = $nadeni;
		return $nadeni;
	}

	/** Ima li ijedna zapreka. */
	public static function ima_zapreku(): bool {
		foreach ( self::svi() as $n ) {
			if ( Nalaz::ZAPREKA === $n->vaznost ) {
				return true;
			}
		}
		return false;
	}

	public static function broj(): int {
		return count( self::svi() );
	}

	public static function zaboravi(): void {
		self::$predmemorija = null;
	}

	/**
	 * Redoslijed kojim se nalazi traze.
	 *
	 * Nije redoslijed prikaza — prikaz slaze po vaznosti. Ovdje je redoslijed
	 * vazan samo zato da uzroci budu izmjereni prije posljedica.
	 *
	 * @return string[]
	 */
	private static function izvori(): array {
		return array(
			'dinamicke_cijene',
			'cjenik_nije_objavljen',
			'cron_nije_postavljen',
			'mapa_nije_zapisiva',
			'cjenik_nedostupan',
			'obrada_je_zapela',
			'dodatna_bez_izvora',
			'pocetna_cijena_nepoznata',
			'dodatna_ceka_odluku',
			'objavljen_bez_cijene',
			'previse_decimala',
			'podaci_nepotpuni',
			'barkod_ne_moze_biti',
			'barkod_sporan',
			'isti_barkod',
			'izvori_se_ne_slazu',
			'dodatna_je_nula',
			'duge_akcije',
			'najniza_prozor_nije_pun',
		);
	}

	/* ===================================================== ZAPREKA ===== */

	/**
	 * 1. Nesto drugo mijenja cijene u hodu.
	 *
	 * Potiskuje sve sto ovisi o tocnosti cijena: dok ovo traje, objavljena brojka
	 * i naplacena brojka mogu biti dvije razlicite stvari, pa nema smisla prijavljivati
	 * da cjenik nije objavljen — nije objavljen upravo zbog ovoga.
	 */
	private static function dinamicke_cijene(): ?Nalaz {
		$nalaz = Straza::provjeri( true );

		if ( $nalaz->prolazi ) {
			return null;
		}

		return ( new Nalaz( 'dinamicke_cijene' ) )
			->vaznost( Nalaz::ZAPREKA )
			->naslov( __( 'Drugi dodatak mijenja cijene u hodu', Config::TEXT_DOMAIN ) )
			->objasnjenje( __( 'Cijena zapisana u proizvodu i cijena koju kupac stvarno placa mogu biti dva razlicita broja. Dok je tako, objavljeni cjenik ne bi govorio istinu, pa se ne objavljuje.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Pogledajte koji dodatak to radi i iskljucite pravilo koje mijenja cijenu, ili nam javite o kojem je dodatku rijec.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_NAPREDNO ), __( 'Pogledaj sto dira cijene', Config::TEXT_DOMAIN ) )
			->potiskuje( 'cjenik_nije_objavljen', 'previse_decimala' );
	}

	/**
	 * 2. Cjenik nije objavljen danas.
	 *
	 * RAZLIKUJE DVIJE SITUACIJE
	 *
	 * "Nije objavljen" u 5 ujutro, kad je objava zakazana za 6, nije problem nego
	 * cekanje. Isto u 10 jest. Bez te razlike administrator svako jutro vidi crveni
	 * redak i nauci da crveno ne znaci nista.
	 */
	private static function cjenik_nije_objavljen(): ?Nalaz {
		if ( Arhiva::od_danas( Config::CJENIK_OBLICI[0] ) ) {
			return null;
		}

		$zadnja = Arhiva::zadnja_objava();

		/*
		 * Prije zakazanog vremena to nije nalaz nego cekanje, i ekran ga pokazuje kao
		 * statusni redak. Inace administrator svako jutro vidi crveni redak i nauci
		 * da crveno ne znaci nista.
		 *
		 * IZNIMKA: cjenik koji nikad nije objavljen nije "jos nije red na njega".
		 * Trgovac koji je dodatak postavio u cetiri ujutro inace ne bi imao nijedan
		 * put da ga objavi — a upravo je to jedina stvar koju je dosao napraviti.
		 */
		if ( $zadnja > 0 && time() < Postavke::ocekivano_do() ) {
			return null;
		}

		$objasnjenje = ( 0 === $zadnja )
			? __( 'Cjenik jos nije objavljen nijednom. Dok ga nema, na javnoj adresi nema sto procitati.', Config::TEXT_DOMAIN )
			: sprintf(
				/* translators: %s = datum i vrijeme */
				__( 'Zadnji put je objavljen %s. Propis trazi da cjenik bude tekuci, pa objava od jucer ne vrijedi za danas.', Config::TEXT_DOMAIN ),
				wp_date( 'j.n.Y. H:i', $zadnja )
			);

		return ( new Nalaz( 'cjenik_nije_objavljen' ) )
			->vaznost( Nalaz::ZAPREKA )
			->naslov(
				( 0 === $zadnja )
					? __( 'Cjenik jos nije objavljen', Config::TEXT_DOMAIN )
					: sprintf(
						/* translators: %s = vrijeme, npr. 06:00 */
						__( 'Cjenik nije objavljen danas — rok je bio %s', Config::TEXT_DOMAIN ),
						wp_date( 'H:i', Postavke::ocekivano_do() )
					)
			)
			->objasnjenje( $objasnjenje )
			->rjesava( 'generiraj_cjenik', __( 'Objavi sada', Config::TEXT_DOMAIN ) );
	}

	/**
	 * 3. Automatsko pokretanje nije postavljeno.
	 *
	 * Gotov redak stoji U NALAZU, ne u dokumentaciji. Administrator koji mora otvoriti
	 * dokumentaciju da bi nastavio vec je izgubljen — a ovo je jedini korak koji se
	 * ne moze odraditi iz WordPressa.
	 */
	private static function cron_nije_postavljen(): ?Nalaz {
		if ( self::cron_radi() ) {
			return null;
		}

		$redak = self::cpanel_redak();

		return ( new Nalaz( 'cron_nije_postavljen' ) )
			->vaznost( Nalaz::ZAPREKA )
			->naslov( __( 'Automatsko pokretanje nije postavljeno', Config::TEXT_DOMAIN ) )
			->objasnjenje( __( 'Bez njega cjenik izlazi samo kad netko posjeti trgovinu, a to se dogada u nepredvidivo doba. Posjeti ga guraju kao rezervu, ali dan bez ijednog posjeta ostaje bez cjenika — a posjet koji posluzi predmemoriju do nas uopce ne dode. Rok se tako ne moze jamciti.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'U cPanelu otvorite Cron Jobs, kliknite Add New Cron Job, zalijepite redak ispod i spremite. Odmah ispod naslova cPanel pise trenutno vrijeme posluzitelja — ako se razlikuje od vremena u ovom retku, javite nam.', Config::TEXT_DOMAIN ) )
			->doslovno( __( 'Redak za cPanel', Config::TEXT_DOMAIN ), $redak );
	}

	/** 4. Mapa za datoteke nije zapisiva. */
	private static function mapa_nije_zapisiva(): ?Nalaz {
		$r = ( new \CJTR\Diagnostika\Provjere\Zapisivost() )->izvrsi();

		if ( \CJTR\Diagnostika\Rezultat::LOSE !== $r->status ) {
			return null;
		}

		return ( new Nalaz( 'mapa_nije_zapisiva' ) )
			->vaznost( Nalaz::ZAPREKA )
			->naslov( __( 'Ne mozemo zapisati datoteku cjenika', Config::TEXT_DOMAIN ) )
			->objasnjenje( __( 'Mapa u koju cjenik ide nije otvorena za pisanje, pa datoteka ne moze nastati.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Zatrazite od hostinga da mapa wp-content/uploads bude zapisiva za WordPress. U cPanelu je to obicno dozvola 755.', Config::TEXT_DOMAIN ) )
			->potiskuje( 'cjenik_nije_objavljen', 'cjenik_nedostupan' );
	}

	/** 5. Objavljeni cjenik nije dohvatljiv izvana. */
	private static function cjenik_nedostupan(): ?Nalaz {
		if ( 0 === Arhiva::zadnja_objava() ) {
			return null;
		}

		$r = ( new \CJTR\Diagnostika\Provjere\Dohvat() )->izvrsi();

		if ( \CJTR\Diagnostika\Rezultat::LOSE !== $r->status ) {
			return null;
		}

		return ( new Nalaz( 'cjenik_nedostupan' ) )
			->vaznost( Nalaz::ZAPREKA )
			->naslov( __( 'Cjenik je napravljen, ali se izvana ne moze otvoriti', Config::TEXT_DOMAIN ) )
			->objasnjenje( __( 'Datoteka postoji na posluzitelju, ali je posjetitelj ne moze preuzeti. Objava koja se ne moze procitati nije objava.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Najcesce je uzrok zastita na posluzitelju ili pravilo koje blokira pristup mapi. Javite hostingu adresu cjenika i pitajte zasto vraca gresku.', Config::TEXT_DOMAIN ) )
			->ekran( Arhiva::stabilni_url( Config::CJENIK_OBLICI[0] ), __( 'Otvori cjenik', Config::TEXT_DOMAIN ) );
	}

	/** 6. Obrada je zapela. */
	private static function obrada_je_zapela(): ?Nalaz {
		$zapeli = array();

		foreach ( Registar::svi() as $kljuc => $posao ) {
			$s = Stanje::ucitaj( $kljuc );

			if ( ! $s->radi() || '' === (string) $s->azurirano ) {
				continue;
			}

			$zadnje = strtotime( $s->azurirano . ' UTC' );

			if ( $zadnje && ( time() - $zadnje ) > self::SATI_ZAPELO * HOUR_IN_SECONDS ) {
				$zapeli[] = $posao->naziv();
			}
		}

		if ( empty( $zapeli ) ) {
			return null;
		}

		return ( new Nalaz( 'obrada_je_zapela' ) )
			->vaznost( Nalaz::ZAPREKA )
			->naslov(
				sprintf(
					/* translators: %d = broj sati */
					__( 'Obrada stoji na istom mjestu vise od %d sati', Config::TEXT_DOMAIN ),
					self::SATI_ZAPELO
				)
			)
			->objasnjenje(
				sprintf(
					/* translators: %s = nazivi poslova */
					__( 'Zapelo je: %s. Dok stoji, podaci koje taj posao priprema ostaju nepotpuni.', Config::TEXT_DOMAIN ),
					implode( ', ', $zapeli )
				)
			)
			->ekran( Admin::url( Admin::STRANICA_NAPREDNO ), __( 'Pogledaj obradu', Config::TEXT_DOMAIN ) );
	}

	/* ======================================================= VAZNO ===== */

	/** 7. Dodatnu cijenu nemamo odakle utvrditi. */
	private static function dodatna_bez_izvora(): ?Nalaz {
		$stanje = Pregled::stanje();
		$n      = (int) $stanje['ceka_unos'];

		if ( $n < 1 ) {
			return null;
		}

		return ( new Nalaz( 'dodatna_bez_izvora' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: 1: referentni datum, 2: broj artikala */
					__( 'Ne znamo koliko je cijena iznosila %1$s — artikala i varijanti: %2$s', Config::TEXT_DOMAIN ),
					wp_date( 'j.n.Y.', Config::t_ref( Postavke::ref_datum() ) ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje( __( 'Za te artikle ne postoji nijedan zapis o cijeni na referentni datum. U cjeniku im sidrena cijena ostaje prazna, a uz cijenu se ne prikazuje.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Cijenu mozete uvesti iz tablice koju vam izvozi vas program, upisati je rucno po artiklu, ili potvrditi da su danasnje cijene vrijedile i na taj datum.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_ARTIKLI ), __( 'Uvezi ili upisi', Config::TEXT_DOMAIN ) )
			->popis( Preuzimanje::url( Config::IZVOR_RUCNI_UNOS ), __( 'Preuzmi popis (CSV)', Config::TEXT_DOMAIN ) );
	}

	/**
	 * 7b. Artikl uveden nakon referentnog datuma, a pocetnu cijenu ne znamo.
	 *
	 * Odvojen od nalaza 7 jer je i lijek drugi. Ondje se cijena trazi u proslosti i
	 * moze se uvesti iz ERP-a. Ovdje je trgovac sam formirao i datum i cijenu — zna
	 * ih, samo ih mi nismo imali tko zabiljeziti.
	 *
	 * Skupina je uska: artikli uvedeni izmedu referentnog datuma i instalacije
	 * dodatka. Za sve uvedene poslije toga prva cijena se biljezi sama.
	 */
	private static function pocetna_cijena_nepoznata(): ?Nalaz {
		$n = (int) ( Pregled::stanje()['ceka_pocetnu'] ?? 0 );

		if ( $n < 1 ) {
			return null;
		}

		return ( new Nalaz( 'pocetna_cijena_nepoznata' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj artikala */
					__( 'Ne znamo po kojoj su cijeni uvedeni — artikala i varijanti: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje( __( 'Ti su artikli u ponudu usli nakon referentnog datuma, pa im je sidrena cijena ona po kojoj su prvi put ponudeni. Usli su i prije nego sto je ovaj dodatak poceo biljeziti cijene, pa tu prvu cijenu nemamo — datum znamo, iznos ne. U cjeniku im sidrena cijena ostaje prazna.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Tu cijenu ste formirali vi i nitko je ne zna bolje: upisite je po artiklu, zajedno s datumom kad je artikl uveden. Artikli uvedeni od sada nadalje ne traze nista — prva cijena im se zabiljezi sama.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_PREGLED ), __( 'Pogledaj i upisi', Config::TEXT_DOMAIN ) )
			->popis( Preuzimanje::url( Config::IZVOR_NAKON_REF_DATUMA ), __( 'Preuzmi popis (CSV)', Config::TEXT_DOMAIN ) );
	}

	/**
	 * 8. Artikli koji cekaju odluku vlasnika.
	 *
	 * BEZ GUMBA. Odluku racuna li se kao dodatna cijena redovna ili akcijska donosi
	 * vlasnik trgovine — softver koji to odluci umjesto njega donio je poslovnu
	 * odluku u njegovo ime.
	 */
	private static function dodatna_ceka_odluku(): ?Nalaz {
		/*
		 * Broji se TOCNO ono sto kartica moze rijesiti.
		 *
		 * `stanje()['ceka_odluku']` zbraja i artikle s oscilirajucom cijenom, koji se
		 * rjesavaju drugim putem i u toj kartici ne stoje. Dok ih ima nula, razlika
		 * se ne vidi — a cim ih bude, nalaz bi tvrdio jedan broj, kartica pokazala
		 * drugi, i citatelj bi opet morao pogadati koji je tocan.
		 *
		 * To je upravo prituzba koja je i dovela do ove kartice. Nema smisla popraviti
		 * odrediste, a ostaviti brojku da se moze razici.
		 */
		$n = Pregled::broj_ceka_odluku();

		if ( $n < 1 ) {
			return null;
		}

		return ( new Nalaz( 'dodatna_ceka_odluku' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj artikala */
					__( 'Cekaju vasu odluku — bili su na akciji na referentni datum: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje( __( 'Kod njih postoje dvije moguce sidrene cijene: redovna koja je tada vrijedila i akcijska koja se tada naplacivala. Koja je od njih prava, odluka je vlasnika trgovine i dodatak je ne donosi umjesto vas.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Na ekranu Artikli, u kartici "Koja je cijena vrijedila na referentni datum", oznacite artikle i recite koja od dvije vrijednosti vrijedi. Odluka je za vecinu kataloga ista, pa se upisuje skupno. Do tada tim artiklima sidrena cijena ostaje prazna.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_ARTIKLI ), __( 'Odluci koja cijena vrijedi', Config::TEXT_DOMAIN ) )
			->popis( Preuzimanje::url( Config::IZVOR_TRAZI_ODLUKU ), __( 'Preuzmi popis (CSV)', Config::TEXT_DOMAIN ) );
	}

	/**
	 * 9. Objavljen artikl bez cijene.
	 *
	 * DVA PROBLEMA U JEDNOM
	 *
	 * Artikl bez cijene ne moze biti u cjeniku — maloprodajna cijena je obvezan
	 * podatak. Ali izostavljanje se ne smije dogoditi tiho: kupac taj artikl vidi,
	 * moze ga otvoriti, a u propisanoj objavi ga nema.
	 *
	 * Uz to, objavljen artikl bez istaknute cijene ima i vlastiti problem, neovisan
	 * o cjeniku.
	 */
	private static function objavljen_bez_cijene(): ?Nalaz {
		$bez = Izvor::bez_cijene();
		$n   = count( $bez );

		if ( $n < 1 ) {
			return null;
		}

		return ( new Nalaz( 'objavljen_bez_cijene' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj artikala */
					__( 'Vidljivi su u trgovini, a nemaju cijenu — artikala: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje( __( 'U cjenik ne mogu uci jer je maloprodajna cijena obvezan podatak — dakle tiho ispadaju iz propisane objave. Uz to, artikl koji je kupcu vidljiv a nema istaknutu cijenu i sam je problem, neovisno o cjeniku.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Upisite im cijenu ili ih sklonite iz prodaje. Dodatak cijenu ne smije izmisliti.', Config::TEXT_DOMAIN ) )
			->popis( Preuzimanje::url( Preuzimanje::POPIS_BEZ_CIJENE ), __( 'Preuzmi popis (CSV)', Config::TEXT_DOMAIN ) );
	}

	/**
	 * 10. Cijene s vise decimala nego sto se objavljuje.
	 *
	 * RANIJA POSLJEDICA JE OTPALA
	 *
	 * Dok se za komadnu robu objavljivala i cijena po jedinici mjere, ovih 87 cijena
	 * proizvodilo je 49 zapisa koji sami sebi proturjece — dvije razlicite brojke za
	 * istu cijenu. NN 101/2026 za komadnu robu to polje vise ne trazi, pa je
	 * proturjecje nestalo iz datoteke.
	 *
	 * Nalaz ostaje jer problem nije nestao nego se preselio: cijena na pet decimala
	 * je i dalje cijena koju blagajna ne moze naplatiti. Samo vise nije i tvrdnja
	 * koja se sama sebi protivi u propisanoj objavi.
	 */
	private static function previse_decimala(): ?Nalaz {
		$decimala = count( Izvor::previse_decimala() );

		if ( $decimala < 1 ) {
			return null;
		}

		$broj = Nedosljednosti::prebroji();
		$dvije = (int) ( $broj['dvije_cijene'] ?? 0 );

		$naslov = ( $dvije > 0 )
			? sprintf(
				/* translators: 1: broj decimala, 2: broj cijena, 3: broj artikala */
				__( 'Cijena s vise od %1$d decimale: %2$s — zbog njih cjenik za %3$s artikala objavljuje dvije razlicite brojke', Config::TEXT_DOMAIN ),
				Config::CJENIK_MAX_DECIMALA,
				number_format_i18n( $decimala ),
				number_format_i18n( $dvije )
			)
			: sprintf(
				/* translators: 1: broj cijena, 2: broj decimala */
				__( 'Objavljujemo %1$s cijena s vise od %2$d decimale', Config::TEXT_DOMAIN ),
				number_format_i18n( $decimala ),
				Config::CJENIK_MAX_DECIMALA
			);

		return ( new Nalaz( 'previse_decimala' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov( $naslov )
			->objasnjenje(
				( $dvije > 0 )
					? __( 'Rijec je o ostatku preracuna iz kuna. Maloprodajna cijena i cijena po jedinici mjere zaokruze se razlicito, pa isti artikl u objavljenoj datoteci nosi dvije brojke za istu stvar.', Config::TEXT_DOMAIN )
					: __( 'Rijec je o ostatku preracuna iz kuna. Objavljena cijena na pet decimala nije cijena koju blagajna moze naplatiti, pa objava i racun ne kazu isto.', Config::TEXT_DOMAIN )
			)
			->postupak( __( 'Cijene treba svesti na dvije decimale. Dodatak to ne radi sam jer bi time mijenjao cijenu koju kupac placa — a to je vasa odluka, ne njegova.', Config::TEXT_DOMAIN ) )
			->rjesava( 'zaokruzi_cijene', __( 'Svedi na dvije decimale', Config::TEXT_DOMAIN ) )
			/*
			 * Poveznica na probni prolaz stoji UVIJEK, ne samo kad potvrde nema.
			 *
			 * Posao mijenja cijene koje kupac placa, pa prije njega ide pogled na to
			 * sto bi se tocno promijenilo. Dok potvrda nije dana, gumba nema i ovo je
			 * jedini put dalje; kad je dana, ovo je put da se predomislite.
			 */
			->ekran( Admin::url( Admin::STRANICA_PREGLED ), __( 'Pogledaj sto bi se promijenilo', Config::TEXT_DOMAIN ) )
			->popis( Preuzimanje::url( Preuzimanje::POPIS_ZAOKRUZIVANJE ), __( 'Preuzmi popis (CSV)', Config::TEXT_DOMAIN ) );
	}

	/** 11. Podaci za cjenik nepotpuni. */
	private static function podaci_nepotpuni(): ?Nalaz {
		$s = Potpunost::sazetak();

		if ( $s['ukupno'] < 1 || $s['spremno'] >= $s['ukupno'] ) {
			return null;
		}

		$nedostaje = array();
		foreach ( Potpunost::po_poljima() as $polje ) {
			if ( $polje['nedostaje'] > 0 ) {
				$nedostaje[] = sprintf( '%s (%s)', mb_strtolower( $polje['naziv'] ), number_format_i18n( $polje['nedostaje'] ) );
			}
		}

		return ( new Nalaz( 'podaci_nepotpuni' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: 1: spremno, 2: ukupno */
					__( 'Za cjenik je spremno %1$s od %2$s artikala i varijanti', Config::TEXT_DOMAIN ),
					number_format_i18n( $s['spremno'] ),
					number_format_i18n( $s['ukupno'] )
				)
			)
			->objasnjenje(
				sprintf(
					/* translators: %s = popis polja */
					__( 'Ostali ce u cjenik uci s praznim poljima. Nedostaje: %s.', Config::TEXT_DOMAIN ),
					implode( ', ', $nedostaje )
				)
			)
			->postupak( __( 'Za vecinu artikala najbrze ide uvozom tablice koju vam izvozi vas program. Pojedinacno se unosi na samom proizvodu, u kartici "Podaci za cjenik" — ondje gdje su i cijena i zaliha. Ono sto artikl objektivno nema — barkod za vlastitu proizvodnju, na primjer — oznacite kao neprimjenjivo i prestaje se brojati kao manjak.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_ARTIKLI ), __( 'Otvori artikle', Config::TEXT_DOMAIN ) );
	}

	/**
	 * Barkod koji NE MOZE biti barkod.
	 *
	 * Ovo je jedini nalaz koji javlja da nesto NE objavljujemo. Tiha objava bila bi
	 * neistinita tvrdnja u propisanoj datoteci; tiho izostavljanje bilo bi podatak
	 * koji trgovac vidi u proizvodu a u objavi ga nema, bez ijednog objasnjenja.
	 * Nalaz nosi vise informacije nego oboje.
	 */
	private static function barkod_ne_moze_biti(): ?Nalaz {
		$nadeno = Potpunost::barkodi_s_problemom()['nemoguci'];
		$n      = count( $nadeno );

		if ( $n < 1 ) {
			return null;
		}

		$primjeri = array();
		foreach ( array_slice( $nadeno, 0, 5 ) as $r ) {
			$primjeri[] = sprintf( '"%s" (%s)', $r->barkod, '' !== $r->sku ? $r->sku : '#' . $r->entity_id );
		}

		return ( new Nalaz( 'barkod_ne_moze_biti' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj artikala */
					__( 'Upisano kao barkod, a ne moze biti barkod — artikala: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje(
				sprintf(
					/* translators: %s = primjeri vrijednosti */
					__( 'Nijedan barkod nema dvije ili tri znamenke. Nadeno: %s. Te vrijednosti NE objavljujemo — objava bi tvrdila da je to barkod tog artikla, a nije. Polje u cjeniku ostaje prazno.', Config::TEXT_DOMAIN ),
					implode( ', ', $primjeri )
				)
			)
			->postupak( __( 'Ispravite ih ili obrisite. Barkod se prepisuje s pakiranja i nikad ne izmislja — izmisljen broj gotovo je sigurno dodijeljen drugoj firmi. Ako artikl barkod nema, ostavite polje prazno i oznacite ga kao "ne odnosi se".', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_ARTIKLI ), __( 'Otvori artikle', Config::TEXT_DOMAIN ) );
	}

	/**
	 * Barkod ispravne duljine koji pada na kontrolnoj znamenki.
	 *
	 * Ovaj se OBJAVLJUJE. Stvarno je moguce da je broj pravi a jedna znamenka krivo
	 * prepisana, pa bi izostavljanje izgubilo podatak koji je vjerojatno dobar.
	 */
	private static function barkod_sporan(): ?Nalaz {
		$nadeno = Potpunost::barkodi_s_problemom()['sporni'];
		$n      = count( $nadeno );

		if ( $n < 1 ) {
			return null;
		}

		$primjeri = array();
		foreach ( array_slice( $nadeno, 0, 5 ) as $r ) {
			$primjeri[] = sprintf( '"%s" (%s)', $r->barkod, '' !== $r->sku ? $r->sku : '#' . $r->entity_id );
		}

		return ( new Nalaz( 'barkod_sporan' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj artikala */
					__( 'Barkod ne prolazi provjeru, ali ga objavljujemo — artikala: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje(
				sprintf(
					/* translators: %s = primjeri vrijednosti */
					__( 'Duljina je ispravna, ali zadnja znamenka ne odgovara ostatku broja. Nadeno: %s. Moguce je da je broj pravi a jedna znamenka krivo prepisana, pa ga objavljujemo kakav jest — ali vrijedi ga provjeriti s pakiranjem.', Config::TEXT_DOMAIN ),
					implode( ', ', $primjeri )
				)
			)
			->postupak( __( 'Usporedite s brojem na pakiranju. Najcesca je greska jedna zamijenjena znamenka.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_ARTIKLI ), __( 'Otvori artikle', Config::TEXT_DOMAIN ) );
	}

	/** 12. Isti barkod na vise artikala. */
	private static function isti_barkod(): ?Nalaz {
		$d = Potpunost::duplikati_barkoda();
		$n = count( $d );

		if ( $n < 1 ) {
			return null;
		}

		return ( new Nalaz( 'isti_barkod' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj barkodova */
					__( 'Barkodova koji stoje na vise od jednog artikla: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje( __( 'Barkod je jedinstven po proizvodu. Isti na dva artikla gotovo je uvijek kopiran redak iz tablice dobavljaca.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Provjerite koji je od njih tocan i ispravite ostale. Dodatak ne moze znati koji je pravi.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_ARTIKLI ), __( 'Otvori artikle', Config::TEXT_DOMAIN ) );
	}

	/** 13. Dva izvora kazu razlicito. */
	private static function izvori_se_ne_slazu(): ?Nalaz {
		$n = Sukobi_Podataka::broj();

		if ( $n < 1 ) {
			return null;
		}

		return ( new Nalaz( 'izvori_se_ne_slazu' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj artikala */
					__( 'Dva izvora kazu razlicito — artikala: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $n )
				)
			)
			->objasnjenje( __( 'Isti podatak nadeni smo na dva mjesta s razlicitim vrijednostima — na primjer barkod upisan u proizvod i barkod iz uvezene tablice. Upisali smo onaj iz jaceg izvora, ali razliku ne skrivamo.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Pogledajte razlike i potvrdite koja je vrijednost tocna.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_ARTIKLI ), __( 'Pogledaj razlike', Config::TEXT_DOMAIN ) );
	}

	/**
	 * 14. Dodatna cijena 0,00 € — mreza ispod uvoza.
	 *
	 * Nula ne bi smjela postojati: upis ide kroz jednu funkciju koja praznu vrijednost
	 * pretvara u pravi NULL, a uvoz redak s nulom odbija jos na ulazu. Pojavi li se
	 * ipak, to je KVAR DODATKA, ne trgovcev problem — i tako i pise.
	 */
	private static function dodatna_je_nula(): ?Nalaz {
		$nule = Db::provjeri_nule();

		if ( empty( $nule ) ) {
			return null;
		}

		$ukupno = 0;
		foreach ( $nule as $n ) {
			$ukupno += (int) $n['koliko'];
		}

		return ( new Nalaz( 'dodatna_je_nula' ) )
			->vaznost( Nalaz::VAZNO )
			->naslov(
				sprintf(
					/* translators: %s = broj redaka */
					__( 'Zapisano kao nula umjesto kao prazno — podataka: %s', Config::TEXT_DOMAIN ),
					number_format_i18n( $ukupno )
				)
			)
			->objasnjenje( __( 'Nula znaci "cijena iznosi nula", a prazno znaci "ne znamo". To su razlicite tvrdnje i ova prva ne bi smjela nastati — upis je stiti na dva mjesta.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Ovo nije pogreska u vasim podacima nego kvar u dodatku. Javite nam, molimo — sami je ne mozete ispraviti i ne biste trebali.', Config::TEXT_DOMAIN ) );
	}

	/* ====================================================== SAVJET ===== */

	/**
	 * 15. Akcije koje traju predugo.
	 *
	 * Prag se CITA iz Configa. Utipkan u naslov, razisao bi se s onim po kojem se
	 * mjeri, i nitko to ne bi primijetio jer bi obje brojke izgledale uvjerljivo.
	 */
	private static function duge_akcije(): ?Nalaz {
		$n = Duge_Akcije::broj();

		if ( $n < 1 ) {
			return null;
		}

		$nalaz = ( new Nalaz( 'duge_akcije' ) )
			->vaznost( Nalaz::SAVJET )
			->naslov(
				sprintf(
					/* translators: 1: broj dana, 2: broj artikala */
					__( 'Na akciji duze od %1$d dana — artikala: %2$s', Config::TEXT_DOMAIN ),
					Config::PRAG_SUMNJIVO_DUGE_AKCIJE,
					number_format_i18n( $n )
				)
			)
			->objasnjenje( __( 'Kod njih precrtana cijena pokazuje popust koji se zapravo nije dogodio — akcijska cijena traje toliko dugo da je ona zapravo redovna cijena tog artikla.', Config::TEXT_DOMAIN ) )
			->postupak( __( 'Ako je akcijska cijena postala vasa redovna cijena, tako je i vodite: cijena koju kupac placa ostaje ista, nestaje samo precrtana brojka iznad nje.', Config::TEXT_DOMAIN ) )
			->popis( Preuzimanje::url( Preuzimanje::POPIS_DUGE_AKCIJE ), __( 'Preuzmi popis (CSV)', Config::TEXT_DOMAIN ) );

		/*
		 * Gumb samo ako postoji posao koji to izvodi — a `moze_rijesiti()` ga povuce i
		 * kad posao ima zapreku. Poveznica na pregled stoji uz njega u oba slucaja:
		 * dok potvrde nema, ona je jedini put dalje.
		 */
		if ( Promocija_Popis::broj() > 0 ) {
			$nalaz->rjesava( 'promocija', __( 'Pretvori u redovnu cijenu', Config::TEXT_DOMAIN ) )
				->ekran( Admin::url( Admin::STRANICA_PROMOCIJA ), __( 'Pogledaj cijene prije i poslije', Config::TEXT_DOMAIN ) );
		}

		return $nalaz;
	}

	/**
	 * 16. Prozor za najnizu cijenu jos nije pun.
	 *
	 * STO OVAJ NALAZ JEST OD 1.2.0
	 *
	 * Ranije je javljao cekanje: prikaz se pali tek kad evidencija pokrije punih
	 * trideset dana. Taj je uvjet maknut — najniza u 30 dana nije tvrdnja da je
	 * cijena stara trideset dana nego najmanja koja je u prozoru primijenjena.
	 *
	 * Sada nalaz nosi OGRADU, ne cekanje: brojka se prikazuje, ali racuna se iz
	 * onoga sto imamo. Bio li artikl prije nase prve biljeske jeftiniji, u njoj
	 * nije. To trgovac mora znati, jer je tvrdnja njegova.
	 *
	 * ZASTO POSTOJI I KAD JE EVIDENCIJA PRAZNA
	 *
	 * Prazna evidencija je stanje svake nove instalacije, i tada se uz cijenu ne
	 * pojavi nista. Bez ovog nalaza to je tiho izostajanje — tocno ona vrsta koju
	 * ovaj dodatak inace lovi kod drugih.
	 */
	private static function najniza_prozor_nije_pun(): ?Nalaz {
		if ( ! Postavke::najniza_30() || Dubina::dovoljna() ) {
			return null;
		}

		$nalaz = ( new Nalaz( 'najniza_prozor_nije_pun' ) )
			->vaznost( Nalaz::SAVJET )
			->postupak( __( 'Imate li stariju povijest cijena iz drugog dodatka, mozemo je preuzeti i prozor se popunjava odmah. Ako je nemate, popunjava se sam, iz dana u dan.', Config::TEXT_DOMAIN ) )
			->ekran( Admin::url( Admin::STRANICA_NAPREDNO ), __( 'Preuzmi stariju povijest', Config::TEXT_DOMAIN ) );

		if ( ! Dubina::ima_zapisa() ) {
			// Nijedan zapis: nema se sto prikazati ni za jedan artikl. Prva
			// rekonsilijacija (dnevno, 05:00) zabiljezi zatecene cijene.
			return $nalaz
				->naslov(
					sprintf(
						/* translators: %d = broj dana */
						__( 'Najniza cijena u %d dana ukljucena je, ali evidencija je jos prazna', Config::TEXT_DOMAIN ),
						Dubina::DANA
					)
				)
				->objasnjenje( __( 'Evidencija o cijenama pocinje se voditi od instalacije dodatka i jos nema nijedan zapis, pa se uz cijenu nema sto prikazati. Prve zatecene cijene biljeze se pri sljedecoj dnevnoj provjeri, a nakon toga svaka promjena cim se dogodi.', Config::TEXT_DOMAIN ) );
		}

		$seze = Dubina::seze_do();

		return $nalaz
			->naslov(
				sprintf(
					/* translators: %d = broj dana */
					__( 'Najniza cijena se prikazuje, ali prozor od %d dana jos nije pun', Config::TEXT_DOMAIN ),
					Dubina::DANA
				)
			)
			->objasnjenje(
				( $seze > 0 )
					? sprintf(
						/* translators: 1: datum dokle sezemo, 2: broj dana */
						__( 'Nasa evidencija seze do %1$s i zato prozor od %2$d dana jos nije pun. Brojka uz cijenu je najmanja koju imamo zabiljezenu — sto je ujedno i najmanja u prozoru, jer druge u njemu nije bilo. Ali ako je artikl prije %1$s bio jeftiniji, toga u njoj nema. Ograda nestaje kad evidencija pokrije svih %2$d dana.', Config::TEXT_DOMAIN ),
						wp_date( 'j.n.Y.', $seze ),
						Dubina::DANA
					)
					: sprintf(
						/* translators: %d = broj dana */
						__( 'Zabiljezene cijene znamo, ali ne i otkad tocno vrijede — takav zapis daje vrijednost, a ne dokazuje koliko daleko unatrag znamo. Brojka uz cijenu je najmanja koju imamo. Ako je artikl ranije bio jeftiniji, to u njoj nije. Ograda nestaje kad evidencija sama pokrije %d dana.', Config::TEXT_DOMAIN ),
						Dubina::DANA
					)
			);
	}

	/* ------------------------------------------------------------- interno */

	/** Radi li automatsko pokretanje na posluzitelju. */
	public static function cron_radi(): bool {
		// Server cron postavljen kroz cPanel obicno ide uz iskljucen WP cron.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return true;
		}

		$zadnji = (int) get_option( Config::option( 'zadnji_cron' ), 0 );

		// Posao se javio u zadnja 24 sata — dakle nesto ga pokrece.
		if ( $zadnji > 0 && ( time() - $zadnji ) < DAY_IN_SECONDS ) {
			return true;
		}

		return false;
	}

	/** Gotov redak za cPanel, preracunat za zonu posluzitelja. */
	public static function cpanel_redak(): string {
		$provjera = new \CJTR\Diagnostika\Provjere\Cpanel_Cron();
		$rezultat = $provjera->izvrsi();

		// Blokovi su kljuc => tekst. Trazi se onaj s wget retkom; ako ga nema,
		// uzima se prvi, jer je alternativa (curl) jednako upotrebljiva.
		foreach ( $rezultat->blokovi as $naslov => $tekst ) {
			if ( false !== strpos( $naslov, 'wget' ) ) {
				return trim( $tekst );
			}
		}

		foreach ( $rezultat->blokovi as $naslov => $tekst ) {
			if ( false !== strpos( $tekst, 'wp-cron.php' ) ) {
				return trim( $tekst );
			}
		}

		return '';
	}
}
