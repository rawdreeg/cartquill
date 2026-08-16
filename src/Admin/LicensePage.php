<?php
/**
 * The subscription plan screen.
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
 * Shows which plan the store is on, and what that plan allows.
 *
 * Two modes, matching {@see \CartQuill\Licensing\FreemiusBridge}. Normally
 * Freemius owns plan status and this page is read-only: the plan arrives with
 * the subscription and there is nothing to type. Only with
 * CARTQUILL_LOCAL_LICENSE set in wp-config.php does it offer key fields, and
 * those keys are stored rather than verified — a development affordance, kept
 * out of reach of anyone who merely has a copy of the plugin.
 *
 * The gate the add-ons read (`License::is_active`) is the same in both modes.
 */
final class LicensePage {

	private const PARENT = 'cartquill';
	public const SLUG    = 'cartquill-license';
	private const MASK   = '••••••••';

	private const LABELS = array(
		Plans::AI  => 'AI Flow Generation',
		Plans::PRO => 'Pro bundle (AI)',
	);

	/** Subscription tiers for the automation product; Freemius owns which one is held. */
	private const TIER_LABELS = array(
		Plans::STARTER => 'Starter',
		Plans::GROWTH  => 'Growth',
		Plans::AGENCY  => 'Agency',
	);

	/** Whether Freemius owns plan status here — see {@see LicensePage::render()}. */
	private readonly bool $owns_plan;

	/**
	 * @param bool|null $owns_plan Defaults to cartquill_fs_owns_plan(); the premium
	 *                             bootstrap defines it, and a tree without that file
	 *                             keeps the local behaviour. Tests pass it explicitly.
	 */
	public function __construct( private readonly OptionLicense $license, ?bool $owns_plan = null ) {
		$this->owns_plan = $owns_plan
			?? ( function_exists( 'cartquill_fs_owns_plan' ) && \cartquill_fs_owns_plan() );
	}

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

