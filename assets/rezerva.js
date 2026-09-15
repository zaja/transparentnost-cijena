/**
 * Rezervni prikaz sidrene cijene.
 *
 * Ucitava se SAMO na stranici na kojoj nas PHP filter nije ispisao nista —
 * dakle na temi ili graditelju koji cijenu crta mimo `get_price_html()`, i kod
 * bloka "All Products", koji je slaze u pregledniku iz Store API-ja.
 *
 * NE CRTA NISTA SAM. Trazi od posluzitelja isti HTML koji bi ispisao i filter;
 * dvije izvedbe iste tvrdnje razisle bi se, a razlika bi se vidjela tek u
 * nadzoru.
 *
 * Bez ovisnosti i bez jQueryja: dodatak koji trazi knjiznicu da bi ispisao
 * jedan redak teksta placa je na svakoj stranici.
 */
( function () {
	'use strict';

	if ( typeof window.cjtrRezerva === 'undefined' ) {
		return;
	}

	var P = window.cjtrRezerva;

	/** Sto je vec obradeno — da isti artikl ne pitamo dvaput. */
	var pitani = {};

	/**
	 * Nadi elemente cijene kojima nas redak nedostaje.
	 *
	 * Trazi se `.price` jer je to jedina oznaka koju WooCommerce jamci — i teme
	 * koje cijenu crtaju same gotovo je uvijek zadrze, jer o njoj vise njihov
	 * vlastiti CSS.
	 */
	function kandidati() {
		var nadeni = [];
		var cijene = document.querySelectorAll( '.price, .wc-block-components-product-price' );

		for ( var i = 0; i < cijene.length; i++ ) {
			var cijena = cijene[ i ];

			// Vec ispisano — ili filterom, ili nasim ranijim prolazom.
			if ( cijena.querySelector( '.' + P.razred ) ) {
				continue;
			}

			var id = idArtikla( cijena );

			if ( ! id || pitani[ id ] ) {
				continue;
			}

			nadeni.push( { id: id, element: cijena } );
		}

		return nadeni;
	}

	/**
	 * ID artikla za dani element cijene.
	 *
	 * Redoslijed je od najpouzdanijeg prema najslabijem. `post-<id>` je
	 * WordPressova klasa i stoji na omotacu artikla u gotovo svakoj temi;
	 * `data-product-id` dodaju blokovi i dio graditelja.
	 */
	function idArtikla( element ) {
		var cvor = element;

		while ( cvor && cvor !== document.body ) {
			if ( cvor.dataset ) {
				var izravni = cvor.dataset.productId || cvor.dataset.product_id;

				if ( izravni && /^\d+$/.test( izravni ) ) {
					return izravni;
				}
			}

			if ( cvor.className && typeof cvor.className === 'string' ) {
				var pogodak = cvor.className.match( /(?:^|\s)post-(\d+)(?:\s|$)/ );

				if ( pogodak ) {
					return pogodak[ 1 ];
				}
			}

			// Gumb "dodaj u kosaricu" nosi ID i ondje gdje ga omotac nema.
			var gumb = cvor.querySelector ? cvor.querySelector( '[data-product_id]' ) : null;

			if ( gumb && /^\d+$/.test( gumb.dataset.product_id ) ) {
				return gumb.dataset.product_id;
			}

			cvor = cvor.parentNode;
		}

		return null;
	}

	function dopisi( nadeni, odgovor ) {
		for ( var i = 0; i < nadeni.length; i++ ) {
			var html = odgovor[ nadeni[ i ].id ];

			if ( ! html ) {
				continue;
			}

			// Jos jednom, jer je filter mogao ispisati dok je zahtjev trajao.
			if ( nadeni[ i ].element.querySelector( '.' + P.razred ) ) {
				continue;
			}

			nadeni[ i ].element.insertAdjacentHTML( 'beforeend', html );
		}
	}

	function pitaj() {
		var nadeni = kandidati();

		if ( ! nadeni.length ) {
			return;
		}

		nadeni = nadeni.slice( 0, P.max );

		var ids = [];

		for ( var i = 0; i < nadeni.length; i++ ) {
			ids.push( nadeni[ i ].id );
			pitani[ nadeni[ i ].id ] = true;
		}

		var tijelo = new URLSearchParams();
		tijelo.append( 'action', P.akcija );
		tijelo.append( 'artikli', ids.join( ',' ) );

		fetch( P.url, {
			method: 'POST',
			credentials: 'same-origin',
			body: tijelo
		} )
			.then( function ( o ) {
				return o.json();
			} )
			.then( function ( o ) {
				if ( o && o.success && o.data ) {
					dopisi( nadeni, o.data );
				}
			} )
			.catch( function () {
				/* Rezerva koja padne ne smije nista srusiti. */
			} );
	}

	function pokreni() {
		pitaj();

		/*
		 * Blok "All Products" i beskonacno listanje crtaju cijene POSLIJE ucitavanja.
		 * Promatrac ceka da se DOM smiri pa pita za ono sto je pridoslo.
		 */
		if ( typeof MutationObserver === 'undefined' ) {
			return;
		}

		var odgoda = null;

		new MutationObserver( function () {
			window.clearTimeout( odgoda );
			odgoda = window.setTimeout( pitaj, 300 );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', pokreni );
	} else {
		pokreni();
	}
}() );
