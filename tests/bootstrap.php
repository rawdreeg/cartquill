<?php
/**
 * PHPUnit bootstrap.
 *
 * Fast, DB-free test suite: the engine and senders are exercised through their
 * injected seams (FakeSender, InMemoryMessageRepository, ArraySettings). WP
 * functions, where a class calls them directly, are stubbed per-test with
 * Brain\Monkey.
 *
 * @package FlowForge
 */

declare(strict_types=1);

// The src/ files guard against direct web access with an ABSPATH check; define
// it so the DB-free suite can autoload them without tripping the guard's exit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
