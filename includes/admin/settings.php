<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return apply_filters(
	'wc_elepay_settings',
    [
        'enabled'         => [
            'title'       => __( 'Enable/Disable', 'woocommerce-gateway-elepay' ),
            'label'       => __( 'Enable elepay', 'woocommerce-gateway-elepay' ),
            'type'        => 'checkbox',
            'description' => '',
            'default'     => 'no',
        ],
        'public_key'      => [
            'title'       => __( 'Public Key', 'woocommerce-gateway-elepay' ),
            'type'        => 'text',
            'description' => __( 'Get your API keys from your elepay account. Invalid values will be rejected. Only values starting with "pk_live_" will be saved.', 'woocommerce-gateway-elepay' ),
            'desc_tip'    => true,
            'default'     => '',
        ],
        'secret_key'      => [
            'title'       => __( 'Secret Key', 'woocommerce-gateway-elepay' ),
            'type'        => 'password',
            'description' => __( 'Get your API keys from your elepay account. Invalid values will be rejected. Only values starting with "sk_live_" will be saved.', 'woocommerce-gateway-elepay' ),
            'desc_tip'    => true,
            'default'     => '',
        ],
        'webhook'         => [
            'title'       => __( 'Webhook Endpoints', 'woocommerce-gateway-elepay' ),
            'type'        => 'text',
            'description' => __( 'Add the URL to elepay\'s development Settings -> Webhook', 'woocommerce-gateway-elepay' ),
            'desc_tip'    => true,
            'default'     => '',
            'custom_attributes' => [ 'readonly' => 'readonly' ],
        ],
        'logging'         => [
            'title'       => __( 'Logging', 'woocommerce-gateway-elepay' ),
            'label'       => __( 'Log debug messages', 'woocommerce-gateway-elepay' ),
            'type'        => 'checkbox',
            'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-elepay' ),
            'default'     => 'no',
            'desc_tip'    => true,
        ],
    ]
);
