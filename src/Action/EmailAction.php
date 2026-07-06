<?php
/**
 * The core action: compose an email and send it through the sending seam.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Action;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Engine\MessageComposer;
use CartQuill\Model\SendResult;
use CartQuill\Sender\SenderInterface;

/**
 * Wraps the original step pipeline's compose -> SenderInterface::send() path
 * exactly, so `wp_mail`/Resend sending, the unsubscribe footer + List-Unsubscribe
 * header, tracking pixel + wrapped links, and last-touch attribution keep
 * working unchanged now that a step runs a typed action.
 *
 * It is customer-facing (suppression is checked before it runs) and records the
 * active sender's key so ESP webhooks can correlate lifecycle events back to it.
 */
final class EmailAction implements ActionInterface {

	public const TYPE = 'email';

	public function __construct(
		private readonly MessageComposer $composer,
		private readonly SenderInterface $sender,
	) {}

	public function type(): string {
		return self::TYPE;
	}

	public function sender_key(): string {
		return $this->sender->key();
	}

	public function is_customer_facing(): bool {
		return true;
	}

	public function target( ActionContext $context ): ?string {
		return $context->customer_email;
	}

	public function execute( ActionContext $context ): SendResult {
		$message = $this->composer->compose(
			$context->step,
			$context->customer_email,
			$context->flow_id,
			$context->step_index,
			$context->enrollment_id,
			$context->message_id,
		);

		return $this->sender->send( $message );
	}
}
