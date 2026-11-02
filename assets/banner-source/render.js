// Renders banner.html to the WordPress.org banner sizes (1544x500 and 772x250).
// Dev only (excluded from releases by .distignore). From the plugin root:
//   node assets/banner-source/render.js v2/tools/node_modules/playwright-core assets/images
const path = require( 'path' );
// Both arguments are relative to the current directory, not to this file.
const { chromium } = require( path.resolve( process.argv[ 2 ] ) );

( async () => {
	const browser = await chromium.launch();
	const out = path.resolve( process.argv[ 3 ] );
	const url = 'file://' + path.join( __dirname, 'banner.html' );

	for ( const scale of [ 1, 0.5 ] ) {
		const page = await browser.newPage( {
			viewport: { width: 1544, height: 500 },
			deviceScaleFactor: scale,
		} );
		await page.goto( url );
		await page.evaluate( () => document.fonts.ready );
		const name = scale === 1 ? 'banner-1544x500' : 'banner-772x250';
		await page.screenshot( { path: path.join( out, name + '.png' ) } );
		await page.close();
	}

	await browser.close();
} )();
