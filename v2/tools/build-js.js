#!/usr/bin/env node
/**
 * Builds each assets/js/*.min.js listed in FILES from its readable source
 * (e.g. ctgen-webp-convert.js) with terser (pinned in package.json).
 *
 *   node build-js.js          regenerate the .min.js
 *   node build-js.js --check  exit 1 if the committed .min.js is not exactly what
 *                             the current source produces (source/min drift)
 *
 * Development only - lives in v2/, never shipped.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { minify } = require( 'terser' );

const ASSETS = path.resolve( __dirname, '../../assets/js' );
const FILES = [
	[ 'ctgen-webp-convert.js', 'ctgen-webp-convert.min.js' ],
	[ 'ctgen-attachment-summary.js', 'ctgen-attachment-summary.min.js' ],
	[ 'ctgen-media-actions.js', 'ctgen-media-actions.min.js' ],
];
const CHECK = process.argv.includes( '--check' );

async function build( src, out ) {
	const source = fs.readFileSync( path.join( ASSETS, src ), 'utf8' );
	const result = await minify( source, {
		compress: true,
		mangle: true,
		format: {
			comments: false,
			preamble: `/*! Generated from ${ src } by v2/tools/build-js.js - do not edit. */`,
		},
	} );
	const code = result.code + '\n';
	const target = path.join( ASSETS, out );

	if ( CHECK ) {
		const current = fs.existsSync( target ) ? fs.readFileSync( target, 'utf8' ) : '';
		if ( current !== code ) {
			console.error( `DRIFT: ${ out } does not match a fresh build of ${ src }. Run: node build-js.js` );
			return false;
		}
		console.log( `OK: ${ out } matches a fresh build of ${ src } (${ code.length } bytes).` );
		return true;
	}

	fs.writeFileSync( target, code );
	console.log( `Built ${ out } from ${ src } (${ source.length } -> ${ code.length } bytes).` );
	return true;
}

( async () => {
	let ok = true;
	for ( const [ src, out ] of FILES ) {
		ok = ( await build( src, out ) ) && ok;
	}
	process.exit( ok ? 0 : 1 );
} )().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
