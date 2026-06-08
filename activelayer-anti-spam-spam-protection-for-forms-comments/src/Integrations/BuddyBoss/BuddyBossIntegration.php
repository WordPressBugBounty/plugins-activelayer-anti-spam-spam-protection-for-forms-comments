<?php

namespace ActiveLayer\Integrations\BuddyBoss;

use ActiveLayer\Integrations\BuddySignup\AbstractBuddySignupIntegration;
use ActiveLayer\Integrations\BuddySignup\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyBoss Platform signup spam protection.
 *
 * Active only when the BuddyBoss Platform plugin is installed (detected via
 * the `BP_PLATFORM_VERSION` constant the plugin defines). Mutually exclusive
 * with BuddyPressIntegration so the shared `bp_signup_validate` hook is bound
 * by exactly one integration on a given site.
 *
 * Reuses the shared SubmissionHandler — the BuddyBoss form-shape difference
 * (no `signup_username`, `field_1` xprofile fallback, error key flips to
 * `signup_email`) is detected at runtime inside the handler, not via subclass
 * branching.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Integrations\BuddyBoss
 */
class BuddyBossIntegration extends AbstractBuddySignupIntegration {

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

		parent::__construct( 'BuddyBoss', 'buddyboss' );

		$this->admin_settings     = new AdminSettings( $this );
		$this->submission_handler = new SubmissionHandler( $this );
	}

	/**
	 * BuddyBoss Platform plugin presence.
	 *
	 * BuddyBoss Platform currently defines `BP_PLATFORM_VERSION` (see
	 * `bp-loader.php`); the legacy `BP_VERSION` constant is also defined for
	 * back-compat with BP themes, so it cannot be used to distinguish the
	 * two flavours on its own. We additionally accept `BP_PLATFORM_PATH` —
	 * not present in today's BB releases, but reserved as a forward-compat
	 * marker so a future BB version that switches the constant does not
	 * silently leave the integration dormant. The sibling
	 * BuddyPressIntegration excludes BOTH markers to preserve mutual
	 * exclusivity.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return ( defined( 'BP_PLATFORM_VERSION' ) || defined( 'BP_PLATFORM_PATH' ) )
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