		// Where Freemius owns plan status the key store grants nothing, so writing
		// to it would only be misleading. render() offers no form in that mode —
		// this is the guard for a stale tab or a hand-made POST.
		if ( $this->owns_plan ) {
			\wp_safe_redirect( \admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
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
		<div class="wrap cartquill-admin">
			<h1><?php echo \esc_html__( 'CartQuill License', 'cartquill' ); ?></h1>
			<?php
			if ( $this->owns_plan ) {
				$this->render_managed();
			} else {
				$this->render_local();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The production view: the plan comes from the customer's CartQuill account
	 * and there is nothing to type. Read-only on purpose — a key field here would
	 * be a second, unverified way to claim a plan.
	 */
	private function render_managed(): void {
		$current_tier = $this->license->plan();
		?>
		<h2><?php echo \esc_html__( 'Subscription plan', 'cartquill' ); ?></h2>
		<p class="cartquill-section-lead">
			<?php echo \esc_html__( 'Your plan comes from your CartQuill account. Purchases, upgrades, and renewals apply here on their own — there are no keys to enter.', 'cartquill' ); ?>
		</p>

		<p>
			<?php if ( '' !== $current_tier ) : ?>
				<span class="cartquill-badge cartquill-badge--brand">
					<?php
					printf(
						/* translators: %s: subscription tier name */
						\esc_html__( 'Current plan: %s', 'cartquill' ),
						\esc_html( self::TIER_LABELS[ $current_tier ] ?? ucfirst( $current_tier ) )
					);
					?>
				</span>
			<?php else : ?>
				<?php echo \esc_html__( 'No active subscription. Everything the free edition ships keeps working and stays uncapped; the paid integrations and AI generation switch on when a plan is active.', 'cartquill' ); ?>
			<?php endif; ?>
		</p>

		<div class="cartquill-tiers">
			<?php foreach ( self::TIER_LABELS as $tier => $label ) : ?>
				<?php $this->tier_card( $tier, $label, $current_tier === $tier, false ); ?>
			<?php endforeach; ?>
		</div>

		<?php $this->caps_line(); ?>

		<p class="description">
			<a href="<?php echo \esc_url( $this->account_url() ); ?>">
				<?php echo \esc_html__( 'Manage your subscription →', 'cartquill' ); ?>
			</a>
		</p>
		<p class="description">
			<a href="https://cartquill.com/#pricing" target="_blank" rel="noopener">
				<?php echo \esc_html__( 'Compare plans and pricing on cartquill.com →', 'cartquill' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * The dev/demo view, reachable only by defining CARTQUILL_LOCAL_LICENSE in
	 * wp-config.php. Keys entered here are not validated against anything — they
	 * exist so the add-ons can be built and demonstrated without a subscription.
	 */
	private function render_local(): void {
		$current_tier = $this->license->plan();
		?>
		<div class="notice notice-warning inline">
			<p><?php echo \esc_html__( 'Local licensing is enabled (CARTQUILL_LOCAL_LICENSE). Keys entered here are not verified — this mode is for development only.', 'cartquill' ); ?></p>
		</div>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php echo \esc_html__( 'License keys saved.', 'cartquill' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'cartquill_save_license' ); ?>
			<input type="hidden" name="action" value="cartquill_save_license" />

			<h2><?php echo \esc_html__( 'Subscription plan', 'cartquill' ); ?></h2>
			<p class="cartquill-section-lead">
				<?php echo \esc_html__( 'All five integrations and email ship on every tier. Tiers differ on the monthly action cap, active workflows, conditional logic, and which add-ons are included. Enter the license key for your tier below.', 'cartquill' ); ?>
			</p>
			<div class="cartquill-tiers">
				<?php foreach ( self::TIER_LABELS as $tier => $label ) : ?>
					<?php $this->tier_card( $tier, $label, $current_tier === $tier, true ); ?>
				<?php endforeach; ?>
			</div>
			<p class="description">
				<a href="https://cartquill.com/#pricing" target="_blank" rel="noopener">
					<?php echo \esc_html__( 'Compare plans and pricing on cartquill.com →', 'cartquill' ); ?>
				</a>
			</p>

			<?php $this->caps_line(); ?>

			<details class="cartquill-legacy">
				<summary><?php echo \esc_html__( 'À la carte add-on keys (legacy)', 'cartquill' ); ?></summary>
				<p class="description">
					<?php echo \esc_html__( 'The add-ons are included in the subscription tiers. These fields only matter for keys bought before the tiered plans.', 'cartquill' ); ?>
				</p>
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
									<span class="cartquill-badge cartquill-badge--active">&#10003; <?php echo \esc_html__( 'Active', 'cartquill' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</details>

			<?php \submit_button( \__( 'Save license keys', 'cartquill' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The caps the store is actually running under, whichever mode decided them.
	 */
	private function caps_line(): void {
		$limits = $this->license->limits();
		?>
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
		<?php
	}

	/**
	 * The SDK's account screen, or the site's own pricing page if the SDK did not
	 * start — the link should never dead-end.
	 */
	private function account_url(): string {
		try {
			if ( function_exists( 'cartquill_fs' ) && null !== \cartquill_fs() ) {
				return (string) \cartquill_fs()->get_account_url();
			}
		} catch ( \Throwable $e ) {
			// Fall through to the public pricing page.
		}
		return 'https://cartquill.com/#pricing';
	}

	/**
	 * One subscription-tier card: the caps and included add-ons straight from
	 * Plans::entitlements()/grants(), a highlight when the store is on this tier,
	 * and — only in local mode — the key field.
	 */
	private function tier_card( string $tier, string $label, bool $is_current, bool $editable ): void {
		$entitlements = Plans::entitlements( $tier );
		$grants       = Plans::grants( $tier );
		?>
		<div class="cartquill-tier<?php echo $is_current ? ' is-current' : ''; ?>">
			<h3>
				<?php echo \esc_html( $label ); ?>
				<?php if ( $is_current ) : ?>
					<span class="cartquill-badge cartquill-badge--brand"><?php echo \esc_html__( 'Current plan', 'cartquill' ); ?></span>
				<?php endif; ?>
			</h3>
			<ul>
				<li>
					<?php
					printf(
						/* translators: %s: monthly action cap */
						\esc_html__( '%s actions / month', 'cartquill' ),
						\esc_html( \number_format_i18n( (int) ( $entitlements['actions'] ?? 0 ) ) )
					);
					?>
				</li>
				<li>
					<?php
					if ( 0 === (int) ( $entitlements['workflows'] ?? 0 ) ) {
						echo \esc_html__( 'Unlimited active workflows', 'cartquill' );
					} else {
						printf(
							/* translators: %s: active-workflow cap */
							\esc_html__( '%s active workflows', 'cartquill' ),
							\esc_html( \number_format_i18n( (int) $entitlements['workflows'] ) )
						);
					}
					?>
				</li>
				<li><?php echo \esc_html__( 'All 5 integrations + email', 'cartquill' ); ?></li>
				<?php if ( ! empty( $entitlements['conditional_logic'] ) ) : ?>
					<li><?php echo \esc_html__( 'Conditional logic', 'cartquill' ); ?></li>
				<?php endif; ?>
				<?php if ( in_array( Plans::AI, $grants, true ) ) : ?>
					<li><?php echo \esc_html__( 'AI flow generation', 'cartquill' ); ?></li>
				<?php endif; ?>
				<?php if ( Plans::AGENCY === $tier ) : ?>
					<li class="is-soon"><?php echo \esc_html__( 'Multi-store console (coming soon)', 'cartquill' ); ?></li>
				<?php endif; ?>
			</ul>
			<?php if ( $editable ) : ?>
				<input type="text" class="regular-text" name="keys[<?php echo \esc_attr( $tier ); ?>]"
					autocomplete="off"
					value="<?php echo '' !== $this->license->key_for( $tier ) ? \esc_attr( self::MASK ) : ''; ?>"
					placeholder="<?php echo \esc_attr__( 'Enter license key', 'cartquill' ); ?>" />
			<?php endif; ?>
		</div>
		<?php
	}
}
