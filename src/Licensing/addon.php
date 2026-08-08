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

// Freemius owns plan status in production; the bridge drives the licensing
// filters from the customer's subscription tier. Both the SDK bootstrap and the
// bridge's provider are no-ops until the SDK and identifiers are present.
require_once CARTQUILL_PATH . 'src/freemius.php';
if ( function_exists( 'cartquill_fs' ) ) {
	cartquill_fs();
}
( new FreemiusBridge() )->register();

( new LicensePage( $cartquill_license ) )->register();
( new LicensedAvailability( $cartquill_license ) )->register();
( new PlanGate( $cartquill_license, new WpdbFlowRepository() ) )->register();

$cartquill_meter = new UsageMeter( new WpdbUsageStore(), $cartquill_license, new SystemClock() );
add_filter( 'cartquill_meter', static fn(): Meter => $cartquill_meter );
( new UsageNotice( $cartquill_meter ) )->register();
