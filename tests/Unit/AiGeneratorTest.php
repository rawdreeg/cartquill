<?php
/**
 * AI Flow Generation: license/rate gating, draft-only output, and the guarantee
 * that generated flows never auto-send.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Ai\AiFlowGenerator;
use FlowForge\Ai\ArrayRateLimiter;
use FlowForge\Ai\GenerationResult;
use FlowForge\Engine\Enroller;
use FlowForge\Licensing\ArrayLicense;
use FlowForge\Licensing\Plans;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Support\FixedClock;
use FlowForge\Tests\Fake\StubProxyClient;
use PHPUnit\Framework\TestCase;

final class AiGeneratorTest extends TestCase {

	/** @var list<array{delay: int, subject: string, body: string, conditions?: array<int, mixed>}> */
	private const STEPS = array(
		array( 'delay' => 0, 'subject' => 'You left something behind', 'body' => 'Come back' ),
		array( 'delay' => 86400, 'subject' => 'Still thinking it over?', 'body' => 'Here is 10% off' ),
	);

	private function generator(
		StubProxyClient $proxy,
		array $plans = array( Plans::AI ),
		int $rate_limit = 5
	): array {
		$flows     = new InMemoryFlowRepository();
		$generator = new AiFlowGenerator(
			$proxy,
			$flows,
			new ArrayRateLimiter( $rate_limit ),
			new ArrayLicense( $plans ),
		);
		return array( $generator, $flows );
	}

	public function test_generate_persists_an_ordered_draft_ai_flow(): void {
		$proxy               = new StubProxyClient( self::STEPS );
		[ $generator, $flows ] = $this->generator( $proxy );

		$result = $generator->generate( 'abandoned_cart', array( 'store_name' => 'Acme' ) );

		$this->assertTrue( $result->is_ok() );
		$flow = $result->flow;
		$this->assertInstanceOf( FlowRecord::class, $flow );
		$this->assertSame( FlowRecord::STATUS_DRAFT, $flow->status, 'AI output must be a draft' );
		$this->assertSame( FlowRecord::SOURCE_AI, $flow->source );
		$this->assertSame( 'abandoned_cart', $flow->type );
		$this->assertCount( 2, $flow->steps );
		$this->assertSame( 'You left something behind', $flow->steps[0]->subject );
		$this->assertSame( 86400, $flow->steps[1]->delay, 'step order and delays are preserved' );
		$this->assertNotNull( $flows->find( (int) $flow->id ), 'flow is saved for the editor' );
		$this->assertSame( array( 'abandoned_cart' ), array_column( $proxy->generate_calls, 'flow_type' ) );
	}

	public function test_generated_flow_never_auto_sends(): void {
		[ $generator ] = $this->generator( new StubProxyClient( self::STEPS ) );
		$flow          = $generator->generate( 'abandoned_cart', array() )->flow;

		$enroller = new Enroller(
			new InMemoryEnrollmentRepository(),
			new ArrayScheduler(),
			new FixedClock( 1_700_000_000 ),
		);

		$this->assertNotNull( $flow );
		$this->assertNull(
			$enroller->enroll( $flow, 'buyer@example.com' ),
			'a draft AI flow is not enrollable, so it can never send'
		);
	}

	public function test_generate_is_not_licensed_without_ai_plan(): void {
		$proxy               = new StubProxyClient( self::STEPS );
		[ $generator, $flows ] = $this->generator( $proxy, array() );

		$result = $generator->generate( 'abandoned_cart', array() );

		$this->assertSame( GenerationResult::NOT_LICENSED, $result->status );
		$this->assertNull( $result->flow );
		$this->assertCount( 0, $flows->all(), 'nothing persisted when unlicensed' );
		$this->assertCount( 0, $proxy->generate_calls, 'proxy is not called when unlicensed' );
	}

	public function test_generate_is_rate_limited_when_allowance_exhausted(): void {
		$proxy               = new StubProxyClient( self::STEPS );
		[ $generator ]       = $this->generator( $proxy, array( Plans::AI ), 1 );

		$this->assertTrue( $generator->generate( 'abandoned_cart', array() )->is_ok() );
		$second = $generator->generate( 'abandoned_cart', array() );

		$this->assertSame( GenerationResult::RATE_LIMITED, $second->status );
		$this->assertCount( 1, $proxy->generate_calls, 'proxy not called once the limit is hit' );
	}

	public function test_generate_degrades_gracefully_on_proxy_failure(): void {
		$proxy = new StubProxyClient( self::STEPS );
		$proxy->fail();
		[ $generator, $flows ] = $this->generator( $proxy );

		$result = $generator->generate( 'abandoned_cart', array() );

		$this->assertSame( GenerationResult::ERROR, $result->status );
		$this->assertNull( $result->flow );
		$this->assertCount( 0, $flows->all() );
	}

	public function test_rewrite_varies_copy_when_licensed(): void {
		[ $generator ] = $this->generator( new StubProxyClient() );

		$rewritten = $generator->rewrite( 'Buy now', 'make it friendlier' );

		$this->assertSame( 'make it friendlier: Buy now', $rewritten );
	}

	public function test_rewrite_returns_null_without_license(): void {
		[ $generator ] = $this->generator( new StubProxyClient(), array() );

		$this->assertNull( $generator->rewrite( 'Buy now', 'make it friendlier' ) );
	}
}
