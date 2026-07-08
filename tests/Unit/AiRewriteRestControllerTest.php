<?php
/**
 * The AI rewrite REST endpoint: it varies a piece of copy through the proxy for the
 * builder, gated by the AI plan, the rate limiter, and the external-service disclosure.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Ai\AiDisclosure;
use CartQuill\Ai\AiFlowGenerator;
use CartQuill\Ai\AiRewriteRestController;
use CartQuill\Ai\ArrayRateLimiter;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Tests\Fake\StubProxyClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AiRewriteRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function controller(
		array $plans = array( Plans::AI ),
		bool $acknowledged = true,
		bool $proxy_fails = false
	): AiRewriteRestController {
		Functions\when( 'get_option' )->justReturn( $acknowledged );

		$proxy = new StubProxyClient();
		if ( $proxy_fails ) {
			$proxy->fail();
		}
		$generator = new AiFlowGenerator(
			$proxy,
			new InMemoryFlowRepository(),
			new ArrayRateLimiter( 5 ),
			new ArrayLicense( $plans ),
		);

		return new AiRewriteRestController( $generator, new AiDisclosure() );
	}

	public function test_rewrites_copy_when_licensed_and_acknowledged(): void {
		$result = $this->controller()->rewrite_copy(
			array( 'copy' => 'Hello there', 'instruction' => 'shorter' )
		);

		$this->assertSame( 200, $result['status'] );
		// StubProxyClient returns "{instruction}: {copy}".
		$this->assertSame( 'shorter: Hello there', $result['copy'] );
	}

	public function test_rejects_blank_copy_without_calling_the_proxy(): void {
		$result = $this->controller()->rewrite_copy(
			array( 'copy' => '   ', 'instruction' => 'shorter' )
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'empty', $result['code'] );
	}

	public function test_requires_the_disclosure_before_rewriting(): void {
		$result = $this->controller( acknowledged: false )->rewrite_copy(
			array( 'copy' => 'Hello', 'instruction' => 'warmer' )
		);

		$this->assertSame( 403, $result['status'] );
		$this->assertSame( 'disclosure', $result['code'] );
		$this->assertArrayHasKey( 'disclosure', $result );
		$this->assertSame(
			AiDisclosure::TERMS_URL,
			$result['disclosure']['terms_url']
		);
		$this->assertNotSame( '', $result['disclosure']['summary'] );
	}

	public function test_acknowledging_inline_proceeds_with_the_rewrite(): void {
		$result = $this->controller( acknowledged: false )->rewrite_copy(
			array(
				'copy'        => 'Hello',
				'instruction' => 'warmer',
				'acknowledge' => true,
			)
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'warmer: Hello', $result['copy'] );
	}

	public function test_unavailable_without_the_ai_plan(): void {
		$result = $this->controller( plans: array() )->rewrite_copy(
			array( 'copy' => 'Hello', 'instruction' => 'x' )
		);

		$this->assertSame( 422, $result['status'] );
		$this->assertSame( 'unavailable', $result['code'] );
	}

	public function test_unavailable_when_the_proxy_fails(): void {
		$result = $this->controller( proxy_fails: true )->rewrite_copy(
			array( 'copy' => 'Hello', 'instruction' => 'x' )
		);

		$this->assertSame( 422, $result['status'] );
		$this->assertSame( 'unavailable', $result['code'] );
	}
}
