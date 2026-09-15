<?php
/**
 * Plan rada — sto je gotovo, sto je na redu, sto jos ne moze.
 *
 * ZASTO POSTOJI KAO ZASEBAN SLOJ
 *
 * Ekran ne smije sam racunati redoslijed. Kad bi racunao, svaki novi modul koji
 * donese posao trazio bi izmjenu ekrana da bi se uklopio — a moduli 1 i 4 upravo
 * to donose. Ovdje se redoslijed cita iz onoga sto poslovi sami o sebi kazu
 * (`skupina()`, `korak()`, `preduvjet()`), pa se novi posao uklopi sam.
 *
 * Uz to se ovako plan da izracunati i za zadano stanje, bez baze — sto je jedini
 * nacin da se ekran pokaze u sva tri stanja a da se nista ne pokrene.
 *
 * @package CJTR
 */

namespace CJTR\Poslovi;

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

final class Plan {

	/** @var Posao[] */
	private $poslovi;

	/** @var array<string,Stanje> */
	private $stanja;

	/**
	 * @param Posao[]|null              $poslovi null = iz registra
	 * @param array<string,Stanje>|null $stanja  null = iz baze
	 */
	public function __construct( ?array $poslovi = null, ?array $stanja = null ) {
		$this->poslovi = $poslovi ?? Registar::svi();

		if ( null !== $stanja ) {
			$this->stanja = $stanja;
			return;
		}

		$this->stanja = array();
		foreach ( $this->poslovi as $kljuc => $posao ) {
			$this->stanja[ $kljuc ] = Stanje::ucitaj( $kljuc );
		}
	}

	public function stanje( string $kljuc ): Stanje {
		if ( isset( $this->stanja[ $kljuc ] ) ) {
			return $this->stanja[ $kljuc ];
		}
		$s        = new Stanje();
		$s->kljuc = $kljuc;
		return $s;
	}

	/**
	 * Poslovi jedne skupine, poredani.
	 *
	 * Unutar pripreme po `korak()`; drugdje redom kojim su prijavljeni, jer ondje
	 * redoslijed ne znaci nista i izmisljati ga bilo bi obmana.
	 *
	 * @return Posao[]
	 */
	public function skupina( string $skupina ): array {
		$izbor = array();

		foreach ( $this->poslovi as $kljuc => $posao ) {
			if ( $posao->skupina() === $skupina ) {
				$izbor[ $kljuc ] = $posao;
			}
		}

		if ( Config::SKUPINA_PRIPREMA === $skupina ) {
			uasort(
				$izbor,
				function ( Posao $a, Posao $b ) {
					return $a->korak() <=> $b->korak();
				}
			);
		}

		return $izbor;
	}

	/**
	 * Je li posao odraden — u smislu STANJA PODATAKA, ne povijesti izvodenja.
	 *
	 * Ponisten posao NIJE odraden: njegov ucinak je vracen. To je cijela pouka
	 * slucaja od 13.9.2026.
	 */
	public function odraden( string $kljuc ): bool {
		$s = $this->stanje( $kljuc );
		return ( null !== $s->zavrseno ) && ! $s->ponisten() && Config::STATUS_GRESKA !== $s->status;
	}

	/**
	 * Cega posao ceka, ili prazno ako ne ceka nista.
	 *
	 * Vraca recenicu za korisnika, ne kljuc — ekran je samo ispisuje.
	 */
	public function ceka( string $kljuc ): string {
		$posao = $this->poslovi[ $kljuc ] ?? null;
		if ( ! $posao ) {
			return '';
		}

		$preduvjet = $posao->preduvjet();
		if ( '' === $preduvjet || $this->odraden( $preduvjet ) ) {
			return '';
		}

		$prvi  = $this->poslovi[ $preduvjet ] ?? null;
		$naziv = $prvi ? $prvi->naziv() : $preduvjet;

		$razlog = $posao->preduvjet_razlog();

		$uvod = sprintf(
			/* translators: %s = naziv posla koji mora prvi */
			__( 'Prvo treba proci "%s".', Config::TEXT_DOMAIN ),
			$naziv
		);

		return ( '' === $razlog ) ? $uvod : $uvod . ' ' . $razlog;
	}

