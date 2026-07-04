<?php
/**
 * One row of the reporting dashboard: a flow's engagement + revenue.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Reporting;

final class FlowReportRow {

	public function __construct(
		public readonly int $flow_id,
		public readonly string $name,
		public readonly int $sent,
		public readonly int $opened,
		public readonly int $clicked,
		public readonly float $revenue,
		public readonly int $delivered = 0,
		public readonly int $bounced = 0,
		public readonly int $complained = 0,
	) {}
}
