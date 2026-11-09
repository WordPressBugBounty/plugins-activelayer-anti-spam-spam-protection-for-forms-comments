/**
 * Allow Ninja Forms to retry a submission rejected by ActiveLayer.
 *
 * Ninja Forms retains server-side form errors between attempts. Remove only
 * our previous verdict before validation; the server checks every new attempt.
 *
 * @since 1.7.0
 */
( function () {
	'use strict';

	/**
	 * Clear the previous ActiveLayer error on the form being submitted.
	 *
	 * @param {Backbone.Model} formModel Ninja Forms form model.
	 */
	function clearPreviousVerdict( formModel ) {
		window.nfRadio.channel( 'form-' + formModel.get( 'id' ) ).request( 'remove:error', 'activelayer' );
		captureClientSignals( formModel );
	}

	/**
	 * Copy only this form's rendered signals into Ninja Forms' native extra data.
	 *
	 * @param {Backbone.Model} formModel Ninja Forms form model.
	 */
	function captureClientSignals( formModel ) {
		var container = document.getElementById( 'nf-form-' + formModel.get( 'id' ) + '-cont' );
		if ( ! container ) {
			return;
		}

		var behavioral = container.querySelector( 'input[name="activelayer_behavioral_signals"]' );
		var collectors = window.ActiveLayer && window.ActiveLayer._collectors;
		var collector = behavioral && collectors && collectors.get( behavioral.closest( 'form' ) );
		if ( collector && collector._initialized ) {
			behavioral.value = JSON.stringify( collector.getFingerprint() );
		}

		var signals = {};
		[ 'activelayer_env_signals', 'activelayer_behavioral_signals', 'al_hp_email' ].forEach( function ( name ) {
			var input = container.querySelector( 'input[name="' + name + '"]' );
			if ( input ) {
				signals[ name ] = input.value;
			}
		} );

		var extra = Object.assign( {}, formModel.get( 'extra' ) || {} );
		if ( Object.keys( signals ).length ) {
			extra.activelayer_client_signals = signals;
		} else {
			delete extra.activelayer_client_signals;
		}
		formModel.set( 'extra', extra );
	}

	if ( window.nfRadio ) {
		window.nfRadio.channel( 'forms' ).on( 'before:submit', clearPreviousVerdict );
	}
} )();
