<?php
/**
 * Automations add-on bootstrap.
 *
 * Included by the composition root when this directory is present; the free
 * WordPress.org build ships without it. Self-registers on
 * `cartquill_register_actions`, `cartquill_register_addons`, and the
 * `cartquill_flow_templates` filter.
 *
 * @package CartQuill
 */

declare(strict_types=1);

use CartQuill\Automations\AutomationsAddon;
use CartQuill\Automations\HttpMailchimpClient;
use CartQuill\Automations\HttpSheetsClient;
use CartQuill\Automations\HttpSlackClient;
use CartQuill\Licensing\OptionLicense;
use CartQuill\Persistence\EncryptedCredentials;
use CartQuill\Persistence\WpdbConnectionStore;
use CartQuill\Security\InstallKey;
use CartQuill\Security\SodiumCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( new AutomationsAddon(
	new WpdbConnectionStore( new EncryptedCredentials( new SodiumCrypto( InstallKey::get() ) ) ),
	new OptionLicense(),
	new HttpSlackClient(),
	new HttpSheetsClient(),
	new HttpMailchimpClient()
) )->register();
