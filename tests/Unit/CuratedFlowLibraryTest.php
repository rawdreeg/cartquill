<?php
/**
 * The curated AI starter-template library: every variant is well-formed, obeys
 * the engine's timing/condition conventions, and renders cleanly.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Ai\CuratedFlowLibrary;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Flow\Renderer;
use PHPUnit\Framework\TestCase;

final class CuratedFlowLibraryTest extends TestCase {

	private const EXIT_TYPES     = array( DefaultFlows::TYPE_ABANDONED_CART, DefaultFlows::TYPE_WIN_BACK );
	private const NO_EXIT_TYPES  = array( DefaultFlows::TYPE_WELCOME, DefaultFlows::TYPE_POST_PURCHASE );

	/**
	 * @return list<string>
	 */
	private function flow_types(): array {
		return array_merge( self::EXIT_TYPES, self::NO_EXIT_TYPES );
	}

	public function test_every_flow_type_ships_at_least_two_distinct_variants(): void {
		foreach ( $this->flow_types() as $type ) {
			$variants = CuratedFlowLibrary::variants( $type );
			$this->assertGreaterThanOrEqual( 2, count( $variants ), "{$type} should offer a real library" );

			// Variants must genuinely differ, not be copies of one another.
			$serialized = array_map( 'serialize', $variants );
			$this->assertSame(
				count( $serialized ),
				count( array_unique( $serialized ) ),
				"{$type} variants must be distinct"
			);
		}
	}

	public function test_unknown_flow_type_has_no_curated_content(): void {
		$this->assertSame( array(), CuratedFlowLibrary::variants( 'browse_abandonment' ) );
	}

	public function test_the_first_variant_is_the_per_type_default_seed(): void {
		$this->assertSame( CuratedFlowLibrary::abandoned_cart(), CuratedFlowLibrary::variants( DefaultFlows::TYPE_ABANDONED_CART )[0] );
		$this->assertSame( CuratedFlowLibrary::welcome(), CuratedFlowLibrary::variants( DefaultFlows::TYPE_WELCOME )[0] );
		$this->assertSame( CuratedFlowLibrary::post_purchase(), CuratedFlowLibrary::variants( DefaultFlows::TYPE_POST_PURCHASE )[0] );
		$this->assertSame( CuratedFlowLibrary::win_back(), CuratedFlowLibrary::variants( DefaultFlows::TYPE_WIN_BACK )[0] );
	}

	public function test_every_step_has_non_empty_subject_and_body(): void {
		foreach ( $this->flow_types() as $type ) {
			foreach ( CuratedFlowLibrary::variants( $type ) as $vi => $variant ) {
				$this->assertNotEmpty( $variant, "{$type} variant {$vi} must have steps" );
				foreach ( $variant as $si => $step ) {
					$this->assertNotSame( '', trim( $step['subject'] ), "{$type} v{$vi} step {$si} subject" );
					$this->assertNotSame( '', trim( $step['body'] ), "{$type} v{$vi} step {$si} body" );
				}
			}
		}
	}

	public function test_delays_are_non_negative_and_ascending_within_each_variant(): void {
		foreach ( $this->flow_types() as $type ) {
			foreach ( CuratedFlowLibrary::variants( $type ) as $vi => $variant ) {
				$previous = -1;
				foreach ( $variant as $si => $step ) {
					$this->assertGreaterThanOrEqual( 0, $step['delay'], "{$type} v{$vi} step {$si} delay non-negative" );
					$this->assertGreaterThanOrEqual( $previous, $step['delay'], "{$type} v{$vi} step {$si} delay ascending" );
					$previous = $step['delay'];
				}
			}
		}
	}

	public function test_exit_on_order_flows_carry_the_condition_on_every_step(): void {
		foreach ( self::EXIT_TYPES as $type ) {
			foreach ( CuratedFlowLibrary::variants( $type ) as $vi => $variant ) {
				foreach ( $variant as $si => $step ) {
					$this->assertSame(
						array( array( 'type' => 'exit_if_ordered' ) ),
						$step['conditions'],
						"{$type} v{$vi} step {$si} must exit on order"
					);
				}
			}
		}
	}

	public function test_always_complete_flows_carry_no_conditions(): void {
		foreach ( self::NO_EXIT_TYPES as $type ) {
			foreach ( CuratedFlowLibrary::variants( $type ) as $vi => $variant ) {
				foreach ( $variant as $si => $step ) {
					$this->assertSame( array(), $step['conditions'], "{$type} v{$vi} step {$si} carries no conditions" );
				}
			}
		}
	}

	public function test_bodies_only_use_the_store_name_merge_tag(): void {
		foreach ( $this->flow_types() as $type ) {
			foreach ( CuratedFlowLibrary::variants( $type ) as $vi => $variant ) {
				foreach ( $variant as $si => $step ) {
					preg_match_all( '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $step['subject'] . ' ' . $step['body'], $matches );
					$tags = array_unique( $matches[1] );
					$unexpected = array_diff( $tags, array( 'store_name' ) );
					$this->assertSame( array(), array_values( $unexpected ), "{$type} v{$vi} step {$si} uses only {{ store_name }}" );
				}
			}
		}
	}

	public function test_every_variant_renders_cleanly_with_no_escaping_regressions(): void {
		$renderer = new Renderer();
		$context  = array( 'store_name' => "Ben & Jerry's <shop>" );

		foreach ( $this->flow_types() as $type ) {
			foreach ( CuratedFlowLibrary::variants( $type ) as $vi => $variant ) {
				foreach ( $variant as $si => $step ) {
					$body = $renderer->render( $step['body'], $context );

					$this->assertStringNotContainsString( '{{', $body, "{$type} v{$vi} step {$si}: no unresolved merge tag leaks" );
					// A hostile store name is HTML-escaped, never injected raw.
					$this->assertStringNotContainsString( '<shop>', $body, "{$type} v{$vi} step {$si}: merge value is escaped" );
					if ( str_contains( $step['body'], '{{ store_name }}' ) ) {
						$this->assertStringContainsString( 'Ben &amp; Jerry&#039;s', $body, "{$type} v{$vi} step {$si}: store name rendered escaped" );
					}
				}
			}
		}
	}
}
