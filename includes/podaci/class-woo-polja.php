<?php
/**
 * Barkod i marka: WooCommerce je izvor istine, mi smo dopuna.
 *
 * MODEL U JEDNOJ RECENICI
 *
 * Ako WooCommerceovo polje ima vrijednost, ona vrijedi i mi svoju BRISEMO.
 * Ako je prazno, vrijedi nasa. Kopije nema nikad.
 *
 * ZASTO BRISANJE, A NE CUVANJE
 *
 * Dvije vrijednosti na dva mjesta, od kojih se jedna tiho ne koristi, zbunjuju
 * vise nego sto stite. Administrator koji vidi svoj unos u nasem polju razumno
 * pretpostavlja da se on i objavljuje.
 *
 * ZASTO SE NE KOPIRA
 *
 * Kopija zastari cim netko promijeni izvornik, i opet postoje dva razlicita
 * broja — samo bismo ovaj put mi bili uzrok. Zato se WooCommerceova vrijednost
 * cita U TRENUTKU PRIKAZA i u trenutku objave, nikad unaprijed.
 *
 * ZASTO PREKO WooCommerceova API-ja, A NE IZ META TABLICE
 *
 * Izmjereno na ovoj trgovini: `global_unique_id` u `postmeta` je PRAZAN za sva
 * tri artikla koja ga imaju, a vrijednost stoji u `wc_product_meta_lookup`.
 * Citanje iz mete dalo bi prazno ondje gdje vrijednost postoji.
 *
 * ODGOVORNOST
 *
 * Za tocnost barkoda i marke odgovara administrator. Upisao ih je u WooCommerce
 * krivo — ispravlja ih ondje; mi ih ne provjeravamo umjesto njega i ne mijenjamo.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Woo_Polja {

	/** Taksonomija marke u WooCommerce jezgri (od 9.4). */
	const TAKSONOMIJA_MARKE = 'product_brand';

	/** Kljuc pod kojim zapisi o preuzimanju stoje u zapisniku. Nije posao i nigdje se ne crta. */
	const KLJUC_ZAPISNIKA = 'woo_preuzeo_polje';

	/**
	 * Barkod iz WooCommercea, ili prazno.
	 *
	 * Postoji i po varijaciji — provjereno na ovoj instalaciji.
	 */
	public static function barkod( int $entity_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$p = wc_get_product( $entity_id );

		if ( ! $p || ! method_exists( $p, 'get_global_unique_id' ) ) {
			return '';
		}

		return trim( (string) $p->get_global_unique_id() );
	}

	/**
	 * Marka iz WooCommercea, ili prazno.
	 *
	 * VARIJACIJA JE NASLJEDUJE OD RODITELJA
	 *
	 * Taksonomija marke stoji na PROIZVODU, ne na varijaciji — tako je u
	 * WooCommerceu i tako je ispravno: majica S i majica XXL istog proizvoda su
	 * iste marke. Cjenik trazi marku za svaki zapis, dakle i za varijacije, pa se
	 * cita roditeljeva.
	 *
	 * Vise marki na proizvodu nije predvideno propisom; uzima se prva, jer je
	 * izmisljanje pravila o tome koja je "glavna" tvrdnja bez pokrica.
	 */
	public static function marka( int $entity_id ): string {
		if ( ! taxonomy_exists( self::TAKSONOMIJA_MARKE ) ) {
			return '';
		}

		$izvor = self::roditelj_ili_sam( $entity_id );

		$termini = wp_get_post_terms( $izvor, self::TAKSONOMIJA_MARKE, array( 'fields' => 'names' ) );

		if ( is_wp_error( $termini ) || empty( $termini ) ) {
			return '';
		}

		return trim( (string) $termini[0] );
	}

	/** Nasljeduje li ovaj entitet marku od roditelja. */
	public static function marka_naslijedena( int $entity_id ): bool {
		return $entity_id !== self::roditelj_ili_sam( $entity_id ) && '' !== self::marka( $entity_id );
	}

	/**
	 * Vrijednost koja vrijedi za ovo polje, i odakle dolazi.
	 *
	 * Jedno mjesto na kojem se odlucuje, pa sucelje, generator i uvoz ne mogu
	 * doci do razlicitog odgovora.
	 *
	 * @return array{vrijednost:string,iz_wooa:bool}
	 */
	public static function vrijedeca( int $entity_id, string $polje, ?string $nasa = null ): array {
		$woo = ( Config::POLJE_BARKOD === $polje ) ? self::barkod( $entity_id ) : self::marka( $entity_id );

		if ( '' !== $woo ) {
			return array(
				'vrijednost' => $woo,
				'iz_wooa'    => true,
			);
		}

		return array(
			'vrijednost' => (string) ( $nasa ?? '' ),
			'iz_wooa'    => false,
		);
	}

	/** Ima li WooCommerce vrijednost za ovo polje. */
	public static function woo_ima( int $entity_id, string $polje ): bool {
		$woo = ( Config::POLJE_BARKOD === $polje ) ? self::barkod( $entity_id ) : self::marka( $entity_id );

		return '' !== $woo;
	}

	/**
	 * Uskladi nasu tablicu s WooCommerceom: gdje je on preuzeo, nase se brise.
	 *
	 * Poziva se kad se proizvod spremi i kad se otvori nas tab — dakle u svakom
	 * trenutku u kojem bi administrator mogao primijetiti razliku.
	 *
	 * @return array<string,string> polje => vrijednost koja je maknuta
	 */
	public static function uskladi( int $entity_id ): array {
		global $wpdb;

		$redak = Zapis_Podataka::procitaj( $entity_id );

		if ( ! $redak ) {
			return array();
		}

		$maknuto = array();

		foreach ( array(
			Config::POLJE_BARKOD => array( 'barkod', 'barkod_izvor', 'barkod_status' ),
			Config::POLJE_MARKA  => array( 'marka', 'marka_izvor' ),
		) as $polje => $stupci ) {

			$stupac = $stupci[0];
			$nasa   = (string) ( $redak->$stupac ?? '' );

			if ( '' === $nasa ) {
				continue;
			}

			$woo = ( Config::POLJE_BARKOD === $polje ) ? self::barkod( $entity_id ) : self::marka( $entity_id );

			if ( '' === $woo ) {
				continue;
			}

			$postavi = array();
			foreach ( $stupci as $s ) {
				$postavi[ $s ] = null;
			}
			$postavi['azurirano'] = current_time( 'mysql', true );

			$wpdb->update(
				Config::table( Config::TABLE_PODACI ),
				$postavi,
				array( 'entity_id' => $entity_id )
			);

			$maknuto[ $polje ] = $nasa;

			self::zapisi_u_zapisnik( $entity_id, $polje, $nasa, $woo );
		}

		return $maknuto;
	}

	/**
	 * Adresa na kojoj se vrijednost mijenja.
	 *
	 * Napomena bez poveznice salje korisnika da trazi; poveznica ga odvede.
	 */
	public static function gdje_se_mijenja( int $entity_id, string $polje ): string {
		$cilj = self::roditelj_ili_sam( $entity_id );
		$url  = get_edit_post_link( $cilj, '' );

		if ( ! $url ) {
			return '';
		}

		if ( Config::POLJE_BARKOD === $polje ) {
			// Varijacija: GTIN stoji uz nju, u kartici Varijacije.
			return $url . ( $cilj === $entity_id ? '#inventory_product_data' : '#variable_product_options' );
		}

		return $url . '#postbox-container-2';
	}

	/** Kako se to mjesto zove, rijecima koje stoje na ekranu WooCommercea. */
	public static function ime_mjesta( string $polje ): string {
		return ( Config::POLJE_BARKOD === $polje )
			? __( 'kartica Inventar na proizvodu', Config::TEXT_DOMAIN )
			: __( 'kutija Marke uz proizvod', Config::TEXT_DOMAIN );
	}

	/* ------------------------------------------------------------------- interno */

	private static function roditelj_ili_sam( int $entity_id ): int {
		$post = get_post( $entity_id );

		if ( $post && 'product_variation' === $post->post_type && $post->post_parent ) {
			return (int) $post->post_parent;
		}

		return $entity_id;
	}

	/**
	 * Zapis o tome da je nasa vrijednost maknuta.
	 *
	 * Ide u zapisnik, ne u tablicu podataka i ne na ekran. Postoji samo zato da
	 * postoji odgovor ako netko pita kamo je nestao njegov unos — a to pitanje
	 * dode mjesecima poslije, kad se vise nitko ne sjeca.
	 */
	private static function zapisi_u_zapisnik( int $entity_id, string $polje, string $nasa, string $woo ): void {
		global $wpdb;

		$wpdb->insert(
			Config::table( Config::TABLE_POSAO_LOG ),
			array(
				'kljuc'    => self::KLJUC_ZAPISNIKA,
				'razina'   => 'info',
				'odmak'    => $entity_id,
				'poruka'   => sprintf(
					/* translators: 1: naziv polja, 2: nasa vrijednost, 3: WooCommerceova vrijednost */
					__( '%1$s: nasa vrijednost "%2$s" maknuta je jer je WooCommerce dobio vrijednost "%3$s". Od sada vrijedi WooCommerceova.', Config::TEXT_DOMAIN ),
					Config::POLJA[ $polje ]['naziv'] ?? $polje,
					$nasa,
					$woo
				),
				'zapisano' => current_time( 'mysql', true ),
			)
		);
	}
}
