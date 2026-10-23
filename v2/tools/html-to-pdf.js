#!/usr/bin/env node
/**
 * Renders an HTML report to PDF with the cached Chromium (development only).
 *
 *   node v2/tools/html-to-pdf.js v2/code-review/code-review.html v2/code-review.pdf
 */
'use strict';

const { chromium } = require( './node_modules/playwright-core' );
const os = require( 'os' );
const path = require( 'path' );

const CHROME = process.env.CTGEN_CHROME || path.join( os.homedir(), '.cache/ms-playwright/chromium-1140/chrome-linux/chrome' );
const [ input, output ] = process.argv.slice( 2 );

if ( ! input || ! output ) {
	console.error( 'Usage: node html-to-pdf.js <input.html> <output.pdf>' );
	process.exit( 1 );
}

( async () => {
	const browser = await chromium.launch( { executablePath: CHROME } );
	try {
		const page = await browser.newPage();
		// No network: the report is self-contained.
		await page.route( '**/*', ( route ) => ( route.request().url().startsWith( 'file:' ) ? route.continue() : route.abort() ) );
		await page.goto( 'file://' + path.resolve( input ), { waitUntil: 'load' } );
		await page.pdf( {
			path: path.resolve( output ),
			format: 'A4',
			printBackground: true,
			preferCSSPageSize: true,
			displayHeaderFooter: true,
			headerTemplate: '<div></div>',
			footerTemplate: '<div style="width:100%;font:8px Arial,sans-serif;color:#646970;padding:0 15mm;display:flex;justify-content:space-between;"><span>Custom Thumbnail Generator 1.1 - Code Review and Security Audit</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>',
		} );
		console.log( 'Wrote ' + path.resolve( output ) );
	} finally {
		await browser.close();
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
