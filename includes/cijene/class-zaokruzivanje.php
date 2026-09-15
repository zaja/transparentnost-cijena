<?php
/**
 * Politika zaokruzivanja: FLOOR na dvije decimale.
 *
 * ZASTO FLOOR, A NE NA NAJBLIZU VRIJEDNOST
 *
 * Zaokruzivanje na najblizu vrijednost podize cijenu u pola slucajeva; floor je
 * nikad ne podize. Kod mjere ciji je smisao zastita potrosaca to je jedini smjer
 * koji se ne mora braniti. Puno obrazlozenje je u ANALIZA.md, sekcija K.
 *
 * ISTA POLITIKA, ISTI PROLAZ
 *
 * `_price`, `_regular_price`, `_sale_price` i dodatna cijena — sve odjednom. Kad
 * bi se tekuca cijena zaokruzila danas a dodatna sutra, nastala bi umjetna razlika
 * koja izgleda kao promjena cijene.
 *
 * @package CJTR
 */

namespace CJTR\Cijene;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Zaokruzivanje {

	/**
	 * Primijeni politiku na jednu vrijednost.
	 *
	 * Racuna se preko stringa, ne `floor($v * 100) / 100`: mnozenje s 100 kod
	 * vrijednosti poput 18.44847 daje 1844.8469999999998, pa floor vrati 1844
	 * umjesto 1844 — tocno, ali kod 5.10 daje 509.99999 i floor vrati 5.09.
	 * Rezanje decimala u zapisu nema tu zamku.
	 *
	 * @return string|null null ako ulaz nije broj
	 */
	public static function primijeni( $vrijednost ): ?string {
		if ( null === $vrijednost || '' === $vrijednost || ! is_numeric( $vrijednost ) ) {
			return null;
		}

		$s = (string) $vrijednost;

		// Negativna cijena nije predmet ove politike i ne dira se.
		if ( 0 === strpos( $s, '-' ) ) {
			return null;
		}

		$tocka = strpos( $s, '.' );

		if ( false === $tocka ) {
			return number_format( (float) $s, Config::ZAOKRUZIVANJE_DECIMALA, '.', '' );
		}

		$cijeli   = substr( $s, 0, $tocka );
		$decimale = substr( $s, $tocka + 1 );

		if ( strlen( $decimale ) <= Config::ZAOKRUZIVANJE_DECIMALA ) {
			return number_format( (float) $s, Config::ZAOKRUZIVANJE_DECIMALA, '.', '' );
		}

		return $cijeli . '.' . substr( $decimale, 0, Config::ZAOKRUZIVANJE_DECIMALA );
	}

	/**
	 * Koliko se tocno oduzima — odrezani dio zapisa, bez racuna u pomicnom zarezu.
	 *
	 * `3.849 - 3.84` u floatu daje 0.008999999999999897. Prikazan na cetiri decimale
	 * to je 0,0090, a zbroj takvih prikaza po 87 redaka ne ispadne jednak iznosu koji
	 * pise na ekranu — popis i ekran postanu dvije tvrdnje o istom broju. Odrezani
	 * dio zapisa (`9` iza `3.84`) je egzaktan i nema tu zamku.
	 *
	 * @return string prazno ako politika nista ne mijenja
	 */
	public static function razlika( $vrijednost ): string {
		if ( ! self::mijenja( $vrijednost ) ) {
			return '';
		}

		$s     = (string) $vrijednost;
		$tocka = strpos( $s, '.' );

		if ( false === $tocka ) {
			return '';
		}

		$odrezano = substr( $s, $tocka + 1 + Config::ZAOKRUZIVANJE_DECIMALA );

		if ( '' === $odrezano ) {
			return '';
		}

		return '0.' . str_repeat( '0', Config::ZAOKRUZIVANJE_DECIMALA ) . $odrezano;
	}

	/** Mijenja li politika ovu vrijednost. */
	public static function mijenja( $vrijednost ): bool {
		$novo = self::primijeni( $vrijednost );

		if ( null === $novo ) {
			return false;
		}

		return abs( (float) $novo - (float) $vrijednost ) > 0.0000001;
	}

