<?php
/**
 * Premium licensing bootstrap.
 *
 * Included by the composition root when this directory is present. The
 * WordPress.org plugin ships without it — and without the whole `src/Licensing`
 * and usage-metering layer — so that build contains no licence check, no plan, no
 * usage cap and no upgrade prompt of any kind.
 *
 * Everything here attaches through the extension seams the plugin already
 * exposes: the builder's availability filter, the flow pre-save filter, and the
 * engine's meter filter.
 *
 * @package CartQuill
 */

declare(strict_types=1);

use CartQuill\Admin\LicensePage;
use CartQuill\Admin\UsageNotice;
use CartQuill\Licensing\FreemiusBridge;
use CartQuill\Licensing\LicensedAvailability;
use CartQuill\Licensing\OptionLicense;
use CartQuill\Licensing\PlanGate;
use CartQuill\Metering\Meter;
use CartQuill\Metering\UsageMeter;
use CartQuill\Metering\WpdbUsageStore;
use CartQuill\Persistence\WpdbFlowRepository;
use CartQuill\Support\SystemClock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cartquill_license = new OptionLicense();

// Freemius owns plan status; the bridge drives the licensing filters from the
// customer's subscription tier. The SDK itself is already running by now —
// early.php starts it while the main plugin file is read, because it has an
// activation hook to register and this file is included too late for that. The
// require_once here is only a safety net keeping cartquill_fs_owns_plan()
// defined if this file is ever reached without early.php having run; when it
// has, it costs nothing.
require_once CARTQUILL_PATH . 'src/freemius.php';
( new FreemiusBridge() )->register();

( new LicensePage( $cartquill_license ) )->register();
( new LicensedAvailability( $cartquill_license ) )->register();
( new PlanGate( $cartquill_license, new WpdbFlowRepository() ) )->register();

$cartquill_meter = new UsageMeter( new WpdbUsageStore(), $cartquill_license, new SystemClock() );
add_filter( 'cartquill_meter', static fn(): Meter => $cartquill_meter );
( new UsageNotice( $cartquill_meter ) )->register();
