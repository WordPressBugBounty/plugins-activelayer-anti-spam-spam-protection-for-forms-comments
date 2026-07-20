<?php
/**
 * Registers ActiveLayer read-only abilities on the WordPress Abilities API.
 *
 * @package ActiveLayer
 */

namespace ActiveLayer\Abilities;

use ActiveLayer\Storage\Storage;
use ActiveLayer\Storage\Query\SubmissionQueryBuilder;
use ActiveLayer\Subscription\SubscriptionStats;
use ActiveLayer\Integrations\IntegrationRegistry;
use WP_Error;

/**
 * Read-only Abilities API integration.
 *
 * Exposes submissions, statistics, and safe settings to AI/MCP clients.
 * Requires WordPress 6.9+ (Abilities API). All abilities are read-only and
 * gated by the manage_activelayer capability.
 *
 * @since 1.6.0
 */
class Abilities {

	/**
	 * Ability namespace.
	 *
	 * @since 1.6.0
	 */
	private const ABILITY_NAMESPACE = 'activelayer';

	/**
	 * Ability category slug.
	 *
	 * @since 1.6.0
	 */
	private const CATEGORY = 'activelayer';

	/**
	 * Register WordPress hooks for category + ability registration.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function hooks(): void {

		/**
		 * Filters whether ActiveLayer registers its abilities at all.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $enabled Whether to register abilities. Default true.
		 */
		$enabled = apply_filters( 'activelayer_abilities_register', true );

