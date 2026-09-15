<?php
/**
 * Posao koji cita cijene.
 *
 * Jedina razlika prema obicnom poslu: ne smije poceti dok straza ne potvrdi da
 * cijena u bazi jest cijena koja se naplacuje. Svaki buduci posao koji dira
 * cijene (snapshot, generator) nasljeduje odavde i time dobiva zastitu bez
 * dodatnog koda.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Cijene\Straza;

defined( 'ABSPATH' ) || exit;

abstract class Posao_S_Cijenama extends Posao {

	public function zapreka(): string {
		// Uvijek svjeze mjerenje: pravilo se moglo ukljuciti prije minute.
		return Straza::provjeri( false )->zapreka();
	}
}
