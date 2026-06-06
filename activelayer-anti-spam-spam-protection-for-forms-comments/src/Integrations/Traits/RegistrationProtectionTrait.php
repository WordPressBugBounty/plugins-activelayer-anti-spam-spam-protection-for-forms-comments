<?php

namespace ActiveLayer\Integrations\Traits;

use ActiveLayer\Helpers\RequestHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable spam-check for registration flows.
 *
 * Hosts the shared logic used by registration adapters (WooCommerce,
 * Native WP, BuddyPress, bbPress). Consumers extend BaseFormIntegration
 * and `use` this trait. The trait builds a normalized raw payload from
 * `$email` / `$login` and delegates to the inherited
 * `process_submission_synchronously()` for the actual API call.
 *
 * Fail-safe: any verdict other than `spam` (including `apifail`,
 * `quota_exhausted`, `clean`) leaves the caller's error bag untouched —
 * registration proceeds. Spam verdicts always block — the global
 * tracking-mode flag does not apply to the registration gate.
 *
 * Required host surface (typically inherited from BaseFormIntegration):
 *   - get_slug(): string                  Provider slug used in log lines.
 *   - process_submission_synchronously( array $raw_data, array $meta ): array
 *       Must return: [ 'success' => bool, 'verdict' => string,
 *                      'submission_id' => string, 'error' => string ]
 *       (`error` only on failure).
 *
 * The host's normalize_form_data() is expected to be a pass-through for the
 * `email`/`name`/`ip`/`user_agent` keys the trait builds — see
 * RegistrationIntegration::normalize_form_data() for the reference shape.
 *
 * @since 1.2.0
 */
trait RegistrationProtectionTrait {

	/**
	 * Run the synchronous registration spam check.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $email   Sanitized email address.
	 * @param string   $login   Sanitized login or display name.
	 * @param array    $meta    Caller-supplied submission metadata (e.g. form_id).
	 * @param callable $on_spam Invoked with the user-facing block message when the
	 *                          verdict is spam. The adapter is responsible for
	 *                          translating that into a provider-specific block
	 *                          (e.g. `$errors->add( 'activelayer_spam', $msg )`).
	 *
	 * @return array{
	 *     verdict:string,
	 *     submission_id?:string,
	 *     error?:string
	 * } Verdict is 'clean' | 'spam' | 'error' (host-emitted) or any value the
	 *   API returns via process_submission_synchronously (typically 'clean' or
	 *   'spam'). The trait never emits 'skipped'.
	 */
	public function check_registration_spam( string $email, string $login, array $meta, callable $on_spam ): array {

		$raw_data = [
			'email'      => $email,
			'name'       => $login,
			'ip'         => RequestHelper::get_user_ip(),
			'user_agent' => RequestHelper::get_user_agent(),
		];

		$result = $this->process_submission_synchronously( $raw_data, $meta );

		if ( empty( $result['success'] ) ) {
			// API/queue/quota/storage failure — fail safe.
			return [
				'verdict' => 'error',
				'error'   => $result['error'] ?? 'unknown',
			];
		}

		$verdict = $result['verdict'] ?? 'clean';

		// Registration always blocks on spam — the global tracking-mode flag
		// deliberately does not apply to account creation. Tracking mode is
		// intended for form submissions (where blocking destroys user data);
		// allowing a known spammer to register would create real accounts.
		if ( $verdict === 'spam' ) {
			$on_spam( $this->get_registration_block_message() );
		}

		return [
			'verdict'       => $verdict,
			'submission_id' => $result['submission_id'] ?? '',
		];
	}

	/**
	 * Default block message presented to the user when registration is rejected.
	 *
	 * Adapters may override this method to customise the message.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	protected function get_registration_block_message(): string {

		return esc_html__( 'Registration blocked: your submission was flagged as spam.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}
}