		if ( ! $enabled ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the ActiveLayer ability category.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register_category(): void {

		// Abilities API ships with WordPress 6.9+ only.
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// Indirect call: keeps the WP 6.9-only function out of static minimum-version scans.
		call_user_func(
			'wp_register_ability_category',
			self::CATEGORY,
			[
				'label'       => __( 'ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description' => __( 'ActiveLayer anti-spam data: submissions, statistics, and settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			]
		);
	}

	/**
	 * Register all read-only abilities.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register_abilities(): void {

		// Abilities API ships with WordPress 6.9+ only.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_list_submissions();
		$this->register_search_submissions();
		$this->register_get_submission();
		$this->register_get_stats();
		$this->register_get_settings();
	}

	/**
	 * Permission callback shared by all abilities.
	 *
	 * @since 1.6.0
	 *
	 * @return true|WP_Error True when allowed, WP_Error otherwise.
	 */
	public function check_permission() {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return new WP_Error(
				'activelayer_forbidden',
				__( 'You are not allowed to access ActiveLayer data.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Execute list-submissions.
	 *
	 * @since 1.6.0
	 *
	 * @param array $input Validated input.
	 *
	 * @return array Submissions + total.
	 */
	public function execute_list_submissions( array $input ): array {

		return $this->query_submissions( $input, '' );
	}

	/**
	 * Execute search-submissions.
	 *
	 * @since 1.6.0
	 *
	 * @param array $input Validated input.
	 *
	 * @return array Submissions + total.
	 */
	public function execute_search_submissions( array $input ): array {

		$search = isset( $input['search'] ) ? (string) $input['search'] : '';

		return $this->query_submissions( $input, $search );
	}

	/**
	 * Execute get-submission.
	 *
	 * @since 1.6.0
	 *
	 * @param array $input Validated input.
	 *
	 * @return array|WP_Error Detail array or error.
	 */
	public function execute_get_submission( array $input ) {

		$id = isset( $input['id'] ) ? sanitize_text_field( (string) $input['id'] ) : '';

		if ( $id === '' ) {
			return new WP_Error(
				'activelayer_invalid_id',
				__( 'A submission id is required.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				[ 'status' => 400 ]
			);
		}

		$submission = Storage::get_instance()->find( $id );

		if ( $submission === null ) {
			return new WP_Error(
				'activelayer_submission_not_found',
				__( 'Submission not found.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				[ 'status' => 404 ]
			);
		}

		$include_fields = isset( $input['include_fields'] ) ? (bool) $input['include_fields'] : true;

		return ( new SubmissionFormatter() )->detail( $submission, $include_fields );
	}

	/**
	 * Execute get-stats.
	 *
	 * @since 1.6.0
	 *
	 * @param array $input Validated input.
	 *
	 * @return array Stats payload.
	 */
	public function execute_get_stats( array $input ): array {

		$days = isset( $input['days'] ) ? (int) $input['days'] : 7;
		$days = max( 1, min( 90, $days ) );

		$include_subscription = isset( $input['include_subscription'] ) ? (bool) $input['include_subscription'] : true;

		$subscription = null;

		if ( $include_subscription ) {
			$stats = SubscriptionStats::get_instance()->get_stats();

			if ( ! empty( $stats['success'] ) ) {
				$subscription = $stats;
			}
		}

		$storage = Storage::get_instance();

		return ( new StatsFormatter() )->build(
			$storage->get_queue_stats(),
			$storage->get_daily_counts( $days ),
			$subscription
		);
	}

	/**
	 * Execute get-settings.
	 *
	 * @since 1.6.0
	 *
	 * @param array $input Validated input (unused).
	 *
	 * @return array Settings payload.
	 */
	public function execute_get_settings( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		return ( new SettingsFormatter() )->build( IntegrationRegistry::get_instance()->get_status() );
	}

	/**
	 * Run a submissions query and format summaries.
	 *
	 * @since 1.6.0
	 *
	 * @param array  $input  Validated input (status, provider, limit, offset, exclude_trash).
	 * @param string $search Free-text search term.
	 *
	 * @return array Submissions + total.
	 */
	private function query_submissions( array $input, string $search ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$result = Storage::get_instance()->get_submissions(
			[
				'status'        => isset( $input['status'] ) ? (string) $input['status'] : '',
				'provider'      => isset( $input['provider'] ) ? (string) $input['provider'] : '',
				'search'        => $search,
				'limit'         => isset( $input['limit'] ) ? (int) $input['limit'] : 50,
				'offset'        => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
				'exclude_trash' => isset( $input['exclude_trash'] ) ? (bool) $input['exclude_trash'] : true,
			]
		);

		$formatter = new SubmissionFormatter();
		$items     = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : [];

		return [
			'submissions' => array_map( [ $formatter, 'summary' ], $items ),
			'total'       => (int) ( $result['total'] ?? 0 ),
		];
	}

	/**
	 * Shared submission filter properties for input schemas.
	 *
	 * @since 1.6.0
	 *
	 * @return array JSON-schema properties.
	 */
	private function submission_filter_properties(): array {

		$query_builder = new SubmissionQueryBuilder();

		return [
			'status'        => [
				'type'    => 'string',
				'enum'    => array_merge( [ '' ], $query_builder->get_allowed_statuses() ),
				'default' => '',
			],
			'provider'      => [
				'type'    => 'string',
				'enum'    => array_merge( [ '' ], $query_builder->get_allowed_providers() ),
				'default' => '',
			],
			'limit'         => [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 200,
				'default' => 50,
			],
			'offset'        => [
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
			'exclude_trash' => [
				'type'    => 'boolean',
				'default' => true,
			],
		];
	}

	/**
	 * Read-only meta annotations shared by all abilities.
	 *
	 * @since 1.6.0
	 *
	 * @return array Meta array.
	 */
	private function readonly_meta(): array {

		return [
			'show_in_rest' => true,
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'mcp'          => [ 'public' => true ],
		];
	}

	/**
	 * Register a single ability within the ActiveLayer namespace.
	 *
	 * Uses an indirect call so static analysers tied to the plugin's minimum
	 * WordPress version do not flag the WP 6.9-only function; availability is
	 * guaranteed by the function_exists() gate in register_abilities().
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug Ability slug within the ActiveLayer namespace.
	 * @param array  $args Ability registration arguments.
	 *
	 * @return void
	 */
	private function register_ability( string $slug, array $args ): void {

		call_user_func( 'wp_register_ability', self::ABILITY_NAMESPACE . '/' . $slug, $args );
	}

	/**
	 * Register the list-submissions ability.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function register_list_submissions(): void {

		$this->register_ability(
			'list-submissions',
			[
				'label'               => __( 'List submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description'         => __( 'List spam-check submissions filtered by status and provider.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => $this->submission_filter_properties(),
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'submissions' => [ 'type' => 'array' ],
						'total'       => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_submissions' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->readonly_meta(),
			]
		);
	}

	/**
	 * Register the search-submissions ability.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function register_search_submissions(): void {

		$properties           = $this->submission_filter_properties();
		$properties['search'] = [ 'type' => 'string' ];

		$this->register_ability(
			'search-submissions',
			[
				'label'               => __( 'Search submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description'         => __( 'Search submissions by text matched against the submitted form data.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => $properties,
					'required'   => [ 'search' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'submissions' => [ 'type' => 'array' ],
						'total'       => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_search_submissions' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->readonly_meta(),
			]
		);
	}

	/**
	 * Register the get-submission ability.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function register_get_submission(): void {

		$this->register_ability(
			'get-submission',
			[
				'label'               => __( 'Get submission', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description'         => __( 'Get detailed information about a single submission.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'id'             => [ 'type' => 'string' ],
						'include_fields' => [
							'type'    => 'boolean',
							'default' => true,
						],
					],
					'required'   => [ 'id' ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => [ $this, 'execute_get_submission' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->readonly_meta(),
			]
		);
	}

	/**
	 * Register the get-stats ability.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function register_get_stats(): void {

		$this->register_ability(
			'get-stats',
			[
				'label'               => __( 'Get statistics', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description'         => __( 'Get aggregated anti-spam statistics and subscription usage.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'days'                 => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 90,
							'default' => 7,
						],
						'include_subscription' => [
							'type'    => 'boolean',
							'default' => true,
						],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => [ $this, 'execute_get_stats' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->readonly_meta(),
			]
		);
	}

	/**
	 * Register the get-settings ability.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function register_get_settings(): void {

		$this->register_ability(
			'get-settings',
			[
				'label'               => __( 'Get settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description'         => __( 'Get safe (non-sensitive) plugin settings and the integration list.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => [ $this, 'execute_get_settings' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->readonly_meta(),
			]
		);
	}
}
