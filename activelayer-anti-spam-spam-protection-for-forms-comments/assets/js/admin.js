/**
 * ActiveLayer Plugin Admin JavaScript - MVP Version
 */

( function( $ ) {
	'use strict';

	const strings = ( window.activelayerAdmin && activelayerAdmin.strings ) || {};
	const verifyButton = $( '#verify-api-key' );
	const defaultVerifyLabel = verifyButton.text();
	verifyButton.data( 'default-label', defaultVerifyLabel );

	const getString = ( key ) => ( typeof strings[ key ] === 'string' ? strings[ key ] : '' );

	// API Key Verification
	verifyButton.on( 'click', function( e ) {
		e.preventDefault();

		const button = $( this );
		const apiKeyInput = $( '#api_key' );
		const apiKey = apiKeyInput.val().trim();
		const resultContainer = $( '#api-key-verification-result' );

		if ( ! apiKey ) {
			const message = getString( 'emptyApiKey' );
			if ( message ) {
				showVerificationMessage( resultContainer, 'error', message );
			}
			hideValidIndicator();
			return;
		}

		// Disable button and show loading state
		button.prop( 'disabled', true );
		const verifyingText = getString( 'verifying' );
		if ( verifyingText ) {
			button.text( verifyingText );
		}
		resultContainer.empty();
		// Clear any existing PHP-rendered status messages
		resultContainer.siblings( '.api-status' ).remove();
		hideValidIndicator();

		// Make AJAX request
		$.ajax( {
			url: activelayerAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'activelayer_verify_api_key',
				nonce: activelayerAdmin.verifyKeyNonce,
				api_key: apiKey
			},
			success: function( response ) {
				if ( response.success ) {
					showVerificationMessage( resultContainer, 'success', response.data.message );
					showValidIndicator();

					// Reload page after successful verification to show updated UI state.
					if ( response.data.key_saved ) {
						setTimeout( function() {
							window.location.reload();
						}, 1500 );
					}
				} else {
					showVerificationMessage( resultContainer, 'error', response.data.message );
					hideValidIndicator();
				}
			},
			error: function() {
				const errorMessage = getString( 'connectionError' );
				if ( errorMessage ) {
					showVerificationMessage( resultContainer, 'error', errorMessage );
				}
				hideValidIndicator();
			},
			complete: function() {
				button.prop( 'disabled', false );
				const verifyLabel = getString( 'verifyKey' ) || button.data( 'default-label' );
				if ( verifyLabel ) {
					button.text( verifyLabel );
				}
			}
		} );
	} );

	// Hide valid indicator when API key input changes
	$( '#api_key' ).on( 'input', function() {
		hideValidIndicator();
	} );

	function showVerificationMessage( container, type, message ) {
		const iconClass = type === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning';
		const statusClass = type === 'success' ? 'api-status-connected' : 'api-status-missing';

		const messageWrapper = $( '<p>' )
			.addClass( 'api-status' )
			.addClass( statusClass );

		const icon = $( '<span>' )
			.addClass( 'dashicons' )
			.addClass( iconClass );

		const text = $( '<span>' )
			.addClass( 'api-status-text' )
			.text( message );

		messageWrapper.append( icon ).append( ' ' ).append( text );

		container.empty().append( messageWrapper );
	}

	function showValidIndicator() {
		// Remove existing indicator if present
		$( '#api-key-valid-indicator' ).remove();

		// Add the checkmark indicator
		const indicator = $( '<span class="dashicons dashicons-yes-alt" id="api-key-valid-indicator"></span>' )
			.css( {
				'color': '#00a32a',
				'margin-left': '-32px',
				'z-index': '10',
				'position': 'relative'
			} );

		const indicatorTitle = getString( 'apiKeyValidTitle' );
		if ( indicatorTitle ) {
			indicator.attr( 'title', indicatorTitle );
		}

		$( '#api_key' ).after( indicator );
	}

	function hideValidIndicator() {
		$( '#api-key-valid-indicator' ).remove();
	}

	// Auto-refresh dashboard stats every 30 seconds
	if ( $( '.activelayer-stats-grid' ).length ) {
		setInterval( function() {
			refreshDashboardStats();
		}, 30000 );
	}

	function refreshDashboardStats() {
		$.ajax( {
			url: activelayerAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'activelayer_refresh_stats',
				nonce: activelayerAdmin.nonce
			},
			success: function( response ) {
				if ( response.success && response.data ) {
					updateStatCards( response.data );
				}
			}
		} );
	}

	function updateStatCards( stats ) {
		$( '.activelayer-stat-card' ).each( function() {
			const card = $( this );
			const statType = card.data( 'stat-type' );

			if ( stats[ statType ] !== undefined ) {
				card.find( '.stat-number' ).text( formatNumber( stats[ statType ] ) );
			}
		} );
	}

	function formatNumber( num ) {
		if ( num >= 1000000 ) {
			return ( num / 1000000 ).toFixed( 1 ) + 'M';
		} else if ( num >= 1000 ) {
			return ( num / 1000 ).toFixed( 1 ) + 'K';
		}
		return num.toString();
	}

	// Usage limit notice dismiss — persist the native WP dismissal per billing
	// period. WordPress core handles hiding the notice; we only record it.
	$( document ).on( 'click', '#activelayer-usage-banner .notice-dismiss', function() {
		$.ajax( {
			url: activelayerAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'activelayer_dismiss_usage_banner',
				nonce: activelayerAdmin.nonce
			}
		} );
	} );

	// First-spam review request notice dismiss
	$( document ).on( 'click', '#activelayer-review-notice .activelayer-review-dismiss', function( e ) {
		const $link = $( this );

		// Internal links (#) must not jump; the external review link opens in a new tab.
		if ( ! $link.hasClass( 'activelayer-review-out' ) ) {
			e.preventDefault();
		}

		$.ajax( {
			url: activelayerAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'activelayer_dismiss_review_request',
				nonce: activelayerAdmin.nonce
			}
		} );

		$( '#activelayer-review-notice' ).slideUp( 200 );
	} );

	// Bulk actions confirmation
	$( '.wp-list-table' ).on( 'submit', function( e ) {
		const action = $( this ).find( 'select[name="action"]' ).val();
		const selectedItems = $( this ).find( 'input[name="submission[]"]:checked' ).length;

		if ( action === 'delete' && selectedItems > 0 ) {
			const template = getString( 'bulkDeleteConfirm' );
			if ( ! template ) {
				return true;
			}

			const message = template.replace( '%s', selectedItems );

			if ( ! confirm( message ) ) {
				e.preventDefault();
				return false;
			}
		}
	} );

	// Delete action confirmation
	$( '#major-publishing-actions .submitdelete' ).on( 'click', function( e ) {
		const message = getString( 'singleDeleteConfirm' );

		if ( message && ! confirm( message ) ) {
			e.preventDefault();
			return false;
		}
	} );

	// Install & Activate plugin (promo row)
	$( document ).on( 'click', '.activelayer-install-plugin', function( e ) {
		e.preventDefault();

		var button = $( this );
		var pluginSlug = button.data( 'plugin-slug' );
		var originalText = button.text();

		button.prop( 'disabled', true );
		button.text( getString( 'installing' ) || 'Installing...' );

		$.ajax( {
			url: activelayerAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'activelayer_install_plugin',
				nonce: activelayerAdmin.installPluginNonce,
				plugin_slug: pluginSlug
			},
			success: function( response ) {
				if ( response.success ) {
					button.text( getString( 'activating' ) || 'Activating...' );
					setTimeout( function() {
						window.location.reload();
					}, 1000 );
				} else {
					button.text( originalText );
					button.prop( 'disabled', false );
					if ( response.data && response.data.message ) {
						alert( response.data.message ); // eslint-disable-line no-alert
					}
				}
			},
			error: function() {
				button.text( originalText );
				button.prop( 'disabled', false );
				alert( getString( 'installError' ) || 'Installation failed. Please try again.' ); // eslint-disable-line no-alert
			}
		} );
	} );

	// Copy API ID to clipboard
	$( document ).on( 'click', '.activelayer-copy-api-id', function( e ) {
		e.preventDefault();

		var link = $( this );
		var apiId = link.data( 'api-id' );

		if ( ! apiId ) {
			return;
		}

		var originalText = link.text();
		var copiedText = getString( 'copied' ) || 'Copied!';

		function showCopied() {
			link.text( copiedText );
			setTimeout( function() {
				link.text( originalText );
			}, 2000 );
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( apiId ).then( showCopied );
		} else {
			var textarea = document.createElement( 'textarea' );
			textarea.value = apiId;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textarea );
			showCopied();
		}
	} );

	// Onboarding dismiss
	$( '#activelayer-dismiss-onboarding' ).on( 'click', function( e ) {
		e.preventDefault();

		var message = getString( 'dismissOnboardingConfirm' );

		if ( message && ! confirm( message ) ) {
			return;
		}

		var banner = $( '#activelayer-onboarding-banner' );

		$.ajax( {
			url: activelayerAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'activelayer_dismiss_onboarding',
				nonce: activelayerAdmin.nonce
			},
			success: function( response ) {
				if ( response.success ) {
					banner.fadeOut( 300, function() {
						banner.remove();
					} );
				}
			}
		} );
	} );

	// Opt-out announce notice dismiss — fires on both the X button and the
	// CTA link. Fire-and-forget; if the CTA navigation races the request,
	// the notice simply re-renders on the next page load and remains
	// dismissable.
	$( document ).on(
		'click',
		'.activelayer-opt-out-notice .notice-dismiss, .activelayer-opt-out-notice .activelayer-opt-out-cta',
		function() {
			$.ajax( {
				url: activelayerAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'activelayer_dismiss_opt_out_announce',
					nonce: activelayerAdmin.nonce
				}
			} );
		}
	);

} )( jQuery );
