<?php
/**
 * Koji je posebni oblik prodaje na snazi — i tko to kaze.
 *
 * ZASTO SE VEZE UZ RAZDOBLJE, A NE UZ ARTIKL
 *
 * Isti artikl moze u ozujku biti na akciji, a u prosincu na rasprodaji. Oznaka
 * zapisana "na artikl" bila bi tocna jednom i netocna svaki sljedeci put, a nitko
 * je ne bi dosao ispraviti — pa bi propisana objava iznosila prosle godine tocnu
 * tvrdnju o ovogodisnjoj cijeni.
 *
 * Zato oznaka stoji na OTVORENOM INTERVALU u vlastitoj povijesti cijena. Kad se
 * cijena promijeni, interval se zatvara i otvara novi — a s njim se oznaka vraca
 * na zadano. Trgovac koji je u prosincu rekao "rasprodaja" ne mora se sjetiti da
 * to u sijecnju povuce.
 *
 * ZASTO IZVEDENA VRIJEDNOST OSTAJE ZADANA
 *
 * 'akcijska' je definicija prodaje po cijeni nizoj od redovne i najcesci slucaj.
 * Bez trgovceva izbora ostaje ona — polje je obvezno i ne smije izaci prazno samo
 * zato sto se nitko nije izjasnio.
 *
 * Uz to: oznaka se NE cita kao jedini izvor istine. Ako povijest za neki artikl
 * kaze 'nema' a cijena je ocito niza od redovne, objavljuje se izvedena vrijednost.
 * Zapis u povijesti moze biti nepotpun; cijena u bazi je cinjenica.
 *
 * @package CJTR
 */

namespace CJTR\Povijest;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Vrsta_Prodaje {

	/** Vrste koje trgovac bira sam; ostalo se izvodi iz cijena. */
	const RUCNE = array( Config::POP_RASPRODAJA, Config::POP_SEZONSKO );

