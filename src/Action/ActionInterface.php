<?php
/**
 * A typed step action — the generalization of the single sending seam.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Action;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Model\SendResult;

/**
 * Every thing a flow step can *do* implements this interface: send an email,
 * post to Slack, append a Sheets row, sync a Mailchimp member, text an SMS.
 *
 * It mirrors {@see \CartQuill\Sender\SenderInterface} one level up: the step
 * runner resolves a step's action by {@see self::type()} and calls
 * {@see self::execute()}, staying ignorant of *what* the action does. Core ships
 * the `email` action (which wraps the compose -> SenderInterface::send() path
 * unchanged); paid add-ons register additional actions on the
 * `cartquill_register_actions` hook, gated on license + connection status.
 *
 * The result is the same {@see SendResult} every sender returns — an
 * accepted/failed status plus an optional external id — so the runner's record,
 * retry/backoff, and dead-letter logic apply to every channel for free.
 */
interface ActionInterface {

	/**
	 * Stable machine key (e.g. "email", "slack_post", "sms_send").
	 *
	 * Matches a flow step's `action` field and is recorded as the message row's
	 * `channel`, so later lifecycle events and reporting can be grouped by it.
	 */
	public function type(): string;

	/**
	 * Transport identity recorded in the message row's `sender` column
	 * (e.g. "wp_mail", "resend" for email; "slack", "twilio" for others). Lets
	 * webhook ingestion route a lifecycle event back to what produced the send.
	 */
	public function sender_key(): string;

	/**
	 * Whether this action reaches the customer directly.
	 *
	 * Customer-facing actions (email, SMS) are checked against the suppression
	 * list before every send; internal actions (Slack, Sheets, Mailchimp) reach
	 * no customer inbox and skip suppression.
	 */
	public function is_customer_facing(): bool;

	/**
	 * The customer destination this run targets — the email address, phone
	 * number, etc. Recorded as the message row's `target` and, for
	 * customer-facing actions, the identifier checked against suppression.
	 *
	 * Null when the action reaches no single customer destination (internal
	 * actions), in which case suppression is not consulted.
	 */
	public function target( ActionContext $context ): ?string;

	/**
	 * Perform the action.
	 *
	 * Must not throw for ordinary failures — return a failed {@see SendResult}
	 * instead so the runner can record the outcome and retry/dead-letter it.
	 */
	public function execute( ActionContext $context ): SendResult;
}
