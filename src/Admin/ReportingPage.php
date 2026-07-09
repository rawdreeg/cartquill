<?php
/**
 * Reporting dashboard: revenue-per-flow + engagement.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Integration\AttributionTrigger;
use CartQuill\Licensing\License;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\AttributionRepository;
use CartQuill\Persistence\FlowRepository;
use CartQuill\Persistence\MessageRepository;
use CartQuill\Reporting\FlowReport;

/**
 * Renders the one reporting screen: per-flow sent / opens / clicks / revenue,
 * the attribution window (surfaced, per the transparency requirement), and the
 * free-tier "delivery unconfirmed" upgrade note.
 */
final class ReportingPage {

	private const PARENT = 'cartquill';
	private const SLUG   = 'cartquill-reporting';

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly MessageRepository $messages,
		private readonly AttributionRepository $attributions,
		private readonly License $license,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'Reporting', 'cartquill' ),
			\__( 'Reporting', 'cartquill' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$deliverability = $this->license->is_active( Plans::DELIVERABILITY );
		// Whether delivery events are actually flowing (key + verified domain +
		// webhook secret), refined by the Deliverability add-on. The columns show
		// on license alone, but the green "tracking active" banner needs the real
		// pipeline wired — otherwise it claims tracking that never arrives.
		$tracking_ready = (bool) \apply_filters( 'cartquill_delivery_tracking_ready', false );
		$report         = new FlowReport();
		$rows           = $report->build(
			$this->flows->all(),
			$this->messages->stats_by_flow(),
			$this->attributions->revenue_by_flow(),
			$deliverability ? $this->messages->delivery_stats_by_flow() : array()
		);
		$total          = $report->total_revenue( $rows );
		$totals         = $report->totals( $rows );
		$days           = max( 1, (int) round( AttributionTrigger::window() / DAY_IN_SECONDS ) );
		?>
		<div class="wrap cartquill-admin">
			<h1><?php echo \esc_html__( 'CartQuill Reporting', 'cartquill' ); ?></h1>

			<div class="cartquill-stats">
				<div class="cartquill-stat cartquill-stat--primary">
					<div class="cartquill-stat__value"><?php echo \wp_kses_post( $this->money( $total ) ); ?></div>
					<div class="cartquill-stat__label"><?php echo \esc_html__( 'Revenue attributed', 'cartquill' ); ?></div>
				</div>
				<div class="cartquill-stat">
					<div class="cartquill-stat__value"><?php echo \esc_html( \number_format_i18n( $totals['sent'] ) ); ?></div>
					<div class="cartquill-stat__label"><?php echo \esc_html__( 'Emails sent', 'cartquill' ); ?></div>
				</div>
				<div class="cartquill-stat">
					<div class="cartquill-stat__value"><?php echo \esc_html( \number_format_i18n( $totals['opened'] ) ); ?></div>
					<div class="cartquill-stat__label"><?php echo \esc_html__( 'Opens', 'cartquill' ); ?></div>
					<div class="cartquill-stat__sub"><?php echo \esc_html( $this->rate( $totals['opened'], $totals['sent'] ) ); ?></div>
				</div>
				<div class="cartquill-stat">
					<div class="cartquill-stat__value"><?php echo \esc_html( \number_format_i18n( $totals['clicked'] ) ); ?></div>
					<div class="cartquill-stat__label"><?php echo \esc_html__( 'Clicks', 'cartquill' ); ?></div>
					<div class="cartquill-stat__sub"><?php echo \esc_html( $this->rate( $totals['clicked'], $totals['sent'] ) ); ?></div>
				</div>
			</div>

			<p class="description">
				<?php
				printf(
					/* translators: %s: attribution window, e.g. "1 day" or "7 days". */
					\esc_html__( 'Revenue is attributed last-touch: an order credits the most recent flow email sent within %s.', 'cartquill' ),
					\esc_html( sprintf( \_n( '%d day', '%d days', $days, 'cartquill' ), $days ) )
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo \esc_html__( 'Flow', 'cartquill' ); ?></th>
						<th><?php echo \esc_html__( 'Sent', 'cartquill' ); ?></th>
						<th><?php echo \esc_html__( 'Failed', 'cartquill' ); ?></th>
						<?php if ( $deliverability ) : ?>
							<th><?php echo \esc_html__( 'Delivered', 'cartquill' ); ?></th>
						<?php endif; ?>
						<th><?php echo \esc_html__( 'Opens', 'cartquill' ); ?></th>
						<th><?php echo \esc_html__( 'Clicks', 'cartquill' ); ?></th>
						<?php if ( $deliverability ) : ?>
							<th><?php echo \esc_html__( 'Bounced', 'cartquill' ); ?></th>
							<th><?php echo \esc_html__( 'Complaints', 'cartquill' ); ?></th>
						<?php endif; ?>
						<th><?php echo \esc_html__( 'Revenue', 'cartquill' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php $cols = $deliverability ? 9 : 6; ?>
					<?php if ( array() === $rows ) : ?>
						<tr><td colspan="<?php echo (int) $cols; ?>"><?php echo \esc_html__( 'No flows yet — install one from the flow library.', 'cartquill' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo \esc_html( $row->name ); ?></td>
							<td><?php echo \esc_html( (string) $row->sent ); ?></td>
							<td><?php echo \esc_html( (string) $row->failed ); ?></td>
							<?php if ( $deliverability ) : ?>
								<td><?php echo \esc_html( (string) $row->delivered ); ?></td>
							<?php endif; ?>
							<td><?php echo \esc_html( (string) $row->opened ); ?></td>
							<td><?php echo \esc_html( (string) $row->clicked ); ?></td>
							<?php if ( $deliverability ) : ?>
								<td><?php echo \esc_html( (string) $row->bounced ); ?></td>
								<td><?php echo \esc_html( (string) $row->complained ); ?></td>
							<?php endif; ?>
							<td><?php echo \wp_kses_post( $this->money( $row->revenue ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th><?php echo \esc_html__( 'Total', 'cartquill' ); ?></th>
						<th colspan="<?php echo (int) ( $cols - 2 ); ?>"></th>
						<th><?php echo \wp_kses_post( $this->money( $total ) ); ?></th>
					</tr>
				</tfoot>
			</table>

			<?php if ( $tracking_ready ) : ?>
				<div class="notice notice-success inline" style="margin-top:16px">
					<p>
						<strong><?php echo \esc_html__( 'Delivery tracking active.', 'cartquill' ); ?></strong>
						<?php echo \esc_html__( 'Delivered, bounce, and complaint data populates here as your ESP sends webhook events. Bounced and complained addresses are automatically suppressed.', 'cartquill' ); ?>
					</p>
				</div>
			<?php elseif ( $deliverability ) : ?>
				<div class="notice notice-warning inline" style="margin-top:16px">
					<p>
						<strong><?php echo \esc_html__( 'Finish delivery setup.', 'cartquill' ); ?></strong>
						<?php echo \esc_html__( 'Connect Resend, verify your sending domain, and add the webhook signing secret on the Deliverability screen to start receiving delivered, bounce, and complaint data.', 'cartquill' ); ?>
					</p>
				</div>
			<?php else : ?>
				<div class="notice notice-info inline" style="margin-top:16px">
					<p>
						<strong><?php echo \esc_html__( 'Delivery unconfirmed.', 'cartquill' ); ?></strong>
						<?php echo \esc_html__( 'The free tier sends via your site mail, so inbox delivery can’t be confirmed. Add the Deliverability add-on to see delivered, bounce, and inbox-placement data.', 'cartquill' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return \wc_price( $amount );
		}
		return \esc_html( number_format_i18n( $amount, 2 ) );
	}

	/**
	 * A rate like "40% of sent" for the open/click stat cards; empty while
	 * nothing has been sent, so a fresh store shows no misleading 0%.
	 */
	private function rate( int $part, int $whole ): string {
		if ( $whole <= 0 ) {
			return '';
		}
		return sprintf(
			/* translators: %s: percentage of sent emails, e.g. "40%". */
			\__( '%s of sent', 'cartquill' ),
			\number_format_i18n( ( $part / $whole ) * 100, 1 ) . '%'
		);
	}
}
