<?php
/**
 * Provjera: je li objavljeni cjenik ispravan.
 *
 * ZASTO JE OVO DIJAGNOSTIKA
 *
 * Cjenik se objavljuje svaki dan u 8:00 bez da ga itko gleda, a WordPress i
 * WooCommerce se na produkciji azuriraju automatski preko vecih verzija. Bez ove
 * provjere prvi znak da je nesto puklo bio bi poziv inspekcije.
 *
 * Provjerava se OBJAVLJENA datoteka, ne kod koji je stvara — jedino to govori sto
 * kupac i inspektor stvarno dobiju.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Cjenik\Arhiva;
use CJTR\Cjenik\Izvor;
use CJTR\Cjenik\Redak;
use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Cjenik extends Provjera {

	public function kljuc(): string {
		return 'cjenik';
	}

	public function izvrsi(): Rezultat {
		$r = new Rezultat( __( 'Objavljeni cjenik', Config::TEXT_DOMAIN ) );

		$r->znacenje(
			__( 'Cjenik se objavljuje svaki dan bez da ga itko gleda, a okolina se automatski azurira. Ove provjere citaju objavljenu datoteku i javljaju ako se nesto razislo.', Config::TEXT_DOMAIN )
		);

		$oblik = Config::CJENIK_OBLICI[0];
		$put   = Arhiva::stabilna_putanja( $oblik );

		if ( ! file_exists( $put ) ) {
			return $r->status( Rezultat::UPOZ )
				->stavka( __( 'Datoteka', Config::TEXT_DOMAIN ), __( 'jos nije generirana', Config::TEXT_DOMAIN ) )
				->postupak( __( 'Pokrenite posao "Objavi dnevni cjenik".', Config::TEXT_DOMAIN ) );
		}

		$ishodi = array_merge(
			$this->o_datoteci( $r, $put, $oblik ),
			$this->o_sadrzaju(),
			$this->sinteticki()
		);

		$palo = array();

		foreach ( $ishodi as $i ) {
			if ( ! $i['ok'] ) {
				$palo[] = $i['opis'];
			}
		}

		if ( ! empty( $palo ) ) {
			$r->blok( __( 'Sto ne prolazi', Config::TEXT_DOMAIN ), implode( "\n", $palo ) );
			return $r->status( Rezultat::LOSE )
				->postupak( __( 'Cjenik je objavljen, ali nesto u njemu ne stoji. Provjerite prije iduceg roka.', Config::TEXT_DOMAIN ) );
		}

		return $r->status( Rezultat::OK );
	}

	/* --------------------------------------------------------------- interno */

	/** @return array<int,array{ok:bool,opis:string}> */
	private function o_datoteci( Rezultat $r, string $put, string $oblik ): array {
		$starost = time() - (int) filemtime( $put );
		$danasnja = Arhiva::od_danas( $oblik );

		$r->stavka(
			__( 'Zadnji put objavljeno', Config::TEXT_DOMAIN ),
			wp_date( 'd.m.Y. H:i', (int) filemtime( $put ) ) . ' (' . human_time_diff( (int) filemtime( $put ) ) . ')'
		);

		$r->stavka( __( 'Javna adresa', Config::TEXT_DOMAIN ), Arhiva::stabilni_url( $oblik ) );
		$r->stavka( __( 'Velicina', Config::TEXT_DOMAIN ), size_format( (int) filesize( $put ) ) );
		$r->stavka( __( 'Datoteka u arhivi', Config::TEXT_DOMAIN ), (string) count( Arhiva::popis() ) );

		return array(
			array(
				'ok'   => $danasnja,
				'opis' => sprintf(
					/* translators: %s = datum */
					__( 'objavljena datoteka nije od danas nego od %s', Config::TEXT_DOMAIN ),
					wp_date( 'd.m.Y.', (int) filemtime( $put ) )
				),
			),
			array(
				'ok'   => $starost < 2 * DAY_IN_SECONDS,
				'opis' => __( 'datoteka je starija od dva dana — cron vjerojatno ne radi', Config::TEXT_DOMAIN ),
			),
		);
	}

	/**
	 * Provjere nad sadrzajem, na uzorcima iz kataloga.
	 *
	 * @return array<int,array{ok:bool,opis:string}>
	 */
	private function o_sadrzaju(): array {
		global $wpdb;

		$ishodi = array();

		// 1. Varijabilni roditelj NE SMIJE biti u datoteci.
		$roditelj = (int) $wpdb->get_var(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			 JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id AND tm.slug = 'variable'
			 WHERE " . \CJTR\Katalog::uvjet() . ' LIMIT 1' // phpcs:ignore
		);

		if ( $roditelj ) {
			$u_cjeniku = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ( ' . self::upit_jednog( $roditelj ) . ' ) x', 1 ) // phpcs:ignore
			);

			$ishodi[] = array(
				'ok'   => ! $u_cjeniku,
				'opis' => sprintf( 'varijabilni roditelj #%d je u cjeniku, a ne smije biti', $roditelj ),
			);
		}

		// 2. Svaki zapis ima sva polja, i prazna polja su prazna a ne nula.
		$uzorak = Izvor::komad( 0, 50 );
		$bez_polja = 0;
		$nula      = 0;

		foreach ( $uzorak as $red ) {
			$zapis = Redak::iz( $red );

			foreach ( array_keys( Config::shema() ) as $kljuc ) {
				if ( ! array_key_exists( $kljuc, $zapis ) ) {
					$bez_polja++;
					break;
				}
			}

			// Prazna dodatna cijena mora biti PRAZNA, nikad "0" ni "0,00".
			if ( in_array( $zapis['sidrena_cijena'], array( '0', '0.00', '0,00' ), true ) ) {
				$nula++;
			}
		}

		$ishodi[] = array(
			'ok'   => 0 === $bez_polja,
			'opis' => sprintf( '%d zapisa nema sva polja iz sheme', $bez_polja ),
		);

		$ishodi[] = array(
			'ok'   => 0 === $nula,
			'opis' => sprintf( '%d zapisa ima dodatnu cijenu zapisanu kao nula umjesto prazno', $nula ),
		);

		// 3. Artikl na akciji: oznacen je kao posebni oblik prodaje, i oblik ima naziv.
		$akcijski = $wpdb->get_var(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_price'
			 JOIN {$wpdb->postmeta} mr ON mr.post_id = p.ID AND mr.meta_key = '_regular_price'
			 WHERE " . \CJTR\Katalog::uvjet() . "
			   AND mp.meta_value <> '' AND mr.meta_value <> ''
			   AND CAST( mp.meta_value AS DECIMAL(12,4) ) < CAST( mr.meta_value AS DECIMAL(12,4) )
			 LIMIT 1" // phpcs:ignore
		);

		if ( $akcijski ) {
			$red = $wpdb->get_row( self::upit_jednog( (int) $akcijski ) ); // phpcs:ignore

			if ( $red ) {
				$zapis    = Redak::iz( $red );
				$ishodi[] = array(
					'ok'   => Config::DA === $zapis['posebni_oblik'] && '' !== $zapis['naziv_posebnog_oblika'],
					'opis' => sprintf(
						'artikl na akciji #%d: posebni oblik "%s", naziv "%s"',
						(int) $akcijski,
						$zapis['posebni_oblik'],
						$zapis['naziv_posebnog_oblika']
					),
				);
			}
		}

		return $ishodi;
	}

	/**
	 * Slucajevi kojih u katalogu nema, gradeni sinteticki.
	 *
	 * `Redak::iz()` je cista funkcija: prima redak upita, vraca zapis. Zato se
	 * slucaj moze sastaviti ovdje, bez ijedne izmjene u bazi.
	 *
	 * Bez ovoga bi dvije od sest trazenih provjera bile prazne: nakon promocije u
	 * katalogu nema nijednog artikla na akciji, a svi artikli s cijenom imaju i
	 * dodatnu cijenu. Provjera koja nema sto provjeriti prolazi iz krivog razloga.
	 *
	 * @return array<int,array{ok:bool,opis:string}>
	 */
	private function sinteticki(): array {
		$napravi = function ( array $p ) {
			$r = new \stdClass();

			$zadano = array(
				'entity_id' => 0, 'post_type' => 'product', 'naziv' => 'x', 'sku' => 'x',
				'price' => null, 'regular_price' => null, 'marka' => null,
				'barkod' => null, 'barkod_status' => null,
				'neto_kolicina' => null, 'jedinica_mjere' => null,
				'zakonska_kategorija' => null, 'sidrena_cijena' => null, 'vrsta_pop' => null,
				'stock_status' => 'instock',
			);

			foreach ( $zadano as $k => $v ) {
				$r->$k = $p[ $k ] ?? $v;
			}

			return $r;
		};

		$ishodi = array();

		// Artikl na akciji: maloprodajna je akcijska, redovna je redovna, vrsta oznacena.
		$z        = Redak::iz( $napravi( array( 'price' => '39.90', 'regular_price' => '79.90' ) ) );
		/*
		 * Ocekuje se '39.90', ne '39.9'. Iznosi u novcu pisu se s najmanje dvije
		 * decimale — bez toga je isti artikl imao `maloprodajna_cijena = 9.2` i
		 * `cijena_za_jedinicu_mjere = 9.20`, dvije brojke za istu cijenu (Z1).
		 */
		$ishodi[] = array(
			'ok'   => '39.90' === $z['maloprodajna_cijena']
				&& Config::DA === $z['posebni_oblik']
				&& '' !== $z['naziv_posebnog_oblika'],
			'opis' => sprintf(
				'artikl na akciji: maloprodajna "%s" mora imati dvije decimale, posebni oblik "%s" mora biti "da" uz naziv "%s"',
				$z['maloprodajna_cijena'],
				$z['posebni_oblik'],
				$z['naziv_posebnog_oblika']
			),
		);

		// Artikl koji NIJE na akciji: polje je "ne", naziv oblika prazan.
		$z        = Redak::iz( $napravi( array( 'price' => '39.90', 'regular_price' => '39.90' ) ) );
		$ishodi[] = array(
			'ok'   => Config::NE === $z['posebni_oblik'] && '' === $z['naziv_posebnog_oblika'],
			'opis' => sprintf(
				'artikl bez akcije: posebni oblik "%s" mora biti "ne", naziv "%s" prazan',
				$z['posebni_oblik'],
				$z['naziv_posebnog_oblika']
			),
		);

		// Rucno upisana vrsta posebne prodaje ima prednost pred izvedenom.
		$z        = Redak::iz( $napravi( array( 'price' => '5', 'regular_price' => '10', 'vrsta_pop' => Config::POP_RASPRODAJA ) ) );
		$ishodi[] = array(
			'ok'   => Config::NAZIV_POSEBNOG_OBLIKA[ Config::POP_RASPRODAJA ] === $z['naziv_posebnog_oblika'],
			'opis' => 'rucno upisana vrsta posebne prodaje mora imati prednost pred izvedenom',
		);

		// Prazna sidrena cijena: polje postoji i PRAZNO je, nikad nula.
		$z        = Redak::iz( $napravi( array( 'price' => '10', 'sidrena_cijena' => null ) ) );
		$ishodi[] = array(
			'ok'   => array_key_exists( 'sidrena_cijena', $z ) && '' === $z['sidrena_cijena'],
			'opis' => 'prazna sidrena cijena mora izaci kao prazno polje, nikad kao 0',
		);

		/*
		 * Dostupnost dolazi iz stanja zaliha, a roba na cekanju racuna se kao
		 * dostupna — trgovina je aktivno prodaje. Vidi Config::CEKANJE_JE_DOSTUPNO.
		 */
		foreach ( array(
			array( 'instock', Config::DOSTUPNO ),
			array( 'outofstock', Config::NEDOSTUPNO ),
			array( 'onbackorder', Config::CEKANJE_JE_DOSTUPNO ? Config::DOSTUPNO : Config::NEDOSTUPNO ),
			array( '', Config::DOSTUPNO ),
		) as $slucaj ) {
			$z        = Redak::iz( $napravi( array( 'price' => '1', 'stock_status' => $slucaj[0] ) ) );
			$ishodi[] = array(
				'ok'   => $slucaj[1] === $z['dostupnost'],
				'opis' => sprintf(
					'zaliha "%s" mora dati dostupnost "%s", dobiveno "%s"',
					'' === $slucaj[0] ? '(nepoznato)' : $slucaj[0],
					$slucaj[1],
					$z['dostupnost']
				),
			);
		}

		/*
		 * Komadna roba: jedinica mjere i cijena po jedinici se IZOSTAVLJAJU.
		 *
		 * Odluka za ta dva polja kaze "ako je primjenjivo". Spil karata nema cijenu
		 * po kilogramu — to nije podatak koji nedostaje nego podatak koji ne postoji,
		 * i prazan element bi tvrdio nesto drugo.
		 */
		$z        = Redak::iz( $napravi( array( 'price' => '9.20', 'neto_kolicina' => '1', 'jedinica_mjere' => 'kom' ) ) );
		$ishodi[] = array(
			'ok'   => null === $z['jedinica_mjere'] && null === $z['cijena_za_jedinicu'],
			'opis' => 'komadna roba: jedinica mjere i cijena po jedinici moraju se IZOSTAVITI, ne izaci prazne',
		);

		/*
		 * Granica objave barkoda.
		 *
		 * NE IZLAZI ono sto barkod ne moze biti (pogresna duljina, sve nule) i ono
		 * sto je valjan kod ali opisuje drugu stvar (transportno pakiranje, interni
		 * kod). IZLAZI ono sto je sporno — ispravna duljina, kriva kontrolna
		 * znamenka — jer je moguce da je broj pravi a znamenka krivo prepisana.
		 */
		foreach ( array(
			array( '10614141000415', Config::GTIN_TRANSPORTNI, false ),
			array( '2001234567890', Config::GTIN_INTERNI, false ),
			array( '40', Config::GTIN_NEMOGUC, false ),
			array( '100', Config::GTIN_NEMOGUC, false ),
			array( '0000000000000', Config::GTIN_NEMOGUC, false ),
			array( '3858881840013', Config::GTIN_NEISPRAVAN, true ),
			array( '4006381333931', Config::GTIN_VALJAN, true ),
		) as $slucaj ) {
			$z        = Redak::iz( $napravi( array( 'price' => '1', 'barkod' => $slucaj[0], 'barkod_status' => $slucaj[1] ) ) );
			$izasao   = ( '' !== $z['barkod'] );
			$ishodi[] = array(
				'ok'   => $izasao === $slucaj[2],
				'opis' => sprintf(
					'barkod "%s" (%s) %s izaci u cjenik',
					$slucaj[0],
					$slucaj[1],
					$slucaj[2] ? 'mora' : 'ne smije'
				),
			);
		}

		// Status se mora slagati sa zapisom: "40" je nemoguc, ne samo neispravan.
		foreach ( array(
			array( '40', Config::GTIN_NEMOGUC ),
			array( '100', Config::GTIN_NEMOGUC ),
			array( 'abc', Config::GTIN_NEMOGUC ),
			array( '3858881840013', Config::GTIN_NEISPRAVAN ),
			array( '4006381333931', Config::GTIN_VALJAN ),
		) as $slucaj ) {
			$dobiven  = \CJTR\Podaci\Gtin::status( $slucaj[0] );
			$ishodi[] = array(
				'ok'   => $slucaj[1] === $dobiven,
				'opis' => sprintf( '"%s" mora biti %s, dobiveno %s', $slucaj[0], $slucaj[1], $dobiven ),
			);
		}

		// Cijena po jedinici racuna se iz EFEKTIVNE cijene, ne redovne.
		$z        = Redak::iz(
			$napravi(
				array(
					'price'          => '2.50',
					'regular_price'  => '5.00',
					'neto_kolicina'  => '0.5',
					'jedinica_mjere' => 'kg',
				)
			)
		);
		$ishodi[] = array(
			'ok'   => '5.00' === $z['cijena_za_jedinicu'],
			'opis' => 'cijena po jedinici mora se racunati iz akcijske cijene, ne redovne',
		);

		// Generator ne zaokruzuje.
		$z        = Redak::iz( $napravi( array( 'price' => '3.8490' ) ) );
		$ishodi[] = array(
			'ok'   => '3.849' === $z['maloprodajna_cijena'],
			'opis' => sprintf( 'generator ne smije zaokruzivati cijenu (dobiveno "%s")', $z['maloprodajna_cijena'] ),
		);

		// Iznos s jednom decimalom nadopunjuje se do dvije, ali se ne zaokruzuje.
		$z        = Redak::iz( $napravi( array( 'price' => '9.2' ) ) );
		$ishodi[] = array(
			'ok'   => '9.20' === $z['maloprodajna_cijena'],
			'opis' => sprintf( 'iznos "9.2" mora izaci kao "9.20" (dobiveno "%s")', $z['maloprodajna_cijena'] ),
		);

		/*
		 * Tocno jedna kanonska jedinica: maloprodajna i cijena po jedinici mjere
		 * moraju biti ISTI ZAPIS, ne samo blizu.
		 *
		 * Ranije je ista provjera stajala nad komadnom robom. NN 101/2026 za nju to
		 * polje vise ne objavljuje, pa bi provjera tiho prestala vrijediti — a artikl
		 * od tocno 1 kg nosi isti problem i dalje se objavljuje.
		 */
		$z        = Redak::iz( $napravi( array( 'price' => '9.2', 'neto_kolicina' => '1', 'jedinica_mjere' => 'kg' ) ) );
		$ishodi[] = array(
			'ok'   => '9.20' === $z['maloprodajna_cijena'] && $z['maloprodajna_cijena'] === $z['cijena_za_jedinicu'],
			'opis' => sprintf(
				'artikl od 1 kg: maloprodajna "%s" i cijena po jedinici "%s" moraju biti ISTI zapis',
				$z['maloprodajna_cijena'],
				(string) $z['cijena_za_jedinicu']
			),
		);

		return $ishodi;
	}

	/** Upit koji vraca jedan entitet kroz istu putanju kojom ide i cjenik. */
	private static function upit_jednog( int $id ): string {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );
		$np     = Config::table( Config::TABLE_NEPRIMJENJIVO );

		return "SELECT p.ID AS entity_id, p.post_type,
			COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
			COALESCE( l.sku, '' ) AS sku,
			mp.meta_value AS price, mr.meta_value AS regular_price,
			c.marka, c.barkod, c.barkod_status, c.neto_kolicina, c.jedinica_mjere,
			c.zakonska_kategorija, c.sidrena_cijena
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
			LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
			LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_price'
			LEFT JOIN {$wpdb->postmeta} mr ON mr.post_id = p.ID AND mr.meta_key = '_regular_price'
			WHERE p.ID = " . (int) $id . "
			  AND mp.meta_value IS NOT NULL AND mp.meta_value <> ''
			  AND NOT EXISTS (
			      SELECT 1 FROM `{$np}` n
			      WHERE n.entity_id = p.ID AND n.polje = '" . esc_sql( Config::POLJE_KATEGORIJA ) . "'
			        AND n.razlog LIKE '%varijabiln%' )";
	}
}
