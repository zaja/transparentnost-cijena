<?php
/**
 * Zakacivanje dodatne cijene uz prikaz cijene proizvoda.
 *
 * OPSEG
 *
 * Dodatak radi iskljucivo s cijenama unesenima u proizvod. Cijene utipkane u
 * sadrzaj stranica — baneri, elementi graditelja stranica, opisi, odredisne
 * stranice — odgovornost su vlasnika trgovine; dodatak ih ne dira i ne trazi.
 *
 * PRIORITET 1100
 *
 * Mjesto 1000 na `woocommerce_get_price_html` zauzima dodatak za najnizu cijenu.
 * Trenutno radi u nacinu u kojem cijenu propusta nepromijenjenu, ali to ovisi o
 * retku u wp-config.php koji vlasnik moze promijeniti. Nas filter zato ide IZA
 * njega, da ga promjena te postavke ne moze prepisati.
 *
 * @package CJTR
 */

namespace CJTR\Prikaz;

use CJTR\Config;
use CJTR\Postavke;
use CJTR\Povijest\Dubina;
use CJTR\Povijest\Zapis;

defined( 'ABSPATH' ) || exit;

final class Prikaz {

	/** Iza svih zatecenih zahvata u prikaz cijene. */
	const PRIORITET = 1100;

	/** @var array<int,array|null> procitano u ovom zahtjevu */
	private static $predmemorija = array();

	/**
	 * Koliko je puta u ovom zahtjevu prikaz doista ispisan.
	 *
	 * Broji se da bi se znalo treba li rezerva. Nula na stranici koja prikazuje
	 * cijene znaci da tema ide mimo `get_price_html()` — vidi `Prikaz\Rezerva`.
	 */
	private static $ispisano = 0;

