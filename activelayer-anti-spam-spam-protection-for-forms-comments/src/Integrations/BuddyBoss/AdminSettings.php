<?php

namespace ActiveLayer\Integrations\BuddyBoss;

use ActiveLayer\Integrations\BuddySignup\AbstractBuddyAdminSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyBoss Platform signup admin settings.
 *
 * Stores configuration under option key `activelayer_buddyboss_settings`.
 * All persistence + status logic lives in `AbstractBuddyAdminSettings`; this
 * subclass exists only to surface the BuddyBoss-specific description string.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Integrations\BuddyBoss
 */
class AdminSettings extends AbstractBuddyAdminSettings {

	/**
	 * Human-readable description shown on the Integrations admin page.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	protected function get_description(): string {

		return esc_html__( 'Block spam registrations on the BuddyBoss Platform signup form.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}
}
