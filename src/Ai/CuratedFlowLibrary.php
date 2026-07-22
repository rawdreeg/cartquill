<?php
/**
 * The curated, vetted starter-template library for AI Flow Generation.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\DefaultFlows;

/**
 * The moat behind the AI add-on: hand-authored, compliance-clean starter
 * templates the proxy personalizes rather than inventing copy from a one-line
 * hint. Each flow type ships two genuinely distinct variants (a small "library"),
 * so a generation seeds Claude with real, high-converting copy to adapt.
 *
 * Unlike {@see DefaultFlows} — which returns free-core `FlowRecord`/`FlowStep`
 * objects for direct activation — this returns plain step arrays shaped exactly
 * like the {@see ProxyClient::generate()} contract, because the data travels over
 * HTTP as the `seed_steps` payload for the proxy call.
 *
 * Conventions match {@see DefaultFlows}: delays are seconds from enrollment start
 * (3600=1h, 86400=1d, 259200=3d, 604800=7d, 1209600=14d), only the
 * `{{ store_name }}` merge tag is used, bodies are simple `<p>` HTML, and
 * `abandoned_cart`/`win_back` carry `exit_if_ordered` on every step while
 * `welcome`/`post_purchase` carry none.
 *
 * @phpstan-type CuratedStep array{delay: int, subject: string, body: string, conditions: array<int, array<string, string>>}
 */
final class CuratedFlowLibrary {

	/**
	 * All curated variants for a flow type, in priority order — the first is the
	 * default seed. Empty for any flow type without curated content yet, so the
	 * generator falls through to the proxy's own guidance unchanged.
	 *
	 * @return list<list<CuratedStep>>
	 */
	public static function variants( string $flow_type ): array {
		return match ( $flow_type ) {
			DefaultFlows::TYPE_ABANDONED_CART => array( self::abandoned_cart(), self::abandoned_cart_social_proof() ),
			DefaultFlows::TYPE_WELCOME        => array( self::welcome(), self::welcome_brand_story() ),
			DefaultFlows::TYPE_POST_PURCHASE  => array( self::post_purchase(), self::post_purchase_review() ),
			DefaultFlows::TYPE_WIN_BACK       => array( self::win_back(), self::win_back_incentive() ),
			default                           => array(),
		};
	}

	/**
	 * Abandoned cart, variant 1 — the straightforward "you left this behind"
	 * nudge: a gentle t+1h reminder and a t+24h follow-up, each exiting on order.
	 *
	 * @return list<CuratedStep>
	 */
	public static function abandoned_cart(): array {
		$exit = self::exit_if_ordered();

		return array(
			array(
				'delay'      => 3600, // t+1h
				'subject'    => 'You left something behind',
				'body'       => '<p>Hi — you started an order at {{ store_name }} but did not finish checking out.</p><p>Your items are still saved. Pick up right where you left off whenever you are ready.</p>',
				'conditions' => $exit,
			),
			array(
				'delay'      => 86400, // t+24h
				'subject'    => 'Still thinking it over?',
				'body'       => '<p>Your cart at {{ store_name }} is still waiting for you.</p><p>If anything was holding you back or you have a question, just reply — we are happy to help.</p>',
				'conditions' => $exit,
			),
		);
	}

	/**
	 * Abandoned cart, variant 2 — an urgency + social-proof angle: three steps
	 * that lean on popularity and limited availability, each exiting on order.
	 *
	 * @return list<CuratedStep>
	 */
	public static function abandoned_cart_social_proof(): array {
		$exit = self::exit_if_ordered();

		return array(
			array(
				'delay'      => 3600, // t+1h
				'subject'    => 'Your cart is popular — grab it before it is gone',
				'body'       => '<p>Good taste! The items in your {{ store_name }} cart are among our most-loved picks right now.</p><p>They are still reserved for you — complete your order to lock them in.</p>',
				'conditions' => $exit,
			),
			array(
				'delay'      => 86400, // t+24h
				'subject'    => 'Shoppers love these — do not miss out',
				'body'       => '<p>Plenty of customers at {{ store_name }} have been adding these to their carts too.</p><p>Popular items can sell through quickly, so finish checking out while yours are still held.</p>',
				'conditions' => $exit,
			),
			array(
				'delay'      => 259200, // t+3d
				'subject'    => 'Last call for your saved items',
				'body'       => '<p>This is a final reminder that your {{ store_name }} cart is still saved.</p><p>We cannot hold your items much longer — complete your order today so you do not miss out.</p>',
				'conditions' => $exit,
			),
		);
	}

	/**
	 * Welcome, variant 1 — a warm hello and a t+3d getting-started email. No exit
	 * condition; a welcome sequence always runs to completion.
	 *
	 * @return list<CuratedStep>
	 */
	public static function welcome(): array {
		return array(
			array(
				'delay'      => 0, // immediate
				'subject'    => 'Welcome to {{ store_name }}',
				'body'       => '<p>Thanks for joining {{ store_name }} — we are so glad you are here.</p><p>Take a look around and let us know if there is anything we can help you find.</p>',
				'conditions' => array(),
			),
			array(
				'delay'      => 259200, // t+3d
				'subject'    => 'Getting the most out of {{ store_name }}',
				'body'       => '<p>Now that you have settled in, here are a few favourites and tips to help you get started at {{ store_name }}.</p><p>Questions? Just reply to this email — a real person will get back to you.</p>',
				'conditions' => array(),
			),
		);
	}

