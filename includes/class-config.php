<?php
/**
 * JEDINI izvor istine za sva imena i vrijednosti.
 *
 * Pravilo projekta: nijedna vrijednost i nijedno ime polja ne smije biti
 * utipkano na dva mjesta. Sve ide odavde.
 *
 * Kad dode vrijeme za ekran postavki, konstante se mehanicki zamjenjuju
 * pozivima get_option() — potpisi metoda ostaju isti, pozivatelji se ne diraju.
 *
 * @package CJTR
 */

namespace CJTR;

defined( 'ABSPATH' ) || exit;

final class Config {

	/** Prefiks za tablice, opcije, meta polja, hookove i CSS klase. */
	const PREFIX = 'cjtr';

	const VERSION     = '1.4.1';
	const TEXT_DOMAIN = 'cjenovna-transparentnost';

	/** Verzija sheme. Podici pri svakoj promjeni tablica. */
	const DB_VERSION = 8;

	/** Minimalni zahtjevi. */
	const MIN_PHP = '7.4';
	const MIN_WP  = '5.9';
	const MIN_WC  = '7.0';

	/* ---------------------------------------------------------------------
	 * Tablice — logicka imena. Fizicko ime daje table().
	 * ------------------------------------------------------------------ */

	const TABLE_PODACI    = 'podaci';
	const TABLE_SNAPSHOT  = 'snapshot';
	const TABLE_POSLOVI   = 'poslovi';
	const TABLE_POSAO_LOG = 'posao_log';

	/**
	 * Trag: stanje PRIJE svake operacije koja brise ili prepisuje tude podatke.
	 *
	 * Pravilo projekta: operacija koja unistava dokaz mora prvo ostaviti trag.
	 * Bez toga se podatak koji se ispostavi namjernim ne moze vratiti.
	 */
	const TABLE_TRAG = 'trag';

	/**
	 * Vlastita povijest cijena.
	 *
	 * Postoji jer tudi izvor biljezi SAMO efektivnu cijenu. Zbog toga se redovna
	 * cijena na neki datum nije mogla procitati nego se morala rekonstruirati iz
	 * ranijih intervala — i za dio artikala se nije dala rekonstruirati uopce.
	 * Ovdje se biljeze sve tri vrijednosti.
	 */
	const TABLE_POVIJEST = 'povijest';

	/**
	 * "Nije primjenjivo", s razlogom, tko i kada.
	 *
	 * Zasebna tablica, a ne stupac po polju: prazno-jer-nije-uneseno i
	 * prazno-jer-objektivno-ne-postoji dvije su razlicite stvari. Prvo je zadatak,
	 * drugo je ODLUKA trgovca koju mora moci obraniti — a odluka bez razloga i
	 * potpisa nije obranjiva. Uz to se novo polje doda bez migracije.
	 */
	const TABLE_NEPRIMJENJIVO = 'neprimjenjivo';

	/**
	 * Sukobi izmedu izvora istog podatka.
	 *
	 * Kad dva izvora kazu razlicito, ne bira se tiho. Jaci izvor se upise, a
	 * razlika ostaje zapisana da je covjek moze pogledati.
	 */
	const TABLE_SUKOBI = 'sukobi';

	/**
	 * Tablice iz faze prije plugina, koje migracija preimenuje.
	 * staro_ime_bez_wpdb_prefiksa => logicko ime
	 */
	const NASLIJEDENE_TABLICE = array(
		'cjenik_podaci'   => self::TABLE_PODACI,
		'cjenik_snapshot' => self::TABLE_SNAPSHOT,
	);

	/* ---------------------------------------------------------------------
	 * Opcije
	 * ------------------------------------------------------------------ */

	const OPT_DB_VERSION   = 'db_version';
	const OPT_AKTIVIRANO   = 'aktivirano';
	const OPT_ZADNJA_DIJAG = 'zadnja_dijagnostika';

	/** Velicina komada koju je izmjerila dijagnostika. Fallback je BATCH_ZADANO. */
	const OPT_VELICINA_KOMADA = 'velicina_komada';

	/* ---------------------------------------------------------------------
	 * Referentni datumi i rokovi
	 * ------------------------------------------------------------------ */

	/** Hrana, pice, kozmetika, sredstva za ciscenje, toaletne potrepstine, kucanstvo. */
	const REF_DATUM_REGULIRANE = '2025-05-02';

	/** Svi ostali proizvodi i usluge. */
	const REF_DATUM_OSTALO = '2026-09-10';

	/** Datum stupanja na snagu. */
	const PRIMJENA_OD = '2026-10-01';

	/* ---------------------------------------------------------------------
	 * Opseg dodatka
	 * ------------------------------------------------------------------ */

	/**
	 * Granica odgovornosti, ispisana na svakom ekranu dodatka.
	 *
	 * Stoji ovdje jednom da je ekrani ne prepisuju. U readme.txt postoji jos
	 * jednom jer je to staticki tekst instalatera i ne moze pozvati PHP —
	 * mijenja li se, mijenja se na oba mjesta.
	 */
	const OPSEG_RECENICA = 'Dodatak radi iskljucivo s cijenama unesenima u proizvod. Cijene utipkane u sadrzaj stranica — baneri, elementi graditelja stranica, opisi proizvoda, odredisne stranice — nisu mu vidljive i odgovornost su vlasnika trgovine.';

	/**
	 * Primjer uz granicu odgovornosti.
	 *
	 * Nacelna izjava se procita i zaboravi; primjer se prepozna. Ranije je ovdje
	 * stajao doslovan citat iz kataloga na kojem je dodatak pisan — tocan ondje, a
	 * u svakoj drugoj trgovini besmislica. Primjer je zato opisan, ne citiran: svaki
	 * trgovac prepozna svoj slucaj, a nijedan ne cita tudi.
	 */
	const OPSEG_PRIMJER = 'Primjer: opis artikla u kojem rukom pise "-50%" ili "posebna ponuda". Ta tvrdnja o snizenju ne dolazi iz cijene artikla nego je utipkana u tekst, pa dodatak o njoj ne zna nista. Ostaje na stranici i kad se cijena promijeni.';

	/* ---------------------------------------------------------------------
	 * Izvori sidrene cijene
	 * ------------------------------------------------------------------ */

	/**
	 * Sidrena cijena je PRVA cijena po kojoj je artikl ponuden.
	 *
	 * Vrijedi za artikle uvedene NAKON referentnog datuma. Na taj datum artikla
	 * jos nije bilo, pa cijene s njega nema — ali to ne znaci da sidrene cijene
	 * nema.
	 *
	 * TUMACENJE NA KOJEM POCIVA, S PODRIJETLOM
	 *
	 * Ministarstvo gospodarstva, izneseno na radionicama HOK-a u rujnu 2026.:
	 * sidrena cijena takvog artikla je cijena formirana kad je prvi put uvrsten u
	 * ponudu, uz jasnu naznaku datuma kad je formirana. Sidrena i vazeca cijena su
	 * na pocetku iste, a uz njih stoji datum uvodenja umjesto opceg referentnog.
	 *
	 * Materijal s radionice NIJE javno objavljen. Tumacenje se ovdje biljezi s tim
	 * ogradama, ne kao citat propisa — ali se po njemu postupa, jer je izvor
	 * mjerodavan i jer je alternativa gora (nize).
	 *
	 * ZASTO SE PO NJEMU POSTUPA I BEZ OBJAVLJENOG TEKSTA
	 *
	 * Ranije je ovdje stajalo suprotno pravilo: sidrena cijena "ne postoji i ne
	 * moze postojati", polje ostaje prazno. Ono ima posljedicu koju nitko nije
	 * trazio: artikl koji ERP ponovno uveze dobiva novi ID, i sidrena cijena mu
	 * tiho nestane. Nitko to nije htio ni primijetio, a iz obvezne objave ispadne
	 * brojka koju je kupac mogao provjeriti.
	 *
	 * Prazno polje je tvrdnja "ne znamo". Za artikl uveden jucer to nije istina —
	 * cijenu po kojoj je uveden znamo, i zabiljezili smo je sami.
	 *
	 * Pravilo, na jednom mjestu jer ga cita i prikaz i cjenik i izvjestaj:
	 *   - sidrena_cijena = prva zabiljezena cijena tog artikla
	 *   - referentni datum tog RETKA je datum uvodenja, ne opci
	 *   - u prikazu uz cijenu stoji taj datum, ne opci
	 *   - u cjeniku uz brojku ide i element s datumom, jer bi inace tvrdila
	 *     nesto o opcem referentnom datumu, a to nije istina
	 */
	const IZVOR_PRVA_CIJENA = 'prva_cijena_u_ponudi';

