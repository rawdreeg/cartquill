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

		foreach ( array_keys( self::LABELS ) as $plan ) {
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
				<?php \submit_button( \__( 'Save license keys', 'cartquill' ) ); ?>
			</form>
		</div>
		<?php
	}
}
