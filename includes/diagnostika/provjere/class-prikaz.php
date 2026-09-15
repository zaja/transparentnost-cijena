<?php
/**
 * Provjera: prikazuje li se dodatna cijena i u rubnim stanjima artikla.
 *
 * PRAVILO KOJE SE PROVJERAVA
 *
 * Obveza isticanja dodatne cijene NE ovisi o zalihi, o tome moze li se artikl
 * kupiti ni o tome je li skriven iz kataloga. Ako artikl ima cijenu i kupac ga
 * moze vidjeti, dodatna cijena mora biti prikazana.
 *
 * Provjera postoji zato sto je 13.9.2026. izostanak prikaza kod artikla izvan
 * zalihe izgledao kao da modul prikaza iskljucuje takve artikle. Nije bilo tako —
 * dodatna cijena naprosto nije bila upisana — ali dok to nije izmjereno, obje su
 * pretpostavke bile jednako vjerojatne. Sad se mjeri.
 *
 * ZASTO NE STVARA TESTNE PROIZVODE
 *
 * Stanja se postavljaju na UCITANOM objektu, bez `save()`. Nijedan redak baze se
 * ne mijenja, nista se ne upisuje u povijest cijena i nema sto ostati iza provjere
 * ako se prekine usred rada. Testni proizvod koji se stvara pa brise na shared
 * hostingu prezivi svaki prekid i zatrpa katalog.
 *
 * @package CJTR
 */

namespace CJTR\Diagnostika\Provjere;

use CJTR\Config;
use CJTR\Diagnostika\Provjera;
use CJTR\Diagnostika\Rezultat;

defined( 'ABSPATH' ) || exit;

final class Prikaz extends Provjera {

	public function kljuc(): string {
		return 'prikaz';
	}

	public function izvrsi(): Rezultat {
		$r = new Rezultat( __( 'Prikaz sidrene cijene u rubnim stanjima', Config::TEXT_DOMAIN ) );

		$r->znacenje(
			__( 'Obveza isticanja sidrene cijene ne ovisi o zalihi, o mogucnosti kupnje ni o vidljivosti u katalogu. Ako artikl ima cijenu i kupac ga vidi, sidrena cijena se mora prikazati.', Config::TEXT_DOMAIN )
		);

		$id = $this->uzorak();

		if ( 0 === $id ) {
			return $r->status( Rezultat::UPOZ )
				->stavka( __( 'Uzorak', Config::TEXT_DOMAIN ), __( 'nema nijednog objavljenog artikla s upisanom sidrenom cijenom', Config::TEXT_DOMAIN ) )
				->postupak( __( 'Pokrenite posao koji utvrduje sidrenu cijenu, pa ponovite provjeru.', Config::TEXT_DOMAIN ) );
		}

		$r->stavka( __( 'Artikl na kojem se mjeri', Config::TEXT_DOMAIN ), '#' . $id );

		$palo = 0;

		foreach ( $this->slucajevi() as $naziv => $priprema ) {
			$vidi = $this->vidi_li_se( $id, $priprema );

			$r->stavka( $naziv, $vidi ? __( 'prikazuje se', Config::TEXT_DOMAIN ) : __( 'NE prikazuje se', Config::TEXT_DOMAIN ) );

			if ( ! $vidi ) {
				$palo++;
			}
		}

		if ( $palo > 0 ) {
			return $r->status( Rezultat::LOSE )
				->postupak(
					sprintf(
						/* translators: %d = broj stanja */
						__( 'U %d stanja sidrena cijena nije prikazana. Modul prikaza ne smije gledati zalihu ni vidljivost — provjerite uvjete u Prikaz::podaci().', Config::TEXT_DOMAIN ),
						$palo
					)
				);
		}

		return $r->status( Rezultat::OK );
	}

	/* --------------------------------------------------------------- interno */

	/**
	 * Stanja koja se ispituju.
	 *
	 * Svaka priprema mijenja UCITANI objekt, bez spremanja. Backorder se radi
	 * sinteticki jer u katalogu nema nijednog takvog artikla — a stanje je posve
	 * legitimno i korisnik iz zajednice ga moze imati.
	 *
	 * @return array<string,callable>
	 */
	private function slucajevi(): array {
		return array(
			__( 'Na zalihi', Config::TEXT_DOMAIN )              => function ( $p ) {
				$p->set_stock_status( 'instock' );
			},
			__( 'Nema na zalihi', Config::TEXT_DOMAIN )         => function ( $p ) {
				$p->set_stock_status( 'outofstock' );
			},
			__( 'Na cekanju (backorder)', Config::TEXT_DOMAIN ) => function ( $p ) {
				$p->set_stock_status( 'onbackorder' );
				$p->set_backorders( 'notify' );
				$p->set_manage_stock( true );
				$p->set_stock_quantity( 0 );
			},
			__( 'Bez mogucnosti kupnje', Config::TEXT_DOMAIN )  => function ( $p ) {
				// Kupljivost se ne da postaviti na objektu — izvodi se iz cijene i
				// statusa. Zato se postavlja filterom, i skida odmah nakon mjerenja.
				add_filter( 'woocommerce_is_purchasable', '__return_false', 999 );
			},
			__( 'Skriven iz kataloga', Config::TEXT_DOMAIN )    => function ( $p ) {
				$p->set_catalog_visibility( 'hidden' );
			},
		);
	}

	/**
	 * Vidi li se dodatna cijena kad je artikl u zadanom stanju.
	 *
	 * Objekt se ucitava svjeze za svako mjerenje, pa jedno stanje ne prelazi u
	 * drugo. Predmemorija modula prikaza se cisti iz istog razloga — inace bi drugo
	 * mjerenje vratilo odgovor prvog.
	 */
	private function vidi_li_se( int $id, callable $priprema ): bool {
		\CJTR\Prikaz\Prikaz::zaboravi();

		$proizvod = wc_get_product( $id );
		if ( ! $proizvod ) {
			return false;
		}

		$priprema( $proizvod );

		$html = $proizvod->get_price_html();

		remove_filter( 'woocommerce_is_purchasable', '__return_false', 999 );

		return false !== strpos( (string) $html, Config::css( 'dodatne' ) );
	}

	/**
	 * Objavljen artikl s upisanom dodatnom cijenom, na kojem se mjeri.
	 *
	 * Uzima se stvarni artikl iz kataloga, ne izmisljen: tako se mjeri i to da
	 * upit modula prikaza doista nalazi ono sto je upisano.
	 */
	private function uzorak(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT c.entity_id
			 FROM `' . Config::table( Config::TABLE_PODACI ) . "` c
			 JOIN {$wpdb->posts} p ON p.ID = c.entity_id
			 WHERE c.sidrena_cijena IS NOT NULL
			   AND p.post_type = 'product'
			   AND p.post_status = 'publish'
			 ORDER BY c.entity_id ASC
			 LIMIT 1" // phpcs:ignore
		);
	}
}