	/**
	 * Welcome, variant 2 — a brand-story angle: an introduction to what the store
	 * stands for, then a follow-up on the details it cares about. No exit condition.
	 *
	 * @return list<CuratedStep>
	 */
	public static function welcome_brand_story(): array {
		return array(
			array(
				'delay'      => 0, // immediate
				'subject'    => 'Hello from {{ store_name }} — here is what we are about',
				'body'       => '<p>Welcome! We started {{ store_name }} to bring you products we genuinely believe in.</p><p>Thanks for being part of it — we cannot wait to share what we have in store.</p>',
				'conditions' => array(),
			),
			array(
				'delay'      => 259200, // t+3d
				'subject'    => 'The little things we care about',
				'body'       => '<p>At {{ store_name }}, the details matter to us — from how we choose our products to how we look after every order.</p><p>Have a browse, and reach out any time. We would love to hear from you.</p>',
				'conditions' => array(),
			),
		);
	}

	/**
	 * Post-purchase, variant 1 — an immediate thank-you and a t+14d cross-sell.
	 * No exit condition; the buyer already converted.
	 *
	 * @return list<CuratedStep>
	 */
	public static function post_purchase(): array {
		return array(
			array(
				'delay'      => 0, // t+0
				'subject'    => 'Thanks for your order',
				'body'       => '<p>Thank you for shopping with {{ store_name }}! Your order is confirmed and on its way.</p><p>We will be in touch with any updates. In the meantime, sit back and relax.</p>',
				'conditions' => array(),
			),
			array(
				'delay'      => 1209600, // t+14d
				'subject'    => 'You might also like…',
				'body'       => '<p>We hope you are enjoying your recent order from {{ store_name }}.</p><p>Based on what you picked, here are a few things other customers love. Take a look whenever you are ready.</p>',
				'conditions' => array(),
			),
		);
	}

	/**
	 * Post-purchase, variant 2 — an order confirmation and a t+14d review request.
	 * No exit condition.
	 *
	 * @return list<CuratedStep>
	 */
	public static function post_purchase_review(): array {
		return array(
			array(
				'delay'      => 0, // t+0
				'subject'    => 'Your order is confirmed — thank you',
				'body'       => '<p>Thanks for your order with {{ store_name }}! We are getting it ready and will let you know the moment it ships.</p><p>Thank you for supporting us.</p>',
				'conditions' => array(),
			),
			array(
				'delay'      => 1209600, // t+14d
				'subject'    => 'How did we do?',
				'body'       => '<p>Now that you have had a chance to enjoy your order, we would love to hear what you think.</p><p>Your feedback helps {{ store_name }} — and other shoppers — so if you have a moment, we would really appreciate a quick review.</p>',
				'conditions' => array(),
			),
		);
	}

	/**
	 * Win-back, variant 1 — a warm re-engagement nudge and a t+7d follow-up, each
	 * exiting the moment the customer orders again.
	 *
	 * @return list<CuratedStep>
	 */
	public static function win_back(): array {
		$exit = self::exit_if_ordered();

		return array(
			array(
				'delay'      => 0,
				'subject'    => 'We miss you at {{ store_name }}',
				'body'       => '<p>It has been a while, and we wanted to say hello.</p><p>Whenever you are ready, {{ store_name }} is here — come see what is new.</p>',
				'conditions' => $exit,
			),
			array(
				'delay'      => 604800, // t+7d
				'subject'    => 'Still here whenever you are ready',
				'body'       => '<p>No rush — we just wanted you to know the door is always open at {{ store_name }}.</p><p>If there is anything we can help with, simply reply and we will be glad to.</p>',
				'conditions' => $exit,
			),
		);
	}

	/**
	 * Win-back, variant 2 — a re-engagement sequence that softly mentions a
	 * welcome-back offer, each step exiting the moment the customer orders again.
	 *
	 * @return list<CuratedStep>
	 */
	public static function win_back_incentive(): array {
		$exit = self::exit_if_ordered();

		return array(
			array(
				'delay'      => 0,
				'subject'    => 'It has been a while — come see what is new',
				'body'       => '<p>We noticed it has been some time since your last visit to {{ store_name }}, and we would love to welcome you back.</p><p>Plenty has changed since you were last here — take a look when you have a moment.</p>',
				'conditions' => $exit,
			),
			array(
				'delay'      => 604800, // t+7d
				'subject'    => 'A little something to welcome you back',
				'body'       => '<p>To make your return a little sweeter, {{ store_name }} has a welcome-back offer waiting for you.</p><p>Come back and take a look — we would be delighted to see you again.</p>',
				'conditions' => $exit,
			),
		);
	}

	/**
	 * The single exit condition the engine understands, matching
	 * {@see DefaultFlows}: stop the flow the moment the customer places an order.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function exit_if_ordered(): array {
		return array( array( 'type' => 'exit_if_ordered' ) );
	}
}
