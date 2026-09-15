<?php
/**
 * Uvoz podataka o proizvodima iz CSV-a.
 *
 * PROBNI PROLAZ PRIJE UPISA — NIJE OPCIJA
 *
 * Tablica od dobavljaca je tudi podatak nad kojim nemamo kontrolu. Uvoz koji
 * odmah pise moze u jednom potezu upisati 3000 krivih barkodova, a povrat bi
 * trazio da se zna kakvo je stanje bilo prije. Zato uvoz UVIJEK prvo prijavi sto
 * bi napravio, pa tek na potvrdu pise.
 *
 * UPARIVANJE
 *
 * Po sifri (SKU) primarno, po ID-u kao rezerva. Sifra je jedino sto dobavljac zna.
 * Sifra koja se u trgovini pojavljuje vise puta NE uparuje se — dvosmislen pogodak
 * gori je od nikakvog.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Uvoz {

	/** Koliko redaka najvise prihvacamo iz jedne datoteke. */
	const MAX_REDAKA = 20000;

	/**
	 * Procitaj CSV i reci sto bi se dogodilo.
	 *
	 * @param string $putanja Privremena datoteka iz obrasca.
	 * @return array{redci:array,sazetak:array,greske:string[]}
	 */
	public static function procitaj( string $putanja, array $karta = array(), string $ime_datoteke = '' ): array {
		$greske = array();
		$redci  = array();

		$h = fopen( $putanja, 'r' );
		if ( ! $h ) {
			return array(
				'redci'   => array(),
				'sazetak' => self::prazan_sazetak(),
				'greske'  => array( __( 'Datoteka se nije mogla otvoriti.', Config::TEXT_DOMAIN ) ),
			);
		}

		$zaglavlje = fgetcsv( $h );

		if ( ! is_array( $zaglavlje ) ) {
			fclose( $h );
			return array(
				'redci'   => array(),
				'sazetak' => self::prazan_sazetak(),
				'greske'  => array( __( 'Datoteka je prazna.', Config::TEXT_DOMAIN ) ),
			);
		}

		// BOM iz Excela pojede prvi stupac ako se ne makne.
		$zaglavlje[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $zaglavlje[0] );
		$zaglavlje    = array_map(
			function ( $v ) {
				return strtolower( trim( (string) $v ) );
			},
			$zaglavlje
		);

		/*
		 * Stupci se odreduju na dva nacina, i korisnikov izbor uvijek pobjeduje.
		 *
		 * Zaglavlje pogada samo kad se slucajno zove kao nase polje. Program koji
		 * izvozi "EAN" umjesto "barkod" nije pogrijesio — samo ne zna za nas. Zato
		 * ekran nudi povezivanje, a ovdje se ta karta primijeni preko pogodenog.
		 */
		$stupci = array_flip( $zaglavlje );

		foreach ( $karta as $polje => $indeks ) {
			if ( '' === (string) $indeks ) {
				unset( $stupci[ $polje ] );
				continue;
			}
			$stupci[ $polje ] = (int) $indeks;
		}

		if ( ! isset( $stupci['sifra'] ) && ! isset( $stupci['entity_id'] ) ) {
			fclose( $h );
			return array(
				'redci'   => array(),
				'sazetak' => self::prazan_sazetak(),
				'greske'  => array( __( 'Nije receno koji stupac nosi sifru artikla. Bez njega se ne zna na koji se artikl redak odnosi — povezite stupce iznad.', Config::TEXT_DOMAIN ) ),
			);
		}

		$karta_sifri = self::karta_sifri();
		$broj        = 0;

		while ( ( $polja = fgetcsv( $h ) ) !== false ) {
			$broj++;

			if ( $broj > self::MAX_REDAKA ) {
				$greske[] = sprintf(
					/* translators: %d = ogranicenje */
					__( 'Datoteka ima vise od %d redaka — obraduje se samo toliko.', Config::TEXT_DOMAIN ),
					self::MAX_REDAKA
				);
				break;
			}

			$redak = self::redak( $polja, $stupci, $karta_sifri, $broj, $ime_datoteke );
			if ( null !== $redak ) {
				$redci[] = $redak;
			}
		}

		fclose( $h );

		return array(
			'redci'     => $redci,
			'sazetak'   => self::sazmi( $redci ),
			'greske'    => $greske,
			'zaglavlje' => $zaglavlje,
			'datoteka'  => $ime_datoteke,
		);
	}

	/**
	 * Zaglavlje datoteke, za ekran povezivanja stupaca.
	 *
	 * Cita se bez obrade ijednog retka — korisnik mora vidjeti sto ima prije nego
	 * mu itko kaze sto s tim.
	 *
	 * @return array{stupci:string[],primjer:string[],pogodeno:array<string,int>}
	 */
	public static function zaglavlje( string $putanja ): array {
		$h = fopen( $putanja, 'r' );

		if ( ! $h ) {
			return array(
				'stupci'   => array(),
				'primjer'  => array(),
				'pogodeno' => array(),
			);
		}

		$zaglavlje = fgetcsv( $h );
		$primjer   = fgetcsv( $h );
		fclose( $h );

		if ( ! is_array( $zaglavlje ) ) {
			return array(
				'stupci'   => array(),
				'primjer'  => array(),
				'pogodeno' => array(),
			);
		}

		$zaglavlje[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $zaglavlje[0] );
		$zaglavlje    = array_map( 'trim', array_map( 'strval', $zaglavlje ) );

		return array(
			'stupci'   => $zaglavlje,
			'primjer'  => is_array( $primjer ) ? array_map( 'strval', $primjer ) : array(),
			'pogodeno' => self::pogodi( $zaglavlje ),
		);
	}

	/**
	 * Pogodi koji stupac je koje polje, po imenu.
	 *
	 * Pogadanje je PRIJEDLOG, ne odluka: ekran ga ponudi kao vec odabrano, a
	 * korisnik ga smije promijeniti. Sinonimi su ono sto programi stvarno izvoze.
	 *
	 * @return array<string,int> polje => indeks stupca
	 */
	public static function pogodi( array $zaglavlje ): array {
		$sinonimi = array(
			'sifra'               => array( 'sifra', 'sifra artikla', 'sku', 'artikl', 'kod', 'code' ),
			'entity_id'           => array( 'entity_id', 'id' ),
			'barkod'              => array( 'barkod', 'bar kod', 'ean', 'ean13', 'gtin', 'upc', 'crtni kod' ),
			'marka'               => array( 'marka', 'brend', 'brand', 'proizvodac' ),
			'neto_kolicina'       => array( 'neto_kolicina', 'neto kolicina', 'kolicina', 'neto' ),
			'jedinica_mjere'      => array( 'jedinica_mjere', 'jedinica mjere', 'jedinica', 'jm', 'mjera' ),
			'zakonska_kategorija' => array( 'zakonska_kategorija', 'zakonska kategorija', 'kategorija' ),
			'sidrena_cijena'      => array( 'sidrena_cijena', 'dodatna cijena', 'sidrena cijena', 'referentna cijena' ),
		);

		$mala = array_map(
			function ( $v ) {
				return mb_strtolower( trim( (string) $v ) );
			},
			$zaglavlje
		);

		$karta = array();

		foreach ( $sinonimi as $polje => $imena ) {
			foreach ( $imena as $ime ) {
				$i = array_search( $ime, $mala, true );
				if ( false !== $i ) {
					$karta[ $polje ] = (int) $i;
					break;
				}
			}
		}

		return $karta;
	}

	/**
	 * Upisi ono sto je probni prolaz odobrio.
	 *
	 * @param array $redci Iz `procitaj()`.
	 * @return array{upisano:int,preskoceno:int}
	 */
	public static function upisi( array $redci ): array {
		$upisano    = 0;
		$nedirnuto  = 0;
		$preskoceno = 0;
		$vec_u_woou = 0;

		foreach ( $redci as $r ) {
			if ( ! empty( $r['vec_u_woou'] ) ) {
				$vec_u_woou++;
			}

			if ( 'upisat_ce_se' !== $r['ishod'] ) {
				$preskoceno++;
				continue;
			}

			$promijenjeno = false;

			foreach ( $r['promjene'] as $polje => $vrijednost ) {
				// Dodatna cijena ne ide kroz isti put kao podaci o proizvodu: ona nosi
				// vlastitu provenijenciju (odakle, tko, kada) i vlastito pravilo o
				// tome smije li prepisati ono sto vec stoji.
				if ( 'sidrena_cijena' === $polje ) {
					$ishod = Sidrena_Unos::upisi(
						(int) $r['entity_id'],
						(string) $vrijednost['vrijednost'],
						Config::IZVOR_UVOZ,
						(string) $vrijednost['biljeska']
					);

					if ( ! empty( $ishod['ok'] ) ) {
						$promijenjeno = true;
					}
					continue;
				}

				$ishod = Zapis_Podataka::upisi( (int) $r['entity_id'], $polje, $vrijednost, Config::IZVOR_PODATKA_CSV );

				if ( empty( $ishod['nedirnut'] ) ) {
					$promijenjeno = true;
				}
			}

			// Redak koji nosi tocno ono sto vec stoji nije upisan nego potvrden.
			// Razlika je vidljiva u izvjestaju da se ne bi cinilo da je uvoz nesto
			// promijenio kad nije.
			if ( $promijenjeno ) {
				$upisano++;
			} else {
				$nedirnuto++;
			}
		}

		return array(
			'upisano'    => $upisano,
			'nedirnuto'  => $nedirnuto,
			'preskoceno' => $preskoceno,
			'vec_u_woou' => $vec_u_woou,
		);
	}

	/* --------------------------------------------------------------- interno */

	/**
	 * Jedan redak: na koji artikl ide, sto bi se promijenilo, i je li sve u redu.
	 *
	 * @return array|null null ako je redak prazan
	 */
	private static function redak( array $polja, array $stupci, array $karta_sifri, int $broj, string $ime_datoteke = '' ): ?array {
		$daj = function ( $ime ) use ( $polja, $stupci ) {
			if ( ! isset( $stupci[ $ime ] ) ) {
				return '';
			}
			return trim( (string) ( $polja[ $stupci[ $ime ] ] ?? '' ) );
		};

		$sifra = $daj( 'sifra' );
		$id    = (int) $daj( 'entity_id' );

		if ( '' === $sifra && 0 === $id ) {
			return null;
		}

		$redak = array(
			'broj'      => $broj,
			'sifra'     => $sifra,
			'entity_id' => 0,
			'naziv'     => '',
			'ishod'      => 'upisat_ce_se',
			'poruka'     => '',
			'promjene'   => array(),
			'vec_u_woou' => array(),
		);

		// Uparivanje: sifra prvo, ID kao rezerva.
		if ( '' !== $sifra && isset( $karta_sifri[ $sifra ] ) ) {
			$pogoci = $karta_sifri[ $sifra ];

			if ( count( $pogoci ) > 1 ) {
				$redak['ishod']  = 'dvosmisleno';
				$redak['poruka'] = sprintf(
					/* translators: 1: sifra, 2: broj artikala */
					__( 'Sifra "%1$s" stoji na %2$d artikala — ne zna se na koji se redak odnosi.', Config::TEXT_DOMAIN ),
					$sifra,
					count( $pogoci )
				);
				return $redak;
			}

			$redak['entity_id'] = (int) $pogoci[0];
		} elseif ( $id > 0 && self::u_katalogu( $id ) ) {
			$redak['entity_id'] = $id;
		} else {
			$redak['ishod']  = 'nije_nadeno';
			$redak['poruka'] = ( '' !== $sifra )
				? sprintf( __( 'Sifra "%s" ne postoji u trgovini.', Config::TEXT_DOMAIN ), $sifra )
				: sprintf( __( 'Artikl #%d ne postoji u trgovini.', Config::TEXT_DOMAIN ), $id );
			return $redak;
		}

		$redak['naziv'] = (string) get_the_title( $redak['entity_id'] );

		// Sto se mijenja. Prazna celija znaci "ne diraj", ne "obrisi" — tablica od
		// dobavljaca nosi samo ono sto on zna, a ne cijelo stanje naseg kataloga.
		/*
		 * Barkod i marka: ako ih WooCommerce vec ima, uvoz ih NE dira.
		 *
		 * WooCommerceova vrijednost je izvor istine i ne prepisuje se tablicom iz
		 * tudeg programa. Redak se preskace i broji ZASEBNO — sakriven pod "upisano"
		 * ostavio bi trgovca u uvjerenju da je njegova tablica presla, a nije.
		 */
		$vec_u_woou = array();

		$barkod = $daj( 'barkod' );
		if ( '' !== $barkod && Woo_Polja::woo_ima( $redak['entity_id'], Config::POLJE_BARKOD ) ) {
			$vec_u_woou[] = mb_strtolower( Config::POLJA[ Config::POLJE_BARKOD ]['naziv'] );
			$barkod       = '';
		}

		$marka_iz_datoteke = $daj( 'marka' );
		if ( '' !== $marka_iz_datoteke && Woo_Polja::woo_ima( $redak['entity_id'], Config::POLJE_MARKA ) ) {
			$vec_u_woou[] = mb_strtolower( Config::POLJA[ Config::POLJE_MARKA ]['naziv'] );
		}

		if ( '' !== $barkod ) {
			$cist   = Gtin::ocisti( $barkod );
			$status = Gtin::status( $cist );

			/*
			 * Odbija se samo ono sto barkod NE MOZE BITI.
			 *
			 * Vrijednost ispravne duljine koja pada na kontrolnoj znamenki prolazi, uz
			 * napomenu: moguce je da je broj pravi a jedna znamenka krivo prepisana, a
			 * odbiti je znacilo bi izgubiti podatak koji je vjerojatno dobar.
			 */
			if ( Config::GTIN_NEMOGUC === $status ) {
				$redak['ishod']  = 'nemoguc_barkod';
				$redak['poruka'] = sprintf(
					/* translators: %s = barkod */
					__( '"%s" ne moze biti barkod — nijedan GTIN nema tu duljinu ili te znakove.', Config::TEXT_DOMAIN ),
					$barkod
				);
			} else {
				$redak['promjene'][ Config::POLJE_BARKOD ] = array( 'barkod' => $cist );
				if ( Config::GTIN_VALJAN !== $status ) {
					$redak['poruka'] = Gtin::objasnjenje( $status );
				}
			}
		}

		$marka = $marka_iz_datoteke;
		if ( '' !== $marka && ! Woo_Polja::woo_ima( $redak['entity_id'], Config::POLJE_MARKA ) ) {
			$redak['promjene'][ Config::POLJE_MARKA ] = array( 'marka' => $marka );
		}

		$kolicina = $daj( 'neto_kolicina' );
		$jedinica = $daj( 'jedinica_mjere' );

		if ( '' !== $kolicina || '' !== $jedinica ) {
			if ( '' === $kolicina || '' === $jedinica ) {
				$redak['ishod']  = 'nepotpuna_kolicina';
				$redak['poruka'] = __( 'Kolicina bez jedinice mjere (ili obrnuto) nema znacenje.', Config::TEXT_DOMAIN );
			} elseif ( ! Kolicina::poznata( $jedinica ) ) {
				$redak['ishod']  = 'nepoznata_jedinica';
				$redak['poruka'] = sprintf(
					/* translators: %s = jedinica */
					__( 'Jedinica "%s" nije poznata.', Config::TEXT_DOMAIN ),
					$jedinica
				);
			} else {
				$redak['promjene'][ Config::POLJE_KOLICINA ] = array(
					'kolicina' => $kolicina,
					'jedinica' => $jedinica,
				);
			}
		}

		/*
		 * Dodatna cijena.
		 *
		 * Za velik dio trgovaca je uvoz JEDINI izvor te vrijednosti — nemaju povijest
		 * cijena ni iz cega je izmjeriti. Zato stoji ovdje, uz ostale podatke, a ne
		 * na zasebnom putu.
		 *
		 * NULA SE ODBIJA NA ULAZU. Prazna celija znaci "ne znam", nula znaci "besplatno
		 * je". Bez ove provjere nula bi usla u bazu i poslije bila prijavljena kao kvar
		 * dodatka — a nije kvar nego nesporazum, i rjesava se ondje gdje nastaje.
		 */
		$cijena = $daj( 'sidrena_cijena' );
		if ( '' !== $cijena ) {
			$provjera = Sidrena_Unos::provjeri( $cijena );

			if ( ! $provjera['ok'] ) {
				$redak['ishod']  = 'neispravna_cijena';
				$redak['poruka'] = $provjera['poruka'];
			} else {
				$redak['promjene']['sidrena_cijena'] = array(
					'vrijednost' => $provjera['vrijednost'],
					'biljeska'   => sprintf(
						/* translators: 1: ime datoteke, 2: datum */
						__( 'uvoz iz datoteke "%1$s", %2$s', Config::TEXT_DOMAIN ),
						( '' !== $ime_datoteke ) ? $ime_datoteke : __( 'bez imena', Config::TEXT_DOMAIN ),
						wp_date( 'j.n.Y.' )
					),
				);
			}
		}

		$kategorija = $daj( 'zakonska_kategorija' );
		if ( '' !== $kategorija ) {
			if ( ! isset( Config::ZAKONSKE_KATEGORIJE[ $kategorija ] ) ) {
				$redak['ishod']  = 'nepoznata_kategorija';
				$redak['poruka'] = sprintf(
					/* translators: %s = kategorija */
					__( 'Kategorija "%s" nije jedna od propisanih.', Config::TEXT_DOMAIN ),
					$kategorija
				);
			} else {
				$redak['promjene'][ Config::POLJE_KATEGORIJA ] = array( 'kategorija' => $kategorija );
			}
		}

		if ( ! empty( $vec_u_woou ) ) {
			// Zasebna oznaka, NEOVISNA o ishodu retka. Redak koji uz odbijeni barkod
			// ipak upise marku i dalje je "upisano" — ali trgovac mora vidjeti da
			// barkod nije presao, inace ce mjesecima misliti da jest.
			$redak['vec_u_woou'] = $vec_u_woou;

			$redak['poruka'] = trim(
				$redak['poruka'] . ' ' . sprintf(
					/* translators: %s = popis polja */
					__( 'Nije upisano (%s) — te vrijednosti vec stoje u WooCommerceu i one vrijede.', Config::TEXT_DOMAIN ),
					implode( ', ', $vec_u_woou )
				)
			);

			if ( 'upisat_ce_se' === $redak['ishod'] && empty( $redak['promjene'] ) ) {
				$redak['ishod'] = 'vec_u_woocommerceu';
				return $redak;
			}
		}

		if ( 'upisat_ce_se' === $redak['ishod'] && empty( $redak['promjene'] ) ) {
			$redak['ishod']  = 'nema_promjena';
			$redak['poruka'] = __( 'Redak ne nosi nijedan podatak.', Config::TEXT_DOMAIN );
		}

		return $redak;
	}

	/** @return array<string,int[]> sifra => ID-evi */
	private static function karta_sifri(): array {
		global $wpdb;

		$karta = array();

		foreach ( (array) $wpdb->get_results(
			"SELECT l.sku, p.ID
			 FROM {$wpdb->prefix}wc_product_meta_lookup l
			 JOIN {$wpdb->posts} p ON p.ID = l.product_id
			 WHERE l.sku <> '' AND " . Katalog::uvjet() // phpcs:ignore
		) as $r ) {
			$karta[ (string) $r->sku ][] = (int) $r->ID;
		}

		return $karta;
	}

	private static function u_katalogu( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.ID = %d AND " . Katalog::uvjet(),
				$id
			) // phpcs:ignore
		);
	}

	private static function sazmi( array $redci ): array {
		$sazetak = self::prazan_sazetak();

		foreach ( $redci as $r ) {
			$sazetak['ukupno']++;
			$sazetak[ $r['ishod'] ] = ( $sazetak[ $r['ishod'] ] ?? 0 ) + 1;

			// Broji se ZASEBNO, ne umjesto ishoda: redak koji uz odbijeni barkod
			// ipak upise marku pojavljuje se u obje brojke, i to je tocno.
			if ( ! empty( $r['vec_u_woou'] ) ) {
				$sazetak['vec_u_woocommerceu'] = ( $sazetak['vec_u_woocommerceu'] ?? 0 ) + 1;
			}
		}

		return $sazetak;
	}

	private static function prazan_sazetak(): array {
		return array(
			'ukupno'       => 0,
			'upisat_ce_se' => 0,
		);
	}

	/** Objasnjenja ishoda, za ekran. */
	public static function opis_ishoda( string $ishod ): string {
		$tekstovi = array(
			'upisat_ce_se'         => __( 'bit ce upisano', Config::TEXT_DOMAIN ),
			'nije_nadeno'          => __( 'artikl nije naden', Config::TEXT_DOMAIN ),
			'dvosmisleno'          => __( 'sifra stoji na vise artikala', Config::TEXT_DOMAIN ),
			'neispravan_barkod'    => __( 'barkod ne prolazi provjeru', Config::TEXT_DOMAIN ),
			'nepotpuna_kolicina'   => __( 'kolicina bez jedinice mjere', Config::TEXT_DOMAIN ),
			'nepoznata_jedinica'   => __( 'jedinica mjere nije poznata', Config::TEXT_DOMAIN ),
			'nepoznata_kategorija' => __( 'kategorija nije propisana', Config::TEXT_DOMAIN ),
			'nema_promjena'        => __( 'redak ne nosi podatke', Config::TEXT_DOMAIN ),
			'neispravna_cijena'    => __( 'cijena se ne moze procitati, ili je nula', Config::TEXT_DOMAIN ),
			'vec_u_woocommerceu'   => __( 'barkod ili marka vec postoje u WooCommerceu — nisu prepisani', Config::TEXT_DOMAIN ),
			'nemoguc_barkod'       => __( 'vrijednost ne moze biti barkod', Config::TEXT_DOMAIN ),
		);

		return $tekstovi[ $ishod ] ?? $ishod;
	}
}
