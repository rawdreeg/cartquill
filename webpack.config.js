/**
 * Extends the @wordpress/scripts default webpack config to build the flow builder
 * from assets/builder/src into assets/builder/build (kept out of the root build/
 * dir the packaging script uses for staging).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const src = path.resolve( __dirname, 'assets', 'builder', 'src' );

// The builder itself always builds. The `ai` entry belongs to the separately
// distributed AI add-on, so it is built only when that add-on's source is present
// — which keeps the plugin's own bundle free of add-on code and, because the
// package is staged before this runs, keeps the shipped bundle an exact build of
// the shipped source.
const entry = { index: path.join( src, 'index.js' ) };
const aiEntry = path.join( src, 'ai', 'index.js' );
if ( fs.existsSync( aiEntry ) ) {
	entry.ai = aiEntry;
}

module.exports = {
	...defaultConfig,
	entry,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets', 'builder', 'build' ),
	},
};
