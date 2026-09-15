<?php
/**
 * Sastavljanje HTML-a za dodatnu cijenu.
 *
 * Cista funkcija: prima brojke, vraca HTML. Ne zna nista o temi, o hookovima ni
 * o tome gdje ce zavrsiti. Zakacivanje je posao klase Prikaz.
 *
 * ZASTO DODATNA CIJENA NIJE PRECRTANA
 *
 * Precrtana brojka znaci "ovo je bilo, sada je jeftinije". Dodatna cijena to NIJE:
 * ona je cijena na jedan odredeni datum i moze biti i niza i visa od danasnje.
 * Kad bi se prikazala precrtano, kupac bi je procitao kao referencu snizenja —
 * a kod artikla koji je poskupio to bi bila obrnuta neistina. Zato nosi tekst
 * koji kaze sto je, a ne samo polozaj.
 *
 * @package CJTR
 */

namespace CJTR\Prikaz;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Render {

	/**
	 * HTML koji se dodaje uz cijenu.
	 *
	 * @param array $p {
	 *     @type float|null  sidrena     Dodatna cijena. null = ne prikazuje se nista.
	 *     @type float|null  najniza_30  Najniza cijena u 30 dana. null = ne prikazuje se.
	 *     @type string      ref_datum   Referentni datum, Y-m-d.
	 * }
	 * @return string prazan string ako nema sto prikazati
	 */
	public static function html( array $p ): string {
		$redci = array();

		$najniza = $p['najniza_30'] ?? null;
		if ( null !== $najniza ) {
			$redci[] = self::redak(
				'najniza',
				__( 'Najniža cijena u 30 dana', Config::TEXT_DOMAIN ),
				(float) $najniza
			);
		}

		$sidrena = $p['sidrena'] ?? null;
		if ( null !== $sidrena ) {
			$redci[] = self::redak(
				'sidrena',
				self::oznaka_sidrene( $p['ref_datum'] ?? Config::REF_DATUM_OSTALO ),
				(float) $sidrena
			);
		}

		if ( empty( $redci ) ) {
			return '';
		}

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( Config::css( 'dodatne' ) ),
			implode( '', $redci )
		);
	}

	/**
	 * Tekst uz dodatnu cijenu.
	 *
	 * Datum je u oznaci namjerno: bez njega bi "sidrena cijena" bila prazan pojam,
	 * a s njim kupac odmah zna na sto se brojka odnosi.
	 *
	 * IME SE ZOVE SVOJIM IMENOM
	 *
	 * Ranije je pisalo samo "Cijena 10.9.2026." — tocno, ali kupac iz toga ne zna
	 * sto gleda ni zasto to ondje stoji. "Sidrena cijena" je naziv iz propisa, pa
	 * onaj tko za njega cuje negdje drugdje prepozna istu stvar.
	 */
	private static function oznaka_sidrene( string $ref_datum ): string {
		$ts = strtotime( $ref_datum );

		return sprintf(
			/* translators: %s = referentni datum */
			__( 'Sidrena cijena %s', Config::TEXT_DOMAIN ),
			$ts ? wp_date( 'j.n.Y.', $ts ) : $ref_datum
		);
	}

	private static function redak( string $vrsta, string $oznaka, float $iznos ): string {
		return sprintf(
			'<span class="%1$s %2$s"><span class="%3$s">%4$s:</span> <span class="%5$s">%6$s</span></span>',
			esc_attr( Config::css( 'dodatna' ) ),
			esc_attr( Config::css( 'dodatna-' . $vrsta ) ),
			esc_attr( Config::css( 'dodatna-oznaka' ) ),
			esc_html( $oznaka ),
			esc_attr( Config::css( 'dodatna-iznos' ) ),
			wp_kses_post( wc_price( $iznos ) )
		);
	}
}
