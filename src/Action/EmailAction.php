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

use CartQuill\Builder\ConfigFields;
use CartQuill\Builder\DescribesConfig;
use CartQuill\Engine\MessageComposer;
use CartQuill\Model\SendResult;
use CartQuill\Sender\SenderInterface;

/**
 * Wraps the original step pipeline's compose -> SenderInterface::send() path
 * exactly, so `wp_mail` sending, the unsubscribe footer + List-Unsubscribe
 * header, tracking pixel + wrapped links, and last-touch attribution keep
 * working unchanged now that a step runs a typed action.
 *
 * It is customer-facing (suppression is checked before it runs) and records the
 * active sender's key against the message row.
 */
final class EmailAction implements ActionInterface, DescribesConfig {

	use ConfigFields;

	public const TYPE = 'email';

	public function __construct(
		private readonly MessageComposer $composer,
		private readonly SenderInterface $sender,
	) {}

	/**
	 * The subject + body the builder edits, matching the keys the step pipeline
	 * composes from (a step's top-level subject/body, mirrored into config).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function config_fields(): array {
		return array(
			self::config_field( 'subject', 'Subject', 'text', array( 'required' => true, 'help' => 'The email subject line.', 'merge' => true ) ),
			self::config_field( 'body', 'Body', 'html', array( 'required' => true, 'help' => 'The email body; HTML is allowed.', 'merge' => true ) ),
		);
	}

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
