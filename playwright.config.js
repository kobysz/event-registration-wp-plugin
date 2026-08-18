const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 60000,
	use: {
		baseURL: 'http://localhost:8891',
		headless: true,
	},
	reporter: 'line',
} );
