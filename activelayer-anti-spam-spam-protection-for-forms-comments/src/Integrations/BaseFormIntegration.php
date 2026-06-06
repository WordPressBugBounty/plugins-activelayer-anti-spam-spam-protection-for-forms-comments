<?php

namespace ActiveLayer\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Enrichers\BehavioralSignalsEnricher;
use ActiveLayer\ClientSignals\Enrichers\EnvironmentSignalsEnricher;
use ActiveLayer\ClientSignals\Enrichers\HoneypotEnricher;
use ActiveLayer\ClientSignals\Enrichers\SignalsStrippedDetector;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Integrations\Submission\FormContextBuilder;
use ActiveLayer\Integrations\Submission\SyncSubmissionProcessor;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Queue\QueueManager;
use ActiveLayer\Storage\Storage;
use Exception;

/**
 * Base Form Integration.
 *
 * Abstract base class for all form provider integrations.
 * Provides common functionality and enforces consistent interface.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations
 */
abstract class BaseFormIntegration {

	/**
	 * Integration name/identifier.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Slugified integration identifier for storage/keys.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Storage instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Integration settings.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Form context builder.
	 *
	 * @since 1.2.0
	 *
	 * @var FormContextBuilder
	 */
	protected $context_builder;

	/**
	 * Signal enrichers (environment, behavioral, honeypot, signals-stripped).
	 *
	 * @since 1.2.0
	 *
	 * @var array
	 */
	protected $signal_enrichers = [];

	/**
	 * Synchronous submission processor.
	 *
	 * @since 1.2.0
	 *
	 * @var SyncSubmissionProcessor
	 */
	protected $sync_processor;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added optional `$slug_override` parameter for explicit slug control.
	 *
	 * @param string      $name          Integration name.
	 * @param string|null $slug_override Explicit slug. When provided it is used as-is
	 *                                   instead of the auto-generated slug. Lets the
	 *                                   settings load against the correct option key
	 *                                   on first construct.
	 */
	public function __construct( string $name, ?string $slug_override = null ) {

		$this->name             = $name;
		$this->slug             = $slug_override ?? $this->generate_slug( $name );
		$this->storage          = Storage::get_instance();
		$this->settings         = $this->load_settings();
		$this->context_builder  = new FormContextBuilder();
		$this->signal_enrichers = [
			new EnvironmentSignalsEnricher(),
			new BehavioralSignalsEnricher(),
			new HoneypotEnricher(),
			new SignalsStrippedDetector(),
		];
		$this->sync_processor   = new SyncSubmissionProcessor( $this->storage );
	}

	/**
	 * Initialize integration.
	 * Called during plugin startup.
	 *
	 * @since 1.0.0
	 */
	abstract public function init(): void;

	/**
	 * Check if the form plugin is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if active, false otherwise.
	 */
	abstract public function is_active(): bool;

	/**
	 * Add provider/tracking metadata to $meta. Used by both sync and async flows.
	 *
	 * @since 1.2.0
	 *
	 * @param array $meta Incoming metadata from the integration.
	 *
	 * @return array Enriched metadata.
	 */
	private function enrich_meta( array $meta ): array {

		$meta['provider']      = $this->slug;
		$meta['provider_name'] = $this->name;
		$meta['tracking_mode'] = $this->resolve_tracking_mode_flag( $meta['tracking_mode'] ?? null );

		return $meta;
	}

	/**
	 * Normalize raw form data, build context, run signal enrichers.
	 *
	 * Shared between async (process_submission) and sync
	 * (process_submission_synchronously) flows.
	 *
	 * @since 1.2.0
	 *
	 * @param array $raw_data Raw provider form data.
	 * @param array $meta     Submission metadata (already enriched via enrich_meta).
	 *
	 * @return array Submission data ready for persistence.
	 */
	private function prepare_submission_data( array $raw_data, array $meta ): array {

		$normalized              = $this->normalize_form_data( $raw_data );
		$normalized['data_type'] = $this->get_data_type();
		$normalized              = $this->context_builder->build( $normalized, $meta );

		foreach ( $this->signal_enrichers as $enricher ) {
			$normalized = $enricher->enrich( $normalized, $this->slug );
		}

		return $normalized;
	}

