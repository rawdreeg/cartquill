<?php
/**
 * PHPUnit bootstrap.
 *
 * Fast, DB-free test suite: the engine and senders are exercised through their
 * injected seams (FakeSender, InMemoryMessageRepository, ArraySettings). WP
 * functions, where a class calls them directly, are stubbed per-test with
 * Brain\Monkey.
 *
 * @package CartQuill
 */

declare(strict_types=1);

// The src/ files guard against direct web access with an ABSPATH check; define
// it so the DB-free suite can autoload them without tripping the guard's exit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// A few classes reference WordPress time constants in default values evaluated at
// class-load (e.g. AiAddon's rate-limit window), so the DB-free suite can autoload
// them.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
