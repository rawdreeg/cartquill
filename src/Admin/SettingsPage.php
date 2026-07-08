<?php
/**
 * Minimal admin settings screen: the from-identity.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Settings\OptionsSettings;

/**
 * Registers a top-level "CartQuill" admin menu with a settings form for the
 * from-name and from-address. Persistence goes through OptionsSettings so the
 * engine reads the same values.
 */
final class SettingsPage {

	private const PAGE_SLUG   = 'cartquill';
	private const OPTION_GROUP = 'cartquill_settings_group';

	public function __construct( private readonly OptionsSettings $settings ) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu(): void {
		\add_menu_page(
			\__( 'CartQuill', 'cartquill' ),
			\__( 'CartQuill', 'cartquill' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			MenuIcon::data_uri(),
			56
		);
	}

	public function register_settings(): void {
		\register_setting(
			self::OPTION_GROUP,
			OptionsSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, string>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'from_name'                => \sanitize_text_field( $input['from_name'] ?? '' ),
			'from_email'               => \sanitize_email( $input['from_email'] ?? '' ),
			'attribution_window_days'  => max( 1, (int) ( $input['attribution_window_days'] ?? OptionsSettings::DEFAULT_ATTRIBUTION_DAYS ) ),
			'remove_data_on_uninstall' => empty( $input['remove_data_on_uninstall'] ) ? '' : '1',
		);
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$option = OptionsSettings::OPTION;
		?>
		<div class="wrap cartquill-admin">
			<h1><?php echo \esc_html__( 'CartQuill Settings', 'cartquill' ); ?></h1>
			<form action="options.php" method="post">
				<?php \settings_fields( self::OPTION_GROUP ); ?>
				<div class="cartquill-panel">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="cartquill_from_name"><?php echo \esc_html__( 'From name', 'cartquill' ); ?></label>
						</th>
						<td>
							<input type="text" id="cartquill_from_name"
								name="<?php echo \esc_attr( $option ); ?>[from_name]"
								value="<?php echo \esc_attr( $this->settings->from_name() ); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cartquill_from_email"><?php echo \esc_html__( 'From email', 'cartquill' ); ?></label>
						</th>
						<td>
							<input type="email" id="cartquill_from_email"
								name="<?php echo \esc_attr( $option ); ?>[from_email]"
								value="<?php echo \esc_attr( $this->settings->from_email() ); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cartquill_attribution_window_days"><?php echo \esc_html__( 'Attribution window (days)', 'cartquill' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" step="1" id="cartquill_attribution_window_days"
								name="<?php echo \esc_attr( $option ); ?>[attribution_window_days]"
								value="<?php echo \esc_attr( (string) $this->settings->attribution_window_days() ); ?>"
								class="small-text" />
							<p class="description"><?php echo \esc_html__( 'An order credits the most recent flow email sent within this many days.', 'cartquill' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo \esc_html__( 'Data on uninstall', 'cartquill' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo \esc_attr( $option ); ?>[remove_data_on_uninstall]"
									value="1" <?php \checked( $this->settings->remove_data_on_uninstall() ); ?> />
								<?php echo \esc_html__( 'Delete all CartQuill data (flows, enrollments, reports, settings) when the plugin is deleted.', 'cartquill' ); ?>
							</label>
							<p class="description"><?php echo \esc_html__( 'Off by default — your data is kept if you delete and later reinstall.', 'cartquill' ); ?></p>
						</td>
					</tr>
				</table>
				</div>
				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