	/**
	 * Artikl je uveden nakon referentnog datuma, a pocetnu cijenu ne znamo.
	 *
	 * Uzak, ali stvaran slucaj: artikl uveden izmedu referentnog datuma i
	 * instalacije dodatka. Datum uvodenja znamo iz WordPressa, cijenu tog dana ne
	 * — nismo je imali tko zabiljeziti.
	 *
	 * To NIJE "ne odnosi se" nego "ne znamo, a zna trgovac": on je tu cijenu
	 * formirao. Zato ceka njegov unos i prijavljuje se kao nalaz, a ne presucuje.
	 */
	const IZVOR_NAKON_REF_DATUMA = 'nastao_nakon_referentnog_datuma';

	/** Sidrena cijena je opazena u povijesti cijena. */
	const IZVOR_POVIJEST = 'povijest';

	/** Bio na akciji na referentni datum — oba kandidata upisana, odluka na klijentu. */
	const IZVOR_TRAZI_ODLUKU = 'trazi_odluku';

	/** Artikl ceka rucni unos sidrene cijene. */
	const IZVOR_RUCNI_UNOS = 'rucni_unos';

	/**
	 * Kandidat potjece iz oscilacije cijene, ne iz stvarne akcije.
	 *
	 * Artiklu je akcija istekla, a akcijska cijena ostala postavljena, pa mu cijena
	 * svaki dan skace gore-dolje. Zapis u povijesti na referentni datum je artefakt
	 * te oscilacije. Odluka o njemu nije poslovna ("je li akcijska cijena zapravo
	 * redovna") nego cinjenicna ("koja je od dvije vrijednosti vrijedila") — zato
	 * ne smije zavrsiti u istom popisu kao stvarne akcije.
	 */
	const IZVOR_ARTEFAKT_OSCILACIJE = 'artefakt_oscilacije';

	/**
	 * Dodatna cijena utvrdena iz povijesti, POTVRDENA redovnom cijenom.
	 *
	 * Ime govori kako je utvrdena, ne samo da je rijesena: vrijednost dolazi iz
	 * zapisa o cijenama, a potvrduje je redovna cijena koja se nakon uklanjanja
	 * oscilacije s njom poklapa. Dvije neovisne potvrde iste brojke.
	 */
	const IZVOR_POVIJEST_POTVRDENA_REDOVNOM = 'povijest_potvrdena_redovnom';

	/**
	 * Dodatna cijena utvrdena ODLUKOM TRGOVCA, potvrdenom zapisom o cijenama.
	 *
	 * Ime nosi oba dijela jer su oba nuzna i nijedan sam nije dovoljan:
	 *
	 *   odluka       — trgovac je priznao da je cijena koja se naplacuje njegova
	 *                  redovna cijena. To je poslovna odluka i softver je ne donosi.
	 *   potvrdena    — ta se cijena poklapa s onom koju zapis biljezi za referentni
	 *   povijescu      datum. Bez poklapanja se NE upisuje nego prijavljuje, jer bi
	 *                  inace odluka prepisala opazanje.
	 *
	 * Razlikuje se od IZVOR_POVIJEST_POTVRDENA_REDOVNOM po tome tko je izvor
	 * tvrdnje: ondje su to dva neovisna zapisa, ovdje odluka covjeka uz zapis kao
	 * provjeru.
	 */
	const IZVOR_ODLUKA_POTVRDENA_POVIJESCU = 'odluka_potvrdena_povijescu';

	/**
	 * Vlasnik je odlucio koja je od dvije moguce vrijednosti sidrena cijena.
	 *
	 * Artikl koji je na referentni datum bio na akciji ima dvije: redovnu koja je
	 * tada vrijedila i akcijsku koja se tada naplacivala. Koja od njih je sidrena,
	 * podatak ne moze reci — obje su tocne, pitanje je koju trgovac vodi kao svoju.
	 *
	 * Razlika prema `IZVOR_ODLUKA_POTVRDENA_POVIJESCU`: ondje je odluku POTVRDIO i
	 * zapis o cijenama. Ovdje je odluka sama za sebe, i tako se i biljezi — ne
	 * pripisuje joj se potvrda koje nema.
	 */
	const IZVOR_ODLUKA_TRGOVCA = 'odluka_trgovca';

	/**
	 * Izvori koji NE ulaze u popis zadataka za rucni unos.
	 *
	 * Prazna sidrena cijena kod ovih izvora je tocan ishod, ne manjak podatka.
	 */
	const IZVORI_BEZ_ZADATKA = array( self::IZVOR_NAKON_REF_DATUMA );

	/** Tko je upisao vrijednost. Rucni unos i odluka klijenta imaju prednost pred poslom. */
	const POSTAVIO_POSAO = 'posao';
	const POSTAVIO_CLI   = 'cli';

	/** Vrijednosti koje posao smije prepisati. Sve ostalo je ljudski unos i ne dira se. */
	const POSTAVIO_PREPISIVO = array( self::POSTAVIO_POSAO, self::POSTAVIO_CLI );

	/**
	 * Izvori koje glavni posao NE smije prepisati, iako ih je upisao automat.
	 *
	 * Dodatna cijena je tvrdnja o proslosti i ne mijenja se. Kad je jednom utvrdena
	 * s dvije neovisne potvrde, ponovno izvodenje ne dodaje nista — samo bi izbrisalo
	 * zapis o tome KAKO je utvrdena. Vrijednost bi ostala ista, trag ne bi.
	 */
	const IZVORI_KOJE_POSAO_NE_PREPISUJE = array(
		self::IZVOR_POVIJEST_POTVRDENA_REDOVNOM,
		self::IZVOR_ODLUKA_POTVRDENA_POVIJESCU,
		// Odluka vlasnika nije automatski izvor. Ponovno izvodenje posla vratilo bi
		// artikl u "ceka odluku" i obrisalo ono sto je covjek odlucio.
		self::IZVOR_ODLUKA_TRGOVCA,
	);

	/** Dokazna snaga sidrene cijene. */
	const SNAGA_OPAZENO       = 'opazeno';
	const SNAGA_NEPRIMJENJIVO = 'neprimjenjivo';
	const SNAGA_NEMA          = 'nema';

	/**
	 * Dodatna cijena upisana iz tablice koju je trgovac uvezao.
	 *
	 * Uz nju se biljezi IME DATOTEKE i datum uvoza, jer "uvezeno" bez toga ne
	 * odgovara na pitanje odakle brojka — samo ga premjesta jedan korak dalje.
	 */
	const IZVOR_UVOZ = 'uvoz';

	/**
	 * Dodatna cijena koju je trgovac IZJAVIO, a ne koju smo izmjerili.
	 *
	 * Trgovac bez ikakve povijesti mora moci reci "uzmi danasnje cijene kao dodatne",
	 * inace dodatak za njega ne radi. Ali to je izjava, ne opazanje, i mora se moci
	 * razlikovati — ne da bi ga se zastitilo, nego da za sest mjeseci postoji odgovor
	 * odakle brojka.
	 */
	const IZVOR_IZJAVA_TRGOVCA = 'izjava_trgovca';

	/** Dokazna snaga izjave: ispod opazanja, jer je nitko nije provjerio. */
	const SNAGA_IZJAVA = 'izjava';

	/**
	 * Koliko je koji izvor dodatne cijene jak.
	 *
	 * Veci broj pobjeduje. Uvoz smije prepisati izjavu (trgovac je nasao pravi
	 * podatak), ali NE smije prepisati opazanje iz zapisa ni rucni unos — jer bi
	 * tablica iz ERP-a pregazila ono sto je netko provjerio.
	 */
	const SNAGA_IZVORA_SIDRENE = array(
		self::IZVOR_IZJAVA_TRGOVCA              => 1,
		self::IZVOR_UVOZ                        => 2,
		self::IZVOR_POVIJEST                    => 3,
		self::IZVOR_POVIJEST_POTVRDENA_REDOVNOM => 4,
		self::IZVOR_ODLUKA_POTVRDENA_POVIJESCU  => 4,
		self::IZVOR_ODLUKA_TRGOVCA              => 4,
	);

	/**
	 * Stupci koji smiju biti prazni i u kojima nula NIJE legitimna vrijednost.
	 *
	 * Sluzi provjeri koja hvata potpis greske `prepare('%s', null)` — vidi Db.
	 * Nijedan artikl nema dodatnu cijenu od nula ni neto kolicinu nula; pojavi li
	 * se, to je greska pri upisu, ne podatak.
	 *
	 * logicka tablica => stupci
	 */
	const POLJA_KOJA_SMIJU_BITI_PRAZNA = array(
		self::TABLE_PODACI   => array(
			'sidrena_cijena',
			'sidrena_kandidat_regular',
			'sidrena_kandidat_efektivna',
			'neto_kolicina',
		),
		self::TABLE_SNAPSHOT => array(
			'regular_price',
			'price',
		),
	);

	/**
	 * Tolerancija pri usporedbi cijena iz povijesti.
	 *
	 * Tablica povijesti koju vodi tudi dodatak drzi cijenu kao `double`, ne kao
	 * decimalni tip. Usporedba normaliziranih stringova, koju inace koristimo, kod
	 * doublea nije pouzdana jer ista vrijednost moze imati razlicit zapis. Zato je
	 * ovo JEDINO mjesto gdje tolerancija ostaje nakon sto smo je izbacili iz diffa —
	 * i namjerno je red velicine manja od najmanje stvarne razlike u centima.
	 */
	const TOLERANCIJA_POVIJESTI = 0.0001;

