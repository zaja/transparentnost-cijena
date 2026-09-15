<?php
/**
 * 7. Tko jos dira cijene, feedove ili cache.
 *
 * Ne oslanjamo se na popis poznatih dodataka — takav popis zastari cim ga napisemo.
 * Umjesto toga gledamo tko je STVARNO zakacen na hookove cijene i svaku funkciju
 * vracamo na datoteku, pa datoteku na dodatak.
 *
 * Cetiri skupine, jer nisu ista stvar:
 *   1. mijenja PRIKAZ cijene      — broj koji se naplacuje ostaje isti
 *   2. MOZE mijenjati VRIJEDNOST  — uz izmjereni nalaz mijenja li ju trenutno
 *   3. formira cijenu PAKETA      — spaja proizvode, ne dira samostalne cijene
 *   4. BILJEZI promjene cijena    — vodi vlastitu evidenciju, ne mijenja nista
 *
 * Cetvrta skupina postoji zbog konkretnog propusta: dodatak koji vodi povijest
 * cijena bio je nevidljiv jer mu se prikaz moze konfigurirati na hook koji ne
 * pratimo, dok mu zapisivac radi bezuvjetno. Tablica mu je rasla svaki dan, a
 * pregled ga nije prijavljivao.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Cijene\Nalaz;
use CJTR\Cijene\Straza;
use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;
use CJTR\Diagnostika\Snimatelj;
use CJTR\Diagnostika\Tragac;

defined( 'ABSPATH' ) || exit;

final class Sukobi extends Provjera {

	public function kljuc(): string {
		return 'sukobi';
	}

	public function izvrsi(): Rezultat {
		$r = new Rezultat( __( '7. Sto jos dira cijene', Config::TEXT_DOMAIN ) );

		// Snimka s frontenda: admin i frontend nemaju isti $wp_filter, pa bi bez
		// nje dodatak registriran samo na frontendu ostao nevidljiv.
		if ( Snimatelj::zastarjela() ) {
			Snimatelj::zatrazi();
		}
		$starost = Snimatelj::starost_sati();
		$r->stavka(
			__( 'Pregled s frontenda', Config::TEXT_DOMAIN ),
			null === $starost
				? __( 'NEDOSTAJE — pregledan je samo administracijski dio', Config::TEXT_DOMAIN )
				: sprintf(
					/* translators: %s = broj sati */
					__( 'snimljen prije %s h', Config::TEXT_DOMAIN ),
					number_format_i18n( $starost, 1 )
				)
		);
		if ( null === $starost ) {
			$r->pogorsaj( Rezultat::UPOZ );
		}

		$izvori = $this->prikupi();
		$paketi = $this->dodaci_paketa();

		foreach ( array_keys( $paketi ) as $slug ) {
			unset( $izvori[ $slug ] );
		}

		$skupine = array(
			Config::VRSTA_PRIKAZ     => array(),
			Config::VRSTA_VRIJEDNOST => array(),
			Config::VRSTA_PISANJE    => array(),
		);
		foreach ( $izvori as $slug => $i ) {
			foreach ( array_keys( $skupine ) as $vrsta ) {
				if ( ! empty( $i['vrste'][ $vrsta ] ) ) {
					$skupine[ $vrsta ][ $slug ] = $i;
				}
			}
		}

		$nalaz = Straza::provjeri( false );

		$this->skupina_prikaz( $r, $skupine[ Config::VRSTA_PRIKAZ ] );
		$this->skupina_vrijednost( $r, $skupine[ Config::VRSTA_VRIJEDNOST ], $nalaz );
		$this->skupina_paketi( $r, $paketi );
		$this->skupina_pisanje( $r, $skupine[ Config::VRSTA_PISANJE ] );

		foreach ( $this->cache_i_feed() as $ime => $opis ) {
			$r->stavka( $ime, $opis );
		}

		$this->konstante_prikaza( $r );

		$najkasniji = $this->najveci_prioritet_hooka( 'woocommerce_get_price_html' );
		if ( null !== $najkasniji ) {
			$r->stavka(
				__( 'Najkasniji zahvat u prikaz cijene', Config::TEXT_DOMAIN ),
				sprintf(
					/* translators: 1: zatecen prioritet, 2: potreban prioritet */
					__( '%1$d — sidrena cijena morat ce ici iza toga (%2$d)', Config::TEXT_DOMAIN ),
					$najkasniji,
					$najkasniji + 100
				)
			);
		}

		if ( $nalaz->razlika > 0 || ! empty( $nalaz->pravila ) ) {
			$r->status( Rezultat::LOSE );
			$r->znacenje( __( 'Neki dodatak trenutno mijenja cijene u hodu. To znaci da cijena u bazi nije cijena koju kupac placa, pa se cjenik ne smije objaviti dok se to ne razrijesi.', Config::TEXT_DOMAIN ) );
			$r->postupak( __( 'Provjerite pravila u dodacima navedenima pod "trenutno mijenja". Posao koji cita cijene nece se ni pokrenuti dok je tako.', Config::TEXT_DOMAIN ) );
			return $r;
		}

		$r->pogorsaj( count( $skupine[ Config::VRSTA_PRIKAZ ] ) > 1 ? Rezultat::UPOZ : Rezultat::OK );
		$r->znacenje( __( 'Vazna je razlika izmedu dodatka koji mijenja kako cijena IZGLEDA i dodatka koji mijenja koliko se NAPLACUJE. Zakaceno na hook cijene ne znaci da ju i mijenja — izmjereno je da trenutno nijedan ne mijenja iznos koji kupac placa.', Config::TEXT_DOMAIN ) );

		if ( count( $skupine[ Config::VRSTA_PRIKAZ ] ) > 1 ) {
			$r->postupak( __( 'Vise dodataka mijenja prikaz cijene. Prije nego ukljucimo prikaz sidrene cijene, provjerit cemo kako se slazu.', Config::TEXT_DOMAIN ) );
		}

		return $r;
	}

	/* ----------------------------------------------------------- skupine */

	private function zaglavlje( Rezultat $r, string $naslov, int $koliko ): void {
		$r->stavka(
			$naslov,
			$koliko
				? sprintf( _n( '%d dodatak', '%d dodatka', $koliko, Config::TEXT_DOMAIN ), $koliko )
				: __( 'nijedan', Config::TEXT_DOMAIN )
		);
	}

	private function skupina_prikaz( Rezultat $r, array $lista ): void {
		$this->zaglavlje( $r, __( 'MIJENJA PRIKAZ CIJENE', Config::TEXT_DOMAIN ), count( $lista ) );
		foreach ( $lista as $i ) {
			$r->stavka(
				'   ' . $i['ime'],
				sprintf(
					/* translators: 1: sto mijenja, 2: prioriteti, 3: gdje je vidjen */
					__( '%1$s (redoslijed: %2$s; vidjeno: %3$s)', Config::TEXT_DOMAIN ),
					implode( ', ', $i['vrste'][ Config::VRSTA_PRIKAZ ] ),
					implode( ', ', $i['prioriteti'] ),
					implode( ', ', $i['konteksti'] )
				)
			);
		}
	}

	private function skupina_vrijednost( Rezultat $r, array $lista, Nalaz $nalaz ): void {
		$this->zaglavlje( $r, __( 'MOZE MIJENJATI VRIJEDNOST CIJENE', Config::TEXT_DOMAIN ), count( $lista ) );

		$aktivni = array();
		foreach ( $nalaz->pravila as $p ) {
			$aktivni[ $p['dodatak'] ] = $p['koliko'];
		}

		foreach ( $lista as $i ) {
			$pravila = null;
			foreach ( $aktivni as $ime => $koliko ) {
				if ( false !== stripos( $i['ime'], $ime ) || false !== stripos( $ime, $i['ime'] ) ) {
					$pravila = $koliko;
				}
			}

			$r->stavka(
				'   ' . $i['ime'],
				null !== $pravila
					? sprintf(
						/* translators: %d = broj pravila */
						__( 'TRENUTNO MIJENJA — %d aktivnih pravila', Config::TEXT_DOMAIN ),
						$pravila
					)
					: sprintf(
						/* translators: %d = velicina uzorka */
						__( 'izmjereno: trenutno NE mijenja (uzorak %d artikala)', Config::TEXT_DOMAIN ),
						$nalaz->uzorak
					)
			);
		}

		if ( $nalaz->razlika > 0 ) {
			$r->stavka(
				__( '   razlika u cijenama', Config::TEXT_DOMAIN ),
				sprintf(
					/* translators: 1: broj razlika, 2: uzorak */
					__( '%1$d od %2$d artikala ima drugaciju cijenu od one u bazi', Config::TEXT_DOMAIN ),
					$nalaz->razlika,
					$nalaz->uzorak
				)
			);
		}
	}

	private function skupina_paketi( Rezultat $r, array $paketi ): void {
		if ( ! $paketi ) {
			return;
		}
		$this->zaglavlje( $r, __( 'FORMIRA CIJENU PAKETA', Config::TEXT_DOMAIN ), count( $paketi ) );
		foreach ( $paketi as $p ) {
			$r->stavka( '   ' . $p['ime'], $p['nalaz'] );
		}
	}

	private function skupina_pisanje( Rezultat $r, array $lista ): void {
		if ( ! $lista ) {
			return;
		}
		$this->zaglavlje( $r, __( 'BILJEZI PROMJENE CIJENA', Config::TEXT_DOMAIN ), count( $lista ) );
		foreach ( $lista as $i ) {
			$r->stavka(
				'   ' . $i['ime'],
				sprintf(
					/* translators: 1: sto radi, 2: gdje je vidjen */
					__( '%1$s (vidjeno: %2$s)', Config::TEXT_DOMAIN ),
					implode( ', ', $i['vrste'][ Config::VRSTA_PISANJE ] ),
					implode( ', ', $i['konteksti'] )
				)
			);
		}
	}

	/* ---------------------------------------------------------- prikupljanje */

	/** @return array<string,array{ime:string,vrste:array,prioriteti:array,konteksti:array}> */
	private function prikupi(): array {
		$izvori = array();

		foreach ( Config::HOOKOVI_CIJENE as $hook => $podaci ) {
			foreach ( $this->pozivatelji( $hook ) as $poziv ) {
				$slug = $poziv['slug'];

				if ( ! isset( $izvori[ $slug ] ) ) {
					$izvori[ $slug ] = array(
						'ime'        => $poziv['ime'],
						'vrste'      => array(),
						'prioriteti' => array(),
						'konteksti'  => array(),
					);
				}

				$izvori[ $slug ]['vrste'][ $podaci['vrsta'] ][ $podaci['opis'] ] = $podaci['opis'];
				$izvori[ $slug ]['prioriteti'][ $poziv['prioritet'] ]            = $poziv['prioritet'];
				$izvori[ $slug ]['konteksti'][ $poziv['kontekst'] ]              = $poziv['kontekst'];
			}
		}

		foreach ( $izvori as &$i ) {
			foreach ( $i['vrste'] as &$v ) {
				$v = array_values( $v );
			}
			unset( $v );
			sort( $i['prioriteti'] );
			$i['konteksti'] = array_values( $i['konteksti'] );
		}
		unset( $i );

		ksort( $izvori );
		return $izvori;
	}

	/**
	 * Tko je zakacen na dani hook — i u ovom zahtjevu i prema snimci s frontenda.
	 *
	 * @return array<int,array{slug:string,ime:string,prioritet:int,kontekst:string}>
	 */
	private function pozivatelji( string $hook ): array {
		global $wp_filter;

		$rezultat = array();

		if ( ! empty( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $prioritet => $stavke ) {
				foreach ( $stavke as $stavka ) {
					$izvor = Tragac::datoteka_u_izvor( Tragac::datoteka_funkcije( $stavka['function'] ) );
					if ( null === $izvor ) {
						continue;
					}
					$izvor['prioritet'] = (int) $prioritet;
					$izvor['kontekst']  = __( 'admin', Config::TEXT_DOMAIN );
					$rezultat[]         = $izvor;
				}
			}
		}

		$snimka = Snimatelj::snimka();
		if ( $snimka ) {
			foreach ( $snimka['hookovi'] as $zapis ) {
				if ( $zapis['hook'] !== $hook ) {
					continue;
				}
				$izvor = Tragac::datoteka_u_izvor( (string) $zapis['datoteka'] );
				if ( null === $izvor ) {
					continue;
				}
				$izvor['prioritet'] = (int) $zapis['prioritet'];
				$izvor['kontekst']  = __( 'frontend', Config::TEXT_DOMAIN );
				$rezultat[]         = $izvor;
			}
		}

		return $rezultat;
	}

	/** @return array<string,array{ime:string,nalaz:string}> */
	private function dodaci_paketa(): array {
		global $wpdb;
		$nadeno = array();

		foreach ( Config::DODACI_PAKETA as $slug => $tip ) {
			$ime = Tragac::ime_plugina( $slug );
			if ( $ime === $slug ) {
				continue;
			}

			$koliko = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT tr.object_id)
					 FROM {$wpdb->term_relationships} tr
					 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
					 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
					 JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_status IN ('publish','private')",
					$tip
				)
			); // phpcs:ignore

			$po_sastavnici = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} m
					 JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status IN ('publish','private')
					 WHERE m.meta_key IN ('_mnm_per_product_pricing','_wc_pb_per_product_pricing') AND m.meta_value = %s",
					'yes'
				)
			); // phpcs:ignore

			$nadeno[ $slug ] = array(
				'ime'   => $ime,
				'nalaz' => $po_sastavnici > 0
					? sprintf(
						/* translators: 1: broj paketa, 2: broj s cijenom po sastavnici */
						__( '%1$d paketa; %2$d ima cijenu koja se racuna iz sastavnica — te treba provjeriti', Config::TEXT_DOMAIN ),
						$koliko,
						$po_sastavnici
					)
					: sprintf(
						/* translators: %d = broj paketa */
						_n( '%d paket, cijena je fiksna iz osnovne cijene paketa — samostalne cijene artikala se ne diraju',
							'%d paketa, cijena je fiksna iz osnovne cijene paketa — samostalne cijene artikala se ne diraju',
							$koliko,
							Config::TEXT_DOMAIN
						),
						$koliko
					),
			);
		}

		return $nadeno;
	}

	/** Dodaci za cache i feedove — njih se ne vidi kroz hookove cijene. */
	private function cache_i_feed(): array {
		$nadeno = array();

		$tragovi = array(
			'WP_ROCKET_VERSION'       => array( 'WP Rocket', __( 'sprema stranice u memoriju — moze prikazivati staru cijenu', Config::TEXT_DOMAIN ) ),
			'W3TC'                    => array( 'W3 Total Cache', __( 'sprema stranice u memoriju', Config::TEXT_DOMAIN ) ),
			'WPCACHEHOME'             => array( 'WP Super Cache', __( 'sprema stranice u memoriju', Config::TEXT_DOMAIN ) ),
			'LSCWP_V'                 => array( 'LiteSpeed Cache', __( 'sprema stranice u memoriju', Config::TEXT_DOMAIN ) ),
			'WOOCOMMERCE_GPF_VERSION' => array( 'Google Product Feed', __( 'vec izvozi podatke o artiklima', Config::TEXT_DOMAIN ) ),
		);

		foreach ( $tragovi as $konstanta => $podaci ) {
			if ( defined( $konstanta ) ) {
				$nadeno[ $podaci[0] ] = $podaci[1];
			}
		}

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			$nadeno['Action Scheduler'] = __( 'sustav za poslove u pozadini — koristit cemo ga', Config::TEXT_DOMAIN );
		}

		return $nadeno;
	}

	/**
	 * Vrijednosti konstanti kojima se mijenja nacin prikaza cijene.
	 *
	 * Dodatak postavljen na drugi nacin prikaza kaci se na drugi hook i time
	 * izmice pregledu po hookovima. Ispis vrijednosti to pretvara iz nagadanja
	 * u cinjenicu.
	 */
	private function konstante_prikaza( Rezultat $r ): void {
		foreach ( Config::KONSTANTE_PRIKAZA as $konstanta => $dodatak ) {
			if ( ! $this->dodatak_prisutan( $dodatak ) ) {
				continue;
			}
			$r->stavka(
				sprintf( '%s (%s)', $konstanta, $dodatak ),
				defined( $konstanta )
					? sprintf(
						/* translators: %s = vrijednost konstante */
						__( 'postavljeno na "%s"', Config::TEXT_DOMAIN ),
						(string) constant( $konstanta )
					)
					: __( 'nije postavljeno — vrijedi zadana vrijednost dodatka', Config::TEXT_DOMAIN )
			);
		}
	}

	private function dodatak_prisutan( string $ime ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $podaci ) {
			if ( $podaci['Name'] === $ime ) {
				return true;
			}
		}
		return false;
	}

	private function najveci_prioritet_hooka( string $hook ): ?int {
		$max = null;
		foreach ( $this->pozivatelji( $hook ) as $poziv ) {
			if ( null === $max || $poziv['prioritet'] > $max ) {
				$max = $poziv['prioritet'];
			}
		}
		return $max;
	}
}
