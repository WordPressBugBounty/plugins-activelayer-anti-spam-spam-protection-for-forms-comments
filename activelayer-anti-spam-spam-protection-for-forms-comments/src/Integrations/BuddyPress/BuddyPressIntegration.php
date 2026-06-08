<?php

namespace ActiveLayer\Integrations\BuddyPress;

use ActiveLayer\Integrations\BuddySignup\AbstractBuddySignupIntegration;
use ActiveLayer\Integrations\BuddySignup\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyPress (free) signup spam protection.
 *
 * Active only when the free BuddyPress plugin is installed without the
 * BuddyBoss Platform fork. BuddyBoss is covered by the sibling
 * BuddyBossIntegration so the `bp_signup_validate` hook is bound by exactly
 * one integration on a given site, preventing double-API-calls / duplicate
 * submission rows.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Integrations\BuddyPress
 */
class BuddyPressIntegration extends AbstractBuddySignupIntegration {

	/**
	 * Admin settings.
	 *
	 * @since 1.3.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {

		parent::__construct( 'BuddyPress', 'buddypress' );

		$this->admin_settings     = new AdminSettings( $this );
		$this->submission_handler = new SubmissionHandler( $this );
	}

	/**
	 * BuddyPress (free) plugin presence.
	 *
	 * Mutually exclusive with BuddyBossIntegration::is_active(): when any
	 * BuddyBoss Platform marker is defined, BuddyBoss owns the signup hook
	 * and this integration stays dormant. BuddyBoss Platform currently
	 * defines only `BP_PLATFORM_VERSION` (see `bp-loader.php`); the extra
	 * `BP_PLATFORM_PATH` exclusion is defense-in-depth so the mutual-exclusivity
	 * invariant survives a future BB release that switches the marker constant
	 * (e.g. for symlinked installs). Do not remove without first verifying
	 * which constants the targeted BB version still defines.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return defined( 'BP_VERSION' )
			&& ! defined( 'BP_PLATFORM_VERSION' )
			&& ! defined( 'BP_PLATFORM_PATH' )
			&& function_exists( 'buddypress' );
	}

	/**
	 * Admin settings accessor.
	 *
	 * @since 1.3.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}
}