	/**
	 * Referentni trenutak: KRAJ referentnog dana po vremenu trgovine.
	 *
	 * Na jednom mjestu jer ga citaju i dijagnostika i snapshot i diff — pravilo
	 * i obrazlozenje su u ANALIZA.md, sekcija J4.
	 */
	public static function t_ref( string $datum ): int {
		$kraj = new \DateTimeImmutable( $datum . ' 23:59:59', wp_timezone() );
		return $kraj->getTimestamp();
	}

	/** Je li artikl nastao nakon referentnog datuma za svoju skupinu. */
	public static function nastao_nakon_ref_datuma( string $datum_nastanka, string $referentni_datum ): bool {
		return strtotime( $datum_nastanka ) > strtotime( $referentni_datum . ' 23:59:59' );
	}

	/** Cjenik mora biti objavljen do ovog sata po lokalnom vremenu trgovine. */
	const ROK_OBJAVE_SAT = 8;

	/** Sigurnosna rezerva prije roka, u satima — pokriva kasnjenje crona i trajanje posla. */
	const ROK_REZERVA_SATI = 2;

	/* ---------------------------------------------------------------------
	 * Vlastita povijest cijena
	 * ------------------------------------------------------------------ */

	/** Sto je izazvalo zapis. */
	const OKIDAC_META      = 'meta';
	const OKIDAC_CRUD      = 'crud';
	const OKIDAC_RECONCILE = 'reconcile';
	const OKIDAC_UVOZ      = 'uvoz';

	/**
	 * Promocija akcijske cijene u redovnu.
	 *
	 * Vlastiti okidac postoji zato sto bi bez njega zapis izgledao kao promjena
	 * cijene, a nije: naplacivana cijena ostaje ista, mijenja se samo to koja se
	 * vrijednost naziva redovnom. Zapis s okidacem 'meta' na tom mjestu tvrdio bi
	 * da je cijena pala — a pala je bila davno, ili nikad.
	 */
	const OKIDAC_PROMOCIJA = 'promocija';

	/**
	 * Svodenje cijene na dvije decimale.
	 *
	 * Vlastiti okidac jer bi inace u povijesti izgledalo kao pad cijene od pola
	 * centa. Nije pad — ista je cijena, samo zapisana bez suvisnih decimala koje je
	 * ostavio preracun iz kuna.
	 */
	const OKIDAC_ZAOKRUZIVANJE = 'zaokruzivanje';

	/**
	 * Okidaci kod kojih se EFEKTIVNA cijena ne mijenja, mijenja se samo njezino ime.
	 *
	 * Citatelj povijesti (prikaz, cjenik, izvjestaj) ih smije preskociti kad racuna
	 * kretanje cijene, jer ne predstavljaju dogadaj na trzistu.
	 */
	const OKIDACI_BEZ_PROMJENE_CIJENE = array( self::OKIDAC_PROMOCIJA, self::OKIDAC_ZAOKRUZIVANJE );

	/** Okidaci kod kojih pocetak intervala NIJE pouzdan. */
	const OKIDACI_BEZ_POUZDANOG_POCETKA = array( self::OKIDAC_RECONCILE, self::OKIDAC_UVOZ );

	/**
	 * Vrsta posebnog oblika prodaje.
	 *
	 * Cjenik trazi maloprodajnu cijenu, redovnu cijenu i vrstu posebne prodaje kao
	 * TRI zasebna podatka. 'akcijska' se moze utvrditi iz podataka; ostale vrste
	 * zna samo trgovac, pa ih upisuje rucno.
	 */
	const POP_NEMA       = 'nema';
	const POP_AKCIJSKA   = 'akcijska';
	const POP_RASPRODAJA = 'rasprodaja';
	const POP_SEZONSKO   = 'sezonsko';

	/**
	 * Kako se posebni oblik prodaje ZOVE u objavljenoj datoteci.
	 *
	 * Odluka trazi naziv oblika, ne nas interni kljuc. Datoteku cita i covjek i
	 * stroj, pa "akcija" znaci vise od "akcijska".
	 */
	const NAZIV_POSEBNOG_OBLIKA = array(
		self::POP_AKCIJSKA   => 'akcija',
		self::POP_RASPRODAJA => 'rasprodaja',
		self::POP_SEZONSKO   => 'sezonsko snizenje',
	);

	/**
	 * Sto trgovac bira, i kako mu se to nudi.
	 *
	 * ZASTO POSTOJI IZBOR
	 *
	 * Naziv posebnog oblika prodaje OBVEZNO je polje propisane objave. Dok ga je
	 * dodatak popunjavao sam, iznosio je tvrdnju u ime trgovca — a rasprodaja i
	 * sezonsko snizenje pravno nisu isto sto i akcija.
	 *
	 * Zadano ostaje 'akcijska': to je definicija prodaje po cijeni nizoj od redovne
	 * i najcesci slucaj. Trgovac mijenja samo ono sto zna bolje od nas.
	 */
	const IZBOR_POSEBNOG_OBLIKA = array(
		self::POP_AKCIJSKA   => 'Akcijska prodaja (zadano)',
		self::POP_RASPRODAJA => 'Rasprodaja',
		self::POP_SEZONSKO   => 'Sezonsko snizenje',
	);

	/**
	 * Ogranicenja sezonskog snizenja.
	 *
	 * Za razliku od akcijske prodaje, kod koje maksimalno trajanje NIJE propisano,
	 * sezonsko snizenje ima brojcanu granicu. To je jedina brojka u ovom podrucju
	 * koja dolazi iz propisa, a ne iz tumacenja — zato je smije provjeravati stroj.
	 *
	 * Dodatak UPOZORAVA, ne sprjecava. Prekoracenje moze imati razlog koji mi ne
	 * vidimo, a odluka je trgovceva.
	 */
	const SEZONSKO_MAX_DANA     = 60;
	const SEZONSKO_MAX_GODISNJE = 2;

	/**
	 * Prag za OZNACAVANJE sumnjivo dugih akcija, u danima.
	 *
	 * Ime govori sto radi: oznacava, ne ogranicava. Ovo NIJE pravna granica —
	 * ne postoji propis koji kaze da akcija dulja od 90 dana prestaje biti akcija.
	 *
	 * Mjeri se KOLIKO DUGO JE TEKUCA CIJENA NEPROMIJENJENA, ne koliko je akcija
	 * zakazana da traje. Datum pocetka akcije je namjera; trajanje nepromijenjene
	 * cijene je cinjenica. Kod drugog trgovca to nije isto — akcija moze biti
	 * zakazana na godinu dana uz promjene cijene usput.
	 *
	 * Plugin OZNACAVA i trazi potvrdu trgovca. NIKAD ne prekvalificira sam: ako
	 * bi sam odlucio, donio bi pravnu odluku umjesto trgovca.
	 */
	const PRAG_SUMNJIVO_DUGE_AKCIJE = 90;

	/* ---------------------------------------------------------------------
	 * Obrada u pozadini
	 * ------------------------------------------------------------------ */

	/** Polazna velicina komada. Dijagnostika je korigira prema izmjerenom. */
	const BATCH_ZADANO = 200;
	const BATCH_MIN    = 25;
	const BATCH_MAX    = 1000;

	/** Koliki dio memory_limit-a smijemo potrositi na jedan komad. */
	const BATCH_UDIO_MEMORIJE = 0.25;

	/** Koliki dio max_execution_time-a smijemo potrositi na jedan komad. */
	const BATCH_UDIO_VREMENA = 0.5;

	/* ---------------------------------------------------------------------
	 * Poslovi u pozadini
	 * ------------------------------------------------------------------ */

	const STATUS_CEKA     = 'ceka';
	const STATUS_RADI     = 'radi';
	const STATUS_PAUZA    = 'pauza';
	const STATUS_GOTOVO   = 'gotovo';
	const STATUS_PREKINUT = 'prekinut';
	const STATUS_GRESKA   = 'greska';

	/**
	 * Posao je bio izvrsen, pa mu je ucinak ponisten povratom.
	 *
	 * Trece stanje postoji zato sto bi bez njega ekran tvrdio da je posao gotov i
	 * nakon sto je sve sto je napravio vraceno. Upravo se to dogodilo 13.9.2026.:
	 * povrat je vratio 845 artikala, a posao dodatne cijene i dalje je stajao kao
	 * "gotovo, 845", pa je izgledalo da su vrijednosti upisane. Nisu bile.
	 *
	 * Zapis se NE brise. Cinjenica da je posao izvrsen pa ponisten sama je po sebi
	 * podatak, a brisanje traga unistava dokaz — pravilo koje projekt drzi svugdje.
	 *
	 * Stanje posla mora odrazavati stanje PODATAKA, ne samo cinjenicu da je kod
	 * jednom prosao.
	 */
	const STATUS_PONISTENO = 'ponisteno';

