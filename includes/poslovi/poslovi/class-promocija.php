<?php
/**
 * Posao: akcijska cijena postaje redovna cijena.
 *
 * ODLUKA KOJU IZVRSAVA
 *
 * Vlasnik trgovine potvrdio je da je cijena koja se naplacuje njegova redovna
 * cijena, a ne akcija. Posao to zapisuje u podatke: redovna cijena postaje ona
 * koja se naplacuje, a akcijski podaci se uklanjaju.
 *
 * CIJENA KOJU KUPAC PLACA SE NE MIJENJA. To nije nusprodukt nego uvjet: ako se
 * `_price` promijeni, posao to prijavljuje kao GRESKU, ne kao uspjeh. Posao koji
 * tiho promijeni naplacivanu cijenu na 845 artikala gori je od posla koji stane.
 *
 * ZASTO NE `Posao_S_Cijenama`
 *
 * Straza sluzi poslovima koji cijene citaju da bi ih objavili. Ovaj pise metu u
 * 'edit' kontekstu, dakle bez filtera dodataka, i nista ne objavljuje.
 *
 * POVRATNO
 *
 * Prije upisa se stanje sve cetiri mete zapisuje u trag. Ne uspije li zapis,
 * artikl se NE dira. Vracanje radi posao "Vrati promociju".
 *
 * POTVRDA PRIJE POKRETANJA
 *
 * Posao se ne da pokrenuti dok potvrda nije zabiljezena na ekranu pregleda, gdje
 * stoji koja je cijena prije i koja poslije. Pouka iz slucaja s oscilirajucim
 * cijenama: ondje je redoslijed posao-pa-provjera posao naopako, i ispravan
 * ishod se pokazao tek naknadno. Ovdje se prvo gleda, pa radi.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Cijene\Promocija_Popis;
use CJTR\Config;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;
use CJTR\Povijest\Biljeznik;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Promocija extends Posao {

	/** Opcija u kojoj stoji potvrda s ekrana pregleda. */
	const OPT_POTVRDA = 'promocija_potvrda';

	public function kljuc(): string {
		return 'promocija';
	}

	public function naziv(): string {
		return __( 'Ukini trajne akcije (akcijska cijena postaje redovna)', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Kod artikala koji su godinama na "akciji" ta je cijena zapravo njihova redovna cijena. Posao to upisuje: redovna cijena postaje ona koja se naplacuje, a oznaka akcije i precrtana brojka nestaju. Cijena koju kupac placa se NE mijenja. Pokrece se tek nakon sto na ekranu "Akcijska u redovnu" pregledate popis i potvrdite.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}

		$potvrda = self::potvrda();
		if ( empty( $potvrda ) ) {
			return __( 'Potvrda jos nije dana. Otvorite ekran "Akcijska u redovnu", pregledajte cijene prije i poslije i potvrdite.', Config::TEXT_DOMAIN );
		}

		// Potvrda vrijedi za skup koji je bio pregledan. Naraste li skup u
		// meduvremenu — netko stavi novi artikl na akciju — potvrda ga ne pokriva.
		$sada = Promocija_Popis::broj();
		if ( (int) ( $potvrda['redaka'] ?? 0 ) !== $sada ) {
			return sprintf(
				/* translators: 1: broj pri potvrdi, 2: broj sada */
				__( 'Skup se promijenio otkad je potvrda dana: potvrdeno je %1$d redaka, sada ih je %2$d. Pregledajte i potvrdite ponovno.', Config::TEXT_DOMAIN ),
				(int) $potvrda['redaka'],
				$sada
			);
		}

		if ( 0 === $sada ) {
			return __( 'Nema artikala s akcijom koja tece — nema sto promovirati.', Config::TEXT_DOMAIN );
		}

		return '';
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 7;
	}

	public function preduvjet(): string {
		return 'sidrena_cijena';
	}

	public function preduvjet_razlog(): string {
		return __( 'Sidrena cijena mora prvo biti utvrdena, da se moze provjeriti slaze li se s cijenom koja se naplacuje.', Config::TEXT_DOMAIN );
	}

	public function ukupno(): int {
		return Promocija_Popis::broj();
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$ids = Promocija_Popis::komad( $zadnji_id, $velicina );

		if ( empty( $ids ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$rez = Rezultat_Komada::s( 0, max( $ids ) );

		// Cijeli komad ide pod okidacem promocije: zapis u povijesti mora reci da
		// je rijec o preimenovanju cijene, a ne o promjeni. Zatvara se na kraju
		// bloka, pa okidac ne moze iscuriti na neki drugi posao u istom zahtjevu.
		$obradenih = Biljeznik::pod_okidacem(
			Config::OKIDAC_PROMOCIJA,
			function () use ( $ids, $rez ) {
				$n = 0;
				foreach ( $ids as $id ) {
					if ( $this->jedan( $id, $rez ) ) {
						$n++;
					}
				}
				return $n;
			}
		);

		$rez->obradeno = (int) $obradenih;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();

		$ocisceno = \CJTR\Prikaz\Cache::ocisti();
		if ( ! empty( $ocisceno ) ) {
			$stanje->zapisi(
				'info',
				sprintf(
					/* translators: %s = popis dodataka */
					__( 'Ociscena predmemorija stranica: %s', Config::TEXT_DOMAIN ),
					implode( ', ', $ocisceno )
				)
			);
		}

		$stanje->zapisi(
			'info',
			__( 'Sljedeci korak: posao "Sidrena cijena nakon promocije" upisuje sidrenu cijenu tim artiklima.', Config::TEXT_DOMAIN )
		);
	}

	/* ------------------------------------------------------------ potvrda */

	/** @return array prazno ako potvrde nema */
	public static function potvrda(): array {
		$v = get_option( Config::option( self::OPT_POTVRDA ), array() );
		return is_array( $v ) ? $v : array();
	}

	/** Zabiljezi potvrdu zajedno sa skupom na koji se odnosi. */
	public static function potvrdi(): void {
		$sazetak = Promocija_Popis::sazetak();

		update_option(
			Config::option( self::OPT_POTVRDA ),
			array(
				'kad'       => current_time( 'mysql', true ),
				'tko'       => get_current_user_id(),
				'redaka'    => $sazetak['redaka'],
				'proizvoda' => $sazetak['proizvoda'],
			),
			false
		);
	}

	public static function povuci_potvrdu(): void {
		delete_option( Config::option( self::OPT_POTVRDA ) );
	}

	/* --------------------------------------------------------------- interno */

	/** @return bool je li artikl uspjesno obraden */
	private function jedan( int $id, Rezultat_Komada $rez ): bool {
		$proizvod = wc_get_product( $id );
		if ( ! $proizvod ) {
			$rez->preskoci( $id, __( 'artikl se nije mogao ucitati', Config::TEXT_DOMAIN ) );
			return false;
		}

		$prije = $this->snimi( $proizvod );

		// Artikl kod kojeg bi promocija proturjecila dodatnoj cijeni ne ide skupa
		// s ostalima. Provjera je i ovdje, ne samo na ekranu: skup se moze
		// promijeniti izmedu pregleda i izvodenja.
		if ( Promocija_Popis::nesuglasan( $id ) ) {
			$rez->preskoci( $id, __( 'naplacivana cijena ne odgovara sidrenoj cijeni — rjesava se zasebno', Config::TEXT_DOMAIN ) );
			return false;
		}

		if ( '' === $prije['price'] ) {
			$rez->preskoci( $id, __( 'nema zapisane cijene koja se naplacuje', Config::TEXT_DOMAIN ) );
			return false;
		}

		$trag_id = Trag::zapisi(
			$id,
			$this->kljuc(),
			$prije,
			__( 'odluka vlasnika: cijena koja se naplacuje je redovna cijena, akcija se ukida', Config::TEXT_DOMAIN )
		);

		if ( 0 === $trag_id ) {
			$rez->preskoci( $id, __( 'stanje se nije moglo zapisati u trag, pa artikl nije diran', Config::TEXT_DOMAIN ) );
			return false;
		}

		$proizvod->set_regular_price( $prije['price'] );
		$proizvod->set_sale_price( '' );
		$proizvod->set_date_on_sale_from( null );
		$proizvod->set_date_on_sale_to( null );
		$proizvod->save();

		$svjez   = wc_get_product( $id );
		$poslije = $svjez ? $this->snimi( $svjez ) : array();

		$this->zapisi( $id, $proizvod->get_name(), $prije, $poslije );

		foreach ( $this->odstupanja( $prije, $poslije ) as $opis ) {
			$rez->greska(
				sprintf(
					/* translators: 1: ID, 2: opis odstupanja */
					__( 'ID %1$d: ishod nije onakav kakav je obecan — %2$s', Config::TEXT_DOMAIN ),
					$id,
					$opis
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Sto je poslo po zlu.
	 *
	 * Prva stavka je najvaznija: naplacivana cijena se NE smije promijeniti. Ostale
	 * provjeravaju da je posao doista napravio ono sto tvrdi.
	 *
	 * @return string[] prazno ako je sve kako treba
	 */
	private function odstupanja( array $prije, array $poslije ): array {
		if ( empty( $poslije ) ) {
			return array( __( 'artikl se nakon spremanja nije mogao ponovno ucitati', Config::TEXT_DOMAIN ) );
		}

		$greske = array();

		if ( abs( (float) $prije['price'] - (float) $poslije['price'] ) > Config::TOLERANCIJA_POVIJESTI ) {
			$greske[] = sprintf(
				/* translators: 1: stara cijena, 2: nova cijena */
				__( 'cijena koja se naplacuje promijenila se s %1$s na %2$s', Config::TEXT_DOMAIN ),
				$prije['price'],
				$poslije['price']
			);
		}

		if ( abs( (float) $poslije['regular'] - (float) $prije['price'] ) > Config::TOLERANCIJA_POVIJESTI ) {
			$greske[] = sprintf(
				/* translators: 1: redovna cijena, 2: ocekivana */
				__( 'redovna cijena je %1$s umjesto %2$s', Config::TEXT_DOMAIN ),
				$poslije['regular'],
				$prije['price']
			);
		}

		if ( '' !== $poslije['sale'] ) {
			$greske[] = __( 'akcijska cijena nije uklonjena', Config::TEXT_DOMAIN );
		}

		if ( 0 !== $poslije['from_ts'] || 0 !== $poslije['to_ts'] ) {
			$greske[] = __( 'datumi akcije nisu uklonjeni', Config::TEXT_DOMAIN );
		}

		return $greske;
	}

	/**
	 * Stanje mete, onako kako je zapisano.
	 *
	 * Datumi kao SIROVI vremenski zig — formatirani gubi doba dana, pa povrat ne bi
	 * vratio istu vrijednost. Isti razlog kao kod ciscenja isteklih akcija.
	 */
	private function snimi( $proizvod ): array {
		$od = $proizvod->get_date_on_sale_from( 'edit' );
		$do = $proizvod->get_date_on_sale_to( 'edit' );

		return array(
			'price'   => (string) $proizvod->get_price( 'edit' ),
			'regular' => (string) $proizvod->get_regular_price( 'edit' ),
			'sale'    => (string) $proizvod->get_sale_price( 'edit' ),
			'from_ts' => $od ? (int) $od->getTimestamp() : 0,
			'to_ts'   => $do ? (int) $do->getTimestamp() : 0,
			'from'    => $od ? $od->date( 'Y-m-d H:i:s' ) : '',
			'to'      => $do ? $do->date( 'Y-m-d H:i:s' ) : '',
		);
	}

	private function zapisi( int $id, string $naziv, array $prije, array $poslije ): void {
		$stanje        = new Stanje();
		$stanje->kljuc = $this->kljuc();
		$stanje->zapisi(
			'info',
			sprintf(
				'ID %d "%s" | prije: naplacuje=%s redovna=%s akcijska=%s | poslije: naplacuje=%s redovna=%s akcijska=%s',
				$id,
				mb_substr( $naziv, 0, 40 ),
				$prije['price'],
				$prije['regular'],
				$prije['sale'],
				$poslije['price'] ?? '?',
				$poslije['regular'] ?? '?',
				'' === ( $poslije['sale'] ?? '' ) ? '(uklonjena)' : $poslije['sale']
			)
		);
	}

	/**
	 * Koliko je zapisa u vlastitoj povijesti nastalo promocijom.
	 *
	 * Sluzi provjeri da je nastao TOCNO JEDAN redak po entitetu. Vise redaka
	 * znacilo bi da je isti artikl spremljen dvaput, a to bi u povijesti izgledalo
	 * kao dvije promjene cijene ondje gdje se nije dogodila nijedna.
	 */
	public static function zapisa_u_povijesti(): array {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		$r = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS redaka, COUNT( DISTINCT entity_id ) AS entiteta
				 FROM `{$t}` WHERE okidac = %s",
				Config::OKIDAC_PROMOCIJA
			) // phpcs:ignore
		);

		return array(
			'redaka'   => (int) ( $r->redaka ?? 0 ),
			'entiteta' => (int) ( $r->entiteta ?? 0 ),
		);
	}
}
