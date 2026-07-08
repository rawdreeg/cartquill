<?php
/**
 * The CartQuill admin-menu icon.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The cartquill.com brand mark (quill + box) as a base64 SVG data URI — the
 * form `add_menu_page()` accepts and wp-admin's svg-painter recolors to match
 * the active admin color scheme.
 */
final class MenuIcon {

	/**
	 * The brand mark. Same path as the marketing site's icon; the neutral fill
	 * is a placeholder svg-painter repaints per admin color scheme.
	 */
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="#a7aaad" d="M375.344 237.813C355.281 265.625 338 285.25 327.688 288c-41 11-95.313-24.375-119.688 0-36 36-48 96-48 96l-56.313 32C169.156 219.594 315.594 65.469 512 0c0 0-8.688 30-32 70.469-19.75 5.219-40.969 9.344-59.344 9.344 8.281 9.25 23.25 17.75 40.094 23.75a3535 3535 0 0 1-17 28.5c-22.125 6.313-46.906 11.75-68.063 11.75 9.125 10.188 26.344 19.406 45.25 25.406-3.625 5.813-7.281 11.531-10.906 17.188-26.375 9.313-66.625 21.406-98.344 21.406 12.032 13.437 38.094 25.25 63.657 30M384 288v160H64V128h176l64-64H0v448h448V208z"/></svg>';

	public static function data_uri(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( self::SVG ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI, not obfuscation.
	}
}