	/** Koliko sekundi drzi zakljucavanje komada. Mora nadzivjeti najduzi komad. */
	const ZAKLJUCAJ_SEKUNDI = 300;

	/** Koliko uzastopnih gresaka po poslu tolerirati prije zaustavljanja. */
	const MAX_GRESAKA = 10;

	/** Koliko redaka loga cuvati po poslu. */
	const MAX_LOG_REDAKA = 200;

	/* ---------------------------------------------------------------------
	 * Modul 1 — podaci o proizvodima
	 *
	 * Izmjereno stanje kataloga prije modula: barkod 0 %, marka 0 %, neto
	 * kolicina 0,14 % (5 od 3623). Mjerilo uspjeha modula nije tocnost obrade
	 * nego brzina kojom se od toga dode do potpunog kataloga.
	 * ------------------------------------------------------------------ */

	/** Polja koja ovaj modul popunjava. Kljuc se koristi u bazi, URL-u i CSV-u. */
	const POLJE_BARKOD     = 'barkod';
	const POLJE_MARKA      = 'marka';
	const POLJE_KOLICINA   = 'kolicina';
	const POLJE_KATEGORIJA = 'kategorija';

	/**
	 * Polja, njihova imena i stupci u koje pisu.
	 *
	 * Jedan popis jer ga citaju ekran potpunosti, skupno uredivanje, CSV uvoz i
	 * izvoz, poslovi prikupljanja i izvjestaj o nepotpunosti. Dodavanje polja je
	 * jedan redak ovdje, ne sest izmjena po dodatku.
	 */
	const POLJA = array(
		self::POLJE_BARKOD     => array(
			'naziv'   => 'Barkod',
			'opis'    => 'GTIN, EAN ili UPC s pakiranja. Ne izmislja se — izmisljen barkod gotovo je sigurno tudi.',
			'stupci'  => array( 'barkod' ),
			'izvor'   => 'barkod_izvor',
			'obvezno' => self::OBVEZNO_UVIJEK,
		),
		self::POLJE_MARKA      => array(
			'naziv'   => 'Marka',
			'opis'    => 'Proizvodac ili brend artikla.',
			'stupci'  => array( 'marka' ),
			'izvor'   => 'marka_izvor',
			'obvezno' => self::OBVEZNO_UVIJEK,
		),
		self::POLJE_KOLICINA   => array(
			'naziv'   => 'Neto kolicina',
			'opis'    => 'Kolicina u pakiranju i jedinica mjere. Iz njih se racuna cijena po jedinici mjere, koja se objavljuje samo za robu koja se mjeri.',
			'stupci'  => array( 'neto_kolicina', 'jedinica_mjere' ),
			'izvor'   => 'kolicina_izvor',
			'obvezno' => self::OBVEZNO_NIKAD,
		),
		self::POLJE_KATEGORIJA => array(
			'naziv'   => 'Skupina proizvoda',
			'opis'    => 'Odreduje koji referentni datum vrijedi za artikl. Za trgovinu koja ne prodaje hranu, pice, kozmetiku, sredstva za ciscenje, toaletne potrepstine ni potrepstine za kucanstvo ne treba je uopce.',
			'stupci'  => array( 'zakonska_kategorija' ),
			'izvor'   => 'kategorija_izvor',
			'obvezno' => self::OBVEZNO_AKO_REGULIRANE,
		),
	);

	/**
	 * Kada se polje racuna u potpunost.
	 *
	 * NN 101/2026 je skratio popis obveznih podataka: neto kolicina i kategorija
	 * proizvoda vise nisu medu njima. Polja NE brisemo — i dalje su korisna, a
	 * kategorija je i dalje NUZNA ondje gdje trgovina ima dva referentna datuma.
	 * Mijenja se samo racuna li se njihov izostanak kao manjak.
	 *
	 * Brisanje bi bilo lakse i pogresno: trgovina koja prodaje hranu bez kategorije
	 * dobila bi krivi referentni datum, a nista je ne bi upozorilo.
	 */
	const OBVEZNO_UVIJEK         = 'uvijek';
	const OBVEZNO_NIKAD          = 'nikad';
	const OBVEZNO_AKO_REGULIRANE = 'ako_regulirane';

	/* --------------------------- jedinice mjere --------------------------- */

	const VRSTA_MASA     = 'masa';
	const VRSTA_VOLUMEN  = 'volumen';
	const VRSTA_DUZINA   = 'duzina';
	const VRSTA_POVRSINA = 'povrsina';
	const VRSTA_KOMAD    = 'komad';

	/**
	 * Poznate jedinice: vrsta i faktor prema kanonskoj jedinici te vrste.
	 *
	 * Faktor je ono cime se MNOZI da se dode do kanonske jedinice. Zato je g -> kg
	 * faktor 0.001, a ne 1000. Zamjena smjera ovdje daje gresku od milijun puta kod
	 * cijene po jedinici, pa za to postoje testovi.
	 */
	const JEDINICE = array(
		'kg'  => array( 'vrsta' => self::VRSTA_MASA, 'faktor' => 1.0 ),
		'g'   => array( 'vrsta' => self::VRSTA_MASA, 'faktor' => 0.001 ),
		'mg'  => array( 'vrsta' => self::VRSTA_MASA, 'faktor' => 0.000001 ),
		't'   => array( 'vrsta' => self::VRSTA_MASA, 'faktor' => 1000.0 ),
		'l'   => array( 'vrsta' => self::VRSTA_VOLUMEN, 'faktor' => 1.0 ),
		'dl'  => array( 'vrsta' => self::VRSTA_VOLUMEN, 'faktor' => 0.1 ),
		'cl'  => array( 'vrsta' => self::VRSTA_VOLUMEN, 'faktor' => 0.01 ),
		'ml'  => array( 'vrsta' => self::VRSTA_VOLUMEN, 'faktor' => 0.001 ),
		'm'   => array( 'vrsta' => self::VRSTA_DUZINA, 'faktor' => 1.0 ),
		'cm'  => array( 'vrsta' => self::VRSTA_DUZINA, 'faktor' => 0.01 ),
		'mm'  => array( 'vrsta' => self::VRSTA_DUZINA, 'faktor' => 0.001 ),
		'm2'  => array( 'vrsta' => self::VRSTA_POVRSINA, 'faktor' => 1.0 ),
		'cm2' => array( 'vrsta' => self::VRSTA_POVRSINA, 'faktor' => 0.0001 ),
		'kom' => array( 'vrsta' => self::VRSTA_KOMAD, 'faktor' => 1.0 ),
	);

	/**
	 * Kanonski imenitelj po vrsti — JEDAN po vrsti, kroz cijelu datoteku.
	 *
	 * Mijesanje EUR/kg i EUR/100 g unutar istog cjenika cini cijene neusporedivima,
	 * a usporedivost je jedini razlog zbog kojeg se cijena po jedinici objavljuje.
	 */
	const KANONSKE_JEDINICE = array(
		self::VRSTA_MASA     => 'kg',
		self::VRSTA_VOLUMEN  => 'l',
		self::VRSTA_DUZINA   => 'm',
		self::VRSTA_POVRSINA => 'm2',
		self::VRSTA_KOMAD    => 'kom',
	);

	/** Kako se kanonska jedinica pise u cjeniku i na ekranu. */
	const OZNAKE_JEDINICA = array(
		'kg'  => 'kg',
		'l'   => 'l',
		'm'   => 'm',
		'm2'  => 'm²',
		'kom' => 'kom',
	);

	/** Na koliko decimala se zaokruzuje cijena po jedinici mjere. */
	const DECIMALA_CIJENE_PO_JEDINICI = 2;

	/* --------------------------- politika zaokruzivanja -------------------- */

	/**
	 * Na koliko decimala se svode cijene u katalogu.
	 *
	 * Politika je FLOOR, ne zaokruzivanje na najblizu vrijednost. Obrazlozenje je u
	 * ANALIZA.md, sekcija K; ukratko: floor nikad ne ide u korist trgovca, a kod
	 * mjere ciji je smisao zastita potrosaca to je jedini smjer koji se ne mora
	 * braniti. Izmjereni trosak je oko 18 EUR godisnje.
	 */
	const ZAOKRUZIVANJE_DECIMALA = 2;

	/**
	 * Mete na koje se politika primjenjuje, u ISTOM prolazu istog dana.
	 *
	 * Zajedno sa `sidrena_cijena` u nasoj tablici. Kad bi se tekuca cijena zaokruzila
	 * danas a dodatna sutra, izmedu ta dva dana postojala bi umjetna razlika koja
	 * izgleda kao promjena cijene — i pokvarila bi bas onu dodatnu cijenu koju
	 * gradimo.
	 */
	const ZAOKRUZIVANJE_META = array( '_price', '_regular_price', '_sale_price' );

