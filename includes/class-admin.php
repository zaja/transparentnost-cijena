<?php
/**
 * Admin meni i ucitavanje ekrana.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/* Tri ekrana proizvoda. */
	const STRANICA_STANJE   = 'stanje';
	const STRANICA_ARTIKLI  = 'artikli';
	const STRANICA_POSTAVKE = 'postavke';

	/* Pojavljuje se jednom i nestaje. */
	const STRANICA_CAROBNJAK = 'carobnjak';

	/* Sve ostalo, iza jedne stavke. */
	const STRANICA_NAPREDNO = 'napredno';

	/* Ekrani koji su ostali iz ranijeg sucelja — sada ispod Naprednog. */
	const STRANICA_DIJAGNOSTIKA = 'dijagnostika';
	const STRANICA_POSLOVI      = 'poslovi';
	const STRANICA_PREGLED      = 'pregled';
	const STRANICA_PROMOCIJA    = 'promocija';
	const STRANICA_PODACI       = 'podaci';

	/** Adresa jednog naseg ekrana. */
	public static function url( string $stranica, array $dodatno = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => Config::stranica( $stranica ) ), $dodatno ),
			admin_url( 'admin.php' )
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'meni' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'stilovi' ) );
	}

	public static function meni(): void {
		/*
		 * Izbornik ima najvise cetiri stavke, i to je namjerno.
		 *
		 * Ranije ih je bilo pet, sve ravnopravne, i nijedna nije govorila sto
		 * kliknuti prvo. Sada je prva stavka odgovor na pitanje "radi li sve", a
		 * sve sto administrator ne treba svaki dan stoji iza jedne stavke.
		 */
		$prvi = Carobnjak::gotov() ? self::STRANICA_STANJE : self::STRANICA_CAROBNJAK;

		add_menu_page(
			__( 'Cjenovna transparentnost', Config::TEXT_DOMAIN ),
			__( 'Cjenik i cijene', Config::TEXT_DOMAIN ),
			Config::sposobnost(),
			Config::stranica( $prvi ),
			array( __CLASS__, Carobnjak::gotov() ? 'prikazi_stanje' : 'prikazi_carobnjaka' ),
			'dashicons-tag',
			56
		);

		if ( ! Carobnjak::gotov() ) {
			// Dok carobnjak traje, ostali ekrani postoje ali se ne nude — prvo se
			// postavi, pa se gleda stanje. Obrnuti redoslijed daje prazne ekrane
			// i dojam da nista ne radi.
			add_submenu_page(
				Config::stranica( self::STRANICA_CAROBNJAK ),
				__( 'Prvo postavljanje', Config::TEXT_DOMAIN ),
				__( 'Prvo postavljanje', Config::TEXT_DOMAIN ),
				Config::sposobnost(),
				Config::stranica( self::STRANICA_CAROBNJAK ),
				array( __CLASS__, 'prikazi_carobnjaka' )
			);
		} else {
			add_submenu_page(
				Config::stranica( self::STRANICA_STANJE ),
				__( 'Stanje', Config::TEXT_DOMAIN ),
				__( 'Stanje', Config::TEXT_DOMAIN ),
				Config::sposobnost(),
				Config::stranica( self::STRANICA_STANJE ),
				array( __CLASS__, 'prikazi_stanje' )
			);
		}

		add_submenu_page(
			Config::stranica( $prvi ),
			__( 'Artikli', Config::TEXT_DOMAIN ),
			__( 'Artikli', Config::TEXT_DOMAIN ),
			Config::sposobnost(),
			Config::stranica( self::STRANICA_ARTIKLI ),
			array( __CLASS__, 'prikazi_artikle' )
		);

		add_submenu_page(
			Config::stranica( $prvi ),
			__( 'Postavke', Config::TEXT_DOMAIN ),
			__( 'Postavke', Config::TEXT_DOMAIN ),
			Config::sposobnost(),
			Config::stranica( self::STRANICA_POSTAVKE ),
			array( __CLASS__, 'prikazi_postavke' )
		);

		add_submenu_page(
			Config::stranica( $prvi ),
			__( 'Napredno', Config::TEXT_DOMAIN ),
			__( 'Napredno', Config::TEXT_DOMAIN ),
			Config::sposobnost(),
			Config::stranica( self::STRANICA_NAPREDNO ),
			array( __CLASS__, 'prikazi_napredno' )
		);

		// Ekrani iza Naprednog: postoje i imaju adresu, ali nisu u izborniku.
		foreach ( array(
			self::STRANICA_DIJAGNOSTIKA => 'prikazi_dijagnostiku',
			self::STRANICA_POSLOVI      => 'prikazi_poslove',
			self::STRANICA_PREGLED      => 'prikazi_pregled',
			self::STRANICA_PROMOCIJA    => 'prikazi_promociju',
			self::STRANICA_PODACI       => 'prikazi_podatke',
		) as $stranica => $metoda ) {
			add_submenu_page(
				'',
				__( 'Napredno', Config::TEXT_DOMAIN ),
				'',
				Config::sposobnost(),
				Config::stranica( $stranica ),
				array( __CLASS__, $metoda )
			);
		}

		if ( Carobnjak::gotov() ) {
			// Carobnjak ostaje dohvatljiv, ali izvan izbornika.
			add_submenu_page(
				'',
				__( 'Prvo postavljanje', Config::TEXT_DOMAIN ),
				'',
				Config::sposobnost(),
				Config::stranica( self::STRANICA_CAROBNJAK ),
				array( __CLASS__, 'prikazi_carobnjaka' )
			);
		}
	}

	public static function stilovi( string $hook ): void {
		$nase = false;
		foreach ( array( self::STRANICA_STANJE, self::STRANICA_ARTIKLI, self::STRANICA_POSTAVKE, self::STRANICA_CAROBNJAK, self::STRANICA_NAPREDNO, self::STRANICA_DIJAGNOSTIKA, self::STRANICA_POSLOVI, self::STRANICA_PREGLED, self::STRANICA_PROMOCIJA, self::STRANICA_PODACI ) as $str ) {
			if ( false !== strpos( $hook, Config::stranica( $str ) ) ) {
				$nase = true;
			}
		}
		if ( ! $nase ) {
			return;
		}
		wp_enqueue_style(
			Config::css( 'admin' ),
			CJTR_URL . 'admin/assets/admin.css',
			array(),
			Config::VERSION
		);
	}

	/* ==================================================== TRI EKRANA ===== */

	/**
	 * 1. STANJE — zadani ekran.
	 *
	 * Odgovara na jedno pitanje: radi li sve. Kad radi, kratak je i dosadan.
	 */
	public static function prikazi_stanje(): void {
		self::provjeri_ovlasti();

		$obavijest = self::obradi_nalaz();

		Nalazi\Nalazi::zaboravi();
		$nalazi = Nalazi\Nalazi::svi();

		$cjenik  = get_option( Config::option( Config::OPT_ZADNJI_CJENIK ), array() );
		$danas   = Cjenik\Arhiva::od_danas( Config::CJENIK_OBLICI[0] );
		$ceka    = ! $danas && time() < Postavke::ocekivano_do();
		$sidrene = self::broj_sa_sidrenom();
		$katalog = Katalog::broj();

		require CJTR_DIR . 'admin/views/stanje.php';
	}

	/**
	 * 2. ARTIKLI — uvoz na vrhu, rucna dopuna ispod.
	 *
	 * Redoslijed nije kozmeticki: iznad crte je ono sto rjesava tisuce artikala,
	 * ispod ono sto rjesava desetak. Raniji ekran ih je prikazivao kao ravnopravne,
	 * pa je najsporiji put izgledao kao glavni.
	 */
	public static function prikazi_artikle(): void {
		self::provjeri_ovlasti();

		$obavijest = self::obradi_artikle();

		$sazetak    = Podaci\Potpunost::sazetak();
		$po_poljima = Podaci\Potpunost::po_poljima();

		$filtar   = isset( $_GET['filtar'] ) ? sanitize_key( wp_unslash( $_GET['filtar'] ) ) : '';
		$stranica = isset( $_GET['stranica'] ) ? max( 1, (int) $_GET['stranica'] ) : 1;

		if ( '' !== $filtar && ! isset( Config::POLJA[ $filtar ] ) ) {
			$filtar = '';
		}

		$po_stranici   = 25;
		$ukupno_redaka = Podaci\Potpunost::broj_nepotpunih( $filtar );
		$redci         = Podaci\Potpunost::nepotpuni( $filtar, $po_stranici, ( $stranica - 1 ) * $po_stranici );
		$neprimjenjivo = Podaci\Neprimjenjivo::za_vise( wp_list_pluck( $redci, 'entity_id' ) );

		$uvoz      = self::$uvoz;
		$mapiranje = self::$mapiranje;

		// Popis se gradi iz CIJENA, ne iz oznake: artikl na akciji postoji i prije
		// nego ga itko imenuje, i upravo njega trgovac treba vidjeti.
		$posebna = Povijest\Vrsta_Prodaje::u_posebnoj_prodaji();

		require CJTR_DIR . 'admin/views/artikli.php';
	}

	/** 3. POSTAVKE. */
	public static function prikazi_postavke(): void {
		self::provjeri_ovlasti();

		$obavijest = self::obradi_postavke();
		$omnibus   = Postavke::drugi_omnibus();

		require CJTR_DIR . 'admin/views/postavke.php';
	}

	/** Carobnjak za prvo postavljanje. */
	public static function prikazi_carobnjaka(): void {
		self::provjeri_ovlasti();

		$obavijest = self::obradi_carobnjaka();
		$korak     = Carobnjak::korak();
		$koraci    = Carobnjak::koraci();

		require CJTR_DIR . 'admin/views/carobnjak.php';
	}

	/** Napredno — raspucaj prema svemu ostalom. */
	public static function prikazi_napredno(): void {
		self::provjeri_ovlasti();

		$obavijest = self::obradi_napredno();

		require CJTR_DIR . 'admin/views/napredno.php';
	}

	/* =============================================== POMOCNO ZA EKRANE ===== */

	private static function provjeri_ovlasti(): void {
		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_die( esc_html__( 'Nemate ovlasti za ovu stranicu.', Config::TEXT_DOMAIN ) );
		}
	}

	/** Na koliko se artikala dodatna cijena stvarno prikazuje. */
	private static function broj_sa_sidrenom(): int {
		global $wpdb;

		$t = Config::table( Config::TABLE_PODACI );

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$t}` c
			 JOIN {$wpdb->posts} p ON p.ID = c.entity_id
			 WHERE c.sidrena_cijena IS NOT NULL AND " . Katalog::uvjet() // phpcs:ignore
		);
	}

	/** Gumb "Rijesi ovo" na nalazu. */
	private static function obradi_nalaz(): string {
		if ( empty( $_POST['nalaz_posao'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'nalaz' ) );

		$kljuc = sanitize_key( wp_unslash( $_POST['nalaz_posao'] ) );

		if ( ! Poslovi\Registar::nadi( $kljuc ) ) {
			return __( 'Taj se postupak ne moze pokrenuti na ovoj instalaciji.', Config::TEXT_DOMAIN );
		}

		$s = Poslovi\Pokretac::pokreni( $kljuc );

		if ( Config::STATUS_GRESKA === $s->status || '' !== $s->poruka ) {
			return $s->poruka;
		}

		return __( 'Pokrenuto. Napredak se vidi pod Napredno.', Config::TEXT_DOMAIN );
	}

	/** @var array|null nalaz probnog prolaza uvoza */
	private static $uvoz = null;

	/** @var array|null zaglavlje datoteke, za povezivanje stupaca */
	private static $mapiranje = null;

	private static function obradi_postavke(): string {
		if ( empty( $_POST['radnja'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'postavke' ) );

		if ( 'spremi' !== sanitize_key( wp_unslash( $_POST['radnja'] ) ) ) {
			return '';
		}

		$polja = array(
			Postavke::REF_OSTALO     => 'datum',
			Postavke::REF_REGULIRANE => 'datum',
			Postavke::IMA_REGULIRANE => 'da_ne',
			Postavke::NAJNIZA_30     => 'da_ne',
			Postavke::NAZIV_TVRTKE   => 'tekst',
			Postavke::OZNAKA_OBJEKTA => 'tekst',
			Postavke::ROK_OBJAVE     => 'sat',
		);

		foreach ( $polja as $ime => $vrsta ) {
			$sirovo = isset( $_POST[ $ime ] ) ? wp_unslash( $_POST[ $ime ] ) : '';

			switch ( $vrsta ) {
				case 'da_ne':
					Postavke::spremi( $ime, ! empty( $sirovo ) );
					break;
				case 'sat':
					Postavke::spremi( $ime, max( 0, min( 23, (int) $sirovo ) ) );
					break;
				case 'datum':
					$d = sanitize_text_field( (string) $sirovo );
					if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
						Postavke::spremi( $ime, $d );
					}
					break;
				default:
					Postavke::spremi( $ime, sanitize_text_field( (string) $sirovo ) );
			}
		}

		return __( 'Postavke su spremljene.', Config::TEXT_DOMAIN );
	}

	private static function obradi_napredno(): string {
		if ( empty( $_POST['radnja'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'napredno' ) );

		if ( 'ponovi_carobnjaka' === sanitize_key( wp_unslash( $_POST['radnja'] ) ) ) {
			Carobnjak::ponovi();
			return __( 'Prvo postavljanje je ponovno otvoreno.', Config::TEXT_DOMAIN );
		}

		return '';
	}

	/**
	 * Ekran Artikli: uvoz i rucna dopuna.
	 *
	 * Uvoz ide u tri koraka, i nijedan se ne preskace: odaberi datoteku, povezi
	 * stupce, pogledaj sto bi se dogodilo. Tek onda upis.
	 */
	private static function obradi_artikle(): string {
		if ( empty( $_POST['radnja'] ) && empty( $_FILES['csv']['tmp_name'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'artikli' ) );

		$radnja = isset( $_POST['radnja'] ) ? sanitize_key( wp_unslash( $_POST['radnja'] ) ) : '';

		if ( 'ucitaj' === $radnja ) {
			return self::uvoz_ucitaj();
		}

		if ( 'probni' === $radnja ) {
			return self::uvoz_probni();
		}

		if ( 'uvezi' === $radnja ) {
			return self::uvoz_upisi();
		}

		if ( 'oblik_prodaje' === $radnja ) {
			return self::spremi_oblike_prodaje();
		}

		if ( 'pokupi' === $radnja ) {
			$s = Poslovi\Pokretac::pokreni( 'prikupi_podatke' );
			return ( '' !== $s->poruka ) ? $s->poruka : __( 'Pokrenuto — pokupit cemo sto trgovina vec ima.', Config::TEXT_DOMAIN );
		}

		return self::spremi_retke();
	}

	/** Korak 1: datoteka je poslana, citamo samo zaglavlje. */
	private static function uvoz_ucitaj(): string {
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			return __( 'Datoteka nije poslana.', Config::TEXT_DOMAIN );
		}

		$trajna = wp_tempnam( Config::PREFIX . '-uvoz' );

		if ( ! $trajna || ! move_uploaded_file( $_FILES['csv']['tmp_name'], $trajna ) ) {
			return __( 'Datoteka se nije mogla spremiti za citanje.', Config::TEXT_DOMAIN );
		}

		$zaglavlje = Podaci\Uvoz::zaglavlje( $trajna );

		if ( empty( $zaglavlje['stupci'] ) ) {
			wp_delete_file( $trajna );
			return __( 'Datoteka je prazna ili se ne moze procitati kao tablica.', Config::TEXT_DOMAIN );
		}

		self::$mapiranje = array(
			'putanja'  => $trajna,
			'ime'      => sanitize_file_name( (string) ( $_FILES['csv']['name'] ?? '' ) ),
			'stupci'   => $zaglavlje['stupci'],
			'primjer'  => $zaglavlje['primjer'],
			'pogodeno' => $zaglavlje['pogodeno'],
		);

		return sprintf(
			/* translators: %d = broj stupaca */
			__( 'Procitano zaglavlje: %d stupaca. Provjerite je li svaki povezan s pravim podatkom.', Config::TEXT_DOMAIN ),
			count( $zaglavlje['stupci'] )
		);
	}

	/** Korak 2: probni prolaz s korisnikovom kartom stupaca. NE PISE NISTA. */
	private static function uvoz_probni(): string {
		$putanja = self::sigurna_putanja();

		if ( '' === $putanja ) {
			return __( 'Datoteka vise nije dostupna. Ucitajte je ponovno.', Config::TEXT_DOMAIN );
		}

		$karta = self::karta_iz_obrasca();
		$ime   = isset( $_POST['uvoz_ime'] ) ? sanitize_file_name( wp_unslash( $_POST['uvoz_ime'] ) ) : '';

		$nalaz             = Podaci\Uvoz::procitaj( $putanja, $karta, $ime );
		$nalaz['putanja']  = $putanja;
		$nalaz['ime']      = $ime;
		$nalaz['karta']    = $karta;
		self::$uvoz        = $nalaz;

		if ( ! empty( $nalaz['greske'] ) ) {
			return implode( ' ', $nalaz['greske'] );
		}

		return sprintf(
			/* translators: 1: upisat ce se, 2: ukupno */
			__( 'Procitano. Upisat ce se %1$d od %2$d redaka — pogledajte popis prije potvrde.', Config::TEXT_DOMAIN ),
			(int) ( $nalaz['sazetak']['upisat_ce_se'] ?? 0 ),
			(int) ( $nalaz['sazetak']['ukupno'] ?? 0 )
		);
	}

	/** Korak 3: upis onoga sto je probni prolaz pokazao. */
	private static function uvoz_upisi(): string {
		$putanja = self::sigurna_putanja();

		if ( '' === $putanja ) {
			return __( 'Datoteka vise nije dostupna. Ucitajte je ponovno.', Config::TEXT_DOMAIN );
		}

		$karta = self::karta_iz_obrasca();
		$ime   = isset( $_POST['uvoz_ime'] ) ? sanitize_file_name( wp_unslash( $_POST['uvoz_ime'] ) ) : '';

		$nalaz = Podaci\Uvoz::procitaj( $putanja, $karta, $ime );
		$ishod = Podaci\Uvoz::upisi( $nalaz['redci'] );

		wp_delete_file( $putanja );

		$poruka = sprintf(
			/* translators: 1: upisano, 2: nedirnuto, 3: preskoceno */
			__( 'Uvoz gotov: upisano %1$d, vec bilo isto %2$d, preskoceno %3$d redaka.', Config::TEXT_DOMAIN ),
			(int) $ishod['upisano'],
			(int) $ishod['nedirnuto'],
			(int) $ishod['preskoceno']
		);

		// Zasebna recenica, ne brojka u nizu: ovo je jedini ishod kod kojeg trgovac
		// moze misliti da je njegova tablica presla, a nije.
		if ( ! empty( $ishod['vec_u_woou'] ) ) {
			$poruka .= ' ' . sprintf(
				/* translators: %d = broj redaka */
				__( 'Kod %d redaka barkod ili marka nisu upisani jer te vrijednosti vec stoje u WooCommerceu — one vrijede i ne prepisuju se. Zelite li ih promijeniti, promijenite ih u proizvodu.', Config::TEXT_DOMAIN ),
				(int) $ishod['vec_u_woou']
			);
		}

		return $poruka;
	}

	/**
	 * Putanja poslane datoteke, provjerena.
	 *
	 * Mora biti unutar privremenog direktorija — inace bi obrazac mogao natjerati
	 * dodatak da procita bilo koju datoteku na posluzitelju.
	 */
	private static function sigurna_putanja(): string {
		$putanja = isset( $_POST['uvoz_datoteka'] ) ? sanitize_text_field( wp_unslash( $_POST['uvoz_datoteka'] ) ) : '';

		$temp = trailingslashit( realpath( get_temp_dir() ) );
		$real = $putanja ? realpath( $putanja ) : '';

		if ( ! $real || 0 !== strpos( $real, $temp ) || ! is_readable( $real ) ) {
			return '';
		}

		return $real;
	}

	/** @return array<string,string> polje => indeks stupca */
	private static function karta_iz_obrasca(): array {
		$sirovo = isset( $_POST['karta'] ) && is_array( $_POST['karta'] ) ? wp_unslash( $_POST['karta'] ) : array();

		$karta = array();

		foreach ( $sirovo as $polje => $indeks ) {
			$polje = sanitize_key( $polje );
			$karta[ $polje ] = ( '' === $indeks ) ? '' : (string) (int) $indeks;
		}

		return $karta;
	}

	/**
	 * Skupna izmjena posebnog oblika prodaje.
	 *
	 * Upozorenja se skupljaju i prikazuju zajedno, ali nijedno ne zaustavlja upis —
	 * prekoracenje moze imati razlog koji mi ne vidimo, a odluka je trgovceva.
	 */
	private static function spremi_oblike_prodaje(): string {
		$izbori = isset( $_POST['oblik'] ) && is_array( $_POST['oblik'] ) ? wp_unslash( $_POST['oblik'] ) : array();
		$skupno = isset( $_POST['skupni_oblik'] ) ? sanitize_key( wp_unslash( $_POST['skupni_oblik'] ) ) : '';
		$kvacice = isset( $_POST['odabrani'] ) && is_array( $_POST['odabrani'] ) ? wp_unslash( $_POST['odabrani'] ) : array();

		// Skupni izbor pobjeduje, ali samo za oznacene retke.
		if ( '' !== $skupno ) {
			foreach ( $kvacice as $id ) {
				$izbori[ (int) $id ] = $skupno;
			}
		}

		$promijenjeno = 0;
		$upozorenja   = array();

		foreach ( $izbori as $id => $vrsta ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}

			$ishod = Povijest\Vrsta_Prodaje::postavi( $id, sanitize_key( (string) $vrsta ) );

			if ( ! empty( $ishod['ok'] ) ) {
				$promijenjeno++;
			}

			foreach ( $ishod['upozorenja'] as $u ) {
				$upozorenja[] = sprintf( '#%d: %s', $id, $u );
			}
		}

		$poruka = sprintf(
			/* translators: %d = broj artikala */
			__( 'Zabiljezeno za %d artikala.', Config::TEXT_DOMAIN ),
			$promijenjeno
		);

		if ( ! empty( $upozorenja ) ) {
			$poruka .= ' ' . implode( ' ', array_slice( $upozorenja, 0, 5 ) );
		}

		return $poruka;
	}

	/** Spremi rucne izmjene iz tablice na ekranu Artikli. */
	private static function spremi_retke(): string {
		$redci  = isset( $_POST['r'] ) && is_array( $_POST['r'] ) ? wp_unslash( $_POST['r'] ) : array();
		$np     = isset( $_POST['np'] ) && is_array( $_POST['np'] ) ? wp_unslash( $_POST['np'] ) : array();
		$razlog = isset( $_POST['np_razlog'] ) ? sanitize_text_field( wp_unslash( $_POST['np_razlog'] ) ) : '';

		if ( empty( $redci ) ) {
			return '';
		}

		$spremljeno = 0;
		$poruke     = array();

		foreach ( $redci as $id => $polja ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}

			$trazene = isset( $np[ $id ] ) ? array_map( 'sanitize_key', (array) $np[ $id ] ) : array();

			// Dodatna cijena ide vlastitim putem, jer nosi vlastitu provenijenciju.
			if ( isset( $polja['sidrena_cijena'] ) && '' !== trim( (string) $polja['sidrena_cijena'] ) ) {
				$ishod = Podaci\Sidrena_Unos::upisi(
					$id,
					sanitize_text_field( (string) $polja['sidrena_cijena'] ),
					Config::IZVOR_RUCNI_UNOS,
					__( 'rucni unos u adminu', Config::TEXT_DOMAIN )
				);

				if ( empty( $ishod['ok'] ) ) {
					$poruke[] = sprintf( '#%d: %s', $id, $ishod['poruka'] );
				}
			}

			foreach ( Config::POLJA as $polje => $meta ) {
				if ( in_array( $polje, $trazene, true ) ) {
					if ( '' === $razlog ) {
						$poruke[] = __( 'Oznaka "ne odnosi se na ovaj artikl" trazi razlog — bez njega se ne moze obraniti.', Config::TEXT_DOMAIN );
						continue;
					}
					Podaci\Neprimjenjivo::oznaci( $id, $polje, $razlog );
					continue;
				}

				Podaci\Neprimjenjivo::skini( $id, $polje );

				if ( ! isset( $polja[ $polje ] ) && ! isset( $polja[ $meta['stupci'][0] ] ) ) {
					continue;
				}

				// Isto pravilo kao kod uvoza: WooCommerceova vrijednost se ne prepisuje.
				if ( in_array( $polje, array( Config::POLJE_BARKOD, Config::POLJE_MARKA ), true )
					&& Podaci\Woo_Polja::woo_ima( $id, $polje ) ) {
					$poruke[] = sprintf(
						/* translators: 1: ID artikla, 2: naziv polja */
						__( '#%1$d: %2$s nije upisana — ta vrijednost vec stoji u WooCommerceu i ona vrijedi.', Config::TEXT_DOMAIN ),
						$id,
						mb_strtolower( Config::POLJA[ $polje ]['naziv'] )
					);
					continue;
				}

				$ishod = Podaci\Zapis_Podataka::upisi(
					$id,
					$polje,
					array_map( 'sanitize_text_field', (array) $polja ),
					Config::IZVOR_PODATKA_RUCNO
				);

				if ( ! $ishod['ok'] || '' !== $ishod['poruka'] ) {
					$poruke[] = sprintf( '#%d: %s', $id, $ishod['poruka'] );
				}
			}

			$spremljeno++;
		}

		$poruke = array_unique( array_filter( $poruke ) );

		return trim(
			sprintf(
				/* translators: %d = broj artikala */
				__( 'Spremljeno %d artikala.', Config::TEXT_DOMAIN ),
				$spremljeno
			) . ' ' . implode( ' ', array_slice( $poruke, 0, 5 ) )
		);
	}

	/**
	 * Carobnjak: koraci naprijed, natrag i odluke u koraku 4.
	 *
	 * Svaka odluka je zasebna radnja s vlastitim imenom. Jedan "dalje" koji radi
	 * razlicite stvari ovisno o koraku je mjesto na kojem se poslije ne zna sto je
	 * kliknuto.
	 */
	private static function obradi_carobnjaka(): string {
		if ( empty( $_POST['radnja'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'carobnjak' ) );

		$radnja = sanitize_key( wp_unslash( $_POST['radnja'] ) );

		if ( 'natrag' === $radnja ) {
			Carobnjak::spremi_korak( Carobnjak::korak() - 1 );
			return '';
		}

		if ( 'dalje' === $radnja ) {
			Carobnjak::spremi_korak( Carobnjak::korak() + 1 );
			return '';
		}

		if ( 'datum' === $radnja ) {
			$ima = ! empty( $_POST['ima_regulirane'] );
			Postavke::spremi( Postavke::IMA_REGULIRANE, $ima );

			foreach ( array( Postavke::REF_OSTALO, Postavke::REF_REGULIRANE ) as $ime ) {
				$d = isset( $_POST[ $ime ] ) ? sanitize_text_field( wp_unslash( $_POST[ $ime ] ) ) : '';
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
					Postavke::spremi( $ime, $d );
				}
			}

			Carobnjak::spremi_korak( Carobnjak::KORAK_CIJENE );

			return $ima
				? __( 'Zapisano. Za hranu, pice, kozmetiku, sredstva za ciscenje, toaletne potrepstine i potrepstine za kucanstvo vrijedi raniji datum; za sve ostalo kasniji.', Config::TEXT_DOMAIN )
				: __( 'Zapisano. Za cijeli katalog vrijedi jedan referentni datum.', Config::TEXT_DOMAIN );
		}

		if ( 'preuzmi_povijest' === $radnja ) {
			$s = Poslovi\Pokretac::pokreni( 'uvoz_povijesti' );
			return ( '' !== $s->poruka ) ? $s->poruka : __( 'Preuzimanje povijesti je pokrenuto. Nastavlja se u pozadini.', Config::TEXT_DOMAIN );
		}

		if ( 'izjava' === $radnja ) {
			if ( empty( $_POST['potvrdujem'] ) ) {
				return __( 'Potvrda nije oznacena, pa nista nije upisano.', Config::TEXT_DOMAIN );
			}

			Poslovi\Poslovi\Izjava_Trgovca::potvrdi( Carobnjak::bez_dodatne() );

			$s = Poslovi\Pokretac::pokreni( 'izjava_trgovca' );

			return ( Config::STATUS_GRESKA === $s->status || '' !== $s->poruka )
				? $s->poruka
				: __( 'Zabiljezeno kao vasa izjava, s datumom i imenom. Upis je pokrenut.', Config::TEXT_DOMAIN );
		}

		if ( 'zavrsi' === $radnja ) {
			Carobnjak::zavrsi();
			wp_safe_redirect( self::url( self::STRANICA_STANJE ) );
			exit;
		}

		return '';
	}

	public static function prikazi_dijagnostiku(): void {
		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_die( esc_html__( 'Nemate ovlasti za ovu stranicu.', Config::TEXT_DOMAIN ) );
		}

		$runner    = new Diagnostika\Runner();
		$rezultati = $runner->pokreni();
		$tekst     = $runner->kao_tekst( $rezultati );

		update_option( Config::option( Config::OPT_ZADNJA_DIJAG ), current_time( 'mysql' ) );

		require CJTR_DIR . 'admin/views/dijagnostika.php';
	}

	public static function prikazi_pregled(): void {
		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_die( esc_html__( 'Nemate ovlasti za ovu stranicu.', Config::TEXT_DOMAIN ) );
		}

		$obavijest = self::obradi_zaokruzivanje();

		// Uvijek svjeze iz baze — u meduvremenu se mogao dogoditi rucni unos.
		$stanje = Cijene\Pregled::stanje();

		$zaok_promjene = Cijene\Zaokruzivanje::promjene( 40 );
		$zaok_ucinak   = empty( $zaok_promjene ) ? null : Cijene\Zaokruzivanje::ucinak();
		$zaok_potvrda  = Poslovi\Poslovi\Zaokruzi_Cijene::potvrda();

		// Brojka koju pokazuje gumb mora biti ISTA ona koju posao usporeduje pri
		// pokretanju. Inace bi potvrda za "87 artikala" pala na zapreci koja tvrdi
		// da se skup promijenio, a nista se nije promijenilo.
		$zaok_broj = empty( $zaok_promjene ) ? 0 : Cijene\Zaokruzivanje::broj_pogodenih();

		require CJTR_DIR . 'admin/views/pregled.php';
	}

	/**
	 * Potvrda za svodenje cijena na dvije decimale.
	 *
	 * Stoji na ekranu dodatnih cijena, a ne na svojem: politika se primjenjuje na
	 * tekucu I na dodatnu cijenu u istom prolazu, pa se odluka donosi ondje gdje se
	 * dodatna cijena i gleda.
	 *
	 * @return string obavijest za korisnika, ili prazno
	 */
	private static function obradi_zaokruzivanje(): string {
		if ( empty( $_POST['radnja'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'zaokruzivanje' ) );

		$radnja = sanitize_key( wp_unslash( $_POST['radnja'] ) );

		if ( 'potvrdi_zaokruzivanje' === $radnja ) {
			Poslovi\Poslovi\Zaokruzi_Cijene::potvrdi();
			return __( 'Potvrda je zabiljezena. Posao "Svedi cijene na dvije decimale" moze se pokrenuti na stranici Obrada.', Config::TEXT_DOMAIN );
		}

		if ( 'povuci_zaokruzivanje' === $radnja ) {
			Poslovi\Poslovi\Zaokruzi_Cijene::povuci_potvrdu();
			return __( 'Potvrda je povucena. Posao se vise ne moze pokrenuti dok se ne potvrdi ponovno.', Config::TEXT_DOMAIN );
		}

		return '';
	}

	public static function prikazi_poslove(): void {
		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_die( esc_html__( 'Nemate ovlasti za ovu stranicu.', Config::TEXT_DOMAIN ) );
		}

		$obavijest = self::obradi_radnju();
		$plan      = new Poslovi\Plan();

		require CJTR_DIR . 'admin/views/poslovi.php';
	}

	/** Ekran za unos podataka o proizvodima. */
	public static function prikazi_podatke(): void {
		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_die( esc_html__( 'Nemate ovlasti za ovu stranicu.', Config::TEXT_DOMAIN ) );
		}

		$obavijest = self::obradi_podatke();

		$filtar   = isset( $_GET['filtar'] ) ? sanitize_key( wp_unslash( $_GET['filtar'] ) ) : '';
		$stranica = isset( $_GET['stranica'] ) ? max( 1, (int) $_GET['stranica'] ) : 1;

		if ( '' !== $filtar && ! isset( Config::POLJA[ $filtar ] ) ) {
			$filtar = '';
		}

		$po_stranici = 50;

		$sazetak       = Podaci\Potpunost::sazetak();
		$po_poljima    = Podaci\Potpunost::po_poljima();
		$ukupno_redaka = Podaci\Potpunost::broj_nepotpunih( $filtar );
		$redci         = Podaci\Potpunost::nepotpuni( $filtar, $po_stranici, ( $stranica - 1 ) * $po_stranici );
		$sukobi        = Podaci\Sukobi_Podataka::nerijeseni( 20 );
		$duplikati     = Podaci\Potpunost::duplikati_barkoda();

		$neprimjenjivo = Podaci\Neprimjenjivo::za_vise( wp_list_pluck( $redci, 'entity_id' ) );

		$kategorije          = Podaci\Kategorije::stablo();
		$mapiranje           = Podaci\Kategorije::mapiranje();
		$kategorije_napredak = Podaci\Kategorije::napredak();

		$kom         = Podaci\Komadna_Roba::probni_prolaz();
		$kom_potvrda = Poslovi\Poslovi\Kolicina_Kom::potvrda();

		$uvoz = self::$uvoz;

		require CJTR_DIR . 'admin/views/podaci.php';
	}

	/**
	 * Spremi unos iz retka.
	 *
	 * Prazna vrijednost BRISE podatak — to je razlika prema oznaci "nije
	 * primjenjivo", koja trazi razlog i vodi se odvojeno.
	 */
	private static function obradi_podatke(): string {
		if ( empty( $_POST['radnja'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'podaci' ) );

		$radnja = sanitize_key( wp_unslash( $_POST['radnja'] ) );

		if ( 'kategorije' === $radnja ) {
			$kat = isset( $_POST['kat'] ) && is_array( $_POST['kat'] ) ? wp_unslash( $_POST['kat'] ) : array();
			Podaci\Kategorije::spremi_mapiranje( array_map( 'sanitize_key', $kat ) );
			return __( 'Mapiranje kategorija je spremljeno.', Config::TEXT_DOMAIN );
		}

		if ( 'potvrdi_kom' === $radnja ) {
			Poslovi\Poslovi\Kolicina_Kom::potvrdi();
			return __( 'Potvrdeno. Posao "Upisi 1 kom" moze se pokrenuti na stranici Obrada.', Config::TEXT_DOMAIN );
		}

		if ( 'povuci_kom' === $radnja ) {
			Poslovi\Poslovi\Kolicina_Kom::povuci_potvrdu();
			return __( 'Potvrda je povucena.', Config::TEXT_DOMAIN );
		}

		if ( 'probni' === $radnja ) {
			return self::probni_uvoz();
		}

		if ( 'uvezi' === $radnja ) {
			return self::izvedi_uvoz();
		}

		$redci  = isset( $_POST['r'] ) && is_array( $_POST['r'] ) ? wp_unslash( $_POST['r'] ) : array();
		$np     = isset( $_POST['np'] ) && is_array( $_POST['np'] ) ? wp_unslash( $_POST['np'] ) : array();
		$razlog = isset( $_POST['np_razlog'] ) ? sanitize_text_field( wp_unslash( $_POST['np_razlog'] ) ) : '';

		$spremljeno = 0;
		$poruke     = array();

		foreach ( $redci as $id => $polja ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}

			$trazene = isset( $np[ $id ] ) ? array_map( 'sanitize_key', (array) $np[ $id ] ) : array();

			foreach ( Config::POLJA as $polje => $meta ) {
				if ( Config::POLJE_KATEGORIJA === $polje ) {
					continue;
				}

				// Oznaka "nije primjenjivo" ima prednost pred unosom: polje je
				// onemoguceno, pa vrijednost iz obrasca ne bi ni bila poslana.
				if ( in_array( $polje, $trazene, true ) ) {
					if ( '' === $razlog ) {
						$poruke[] = __( 'Oznaka "nije primjenjivo" trazi razlog — bez njega se ne moze obraniti.', Config::TEXT_DOMAIN );
						continue;
					}
					Podaci\Neprimjenjivo::oznaci( $id, $polje, $razlog );
					continue;
				}

				Podaci\Neprimjenjivo::skini( $id, $polje );

				$ishod = Podaci\Zapis_Podataka::upisi(
					$id,
					$polje,
					array_map( 'sanitize_text_field', (array) $polja ),
					Config::IZVOR_PODATKA_RUCNO
				);

				if ( ! $ishod['ok'] ) {
					$poruke[] = sprintf( '#%d: %s', $id, $ishod['poruka'] );
					continue;
				}

				if ( '' !== $ishod['poruka'] ) {
					$poruke[] = sprintf( '#%d: %s', $id, $ishod['poruka'] );
				}
			}

			$spremljeno++;
		}

		$poruke = array_unique( $poruke );

		return trim(
			sprintf(
				/* translators: %d = broj artikala */
				__( 'Spremljeno %d artikala.', Config::TEXT_DOMAIN ),
				$spremljeno
			) . ' ' . implode( ' ', array_slice( $poruke, 0, 5 ) )
		);
	}

	/**
	 * Procitaj poslanu datoteku i prijavi sto bi se dogodilo. NE PISE NISTA.
	 *
	 * Datoteka se sprema u privremeni direktorij da je potvrda moze pokupiti bez
	 * ponovnog slanja. Ondje je WordPress sam ciste — a i da ne ocisti, u njoj su
	 * podaci o proizvodima, ne o kupcima.
	 */
	private static function probni_uvoz(): string {
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			return __( 'Datoteka nije poslana.', Config::TEXT_DOMAIN );
		}

		$trajna = wp_tempnam( Config::PREFIX . '-uvoz' );

		if ( ! $trajna || ! move_uploaded_file( $_FILES['csv']['tmp_name'], $trajna ) ) {
			return __( 'Datoteka se nije mogla spremiti za citanje.', Config::TEXT_DOMAIN );
		}

		$nalaz            = Podaci\Uvoz::procitaj( $trajna );
		$nalaz['putanja'] = $trajna;
		self::$uvoz       = $nalaz;

		if ( ! empty( $nalaz['greske'] ) ) {
			return implode( ' ', $nalaz['greske'] );
		}

		return sprintf(
			/* translators: 1: upisat ce se, 2: ukupno */
			__( 'Procitano. Upisat ce se %1$d od %2$d redaka — pogledajte popis prije potvrde.', Config::TEXT_DOMAIN ),
			(int) ( $nalaz['sazetak']['upisat_ce_se'] ?? 0 ),
			(int) ( $nalaz['sazetak']['ukupno'] ?? 0 )
		);
	}

	/** Upisi ono sto je probni prolaz odobrio. */
	private static function izvedi_uvoz(): string {
		$putanja = isset( $_POST['uvoz_datoteka'] ) ? sanitize_text_field( wp_unslash( $_POST['uvoz_datoteka'] ) ) : '';

		// Putanja mora biti unutar privremenog direktorija — inace bi obrazac mogao
		// natjerati dodatak da procita bilo koju datoteku na posluzitelju.
		$temp = trailingslashit( realpath( get_temp_dir() ) );
		$real = $putanja ? realpath( $putanja ) : '';

		if ( ! $real || 0 !== strpos( $real, $temp ) || ! is_readable( $real ) ) {
			return __( 'Datoteka za uvoz vise nije dostupna. Ucitajte je ponovno.', Config::TEXT_DOMAIN );
		}

		$nalaz = Podaci\Uvoz::procitaj( $real );
		$ishod = Podaci\Uvoz::upisi( $nalaz['redci'] );

		wp_delete_file( $real );

		return sprintf(
			/* translators: 1: upisano, 2: nedirnuto, 3: preskoceno */
			__( 'Uvoz gotov: upisano %1$d, vec bilo isto %2$d, preskoceno %3$d redaka.', Config::TEXT_DOMAIN ),
			(int) $ishod['upisano'],
			(int) $ishod['nedirnuto'],
			(int) $ishod['preskoceno']
		);
	}

	/**
	 * Ekran pregleda prije promocije. NE MIJENJA NISTA osim biljeske o potvrdi.
	 *
	 * Potvrda je jedina promjena koju ovaj ekran zapisuje, i ne dira nijedan
	 * podatak o proizvodu — samo biljezi da je covjek pogledao i pristao.
	 */
	public static function prikazi_promociju(): void {
		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_die( esc_html__( 'Nemate ovlasti za ovu stranicu.', Config::TEXT_DOMAIN ) );
		}

		$obavijest = self::obradi_potvrdu();

		$sazetak     = Cijene\Promocija_Popis::sazetak();
		$redci       = Cijene\Promocija_Popis::redci();
		$nesuglasni  = Cijene\Promocija_Popis::nesuglasni();
		$bez_podatka = Cijene\Promocija_Popis::broj_bez_podatka();
		$potvrda     = Poslovi\Poslovi\Promocija::potvrda();

		require CJTR_DIR . 'admin/views/promocija.php';
	}

	/** @return string obavijest za korisnika, ili prazno */
	private static function obradi_potvrdu(): string {
		if ( empty( $_POST['radnja'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'promocija' ) );

		$radnja = sanitize_key( wp_unslash( $_POST['radnja'] ) );

		if ( 'potvrdi' === $radnja ) {
			Poslovi\Poslovi\Promocija::potvrdi();
			return __( 'Potvrda je zabiljezena. Posao se sada moze pokrenuti na stranici Obrada.', Config::TEXT_DOMAIN );
		}

		if ( 'povuci' === $radnja ) {
			Poslovi\Poslovi\Promocija::povuci_potvrdu();
			return __( 'Potvrda je povucena. Posao se vise ne moze pokrenuti dok se ne potvrdi ponovno.', Config::TEXT_DOMAIN );
		}

		return '';
	}

	/**
	 * Granica odgovornosti, u podnozju svakog ekrana.
	 *
	 * Stoji na svim ekranima, a ne samo na jednom, jer je vlasnik trgovine ne
	 * treba traziti — treba je vidjeti ondje gdje pomisli da je posao gotov.
	 */
	public static function opseg(): void {
		// Bez __(): izvlacenje prijevoda cita literale, ne konstante. Da je ovdje
		// upisan literal, recenica bi postojala na cetiri mjesta umjesto jednog.
		printf(
			'<p class="%s">%s<br><span class="%s">%s</span></p>',
			esc_attr( Config::css( 'opseg' ) ),
			esc_html( Config::OPSEG_RECENICA ),
			esc_attr( Config::css( 'opseg-primjer' ) ),
			esc_html( Config::OPSEG_PRIMJER )
		);
	}

	/** Pokreni / pauziraj / nastavi / prekini. Sve ide kroz POST i nonce. */
	private static function obradi_radnju(): string {
		if ( empty( $_POST['radnja'] ) || empty( $_POST['kljuc'] ) ) {
			return '';
		}

		check_admin_referer( Config::nonce( 'posao' ) );

		$radnja = sanitize_key( wp_unslash( $_POST['radnja'] ) );
		$kljuc  = sanitize_key( wp_unslash( $_POST['kljuc'] ) );

		if ( ! Poslovi\Registar::nadi( $kljuc ) ) {
			return __( 'Nepoznat posao.', Config::TEXT_DOMAIN );
		}

		switch ( $radnja ) {
			case 'pokreni':
				$s = Poslovi\Pokretac::pokreni( $kljuc );
				return Config::STATUS_GRESKA === $s->status
					? $s->poruka
					: __( 'Posao je pokrenut.', Config::TEXT_DOMAIN );
			case 'pauziraj':
				Poslovi\Pokretac::pauziraj( $kljuc );
				return __( 'Posao je pauziran.', Config::TEXT_DOMAIN );
			case 'nastavi':
				Poslovi\Pokretac::nastavi( $kljuc );
				return __( 'Posao je nastavljen.', Config::TEXT_DOMAIN );
			case 'prekini':
				Poslovi\Pokretac::prekini( $kljuc );
				return __( 'Posao je prekinut.', Config::TEXT_DOMAIN );
		}

		return '';
	}
}
