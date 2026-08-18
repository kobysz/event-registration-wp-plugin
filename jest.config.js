const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	testPathIgnorePatterns: [ '/node_modules/', '/vendor/', '/build/', '/tests/e2e/' ],
};
