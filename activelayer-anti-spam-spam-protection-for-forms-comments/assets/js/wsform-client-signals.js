/**
 * ActiveLayer — WS Form client-signal bridge.
 *
 * WS Form renders its form body client-side. During form_build() it clears the
 * <form> element's children (shared/js/ws-form.js) and rebuilds the fields from
 * its JSON model, so client-signal inputs injected server-side inside the form
 * are wiped before the user can submit. As a result they never reach $_POST.
 *
 * This bridge keeps the signal fields in a hidden sibling box (rendered after
 * the form, where WS Form does not touch them) and clones them into the
 * rendered <form> once WS Form fires its `wsf-rendered` jQuery event. From
 * there the existing ActiveLayer collectors populate the inputs (their
 * MutationObserver picks up the new nodes) and rewrite them on submit, and
 * WS Form's own `new FormData(form)` serialization includes them.
 *
 * The event is a jQuery event on document, so jQuery (a WS Form dependency) is
 * required — this script is enqueued with a 'jquery' dependency.
 *
 * @since 1.4.0
 */
( function () {
	'use strict';

	/**
	 * Clone the signal fields for a given form id into its rendered <form>.
	 *
	 * @param {number|string} formId  WS Form form id.
	 * @param {Object}        formObj jQuery-wrapped <form> element from the event.
	 */
	function injectSignals( formId, formObj ) {
		var form = formObj && formObj.length ? formObj[ 0 ] : null;
		var box  = document.querySelector(
			'.activelayer-wsform-signals[data-wsf-form-id="' + formId + '"]'
		);

		if ( ! form || ! box ) {
			return;
		}

		// Already injected (e.g. a second render pass) — leave it alone. The
		// honeypot class covers the honeypot-only configuration where both
		// tracking inputs are disabled.
		if (
			form.querySelector(
				'input.activelayer-env-signals, input.activelayer-behavioral-signals, input.activelayer-hp-field'
			)
		) {
			return;
		}

		// Clone rather than move so a subsequent re-render can re-inject.
		form.insertAdjacentHTML( 'beforeend', box.innerHTML );
	}

	/**
	 * Bind the WS Form render listener and sweep any pre-rendered forms.
	 *
	 * @param {Function} $ jQuery.
	 */
	function bind( $ ) {
		$( document ).on( 'wsf-rendered', function ( event, form, formId, instanceId, formObj ) { // eslint-disable-line no-unused-vars
			injectSignals( formId, formObj );
		} );

		// Forms already rendered before this script bound.
		var rendered = document.querySelectorAll( 'form.wsf-form[data-wsf-rendered]' );
		Array.prototype.forEach.call( rendered, function ( el ) {
			injectSignals( el.getAttribute( 'data-id' ), $( el ) );
		} );
	}

	if ( window.jQuery ) {
		bind( window.jQuery );
	}
} )();
