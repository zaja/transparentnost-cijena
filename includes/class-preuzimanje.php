<?php
/**
 * Preuzimanje radnih popisa — kroz WordPress, s provjerom ovlasti.
 *
 * ZASTO NE DATOTEKA NA DISKU
 *
 * Radni popisi sadrze cjelokupnu strukturu cijena i kandidate za redovnu cijenu.
 * Datoteka u uploads direktoriju javno je dohvatljiva, a nacini da se to sprijeci
 * ovise o web posluzitelju: .htaccess radi na Apacheu, na nginxu nema nikakav
 * ucinak. Korisnik dodatka ne zna koji posluzitelj ima, pa se na to ne smijemo
 * osloniti. Nasumicno ime je zamagljivanje, ne zastita.
 *
 * Zato datoteke NEMA. CSV se sastavlja u trenutku preuzimanja, iz baze, i salje
 * samo prijavljenom korisniku s ovlastima. Nema sto procuriti jer nista ne stoji
 * na disku, i radi jednako na svakom posluzitelju.
 *
 * ZAGLAVLJE IDE U PRVI REDAK, BEZ IZNIMKE
 *
 * Ranije su datoteke pocinjale s nekoliko redaka objasnjenja koji pocinju s `#`.
 * Excel i LibreOffice to ne prepoznaju kao komentar nego kao PODATKE: prvi stupac
 * postane naslov datoteke, a ostatak se raspadne. Provjereno.
 *
 * Gore od toga: nas vlastiti uvoznik trazi `sifra` u prvom retku, pa bi odbio
 * datoteku koju smo sami izvezli.
 *
 * Objasnjenja zato idu NA EKRAN, uz gumb za preuzimanje — ondje ih korisnik i
 * cita, prije nego klikne, a ne nakon sto otvori pokvarenu tablicu.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Preuzimanje {

	const AKCIJA = 'preuzmi_popis';

	/** Popis oznacenih dugih akcija ima vlastite stupce, pa ide zasebnim putem. */
	const POPIS_DUGE_AKCIJE = 'duge_akcije';

	/** Popis artikala koje promocija mijenja — cijene prije i poslije. */
	const POPIS_PROMOCIJA = 'promocija';

	/** Podaci o proizvodima: isti stupci koje uvoz prima natrag. */
	const POPIS_PODACI = 'podaci';

	/** Artikli kojima "1 kom" mozda nije tocno — naziv im sadrzi broj. */
	const POPIS_IZNIMKE_KOLICINE = 'iznimke_kolicine';

	/** Cijene koje politika zaokruzivanja mijenja — prije i poslije. */
	const POPIS_ZAOKRUZIVANJE = 'zaokruzivanje';

	/** Artikli koji su kupcu vidljivi, a nemaju cijenu — pa ispadaju iz cjenika. */
	const POPIS_BEZ_CIJENE = 'bez_cijene';

	/**
	 * Tuda tablica povijesti cijena, kakva jest.
	 *
	 * Nudi se PRIJE nego je procitamo. To je jedini trenutak u kojem korisnik jos
	 * ima oboje — i tudi dodatak, i nas.
	 */
	const POPIS_TUDA_POVIJEST = 'tuda_povijest';

	public static function init(): void {
		add_action( 'admin_post_' . Config::hook( self::AKCIJA ), array( __CLASS__, 'posalji' ) );
	}

	/** Adresa za preuzimanje jednog popisa. */
	public static function url( string $izvor ): string {
		return add_query_arg(
			array(
				'action' => Config::hook( self::AKCIJA ),
				'izvor'  => $izvor,
				'_wpnonce' => wp_create_nonce( Config::nonce( self::AKCIJA ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Ime datoteke po popisu.
	 *
	 * @return string prazno ako popis ne postoji
	 */
	public static function ime_datoteke( string $izvor ): string {
		$imena = array(
			self::POPIS_DUGE_AKCIJE        => 'oznacene-duge-akcije.csv',
			self::POPIS_PROMOCIJA          => 'akcijska-u-redovnu.csv',
			self::POPIS_PODACI             => 'podaci-o-proizvodima.csv',
			self::POPIS_IZNIMKE_KOLICINE   => 'kolicina-za-pregled.csv',
			self::POPIS_ZAOKRUZIVANJE      => 'zaokruzivanje-prije-i-poslije.csv',
			self::POPIS_BEZ_CIJENE         => 'artikli-bez-cijene.csv',
			self::POPIS_TUDA_POVIJEST      => 'povijest-cijena-iz-drugog-dodatka.csv',
		);

		if ( isset( $imena[ $izvor ] ) ) {
			return $imena[ $izvor ];
		}

		if ( isset( Config::RADNI_POPISI[ $izvor ] ) ) {
			return Config::RADNI_POPISI[ $izvor ] . '-' . Config::REF_DATUM_OSTALO . '.csv';
		}

		return '';
	}

	/**
	 * Redci jednog popisa — ZAGLAVLJE PRVO, pa podaci.
	 *
	 * Odvojeno od slanja zato da se moze provjeriti bez HTTP zahtjeva. Slanje zavrsi
	 * s `exit`, pa bi inace jedini nacin da se izvoz ispita bio otvoriti ga u
	 * pregledniku — a onda se ne ispituje nego gleda.
	 *
	 * Upravo je to nedostajalo kad su datoteke pocinjale komentarima: nitko nije
	 * provjerio moze li nas vlastiti uvoznik procitati ono sto smo izvezli.
	 *
	 * @return array[] prvi redak je zaglavlje
	 */
	public static function redci_csv( string $izvor ): array {
		if ( self::POPIS_DUGE_AKCIJE === $izvor ) {
			return self::redci_duge_akcije();
		}

		if ( self::POPIS_PROMOCIJA === $izvor ) {
			return self::redci_promocije();
		}

		if ( self::POPIS_PODACI === $izvor ) {
			return self::redci_podataka();
		}

		if ( self::POPIS_IZNIMKE_KOLICINE === $izvor ) {
			return self::redci_iznimki_kolicine();
		}

		if ( self::POPIS_ZAOKRUZIVANJE === $izvor ) {
			return self::redci_zaokruzivanja();
		}

		if ( self::POPIS_BEZ_CIJENE === $izvor ) {
			return self::redci_bez_cijene();
		}

		if ( self::POPIS_TUDA_POVIJEST === $izvor ) {
			return \CJTR\Cijene\Povijest_Cijena::izvoz();
		}

		if ( isset( Config::RADNI_POPISI[ $izvor ] ) ) {
			return self::redci_radnog_popisa( $izvor );
		}

		return array();
	}

	/** Posalji sastavljene retke kao CSV. */
	private static function posalji_csv( string $ime, array $redci ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $ime . '"' );

		$h = fopen( 'php://output', 'w' );

		foreach ( $redci as $redak ) {
			fputcsv( $h, $redak );
		}

		fclose( $h );
		exit;
	}

	public static function posalji(): void {
		if ( ! current_user_can( Config::sposobnost() ) ) {
			wp_die( esc_html__( 'Nemate ovlasti za preuzimanje ovog popisa.', Config::TEXT_DOMAIN ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( Config::nonce( self::AKCIJA ) );

		$izvor = isset( $_GET['izvor'] ) ? sanitize_key( wp_unslash( $_GET['izvor'] ) ) : '';
		$ime   = self::ime_datoteke( $izvor );

		if ( '' === $ime ) {
			wp_die( esc_html__( 'Nepoznat popis.', Config::TEXT_DOMAIN ), '', array( 'response' => 400 ) );
		}

		self::posalji_csv( $ime, self::redci_csv( $izvor ) );
	}

	/** @return array[] */
	private static function redci_radnog_popisa( string $izvor ): array {
		$izlaz = array(
			array(
				'roditelj_id', 'entity_id', 'sku', 'tip', 'status', 'naziv',
				'kandidat_regular', 'kandidat_efektivna', 'odabrana_sidrena_cijena',
				'valuta', 'referentni_datum', 'bio_na_akciji', 'pocetak_pouzdan', 'napomena',
			),
		);

		foreach ( self::redci( $izvor ) as $r ) {
			$izlaz[] = array(
				$r->roditelj_id,
				$r->entity_id,
				$r->sku,
				$r->post_type,
				$r->post_status,
				$r->naziv,
				$r->sidrena_kandidat_regular,
				$r->sidrena_kandidat_efektivna,
				'',
				get_woocommerce_currency(),
				Config::REF_DATUM_OSTALO,
				$r->bio_na_akciji,
				$r->pocetak_pouzdan,
				$r->sidrena_biljeska,
			);
		}

		return $izlaz;
	}

	/**
	 * Artikli kojima akcija traje neobicno dugo.
	 *
	 * Oznaceni su, ne prekvalificirani — odluka je trgovceva.
	 *
	 * @return array[]
	 */
	private static function redci_duge_akcije(): array {
		$izlaz = array(
			array(
				'roditelj_id', 'entity_id', 'sku', 'tip', 'naziv',
				'redovna_cijena', 'akcijska_cijena', 'naplacuje_se',
				'dana_nepromijenjeno', 'valuta', 'potvrda_trgovca',
			),
		);

		foreach ( \CJTR\Povijest\Duge_Akcije::oznaceni() as $r ) {
			$izlaz[] = array(
				$r->roditelj_id,
				$r->entity_id,
				$r->sku,
				$r->post_type,
				$r->naziv,
				self::cijena( $r->regular_price ),
				self::cijena( $r->sale_price ),
				self::cijena( $r->price ),
				$r->dana,
				get_woocommerce_currency(),
				'',
			);
		}

		return $izlaz;
	}

	/**
	 * Artikli koje promocija mijenja, s cijenom prije i poslije.
	 *
	 * Stupac `naplacuje_se_poslije` postoji iako je uvijek jednak onom prije —
	 * upravo je to tvrdnja koju popis mora dokazati citatelju. Izostavljen bi
	 * ostavio pitanje mijenja li se cijena koju kupac placa.
	 *
	 * @return array[]
	 */
	private static function redci_promocije(): array {
		$izlaz = array(
			array(
				'roditelj_id', 'entity_id', 'sku', 'tip', 'status', 'naziv',
				'naplacuje_se_prije', 'redovna_prije', 'akcijska_prije',
				'naplacuje_se_poslije', 'redovna_poslije', 'akcijska_poslije',
				'sidrena_cijena', 'sidrena_kandidat_efektivna', 'izvor_sidrene',
				'slaze_se_s_dodatnom', 'valuta',
			),
		);

		$tol = Config::TOLERANCIJA_POVIJESTI;

		foreach ( \CJTR\Cijene\Promocija_Popis::redci() as $r ) {
			$sidrena = $r->sidrena_cijena ?? $r->sidrena_kandidat_efektivna;
			$slaze   = ( null === $sidrena )
				? 'nema podatka'
				: ( ( abs( (float) $sidrena - (float) $r->naplacuje_se ) <= $tol ) ? 'da' : 'NE' );

			$izlaz[] = array(
				$r->roditelj_id,
				$r->entity_id,
				$r->sku,
				$r->post_type,
				$r->post_status,
				$r->naziv,
				$r->naplacuje_se,
				$r->redovna,
				$r->akcijska,
				$r->naplacuje_se,
				$r->naplacuje_se,
				'',
				$r->sidrena_cijena,
				$r->sidrena_kandidat_efektivna,
				$r->sidrena_izvor,
				$slaze,
				get_woocommerce_currency(),
			);
		}

		return $izlaz;
	}

	/**
	 * Artikli vidljivi u trgovini koji nemaju cijenu.
	 *
	 * Stupac `adresa` postoji zato da se artikl moze otvoriti i pogledati. Popis od
	 * dvadeset sifri bez adrese znaci dvadeset pretraga po adminu.
	 *
	 * @return array[]
	 */
	private static function redci_bez_cijene(): array {
		$izlaz = array(
			array( 'entity_id', 'sifra', 'naziv', 'adresa' ),
		);

		foreach ( \CJTR\Cjenik\Izvor::bez_cijene() as $r ) {
			$izlaz[] = array(
				$r->entity_id,
				$r->sku,
				$r->naziv,
				get_permalink( (int) $r->entity_id ),
			);
		}

		return $izlaz;
	}

	/**
	 * Cijene koje politika zaokruzivanja mijenja, svaka sa starom i novom brojkom.
	 *
	 * Jedan redak po CIJENI, ne po artiklu: artikl moze imati i redovnu i akcijsku
	 * i dodatnu, a potvrda se daje na ono sto se stvarno mijenja. Zbrajanjem stupca
	 * `razlika` dobije se ukupno snizenje po komadu.
	 *
	 * @return array[]
	 */
	private static function redci_zaokruzivanja(): array {
		$izlaz = array(
			array( 'entity_id', 'naziv', 'polje', 'prije', 'poslije', 'razlika', 'valuta' ),
		);

		foreach ( \CJTR\Cijene\Zaokruzivanje::promjene() as $r ) {
			foreach ( $r['polja'] as $polje => $par ) {
				$izlaz[] = array(
					$r['entity_id'],
					$r['naziv'],
					$polje,
					$par[0],
					$par[1],
					// Egzaktan odrezani dio, ne `cijena()`: razlika je uvijek manja od
					// centa, pa bi je dvodecimalni prikaz sveo na 0,00 — a zaokruzen
					// prikaz ne bi se zbrojio u iznos koji pise na ekranu.
					\CJTR\Cijene\Zaokruzivanje::razlika( $par[0] ),
					get_woocommerce_currency(),
				);
			}
		}

		return $izlaz;
	}

	/**
	 * Podaci o proizvodima — izvoz koji se moze vratiti natrag uvozom.
	 *
	 * Stupci su ISTI kao oni koje uvoz ocekuje. Tablica se preuzme, popuni u
	 * proracunskoj tablici i vrati; barkodovi od dobavljaca ionako dolaze kao
	 * tablica, pa je to najkraci put od dobavljaca do trgovine.
	 *
	 * @return array[]
	 */
	private static function redci_podataka(): array {
		$izlaz = array( \CJTR\Podaci\Izvoz::ZAGLAVLJE );

		foreach ( \CJTR\Podaci\Izvoz::redci() as $r ) {
			$izlaz[] = \CJTR\Podaci\Izvoz::redak( $r );
		}

		return $izlaz;
	}

	/**
	 * Artikli kojima posao nije upisao "1 kom" jer im naziv sadrzi broj.
	 *
	 * Poredano po prometu — popis se obraduje dok se ne umori, pa prvo ide ono sto
	 * se najvise prodaje.
	 *
	 * Stupci `neto_kolicina` i `jedinica_mjere` su PRAZNI i to je namjerno: tablica
	 * se ispuni i vrati kroz uvoz, pa se popis rijesi bez otvaranja ijednog artikla.
	 *
	 * @return array[]
	 */
	private static function redci_iznimki_kolicine(): array {
		$izlaz = array(
			array(
				'sifra', 'entity_id', 'naziv', 'tip', 'broj_iz_naziva',
				'cijena', 'promet', 'valuta', 'neto_kolicina', 'jedinica_mjere',
			),
		);

		foreach ( \CJTR\Podaci\Komadna_Roba::iznimke() as $r ) {
			$izlaz[] = array(
				(string) $r->sku,
				(int) $r->entity_id,
				(string) $r->naziv,
				(string) $r->post_type,
				(string) $r->broj,
				self::cijena( $r->cijena ),
				self::cijena( $r->promet ),
				get_woocommerce_currency(),
				'',
				'',
			);
		}

		return $izlaz;
	}

	/**
	 * Cijena za prikaz u tablici, na dvije decimale.
	 *
	 * U bazi jos stoje vrijednosti s pet decimala — ostatak preracuna iz kuna, vidi
	 * sekciju I. Politika zaokruzivanja jos nije primijenjena na katalog, pa se u
	 * izvozu barem PRIKAZUJE dvije decimale: `52.9564` u tablici izgleda kao greska,
	 * a nije nego nerijeseno naslijede.
	 *
	 * Ovo ne mijenja nista u bazi niti zamjenjuje taj popravak.
	 *
	 * NE primjenjuje se na popise gdje je cijena PREDMET ODLUKE — `odluka-klijenta`
	 * i `akcijska-u-redovnu`. Ondje klijent bira izmedu dvije konkretne vrijednosti
	 * i mora vidjeti tocno onu koja ce se upisati; zaokruzen prikaz ondje bi krio
	 * upravo ono o cemu odlucuje.
	 */
	private static function cijena( $vrijednost ): string {
		if ( null === $vrijednost || '' === $vrijednost ) {
			return '';
		}

		return number_format( (float) $vrijednost, 2, '.', '' );
	}

	/** @return object[] */
	public static function redci( string $izvor ): array {
		global $wpdb;

		$tablica = Config::table( Config::TABLE_PODACI );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.entity_id, c.sidrena_kandidat_regular, c.sidrena_kandidat_efektivna,
				        c.bio_na_akciji, c.pocetak_pouzdan, c.sidrena_biljeska,
				        p.post_type, p.post_status,
				        IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AS roditelj_id,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				        COALESCE( l.sku, '' ) AS sku
				 FROM `{$tablica}` c
				 JOIN {$wpdb->posts} p ON p.ID = c.entity_id
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = c.entity_id
				 WHERE c.sidrena_izvor = %s
				 ORDER BY roditelj_id ASC, c.entity_id ASC",
				$izvor
			) // phpcs:ignore
		);
	}

	public static function broj( string $izvor ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE sidrena_izvor = %s',
				$izvor
			) // phpcs:ignore
		);
	}

	/**
	 * Odbija li preuzimanje neprijavljenog posjetitelja.
	 *
	 * Mjeri se, ne pretpostavlja — dohvatom bez kolacica. Odgovor 200 znaci da je
	 * popis dostupan bilo kome i to mora biti prijavljeno.
	 */
	public static function zasticeno(): array {
		$odgovor = wp_remote_get(
			self::url( array_key_first( Config::RADNI_POPISI ) ),
			array(
				'timeout'   => 10,
				'cookies'   => array(),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $odgovor ) ) {
			return array( 'provjereno' => false, 'razlog' => $odgovor->get_error_message() );
		}

		$kod = (int) wp_remote_retrieve_response_code( $odgovor );

		return array(
			'provjereno' => true,
			'zasticen'   => ( $kod >= 300 ),
			'kod'        => $kod,
			'razlog'     => ( $kod >= 300 )
				? __( 'neprijavljeni posjetitelj ne moze preuzeti popis', Config::TEXT_DOMAIN )
				: __( 'POPIS JE DOSTUPAN BILO KOME — provjeri ovlasti', Config::TEXT_DOMAIN ),
		);
	}
}
