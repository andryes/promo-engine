/**
 * Promo Engine frontend: popup (once per session, accessible) + tracking beacons.
 */
( function () {
	'use strict';

	var cfg = window.promoEngine || {};

	function track( event, promoId, variant ) {
		if ( ! cfg.ajaxUrl || ! promoId ) {
			return;
		}
		var data = new FormData();
		data.append( 'action', 'pe_track' );
		data.append( 'nonce', cfg.nonce );
		data.append( 'event', event );
		data.append( 'promo_id', promoId );
		if ( variant ) {
			data.append( 'variant', variant );
		}
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.ajaxUrl, data );
		} else {
			fetch( cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } );
		}
	}

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function startCountdown( el, ends ) {
		var value = el.querySelector( '.pe-popup__timer-value' ) || el;

		function tick() {
			var left = ends - Date.now();
			if ( left <= 0 ) {
				value.textContent = ( cfg.i18n && cfg.i18n.ended ) || 'Ended';
				window.clearInterval( timer );
				return;
			}
			var s = Math.floor( left / 1000 );
			var days = Math.floor( s / 86400 );
			var h = Math.floor( ( s % 86400 ) / 3600 );
			var m = Math.floor( ( s % 3600 ) / 60 );
			var sec = s % 60;
			var text = pad( h ) + ':' + pad( m ) + ':' + pad( sec );
			if ( days > 0 ) {
				text = days + ( ( cfg.i18n && cfg.i18n.days ) || 'd' ) + ' ' + text;
			}
			value.textContent = text;
		}

		var timer = window.setInterval( tick, 1000 );
		tick();
	}

	function initPopup() {
		var popup = document.getElementById( 'pe-popup' );
		if ( ! popup || ! cfg.popup || ! cfg.popup.id ) {
			return;
		}

		var storageKey = 'pePopupSeen-' + cfg.popup.id;
		var seen = false;
		try {
			seen = window.sessionStorage.getItem( storageKey ) === '1';
		} catch ( e ) {
			// Storage unavailable — show anyway.
		}
		if ( seen ) {
			return;
		}

		var lastFocused = null;

		// A/B test: variant B headline configured → split visitors 50/50,
		// remember the assignment for the session, report it with beacons.
		var variant = '';
		var titles = cfg.popup.titles || {};
		if ( titles.b ) {
			var variantKey = 'pePopupVariant-' + cfg.popup.id;
			try {
				variant = window.sessionStorage.getItem( variantKey ) || '';
			} catch ( e ) {
				// Storage unavailable.
			}
			if ( 'a' !== variant && 'b' !== variant ) {
				variant = Math.random() < 0.5 ? 'a' : 'b';
				try {
					window.sessionStorage.setItem( variantKey, variant );
				} catch ( e ) {
					// Ignore.
				}
			}
			var titleEl = popup.querySelector( '.pe-popup__title' );
			if ( titleEl && titles[ variant ] ) {
				titleEl.textContent = titles[ variant ];
			}
		}

		function focusables() {
			return popup.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		}

		function onKeydown( e ) {
			if ( 'Escape' === e.key ) {
				close();
				return;
			}
			if ( 'Tab' !== e.key ) {
				return;
			}
			var items = focusables();
			if ( ! items.length ) {
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}

		function close() {
			popup.hidden = true;
			document.body.classList.remove( 'pe-popup-open' );
			document.removeEventListener( 'keydown', onKeydown, true );
			if ( lastFocused && lastFocused.focus ) {
				lastFocused.focus();
			}
		}

		function show() {
			try {
				window.sessionStorage.setItem( storageKey, '1' );
			} catch ( e ) {
				// Ignore.
			}
			lastFocused = document.activeElement;
			popup.hidden = false;
			document.body.classList.add( 'pe-popup-open' );
			document.addEventListener( 'keydown', onKeydown, true );

			var closeBtn = popup.querySelector( '.pe-popup__close' );
			if ( closeBtn ) {
				closeBtn.focus();
			}

			var timerEl = popup.querySelector( '[data-pe-countdown]' );
			if ( timerEl && cfg.popup.ends ) {
				startCountdown( timerEl, cfg.popup.ends );
			}

			track( 'view', cfg.popup.id, variant );
		}

		popup.addEventListener( 'click', function ( e ) {
			var target = e.target;
			if ( target.closest( '[data-pe-close]' ) ) {
				close();
			}
			if ( target.closest( '[data-pe-cta]' ) ) {
				track( 'click', cfg.popup.id, variant );
				if ( cfg.dealsUrl ) {
					window.setTimeout( function () {
						window.location.href = cfg.dealsUrl;
					}, 120 );
				} else {
					close();
				}
			}
		} );

		var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		window.setTimeout( show, reduceMotion ? 0 : 1200 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initPopup );
	} else {
		initPopup();
	}
}() );
