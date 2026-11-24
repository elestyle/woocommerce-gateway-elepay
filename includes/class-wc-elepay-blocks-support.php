<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers elepay as a payment method of the Cart and Checkout blocks.
 *
 * The block checkout renders payment methods in React and only lists the ones registered through
 * the Blocks payment method registry. Placing the order still goes through
 * WC_Elepay_Gateway::process_payment(), so the payment flow is the same as the classic checkout.
 */
final class WC_Elepay_Blocks_Support extends AbstractPaymentMethodType {
	const SCRIPT_HANDLE = 'wc-elepay-blocks';

	/**
	 * Transient that caches the payment method icons shown in the block checkout.
	 */
	const PAYMENT_METHODS_CACHE_KEY = 'wc_elepay_blocks_payment_methods';
	const PAYMENT_METHODS_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Must match the gateway ID, so that the Store API hands the order to WC_Elepay_Gateway.
	 *
	 * @var string
	 */
	protected $name = 'elepay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_elepay_settings', [] );
	}

	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/blocks.js', WC_ELEPAY_MAIN_FILE ),
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
			WC_ELEPAY_VERSION,
			true
		);

		return [ self::SCRIPT_HANDLE ];
	}

	public function get_payment_method_data() {
		$gateway = $this->get_gateway();

		return [
			'title'          => $gateway ? $gateway->get_title() : __( 'QR Code Payment', 'woocommerce-gateway-elepay' ),
			'description'    => $gateway ? trim( $gateway->get_description() ) : '',
			'logo'           => $gateway ? $gateway->icon : '',
			'paymentMethods' => $this->get_payment_methods(),
			'supports'       => $gateway ? array_values( array_filter( $gateway->supports, [ $gateway, 'supports' ] ) ) : [ 'products' ],
		];
	}

	/**
	 * Clears the cached payment method icons, e.g. after the API keys change.
	 */
	public static function clear_cache() {
		delete_transient( self::PAYMENT_METHODS_CACHE_KEY );
	}

	/**
	 * @return WC_Payment_Gateway|null
	 */
	private function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways[ $this->name ] ?? null;
	}

	/**
	 * The data is built on every page that contains the Cart or Checkout block, and fetching the icons
	 * takes two requests to elepay, so successful results are cached for a short time.
	 *
	 * @return array
	 */
	private function get_payment_methods() {
		$cached = get_transient( self::PAYMENT_METHODS_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! class_exists( 'WC_Elepay_Helper' ) ) {
			return [];
		}

		$payment_methods = [];
		foreach ( WC_Elepay_Helper::get_payment_methods() as $item ) {
			$payment_methods[] = [
				'key'   => $item['key'],
				'name'  => $item['name'],
				'image' => $item['image'],
			];
		}

		// An empty list usually means the request failed or the keys are not set yet; do not cache it.
		if ( ! empty( $payment_methods ) ) {
			set_transient( self::PAYMENT_METHODS_CACHE_KEY, $payment_methods, self::PAYMENT_METHODS_CACHE_TTL );
		}

		return $payment_methods;
	}
}