	/**
	 * Artikli kojima politika nesto mijenja.
	 *
	 * @return object[]
	 */
	public static function pogodeni( int $nakon_id = 0, int $koliko = 0 ): array {
		global $wpdb;

		$podaci = Config::table( Config::TABLE_PODACI );
		$uzorak   = '\\.[0-9]{' . ( Config::ZAOKRUZIVANJE_DECIMALA + 1 ) . ',}';
		$decimala = (int) Config::ZAOKRUZIVANJE_DECIMALA;

		$mete = array();
		foreach ( Config::ZAOKRUZIVANJE_META as $m ) {
			$mete[] = "'" . esc_sql( $m ) . "'";
		}
		$mete = implode( ',', $mete );

		$rep = ' ORDER BY p.ID ASC';
		if ( $koliko > 0 ) {
			$rep .= ' LIMIT ' . (int) $koliko;
		}

		/*
		 * Dodatna cijena je DECIMAL(12,4), pa joj zapis UVIJEK ima cetiri decimale.
		 * Regex nad njezinim tekstualnim oblikom pogada svaki redak i vraca cijeli
		 * katalog — izmjereno 3600 od 3623. Zato se usporeduje VRIJEDNOST s odrezanom
		 * vrijednoscu, a regex ostaje samo za metu, koja je varchar.
		 */
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS entity_id,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				        c.sidrena_cijena
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 LEFT JOIN `{$podaci}` c ON c.entity_id = p.ID
				 WHERE " . Katalog::uvjet() . " AND p.ID > %d
				   AND (
				       EXISTS (
				           SELECT 1 FROM {$wpdb->postmeta} m
				           WHERE m.post_id = p.ID AND m.meta_key IN ({$mete})
				             AND m.meta_value REGEXP '{$uzorak}'
				       )
				       OR ( c.sidrena_cijena IS NOT NULL
				            AND c.sidrena_cijena <> TRUNCATE( c.sidrena_cijena, {$decimala} ) )
				   )
				 " . $rep,
				$nakon_id
			) // phpcs:ignore
		);
	}

	public static function broj_pogodenih(): int {
		return count( self::pogodeni() );
	}

	/**
	 * Probni prolaz: sto bi se tocno promijenilo. NE PISE NISTA.
	 *
	 * Potvrda bez ovoga je potpis na prazan papir. Ista se lista prikazuje na ekranu
	 * (skracena) i preuzima kao CSV (cijela), pa se moze pogledati prije nego se
	 * dira i jedna cijena.
	 *
	 * @param int $limit 0 = sve.
	 * @return array<int,array{entity_id:int,naziv:string,polja:array<string,array{0:string,1:string}>,razlika:float}>
	 */
	public static function promjene( int $limit = 0 ): array {
		$izlaz = array();

		foreach ( self::pogodeni() as $r ) {
			$id    = (int) $r->entity_id;
			$polja = array();

			foreach ( Config::ZAOKRUZIVANJE_META as $meta ) {
				$staro = (string) get_post_meta( $id, $meta, true );

				if ( '' === $staro || ! self::mijenja( $staro ) ) {
					continue;
				}

				$polja[ $meta ] = array( $staro, (string) self::primijeni( $staro ) );
			}

			if ( null !== $r->sidrena_cijena && self::mijenja( $r->sidrena_cijena ) ) {
				$polja['sidrena_cijena'] = array(
					(string) $r->sidrena_cijena,
					(string) self::primijeni( $r->sidrena_cijena ),
				);
			}

			if ( empty( $polja ) ) {
				continue;
			}

			// Razlika se mjeri na cijeni koja se naplacuje — ostale prate.
			$razlika = isset( $polja['_price'] )
				? (float) self::razlika( $polja['_price'][0] )
				: 0.0;

			$izlaz[] = array(
				'entity_id' => $id,
				'naziv'     => (string) $r->naziv,
				'polja'     => $polja,
				'razlika'   => round( $razlika, 4 ),
			);

			if ( $limit > 0 && count( $izlaz ) >= $limit ) {
				break;
			}
		}

		return $izlaz;
	}

	/**
	 * Financijski ucinak: koliko se ukupno snizuje, i koliko to znaci godisnje.
	 *
	 * Kolicine se citaju AGREGATNO iz stavki narudzbi. Nijedan podatak o kupcima
	 * se ne dira.
	 *
	 * @return array{artikala:int,zbroj_po_komadu:float,prodavanih:int,komada:int,godisnje:float}
	 */
	public static function ucinak(): array {
		global $wpdb;

		$oi  = $wpdb->prefix . 'woocommerce_order_items';
		$oim = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$od  = gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS );

		$zbroj      = 0.0;
		$godisnje   = 0.0;
		$prodavanih = 0;
		$komada     = 0;
		$artikala   = 0;

		foreach ( self::pogodeni() as $r ) {
			$cijena = get_post_meta( (int) $r->entity_id, '_price', true );

			if ( '' === $cijena || ! self::mijenja( $cijena ) ) {
				continue;
			}

			$artikala++;

			$razlika = (float) self::razlika( $cijena );
			$zbroj  += $razlika;

			$prodano = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE( SUM( CAST( q.meta_value AS SIGNED ) ), 0 )
					 FROM `{$oi}` i
					 JOIN `{$oim}` pid ON pid.order_item_id = i.order_item_id AND pid.meta_key = '_product_id'
					 JOIN `{$oim}` q ON q.order_item_id = i.order_item_id AND q.meta_key = '_qty'
					 JOIN {$wpdb->posts} o ON o.ID = i.order_id AND o.post_type = 'shop_order'
					 WHERE i.order_item_type = 'line_item' AND pid.meta_value = %d AND o.post_date_gmt >= %s",
					(int) $r->entity_id,
					$od
				) // phpcs:ignore
			);

			if ( $prodano > 0 ) {
				$prodavanih++;
				$komada   += $prodano;
				$godisnje += $razlika * $prodano;
			}
		}

		return array(
			'artikala'        => $artikala,
			'zbroj_po_komadu' => round( $zbroj, 4 ),
			'prodavanih'      => $prodavanih,
			'komada'          => $komada,
			'godisnje'        => round( $godisnje, 2 ),
		);
	}
}
