<?php
/**
 * Posao: ocisti istekle akcijske podatke koji svaki dan mijenjaju cijenu.
 *
 * STANJE KOJE POPRAVLJA
 *
 * Artikl ima postavljenu akcijsku cijenu i datum zavrsetka akcije koji je PROSAO,
 * ali akcijska cijena nije uklonjena. WooCommerce svaki dan prolazi kroz dvije
 * provjere:
 *
 *   "akcije koje pocinju"  — uhvati artikl jer datum pocetka je prosao a cijena
 *                            nije akcijska, pa je postavi na akcijsku
 *   "akcije koje zavrsavaju" — uhvati isti artikl jer datum zavrsetka je prosao,
 *                            pa cijenu vrati na redovnu
 *
 * Rezultat: cijena artikla svaki dan skace izmedu dvije vrijednosti, BEZ ikakve
 * oznake akcije prema kupcu. Ovisno o trenutku posjeta, kupac plati jednu ili
 * drugu. Uz to se svaka promjena zapisuje u povijest cijena, pa ona puni smecem.
 *
 * STO POSAO RADI
 *
 * Uklanja akcijsku cijenu i oba datuma. Redovna cijena se NE dira, pa cijena koju
 * kupac placa ostaje ista kakva je bila u trenutku popravka.
 *
 * POVRATNO
 *
 * Prije brisanja se cijelo zateceno stanje sve cetiri mete zapisuje u trag, s
 * razlogom i datumom. Meta koja izgleda kao kvar moze biti namjerna; bez traga
 * se to ne bi moglo ni provjeriti ni vratiti. Vracanje radi posao "Vrati akcije".
 *
 * @package CJTR
 */

namespace CJTR\Poslovi\Poslovi;

use CJTR\Config;
use CJTR\Poslovi\Posao;
use CJTR\Poslovi\Rezultat_Komada;
use CJTR\Trag;

defined( 'ABSPATH' ) || exit;

final class Istekle_Akcije extends Posao {

	public function kljuc(): string {
		return 'istekle_akcije';
	}

	public function naziv(): string {
		return __( 'Zaustavi cijene koje skacu gore-dolje', Config::TEXT_DOMAIN );
	}

	public function opis(): string {
		return __( 'Kod nekih artikala akcija je zavrsila, a akcijska cijena je ostala postavljena — pa im cijena svaki dan skace izmedu dvije vrijednosti, bez ikakve oznake akcije. Kupac plati jednu ili drugu, ovisno o tome kad dode. Posao to zaustavlja. Cijena koju kupac placa se NE mijenja.', Config::TEXT_DOMAIN );
	}

