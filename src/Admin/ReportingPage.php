<?php
/**
 * Reporting dashboard: revenue-per-flow + engagement.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Admin;

use FlowForge\Integration\AttributionTrigger;
use FlowForge\Persistence\AttributionRepository;
use FlowForge\Persistence\FlowRepository;
use FlowForge\Persistence\MessageRepository;
use FlowForge\Reporting\FlowReport;

/**
 * Renders the one reporting screen: per-flow sent / opens / clicks / revenue,
 * the attribution window (surfaced, per the transparency requirement), and the
 * free-tier "delivery unconfirmed" upgrade note.
 */
final class ReportingPage {

	private const PARENT = 'flowforge';
	private const SLUG   = 'flowforge-reporting';

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly MessageRepository $messages,
		private readonly AttributionRepository $attributions,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'Reporting', 'flowforge' ),
			\__( 'Reporting', 'flowforge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = new FlowReport();
		$rows   = $report->build( $this->flows->all(), $this->messages->stats_by_flow(), $this->attributions->revenue_by_flow() );
		$total  = $report->total_revenue( $rows );
		$days   = (int) round( AttributionTrigger::window() / DAY_IN_SECONDS );
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'FlowForge Reporting', 'flowforge' ); ?></h1>

			<p class="description">
				<?php
				printf(
					/* translators: %d: attribution window in days. */
					\esc_html__( 'Revenue is attributed last-touch: an order credits the most recent flow email sent within %d days.', 'flowforge' ),
					(int) $days
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo \esc_html__( 'Flow', 'flowforge' ); ?></th>
						<th><?php echo \esc_html__( 'Sent', 'flowforge' ); ?></th>
						<th><?php echo \esc_html__( 'Opens', 'flowforge' ); ?></th>
						<th><?php echo \esc_html__( 'Clicks', 'flowforge' ); ?></th>
						<th><?php echo \esc_html__( 'Revenue', 'flowforge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $rows ) : ?>
						<tr><td colspan="5"><?php echo \esc_html__( 'No flows yet — install one from the flow library.', 'flowforge' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo \esc_html( $row->name ); ?></td>
							<td><?php echo \esc_html( (string) $row->sent ); ?></td>
							<td><?php echo \esc_html( (string) $row->opened ); ?></td>
							<td><?php echo \esc_html( (string) $row->clicked ); ?></td>
							<td><?php echo \wp_kses_post( $this->money( $row->revenue ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th><?php echo \esc_html__( 'Total', 'flowforge' ); ?></th>
						<th></th><th></th><th></th>
						<th><?php echo \wp_kses_post( $this->money( $total ) ); ?></th>
					</tr>
				</tfoot>
			</table>

			<div class="notice notice-info inline" style="margin-top:16px">
				<p>
					<strong><?php echo \esc_html__( 'Delivery unconfirmed.', 'flowforge' ); ?></strong>
					<?php echo \esc_html__( 'The free tier sends via your site mail, so inbox delivery can’t be confirmed. Add the Deliverability add-on to see delivered, bounce, and inbox-placement data.', 'flowforge' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	private function money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return \wc_price( $amount );
		}
		return \esc_html( number_format_i18n( $amount, 2 ) );
	}
}
