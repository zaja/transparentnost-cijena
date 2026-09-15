<?php
/**
 * Cijena po jedinici mjere.
 *
 * IZ EFEKTIVNE CIJENE, NE REDOVNE
 *
 * Cjenik nosi cijenu koju kupac stvarno placa, ukljucivo akciju. Racunanje iz
 * redovne cijene dalo bi EUR/kg koji ne odgovara nijednoj cijeni na polici — i to
 * bas kod artikala na akciji, dakle ondje gdje kupac najvise usporeduje.
 *
 * JEDAN IMENITELJ PO VRSTI
 *
 * EUR/kg za sve mase, EUR/l za sve volumene. Mijesanje EUR/kg i EUR/100 g unutar
 * iste datoteke cini cijene neusporedivima, a usporedivost je jedini razlog zbog
 * kojeg se ovaj podatak uopce objavljuje.
 *
 * @package CJTR
 */

namespace CJTR\Podaci;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Cijena_Po_Jedinici {

	/**
	 * Izracunaj cijenu po jedinici mjere.
	 *
	 * @param float  $cijena        Efektivna cijena artikla.
	 * @param float  $kolicina      Neto kolicina, u jedinici `$jedinica`.
	 * @param string $jedinica      Jedinica u kojoj je kolicina zapisana.
	 *
	 * @return array{
	 *     iznos:float, jedinica:string, oznaka:string, zapis:string,
	 *     tocan_iznos:float, gubi_preciznost:bool
	 * }|null null ako se ne moze izracunati
	 */
	public static function izracunaj( float $cijena, float $kolicina, string $jedinica ): ?array {
		if ( $cijena <= 0 || $kolicina <= 0 ) {
			return null;
		}

		$kanonska = Kolicina::u_kanonsku( $kolicina, $jedinica );
		if ( null === $kanonska ) {
			return null;
		}

		// Kolicina nula u kanonskoj jedinici znaci da je ulazna vrijednost manja od
		// razlucivosti tipa. Dijeljenje bi puklo, pa se odbija.
		if ( $kanonska['kolicina'] <= 0 ) {
			return null;
		}

		$tocan     = $cijena / $kanonska['kolicina'];
		$zaokruzen = round( $tocan, Config::DECIMALA_CIJENE_PO_JEDINICI );

		return array(
			'iznos'           => $zaokruzen,
			'tocan_iznos'     => $tocan,
			'jedinica'        => $kanonska['jedinica'],
			'oznaka'          => Kolicina::oznaka( $kanonska['jedinica'] ),
			'zapis'           => self::zapis( $zaokruzen, $kanonska['jedinica'] ),
			'gubi_preciznost' => self::gubi_preciznost( $tocan, $zaokruzen ),
		);
	}

	/**
	 * Pomice li zaokruzivanje vrijednost vise nego sto se tolerira.
	 *
	 * Mjeri se RELATIVNA greska, ne iznos. Kod jeftinog artikla velike mase — 0,99
	 * EUR za 25 kg — tocna vrijednost je 0,0396 EUR/kg, a zaokruzena 0,04: pomak od
	 * 1,01 %. Kriterij "iznos je malen" to ne bi uhvatio, jer 0,0396 nije malen
	 * broj sam po sebi; uhvati ga tek odnos prema zaokruzenoj vrijednosti.
	 *
	 * Brojka se NE mijenja, samo se prijavljuje.
	 */
	private static function gubi_preciznost( float $tocan, float $zaokruzen ): bool {
		if ( $tocan <= 0 ) {
			return false;
		}
		return ( abs( $zaokruzen - $tocan ) / $tocan ) > Config::PRAG_RELATIVNE_GRESKE_ZAOKRUZIVANJA;
	}

	/** Zapis za prikaz i za cjenik, npr. "12,34 EUR/kg". */
	public static function zapis( float $iznos, string $jedinica ): string {
		return sprintf(
			'%s %s/%s',
			number_format_i18n( $iznos, Config::DECIMALA_CIJENE_PO_JEDINICI ),
			get_woocommerce_currency(),
			Kolicina::oznaka( $jedinica )
		);
	}

	/**
	 * Izracunaj za artikl iz baze.
	 *
	 * Cijena se cita iz mete `_price`, dakle bez filtera dodataka — isti izvor koji
	 * koristi i cjenik. Kolicina iz nase tablice.
	 *
	 * @return array|null
	 */
	public static function za_artikl( int $entity_id ): ?array {
		global $wpdb;

		$redak = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT neto_kolicina, jedinica_mjere FROM `' . Config::table( Config::TABLE_PODACI ) . '` WHERE entity_id = %d',
				$entity_id
			) // phpcs:ignore
		);

		if ( ! $redak || null === $redak->neto_kolicina || '' === (string) $redak->jedinica_mjere ) {
			return null;
		}

		$cijena = get_post_meta( $entity_id, '_price', true );
		if ( '' === $cijena ) {
			return null;
		}

		return self::izracunaj( (float) $cijena, (float) $redak->neto_kolicina, (string) $redak->jedinica_mjere );
	}
}