	/** Smije li se posao ponuditi za pokretanje. */
	public function moze( string $kljuc ): bool {
		$posao = $this->poslovi[ $kljuc ] ?? null;
		if ( ! $posao ) {
			return false;
		}
		return '' === $this->ceka( $kljuc ) && '' === $posao->zapreka();
	}

	/**
	 * Posao koji je sljedeci na redu u pripremi, ili null.
	 *
	 * Prvi po koraku koji nije odraden. Posao koji ceka preduvjet preskace se —
	 * inace bi vrh ekrana upucivao na nesto sto se ne da pokrenuti.
	 */
	public function sljedeci(): ?Posao {
		foreach ( $this->skupina( Config::SKUPINA_PRIPREMA ) as $kljuc => $posao ) {
			if ( $this->odraden( $kljuc ) ) {
				continue;
			}
			if ( '' !== $this->ceka( $kljuc ) ) {
				continue;
			}
			return $posao;
		}
		return null;
	}

	/**
	 * Jedna recenica na vrhu ekrana: sto je sljedece.
	 *
	 * Jedna, ne popis. Administrator koji je prvi put otvorio ekran treba znati sto
	 * kliknuti, a ne dobiti jos jedan izbor.
	 */
	public function sljedeci_korak(): string {
		$priprema = $this->skupina( Config::SKUPINA_PRIPREMA );

		$sljedeci = $this->sljedeci();

		if ( $sljedeci ) {
			$zapreka = $sljedeci->zapreka();

			if ( '' !== $zapreka ) {
				return sprintf(
					/* translators: 1: naziv posla, 2: zapreka */
					__( 'Na redu je "%1$s", ali jos ne moze: %2$s', Config::TEXT_DOMAIN ),
					$sljedeci->naziv(),
					$zapreka
				);
			}

			return sprintf(
				/* translators: %s = naziv posla */
				__( 'Sljedeci korak: pokrenite "%s".', Config::TEXT_DOMAIN ),
				$sljedeci->naziv()
			);
		}

		// Nema nista na redu. Ili je sve gotovo, ili nesto stoji zbog preduvjeta
		// koji se ne moze ispuniti — a to su dvije razlicite poruke.
		foreach ( $priprema as $kljuc => $posao ) {
			if ( $this->odraden( $kljuc ) ) {
				continue;
			}
			return sprintf(
				/* translators: 1: naziv posla, 2: cega ceka */
				__( 'Zastalo na "%1$s". %2$s', Config::TEXT_DOMAIN ),
				$posao->naziv(),
				$this->ceka( $kljuc )
			);
		}

		return __( 'Priprema je gotova. Dodatak dalje radi sam.', Config::TEXT_DOMAIN );
	}

	/** Je li cijela priprema odradena. */
	public function priprema_gotova(): bool {
		foreach ( $this->skupina( Config::SKUPINA_PRIPREMA ) as $kljuc => $posao ) {
			if ( ! $this->odraden( $kljuc ) ) {
				return false;
			}
		}
		return true;
	}

	/** Koliko je koraka pripreme odradeno, i koliko ih je ukupno. */
	public function napredak_pripreme(): array {
		$priprema = $this->skupina( Config::SKUPINA_PRIPREMA );
		$gotovih  = 0;

		foreach ( $priprema as $kljuc => $posao ) {
			if ( $this->odraden( $kljuc ) ) {
				$gotovih++;
			}
		}

		return array( 'gotovih' => $gotovih, 'ukupno' => count( $priprema ) );
	}

	/**
	 * Ima li u skupini ponistavanja ista sto se doista moze vratiti.
	 *
	 * Sluzi tome da se ta skupina uopce ne pojavi kad nema sto vracati — povrat se
	 * ne nudi onome tko ga ne treba.
	 */
	public function ima_sto_vratiti(): bool {
		foreach ( $this->skupina( Config::SKUPINA_VRACANJE ) as $posao ) {
			if ( '' === $posao->zapreka() ) {
				return true;
			}
		}
		return false;
	}
}
