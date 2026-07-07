<?php
/**
 * The default set of action descriptors the builder catalog is seeded with.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Action\EmailAction;
use CartQuill\Licensing\Plans;

/**
 * The action descriptors the free core ships. The `email` action is authoritative
 * from {@see EmailAction::config_fields()}. The paid actions live in the Automations
 * add-on, which the free WordPress.org build strips out — so core cannot (and must
 * not) reference those classes. Instead it ships a lightweight *static fallback*
 * descriptor for each paid type, marked with the automations capability, so the
 * free build can still show them as locked "upgrade" cards.
 *
 * When the Automations add-on is present it replaces these fallbacks with
 * authoritative descriptors (sourced from the live actions' own {@see
 * DescribesConfig::config_fields()}) through the {@see BuilderCatalog::FILTER_ACTIONS}
 * filter — so a licensed store edits the exact fields the action reads, while the
 * catalog module here never imports paid code.
 */
final class CoreActionDescriptors {

	use ConfigFields;

	/**
	 * @return list<ActionDescriptor>
	 */
	public static function all(): array {
		return array(
			new ActionDescriptor( EmailAction::TYPE, 'Send email', null, null, true, EmailAction::config_fields() ),
			new ActionDescriptor( 'slack_post', 'Post to Slack', 'slack', Plans::AUTOMATIONS, false, self::slack_fields() ),
			new ActionDescriptor( 'sheets_append', 'Append to Google Sheet', 'sheets', Plans::AUTOMATIONS, false, self::sheets_fields() ),
			new ActionDescriptor( 'mailchimp_sync', 'Sync to Mailchimp', 'mailchimp', Plans::AUTOMATIONS, false, self::mailchimp_fields() ),
			new ActionDescriptor( 'sms_send', 'Send SMS', 'twilio', Plans::AUTOMATIONS, true, self::sms_fields() ),
		);
	}

	/** @return list<array<string, mixed>> */
	private static function slack_fields(): array {
		return array(
			self::config_field( 'channel', 'Channel', 'text', array( 'help' => 'e.g. #orders' ) ),
			self::config_field( 'text', 'Message', 'textarea', array( 'default' => 'New order from {{ customer_email }}', 'merge' => true ) ),
		);
	}

	/** @return list<array<string, mixed>> */
	private static function sheets_fields(): array {
		return array(
			self::config_field( 'columns', 'Row columns', 'list', array( 'help' => 'One template per cell, e.g. {{ order_id }}', 'merge' => true ) ),
		);
	}

	/** @return list<array<string, mixed>> */
	private static function mailchimp_fields(): array {
		return array(
			self::config_field( 'tag', 'Tag', 'text', array( 'help' => 'The Mailchimp tag to add (drives their journey).' ) ),
		);
	}

	/** @return list<array<string, mixed>> */
	private static function sms_fields(): array {
		return array(
			self::config_field( 'text', 'Message', 'textarea', array( 'default' => 'Your order from {{ store_name }} has shipped.', 'merge' => true, 'required' => true ) ),
		);
	}
}
