<?php
/**
 * Posao: primijeni politiku zaokruzivanja na katalog.
 *
 * PREDUVJET ZA PRVU OBJAVU CJENIKA
 *
 * Dok se ne izvrsi, objavljena datoteka sadrzi zapise koji sami sebi proturjece:
 * artikl koji se prodaje po komadu ima maloprodajnu cijenu 3.849 i cijenu po
 * jedinici mjere 3.85 — dvije brojke za istu cijenu. Izmjereno: 49 takvih.
 *
 * Generator to NE popravlja i ne smije: zaokruzivanje u generatoru znacilo bi da
 * cjenik i trgovina prikazuju razlicite brojke, a to je tocno problem koji
 * rjesavamo.
 *
 * FLOOR, I SVE U ISTOM PROLAZU
 *
 * `_price`, `_regular_price`, `_sale_price` i dodatna cijena — odjednom. Kad bi se
 * tekuca cijena zaokruzila danas a dodatna sutra, nastala bi umjetna razlika koja
 * izgleda kao promjena cijene i pokvarila bas onu dodatnu cijenu koju gradimo.
 *
 * IDE PRIJE UKIDANJA TRAJNIH AKCIJA, NE POSLIJE
 *
 * Raniji plan stavljao ga je iza promocije, uz obrazlozenje da bi inace promocija
 * prenijela nezaokruzenu vrijednost u redovnu cijenu. To vrijedi samo ako se
 * zaokruzuje `_regular_price` i `_sale_price` — ovaj posao dira i `_price`, pa
 * promocija prepisuje vec zaokruzenu vrijednost. Obrnuti redoslijed bi istu
 * cijenu upisao dvaput i u povijesti ostavio dva retka za jednu promjenu.
 *
 * POVIJEST NE SMIJE VIDJETI SNIZENJE
 *
 * Promjena se biljezi pod vlastitim okidacem. Bez toga bi u povijesti cijena
 * izgledala kao pad cijene od pola centa, a nije — ista je cijena, samo zapisana
 * s dvije decimale.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Cijene\Zaokruzivanje;
use CJTR\Config;
use CJTR\Db;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Poslovi\Stanje;
use CJTR\Povijest\Biljeznik;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Zaokruzi_Cijene extends Posao {

	const OPT_POTVRDA = 'zaokruzivanje_potvrda';

	public function kljuc(): string {
		return 'zaokruzi_cijene';
	}

	public function naziv(): string {
		return __( 'Svedi cijene na dvije decimale', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Dio cijena ima vise od dvije decimale — ostatak preracuna iz kuna. Zbog toga cjenik za isti artikl objavljuje dvije razlicite brojke. Posao ih svodi na dvije decimale, uvijek prema dolje, nikad prema gore. Cijene se time samo snizuju, i to za manje od centa.', Config::TEXT_DOMAIN );
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 6;
	}

	public function preduvjet(): string {
		return 'sidrena_cijena';
	}

	public function preduvjet_razlog(): string {
		return __( 'Sidrena cijena mora vec postojati — zaokruzuje se u istom prolazu kao i ostale, inace nastaje umjetna razlika koja izgleda kao promjena cijene.', Config::TEXT_DOMAIN );
	}

	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}

		$potvrda = self::potvrda();

		if ( empty( $potvrda ) ) {
			return __( 'Potvrda jos nije dana. Otvorite "Sidrene cijene", pogledajte probni prolaz i potvrdite.', Config::TEXT_DOMAIN );
		}

		$sada = Zaokruzivanje::broj_pogodenih();

		if ( (int) ( $potvrda['artikala'] ?? -1 ) !== $sada ) {
			return sprintf(
				/* translators: 1: broj pri potvrdi, 2: broj sada */
				__( 'Skup se promijenio otkad je potvrda dana: potvrdeno je %1$d artikala, sada ih je %2$d. Pogledajte i potvrdite ponovno.', Config::TEXT_DOMAIN ),
				(int) $potvrda['artikala'],
				$sada
			);
		}

		if ( 0 === $sada ) {
			return __( 'Nijedna cijena nema vise od dvije decimale — nema sto zaokruzivati.', Config::TEXT_DOMAIN );
		}

		return '';
	}

	public function ukupno(): int {
		return Zaokruzivanje::broj_pogodenih();
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		$pogodeni = Zaokruzivanje::pogodeni( $zadnji_id, $velicina );

		if ( empty( $pogodeni ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$rez    = Rezultat_Komada::s( 0, null );
		$zadnji = 0;

		$obradenih = Biljeznik::pod_okidacem(
			Config::OKIDAC_ZAOKRUZIVANJE,
			function () use ( $pogodeni, $rez, &$zadnji ) {
				$n = 0;

				foreach ( $pogodeni as $r ) {
					$id     = (int) $r->entity_id;
					$zadnji = max( $zadnji, $id );

					if ( $this->jedan( $id, $rez ) ) {
						$n++;
					}
				}

				return $n;
			}
		);

		$rez->obradeno  = (int) $obradenih;
		$rez->zadnji_id = $zadnji ?: null;
		return $rez;
	}

	public function nakon_zavrsetka(): void {
		$stanje = Stanje::ucitaj( $this->kljuc() );

		$preostalo = Zaokruzivanje::broj_pogodenih();

		$stanje->zapisi(
			'info',
			sprintf(
				/* translators: %d = broj artikala */
				__( 'Preostalo artikala s vise od dvije decimale: %d.', Config::TEXT_DOMAIN ),
				$preostalo
			)
		);

		foreach ( Db::provjeri_nule() as $nalaz ) {
			$stanje->zapisi(
				'greska',
				sprintf(
					/* translators: 1: stupac, 2: tablica, 3: koliko */
					__( 'Provjera nula: stupac %1$s u tablici %2$s ima %3$d redaka s nulom.', Config::TEXT_DOMAIN ),
					$nalaz['stupac'],
					$nalaz['tablica'],
					$nalaz['koliko']
				)
			);
		}

		\CJTR\Prikaz\Cache::ocisti();

		$stanje->zapisi(
			'info',
			__( 'Sljedeci korak: pokrenite "Objavi dnevni cjenik" da datoteka pokupi zaokruzene cijene.', Config::TEXT_DOMAIN )
		);
	}

	/* ------------------------------------------------------------ potvrda */

	public static function potvrda(): array {
		$v = get_option( Config::option( self::OPT_POTVRDA ), array() );
		return is_array( $v ) ? $v : array();
	}

	public static function potvrdi(): void {
		$u = Zaokruzivanje::ucinak();

		update_option(
			Config::option( self::OPT_POTVRDA ),
			array(
				'kad'      => current_time( 'mysql', true ),
				'artikala' => Zaokruzivanje::broj_pogodenih(),
				'godisnje' => $u['godisnje'],
			),
			false
		);
	}

	public static function povuci_potvrdu(): void {
		delete_option( Config::option( self::OPT_POTVRDA ) );
	}

	/* --------------------------------------------------------------- interno */

	private function jedan( int $id, Rezultat_Komada $rez ): bool {
		$prije = $this->snimi( $id );

		$promjene = array();

		foreach ( Config::ZAOKRUZIVANJE_META as $meta ) {
			$staro = $prije['meta'][ $meta ] ?? '';

			if ( '' === $staro || ! Zaokruzivanje::mijenja( $staro ) ) {
				continue;
			}

			$promjene[ $meta ] = Zaokruzivanje::primijeni( $staro );
		}

		$sidrena_nova = null;

		if ( null !== $prije['sidrena'] && Zaokruzivanje::mijenja( $prije['sidrena'] ) ) {
			$sidrena_nova = Zaokruzivanje::primijeni( $prije['sidrena'] );
		}

		if ( empty( $promjene ) && null === $sidrena_nova ) {
			return false;
		}

		// Trag PRIJE promjene. Ne uspije li zapis, artikl se ne dira.
		$trag_id = Trag::zapisi(
			$id,
			$this->kljuc(),
			$prije,
			__( 'politika zaokruzivanja: dvije decimale, uvijek prema dolje', Config::TEXT_DOMAIN )
		);

		if ( 0 === $trag_id ) {
			$rez->preskoci( $id, __( 'stanje se nije moglo zapisati u trag, pa artikl nije diran', Config::TEXT_DOMAIN ) );
			return false;
		}

		foreach ( $promjene as $meta => $vrijednost ) {
			update_post_meta( $id, $meta, $vrijednost );
		}

		if ( null !== $sidrena_nova ) {
			$this->upisi_sidrenu( $id, $sidrena_nova );
		}

		// Cijena se smije samo SNIZITI. Poraste li, politika nije primijenjena nego
		// pokvarena — i to je greska, ne uspjeh.
		$poslije = $this->snimi( $id );

		foreach ( Config::ZAOKRUZIVANJE_META as $meta ) {
			$s = $prije['meta'][ $meta ] ?? '';
			$p = $poslije['meta'][ $meta ] ?? '';

			if ( '' === $s || '' === $p ) {
				continue;
			}

			if ( (float) $p > (float) $s + 0.0000001 ) {
				$rez->greska(
					sprintf(
						/* translators: 1: ID, 2: meta, 3: staro, 4: novo */
						__( 'ID %1$d: %2$s je PORASLA s %3$s na %4$s — politika snizuje, nikad ne podize.', Config::TEXT_DOMAIN ),
						$id,
						$meta,
						$s,
						$p
					)
				);
				return false;
			}
		}

		return true;
	}

	/** @return array{meta:array<string,string>,sidrena:?string} */
	private function snimi( int $id ): array {
		global $wpdb;

		$meta = array();

		foreach ( Config::ZAOKRUZIVANJE_META as $kljuc ) {
			$meta[ $kljuc ] = (string) get_post_meta( $id, $kljuc, true );
		}

		$sidrena = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT sidrena_cijena FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$id
			) // phpcs:ignore
		);

		return array(
			'meta'    => $meta,
			'sidrena' => ( null === $sidrena ) ? null : (string) $sidrena,
		);
	}

	private function upisi_sidrenu( int $id, string $vrijednost ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . Config::table( Config::TABLE_PODACI ) . '`
				 SET sidrena_cijena = ' . Db::cijena( $vrijednost ) . ', azurirano = %s
				 WHERE entity_id = %d',
				current_time( 'mysql', true ),
				$id
			) // phpcs:ignore
		);
	}
}
