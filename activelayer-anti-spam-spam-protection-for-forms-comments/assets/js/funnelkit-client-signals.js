/**
 * ActiveLayer — FunnelKit client-signal bridge.
 *
 * FunnelKit's wfopp_output_form_tag_before hook fires inside the
 * .wffn-optin-form wrapper but BEFORE the <form> tag, and FunnelKit submits
 * via jQuery serialize() on the <form>, so signal inputs rendered at the hook
 * would never reach $_POST. This bridge's only job is to move them inside the
 * form, and to re-run that move when FunnelKit re-renders popup opt-ins.
 *
 * Block UX is no longer handled here: on a spam verdict the server emits a
 * native FunnelKit next_url redirect to the opt-in page (?al_blocked=1) and a
 * server-side notice is rendered on reload — no JS involved.
 *
 * jQuery is used only to bind the popup re-render listener (FunnelKit's public
 * script depends on it too) — enqueued with a 'jquery' dependency.
 *
 * @since 1.5.0
 */
( function () {
	'use strict';

	/**
	 * Move each signal box's inputs inside its adjacent opt-in <form>.
	 *
	 * The fields are cloned (innerHTML), leaving the hidden source box intact so
	 * a popup re-render can re-inject them into a freshly rendered form.
	 */
	function moveSignals() {
		var boxes = document.querySelectorAll( '.activelayer-funnelkit-signals' );

		Array.prototype.forEach.call( boxes, function ( box ) {
			var wrapper = box.parentElement;
			var form    = wrapper ? wrapper.querySelector( 'form.wffn-custom-optin-from' ) : null;

			if ( ! form ) {
				return;
			}

			// Already injected — leave it alone. The honeypot class covers the
			// honeypot-only configuration where both tracking inputs are off.
			if (
				form.querySelector(
					'input.activelayer-env-signals, input.activelayer-behavioral-signals, input.activelayer-hp-field'
				)
			) {
				return;
			}

			// Clone into the form, leaving the source box intact for re-renders.
			form.insertAdjacentHTML( 'beforeend', box.innerHTML );
		} );
	}

	/**
	 * Re-run the signal move when FunnelKit re-renders popup opt-ins.
	 *
	 * @param {Function} $ jQuery.
	 */
	function bind( $ ) {
		// FunnelKit triggers this event when popup opt-ins are re-rendered
		// (e.g. after a page transition inside a funnel). Re-run the DOM setup
		// so signal fields are moved into the freshly rendered form.
		$( document ).on( 'wffn_reload_popups', function () {
			moveSignals();
		} );
	}

	function init() {
		moveSignals();

		if ( window.jQuery ) {
			bind( window.jQuery );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
