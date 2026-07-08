/**
 * Extends the @wordpress/scripts default webpack config to build the flow builder
 * from assets/builder/src into assets/builder/build (kept out of the root build/
 * dir the packaging script uses for staging).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'assets', 'builder', 'src', 'index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets', 'builder', 'build' ),
	},
};