	/**
	 * API `data_type` classifier for this integration.
	 *
	 * Sent in the normalized submission payload so the ActiveLayer API can
	 * apply type-specific priors and thresholds. Default is `form`; override
	 * in subclasses that protect a different surface.
	 *
	 * Recognised values: `form` | `comment` | `user_registration` | `review`.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_data_type(): string {

		return 'form';
	}

	/**
	 * Process form submission.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Added environment signals extraction.
	 * @since 1.2.0 Stripped-signals detection delegated to SignalsStrippedDetector enricher.
	 * @since 1.2.0 Preparation delegated to enrich_meta()/prepare_submission_data().
	 *
	 * @param array $form_data Normalized form data.
	 * @param array $meta      Form metadata (form_id, etc.). Passed by reference to expose queue status.
	 *
	 * @return string Submission ID.
	 * @throws Exception If storage creation fails.
	 */
	public function process_submission( array $form_data, array &$meta ): string {

		$meta = $this->enrich_meta( $meta );

		if ( UpgradeHelper::is_quota_exhausted_cached() ) {
			Logger::log( 'Quota exhausted - skipping submission', [ 'provider' => $this->slug ] );

			$meta['queue_failed'] = true;

			return '';
		}

		$normalized_data = $this->prepare_submission_data( $form_data, $meta );

		$submission_id = $this->storage->create_pending( $normalized_data, $meta );

		if ( ! $submission_id ) {
			throw new Exception( 'Failed to create submission in storage' );
		}

		$queued = QueueManager::queue( $submission_id );

		$meta['queue_failed'] = ! $queued;

		if ( ! $queued ) {
			Logger::log(
				'Queue unavailable - falling back to default behaviour',
				[
					'provider'      => $this->slug,
					'submission_id' => $submission_id,
				]
			);
		}

		return $submission_id;
	}

	/**
	 * Normalize form data to standard format.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_data Raw form data from provider.
	 *
	 * @return array Normalized data.
	 */
	abstract protected function normalize_form_data( array $raw_data ): array;


	/**
	 * Get form metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $form_instance Form instance or ID.
	 *
	 * @return array Form metadata.
	 */
	abstract protected function get_form_meta( $form_instance ): array;

	/**
	 * Run synchronous verification for raw submission data.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Added environment signals extraction.
	 * @since 1.2.0 Stripped-signals detection delegated to SignalsStrippedDetector enricher.
	 * @since 1.2.0 Synchronous flow delegated to SyncSubmissionProcessor.
	 *
	 * @param array $raw_data Raw provider data (before normalization).
	 * @param array $meta     Submission metadata (form_id, entry_id, etc.).
	 *
	 * @return array{
	 *     success:bool,
	 *     verdict?:string,
	 *     submission_id?:string,
	 *     tracking_mode?:bool,
	 *     error?:string
	 * }
	 */
	public function process_submission_synchronously( array $raw_data, array $meta ): array {

		$meta = $this->enrich_meta( $meta );

		if ( UpgradeHelper::is_quota_exhausted_cached() ) {
			Logger::log( 'Quota exhausted - skipping sync submission', [ 'provider' => $this->slug ] );

			return [
				'success' => false,
				'error'   => 'quota_exhausted',
			];
		}

		$normalized_data = $this->prepare_submission_data( $raw_data, $meta );

		return $this->sync_processor->process( $normalized_data, $meta, $this->slug );
	}


	/**
	 * Get integration name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Integration name.
	 */
	public function get_name(): string {

		return $this->name;
	}

	/**
	 * Get integration slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string Integration slug.
	 */
	public function get_slug(): string {

		return $this->slug;
	}

	/**
	 * Check if integration is enabled for runtime operation.
	 *
	 * Requires both a valid API key and the enabled setting to be true.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Behavior updated to require a configured API key.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && ( $this->settings['enabled'] ?? true );
	}

	/**
	 * Check if integration is enabled in settings.
	 *
	 * Returns the raw setting value without checking for API key.
	 * Use this for UI display purposes.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if enabled in settings, false otherwise.
	 */
	public function is_setting_enabled(): bool {

		return $this->settings['enabled'] ?? true;
	}

