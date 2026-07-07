<?php
/**
 * License key entry for the paid add-ons.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Licensing\OptionLicense;
use CartQuill\Licensing\Plans;

/**
 * Lets a store enter license keys to unlock the AI and Deliverability add-ons
 * (or the Pro bundle). SCAFFOLD: this stores the keys and marks plans active
 * locally; in production Freemius validates each key. The gate the add-ons read
 * (`License::is_active`) is the same in both worlds.
 */
final class LicensePage {

	private const PARENT = 'cartquill';
	public const SLUG    = 'cartquill-license';
	private const MASK   = '••••••••';

	private const LABELS = array(
		Plans::AI            => 'AI Flow Generation',
		Plans::DELIVERABILITY => 'Deliverability',
		Plans::PRO           => 'Pro bundle (AI + Deliverability)',
	);

	/** Subscription tiers for the automation product (scaffold: Freemius owns these in production). */
	private const TIER_LABELS = array(
		Plans::STARTER => 'Starter',
		Plans::GROWTH  => 'Growth',
		Plans::AGENCY  => 'Agency',
	);

	public function __construct( private readonly OptionLicense $license ) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_post_cartquill_save_license', array( $this, 'handle_save' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'License', 'cartquill' ),
			\__( 'License', 'cartquill' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save(): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( 'cartquill_save_license' ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'cartquill' ) );
		}

		$plans = array_merge( array_keys( self::LABELS ), array_keys( self::TIER_LABELS ) );
		foreach ( $plans as $plan ) {
			if ( ! isset( $_POST['keys'][ $plan ] ) ) {
				continue;
			}
			$key = \sanitize_text_field( \wp_unslash( $_POST['keys'][ $plan ] ) );
			// The mask means "unchanged"; an empty value clears the key.
			if ( self::MASK !== $key ) {
				$this->license->set_key( $plan, $key );
			}
		}

		\wp_safe_redirect( \admin_url( 'admin.php?page=' . self::SLUG . '&updated=1' ) );
		exit;
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'CartQuill License', 'cartquill' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php echo \esc_html__( 'License keys saved.', 'cartquill' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<?php \wp_nonce_field( 'cartquill_save_license' ); ?>
				<input type="hidden" name="action" value="cartquill_save_license" />
				<table class="form-table">
					<?php foreach ( self::LABELS as $plan => $label ) : ?>
						<tr>
							<th scope="row"><?php echo \esc_html( $label ); ?></th>
							<td>
								<input type="text" class="regular-text" name="keys[<?php echo \esc_attr( $plan ); ?>]"
									autocomplete="off"
									value="<?php echo '' !== $this->license->key_for( $plan ) ? \esc_attr( self::MASK ) : ''; ?>"
									placeholder="<?php echo \esc_attr__( 'Enter license key', 'cartquill' ); ?>" />
								<?php if ( $this->license->is_active( $plan ) ) : ?>
									<span style="color:#008a20">&#10003; <?php echo \esc_html__( 'Active', 'cartquill' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php echo \esc_html__( 'Subscription plan', 'cartquill' ); ?></h2>
				<p class="description">
					<?php echo \esc_html__( 'The automation product plan. All five integrations ship on every tier; plans differ on the monthly action cap, active-workflow cap, and conditional logic.', 'cartquill' ); ?>
				</p>
				<table class="form-table">
					<?php foreach ( self::TIER_LABELS as $tier => $label ) : ?>
						<tr>
							<th scope="row"><?php echo \esc_html( $label ); ?></th>
							<td>
								<input type="text" class="regular-text" name="keys[<?php echo \esc_attr( $tier ); ?>]"
									autocomplete="off"
									value="<?php echo '' !== $this->license->key_for( $tier ) ? \esc_attr( self::MASK ) : ''; ?>"
									placeholder="<?php echo \esc_attr__( 'Enter license key', 'cartquill' ); ?>" />
								<?php if ( $this->license->plan() === $tier ) : ?>
									<span style="color:#008a20">&#10003; <?php echo \esc_html__( 'Current plan', 'cartquill' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php $limits = $this->license->limits(); ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: monthly action cap, 2: active-workflow cap, 3: on/off */
						\esc_html__( 'Current caps — actions/month: %1$s, active workflows: %2$s, conditional logic: %3$s.', 'cartquill' ),
						\esc_html( \number_format_i18n( (int) ( $limits['actions'] ?? 0 ) ) ),
						0 === (int) ( $limits['workflows'] ?? 0 ) ? \esc_html__( 'unlimited', 'cartquill' ) : \esc_html( \number_format_i18n( (int) $limits['workflows'] ) ),
						empty( $limits['conditional_logic'] ) ? \esc_html__( 'off', 'cartquill' ) : \esc_html__( 'on', 'cartquill' )
					);
					?>
				</p>

				<?php \submit_button( \__( 'Save license keys', 'cartquill' ) ); ?>
			</form>
		</div>
		<?php
	}
}
