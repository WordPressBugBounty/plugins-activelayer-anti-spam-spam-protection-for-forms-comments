/**
 * ActiveLayer Integrations Page
 *
 * Handles inline panel expand/collapse, AJAX settings save, enable toggles,
 * and Select All functionality for the Integrations admin page.
 *
 * @since 1.1.0
 */
( function() {
	'use strict';

	const config = window.activelayerIntegrations || {};

	/**
	 * Initialize all integrations page behaviors.
	 */
	function init() {

		bindConfigureButtons();
		bindEnableToggles();
		bindFormSubmits();
		bindSelectAllButtons();
	}

	// =====================================================================
	// Panel Expand / Collapse (Accordion)
	// =====================================================================

	/**
	 * Bind click handlers to all Configure buttons.
	 */
	function bindConfigureButtons() {

		const buttons = document.querySelectorAll( '.activelayer-integration-configure[aria-expanded]' );

		buttons.forEach( function( button ) {
			button.addEventListener( 'click', function() {
				togglePanel( button );
			} );
		} );
	}

	/**
	 * Toggle a settings panel open or closed (accordion: close others first).
	 *
	 * @param {HTMLElement} button The Configure button that was clicked.
	 */
	function togglePanel( button ) {

		const panelId   = button.getAttribute( 'aria-controls' );
		const panel     = document.getElementById( panelId );
		const isOpen    = button.getAttribute( 'aria-expanded' ) === 'true';

		if ( ! panel ) {
			return;
		}

		if ( isOpen ) {
			closePanel( button, panel );
		} else {
			// Accordion: close all other panels first.
			closeAllPanels();
			openPanel( button, panel );
		}
	}

	/**
	 * Open a panel with scrollHeight animation.
	 *
	 * @param {HTMLElement} button The Configure button.
	 * @param {HTMLElement} panel  The panel element.
	 */
	function openPanel( button, panel ) {

		button.setAttribute( 'aria-expanded', 'true' );
		panel.style.maxHeight = panel.scrollHeight + 'px';

		// After transition, remove max-height constraint so content resizing works.
		panel.addEventListener( 'transitionend', function handler() {
			panel.removeEventListener( 'transitionend', handler );
			if ( button.getAttribute( 'aria-expanded' ) === 'true' ) {
				panel.classList.add( 'is-open' );
				panel.style.maxHeight = '';
			}
		} );
	}

	/**
	 * Close a panel with animation.
	 *
	 * @param {HTMLElement} button The Configure button.
	 * @param {HTMLElement} panel  The panel element.
	 */
	function closePanel( button, panel ) {

		panel.classList.remove( 'is-open' );

		// Set explicit height first so transition can animate from it.
		panel.style.maxHeight = panel.scrollHeight + 'px';

		// Force reflow, then collapse.
		panel.offsetHeight; // eslint-disable-line no-unused-expressions
		panel.style.maxHeight = '0';

		button.setAttribute( 'aria-expanded', 'false' );
	}

	/**
	 * Close all open panels.
	 */
	function closeAllPanels() {

		const buttons = document.querySelectorAll( '.activelayer-integration-configure[aria-expanded="true"]' );

		buttons.forEach( function( button ) {
			const panelId = button.getAttribute( 'aria-controls' );
			const panel   = document.getElementById( panelId );

			if ( panel ) {
				closePanel( button, panel );
			}
		} );
	}

	// =====================================================================
	// Enable Toggle (AJAX)
	// =====================================================================

	/**
	 * Bind change handlers to enable/disable toggle checkboxes.
	 */
	function bindEnableToggles() {

		const toggles = document.querySelectorAll( '.activelayer-integration-enable-toggle' );

		toggles.forEach( function( toggle ) {
			toggle.addEventListener( 'change', function() {
				saveEnabledState( toggle );
			} );
		} );
	}

	/**
	 * Save integration enabled state via AJAX.
	 *
	 * @param {HTMLInputElement} toggle The checkbox element.
	 */
	function saveEnabledState( toggle ) {

		const slug    = toggle.getAttribute( 'data-slug' );
		const enabled = toggle.checked ? '1' : '0';
		const row     = toggle.closest( '.activelayer-integration-row' );
		const badge   = row ? row.querySelector( '.status-badge' ) : null;

		// Lock the panel checkboxes for the duration of the WooCommerce
		// row-toggle AJAX so a sub-flag edit between click and response
		// can't be silently overwritten by syncWooCommerceSubCheckboxes.
		const panelInputs = ( slug === 'woocommerce' && row ) ? lockPanelCheckboxes( row ) : [];

		const formData = new FormData();
		formData.append( 'action', 'activelayer_save_integration_settings' );
		formData.append( 'nonce', config.nonce );
		formData.append( 'type', 'enabled' );
		formData.append( 'slug', slug );
		formData.append( 'enabled', enabled );

		// Show loading state.
		toggle.disabled = true;

		if ( badge ) {
			badge.dataset.prevClass = badge.className;
			badge.dataset.prevText  = badge.textContent;
			badge.className         = 'status-badge status-pending';
			badge.textContent       = config.strings.saving || 'Saving...';
		}

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		} )
			.then( function( response ) {
				return response.json();
			} )
			.then( function( data ) {

				toggle.disabled = false;
				unlockPanelCheckboxes( panelInputs );

				if ( data.success ) {
					updateBadge( badge, toggle.checked );
					updateConfigureVisibility( row, toggle.checked );
					maybeUpdateOnboardingStep2( data.data );

					// WooCommerce umbrella cascades the master toggle to both
					// sub-options on the server. Sync the panel checkboxes so
					// the next panel save doesn't post the stale unchecked
					// state and revert what we just did.
					if ( slug === 'woocommerce' && row ) {
						syncWooCommerceSubCheckboxes( row, toggle.checked );
					}

					// Auto-expand the settings panel after enabling.
					if ( toggle.checked && row ) {
						requestAnimationFrame( function() {
							const btn   = row.querySelector( '.activelayer-integration-configure' );
							const pnlId = btn ? btn.getAttribute( 'aria-controls' ) : null;
							const pnl   = pnlId ? document.getElementById( pnlId ) : null;

							if ( btn && pnl && btn.getAttribute( 'aria-expanded' ) !== 'true' ) {
								closeAllPanels();
								openPanel( btn, pnl );
							}
						} );
					}
				} else {
					toggle.checked = ! toggle.checked;
					revertBadge( badge );
				}
			} )
			.catch( function() {

				toggle.disabled = false;
				unlockPanelCheckboxes( panelInputs );
				toggle.checked  = ! toggle.checked;
				revertBadge( badge );
			} );
	}

	/**
	 * Disable every checkbox inside the row's settings panel.
	 *
	 * Used to prevent the user from editing sub-flag checkboxes between
	 * the row-toggle AJAX request and its response — otherwise the
	 * subsequent `syncWooCommerceSubCheckboxes` would silently overwrite
	 * the user's intermediate edit.
	 *
	 * @param {HTMLElement} row The integration row element.
	 *
	 * @return {Array<HTMLInputElement>} The list of checkboxes that were disabled.
	 */
	function lockPanelCheckboxes( row ) {

		const btn   = row.querySelector( '.activelayer-integration-configure' );
		const pnlId = btn ? btn.getAttribute( 'aria-controls' ) : null;
		const pnl   = pnlId ? document.getElementById( pnlId ) : null;

		if ( ! pnl ) {
			return [];
		}

		const inputs = pnl.querySelectorAll( 'input[type="checkbox"]' );

		inputs.forEach( function( i ) {
			i.disabled = true;
		} );

		return Array.prototype.slice.call( inputs );
	}

	/**
	 * Re-enable previously locked panel checkboxes.
	 *
	 * @param {Array<HTMLInputElement>} inputs The list returned by lockPanelCheckboxes.
	 */
	function unlockPanelCheckboxes( inputs ) {

		inputs.forEach( function( i ) {
			i.disabled = false;
		} );
	}

	/**
	 * Mirror the WooCommerce row toggle into both sub-section
	 * `settings[reviews][enabled]` / `settings[registration][enabled]`
	 * checkboxes inside the already-rendered panel.
	 *
	 * The server-side row toggle cascades to both sub-options, but the
	 * panel DOM was rendered with the prior state, so without this sync
	 * the next "Save Settings" in the umbrella panel would POST an
	 * unchecked enabled field and revert what we just enabled.
	 *
	 * @param {HTMLElement} row     The integration row element.
	 * @param {boolean}     enabled New enabled state.
	 */
	function syncWooCommerceSubCheckboxes( row, enabled ) {

		const btn   = row.querySelector( '.activelayer-integration-configure' );
		const pnlId = btn ? btn.getAttribute( 'aria-controls' ) : null;
		const pnl   = pnlId ? document.getElementById( pnlId ) : null;

		if ( ! pnl ) {
			return;
		}

		const subEnableInputs = pnl.querySelectorAll(
			'input[name="settings[reviews][enabled]"], input[name="settings[registration][enabled]"]'
		);

		subEnableInputs.forEach( function( input ) {
			input.checked = enabled;
		} );
	}

	/**
	 * Update the status badge to reflect the new enabled state.
	 *
	 * @param {HTMLElement|null} badge   The status badge element.
	 * @param {boolean}          enabled Whether the integration is now enabled.
	 */
	function updateBadge( badge, enabled ) {

		if ( ! badge ) {
			return;
		}

		if ( enabled ) {
			badge.className   = 'status-badge status-clean';
			badge.textContent = config.strings.active || 'Active';
		} else {
			badge.className   = 'status-badge status-pending';
			badge.textContent = config.strings.paused || 'Paused';
		}
	}

	/**
	 * Revert the badge to its previous state.
	 *
	 * @param {HTMLElement|null} badge The status badge element.
	 */
	function revertBadge( badge ) {

		if ( ! badge || ! badge.dataset.prevClass ) {
			return;
		}

		badge.className   = badge.dataset.prevClass;
		badge.textContent = badge.dataset.prevText;
	}

	/**
	 * Show or hide the Configure button and close the panel when disabling.
	 *
	 * @param {HTMLElement|null} row     The integration row element.
	 * @param {boolean}          enabled Whether the integration is now enabled.
	 */
	function updateConfigureVisibility( row, enabled ) {

		if ( ! row ) {
			return;
		}

		const button = row.querySelector( '.activelayer-integration-configure' );

		if ( ! button ) {
			return;
		}

		if ( enabled ) {
			button.style.display = '';
		} else {
			// Close the panel first if it's open.
			if ( button.getAttribute( 'aria-expanded' ) === 'true' ) {
				const panelId = button.getAttribute( 'aria-controls' );
				const panel   = document.getElementById( panelId );

				if ( panel ) {
					closePanel( button, panel );
				}
			}

			button.style.display = 'none';
		}
	}

	// =====================================================================
	// Form Submit (AJAX)
	// =====================================================================

	/**
	 * Bind submit handlers to all inline settings forms.
	 */
	function bindFormSubmits() {

		const forms = document.querySelectorAll( '.activelayer-integration-settings-form' );

		forms.forEach( function( form ) {
			form.addEventListener( 'submit', function( e ) {
				e.preventDefault();
				saveFormSettings( form );
			} );
		} );
	}

	/**
	 * Save form settings via AJAX.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function saveFormSettings( form ) {

		const slug     = form.getAttribute( 'data-slug' );
		const type     = form.getAttribute( 'data-type' );
		const feedback = form.querySelector( '.activelayer-panel-feedback' )
			|| form.parentElement.querySelector( '.activelayer-panel-feedback' );
		const button   = form.querySelector( 'button[type="submit"]' );

		const formData = new FormData();
		formData.append( 'action', 'activelayer_save_integration_settings' );
		formData.append( 'nonce', config.nonce );
		formData.append( 'type', type );
		formData.append( 'slug', slug );

		if ( type === 'comments' || type === 'woocommerce' || type === 'memberpress' ) {
			// Gather settings from named inputs.
			// Checkboxes: only append checked ones (omit unchecked) to match
			// the isset() contract in update_*_settings() handlers.
			// WooCommerce umbrella uses nested keys (settings[reviews][...]
			// and settings[registration][...]) which the server splits.
			const inputs = form.querySelectorAll( '[name^="settings["]' );

			inputs.forEach( function( input ) {
				const name = input.getAttribute( 'name' );

				if ( input.type === 'checkbox' ) {
					if ( input.checked ) {
						formData.append( name, '1' );
					}
				} else {
					formData.append( name, input.value );
				}
			} );
		} else if ( type === 'forms' ) {
			// Gather checked form IDs.
			const checkboxes = form.querySelectorAll( 'input[name="forms[]"]:checked' );

			checkboxes.forEach( function( cb ) {
				formData.append( 'forms[]', cb.value );
			} );
		}

		// Show saving state.
		if ( button ) {
			button.disabled  = true;
			button.textContent = config.strings.saving || 'Saving...';
		}

		if ( feedback ) {
			feedback.className = 'activelayer-panel-feedback';
			feedback.textContent = '';
		}

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		} )
			.then( function( response ) {
				return response.json();
			} )
			.then( function( data ) {

				if ( button ) {
					button.disabled = false;
					button.textContent = config.strings.saveButton || 'Save Settings';
				}

				if ( data.success ) {
					maybeUpdateOnboardingStep2( data.data );
				}

				if ( feedback ) {
					if ( data.success ) {
						feedback.className = 'activelayer-panel-feedback is-success';
						feedback.textContent = config.strings.saved || 'Settings saved.';
					} else {
						feedback.className = 'activelayer-panel-feedback is-error';
						feedback.textContent = ( data.data && data.data.message ) || config.strings.saveError || 'Save failed.';
					}
				}
			} )
			.catch( function() {

				if ( button ) {
					button.disabled = false;
					button.textContent = config.strings.saveButton || 'Save Settings';
				}

				if ( feedback ) {
					feedback.className = 'activelayer-panel-feedback is-error';
					feedback.textContent = config.strings.saveError || 'Save failed. Please try again.';
				}
			} );
	}

	// =====================================================================
	// Onboarding Banner Step 2 Update
	// =====================================================================

	/**
	 * Update onboarding banner step 2 after a successful AJAX save.
	 *
	 * Toggles the step between pending and completed states based on the
	 * step_2_completed flag returned by the server.
	 *
	 * @param {Object} responseData The data object from wp_send_json_success().
	 */
	function maybeUpdateOnboardingStep2( responseData ) {

		if ( ! responseData || typeof responseData.step_2_completed === 'undefined' ) {
			return;
		}

		const banner = document.getElementById( 'activelayer-onboarding-banner' );

		if ( ! banner ) {
			return;
		}

		const steps = banner.querySelectorAll( '.onboarding-step' );
		const step2 = steps[ 1 ];

		if ( ! step2 ) {
			return;
		}

		const isCompleted = step2.classList.contains( 'onboarding-step--completed' );

		if ( responseData.step_2_completed && ! isCompleted ) {
			step2.classList.remove( 'onboarding-step--pending' );
			step2.classList.add( 'onboarding-step--completed' );

			const iconContainer = step2.querySelector( '.onboarding-step-icon-container' );

			if ( iconContainer ) {
				iconContainer.innerHTML =
					'<div class="onboarding-step-icon onboarding-step-icon--completed">' +
					'<span class="dashicons dashicons-yes"></span>' +
					'</div>';
			}

			const cta = step2.querySelector( '.onboarding-step-cta' );

			if ( cta ) {
				cta.remove();
			}
		} else if ( ! responseData.step_2_completed && isCompleted ) {
			step2.classList.remove( 'onboarding-step--completed' );
			step2.classList.add( 'onboarding-step--pending' );

			const iconContainer2 = step2.querySelector( '.onboarding-step-icon-container' );

			if ( iconContainer2 ) {
				iconContainer2.innerHTML =
					'<div class="onboarding-step-icon onboarding-step-icon--pending">2</div>';
			}
		}
	}

	// =====================================================================
	// Select All Toggle
	// =====================================================================

	/**
	 * Bind click handlers to Select All buttons.
	 */
	function bindSelectAllButtons() {

		const buttons = document.querySelectorAll( '.activelayer-select-all-forms' );

		buttons.forEach( function( button ) {
			button.addEventListener( 'click', function() {
				toggleSelectAll( button );
			} );
		} );
	}

	/**
	 * Toggle all form checkboxes within the same panel.
	 *
	 * @param {HTMLElement} button The Select All button.
	 */
	function toggleSelectAll( button ) {

		const form       = button.closest( '.activelayer-integration-settings-form' );
		const checkboxes = form ? form.querySelectorAll( 'input[name="forms[]"]' ) : [];
		let allChecked = true;

		checkboxes.forEach( function( cb ) {
			if ( ! cb.checked ) {
				allChecked = false;
			}
		} );

		// If all are checked, uncheck all; otherwise check all.
		const newState = ! allChecked;

		checkboxes.forEach( function( cb ) {
			cb.checked = newState;
		} );

		// Update button text.
		button.textContent = newState
			? ( config.strings.deselectAll || 'Deselect All' )
			: ( config.strings.selectAll || 'Select All' );

		// Toggle hidden class.
		if ( newState ) {
			button.classList.remove( 'hidden' );
		}
	}

	// =====================================================================
	// Boot
	// =====================================================================

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
