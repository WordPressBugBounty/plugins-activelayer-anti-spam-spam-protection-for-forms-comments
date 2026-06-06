<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable component for rendering submission stats counters.
 *
 * Accepts an array of stat counts and outputs an HTML grid of labelled
 * counters for total, clean, spam, and failed submissions.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 *
 * @package ActiveLayer\Admin
 */
class StatsBlock {

	/**
	 * Submission stats.
	 *
	 * @since 1.1.0
	 *
	 * @var array{total: int, clean: int, spam: int, failed: int}
	 */
	private $stats;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param array $stats Stats array with keys: total, clean, spam, failed.
	 */
	public function __construct( array $stats ) {

		$this->stats = [
			'total'  => isset( $stats['total'] ) ? (int) $stats['total'] : 0,
			'clean'  => isset( $stats['clean'] ) ? (int) $stats['clean'] : 0,
			'spam'   => isset( $stats['spam'] ) ? (int) $stats['spam'] : 0,
			'failed' => isset( $stats['failed'] ) ? (int) $stats['failed'] : 0,
		];
	}

	/**
	 * Render the stats grid HTML.
	 *
	 * @since 1.1.0
	 */
	public function render(): void {

		?>
		<div class="activelayer-dash-widget-stats">
			<?php
			$this->render_stat(
				esc_html__( 'Total', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$this->stats['total'],
				'total'
			);
			$this->render_stat(
				esc_html__( 'Clean', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$this->stats['clean'],
				'clean'
			);
			$this->render_stat(
				esc_html__( 'Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$this->stats['spam'],
				'spam'
			);
			$this->render_stat(
				esc_html__( 'Failed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$this->stats['failed'],
				'failed'
			);
			?>
		</div>
		<?php
	}

	/**
	 * Render a single stat item.
	 *
	 * @since 1.1.0
	 *
	 * @param string $label Translated label for the stat.
	 * @param int    $count Numeric count value.
	 * @param string $type  Stat type used as a BEM modifier (total, clean, spam, failed).
	 */
	private function render_stat( string $label, int $count, string $type ): void {

		?>
		<div class="activelayer-dash-widget-stat activelayer-dash-widget-stat--<?php echo esc_attr( $type ); ?>">
			<span class="activelayer-dash-widget-stat__label"><?php echo esc_html( $label ); ?></span>
			<span class="activelayer-dash-widget-stat__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
		</div>
		<?php
	}
}
