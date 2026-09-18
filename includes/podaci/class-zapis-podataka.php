<?php
/**
 * Citanje i pisanje podataka o proizvodu — jedini put do tablice.
 *
 * Svaki upis prolazi ovuda: polja na proizvodu, skupno uredivanje, CSV uvoz i
 * poslovi. Razlog je jedan: barkod se mora normalizirati i ocijeniti, kolicina
 * svesti na kanonsku jedinicu, a marka na kanonski oblik — i to na istom mjestu.
 * Kad bi svaki ulaz to radio sam, tri bi puta radio isto, a cetvrti bi zaboravio.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Zapis_Podataka {

	private static function tablica(): string {
		return Config::table( Config::TABLE_PODACI );
	}

	/** @return object|null */
	public static function procitaj( int $entity_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM `' . self::tablica() . '` WHERE entity_id = %d', $entity_id ) // phpcs:ignore
		);
	}

	/**
	 * Upisi jedno polje.
	 *
	 * Prazna vrijednost BRISE podatak i izvor — to je razlika prema "nije
	 * primjenjivo", koje ima vlastitu tablicu i trazi razlog.
	 *
	 * @param array $vrijednost Oblik ovisi o polju; vidi `pripremi()`.
	 * @return array{ok:bool,poruka:string}
	 */
	public static function upisi( int $entity_id, string $polje, array $vrijednost, string $izvor ): array {
		global $wpdb;

		if ( ! isset( Config::POLJA[ $polje ] ) ) {
			return array(
				'ok'     => false,
				'poruka' => __( 'Nepoznato polje.', Config::TEXT_DOMAIN ),
			);
		}

		$pripremljeno = self::pripremi( $polje, $vrijednost );

		if ( isset( $pripremljeno['greska'] ) ) {
			return array(
				'ok'     => false,
				'poruka' => $pripremljeno['greska'],
			);
		}

		$stupci = $pripremljeno['stupci'];

		/*
		 * NISTA SE NE MIJENJA AKO SE VRIJEDNOST NE MIJENJA.
		 *
		 * Bez ove provjere upis je bio destruktivan i kad je bezopasan izgledao.
		 * Otkrio ju je test "izvezi pa uvezi natrag": vracanje vlastitog izvoza bez
		 * ijedne izmjene promijenilo je 3713 polja i obrisalo 10 oznaka.
		 *
		 * Dvoje se gubilo:
		 *
		 * 1. PROVENIJENCIJA. Marka procitana iz naziva artikla dobila bi izvor
		 *    `csv` i time tvrdila da dolazi iz tablice dobavljaca — jaci izvor nego
		 *    sto zasluzuje. Vrijednost ista, tvrdnja o njoj neistinita.
		 *
		 * 2. OZNAKA "NIJE PRIMJENJIVO". Upis vrijednosti je skida, sto je ispravno
		 *    kad vrijednost stvarno stize; kad se upisuje ista vrijednost koja je
		 *    vec ondje, skidanje je cista steta.
		 *
		 * Idempotentnost nije uglancavanje: uvoz je operacija koja se ponavlja, a
		 * operacija koja se ponavlja mora smjeti biti ponovljena.
		 */
		if ( self::isto_stanje( $entity_id, $stupci ) ) {
			return array(
				'ok'        => true,
				'poruka'    => $pripremljeno['poruka'] ?? '',
				'nedirnut'  => true,
			);
		}

		$stupci[ Config::POLJA[ $polje ]['izvor'] ] = empty( $pripremljeno['prazno'] ) ? $izvor : null;

		self::pisi( $entity_id, $stupci );

		// Upisana vrijednost znaci da dvojbe vise nema — sukob se sklanja.
		if ( empty( $pripremljeno['prazno'] ) ) {
			Sukobi_Podataka::ocisti( $entity_id, $polje );
			Neprimjenjivo::skini( $entity_id, $polje );
		}

		return array(
			'ok'     => true,
			'poruka' => $pripremljeno['poruka'] ?? '',
		);
	}

	/**
	 * Svedi korisnicki unos na stupce tablice.
	 *
	 * @return array{stupci:array,prazno?:bool,poruka?:string,greska?:string}
	 */
	private static function pripremi( string $polje, array $v ): array {
		switch ( $polje ) {

			case Config::POLJE_BARKOD:
				$cist = Gtin::ocisti( (string) ( $v['barkod'] ?? '' ) );

				if ( '' === $cist ) {
					return array(
						'stupci' => array(
							'barkod'        => null,
							'barkod_status' => null,
						),
						'prazno' => true,
					);
				}

				$status = Gtin::status( $cist );

				// Neispravan se UPISUJE i oznacava, ne odbija: trgovac ga mora vidjeti
				// da bi ga ispravio. Odbijen unos nestao bi bez traga.
				return array(
					'stupci' => array(
						'barkod'        => Gtin::u_ean13( $cist ),
						'barkod_status' => $status,
					),
					'poruka' => Config::GTIN_VALJAN === $status ? '' : Gtin::objasnjenje( $status ),
				);

			case Config::POLJE_MARKA:
				$marka = trim( (string) ( $v['marka'] ?? '' ) );

				if ( '' === $marka ) {
					return array(
						'stupci' => array( 'marka' => null ),
						'prazno' => true,
					);
				}

				return array( 'stupci' => array( 'marka' => Marke::kanonski( $marka ) ) );

			case Config::POLJE_KOLICINA:
				$kolicina = (string) ( $v['kolicina'] ?? '' );
				$jedinica = (string) ( $v['jedinica'] ?? '' );

				if ( '' === trim( $kolicina ) && '' === trim( $jedinica ) ) {
					return array(
						'stupci' => array(
							'neto_kolicina'  => null,
							'jedinica_mjere' => null,
						),
						'prazno' => true,
					);
				}

				$broj = (float) str_replace( ',', '.', $kolicina );

				if ( $broj <= 0 ) {
					return array( 'greska' => __( 'Kolicina mora biti veca od nule.', Config::TEXT_DOMAIN ) );
				}

				if ( ! Kolicina::poznata( $jedinica ) ) {
					return array(
						'greska' => sprintf(
							/* translators: 1: jedinica, 2: popis poznatih */
							__( 'Jedinica "%1$s" nije poznata. Poznate su: %2$s.', Config::TEXT_DOMAIN ),
							$jedinica,
							implode( ', ', array_keys( Config::JEDINICE ) )
						),
					);
				}

				// Sprema se u KANONSKOJ jedinici. Unos u gramima i unos u kilogramima
				// tako daju istu zapisanu vrijednost, pa cjenik ne moze pomijesati
				// EUR/kg i EUR/100 g.
				$kanonska = Kolicina::u_kanonsku( $broj, $jedinica );

				return array(
					'stupci' => array(
						'neto_kolicina'  => $kanonska['kolicina'],
						'jedinica_mjere' => $kanonska['jedinica'],
					),
				);

			case Config::POLJE_KATEGORIJA:
				$kat = (string) ( $v['kategorija'] ?? '' );

				if ( '' === $kat ) {
					return array(
						'stupci' => array( 'zakonska_kategorija' => null ),
						'prazno' => true,
					);
				}

				if ( ! isset( Config::ZAKONSKE_KATEGORIJE[ $kat ] ) ) {
					return array( 'greska' => __( 'Nepoznata zakonska kategorija.', Config::TEXT_DOMAIN ) );
				}

				return array( 'stupci' => array( 'zakonska_kategorija' => $kat ) );
		}

		return array( 'greska' => __( 'Nepoznato polje.', Config::TEXT_DOMAIN ) );
	}

	/**
	 * Nosi li redak vec tocno te vrijednosti.
	 *
	 * Usporedba je BROJCANA gdje su obje strane brojevi: DECIMAL(12,4) vraca
	 * "1.0000" ondje gdje se upisuje "1", a to je ista vrijednost. Doslovna
	 * usporedba proglasila bi svaki upis promjenom i provjera ne bi vrijedila nista.
	 *
	 * @param array<string,mixed> $stupci
	 */
	private static function isto_stanje( int $entity_id, array $stupci ): bool {
		$redak = self::procitaj( $entity_id );

		if ( ! $redak ) {
			return false;
		}

		foreach ( $stupci as $stupac => $nova ) {
			$cist = preg_replace( '/[^a-z_]/', '', $stupac );

			if ( ! property_exists( $redak, $cist ) ) {
				return false;
			}

			$stara = $redak->$cist;

			if ( null === $nova || null === $stara ) {
				if ( $nova !== $stara ) {
					return false;
				}
				continue;
			}

			if ( is_numeric( $nova ) && is_numeric( $stara ) ) {
				if ( abs( (float) $nova - (float) $stara ) > 0.00001 ) {
					return false;
				}
				continue;
			}

			if ( (string) $nova !== (string) $stara ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $stupci */
	private static function pisi( int $entity_id, array $stupci ): void {
		global $wpdb;

		$postavke = array();
		$vrijed   = array();

		foreach ( $stupci as $stupac => $vrijednost ) {
			$cist = preg_replace( '/[^a-z_]/', '', $stupac );

			// NULL kao literal. Kroz prepare('%s', null) postao bi prazan string, a
			// prazan string u DECIMAL stupcu postaje nula — greska koja se u ovom
			// projektu vratila tri puta.
			if ( null === $vrijednost ) {
				$postavke[] = "`{$cist}` = NULL";
				continue;
			}

			$postavke[] = "`{$cist}` = %s";
			$vrijed[]   = $vrijednost;
		}

		if ( empty( $postavke ) ) {
			return;
		}

		$postavke[] = '`azurirano` = %s';
		$vrijed[]   = current_time( 'mysql', true );
		$vrijed[]   = $entity_id;

		// Redak mora postojati — artikl dodan nakon zadnjeg posla ga jos nema.
		self::osiguraj_redak( $entity_id );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::tablica() . '` SET ' . implode( ', ', $postavke ) . ' WHERE entity_id = %d',
				$vrijed
			) // phpcs:ignore
		);
	}

	private static function osiguraj_redak( int $entity_id ): void {
		global $wpdb;

		$postoji = $wpdb->get_var(
			$wpdb->prepare( 'SELECT entity_id FROM `' . self::tablica() . '` WHERE entity_id = %d', $entity_id ) // phpcs:ignore
		);

		if ( $postoji ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO `' . self::tablica() . '` ( entity_id, referentni_datum, azurirano ) VALUES ( %d, %s, %s )',
				$entity_id,
				\CJTR\Postavke::ref_datum(),
				current_time( 'mysql', true )
			) // phpcs:ignore
		);
	}
}