	/**
	 * Kolika RELATIVNA greska zaokruzivanja se jos tolerira bez prijave.
	 *
	 * Mjeri se odnos, ne iznos. Prvi pokusaj je bio "prijavi ako je iznos manji od
	 * 0,005", ali to promasuje bit: 0,99 EUR za 25 kg daje 0,0396 EUR/kg, sto se
	 * zaokruzi na 0,04 — odstupanje od 1,01 %, a iznos nije ni blizu 0,005. Bitno
	 * je koliko zaokruzivanje POMAKNE vrijednost, a ne koliko je vrijednost mala.
	 *
	 * Brojka se ne mijenja — vise decimala razbilo bi usporedivost s ostatkom
	 * datoteke — ali se prijavljuje: tiho zaokruzena vrijednost u propisanoj objavi
	 * nije isto sto i zaokruzena na ekranu.
	 */
	const PRAG_RELATIVNE_GRESKE_ZAOKRUZIVANJA = 0.01;

	/* ------------------------------- barkod ------------------------------- */

	/** Dopustene duljine GTIN-a. */
	const GTIN_DULJINE = array( 8, 12, 13, 14 );

	/**
	 * Ishod provjere barkoda.
	 *
	 * `interni` i `transportni` NISU greske — to su valjani kodovi koji u cjenik
	 * prodajne jedinice ne pripadaju, pa se oznacavaju a ne odbacuju.
	 */
	const GTIN_VALJAN      = 'valjan';
	const GTIN_NEISPRAVAN  = 'neispravan';
	const GTIN_INTERNI     = 'interni';
	const GTIN_TRANSPORTNI = 'transportni';
	const GTIN_DUPLIKAT    = 'duplikat';

	/**
	 * Zapis koji GTIN ne moze biti ni u kojem slucaju.
	 *
	 * GRANICA IZMEDU "SPORNO" I "NEMOGUCE"
	 *
	 * Vrijednost koja ima ispravnu duljinu a pada na kontrolnoj znamenki je SPORNA:
	 * stvarno je moguce da je broj pravi a jedna znamenka krivo prepisana. Takva se
	 * objavljuje, uz glasan nalaz.
	 *
	 * Vrijednost od dvije ili tri znamenke nije sporna nego NEMOGUCA — nijedan GTIN
	 * nema tu duljinu. Objaviti je znaci tvrditi da je barkod tog artikla "40", a to
	 * je neistinita tvrdnja u propisanoj datoteci. Ista kategorija kao izmisljen
	 * GTIN, koji smo odbacili jer je gori od praznog polja.
	 *
	 * Izmjereno na ovoj trgovini: tri takve vrijednosti — 40, 20, 100.
	 */
	const GTIN_NEMOGUC = 'nemoguc';

	/**
	 * Statusi koji SMIJU u objavljeni cjenik.
	 *
	 * `interni` i `transportni` ne smiju iako su valjani: prvi nije globalno
	 * jedinstven, drugi opisuje kutiju a ne komad. Oboje bi uz cijenu jednog artikla
	 * tvrdilo nesto netocno.
	 */
	const GTIN_U_CJENIK = array( self::GTIN_VALJAN, self::GTIN_NEISPRAVAN );

