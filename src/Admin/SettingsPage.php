<?php
/**
 * Minimal admin settings screen: the from-identity.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Admin;

use FlowForge\Settings\OptionsSettings;

/**
 * Registers a top-level "FlowForge" admin menu with a settings form for the
 * from-name and from-address. Persistence goes through OptionsSettings so the
 * engine reads the same values.
 */
final class SettingsPage {

	private const PAGE_SLUG   = 'flowforge';
	private const OPTION_GROUP = 'flowforge_settings_group';

	public function __construct( private readonly OptionsSettings $settings ) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu(): void {
		\add_menu_page(
			\__( 'FlowForge', 'flowforge' ),
			\__( 'FlowForge', 'flowforge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-email-alt',
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
			'from_name'  => \sanitize_text_field( $input['from_name'] ?? '' ),
			'from_email' => \sanitize_email( $input['from_email'] ?? '' ),
		);
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$option = OptionsSettings::OPTION;
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'FlowForge Settings', 'flowforge' ); ?></h1>
			<form action="options.php" method="post">
				<?php \settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="flowforge_from_name"><?php echo \esc_html__( 'From name', 'flowforge' ); ?></label>
						</th>
						<td>
							<input type="text" id="flowforge_from_name"
								name="<?php echo \esc_attr( $option ); ?>[from_name]"
								value="<?php echo \esc_attr( $this->settings->from_name() ); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="flowforge_from_email"><?php echo \esc_html__( 'From email', 'flowforge' ); ?></label>
						</th>
						<td>
							<input type="email" id="flowforge_from_email"
								name="<?php echo \esc_attr( $option ); ?>[from_email]"
								value="<?php echo \esc_attr( $this->settings->from_email() ); ?>"
								class="regular-text" />
						</td>
					</tr>
				</table>
				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