	/**
	 * Oznaka zapisana na otvorenom intervalu, ili prazno.
	 *
	 * Vraca SAMO ono sto je trgovac izabrao. Izvedenu vrijednost ne vraca — nju
	 * racuna `Cjenik\Redak`, iz cijena koje su cinjenica.
	 */
	public static function izabrana( int $entity_id ): string {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		$v = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT vrsta_pop FROM `{$t}` WHERE entity_id = %d AND ts_end = 0 ORDER BY ts DESC LIMIT 1",
				$entity_id
			) // phpcs:ignore
		);

		return in_array( (string) $v, self::RUCNE, true ) ? (string) $v : '';
	}

	/**
	 * Prodaje li se artikl trenutno ispod redovne cijene.
	 *
	 * Cita se iz otvorenog intervala povijesti, dakle iz istog izvora iz kojeg se
	 * gradi i popis — da sucelje i cjenik ne mogu reci razlicito.
	 */
	public static function je_u_posebnoj_prodaji( int $entity_id ): bool {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		$ima = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM `{$t}`
				 WHERE entity_id = %d AND ts_end = 0
				   AND regular_price IS NOT NULL AND price IS NOT NULL
				   AND price < regular_price - %f
				 LIMIT 1",
				$entity_id,
				Config::TOLERANCIJA_POVIJESTI
			) // phpcs:ignore
		);

		return null !== $ima;
	}

	/** Oznake za vise artikala odjednom. @return array<int,string> */
	public static function za_vise( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$t = Config::table( Config::TABLE_POVIJEST );
		$u = implode( ',', array_map( 'intval', $ids ) );

		$out = array();

		foreach ( (array) $wpdb->get_results( "SELECT entity_id, vrsta_pop FROM `{$t}` WHERE ts_end = 0 AND entity_id IN ({$u})" ) as $r ) { // phpcs:ignore
			if ( in_array( (string) $r->vrsta_pop, self::RUCNE, true ) ) {
				$out[ (int) $r->entity_id ] = (string) $r->vrsta_pop;
			}
		}

		return $out;
	}

	/**
	 * Zapisi trgovcev izbor na tekuce razdoblje.
	 *
	 * Ne otvara novi interval i ne dira cijene — samo imenuje ono sto vec traje.
	 * Artikl koji nije na posebnoj prodaji nema sto imenovati.
	 *
	 * @return array{ok:bool,poruka:string,upozorenja:string[]}
	 */
	public static function postavi( int $entity_id, string $vrsta ): array {
		global $wpdb;

		$prazno = array(
			'ok'         => false,
			'poruka'     => '',
			'upozorenja' => array(),
		);

		if ( Config::POP_AKCIJSKA === $vrsta || '' === $vrsta ) {
			// Povratak na zadano: oznaka se brise, izvedena vrijednost preuzima.
			$vrsta = Config::POP_AKCIJSKA;
		} elseif ( ! in_array( $vrsta, self::RUCNE, true ) ) {
			$prazno['poruka'] = __( 'Nepoznat oblik prodaje.', Config::TEXT_DOMAIN );
			return $prazno;
		}

		$t = Config::table( Config::TABLE_POVIJEST );

		$redak = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, ts, regular_price, price FROM `{$t}`
				 WHERE entity_id = %d AND ts_end = 0 ORDER BY ts DESC LIMIT 1",
				$entity_id
			) // phpcs:ignore
		);

		if ( ! $redak ) {
			$prazno['poruka'] = __( 'Za ovaj artikl jos nemamo zapis o tekucoj cijeni, pa nema razdoblja koje bi se imenovalo.', Config::TEXT_DOMAIN );
			return $prazno;
		}

		if ( null === $redak->regular_price || null === $redak->price
			|| (float) $redak->price >= (float) $redak->regular_price - Config::TOLERANCIJA_POVIJESTI ) {
			$prazno['poruka'] = __( 'Artikl se ne prodaje ispod redovne cijene, pa nije u posebnom obliku prodaje.', Config::TEXT_DOMAIN );
			return $prazno;
		}

		$wpdb->update(
			$t,
			array( 'vrsta_pop' => $vrsta ),
			array( 'id' => (int) $redak->id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'ok'         => true,
			'poruka'     => '',
			'upozorenja' => ( Config::POP_SEZONSKO === $vrsta ) ? self::upozorenja( $entity_id ) : array(),
		);
	}

	/**
	 * Koliko dana traje tekuce razdoblje.
	 *
	 * Mjeri se od pocetka otvorenog intervala. Interval bez pouzdanog pocetka
	 * (`ts = 0`) ne zna se kad je poceo, pa se trajanje ne tvrdi.
	 *
	 * @return int|null null ako se ne zna
	 */
	public static function trajanje_dana( int $entity_id ): ?int {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		$ts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ts FROM `{$t}` WHERE entity_id = %d AND ts_end = 0 ORDER BY ts DESC LIMIT 1",
				$entity_id
			) // phpcs:ignore
		);

		if ( null === $ts || 0 === (int) $ts ) {
			return null;
		}

		return (int) floor( ( time() - (int) $ts ) / DAY_IN_SECONDS );
	}

	/**
	 * Koliko je sezonskih snizenja bilo u kalendarskoj godini, ukljucujuci tekuce.
	 *
	 * Broje se INTERVALI, ne dani: dva razdvojena razdoblja su dva snizenja, a
	 * jedno koje traje preko Nove godine je jedno.
	 */
	public static function sezonskih_u_godini( int $entity_id, ?int $godina = null ): int {
		global $wpdb;

		$godina = $godina ?? (int) wp_date( 'Y' );
		$tz     = wp_timezone();

		$od = ( new \DateTimeImmutable( sprintf( '%d-01-01 00:00:00', $godina ), $tz ) )->getTimestamp();
		$do = ( new \DateTimeImmutable( sprintf( '%d-01-01 00:00:00', $godina + 1 ), $tz ) )->getTimestamp();

		$t = Config::table( Config::TABLE_POVIJEST );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$t}`
				 WHERE entity_id = %d AND vrsta_pop = %s
				   AND ts > 0 AND ts < %d
				   AND ( ts_end = 0 OR ts_end >= %d )",
				$entity_id,
				Config::POP_SEZONSKO,
				$do,
				$od
			) // phpcs:ignore
		);
	}

	/**
	 * Upozorenja za sezonsko snizenje.
	 *
	 * UPOZORENJE, NE ZAPREKA. Prekoracenje moze imati razlog koji mi ne vidimo, a
	 * odluka je trgovceva — dodatak mjeri i javlja, ne odlucuje.
	 *
	 * @return string[]
	 */
	public static function upozorenja( int $entity_id ): array {
		$izlaz = array();

		$dana = self::trajanje_dana( $entity_id );

		if ( null !== $dana && $dana > Config::SEZONSKO_MAX_DANA ) {
			$izlaz[] = sprintf(
				/* translators: 1: koliko dana traje, 2: dopusteno dana */
				__( 'Ovo sezonsko snizenje traje %1$d dana, a propisano je najdulje %2$d.', Config::TEXT_DOMAIN ),
				$dana,
				Config::SEZONSKO_MAX_DANA
			);
		}

		$koliko = self::sezonskih_u_godini( $entity_id );

		if ( $koliko > Config::SEZONSKO_MAX_GODISNJE ) {
			$izlaz[] = sprintf(
				/* translators: 1: koje je po redu, 2: dopusteno godisnje, 3: godina */
				__( 'Ovo je %1$d. sezonsko snizenje ovog artikla u %3$d. godini, a propisana su najvise %2$d.', Config::TEXT_DOMAIN ),
				$koliko,
				Config::SEZONSKO_MAX_GODISNJE,
				(int) wp_date( 'Y' )
			);
		}

		return $izlaz;
	}

	/**
	 * Artikli koji su TRENUTNO u posebnom obliku prodaje.
	 *
	 * Popis se gradi iz cijena, ne iz oznake: artikl na akciji postoji i prije nego
	 * ga itko imenuje, i upravo njega trgovac treba vidjeti.
	 *
	 * @return object[]
	 */
	public static function u_posebnoj_prodaji( int $limit = 200 ): array {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS entity_id,
				        COALESCE( NULLIF( p.post_title, '' ), par.post_title ) AS naziv,
				        COALESCE( l.sku, '' ) AS sku,
				        h.regular_price, h.price, h.vrsta_pop, h.ts
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				 LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
				 JOIN `{$t}` h ON h.entity_id = p.ID AND h.ts_end = 0
				 WHERE " . \CJTR\Katalog::uvjet() . '
				   AND h.regular_price IS NOT NULL AND h.price IS NOT NULL
				   AND h.price < h.regular_price - %f
				 ORDER BY h.ts ASC
				 LIMIT %d',
				Config::TOLERANCIJA_POVIJESTI,
				$limit
			) // phpcs:ignore
		);
	}

	/** Koliko ih je trenutno u posebnom obliku prodaje. */
	public static function broj_u_posebnoj_prodaji(): int {
		global $wpdb;

		$t = Config::table( Config::TABLE_POVIJEST );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->posts} p
				 JOIN `{$t}` h ON h.entity_id = p.ID AND h.ts_end = 0
				 WHERE " . \CJTR\Katalog::uvjet() . '
				   AND h.regular_price IS NOT NULL AND h.price IS NOT NULL
				   AND h.price < h.regular_price - %f',
				Config::TOLERANCIJA_POVIJESTI
			) // phpcs:ignore
		);
	}
}