	public static function ispisano(): int {
		return self::$ispisano;
	}

	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'uz_cijenu' ), self::PRIORITET, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'stilovi' ) );
	}

	/**
	 * @param string      $html
	 * @param \WC_Product $proizvod
	 */
	public static function uz_cijenu( $html, $proizvod ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}
		if ( ! is_object( $proizvod ) || ! method_exists( $proizvod, 'get_id' ) ) {
			return $html;
		}

		$podaci = self::podaci( $proizvod );
		if ( null === $podaci ) {
			return $html;
		}

		$dodatak = Render::html( $podaci );

		if ( '' === $dodatak ) {
			return $html;
		}

		self::$ispisano++;

		return $html . $dodatak;
	}

	/**
	 * Sto se prikazuje za dani proizvod.
	 *
	 * @return array|null null ako nema sto prikazati
	 */
	private static function podaci( $proizvod ): ?array {
		$id = (int) $proizvod->get_id();

		if ( array_key_exists( $id, self::$predmemorija ) ) {
			return self::$predmemorija[ $id ];
		}

		$tip = method_exists( $proizvod, 'get_type' ) ? $proizvod->get_type() : '';

		$podaci = ( 'variable' === $tip )
			? self::za_varijabilni( $proizvod )
			: self::za_jedan( $id, $proizvod );

		self::$predmemorija[ $id ] = $podaci;
		return $podaci;
	}

	private static function za_jedan( int $id, $proizvod ): ?array {
		$sidrena = Sidrena::za( $id );

		/*
		 * Najniza u 30 dana ima smisla samo kad se oglasava snizenje. Bez akcije bi
		 * bila jednaka danasnjoj cijeni i samo bi zatrpala prikaz.
		 *
		 * Drugi uvjet tice se ISTINITOSTI, ne korisnosti: ako drugi dodatak vec
		 * prikazuje istu tvrdnju, sutimo. Dvije tvrdnje o istoj stvari gore su od
		 * nijedne, jer kupac ne zna kojoj vjerovati. To je postavka.
		 *
		 * TRECEG UVJETA VISE NEMA
		 *
		 * Do 1.2.0 se ovdje trazilo i da nasa evidencija seze punih trideset dana
		 * unatrag. Bilo je pogresno. "Najniza cijena u 30 dana" nije tvrdnja da je
		 * cijena stara trideset dana nego najmanja koja je u tom prozoru
		 * primijenjena — a ako je jedina zabiljezena ona od jucer, onda je ona i
		 * najmanja: druge u prozoru nije bilo.
		 *
		 * Uz to je taj uvjet stvarao gore stanje od onoga koji je htio sprijeciti:
		 * trgovina koja dodatak instalira danas ostajala bi trideset dana bez
		 * obveznog podatka, i to tiho.
		 *
		 * Ograda — da artikl prije nase prve biljeske nije bio jeftiniji — stoji na
		 * ekranu Stanje, dok evidencija ne pokrije puni prozor: prema kupcu se ne
		 * suti, prema trgovcu se ne presucuje.
		 *
		 * `Zapis::najniza()` vraca null kad za artikl nema nijednog zapisa u
		 * prozoru — tada se i dalje ne prikazuje nista, jer nema sto.
		 *
		 * Dodatna cijena se ovime NE dira ni u jednom slucaju.
		 */
		$najniza = null;
		if ( Postavke::najniza_30()
			&& method_exists( $proizvod, 'is_on_sale' ) && $proizvod->is_on_sale() ) {
			$najniza = Zapis::najniza( $id, Dubina::DANA );
		}

		if ( null === $sidrena && null === $najniza ) {
			return null;
		}

		return array(
			'sidrena'    => $sidrena,
			'najniza_30' => $najniza,
			'ref_datum'  => self::ref_datum( $id ),
		);
	}

	/**
	 * Varijabilni roditelj, bez odabrane varijante.
	 *
	 * ODLUKA: brojka se prikazuje samo ako je IMA SVAKA varijanta i ako je svima
	 * ISTA.
	 *
	 * Raspon se ne prikazuje jer "dodatna cijena od 5 do 12 eura" nije tvrdnja ni
	 * o jednom artiklu — kupac kupuje jednu varijantu, ne raspon. Kad se varijante
	 * razlikuju, prikaz se pojavljuje tek pri odabiru, isto kao i sama cijena.
	 *
	 * ZASTO NAJNIZA U 30 DANA DO 1.2.1 OVDJE NIJE IZLAZILA
	 *
	 * Stajalo je tvrdo `'najniza_30' => null`, uz sidrenu koja se racunala. Na
	 * varijabilnom proizvodu se zato uz cijenu vidjela sidrena, a najniza nikad.
	 *
	 * Samo po sebi to se cinilo bezopasnim — "pojavit ce se kad kupac odabere
	 * varijantu". Ne pojavi se: kad sve varijante imaju istu cijenu,
	 * `WC_Product_Variable::get_available_variation()` vraca PRAZAN `price_html`
	 * (jer je min === max), pa se blok s cijenom varijante uopce ne iscrta i nas
	 * filter nad njom nikad ne prode. Ostane samo roditelj — a on je sutio.
	 *
	 * Dvije tocke koje svaka za sebe izgledaju u redu, a zajedno daju tiho
	 * izostajanje obveznog podatka na cijeloj jednoj vrsti proizvoda.
	 *
	 * OSTAJE OTVORENO: varijante iste cijene ali razlicite povijesti. Tada sloge
	 * nema, roditelj suti, a blok varijante je prazan — pa se ne prikazuje nista.
	 * Rjesenje trazi dopunu `woocommerce_available_variation`, sto mijenja izgled
	 * i onima kojima danas radi; nije dio ovog popravka.
	 */
	private static function za_varijabilni( $proizvod ): ?array {
		$djeca = method_exists( $proizvod, 'get_children' ) ? (array) $proizvod->get_children() : array();
		if ( empty( $djeca ) ) {
			return null;
		}

		$djeca = array_map( 'intval', $djeca );

		$sidrena = self::sloga( Sidrena::za_vise( $djeca ), count( $djeca ) );

		/*
		 * Uvjet snizenja je i ovdje isti kao kod pojedinacnog artikla, samo strozi:
		 * mora vrijediti za SVE varijante. Roditelj kod kojeg je snizena samo jedna
		 * varijanta nije snizen kao artikl, pa uz njegovu cijenu ta brojka ne stoji.
		 */
		$najniza = null;
		if ( Postavke::najniza_30() && self::sve_na_akciji( $djeca ) ) {
			$najniza = self::sloga( Zapis::najnize_za( $djeca, Dubina::DANA ), count( $djeca ) );
		}

		if ( null === $sidrena && null === $najniza ) {
			return null;
		}

		/*
		 * Datum roditelja mora biti isti kao brojka koju opisuje: ako se varijante
		 * u datumu razilaze (jedna uvedena poslije referentnog datuma), roditelj
		 * nema jedan datum i sidrena se ne prikazuje.
		 */
		$datumi = array();
		foreach ( $djeca as $d ) {
			$datumi[ self::ref_datum( (int) $d ) ] = true;
		}

		if ( null !== $sidrena && 1 !== count( $datumi ) ) {
			$sidrena = null;
			if ( null === $najniza ) {
				return null;
			}
		}

		return array(
			'sidrena'    => $sidrena,
			'najniza_30' => $najniza,
			'ref_datum'  => ( 1 === count( $datumi ) ) ? (string) key( $datumi ) : Postavke::ref_datum(),
		);
	}

	/**
	 * Jedna vrijednost za sve varijante, ili nista.
	 *
	 * Nedostaje li ijednoj, roditelj suti — tvrdnja bi vrijedila samo za dio
	 * varijanti, a kupac ne vidi za koje.
	 *
	 * @param array<int,float> $vrijednosti
	 */
	private static function sloga( array $vrijednosti, int $koliko_ih_treba ): ?float {
		if ( count( $vrijednosti ) !== $koliko_ih_treba ) {
			return null;
		}

		$razlicite = array_unique(
			array_map(
				function ( $v ) {
					return round( (float) $v, 4 );
				},
				$vrijednosti
			)
		);

		return ( 1 === count( $razlicite ) ) ? (float) reset( $razlicite ) : null;
	}

	/**
	 * Jesu li SVE varijante na snizenju.
	 *
	 * Cita se iz `wc_product_meta_lookup`, jednim upitom — isto kao sve ostalo o
	 * cijenama. `$roditelj->is_on_sale()` ovdje ne valja: on je istinit vec kad je
	 * snizena JEDNA varijanta.
	 *
	 * @param int[] $ids
	 */
	private static function sve_na_akciji( array $ids ): bool {
		global $wpdb;

		if ( empty( $ids ) ) {
			return false;
		}

		$u = implode( ',', array_map( 'intval', $ids ) );

		$snizenih = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup
			 WHERE product_id IN ({$u}) AND onsale = 1" // phpcs:ignore
		);

		return $snizenih === count( $ids );
	}

	/**
	 * Datum na koji se sidrena cijena ovog artikla odnosi.
	 *
	 * TRI RAZINE, OD NAJTOCNIJE PREMA NAJOPCENITIJOJ
	 *
	 * 1. Datum upisan uz sam artikl. Za artikl uveden nakon referentnog datuma to
	 *    je datum kad je cijena formirana, i on se razlikuje od opceg.
	 * 2. Opci datum za njegovu zakonsku skupinu. Trgovina koja prodaje i hranu i
	 *    sve ostalo ima DVA, pa uz cijenu mora stajati onaj koji vrijedi za taj
	 *    artikl.
	 * 3. Opci datum trgovine, kad o artiklu jos nista ne znamo.
	 *
	 * Pogresan datum nije kozmeticka greska nego netocna tvrdnja na stranici
	 * proizvoda: brojka i datum zajedno cine jednu recenicu.
	 */
	private static function ref_datum( int $id ): string {
		global $wpdb;

		$r = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT zakonska_kategorija, referentni_datum
				 FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$id
			) // phpcs:ignore
		);

		if ( ! $r ) {
			return Postavke::ref_datum();
		}

		$vlastiti = (string) $r->referentni_datum;

		if ( '' !== $vlastiti && '0000-00-00' !== $vlastiti ) {
			return $vlastiti;
		}

		return Postavke::ref_datum( (string) $r->zakonska_kategorija );
	}

	/**
	 * Isprazni predmemoriju ovog zahtjeva.
	 *
	 * Postoji zbog mjerenja: provjera ispituje isti artikl u vise stanja zaredom, a
	 * bez ovoga bi svako sljedece mjerenje dobilo odgovor prvoga. U normalnom radu
	 * se ne poziva — unutar jednog zahtjeva stanje artikla se ne mijenja.
	 */
	public static function zaboravi(): void {
		self::$predmemorija = array();
	}

	public static function stilovi(): void {
		wp_enqueue_style(
			Config::css( 'prikaz' ),
			CJTR_URL . 'assets/prikaz.css',
			array(),
			Config::VERSION
		);
	}
}