	/**
	 * Prefiksi internih kodova.
	 *
	 * 02 i 20–29 dodjeljuje trgovac sam, za vlastitu upotrebu. Kontrolna znamenka
	 * im je ispravna, ali nisu globalno jedinstveni — u javnoj datoteci tvrde nesto
	 * sto ne mogu.
	 */
	const GTIN_INTERNI_PREFIKSI = array( '02', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29' );

	/** Meta kljucevi u kojima drugi dodaci drze barkod, redom kojim se gledaju. */
	const META_BARKODA = array(
		/*
		 * `global_unique_id` NAMJERNO NIJE OVDJE.
		 *
		 * To je WooCommerceovo vlastito polje i za njega vrijedi drugi model: cita se
		 * uzivo pri prikazu i objavi, nikad se ne kopira u nasu tablicu. Vidi
		 * `Podaci\Woo_Polja`. Popis ispod su polja TUDIH dodataka, koja nitko drugi
		 * ne cita i koja bi inace ostala neiskoristena.
		 */
		'_gtin',
		'_ean',
		'_barcode',
		'_upc',
		'_wpm_gtin_code',
		'hwp_product_gtin',
		'_woo_uom_input',
	);

	/**
	 * Taksonomije u kojima moze stajati marka, redom kojim se gledaju.
	 *
	 * `product_brand` NAMJERNO NIJE OVDJE — to je WooCommerceova vlastita
	 * taksonomija i za nju vrijedi drugi model. Vidi `Podaci\Woo_Polja`.
	 */
	const TAKSONOMIJE_MARKE = array(
		'pwb-brand',
		'yith_product_brand',
		'berocket_brand',
	);

	/** Meta kljucevi u kojima moze stajati marka. */
	const META_MARKE = array(
		'_yoast_wpseo_brand',
		'_rank_math_brand',
		'_woocommerce_gpf_data',
	);

	/* -------------------------- zakonske kategorije ------------------------ */

	/**
	 * Sest skupina propisanih za cjenik.
	 *
	 * Za ovaj katalog mapiranje ostaje prazno — nijedan artikl nije iz reguliranih
	 * skupina. Mehanizam ipak postoji jer je za svakog drugog trgovca to glavni
	 * slucaj, a shema cjenika trazi kategoriju kao obvezno polje.
	 */
	const KATEGORIJA_NIJE = '';

	const ZAKONSKE_KATEGORIJE = array(
		'hrana'            => 'Hrana',
		'pice'             => 'Pice',
		'kozmetika'        => 'Kozmetika',
		'ciscenje'         => 'Sredstva za ciscenje',
		'toaletno'         => 'Toaletne potrepstine',
		'kucanstvo'        => 'Potrepstine za kucanstvo',
		'ostalo'           => 'Ostalo (nije regulirana skupina)',
	);

	/**
	 * Skupine za koje vrijedi RANIJI referentni datum.
	 *
	 * Popis je izveden iz propisa, a ne iz kataloga: to su hrana, pice, kozmetika,
	 * sredstva za ciscenje, toaletne potrepstine i potrepstine za kucanstvo. Sve
	 * ostalo ima kasniji datum.
	 *
	 * Stoji odvojeno od ZAKONSKE_KATEGORIJE jer je to druga tvrdnja: ondje je popis
	 * skupina koje cjenik poznaje, ovdje popis onih kojima datum nije isti.
	 */
	const REGULIRANE_SKUPINE = array( 'hrana', 'pice', 'kozmetika', 'ciscenje', 'toaletno', 'kucanstvo' );

	/**
	 * Rjecnik marki: kanonski oblik => oblici kako se pojavljuju u nazivima.
	 *
	 * Izmjereno na ovom katalogu: pokriva 363 od 691 proizvoda (52,5 %). Prvi oblik
	 * u nizu je ujedno onaj koji se upisuje, pa se razlicita pisanja istog brenda
	 * (Copag i COPAG) svedu na jedno.
	 *
	 * Rjecnik je POMOC, ne odluka. Sve sto iz njega izade ide na reviziju.
	 */
	const MARKE_RJECNIK = array(
		'Bicycle'     => array( 'Bicycle' ),
		'Piatnik'     => array( 'Piatnik' ),
		'Copag'       => array( 'Copag', 'COPAG' ),
		'Dal Negro'   => array( 'Dal Negro', 'Dal-Negro', 'DalNegro' ),
		'Modiano'     => array( 'Modiano', 'MODIANO' ),
		'Theory11'    => array( 'Theory11', 'Theory 11' ),
		'Cartamundi'  => array( 'Cartamundi' ),
		'Hudica'      => array( 'Hudica' ),
		'Fournier'    => array( 'Fournier' ),
		'Tally-Ho'    => array( 'Tally-Ho', 'Tally Ho', 'Tally' ),
		'KEM'         => array( 'KEM' ),
		'Ellusionist' => array( 'Ellusionist' ),
		'Maverick'    => array( 'Maverick' ),
		'Aviator'     => array( 'Aviator' ),
		'Da Vinci'    => array( 'Da Vinci', 'DaVinci' ),
	);

	/**
	 * Izvori podatka o proizvodu, od najjaceg prema najslabijem.
	 *
	 * Namjerno nose vlastiti prefiks: `IZVOR_*` bez njega vec znaci odakle dolazi
	 * DODATNA CIJENA, a to je posve druga stvar. Dva znacenja pod istim prefiksom
	 * prije ili poslije se pomijesaju u pozivu.
	 */
	const IZVOR_PODATKA_RUCNO      = 'rucno';
	const IZVOR_PODATKA_CSV        = 'csv';
	const IZVOR_PODATKA_META       = 'meta';
	const IZVOR_PODATKA_TAKSONOMIJA = 'taksonomija';
	const IZVOR_PODATKA_ATRIBUT    = 'atribut';
	/**
	 * Dostavna tezina.
	 *
	 * NIJE u automatskom lancu izvora — sluzi samo kao prijedlog covjeku. Vidi
	 * `Podaci\Izvori::prijedlog_iz_tezine()` za obrazlozenje. Konstanta postoji jer
	 * se prijedlog na ekranu mora moci imenovati.
	 */
	const IZVOR_PODATKA_TEZINA     = 'tezina';
	const IZVOR_PODATKA_DIMENZIJE  = 'dimenzije';
	const IZVOR_PODATKA_NAZIV      = 'naziv';

	/**
	 * Naslijedeno od roditeljskog proizvoda.
	 *
	 * Vrijedi samo za varijacije i samo za podatke koji su po definiciji isti za
	 * cijeli proizvod — marka i kategorija. Barkod i neto kolicina se NE nasljeduju:
	 * majica S i majica XXL imaju razlicite barkodove, a boca 0,5 l i 1 l razlicitu
	 * kolicinu. Naslijediti ih znacilo bi upisati tvrdnju koja je za vecinu varijanti
	 * netocna.
	 */
	const IZVOR_PODATKA_RODITELJ = 'roditelj';

	/**
	 * Snaga izvora. Veci broj pobjeduje kad se dva izvora razilaze.
	 *
	 * Rucni unos i CSV od dobavljaca iznad su svega sto je stroj izveo. Parsiranje
	 * iz naziva je najslabije i nikad ne prepisuje nista drugo.
	 */
	const SNAGA_IZVORA_PODATKA = array(
		self::IZVOR_PODATKA_RUCNO       => 100,
		self::IZVOR_PODATKA_CSV         => 90,
		self::IZVOR_PODATKA_META        => 60,
		self::IZVOR_PODATKA_TAKSONOMIJA => 55,
		self::IZVOR_PODATKA_ATRIBUT     => 40,
		self::IZVOR_PODATKA_RODITELJ    => 38,
		self::IZVOR_PODATKA_DIMENZIJE   => 30,
		self::IZVOR_PODATKA_NAZIV       => 10,
	);

	/* ---------------------------------------------------------------------
	 * Modul 4 — generator cjenika
	 *
	 * Odluka NE propisuje shemu ni imena elemenata, samo popis podataka i oblik
	 * (.xml ili .csv). Zato shema stoji ovdje, a ne utipkana po kodu: kad se
	 * zahtjev promijeni ili se pojavi sluzbena shema, mijenja se jedan popis.
	 * ------------------------------------------------------------------ */

	/** Oblici datoteke koje odluka dopusta. */
	const CJENIK_XML = 'xml';
	const CJENIK_CSV = 'csv';

	/** Koji se oblici generiraju. Oba, jer odluka dopusta oba a ne kosta vise. */
	const CJENIK_OBLICI = array( self::CJENIK_XML, self::CJENIK_CSV );

	/** Koliko dana arhive se cuva. */
	const CJENIK_ARHIVA_DANA = 30;

	/** Korijenski element XML-a i element jednog artikla. */
	const CJENIK_KORIJEN = 'cjenik';
	const CJENIK_ARTIKL  = 'artikl';

	/**
	 * Shema zapisa cjenika.
	 *
	 * IZVOR: NN 101/2026, Odluka o objavi cjenika proizvoda i usluga, tocka III.
	 * Donesena 10.9.2026., na snazi od 1.10.2026.
	 *
	 * JEDNA TABLICA, NE DIRANJE GENERATORA
	 *
	 * Ovo je drugi put da se popis polja promijenio. Prvi put je izmjena prosla
	 * lako jer svi citatelji sheme citaju ovu tablicu — ali DODAVANJE polja i dalje
	 * je trazilo izmjenu u generatoru, jer je ondje stajao utipkan popis vrijednosti.
	 *
	 * Sada je i to ovdje: svaki kljuc odgovara metodi `Redak::polje_<kljuc>()`.
	 * Dodati polje znaci dodati redak ovdje i jednu malu metodu ondje; maknuti ga
	 * znaci obrisati redak. Generator se ne dira ni u jednom slucaju.
	 *
	 * `uvjetno` — polje koje se za dio artikala NE PRIMJENJUJE.
	 *
	 * Odluka za dva polja kaze "ako je primjenjivo". To nije isto sto i "ako ga
	 * znamo":
	 *
	 *   izostavljeno  = za ovaj artikl taj podatak ne postoji (spil karata nema
	 *                   cijenu po kilogramu)
	 *   prazno        = podatak postoji, ali ga mi ne znamo
	 *
	 * Producent vraca `null` za prvo i `''` za drugo. U XML-u se prvo IZOSTAVLJA,
	 * drugo izlazi kao prazan element. U CSV-u je i jedno i drugo prazna celija —
	 * tablica ne moze imati redak s manje stupaca, i to je granica tog oblika.
	 *
	 * ZASTO JE `sidrena_datum` OVDJE IAKO GA POPIS IZ TOCKE III NE NAVODI
	 *
	 * Ranije je zapisano pravilo: nepropisano polje se ne dodaje, jer citatelj
	 * propisane objave pretpostavlja da je svako polje ondje zato sto ga propis
	 * trazi. To pravilo i dalje vrijedi — ovo nije iznimka od njega.
	 *
	 * `sidrena_cijena` je tvrdnja o CIJENI NA ODREDENI DATUM. Za gotovo sve artikle
	 * taj je datum opci referentni i ne treba ga pisati. Za artikl uveden poslije
	 * njega datum je drugi (vidi `IZVOR_PRVA_CIJENA`), pa brojka bez datuma tvrdi
	 * nesto sto nije istina.
	 *
	 * Zato je polje UVJETNO: izostavljeno je svugdje gdje vrijedi opci datum, a
	 * pojavljuje se samo ondje gdje bi brojka inace lagala. Ne dodaje nikakav novi
	 * podatak — samo cuva tocnost onoga koji propis trazi.
	 */
	const SHEMA_CJENIKA = array(
		'naziv'                 => array( 'element' => 'naziv' ),
		'sifra'                 => array( 'element' => 'sifra' ),
		'marka'                 => array( 'element' => 'marka' ),
		'jedinica_mjere'        => array( 'element' => 'jedinica_mjere', 'uvjetno' => true ),
		'cijena_za_jedinicu'    => array( 'element' => 'cijena_za_jedinicu_mjere', 'uvjetno' => true ),
		'maloprodajna_cijena'   => array( 'element' => 'maloprodajna_cijena' ),
		'posebni_oblik'         => array( 'element' => 'posebni_oblik_prodaje' ),
		'naziv_posebnog_oblika' => array( 'element' => 'naziv_posebnog_oblika_prodaje' ),
		'sidrena_cijena'        => array( 'element' => 'sidrena_cijena' ),
		'sidrena_datum'         => array( 'element' => 'sidrena_cijena_datum', 'uvjetno' => true ),
		'barkod'                => array( 'element' => 'barkod' ),
		'dostupnost'            => array( 'element' => 'dostupnost' ),
	);

	/**
	 * Shema kao kljuc => ime elementa.
	 *
	 * Postoji da citatelji sheme ostanu jednostavni: pisac, samoprovjera i
	 * dijagnostika ne trebaju znati nista o uvjetnosti.
	 *
	 * @return array<string,string>
	 */
	public static function shema(): array {
		$izlaz = array();

		foreach ( self::SHEMA_CJENIKA as $kljuc => $meta ) {
			$izlaz[ $kljuc ] = (string) $meta['element'];
		}

		return $izlaz;
	}

	/** Smije li se ovo polje izostaviti kad se ne primjenjuje. */
	public static function shema_uvjetno( string $kljuc ): bool {
		return ! empty( self::SHEMA_CJENIKA[ $kljuc ]['uvjetno'] );
	}

	/**
	 * Dostupnost artikla — vrijednosti koje odluka trazi.
	 *
	 * Polje je dvovrijednosno. Trece stanje se ne izmislja, jer bi citatelj morao
	 * pogadati sto znaci.
	 */
	const DOSTUPNO   = 'dostupno';
	const NEDOSTUPNO = 'nedostupno';

	/**
	 * Roba na cekanju racuna se kao DOSTUPNA.
	 *
	 * Cjenik odgovara na pitanje "mogu li ovo kupiti po ovoj cijeni". Artikl u
	 * predbiljezbi kupac moze naruciti i bit ce mu naplacen — trgovina ga aktivno
	 * prodaje. Oznaciti ga kao nedostupan znacilo bi objaviti da se ne prodaje
	 * nesto sto se prodaje.
	 *
	 * Rok isporuke je druga tvrdnja i odluka je ne trazi.
	 */
	const CEKANJE_JE_DOSTUPNO = true;

	/** Da/ne, za polje o posebnom obliku prodaje. */
	const DA = 'da';
	const NE = 'ne';

	/**
	 * Redovna cijena vise NIJE polje cjenika.
	 *
	 * Raniji popis ju je trazio kao zaseban podatak; NN 101/2026 je ne navodi.
	 * Maloprodajna cijena sada nosi samo podatak JE LI iz posebnog oblika prodaje
	 * i KAKO se taj oblik zove.
	 *
	 * Ne dodajemo je natrag na svoju ruku: citatelj propisane objave pretpostavlja
	 * da je svako polje ondje zato sto ga propis trazi, pa neprописano polje nosi
	 * tvrdnju koju nitko nije trazio. Ako je trgovac zeli objaviti, to je njegova
	 * odluka — i sada je jedan redak u tablici iznad, uz metodu koja vec postoji.
	 */
	const REDOVNA_CIJENA_U_CJENIKU = false;

	/**
	 * Podaci o prodajnom objektu, za ime datoteke.
	 *
	 * Odluka trazi oblik objekta, adresu, oznaku i broj pohrane. Ta su polja pisana
	 * za fizicku prodavaonicu, pa za webshop treba prijevod — obrazlozenje je u
	 * ANALIZA.md, sekcija V.
	 *
	 * `adresa` je DOMENA, ne ulica: prodajno mjesto webshopa je web adresa, a ne
	 * sjediste tvrtke. Kupac na sjedistu ne moze nista kupiti.
	 *
	 * ADRESA SE NE UTIPKAVA — CITA SE IZ SAME TRGOVINE
	 *
	 * Utipkana domena je tocna na okolini na kojoj je napisana i netocna svuda
	 * drugdje. Dev kopija i produkcija imaju razlicite domene, pa bi objavljena
	 * datoteka na produkciji nosila ime dev kopije — i to u propisanoj objavi, kao
	 * tvrdnja o tome koji je objekt u pitanju. Konstanta ostaje kao jedino mjesto
	 * gdje se adresa smije nadglasati; prazna znaci "uzmi adresu trgovine".
	 */
	const OBJEKT_OBLIK  = 'webshop';
	const OBJEKT_ADRESA = '';
	const OBJEKT_OZNAKA = 'WEB1';

	/** Adresa prodajnog objekta: nadglasana vrijednost ili domena same trgovine. */
	public static function objekt_adresa(): string {
		if ( '' !== self::OBJEKT_ADRESA ) {
			return self::OBJEKT_ADRESA;
		}

		$domena = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $domena ) ? $domena : '';
	}

