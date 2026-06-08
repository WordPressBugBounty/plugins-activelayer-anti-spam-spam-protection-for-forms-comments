<?php

namespace ActiveLayer\Integrations\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Api\ApiClient;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;
use ActiveLayer\Subscription\SubscriptionStats;
use Exception;

/**
 * Processes submissions synchronously: persist - API - verdict - log.
 *
 * Owns the synchronous-flow tail. Receives already-prepared (normalized
 * + context-enriched + signal-enriched) data and metadata. Knows nothing
 * about BaseFormIntegration.
 *
 * @since 1.2.0
 */
class SyncSubmissionProcessor {

	/**
	 * Submissions storage.
	 *
	 * @since 1.2.0
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * API client (lazily instantiated when null).
	 *
	 * @since 1.2.0
	 *
	 * @var ApiClient|null
	 */
	private $api_client;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Typed $api_client as explicitly nullable for PHP 8.4 compatibility.
	 *
	 * @param Storage        $storage    Submissions storage.
	 * @param ApiClient|null $api_client Optional API client (test seam).
	 */
	public function __construct( Storage $storage, ?ApiClient $api_client = null ) {

		$this->storage    = $storage;
		$this->api_client = $api_client;
	}

	/**
	 * Run the synchronous submission flow.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $normalized_data Prepared submission payload.
	 * @param array  $meta            Submission metadata (must include tracking_mode).
	 * @param string $provider_slug   Integration slug.
	 *
	 * @return array{
	 *     success:bool,
	 *     verdict?:string,
	 *     submission_id?:string,
	 *     tracking_mode?:bool,
	 *     error?:string
	 * }
	 */
	public function process( array $normalized_data, array $meta, string $provider_slug ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		try {
			$submission_id = $this->storage->create_pending( $normalized_data, $meta );
		} catch ( Exception $e ) {
			Logger::log(
				'Sync mode: failed to persist submission',
				[
					'provider' => $provider_slug,
					'error'    => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'error'   => 'storage_error',
			];
		}

		$client     = $this->api_client ?? new ApiClient();
		$api_result = $client->check_submission( $normalized_data );

		if ( empty( $api_result['success'] ) ) {
			$this->storage->update_status(
				$submission_id,
				'failed',
				[
					'verdict'      => 'failed',
					'api_response' => [ 'error' => $api_result['error'] ?? 'unknown' ],
				]
			);

			Logger::log(
				'Sync mode: API error, allowing submission',
				[
					'provider' => $provider_slug,
					'error'    => $api_result['error'] ?? 'unknown',
				]
			);

			return [
				'success'       => false,
				'error'         => 'api_error',
				'submission_id' => $submission_id,
				'tracking_mode' => $meta['tracking_mode'],
			];
		}

		$verdict = $api_result['verdict'] ?? 'clean';

		$this->storage->update_status(
			$submission_id,
			$verdict,
			[
				'api_response' => $api_result['raw_response'] ?? $api_result,
				'processed_at' => current_time( 'mysql' ),
				'verdict'      => $verdict,
			]
		);

		Logger::log(
			'Sync mode verdict',
			[
				'provider'      => $provider_slug,
				'submission_id' => $submission_id,
				'form_id'       => $meta['form_id'] ?? null,
				'verdict'       => $verdict,
				'tracking_mode' => $meta['tracking_mode'],
			]
		);

		SubscriptionStats::get_instance()->schedule_refresh();

		return [
			'success'       => true,
			'verdict'       => $verdict,
			'submission_id' => $submission_id,
			'tracking_mode' => $meta['tracking_mode'],
		];
	}
}