	/**
	 * Get integration settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array.
	 */
	public function get_settings(): array {

		return $this->settings;
	}

	/**
	 * Reload settings from database.
	 *
	 * @since 1.0.0
	 */
	public function reload_settings(): void {

		$this->settings = $this->load_settings();
	}

	/**
	 * Get storage instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Storage Storage instance.
	 */
	public function get_storage(): Storage {

		return $this->storage;
	}

	/**
	 * Load integration settings from WordPress options.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array.
	 */
	protected function load_settings(): array {

		$slug_option_name = $this->get_option_key();
		$defaults         = $this->get_default_settings();
		$saved_settings   = get_option( $slug_option_name, [] );

		return wp_parse_args( $saved_settings, $defaults );
	}

	/**
	 * Get default settings for integration.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default settings.
	 */
	protected function get_default_settings(): array {

		return [
			'enabled' => true,
		];
	}

	/**
	 * Get option key for the integration settings.
	 *
	 * @since 1.0.0
	 *
	 * @return string Option key name.
	 */
	public function get_option_key(): string {

		return "activelayer_{$this->slug}_settings";
	}

	/**
	 * Build a slug from the integration name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Raw integration name.
	 *
	 * @return string Normalized slug.
	 */
	protected function generate_slug( string $name ): string {

		$slug = strtolower( trim( $name ) );
		$slug = preg_replace( '/[^a-z0-9\\s_]/', '', $slug );
		$slug = preg_replace( '/[\\s]+/', '_', $slug );
		$slug = preg_replace( '/_+/', '_', $slug );
		$slug = trim( $slug ?? '', '_' );

		return $slug !== '' ? $slug : 'integration';
	}

	/**
	 * Get integration status info.
	 *
	 * @since 1.0.0
	 *
	 * @return array Status information.
	 */
	public function get_status(): array {

		return [
			'name'     => $this->name,
			'slug'     => $this->slug,
			'active'   => $this->is_active(),
			'enabled'  => $this->is_enabled(),
			'settings' => $this->settings,
		];
	}

	/**
	 * Message shown to users when synchronous verification blocks the submission.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_sync_block_message(): string {

		return esc_html__( 'Your submission was flagged as spam. Please try again or contact support.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}

	/**
	 * Check whether synchronous mode is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	protected function is_sync_mode(): bool {

		return SettingsHelper::is_sync_mode_enabled();
	}

	/**
	 * Expose synchronous mode status.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_sync_mode_enabled(): bool {

		return $this->is_sync_mode();
	}

	/**
	 * Handle API verdict result.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $verdict       API verdict (clean/spam).
	 * @param array  $context       Additional submission context.
	 *
	 * @return bool True if action was taken.
	 */
	public function handle_verdict( string $submission_id, string $verdict, array $context = [] ): bool {

		$tracking_mode = ! empty( $context['tracking_mode'] );

		// In tracking mode, always allow submissions regardless of verdict.
		// Emails were already sent immediately, no need to call allow_submission().
		if ( $tracking_mode ) {
			return true;
		}

		// Normal mode: for clean submissions, allow emails to be sent.
		if ( $verdict === 'clean' ) {
			return $this->allow_submission( $submission_id );
		}

		// Normal mode: for spam submissions, keep them blocked.
		return $this->block_submission( $submission_id );
	}

	/**
	 * Allow clean submission - send emails.
	 * Override in child classes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	protected function allow_submission( string $submission_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		// Override in child classes.
		return true;
	}

	/**
	 * Block spam submission.
	 * Override in child classes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	protected function block_submission( string $submission_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		// Override in child classes.
		return true;
	}

	/**
	 * Resolve tracking mode flag value.
	 *
	 * @since 1.0.0
	 *
	 * @param bool|null $flag Flag supplied by the integration.
	 *
	 * @return bool
	 */
	protected function resolve_tracking_mode_flag( $flag ): bool {

		return (bool) $flag;
	}
}
