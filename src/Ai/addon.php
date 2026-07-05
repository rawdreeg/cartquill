<?php
/**
 * AI Flow Generation add-on bootstrap.
 *
 * Included by the composition root when this directory is present; the free
 * WordPress.org build ships without it. Self-registers on the
 * `flowforge_register_addons` hook.
 *
 * @package FlowForge
 */

declare(strict_types=1);

use FlowForge\Ai\AiAddon;
use FlowForge\Flow\FlowLibrary;
use FlowForge\Licensing\OptionLicense;
use FlowForge\Persistence\WpdbFlowRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( new AiAddon( new WpdbFlowRepository(), new FlowLibrary(), new OptionLicense() ) )->register();
