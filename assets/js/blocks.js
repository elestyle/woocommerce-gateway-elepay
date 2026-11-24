/**
 * Registers elepay in the Cart and Checkout blocks.
 *
 * Plain JavaScript on the globals WordPress and WooCommerce provide, so no build step is needed.
 * The data comes from WC_Elepay_Blocks_Support::get_payment_method_data().
 */
( function () {
	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var el = window.wp.element.createElement;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;

	var settings = getSetting( 'elepay_data', {} );
	var title = decodeEntities( settings.title || '' );
	var description = decodeEntities( settings.description || '' );
	var paymentMethods = settings.paymentMethods || [];

	function Label( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		var text = PaymentMethodLabel ? el( PaymentMethodLabel, { text: title } ) : el( 'span', null, title );

		return el(
			'span',
			{
				className: 'wc-elepay-blocks-label',
				style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%', gap: '8px' },
			},
			text,
			settings.logo
				? el( 'img', {
					src: settings.logo,
					alt: 'elepay',
					style: { height: '24px', width: 'auto', maxWidth: '120px', flexShrink: 0 },
				} )
				: null
		);
	}

	function Content() {
		return el(
			'div',
			{ className: 'wc-elepay-blocks-content' },
			description ? el( 'p', { style: { margin: '0 0 8px' } }, description ) : null,
			paymentMethods.length
				? el(
					'div',
					{ style: { display: 'flex', flexWrap: 'wrap', gap: '10px', alignItems: 'center' } },
					paymentMethods.map( function ( item ) {
						return el( 'img', {
							key: item.key,
							src: item.image,
							alt: item.name,
							title: item.name,
							style: { width: '24px', height: '20px', objectFit: 'contain' },
						} );
					} )
				)
				: null
		);
	}

	registerPaymentMethod( {
		name: 'elepay',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
