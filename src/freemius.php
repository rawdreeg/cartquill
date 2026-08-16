<?php
/**
 * Freemius SDK bootstrap.
 *
 * Exposes cartquill_fs(): the shared Freemius SDK instance, or null when the SDK
 * is absent. This whole file lives below the `# @cartquill:paid` marker in
 * .distignore, so it exists ONLY in the premium build — the WordPress.org core
 * package contains neither it nor the vendored SDK, which is what makes core
 * provably free of any licence check.
 *
 * That fact is load-bearing twice over. It is why CARTQUILL_FS_IS_PREMIUM can
 * simply default to true below (a build running this file *is* the premium
 * build), and it is why cartquill_fs_owns_plan() can treat the premium edition
 * as Freemius-governed without asking the SDK anything.
 *
 * No secret_key ever lives in plugin code — Freemius holds it server-side.
 *
 * @package CartQuill
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// Declared at file scope, not inside cartquill_fs(), so it is true from the
// moment this file is included — cartquill_fs_owns_plan() must not depend on
// anything having called the bootstrap first. wp-config.php can still force it
// false to exercise the free-edition code paths against a premium checkout.
if ( ! defined( 'CARTQUILL_FS_IS_PREMIUM' ) ) {
	define( 'CARTQUILL_FS_IS_PREMIUM', true );
}

if ( ! function_exists( 'cartquill_fs' ) ) {
	/**
	 * The shared Freemius SDK instance, or null when the SDK is absent or failed
	 * to initialize.
	 *
	 * @return \Freemius|null
	 */
	function cartquill_fs() {
		global $cartquill_fs;

		if ( isset( $cartquill_fs ) ) {
			return $cartquill_fs;
		}

		// The SDK is vendored at <plugin>/freemius/ and ships with this edition.
		// Resolving it defensively rather than assuming it: a hand-assembled zip
		// missing the directory should degrade to "no licensing", not fatal.
		$sdk_start = ( defined( 'CARTQUILL_PATH' ) ? CARTQUILL_PATH : \plugin_dir_path( __DIR__ ) ) . 'freemius/start.php';

		// CartQuill product identifiers from the Freemius dashboard. Both are PUBLIC and
		// are meant to ship inside the distributed plugin. Wrapped in defined() so a site
		// can override them from wp-config.php. NEVER define a secret_key here — Freemius
		// holds it server-side and it must be stripped from every distributed build.
		if ( ! defined( 'CARTQUILL_FS_ID' ) ) {
			define( 'CARTQUILL_FS_ID', 33952 );
		}
		if ( ! defined( 'CARTQUILL_FS_PUBLIC_KEY' ) ) {
			define( 'CARTQUILL_FS_PUBLIC_KEY', 'pk_cae3e8510b486523ff362ce76e657' );
		}

		if ( ! is_readable( $sdk_start ) ) {
			$cartquill_fs = null;
			return $cartquill_fs;
		}

		require_once $sdk_start;

		try {
			$cartquill_fs = fs_dynamic_init(
				array(
					'id'               => CARTQUILL_FS_ID,
					'slug'             => 'cartquill',
					// The folder the premium edition installs into. bin/build.sh
					// packages exactly this name and bin/verify-package.sh asserts
					// the two agree — see the comment there for why it matters.
					'premium_slug'     => 'cartquill-premium',
					'type'             => 'plugin',
					'public_key'       => CARTQUILL_FS_PUBLIC_KEY,
					'is_premium'       => CARTQUILL_FS_IS_PREMIUM,
					'is_premium_only'  => false,
					'has_addons'       => false,
					'has_paid_plans'   => true,
					'is_org_compliant' => true,
					'menu'             => array(
						'slug'    => 'cartquill',
						'account' => true,
						'contact' => false,
						'support' => false,
					),
				)
			);
		} catch ( \Throwable $e ) {
			// A store's automations are worth more than its upgrade prompt: if the
			// SDK cannot start, run on without it rather than white-screening the
			// site. cartquill_fs_owns_plan() stays true regardless, so this
			// degrades to "no paid capabilities", never to "everything unlocked".
			$cartquill_fs = null;
			return $cartquill_fs;
		}

		/**
		 * Signal that the Freemius SDK is loaded and initialized.
		 */
		\do_action( 'cartquill_fs_loaded' );

		return $cartquill_fs;
	}
}

if ( ! function_exists( 'cartquill_fs_owns_plan' ) ) {
	/**
	 * Whether Freemius is the authority on which plan this store holds.
	 *
	 * True for every premium build. Note what this deliberately does NOT depend
	 * on: whether the SDK actually loaded. Keying off the live SDK instance would
	 * mean deleting `freemius/` from a copied zip hands the local key store back
	 * its authority — turning the admin license form into a one-field unlock. The
	 * question this answers is "is this the paid edition", which the presence of
	 * this file already settles.
	 *
	 * The single escape hatch is a wp-config.php constant, not an admin setting:
	 * developing and demoing the add-ons needs the local key store, and a site
	 * owner editing wp-config.php can edit the plugin's PHP anyway.
	 */
	function cartquill_fs_owns_plan(): bool {
		if ( defined( 'CARTQUILL_LOCAL_LICENSE' ) && CARTQUILL_LOCAL_LICENSE ) {
			return false;
		}
		return defined( 'CARTQUILL_FS_IS_PREMIUM' ) && CARTQUILL_FS_IS_PREMIUM;
	}
}
