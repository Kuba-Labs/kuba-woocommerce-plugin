/**
 * Captures billing fields on the classic WooCommerce checkout and sends them
 * to the plugin REST endpoint so the server-side sweep can detect abandoned
 * checkouts even when no draft order exists.
 *
 * On Blocks checkout this script is a no-op because the classic
 * <form class="checkout woocommerce-checkout"> element does not exist.
 */
(function () {
	'use strict';

	var form = document.querySelector( 'form.checkout.woocommerce-checkout' );
	if ( ! form ) return;

	var selectors = kubaCheckoutCapture.selectors || {
		phone:      '#billing_phone',
		email:      '#billing_email',
		first_name: '#billing_first_name',
		last_name:  '#billing_last_name',
	};

	var timer    = null;
	var DEBOUNCE = 3000;
	var sending  = false;

	function collect() {
		var data       = {};
		var hasContact = false;

		for ( var key in selectors ) {
			var el = form.querySelector( selectors[ key ] );
			data[ key ] = el ? el.value.trim() : '';
			if ( ( key === 'phone' || key === 'email' ) && data[ key ] ) {
				hasContact = true;
			}
		}

		if ( ! hasContact ) return null;

		var countryEl = form.querySelector( '#billing_country' );
		data.country  = countryEl ? countryEl.value : '';

		// Grab the order total from the review table.
		var totalEl = document.querySelector( '.order-total .woocommerce-Price-amount' );
		if ( totalEl ) {
			var raw = totalEl.textContent.replace( /[^\d.,]/g, '' );
			var lastComma = raw.lastIndexOf( ',' );
			var lastDot   = raw.lastIndexOf( '.' );
			if ( lastComma > lastDot ) {
				raw = raw.replace( /\./g, '' ).replace( ',', '.' );
			} else {
				raw = raw.replace( /,/g, '' );
			}
			data.cart_total = raw;
		}

		return data;
	}

	function send() {
		var data = collect();
		if ( ! data || sending ) return;

		sending = true;
		fetch( kubaCheckoutCapture.endpoint, {
			method:    'POST',
			headers:   {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   kubaCheckoutCapture.nonce,
			},
			body:      JSON.stringify( data ),
			keepalive: true,
		} )
			.catch( function () {} )
			.finally( function () { sending = false; } );
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( send, DEBOUNCE );
	}

	for ( var key in selectors ) {
		var el = form.querySelector( selectors[ key ] );
		if ( el ) {
			el.addEventListener( 'input',  schedule );
			el.addEventListener( 'change', schedule );
		}
	}

	// Flush on page-hide so we capture even if the customer leaves quickly.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			clearTimeout( timer );
			send();
		}
	} );
})();
