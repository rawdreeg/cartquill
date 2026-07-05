<?php
/**
 * Deliverability add-on bootstrap.
 *
 * Included by the composition root when this directory is present; the free
 * WordPress.org build ships without it. Self-registers on
 * `flowforge_register_senders`, `flowforge_register_addons`, and the
 * `flowforge_active_sender` filter.
 *
 * @package FlowForge
 */

declare(strict_types=1);

use FlowForge\Compliance\WpdbSuppressionList;
use FlowForge\Deliverability\DeliverabilityAddon;
use FlowForge\Deliverability\EspSettings;
use FlowForge\Licensing\OptionLicense;
use FlowForge\Persistence\WpdbMessageRepository;
use FlowForge\Security\SodiumCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( new DeliverabilityAddon(
	new EspSettings( new SodiumCrypto( (string) \wp_salt( 'auth' ) ) ),
	new OptionLicense(),
	new WpdbMessageRepository(),
	new WpdbSuppressionList()
) )->register();
