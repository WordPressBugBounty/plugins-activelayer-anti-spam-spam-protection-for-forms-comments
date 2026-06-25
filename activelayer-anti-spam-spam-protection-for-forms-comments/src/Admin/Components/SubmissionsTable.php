<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\DetectionIdResolver;
use ActiveLayer\Helpers\FormEditUrlResolver;
use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Storage\Storage;
use WP_List_Table;

/**
 * Submissions List Table.
 *
 * WP_List_Table implementation for displaying anti-spam submissions.
 *
 * @since 1.0.0
 * @since 1.2.0 Moved to Components namespace.
 *
 * @package ActiveLayer\Admin
 */
class SubmissionsTable extends WP_List_Table {

	/**
	 * Storage instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Whether the database has any submissions at all (unfiltered).
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private $has_any_submissions = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->storage = Storage::get_instance();

		parent::__construct(
            [
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
            ]
        );
	}

	/**
	 * Get table columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array Columns array.
	 */
	public function get_columns(): array {

		return [
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'ID', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'email'        => __( 'Email', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'provider'     => __( 'Provider', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'status'       => __( 'Status', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'processed_at' => __( 'Processed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
		];
	}

	/**
	 * Get sortable columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array Sortable columns.
	 */
	protected function get_sortable_columns(): array {

		return [];
	}

	/**
	 * Get bulk actions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Bulk actions.
	 */
	protected function get_bulk_actions(): array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( $current_status === 'trash' ) {
			return [
				'restore'            => __( 'Restore', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'delete_permanently' => __( 'Delete Permanently', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];
		}

		$actions = [];

		if ( $current_status === 'failed' ) {
			$actions['recheck'] = __( 'Recheck with API', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		if ( $current_status === 'spam' ) {
			$actions['mark_clean'] = __( 'Mark as Clean', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		if ( $current_status === 'clean' ) {
			$actions['mark_spam'] = __( 'Mark as Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		$actions['trash'] = __( 'Move to Trash', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

		return $actions;
	}

	/**
	 * Get views (status filters).
	 *
	 * @since 1.0.0
	 *
	 * @return array Views array.
	 */
	protected function get_views(): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$current_status        = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$allowed_view_statuses = [ 'all', 'pending', 'clean', 'spam', 'failed', 'trash' ];

		if ( ! in_array( $current_status, $allowed_view_statuses, true ) ) {
			$current_status = 'all';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$current_provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';

		$base_url = admin_url( 'admin.php?page=activelayer-submissions' );

		// Preserve provider filter in status links.
		if ( $current_provider !== '' ) {
			$base_url = add_query_arg( 'provider', $current_provider, $base_url );
		}

		$stats = $this->storage->get_queue_stats();

		$total_count = ( $stats['total'] ?? 0 ) - ( $stats['trash'] ?? 0 );

		return [
			'all'     => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $base_url ),
				esc_attr( $current_status === 'all' ? 'current' : '' ),
				esc_html__( 'All', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				absint( $total_count )
			),
			'pending' => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', 'pending', $base_url ) ),
				esc_attr( $current_status === 'pending' ? 'current' : '' ),
				esc_html__( 'Pending', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				absint( $stats['pending'] ?? 0 )
			),
			'clean'   => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', 'clean', $base_url ) ),
				esc_attr( $current_status === 'clean' ? 'current' : '' ),
				esc_html__( 'Clean', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				absint( $stats['clean'] ?? 0 )
			),
			'spam'    => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', 'spam', $base_url ) ),
				esc_attr( $current_status === 'spam' ? 'current' : '' ),
				esc_html__( 'Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				absint( $stats['spam'] ?? 0 )
			),
			'failed'  => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', 'failed', $base_url ) ),
				esc_attr( $current_status === 'failed' ? 'current' : '' ),
				esc_html__( 'Failed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				absint( $stats['failed'] ?? 0 )
			),
			'trash'   => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', 'trash', $base_url ) ),
				esc_attr( $current_status === 'trash' ? 'current' : '' ),
				esc_html__( 'Trash', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				absint( $stats['trash'] ?? 0 )
			),
		];
	}

	/**
	 * Prepare table items.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Added search term support for email lookups.
	 */
	public function prepare_items(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Get parameters.
		$per_page     = min( $this->get_items_per_page( 'submissions_per_page' ), 200 );
		$current_page = $this->get_pagenum();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display/filtering only, no action taken
		$status_raw       = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$allowed_statuses = [ 'pending', 'clean', 'spam', 'failed', 'trash' ];
		$status           = in_array( $status_raw, $allowed_statuses, true ) ? $status_raw : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display/filtering only, no action taken
		$provider_raw      = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';
		$allowed_providers = $this->storage->get_distinct_providers();
		$provider          = in_array( $provider_raw, $allowed_providers, true ) ? $provider_raw : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display/filtering only, no action taken
		$search_term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// Build query args.
		$args = [
			'limit'         => $per_page,
			'offset'        => ( $current_page - 1 ) * $per_page,
			'orderby'       => 'created_at',
			'order'         => 'DESC',
			'exclude_trash' => $status === '' || $status === 'all',
		];

		if ( $status && $status !== 'all' ) {
			$args['status'] = $status;
		}

		if ( $provider ) {
			$args['provider'] = $provider;
		}

		if ( $search_term !== '' ) {
			$args['search'] = $search_term;
		}

		// Get submissions.
		$results     = $this->storage->get_submissions( $args );
		$this->items = $results['items'] ?? [];
		$total_items = $results['total'] ?? 0;

		// Set pagination.
		$this->set_pagination_args(
            [
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
            ]
        );

		// Check if the database has any submissions at all (for empty state).
		$stats                     = $this->storage->get_queue_stats();
		$this->has_any_submissions = ( $stats['total'] ?? 0 ) > 0;

		// Set column headers.
		$this->_column_headers = [
			$this->get_columns(),
			[],
			[],
		];
	}

	/**
	 * Display the empty state message when there are no items.
	 *
	 * Shows an onboarding empty state when the database has no submissions at all,
	 * and a simple message when a filter or search returns no results.
	 *
	 * @since 1.1.0
	 */
	public function no_items(): void {

		?>
		<div class="activelayer-empty-state">
			<div class="activelayer-empty-state-icon">
				<span class="dashicons dashicons-search"></span>
			</div>
			<?php if ( $this->has_any_submissions ) : ?>
				<h2><?php esc_html_e( 'No Submissions Found', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
				<p>
					<?php esc_html_e( 'No submissions match your current filters or search.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</p>
			<?php else : ?>
				<h2><?php esc_html_e( 'No Submissions Yet', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
				<p>
					<?php esc_html_e( 'Submissions will appear here once you start receiving entries.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					<br>
					<?php esc_html_e( 'Make sure ActiveLayer protection is enabled on your forms settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</p>
				<?php
				$setup_guide_url = 'https://activelayer.com/docs/wordpress-plugin/?utm_source=plugin&utm_medium=submissions-empty-state&utm_campaign=setup-guide';
				?>
				<a href="<?php echo esc_url( $setup_guide_url ); ?>" class="activelayer-empty-state-link" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View Setup Guide', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Extra controls to be displayed between bulk actions and pagination.
	 *
	 * @since 1.0.0
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( $which !== 'top' ) {
			return;
		}

		$providers = $this->storage->get_distinct_providers();

		if ( empty( $providers ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display/filtering only, no action taken
		$current_provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display/filtering only, no action taken
		$current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		?>
		<div class="alignleft actions">
			<?php if ( $current_status ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>" />
			<?php endif; ?>
			<label for="filter-by-provider" class="screen-reader-text">
				<?php esc_html_e( 'Filter by provider', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</label>
			<select name="provider" id="filter-by-provider">
				<option value=""><?php esc_html_e( 'All Providers', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></option>
				<?php foreach ( $providers as $provider ) : ?>
					<option value="<?php echo esc_attr( $provider ); ?>" <?php selected( $current_provider, $provider ); ?>>
						<?php echo esc_html( IntegrationRegistry::get_provider_display_name( $provider ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Checkbox column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Item data.
	 *
	 * @return string Checkbox HTML.
	 */
	protected function column_cb( $item ): string {

		return sprintf(
			'<input type="checkbox" name="submission[]" value="%s" />',
			esc_attr( (string) absint( $item['id'] ) )
		);
	}

	/**
	 * ID column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Item data.
	 *
	 * @return string Column HTML.
	 */
	protected function column_id( array $item ): string {

		return esc_html( (string) absint( $item['id'] ) );
	}


	/**
	 * Provider column.
	 *
	 * Shows the provider display name with the form name (linked to the form editor)
	 * or falls back to Form/Post ID when the name is unavailable.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Shows form name with edit link instead of raw ID.
	 *
	 * @param array $item Item data.
	 *
	 * @return string Column HTML.
	 */
	protected function column_provider( array $item ): string {

		$provider_slug = isset( $item['provider'] ) ? (string) $item['provider'] : '';
		$provider      = esc_html( IntegrationRegistry::get_provider_display_name( $provider_slug ) );

		if ( ! isset( $item['form_id'] ) ) {
			return wp_kses_post( $provider );
		}

		$form_id   = (string) $item['form_id'];
		$form_data = $item['form_data'] ?? [];
		$edit_url  = $this->get_form_edit_url( $provider_slug, $form_id, $item );

		list( $display, $label ) = $this->resolve_form_display( $provider_slug, $form_id, $form_data );

		$display_html = $edit_url !== ''
			? '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $display ) . '</a>'
			: esc_html( $display );

		$provider .= '<br><small>' . sprintf( $label, $display_html ) . '</small>';

		return wp_kses_post( $provider );
	}

	/**
	 * Resolve the display name and label for a form/post reference.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Added WooCommerce Reviews (`wc_reviews`) provider branch.
	 * @since 1.2.0 Added WooCommerce Registration (`wc_registration`) provider branch.
	 * @since 1.3.0 Added BuddyPress (`buddypress`) provider branch.
	 * @since 1.3.0 Added BuddyBoss (`buddyboss`) provider branch — shares the BuddyPress display logic.
	 * @since 1.4.0 Added AffiliateWP (`affiliatewp`) provider branch.
	 * @since 1.4.0 Added memberpress to the registration display branch.
	 * @since 1.5.0 Added EDD Reviews (`edd_reviews`, download title) branch and edd_registration to the registration display branch.
	 *
	 * @param string $provider_slug Provider identifier.
	 * @param string $form_id       Form identifier.
	 * @param array  $form_data     Decoded form data.
	 *
	 * @return array{ 0: string, 1: string } Display name and translated label with %s placeholder.
	 */
	private function resolve_form_display( string $provider_slug, string $form_id, array $form_data ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity

		$form_name = $form_data['context']['form_name'] ?? '';

		if ( $form_name !== '' ) {
			/* translators: %s: form name. */
			return [ $form_name, esc_html__( 'Form name: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
		}

		if ( $provider_slug === 'wp_comments' ) {
			$post_title = get_the_title( (int) $form_id );

			/* translators: %s: post name. */
			$display = $post_title !== '' ? $post_title : __( 'Unknown Post', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

			/* translators: %s: post name or identifier. */
			return [ $display, esc_html__( 'Post Name: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
		}

		if ( $provider_slug === 'wc_reviews' ) {
			$product_title = get_the_title( (int) $form_id );

			$display = $product_title !== '' ? $product_title : __( 'Unknown Product', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

			/* translators: %s: product name. */
			return [ $display, esc_html__( 'Product: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
		}

		if ( $provider_slug === 'edd_reviews' ) {
			$download_title = get_the_title( (int) $form_id );

			$display = $download_title !== '' ? $download_title : __( 'Unknown Download', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

			/* translators: %s: download name. */
			return [ $display, esc_html__( 'Download: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
		}

		if ( $provider_slug === 'wc_registration' || $provider_slug === 'buddypress' || $provider_slug === 'buddyboss' || $provider_slug === 'affiliatewp' || $provider_slug === 'memberpress' || $provider_slug === 'edd_registration' ) {
			$email = $form_data['email'] ?? '';
			$login = $form_data['name'] ?? '';

			$display = $login !== '' ? $login : ( $email !== '' ? $email : __( 'Unknown Registration', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );

			if ( $provider_slug === 'wc_registration' || $provider_slug === 'edd_registration' ) {
				/* translators: %s: customer login or email. */
				return [ $display, esc_html__( 'Customer: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
			}

			if ( $provider_slug === 'affiliatewp' ) {
				/* translators: %s: affiliate login or email. */
				return [ $display, esc_html__( 'Affiliate: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
			}

			/* translators: %s: community member login or email. */
			return [ $display, esc_html__( 'Member: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
		}

		if ( $provider_slug === 'contact_form_7' ) {
			$cf7_title = get_the_title( (int) $form_id );

			/* translators: %s: form name. */
			$display = $cf7_title !== '' ? $cf7_title : __( 'Unknown Form', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

			/* translators: %s: form name or identifier. */
			return [ $display, esc_html__( 'Form name: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
		}

		/* translators: %s: form name. */
		return [ __( 'Unknown Form', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), esc_html__( 'Form name: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ];
	}

	/**
	 * Get the admin edit URL for a form based on the provider.
	 *
	 * Uses IntegrationRegistry to dynamically resolve edit URL templates,
	 * with special handling for Comments (link to comment) and Elementor
	 * (requires page_id from context).
	 *
	 * @since 1.1.0
	 * @since 1.1.0 Dynamic lookup via FormAdminSettingsInterface.
	 *
	 * @param string $provider_slug Provider identifier.
	 * @param string $form_id       Form identifier.
	 * @param array  $item          Full submission row data.
	 *
	 * @return string Edit URL or empty string if unavailable.
	 */
	private function get_form_edit_url( string $provider_slug, string $form_id, array $item = [] ): string {

		return FormEditUrlResolver::resolve( $provider_slug, $form_id, $item );
	}

	/**
	 * Email column with row actions.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Item data.
	 *
	 * @return string Column HTML.
	 */
	protected function column_email( array $item ): string {

		$form_data = $item['form_data'] ?? [];
		$email     = ! empty( $form_data['email'] ) ? $form_data['email'] : __( 'N/A', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		$base      = $this->get_row_actions_base_url();
		$actions   = $this->build_row_actions( $item, $base );

		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( $email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Build row actions for a submission entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $item Submission data.
	 * @param string $base Base URL with preserved filters.
	 *
	 * @return array List of action links.
	 */
	private function build_row_actions( array $item, string $base ): array {

		$actions = [];
		$item_id = absint( $item['id'] );

		if ( $item['status'] === 'trash' ) {
			$actions['restore'] = $this->build_row_action_link(
				'restore',
				$item_id,
				$base,
				esc_html__( 'Restore', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);

			$actions['delete_permanently'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							[
								'action' => 'delete_permanently',
								'id'     => $item_id,
							],
							$base
						),
						'delete_permanently_' . $item_id
					)
				),
				esc_js( __( 'Delete this submission permanently?', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ),
				esc_html__( 'Delete Permanently', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);

			return $actions;
		}

		$actions['view'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				add_query_arg(
					[
						'action' => 'view',
						'id'     => $item_id,
					],
					$base
				)
			),
			esc_html__( 'View', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
		);

		if ( $item['status'] === 'failed' ) {
			$actions['recheck'] = $this->build_row_action_link(
				'recheck',
				$item_id,
				$base,
				esc_html__( 'Recheck', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);
		}

		if ( $item['status'] === 'spam' ) {
			$actions['mark_clean'] = $this->build_row_action_link(
				'mark_clean',
				$item_id,
				$base,
				esc_html__( 'Mark Clean', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);
		}

		if ( $item['status'] === 'clean' ) {
			$actions['mark_spam'] = $this->build_row_action_link(
				'mark_spam',
				$item_id,
				$base,
				esc_html__( 'Mark Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);
		}

		$actions['trash'] = sprintf(
			'<a href="%s" class="trash" onclick="return confirm(\'%s\')">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						[
							'action' => 'trash',
							'id'     => $item_id,
						],
						$base
					),
					'trash_' . $item_id
				)
			),
			esc_js( __( 'Move this submission to the trash?', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ),
			esc_html__( 'Trash', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
		);

		$detection_id = $this->get_detection_id( $item );

		if ( $detection_id !== '' ) {
			$actions['copy_api_id'] = sprintf(
				'<a href="#" class="activelayer-copy-api-id" data-api-id="%s">%s</a>',
				esc_attr( $detection_id ),
				esc_html__( 'Copy API ID', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);
		}

		return $actions;
	}

	/**
	 * Build a single row action link.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action  Action slug.
	 * @param int    $item_id Submission ID.
	 * @param string $base    Base URL for actions.
	 * @param string $label   Action label.
	 *
	 * @return string Prepared HTML anchor tag.
	 */
	private function build_row_action_link( string $action, int $item_id, string $base, string $label ): string {

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action' => $action,
					'id'     => $item_id,
				],
				$base
			),
			$action . '_' . $item_id
		);

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			$label
		);
	}

	/**
	 * Get base admin URL for row actions with filters preserved.
	 *
	 * @since 1.0.0
	 *
	 * @return string Base URL.
	 */
	private function get_row_actions_base_url(): string {

		$base_args = [ 'page' => 'activelayer-submissions' ];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( $current_status !== '' ) {
			$base_args['status'] = $current_status;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$current_provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';

		if ( $current_provider !== '' ) {
			$base_args['provider'] = $current_provider;
		}

		return add_query_arg( $base_args, admin_url( 'admin.php' ) );
	}

	/**
	 * Extract the API detection ID from a submission's API response.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item Submission data.
	 *
	 * @return string Detection ID or empty string if unavailable.
	 */
	private function get_detection_id( array $item ): string {

		return DetectionIdResolver::resolve( $item['api_response'] ?? null );
	}

	/**
	 * Status column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Item data.
	 *
	 * @return string Column HTML.
	 */
	protected function column_status( array $item ): string {

		$status_classes = [
			'pending' => 'status-pending',
			'clean'   => 'status-clean',
			'spam'    => 'status-spam',
			'failed'  => 'status-failed',
			'trash'   => 'status-trash',
		];

		$class = $status_classes[ $item['status'] ] ?? '';
		$label = ucfirst( (string) $item['status'] );

		return sprintf(
			'<span class="status-badge %1$s">%2$s</span>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/**
	 * Processed date column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Item data.
	 *
	 * @return string Column HTML.
	 */
	protected function column_processed_at( array $item ): string {

		if ( empty( $item['processed_at'] ) ) {
			return '<span class="not-processed">' . esc_html__( 'Not processed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</span>';
		}

		$timestamp = is_numeric( $item['processed_at'] ) ? $item['processed_at'] : strtotime( $item['processed_at'] );

		if ( ! $timestamp ) {
			return '<span class="not-processed">' . esc_html__( 'Not processed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</span>';
		}

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$date        = wp_date( $date_format, $timestamp );
		$relative    = human_time_diff( $timestamp, time() );

		return sprintf(
			'%1$s<br><span class="relative-time">%2$s</span>',
			esc_html( $date ),
			esc_html(
				sprintf(
					/* translators: %s: human readable time difference. */
					__( '%s ago', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					$relative
				)
			)
		);
	}

	/**
	 * Display admin notices for bulk actions.
	 *
	 * @since 1.0.0
	 */
	public static function display_bulk_action_notices(): void {

		// Only show notice on ActiveLayer admin pages (Guideline 11 compliance).
		$screen = get_current_screen();

		if ( ! $screen || strpos( $screen->id, 'activelayer' ) === false ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		if ( empty( $_GET['bulk_action'] ) || empty( $_GET['count'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$action = sanitize_key( wp_unslash( $_GET['bulk_action'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$count = absint( wp_unslash( $_GET['count'] ) );

		$messages = [
			/* translators: %d: number of submissions. */
			'recheck'            => _n(
				'%d submission queued for recheck.',
				'%d submissions queued for recheck.',
				$count,
				'activelayer-anti-spam-spam-protection-for-forms-comments'
			),
			/* translators: %d: number of submissions. */
			'mark_clean'         => _n(
				'%d submission marked as clean.',
				'%d submissions marked as clean.',
				$count,
				'activelayer-anti-spam-spam-protection-for-forms-comments'
			),
			/* translators: %d: number of submissions. */
			'mark_spam'          => _n(
				'%d submission marked as spam.',
				'%d submissions marked as spam.',
				$count,
				'activelayer-anti-spam-spam-protection-for-forms-comments'
			),
			/* translators: %d: number of submissions. */
			'trash'              => _n(
				'%d submission moved to trash.',
				'%d submissions moved to trash.',
				$count,
				'activelayer-anti-spam-spam-protection-for-forms-comments'
			),
			/* translators: %d: number of submissions. */
			'restore'            => _n(
				'%d submission restored.',
				'%d submissions restored.',
				$count,
				'activelayer-anti-spam-spam-protection-for-forms-comments'
			),
			/* translators: %d: number of submissions. */
			'delete_permanently' => _n(
				'%d submission deleted permanently.',
				'%d submissions deleted permanently.',
				$count,
				'activelayer-anti-spam-spam-protection-for-forms-comments'
			),
		];

		if ( isset( $messages[ $action ] ) ) {
			NoticeHelper::render(
				sprintf( $messages[ $action ], absint( $count ) ),
				NoticeHelper::TYPE_SUCCESS,
				true
			);
		}
	}
}
