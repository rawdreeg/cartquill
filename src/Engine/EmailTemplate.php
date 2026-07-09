<?php
/**
 * The branded HTML shell wrapped around every flow email.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Wraps the rendered step content in a self-contained, responsive, table-based
 * HTML email — the layout email clients actually render (inline styles, no
 * external CSS, no <style>-dependent classes). The store name brands the
 * header; the compliance unsubscribe footer sits below the content.
 *
 * Kept a pure string transform with no WordPress dependency so it is testable
 * DB-free and cannot mangle the trusted, already-rendered content it wraps
 * (content and footer pass through verbatim; only the store-name string is
 * HTML-escaped). A store can replace the whole shell via the
 * `cartquill_email_template` filter when WordPress is loaded.
 */
final class EmailTemplate {

	private const BRAND = '#4d65f1';

	/**
	 * @param string $content   Rendered, trusted email body HTML.
	 * @param string $footer    The unsubscribe footer HTML.
	 * @param string $store_name From-name used to brand the header.
	 */
	public function wrap( string $content, string $footer, string $store_name ): string {
		$name   = htmlspecialchars( $store_name, ENT_QUOTES, 'UTF-8' );
		$header = '' !== $name
			? '<td style="background:' . self::BRAND . ';padding:24px 28px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:18px;font-weight:700;color:#ffffff;">' . $name . '</td>'
			: '<td style="background:' . self::BRAND . ';padding:6px 28px;"></td>';

		$html = '<!DOCTYPE html>'
			. '<html lang="en" xmlns="http://www.w3.org/1999/xhtml">'
			. '<head><meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge" /></head>'
			. '<body style="margin:0;padding:0;background:#f4f5f7;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">'
			. '<tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7ec;">'
			. '<tr>' . $header . '</tr>'
			. '<tr><td style="padding:32px 28px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1e1e2f;">'
			. $content
			. '</td></tr>'
			. '<tr><td style="padding:20px 28px;border-top:1px solid #eeeeee;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
			. $footer
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the fully wrapped email HTML, so a store can supply its own
			 * shell. Receives the rendered content, footer, and store name.
			 *
			 * @param string $html       The default branded email HTML.
			 * @param string $content    The rendered body content.
			 * @param string $footer     The unsubscribe footer HTML.
			 * @param string $store_name The from-name.
			 */
			$html = (string) \apply_filters( 'cartquill_email_template', $html, $content, $footer, $store_name );
		}

		return $html;
	}
}
