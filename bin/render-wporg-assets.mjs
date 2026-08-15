#!/usr/bin/env node
/**
 * Render the WordPress.org directory artwork into .wordpress-org/.
 *
 *   npm install --no-save sharp && node bin/render-wporg-assets.mjs
 *
 * `sharp` is not a project dependency — this runs by hand when the brand mark or
 * the banner copy changes, not on every build. Nothing here ships: `bin/` and
 * `.wordpress-org/` are both stripped by .distignore, and the rendered files are
 * committed so a release never has to rasterise anything.
 *
 * The mark is read from assets/admin/icon.svg so there is one source of truth
 * for it; this script writes the directory's copy rather than it being kept in
 * sync by hand. The directory requires a PNG fallback alongside an SVG icon.
 */
import sharp from 'sharp';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, '.wordpress-org');

// Two sub-paths: the quill feather, then the box frame. Kept apart so the banner
// can use the feather alone as a watermark — the frame's straight edges read as
// a panel seam at low opacity.
const QUILL =
  'M375.344 237.813C355.281 265.625 338 285.25 327.688 288c-41 11-95.313-24.375-119.688 0-36 36-48 96-48 96l-56.313 32C169.156 219.594 315.594 65.469 512 0c0 0-8.688 30-32 70.469-19.75 5.219-40.969 9.344-59.344 9.344 8.281 9.25 23.25 17.75 40.094 23.75a3535 3535 0 0 1-17 28.5c-22.125 6.313-46.906 11.75-68.063 11.75 9.125 10.188 26.344 19.406 45.25 25.406-3.625 5.813-7.281 11.531-10.906 17.188-26.375 9.313-66.625 21.406-98.344 21.406 12.032 13.437 38.094 25.25 63.657 30';
const BOX = 'M384 288v160H64V128h176l64-64H0v448h448V208z';
const MARK = QUILL + BOX;

const W = 1544;
const H = 500;

const banner = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#20203a"/>
      <stop offset="0.55" stop-color="#1a1a2b"/>
      <stop offset="1" stop-color="#121220"/>
    </linearGradient>
    <radialGradient id="glow" cx="0.22" cy="0.5" r="0.62">
      <stop offset="0" stop-color="#4D65F1" stop-opacity="0.30"/>
      <stop offset="1" stop-color="#4D65F1" stop-opacity="0"/>
    </radialGradient>
    <linearGradient id="rule" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#4D65F1"/>
      <stop offset="1" stop-color="#4D65F1" stop-opacity="0"/>
    </linearGradient>
  </defs>

  <rect width="${W}" height="${H}" fill="url(#bg)"/>
  <rect width="${W}" height="${H}" fill="url(#glow)"/>

  <g transform="translate(968 -96) scale(1.42)" opacity="0.085">
    <path d="${QUILL}" fill="#93A3FF"/>
  </g>

  <g transform="translate(112 148) scale(0.335)">
    <path d="${MARK}" fill="#5C73F5"/>
  </g>

  <text x="332" y="226" font-family="Avenir Next, Helvetica Neue, sans-serif"
        font-size="96" font-weight="600" fill="#ffffff" letter-spacing="-1.5">CartQuill</text>

  <rect x="334" y="250" width="86" height="4" rx="2" fill="url(#rule)"/>

  <text x="332" y="302" font-family="Avenir Next, Helvetica Neue, sans-serif"
        font-size="33" font-weight="400" fill="#A7AFD4" letter-spacing="0.2">Automated WooCommerce email flows</text>

  <text x="332" y="354" font-family="Avenir Next, Helvetica Neue, sans-serif"
        font-size="26" font-weight="400" fill="#7A83AC" letter-spacing="0.6">Abandoned cart&#160;&#160;·&#160;&#160;Welcome&#160;&#160;·&#160;&#160;Win-back&#160;&#160;·&#160;&#160;Revenue per flow</text>
</svg>`;

// Icon: copy the plugin's own mark over, then rasterise the fallbacks. Rendered
// at 4x and downsampled — the feather's thin strokes alias badly if librsvg
// rasterises straight to 128px.
const icon = readFileSync(join(ROOT, 'assets', 'admin', 'icon.svg'));
writeFileSync(join(OUT, 'icon.svg'), icon);

for (const size of [128, 256]) {
  await sharp(icon, { density: 384 })
    .resize(size * 4, size * 4, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .resize(size, size, { kernel: 'lanczos3' })
    .png({ compressionLevel: 9 })
    .toFile(join(OUT, `icon-${size}x${size}.png`));
}

// One rasterisation, downsampled for the 1x file, so the two banners are the
// same image rather than two independent renders.
await sharp(Buffer.from(banner)).png({ compressionLevel: 9 }).toFile(join(OUT, 'banner-1544x500.png'));
await sharp(Buffer.from(banner))
  .resize(772, 250, { kernel: 'lanczos3' })
  .png({ compressionLevel: 9 })
  .toFile(join(OUT, 'banner-772x250.png'));

for (const f of ['icon-128x128.png', 'icon-256x256.png', 'banner-772x250.png', 'banner-1544x500.png']) {
  const m = await sharp(join(OUT, f)).metadata();
  console.log(`${f} -> ${m.width}x${m.height}`);
}
