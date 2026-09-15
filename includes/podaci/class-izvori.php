<?php
/**
 * Odakle podatak dolazi — lanac izvora, i sto kad se dva ne slazu.
 *
 * PRAVILO: NE BIRA SE TIHO
 *
 * Kad dva izvora kazu razlicito, jaci se upise a razlika se ZAPISE. Tiho biranje
 * je najgora od tri moguce odluke: kriva vrijednost se ne primijeti, a tocna se
 * izgubi bez traga. Ovako trgovac dobije popis mjesta gdje njegovi podaci sami
 * sebi proturjece — sto je korisno i neovisno o tome koji je izvor bio u pravu.
 *
 * Svaki izvor vraca `array{vrijednost, izvor}` ili null. Nikad prazan string —
 * prazan string i "nema podatka" nisu ista stvar.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Izvori {

	/**
	 * Prikupi sve sto izvori kazu o jednom polju.
	 *
	 * @return array<int,array{vrijednost:string,izvor:string}> redom, najjaci prvi
	 */
	public static function prikupi( int $entity_id, string $polje ): array {
		switch ( $polje ) {
			case Config::POLJE_BARKOD:
				$nalazi = self::barkod( $entity_id );
				break;
			case Config::POLJE_MARKA:
				$nalazi = self::marka( $entity_id );
				break;
			case Config::POLJE_KOLICINA:
				$nalazi = self::kolicina( $entity_id );
				break;
			default:
				return array();
		}

		usort(
			$nalazi,
			function ( $a, $b ) {
				return ( Config::SNAGA_IZVORA_PODATKA[ $b['izvor'] ] ?? 0 )
					<=> ( Config::SNAGA_IZVORA_PODATKA[ $a['izvor'] ] ?? 0 );
			}
		);

		return $nalazi;
	}

	/**
	 * Sto upisati i je li bilo neslaganja.
	 *
	 * @return array{vrijednost:string,izvor:string,sukob:?array}|null
	 */
	public static function odluci( int $entity_id, string $polje ): ?array {
		$nalazi = self::prikupi( $entity_id, $polje );

		if ( empty( $nalazi ) ) {
			return null;
		}

		$pobjednik = $nalazi[0];
		$sukob     = null;

		foreach ( array_slice( $nalazi, 1 ) as $drugi ) {
			if ( self::isto( $polje, $pobjednik['vrijednost'], $drugi['vrijednost'] ) ) {
				continue;
			}

			// Prvi neslaganje je dovoljno za prijavu. Vise njih nabrojati znaci
			// pretvoriti izvjestaj u popis koji nitko ne cita.
			$sukob = array(
				'izvor_a'      => $pobjednik['izvor'],
				'vrijednost_a' => $pobjednik['vrijednost'],
				'izvor_b'      => $drugi['izvor'],
				'vrijednost_b' => $drugi['vrijednost'],
			);
			break;
		}

		return array(
			'vrijednost' => $pobjednik['vrijednost'],
			'izvor'      => $pobjednik['izvor'],
			'sukob'      => $sukob,
		);
	}

	/**
	 * Jesu li dvije vrijednosti istog polja zapravo iste.
	 *
	 * Usporedba ovisi o polju: barkodovi 036000291452 i 0036000291452 su isti kod,
	 * a "COPAG" i "Copag" ista marka. Doslovna usporedba prijavila bi lazne sukobe
	 * i izvjestaj bi postao smece.
	 */
	private static function isto( string $polje, string $a, string $b ): bool {
		if ( Config::POLJE_BARKOD === $polje ) {
			return Gtin::u_ean13( $a ) === Gtin::u_ean13( $b );
		}

		if ( Config::POLJE_MARKA === $polje ) {
			return Marke::kanonski( $a ) === Marke::kanonski( $b );
		}

		if ( Config::POLJE_KOLICINA === $polje ) {
			$ka = Kolicina::razlozi( $a );
			$kb = Kolicina::razlozi( $b );
			if ( $ka && $kb ) {
				return $ka['jedinica'] === $kb['jedinica']
					&& abs( $ka['kolicina'] - $kb['kolicina'] ) < 0.000001;
			}
		}

		return mb_strtolower( trim( $a ) ) === mb_strtolower( trim( $b ) );
	}

	/* ------------------------------------------------------------- barkod */

	/**
	 * Lanac: WooCommerceovo vlastito polje, pa meta drugih dodataka, pa tekst.
	 *
	 * Parsiranje iz naziva i opisa je NAJSLABIJE i tu je namjerno: na ovom katalogu
	 * daje 2 pogotka od 691. Vrijedi imati, ali nikad ne smije prepisati unos.
	 */
	private static function barkod( int $entity_id ): array {
		$nalazi = array();

		foreach ( Config::META_BARKODA as $kljuc ) {
			$v = get_post_meta( $entity_id, $kljuc, true );
			if ( '' === $v || null === $v ) {
				continue;
			}

			$cist = Gtin::ocisti( (string) $v );
			if ( '' === $cist ) {
				continue;
			}

			$nalazi[] = array(
				'vrijednost' => $cist,
				'izvor'      => Config::IZVOR_PODATKA_META,
			);
		}

		$post = get_post( $entity_id );
		if ( $post ) {
			$tekst = $post->post_title . ' ' . $post->post_excerpt . ' ' . $post->post_content;
			foreach ( Gtin::kandidati( $tekst ) as $kandidat ) {
				$nalazi[] = array(
					'vrijednost' => $kandidat,
					'izvor'      => Config::IZVOR_PODATKA_NAZIV,
				);
			}
		}

		return $nalazi;
	}

	/* -------------------------------------------------------------- marka */

	private static function marka( int $entity_id ): array {
		$nalazi = array();

		/*
		 * Varijacija nasljeduje marku roditelja. To nije heuristika nego definicija:
		 * majica S i majica XXL istog proizvoda su iste marke. Bez ovoga bi 2950
		 * varijacija ostalo prazno iako je podatak poznat, a to je u ovom katalogu
		 * 81 % svih redaka.
		 *
		 * Citaju se RODITELJEVI izvori, ne nas upisani redak — poredak obrade ne bi
		 * smio odlucivati hoce li varijacija dobiti marku.
		 */
		$post = get_post( $entity_id );
		if ( $post && 'product_variation' === $post->post_type && $post->post_parent ) {
			foreach ( self::marka( (int) $post->post_parent ) as $roditeljev ) {
				$nalazi[] = array(
					'vrijednost' => $roditeljev['vrijednost'],
					'izvor'      => Config::IZVOR_PODATKA_RODITELJ,
				);
			}
		}

		foreach ( Config::TAKSONOMIJE_MARKE as $taksonomija ) {
			if ( ! taxonomy_exists( $taksonomija ) ) {
				continue;
			}

			$termini = wp_get_post_terms( $entity_id, $taksonomija, array( 'fields' => 'names' ) );
			if ( is_wp_error( $termini ) ) {
				continue;
			}

			foreach ( $termini as $ime ) {
				$nalazi[] = array(
					'vrijednost' => (string) $ime,
					'izvor'      => Config::IZVOR_PODATKA_TAKSONOMIJA,
				);
			}
		}

		// Atribut nazvan "marka" ili "brand", u bilo kojem pisanju.
		$proizvod = function_exists( 'wc_get_product' ) ? wc_get_product( $entity_id ) : null;
		if ( $proizvod && method_exists( $proizvod, 'get_attributes' ) ) {
			foreach ( $proizvod->get_attributes() as $ime => $atribut ) {
				if ( ! preg_match( '/(marka|brand|proizvodac)/i', (string) $ime ) ) {
					continue;
				}
				$v = $proizvod->get_attribute( $ime );
				if ( '' !== $v ) {
					$nalazi[] = array(
						'vrijednost' => (string) $v,
						'izvor'      => Config::IZVOR_PODATKA_ATRIBUT,
					);
				}
			}
		}

		foreach ( Config::META_MARKE as $kljuc ) {
			$v = get_post_meta( $entity_id, $kljuc, true );
			if ( is_string( $v ) && '' !== $v ) {
				$nalazi[] = array(
					'vrijednost' => $v,
					'izvor'      => Config::IZVOR_PODATKA_META,
				);
			}
		}

		$post = get_post( $entity_id );
		if ( $post ) {
			$naslov = $post->post_title;
			if ( '' === trim( $naslov ) && $post->post_parent ) {
				$roditelj = get_post( $post->post_parent );
				$naslov   = $roditelj ? $roditelj->post_title : '';
			}

			$iz_naziva = Marke::iz_naziva( $naslov );
			if ( null !== $iz_naziva ) {
				$nalazi[] = array(
					'vrijednost' => $iz_naziva,
					'izvor'      => Config::IZVOR_PODATKA_NAZIV,
				);
			}
		}

		return $nalazi;
	}

	/* ----------------------------------------------------------- kolicina */

	/**
	 * Lanac: dimenzije, pa atributi, pa naziv.
	 *
	 * `_weight` NAMJERNO NIJE U LANCU — vidi `prijedlog_iz_tezine()`.
	 */
	private static function kolicina( int $entity_id ): array {
		$nalazi = array();

		// Dimenzije daju povrsinu samo kad su obje poznate. Jedna dimenzija sama
		// nije neto kolicina nego podatak o obliku.
		$duljina = get_post_meta( $entity_id, '_length', true );
		$sirina  = get_post_meta( $entity_id, '_width', true );

		if ( '' !== $duljina && '' !== $sirina && (float) $duljina > 0 && (float) $sirina > 0 ) {
			$jedinica = get_option( 'woocommerce_dimension_unit', 'cm' );
			if ( Kolicina::poznata( $jedinica ) ) {
				$nalazi[] = array(
					'vrijednost' => $duljina . $jedinica . ' x ' . $sirina . $jedinica,
					'izvor'      => Config::IZVOR_PODATKA_DIMENZIJE,
				);
			}
		}

		$proizvod = function_exists( 'wc_get_product' ) ? wc_get_product( $entity_id ) : null;
		if ( $proizvod && method_exists( $proizvod, 'get_attributes' ) ) {
			foreach ( $proizvod->get_attributes() as $ime => $atribut ) {
				if ( ! preg_match( '/(kolicina|volumen|tezina|sadrzaj|pakiranje|weight|volume)/i', (string) $ime ) ) {
					continue;
				}
				$v = $proizvod->get_attribute( $ime );
				if ( '' !== $v && Kolicina::razlozi( $v ) ) {
					$nalazi[] = array(
						'vrijednost' => (string) $v,
						'izvor'      => Config::IZVOR_PODATKA_ATRIBUT,
					);
				}
			}
		}

		$post = get_post( $entity_id );
		if ( $post ) {
			$naslov = $post->post_title;
			if ( '' === trim( $naslov ) && $post->post_parent ) {
				$roditelj = get_post( $post->post_parent );
				$naslov   = $roditelj ? $roditelj->post_title : '';
			}

			if ( Kolicina::razlozi( $naslov ) ) {
				$nalazi[] = array(
					'vrijednost' => $naslov,
					'izvor'      => Config::IZVOR_PODATKA_NAZIV,
				);
			}
		}

		return $nalazi;
	}

	/**
	 * Dostavna tezina kao PRIJEDLOG covjeku — nikad kao upis.
	 *
	 * ZASTO `_weight` NIJE IZVOR NETO KOLICINE
	 *
	 * Dva razloga, i drugi je jaci od prvog.
	 *
	 * 1. `_weight` je DOSTAVNA tezina, ne neto sadrzaj. Spil karata od 0,2 kg tezi
	 *    toliko s kutijom i celofanom; neto sadrzaj su 52 karte.
	 *
	 * 2. Vaznije: cijena po jedinici mjere obvezna je samo za robu koja se prodaje
	 *    po masi, obujmu, duzini ili povrsini. Spil karata se prodaje PO KOMADU —
	 *    njegova jedinica mjere je "kom". `_weight` bi mu dao polje koje taj artikl
	 *    uopce ne treba, i objavio EUR/kg za spil karata.
	 *
	 * Za javnu verziju to vrijedi jednako, i to je jaci razlog nego ovdje: svaki
	 * korisnik ima `_weight` popunjen jer ga trazi dostava. Automatsko uzimanje
	 * dalo bi svakom od njih besmislenu cijenu po jedinici mjere — i to tiho,
	 * jer bi brojka izgledala kao podatak.
	 *
	 * Zato: prijedlog na ekranu, uz pitanje. Odgovor daje covjek.
	 *
	 * @return array{vrijednost:string,kolicina:float,jedinica:string}|null
	 */
	public static function prijedlog_iz_tezine( int $entity_id ): ?array {
		$tezina = get_post_meta( $entity_id, '_weight', true );

		if ( '' === $tezina || null === $tezina || (float) $tezina <= 0 ) {
			return null;
		}

		$jedinica = get_option( 'woocommerce_weight_unit', 'kg' );

		if ( ! Kolicina::poznata( $jedinica ) ) {
			return null;
		}

		return array(
			'vrijednost' => $tezina . ' ' . $jedinica,
			'kolicina'   => (float) $tezina,
			'jedinica'   => $jedinica,
		);
	}
}
