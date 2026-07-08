import {
	arrayToLines,
	clampInt,
	controlType,
	linesToArray,
	numberToText,
} from './fields';

describe( 'controlType', () => {
	it( 'maps each descriptor type to its control, defaulting to text', () => {
		expect( controlType( { type: 'text' } ) ).toBe( 'text' );
		expect( controlType( { type: 'textarea' } ) ).toBe( 'textarea' );
		expect( controlType( { type: 'html' } ) ).toBe( 'textarea' );
		expect( controlType( { type: 'number' } ) ).toBe( 'number' );
		expect( controlType( { type: 'select' } ) ).toBe( 'select' );
		expect( controlType( { type: 'list' } ) ).toBe( 'list' );
		expect( controlType( { type: 'anything-else' } ) ).toBe( 'text' );
		expect( controlType( {} ) ).toBe( 'text' );
	} );
} );

describe( 'linesToArray / arrayToLines', () => {
	it( 'stores an emptied field as [] and drops blank lines', () => {
		expect( linesToArray( '' ) ).toEqual( [] );
		expect( linesToArray( 'a\n\nb\n' ) ).toEqual( [ 'a', 'b' ] );
		expect( linesToArray( '{{ order_id }}\n{{ total }}' ) ).toEqual( [
			'{{ order_id }}',
			'{{ total }}',
		] );
	} );

	it( 'joins a stored array to text and tolerates non-arrays', () => {
		expect( arrayToLines( [ 'a', 'b' ] ) ).toBe( 'a\nb' );
		expect( arrayToLines( undefined ) ).toBe( '' );
		expect( arrayToLines( '' ) ).toBe( '' );
		expect( arrayToLines( [] ) ).toBe( '' );
	} );

	it( 'round-trips a clean array through text and back', () => {
		const value = [ 'x', 'y' ];
		expect( linesToArray( arrayToLines( value ) ) ).toEqual( value );
	} );
} );

describe( 'clampInt / numberToText', () => {
	it( 'clamps to a non-negative integer', () => {
		expect( clampInt( '7200' ) ).toBe( 7200 );
		expect( clampInt( '' ) ).toBe( 0 );
		expect( clampInt( '-5' ) ).toBe( 0 );
		expect( clampInt( '1.9' ) ).toBe( 1 );
		expect( clampInt( 'abc' ) ).toBe( 0 );
	} );

	it( 'keeps an explicitly empty value blank but shows real numbers', () => {
		expect( numberToText( '' ) ).toBe( '' );
		expect( numberToText( null ) ).toBe( '' );
		expect( numberToText( undefined ) ).toBe( '' );
		expect( numberToText( 0 ) ).toBe( '0' );
		expect( numberToText( 3600 ) ).toBe( '3600' );
	} );
} );
