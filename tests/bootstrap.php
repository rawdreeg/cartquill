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

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
