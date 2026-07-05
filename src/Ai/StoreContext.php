<?php
/**
 * Gathers representative store/catalog data sent to the AI proxy.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The AI proxy drafts better copy when it knows what the store sells, so this
 * assembles a small, representative snapshot — store name, tone, currency, and a
 * few top product names. Kept in sync with the readme "External services"
 * disclosure of what is transmitted.
 */
final class StoreContext {

	/**
	 * @return array<string, mixed>
	 */
	public static function gather( string $tone = '' ): array {
		return array(
			'store_name'   => \get_bloginfo( 'name' ),
			'tone'         => $tone,
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? (string) \get_woocommerce_currency() : '',
			'top_products' => self::top_product_names(),
		);
	}

	/**
	 * @return list<string>
	 */
	private static function top_product_names(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$names = array();
		$products = \wc_get_products(
			array(
				'limit'   => 5,
				'status'  => 'publish',
				'orderby' => 'popularity',
				'return'  => 'objects',
			)
		);
		foreach ( (array) $products as $product ) {
			if ( is_object( $product ) && method_exists( $product, 'get_name' ) ) {
				$names[] = (string) $product->get_name();
			}
		}
		return $names;
	}
}
