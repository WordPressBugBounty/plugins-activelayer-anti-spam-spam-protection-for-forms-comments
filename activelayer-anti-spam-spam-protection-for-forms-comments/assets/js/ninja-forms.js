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
	}

	if ( window.nfRadio ) {
		window.nfRadio.channel( 'forms' ).on( 'before:submit', clearPreviousVerdict );
	}
} )();
