<?php
/**
 * Straza nad dinamickim cijenama.
 *
 * Cjenik mora sadrzavati cijenu koja se stvarno naplacuje. Ako bilo koji dodatak
 * mijenja cijenu u hodu, onda cijena u bazi, cijena u cjeniku i cijena koju kupac
 * placa mogu biti tri razlicita broja.
 *
 * Ovo NIJE jednokratna kontrola. Nalaz "nema dinamickih cijena" vrijedi samo za
 * trenutak u kojem je izmjeren — klijent jednim klikom u adminu ukljuci pravilo i
 * od tog trenutka bi se objavljivale cijene koje se ne naplacuju. Zato se provjera
 * vrti PRIJE svakog posla koji cita cijene, i posao ne pocinje ako ne prode.
 *
 * @package CJTR
 */

namespace CJTR\Cijene;

use CJTR\Config;
use CJTR\Katalog;

defined( 'ABSPATH' ) || exit;

final class Straza {

	/** Koliko entiteta uzeti u uzorak. Cijeli katalog bi trajao predugo za predprovjeru. */
	const UZORAK = 250;

	/** Nakon koliko sati se nalaz smatra zastarjelim. */
	const VRIJEDI_SATI = 24;

	/**
	 * Provjeri i vrati nalaz.
	 *
	 * @param bool $iz_predmemorije Smije li se vratiti nedavni nalaz umjesto novog mjerenja.
	 */
	public static function provjeri( bool $iz_predmemorije = false ): Nalaz {
		if ( $iz_predmemorije ) {
			$spremljen = self::zadnji();
			if ( $spremljen && ! $spremljen->zastario() ) {
				return $spremljen;
			}
		}

		$n = new Nalaz();

		$n->pravila = self::aktivna_pravila();
		self::usporedi_uzorak( $n );

		$n->prolazi    = empty( $n->pravila ) && 0 === $n->razlika;
		$n->provjereno = current_time( 'mysql', true );

		update_option( Config::option( 'straza_nalaz' ), $n->kao_polje(), false );

		return $n;
	}

	public static function zadnji(): ?Nalaz {
		$polje = get_option( Config::option( 'straza_nalaz' ) );
		return is_array( $polje ) ? Nalaz::iz_polja( $polje ) : null;
	}

	/**
	 * Aktivna pravila u poznatim dodacima.
	 *
	 * Popis poznatih dodataka nuzno je nepotpun i zastarjet ce. Zato je ovo samo
	 * dopuna uzorku iz usporedi_uzorak(), koji radi neovisno o tome koji je dodatak
	 * u pitanju. Detektor koji ne nade svoj dodatak suti, ne lazno umiruje.
	 *
	 * @return array<int,array{dodatak:string,koliko:int,gdje:string}>
	 */
	private static function aktivna_pravila(): array {
		global $wpdb;
		$nadeno = array();

		// --- Discount Rules for WooCommerce ---
		$t = $wpdb->prefix . 'wdr_rules';
		if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}` WHERE enabled = 1 AND deleted = 0" ); // phpcs:ignore
			if ( $n > 0 ) {
				$nadeno[] = array(
					'dodatak' => 'Discount Rules for WooCommerce',
					'koliko'  => $n,
					'gdje'    => __( 'WooCommerce > Discount Rules', Config::TEXT_DOMAIN ),
				);
			}
		}

		// --- Buy One Get One Free ---
		if ( post_type_exists( 'shop_bogof_rule' ) ) {
			$n = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 WHERE p.post_type = %s AND p.post_status NOT LIKE %s",
					'shop_bogof_rule',
					'%disabled'
				)
			); // phpcs:ignore
			if ( $n > 0 ) {
				$nadeno[] = array(
					'dodatak' => 'Buy One Get One Free',
					'koliko'  => $n,
					'gdje'    => __( 'WooCommerce > BOGO pravila', Config::TEXT_DOMAIN ),
				);
			}
		}

		// --- Mix and Match s cijenom po djetetu ---
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m
				 JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status IN ('publish','private')
				 WHERE m.meta_key = %s AND m.meta_value = %s",
				'_mnm_per_product_pricing',
				'yes'
			)
		); // phpcs:ignore
		if ( $n > 0 ) {
			$nadeno[] = array(
				'dodatak' => 'Mix and Match Products',
				'koliko'  => $n,
				'gdje'    => __( 'proizvodi s cijenom po sastavnici', Config::TEXT_DOMAIN ),
			);
		}

		return $nadeno;
	}

	/**
	 * Usporedi sirovu `_price` metu s vrijednoscu koju vrati get_price().
	 *
	 * Ovo je generički dio: hvata bilo koji dodatak koji filtrira cijenu, i one
	 * koje jos ne poznajemo. Uzorak, ne cijeli katalog — predprovjera mora biti
	 * brza. Zato je dopuna detekciji pravila, ne zamjena za nju.
	 */
	private static function usporedi_uzorak( Nalaz $n ): void {
		global $wpdb;

		$redci = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm.meta_value AS cijena
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_price'
				 WHERE " . Katalog::uvjet() . " AND pm.meta_value <> ''
				 ORDER BY RAND()
				 LIMIT %d",
				self::UZORAK
			)
		); // phpcs:ignore

		$n->uzorak = count( $redci );

		foreach ( $redci as $red ) {
			$proizvod = wc_get_product( (int) $red->ID );
			if ( ! $proizvod ) {
				continue;
			}
			if ( self::norm( $red->cijena ) === self::norm( $proizvod->get_price() ) ) {
				continue;
			}
			$n->razlika++;
			if ( count( $n->primjeri ) < 10 ) {
				$n->primjeri[] = sprintf(
					/* translators: 1: ID, 2: cijena u bazi, 3: cijena koja se primjenjuje */
					__( 'ID %1$d: u bazi %2$s, primjenjuje se %3$s', Config::TEXT_DOMAIN ),
					(int) $red->ID,
					$red->cijena,
					(string) $proizvod->get_price()
				);
			}
		}
	}

	private static function norm( $v ): ?string {
		if ( null === $v || '' === $v ) {
			return null;
		}
		$v = trim( (string) $v );
		if ( ! is_numeric( $v ) ) {
			return $v;
		}
		if ( false !== strpos( $v, '.' ) ) {
			$v = rtrim( rtrim( $v, '0' ), '.' );
		}
		return '' === $v ? '0' : $v;
	}
}