	/** Brojac pohrane — raste sa svakom objavljenom datotekom. */
	const OPT_BROJ_POHRANE = 'cjenik_broj_pohrane';

	/** Zadnja uspjesna objava: brojke i vrijeme. */
	const OPT_ZADNJI_CJENIK = 'cjenik_zadnji';

	/** REST prostor imena i ruta. */
	const REST_PROSTOR = 'cjenik/v1';
	const REST_RUTA    = 'danas';

	/**
	 * Najvise decimala koje maloprodajna cijena smije imati.
	 *
	 * Generator NE zaokruzuje — cita vrijednost kakva jest. Ali cijenu s vise
	 * decimala PRIJAVLJUJE, jer tiho zaokruzena brojka u propisanoj objavi nije
	 * ista stvar kao zaokruzena na ekranu. Politiku primjenjuje zaseban posao.
	 */
	const CJENIK_MAX_DECIMALA = 2;

	/* ---------------------------------------------------------------------
	 * Skupine poslova
	 *
	 * Ekran obrade nije popis nego redoslijed. Devet poslova u jednom nizu, svi
	 * jednakog izgleda, administratoru ne kazu sto kliknuti prvo — a moduli koji
	 * dolaze donose jos poslova na isti ekran.
	 * ------------------------------------------------------------------ */

	/** Jednokratno, izvodi se redom, jednom pa nikad vise. */
	const SKUPINA_PRIPREMA = 'priprema';

	/** Povremeno ili automatski. */
	const SKUPINA_ODRZAVANJE = 'odrzavanje';

	/** Samo ako je nesto poslo po zlu. Ne stoji ravnopravno uz ostalo. */
	const SKUPINA_VRACANJE = 'vracanje';

	/**
	 * Kako se skupine prikazuju, i kojim redom.
	 *
	 * `skriveno` znaci da skupina stoji iza "Napredno" i otvara se tek na zahtjev —
	 * vracanje se ne nudi onome tko ga ne treba.
	 */
	const SKUPINE = array(
		self::SKUPINA_PRIPREMA   => array(
			'naslov'   => 'Priprema',
			'uvod'     => 'Izvodi se redom, jednom. Kad prode, ovdje vise nema sto raditi.',
			'skriveno' => false,
		),
		self::SKUPINA_ODRZAVANJE => array(
			'naslov'   => 'Redovan rad',
			'uvod'     => 'Radi se povremeno ili samo od sebe. Ne treba ga pokretati rucno osim ako nesto provjeravate.',
			'skriveno' => false,
		),
		self::SKUPINA_VRACANJE   => array(
			'naslov'   => 'Ponistavanje',
			'uvod'     => 'Vraca ucinak vec izvedenog posla. Otvorite samo ako treba nesto vratiti.',
			'skriveno' => true,
		),
	);

	/** Hook za Action Scheduler i wp-cron. */
	const HOOK_KOMAD = 'obradi_komad';

	/** AJAX akcija za rucno pokretanje iz admina. */
	const AJAX_KOMAD = 'obradi_komad';

	/* ---------------------------------------------------------------------
	 * Direktoriji
	 * ------------------------------------------------------------------ */

	/** Poddirektorij unutar uploads u koji plugin pise. Ovdje ide JAVNI cjenik. */
	const UPLOAD_PODDIR = 'cjenik';

	/**
	 * Sto svaka skupina znaci, jezikom netehnicke osobe.
	 *
	 * Na jednom mjestu jer isti tekst citaju i zapisnik posla i ekran pregleda.
	 * naslov = kratko ime skupine, opis = sto korisnik treba znati i uciniti.
	 */
	const OPIS_IZVORA = array(
		self::IZVOR_POVIJEST => array(
			'naslov' => 'Utvrdeno iz zapisa',
			'opis'   => 'Za ove artikle postoji zapis koliko je cijena iznosila na referentni datum. Dodatna cijena je upisana i ne treba nista poduzeti.',
		),
		self::IZVOR_POVIJEST_POTVRDENA_REDOVNOM => array(
			'naslov' => 'Utvrdeno i potvrdeno',
			'opis'   => 'Kod ovih artikala cijena je ranije oscilirala. Oscilacija je uklonjena, a redovna cijena uskladena s onom iz zapisa — obje kazu isto, pa je dodatna cijena upisana i vise ne treba nista poduzeti.',
		),
		self::IZVOR_ODLUKA_POTVRDENA_POVIJESCU => array(
			'naslov' => 'Utvrdeno odlukom, potvrdeno zapisom',
			'opis'   => 'Ovi artikli bili su vodeni kao akcija, a vlasnik trgovine potvrdio je da je cijena koja se naplacuje njegova redovna cijena. Akcija je ukinuta, a zapis o cijenama potvrduje istu vrijednost. Dodatna cijena je upisana i ne treba nista poduzeti.',
		),
		self::IZVOR_ODLUKA_TRGOVCA => array(
			'naslov' => 'Utvrdeno vasom odlukom',
			'opis'   => 'Ovi artikli bili su na akciji na referentni datum, pa su imali dvije moguce sidrene cijene. Odlucili ste koja od njih vrijedi i ta je upisana.',
		),
		self::IZVOR_TRAZI_ODLUKU => array(
			'naslov' => 'Ceka vasu odluku',
			'opis'   => 'Ovi artikli bili su na akciji na referentni datum. Treba odluciti racuna li se kao dodatna cijena redovna ili akcijska — o tome je zaseban dokument.',
		),
		self::IZVOR_ARTEFAKT_OSCILACIJE => array(
			'naslov' => 'Cijena oscilira',
			'opis'   => 'Kod ovih artikala cijena svaki dan skace gore-dolje jer je akcija zavrsila a akcijska cijena ostala postavljena. Zapis za referentni datum zato ovisi o trenutku. Treba samo potvrditi koja je vrijednost tocna.',
		),
		self::IZVOR_RUCNI_UNOS => array(
			'naslov' => 'Treba unijeti rucno',
			'opis'   => 'Za ove artikle ne postoji zapis o cijeni na referentni datum. Dodatnu cijenu treba unijeti iz drugog izvora — racuna, cjenika dobavljaca ili vlastite evidencije.',
		),
		self::IZVOR_NAKON_REF_DATUMA => array(
			'naslov' => 'Nastali nakon referentnog datuma',
			'opis'   => 'Ovi artikli uvedeni su nakon referentnog datuma, pa dodatna cijena za njih ne postoji i ne moze postojati. Polje ostaje prazno i to je tocan ishod, ne propust.',
		),
	);

	/**
	 * Radni popisi — sastavljaju se pri preuzimanju, NE stoje na disku.
	 *
	 * izvor => ime datoteke koja se nudi korisniku
	 */
	const RADNI_POPISI = array(
		self::IZVOR_TRAZI_ODLUKU        => 'odluka-klijenta',
		self::IZVOR_RUCNI_UNOS          => 'rucni-unos',
		self::IZVOR_ARTEFAKT_OSCILACIJE => 'oscilacija-cijene',
		self::IZVOR_NAKON_REF_DATUMA    => 'uvedeni-poslije-bez-pocetne-cijene',
	);

