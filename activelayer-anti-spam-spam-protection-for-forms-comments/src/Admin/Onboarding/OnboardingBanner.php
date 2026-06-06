<?php

namespace ActiveLayer\Admin\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the onboarding banner.
 *
 * Outputs the interactive 3-step onboarding banner matching the Figma design.
 * Each step shows pending or completed state based on step data.
 *
 * @since 1.1.0
 */
class OnboardingBanner {

	/**
	 * OnboardingManager instance.
	 *
	 * @since 1.1.0
	 *
	 * @var OnboardingManager
	 */
	private $manager;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param OnboardingManager $manager Onboarding manager instance.
	 */
	public function __construct( OnboardingManager $manager ) {

		$this->manager = $manager;
	}

	/**
	 * Render the onboarding banner if it should be shown.
	 *
	 * @since 1.1.0
	 */
	public function render(): void {

		if ( ! $this->manager->should_show_banner() ) {
			return;
		}

		$steps = $this->manager->get_steps();

		?>
		<div class="activelayer-onboarding-banner" id="activelayer-onboarding-banner">
			<button type="button" class="onboarding-dismiss" id="activelayer-dismiss-onboarding" aria-label="<?php esc_attr_e( 'Dismiss setup guide', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>

			<div class="onboarding-main-content">
				<div class="onboarding-text-content">
					<h2 class="onboarding-title">
						<?php
						echo esc_html__( 'Get Started with ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . ' ';
						?>
						<span class="onboarding-wave">&#x1F44B;</span>
					</h2>

					<div class="onboarding-instructions">
						<?php
						$step_keys = array_keys( $steps );
						$last_key  = end( $step_keys );

						foreach ( $steps as $key => $step ) {
							$this->render_step( $step );

							if ( $key !== $last_key ) {
								echo '<hr class="onboarding-divider" />';
							}
						}
						?>
					</div>

					<div class="onboarding-documentation">
						<p>
							<?php
							printf(
								/* translators: %s: documentation URL. */
								wp_kses_post( __( 'Need more help? Check out our <a href="%s" target="_blank" rel="noopener noreferrer">Documentation here</a>', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ),
								esc_url( 'https://activelayer.com/docs/wordpress-plugin/?utm_source=plugin&utm_medium=onboarding-banner&utm_campaign=setup-guide' )
							);
							?>
							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</p>
					</div>
				</div>

				<div class="onboarding-image-container">
					<img src="<?php echo esc_url( ACTIVELAYER_PLUGIN_URL . 'assets/images/onboarding-image.webp' ); ?>" alt="<?php esc_attr_e( 'ActiveLayer onboarding', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>" />
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single onboarding step.
	 *
	 * @since 1.1.0
	 *
	 * @param array $step Step data from OnboardingManager::get_steps().
	 */
	private function render_step( array $step ): void {

		$is_completed = ! empty( $step['completed'] );
		$step_class   = $is_completed ? 'onboarding-step--completed' : 'onboarding-step--pending';

		?>
		<div class="onboarding-step <?php echo esc_attr( $step_class ); ?>">
			<div class="onboarding-step-icon-container">
				<?php if ( $is_completed ) : ?>
					<div class="onboarding-step-icon onboarding-step-icon--completed">
						<span class="dashicons dashicons-yes"></span>
					</div>
				<?php else : ?>
					<div class="onboarding-step-icon onboarding-step-icon--pending">
						<?php echo esc_html( $step['number'] ); ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="onboarding-step-text">
				<div class="onboarding-step-content">
					<p class="onboarding-step-title"><?php echo esc_html( $step['title'] ); ?></p>
					<p class="onboarding-step-description"><?php echo wp_kses_post( $step['description'] ); ?></p>
				</div>

				<?php if ( ! $is_completed && ! empty( $step['cta_url'] ) ) : ?>
					<a href="<?php echo esc_url( $step['cta_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary onboarding-step-cta">
						<span><?php echo esc_html( $step['cta_label'] ); ?></span>
						<span class="dashicons dashicons-external"></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
