<?php
/**
 * AI Flow Generation add-on bootstrap.
 *
 * Included by the composition root when this directory is present; the free
 * WordPress.org build ships without it. Self-registers on the
 * `cartquill_register_addons` hook.
 *
 * @package CartQuill
 */

declare(strict_types=1);

use CartQuill\Ai\AiAddon;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Licensing\OptionLicense;
use CartQuill\Persistence\WpdbFlowRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( new AiAddon( new WpdbFlowRepository(), new FlowLibrary(), new OptionLicense() ) )->register();