	/* ---------------------------------------------------------------------
	 * Hookovi koje pratimo radi otkrivanja sukoba.
	 *
	 * VRSTA je bitna i ne smije se pomijesati:
	 *
	 *   prikaz     — mijenja KAKO je cijena prikazana. Broj koji se naplacuje
	 *                ostaje isti.
	 *   vrijednost — MOZE promijeniti broj koji se naplacuje. Zakaceno na taj
	 *                filter ne znaci da ga i mijenja; znaci samo da moze.
	 *                Mijenja li ga stvarno, utvrduje se mjerenjem (Cijene\Straza).
	 *
	 * Ranija verzija izvjestaja pisala je "vrijednost cijene" i za dodatke koji
	 * su samo zakaceni na filter — to je citatelja navelo da misle kako mu dodatak
	 * mijenja cijene. Zato vrsta i izmjereni nalaz idu zajedno.
	 * ------------------------------------------------------------------ */

	const VRSTA_PRIKAZ     = 'prikaz';
	const VRSTA_VRIJEDNOST = 'vrijednost';
	const VRSTA_PISANJE    = 'pisanje';

	const HOOKOVI_CIJENE = array(
		'woocommerce_get_price_html'            => array( 'vrsta' => self::VRSTA_PRIKAZ, 'opis' => 'prikaz cijene' ),
		'woocommerce_variable_price_html'       => array( 'vrsta' => self::VRSTA_PRIKAZ, 'opis' => 'prikaz cijene varijabilnog proizvoda' ),
		'woocommerce_product_is_on_sale'        => array( 'vrsta' => self::VRSTA_PRIKAZ, 'opis' => 'oznaka akcije' ),
		'woocommerce_product_get_price'         => array( 'vrsta' => self::VRSTA_VRIJEDNOST, 'opis' => 'cijena artikla' ),
		'woocommerce_product_get_regular_price' => array( 'vrsta' => self::VRSTA_VRIJEDNOST, 'opis' => 'redovna cijena' ),
		'woocommerce_product_get_sale_price'    => array( 'vrsta' => self::VRSTA_VRIJEDNOST, 'opis' => 'akcijska cijena' ),
		'woocommerce_cart_item_price'           => array( 'vrsta' => self::VRSTA_VRIJEDNOST, 'opis' => 'cijena u kosarici' ),

		/*
		 * Hookovi na kojima se cijena ZAPISUJE.
		 *
		 * Zasto su ovdje: dodatak moze voditi vlastitu evidenciju cijena a da se
		 * nikad ne zakaci na filter prikaza — npr. ako mu je prikaz konfiguriran
		 * na drugi nacin. Takav dodatak bi bez ovih hookova bio nevidljiv, iako
		 * aktivno radi s cijenama. Upravo to se dogodilo na produkciji s
		 * dodatkom koji vodi povijest cijena.
		 */
		'woocommerce_update_product'            => array( 'vrsta' => self::VRSTA_PISANJE, 'opis' => 'reagira na spremanje artikla' ),
		'woocommerce_update_product_variation'  => array( 'vrsta' => self::VRSTA_PISANJE, 'opis' => 'reagira na spremanje varijante' ),
		'woocommerce_before_product_object_save' => array( 'vrsta' => self::VRSTA_PISANJE, 'opis' => 'reagira prije spremanja artikla' ),
		'woocommerce_product_object_updated_props' => array( 'vrsta' => self::VRSTA_PISANJE, 'opis' => 'prati promjene svojstava artikla' ),
	);

	/** Opcija u koju se sprema snimka hookova vidjenih na frontendu. */
	const OPT_SNIMKA_HOOKOVA = 'snimka_hookova';

	/** Koliko sati snimka s frontenda vrijedi prije nego ju treba osvjeziti. */
	const SNIMKA_VRIJEDI_SATI = 12;

	/**
	 * Konstante kojima se dodacima mijenja nacin prikaza cijene.
	 *
	 * Zasto se ispisuju: dodatak konfiguriran na drugi nacin prikaza kaci se na
	 * drugi hook, pa ga pregled po hookovima moze promasiti. Ispis vrijednosti
	 * pretvara nagadanje o uzroku u jedan redak izvjestaja.
	 *
	 * konstanta => dodatak na koji se odnosi
	 */
	const KONSTANTE_PRIKAZA = array(
		'WPLP_DISPLAY_TYPE'  => 'WooCommerce Lowest Price',
		'WPLP_VARIANT_LOOP'  => 'WooCommerce Lowest Price',
	);

	/**
	 * Izvori koji se ne prijavljuju kao sukob.
	 *
	 * WooCommerce filtrira vlastite cijene kroz vlastite filtere — to je normalan
	 * rad jezgre, ne dodatak koji se umijesao.
	 */
	const IZUZETI_IZVORI = array( 'woocommerce' );

	/**
	 * Dodaci koji uz cijenu prikazuju najnizu cijenu u 30 dana.
	 *
	 * Njihov zahvat u prikaz cijene se uklanja i ulogu preuzimamo mi — vidi
	 * Prikaz\Preuzimanje_Uloge za obrazlozenje.
	 *
	 * Usporeduje se PREFIKS, ne cijeli slug. Isti dodatak zna zivjeti pod vise
	 * imena direktorija: instalacija iz zip arhive doda sufiks, sluzbeno azuriranje
	 * ga makne. Tocno se to i dogodilo — dodatak je bio u mapi
	 * `woocommerce-lowest-price-main`, a nakon azuriranja u `woocommerce-lowest-price`,
	 * pa ga provjera po cijelom slugu vise nije nalazila.
	 */
	const DODACI_NAJNIZE_CIJENE = array(
		'woocommerce-lowest-price',
	);

	/**
	 * Dodaci koji spajaju vise proizvoda u jedan paket.
	 *
	 * Oni se kace na filtere cijene, ali ne mijenjaju samostalne kataloske cijene —
	 * formiraju cijenu paketa. U izvjestaju idu u vlastitu skupinu jer bi pod
	 * "dira cijene" bili obmanjujuci.
	 *
	 * slug dodatka => tip proizvoda koji registrira
	 */
	const DODACI_PAKETA = array(
		'woocommerce-mix-and-match-products' => 'mix-and-match',
		'woocommerce-product-bundles'        => 'bundle',
		'woocommerce-composite-products'     => 'composite',
	);

	/* ---------------------------------------------------------------------
	 * Izvedena imena
	 * ------------------------------------------------------------------ */

	/** Puno ime tablice, s WordPress prefiksom. */
	public static function table( string $logicko ): string {
		global $wpdb;
		return $wpdb->prefix . self::PREFIX . '_' . $logicko;
	}

	/** Puno ime naslijedene tablice, s WordPress prefiksom. */
	public static function naslijedena_table( string $staro ): string {
		global $wpdb;
		return $wpdb->prefix . $staro;
	}

	public static function option( string $ime ): string {
		return self::PREFIX . '_' . $ime;
	}

	public static function meta( string $ime ): string {
		return '_' . self::PREFIX . '_' . $ime;
	}

	public static function hook( string $ime ): string {
		return self::PREFIX . '_' . $ime;
	}

	/** CSS klasa i HTML id. */
	public static function css( string $ime ): string {
		return self::PREFIX . '-' . $ime;
	}

	/** Slug admin stranice. */
	public static function stranica( string $ime ): string {
		return self::PREFIX . '-' . $ime;
	}

	/** Nonce akcija. */
	public static function nonce( string $ime ): string {
		return self::PREFIX . '_nonce_' . $ime;
	}

	/** Sposobnost potrebna za pristup ekranima plugina. */
	public static function sposobnost(): string {
		return 'manage_woocommerce';
	}

	/** Apsolutni put do poddirektorija u uploads. */
	public static function upload_dir(): string {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] ) . self::UPLOAD_PODDIR;
	}

	/**
	 * Radni direktorij, zasticen od izravnog dohvata.
	 *
	 * Na Apacheu (cPanel) .htaccess zabranjuje pristup. Na nginxu .htaccess nema
	 * ucinka — zato i nasumican dio u imenu datoteke, da se putanja ne moze
	 * pogoditi. Nijedna od te dvije mjere sama nije dovoljna.
	 */
	public static function radni_dir(): string {
		$dir = trailingslashit( self::upload_dir() ) . self::UPLOAD_RADNO;

		if ( wp_mkdir_p( $dir ) ) {
			$ht = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $ht ) ) {
				file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
			}
			$idx = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $idx ) ) {
				file_put_contents( $idx, "<?php // Silence is golden.\n" ); // phpcs:ignore
			}
		}

		return $dir;
	}

	/** Javni URL poddirektorija u uploads. */
	public static function upload_url(): string {
		$u = wp_upload_dir();
		return trailingslashit( $u['baseurl'] ) . self::UPLOAD_PODDIR;
	}
}
