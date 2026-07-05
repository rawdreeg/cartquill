<?php
/**
 * Deliverability add-on bootstrap.
 *
 * Included by the composition root when this directory is present; the free
 * WordPress.org build ships without it. Self-registers on
 * `cartquill_register_senders`, `cartquill_register_addons`, and the
 * `cartquill_active_sender` filter.
 *
 * @package CartQuill
 */

declare(strict_types=1);

use CartQuill\Compliance\WpdbSuppressionList;
use CartQuill\Deliverability\DeliverabilityAddon;
use CartQuill\Deliverability\EspSettings;
use CartQuill\Licensing\OptionLicense;
use CartQuill\Persistence\WpdbMessageRepository;
use CartQuill\Security\InstallKey;
use CartQuill\Security\SodiumCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( new DeliverabilityAddon(
	new EspSettings( new SodiumCrypto( InstallKey::get() ) ),
	new OptionLicense(),
	new WpdbMessageRepository(),
	new WpdbSuppressionList()
) )->register();
