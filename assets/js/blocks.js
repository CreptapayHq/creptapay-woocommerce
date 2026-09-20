/* CreptaPay payment method for the WooCommerce Checkout block. No build step needed. */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'creptapay_data', {} );
	const title = decodeEntities( settings.title || 'Pay with crypto' );
	const description = decodeEntities( settings.description || '' );

	const Content = () => createElement( 'div', null, description );
	const Label = () => createElement( 'span', null, title );

	registerPaymentMethod( {
		name: 'creptapay',
		label: createElement( Label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		ariaLabel: title,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
