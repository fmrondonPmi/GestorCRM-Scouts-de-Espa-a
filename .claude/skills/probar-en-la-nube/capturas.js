/**
 * Fotografía pantallas del área con Chrome (Playwright) a 1280 y a 390 px y mide el desborde horizontal.
 *
 * Uso: NODE_PATH=$(npm root -g) node capturas.js <sesion.json> <paginas.json> <carpeta de salida>
 *   sesion.json:  lo que imprime sesion.php ({ cookies: { perfil: { name, value } } })
 *   paginas.json: [ [ "perfil", "nombre-de-la-captura", "http://localhost:8099/area-privada/…" ], … ]
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const [ , , sesionJson, paginasJson, salida ] = process.argv;
const sesion = JSON.parse( fs.readFileSync( sesionJson, 'utf8' ) );
const paginas = JSON.parse( fs.readFileSync( paginasJson, 'utf8' ) );
const chrome = fs.readdirSync( '/opt/pw-browsers' ).filter( ( d ) => /^chromium-\d+$/.test( d ) ).sort().pop();
( async () => {
	const navegador = await chromium.launch( { executablePath: `/opt/pw-browsers/${ chrome }/chrome-linux/chrome` } );
	let desbordes = 0;
	for ( const [ ancho, alto ] of [ [ 1280, 900 ], [ 390, 844 ] ] ) {
		for ( const [ perfil, nombre, url ] of paginas ) {
			const ctx = await navegador.newContext( { viewport: { width: ancho, height: alto } } );
			const c = sesion.cookies[ perfil ];
			if ( c ) {
				await ctx.addCookies( [ { name: c.name, value: c.value, url: new URL( url ).origin + '/' } ] );
			}
			const p = await ctx.newPage();
			await p.goto( url, { waitUntil: 'networkidle' } );
			const desborde = await p.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
			desbordes += desborde > 0 ? 1 : 0;
			await p.screenshot( { path: `${ salida }/${ ancho }-${ nombre }.png`, fullPage: true } );
			console.log( `${ ancho } ${ nombre }: desborde horizontal ${ desborde }px` );
			await ctx.close();
		}
	}
	await navegador.close();
	console.log( desbordes ? `${ desbordes } pantallas se salen de ancho` : 'Ninguna pantalla se sale de ancho' );
} )();