	/**
	 * Posao NE nasljeduje Posao_S_Cijenama namjerno.
	 *
	 * Straza sluzi poslovima koji cijene citaju da bi ih negdje objavili. Ovaj posao
	 * cita metu u 'edit' kontekstu, dakle bez filtera, i nista ne objavljuje —
	 * blokirati ga zbog aktivnog popusta znacilo bi ne dati da se popravi kvar.
	 */
	public function zapreka(): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce nije aktivan.', Config::TEXT_DOMAIN );
		}
		return '';
	}

	protected function vlastita_skupina(): string {
		return Config::SKUPINA_PRIPREMA;
	}

	public function korak(): int {
		return 3;
	}

	public function preduvjet(): string {
		return 'prebroji';
	}

	public function ukupno(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $this->upit( 'SELECT COUNT(*)' ) ); // phpcs:ignore
	}

	public function obradi( int $zadnji_id, int $velicina ): Rezultat_Komada {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				$this->upit( 'SELECT p.ID' ) . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d',
				$zadnji_id,
				$velicina
			)
		); // phpcs:ignore

		if ( empty( $ids ) ) {
			return Rezultat_Komada::s( 0, null );
		}

		$ids       = array_map( 'intval', $ids );
		$rez       = Rezultat_Komada::s( 0, max( $ids ) );
		$obradenih = 0;

		foreach ( $ids as $id ) {
			$proizvod = wc_get_product( $id );
			if ( ! $proizvod ) {
				$rez->preskoci( $id, __( 'artikl se nije mogao ucitati', Config::TEXT_DOMAIN ) );
				continue;
			}

			$prije = $this->snimi( $proizvod );

			// Trag PRIJE promjene. Ako zapis ne uspije, artikl se ne dira —
			// radije neka kvar ostane nego da nestane bez mogucnosti povrata.
			$trag_id = Trag::zapisi(
				$id,
				$this->kljuc(),
				$prije,
				__( 'akcija je zavrsila, a akcijska cijena je ostala postavljena', Config::TEXT_DOMAIN )
			);

			if ( 0 === $trag_id ) {
				$rez->preskoci( $id, __( 'stanje se nije moglo zapisati u trag, pa artikl nije diran', Config::TEXT_DOMAIN ) );
				continue;
			}

			$proizvod->set_sale_price( '' );
			$proizvod->set_date_on_sale_from( null );
			$proizvod->set_date_on_sale_to( null );
			$proizvod->save();

			$svjez    = wc_get_product( $id );
			$poslije  = $svjez ? $this->snimi( $svjez ) : array();
			$promjena = $this->usporedi_cijenu( $prije, $poslije );

			$this->zapisi( $id, $proizvod->get_name(), $prije, $poslije );

			if ( '' !== $promjena ) {
				// Cijena se promijenila iako nije smjela — to je greska, ne uspjeh.
				$rez->greska(
					sprintf(
						/* translators: 1: ID, 2: opis promjene */
						__( 'ID %1$d: cijena se promijenila, a nije smjela — %2$s', Config::TEXT_DOMAIN ),
						$id,
						$promjena
					)
				);
				continue;
			}

			$obradenih++;
		}

		$rez->obradeno = $obradenih;
		return $rez;
	}

	public function prije_pocetka(): void {
		update_option( Config::option( 'istekle_akcije_povijest_prije' ), $this->redaka_povijesti(), false );
	}

	/**
	 * Dokaz da popravak drzi: pokreni WooCommerceovu dnevnu provjeru akcija i
	 * provjeri nastaju li jos retci u povijesti cijena.
	 */
	public function nakon_zavrsetka(): void {
		$prije = (int) get_option( Config::option( 'istekle_akcije_povijest_prije' ), 0 );

		if ( function_exists( 'wc_scheduled_sales' ) ) {
			wc_scheduled_sales();
			wc_scheduled_sales();
		}

		$poslije = $this->redaka_povijesti();

		update_option(
			Config::option( 'istekle_akcije_dokaz' ),
			array(
				'povijest_prije'  => $prije,
				'povijest_poslije' => $poslije,
				'jos_nastaju'     => $poslije > $prije,
				'preostalo'       => $this->ukupno(),
				'kad'             => current_time( 'mysql', true ),
			),
			false
		);
	}

	/* --------------------------------------------------------------- interno */

	/**
	 * Artikli s akcijom koja je zavrsila, a akcijska cijena je ostala.
	 *
	 * Uvjet odgovara onome sto WooCommerce koristi u "akcije koje zavrsavaju"
	 * (WC_Product_Data_Store_CPT::get_ending_sales), pa hvatamo tocno one artikle
	 * koje ta provjera svaki dan iznova dira.
	 */
	private function upit( string $select ): string {
		global $wpdb;
		$sada = time();

		return "{$select}
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = '_sale_price'
			JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = '_sale_price_dates_to'
			WHERE p.post_type IN ('product','product_variation')
			  AND p.post_status NOT IN ('trash','auto-draft')
			  AND sp.meta_value <> ''
			  AND dt.meta_value <> ''
			  AND CAST(dt.meta_value AS UNSIGNED) > 0
			  AND CAST(dt.meta_value AS UNSIGNED) < {$sada}";
	}

	/**
	 * Stanje mete, onako kako je zapisano.
	 *
	 * Datumi se cuvaju kao SIROVI vremenski zig, ne kao 'Y-m-d'. Formatirani datum
	 * gubi doba dana, pa bi se 1.9. 23:59:59 vratio kao 1.9. 00:00:00 — dan manje
	 * trajanja akcije. Povrat koji ne vraca istu vrijednost nije povrat.
	 * Citljivi oblik ide uz njega, samo za zapisnik.
	 */
	private function snimi( $proizvod ): array {
		$od = $proizvod->get_date_on_sale_from( 'edit' );
		$do = $proizvod->get_date_on_sale_to( 'edit' );

		return array(
			'price'    => (string) $proizvod->get_price( 'edit' ),
			'regular'  => (string) $proizvod->get_regular_price( 'edit' ),
			'sale'     => (string) $proizvod->get_sale_price( 'edit' ),
			'from_ts'  => $od ? (int) $od->getTimestamp() : 0,
			'to_ts'    => $do ? (int) $do->getTimestamp() : 0,
			'from'     => $od ? $od->date( 'Y-m-d H:i:s' ) : '',
			'to'       => $do ? $do->date( 'Y-m-d H:i:s' ) : '',
		);
	}

	/** Prazno ako je cijena ostala ista; inace opis promjene. */
	private function usporedi_cijenu( array $prije, array $poslije ): string {
		if ( empty( $poslije ) ) {
			return __( 'artikl se nakon spremanja nije mogao ponovno ucitati', Config::TEXT_DOMAIN );
		}
		if ( abs( (float) $prije['price'] - (float) $poslije['price'] ) < 0.0001 ) {
			return '';
		}
		return sprintf( '%s -> %s', $prije['price'], $poslije['price'] );
	}

	private function zapisi( int $id, string $naziv, array $prije, array $poslije ): void {
		$stanje = new \CJTR\Poslovi\Stanje();
		$stanje->kljuc = $this->kljuc();
		$stanje->zapisi(
			'info',
			sprintf(
				'ID %d "%s" | prije: cijena=%s redovna=%s akcijska=%s od=%s do=%s | poslije: cijena=%s redovna=%s akcijska=%s',
				$id,
				mb_substr( $naziv, 0, 40 ),
				$prije['price'],
				$prije['regular'],
				$prije['sale'],
				$prije['from'],
				$prije['to'],
				$poslije['price'] ?? '?',
				$poslije['regular'] ?? '?',
				'' === ( $poslije['sale'] ?? '' ) ? '(uklonjena)' : $poslije['sale']
			)
		);
	}

	private function redaka_povijesti(): int {
		global $wpdb;
		$t = $wpdb->prefix . 'price_history';
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ); // phpcs:ignore
	}
}
