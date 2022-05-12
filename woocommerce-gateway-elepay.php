<?php
/**
 * Plugin Name: WooCommerce elepay Gateway
 * Plugin URI: https://wordpress.org/plugins/woocommerce-gateway-elepay/
 * Description: elepay決済プラグイン
 * Author: elepay
 * Author URI: https://elepay.io/
 * Version: 1.0.0
 * Requires at least: 5.0
 * Tested up to: 5.7
 * WC requires at least: 3.0
 * WC tested up to: 5.4
 * Text Domain: woocommerce-gateway-elepay
 * Domain Path: /languages
 */

use Automattic\WooCommerce\Admin\Overrides\Order;
use Elepay\ApiException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required minimums and constants
 */
define( 'WC_ELEPAY_VERSION', '1.0.0' );
define( 'WC_ELEPAY_MAIN_FILE', __FILE__ );
define( 'WC_ELEPAY_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_ELEPAY_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_ELEPAY_TEXT_DOMAIN', 'woocommerce-gateway-elepay' );
define( 'WC_ELEPAY_WEBHOOK_NAME', 'wc_elepay_paid' );

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'elepay_add_gateway_class' );
function elepay_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_Elepay_Gateway'; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'elepay_init_gateway_class' );
function elepay_init_gateway_class() {

    class WC_Elepay_Gateway extends WC_Payment_Gateway {
        /**
         * The *Singleton* instance of this class
         *
         * @var Singleton
         */
        private static $instance;

        /**
         * Returns the *Singleton* instance of this class.
         *
         * @return Singleton The *Singleton* instance.
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Private clone method to prevent cloning of the instance of the
         * *Singleton* instance.
         *
         * @return void
         */
        public function __clone() {}

        /**
         * Private unserialize method to prevent unserializing of the *Singleton*
         * instance.
         *
         * @return void
         */
        public function __wakeup() {}

        /**
         * Protected constructor to prevent creating a new instance of the
         * *Singleton* via the `new` operator from outside of this class.
         *
         * Paidy for WooCommerce Constructor.
         * @access public
         * @return WooCommerce

         */
        public function __construct() {
            $this->init();
        }

        /**
         * Init the plugin after plugins_loaded so environment variables are set.
         *
         * @since 1.0.0
         * @version 5.0.0
         */
        private function init() {
            $this->id = 'elepay'; // payment gateway plugin ID
            $this->icon = 'https://files.elecdn.com/dashboard/img/partner/elepay/logo-full.svg'; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->title = __( 'QR Code Payment', 'woocommerce-gateway-elepay' );
            $this->description = ' ';
            $this->order_button_text = __( 'QR Code Payment', 'woocommerce-gateway-elepay' );
            $this->method_title = __( 'elepay Payment', 'woocommerce-gateway-elepay' );
            $this->method_description = __( 'elepay allows you to make payments using a variety of methods including: Credit Cards, PayPay, LINE Pay, Merpay, auPAY, RPay, WeChat Pay, Alipay, Union Pay, etc.', 'woocommerce-gateway-elepay' );
            $this->has_fields = false; // in case you need a custom credit card form

            load_plugin_textdomain( WC_ELEPAY_TEXT_DOMAIN, false, basename( dirname( __FILE__ ) ) . '/languages' );

            $this->init_form_fields();
            $this->init_includes();

            // Load settings
            $this->init_settings();

            $webhook_url = add_query_arg( 'wc-api', WC_ELEPAY_WEBHOOK_NAME, trailingslashit( get_home_url() ) );
            $this->update_option( 'webhook', $webhook_url );
//            $this->enabled = $this->get_option( 'enabled' );
//            $this->public_key = $this->get_option( 'public_key' );
//            $this->secret_key = $this->get_option( 'secret_key' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'woocommerce_api_' . WC_ELEPAY_WEBHOOK_NAME, [ $this, 'webhook' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
            add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
            add_action( 'wp', [ $this, 'process_action' ] );
        }

        public function init_form_fields() {
            $this->form_fields = require WC_ELEPAY_PLUGIN_PATH . '/includes/admin/settings.php';
        }

        private function init_includes() {
            require_once WC_ELEPAY_PLUGIN_PATH . '/includes/utils.php';
            require_once WC_ELEPAY_PLUGIN_PATH . '/includes/class-wc-logger.php';
            require_once WC_ELEPAY_PLUGIN_PATH . '/includes/class-wc-helper.php';
        }

        public function process_action() {
            if ( empty( $_GET[ WC_ELEPAY_TEXT_DOMAIN ] ) ) {
                return null;
            }

            switch ( $_GET['action'] ) {
                case 'redirect_order':
                    $this->process_redirect_order();
                    break;
            }
        }

        public function admin_scripts() {
            if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
                return;
            }

            // import css
            wp_register_style( 'wc_elepay_styles', plugins_url( 'assets/css/styles.css', WC_ELEPAY_MAIN_FILE ), [], false );
            wp_enqueue_style( 'wc_elepay_styles' );

            // import js
            wp_register_script( 'wc_elepay_clipboard', 'https://cdn.jsdelivr.net/npm/clipboard@2.0.6/dist/clipboard.min.js', [], false, true );
            wp_enqueue_script( 'wc_elepay_clipboard' );
            wp_register_script( 'wc_elepay_admin', plugins_url( 'assets/js/admin.js', WC_ELEPAY_MAIN_FILE ), [ 'wc_elepay_clipboard' ], false, true );
            wp_enqueue_script( 'wc_elepay_admin' );
        }

        /**
         * UI - Payment page fields.
         */
        public function payment_fields() {
            $payment_methods = WC_Elepay_Helper::get_payment_methods();
            ?>
            <div id="elepay_info">
                <?php foreach ($payment_methods as $item) : ?>
                    <div class="elepay-payment-method-icon" style="background-image: url(<?php echo $item['image'] ?>)"></div>
                <?php endforeach ?>
            </div>
            <style>
                #elepay_info {
                    padding: 10px;
                    line-height: 0;
                    background-color: white;
                }
                .elepay-payment-method-icon {
                    display: inline-block;
                    margin: 5px;
                    width: 24px;
                    height: 20px;
                    background-repeat: no-repeat;
                    background-position: center;
                    background-size: contain;
                }
            </style>
            <?php
        }

        /**
         * UI - Payment page scripts.
         */
        public function payment_scripts() {
            // import js
        }

        public function process_payment( $order_id ) {
            /** @var Order $order */
            $order = wc_get_order( $order_id );

            if ( empty( $order ) ) {
                WC_Elepay_Logger::log( '[注文確認] ERROR::Order does not exist.' );
                wc_add_notice( __( 'Order does not exist.', 'woocommerce-gateway-elepay' ), 'error' );
                return null;
            }

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('on-hold', __( 'Awaiting elepay payment', 'woocommerce-gateway-elepay' ));

            if ( (integer)$order->get_total() === 0 ) {
                $this->order_complete( $order );

                WC_Elepay_Logger::log( '[注文処理] 購入完了画面へ遷移します.' );
                return [
                    'result' => 'success',
                    'redirect' => $order->get_checkout_order_received_url()
                ];
            }

            $checkout_payment_url = add_query_arg(
                [
                    WC_ELEPAY_TEXT_DOMAIN => true,
                    'action' => 'redirect_order',
                    'orderNo' => WC_Elepay_Helper::get_order_no( $order )
                ],
                $order->get_checkout_payment_url( false )
            );

            try {
                $payment_object = WC_Elepay_Helper::create_code_object( $order, $checkout_payment_url );
                $redirect_url = add_query_arg(
                    [
                        'mode' => 'auto'
                    ],
                    $payment_object['codeUrl']
                );

                return [
                    'result' => 'success',
                    'redirect' => $redirect_url
                ];
            } catch ( InvalidArgumentException $e ) {
                WC_Elepay_Logger::log( '[注文処理] ERROR::Exception when calling CodeApi->createCode::' . $e->getMessage() );
                wc_add_notice( __( 'Payment process encountered error, please contact us.', 'woocommerce-gateway-elepay' ), 'error' );
                return null;
            }
        }

        private function process_redirect_order() {
            $status = WC_Elepay_Helper::get_query( 'status' );
            $code_id = WC_Elepay_Helper::get_query( 'codeId' );
            $charge_id = WC_Elepay_Helper::get_query( 'chargeId' );
            $order_no = WC_Elepay_Helper::get_query( 'orderNo' );
            $order_id = WC_Elepay_Helper::parse_order_no( $order_no );

            /** @var Order $order */
            $order = wc_get_order( $order_id );

            if ( empty( $order ) ) {
                WC_Elepay_Logger::log( '[注文確認] ERROR::Order does not exist.' );
                wc_add_notice( __( 'Order does not exist.', 'woocommerce-gateway-elepay' ), 'error' );
                return null;
            }

            if ( $status === 'captured' ) {
                try {
                    if ( ! empty( $charge_id ) ) {
                        $charge_object = WC_Elepay_Helper::get_charge_object( $charge_id );
                    } else if ( ! empty( $code_id ) ) {
                        $code_object = WC_Elepay_Helper::get_code_object( $code_id );
                        $charge_object = $code_object['charge'];
                    }

                    if ( empty( $charge_object ) ) {
                        WC_Elepay_Logger::log( '[注文処理] ERROR::Charge does not exist.' );
                        wc_add_notice( __( 'Charge does not exist.', 'woocommerce-gateway-elepay' ), 'error' );
                        return null;
                    }

                    $result = $this->order_validate( $order, $charge_object );

                    if ( $result === 'success' ) {
                        $this->order_complete( $order );
                        WC_Elepay_Logger::log( '[注文処理] 購入完了画面へ遷移します.' );
                        WC_Elepay_Helper::redirect( $order->get_checkout_order_received_url() );
                    }
                } catch ( InvalidArgumentException $e ) {
                    WC_Elepay_Logger::log( '[注文処理] ERROR::Exception when calling ChargeApi->retrieveCharge::' . $e->getMessage() );
                    wc_add_notice( __( 'Payment process encountered error, please contact us.', 'woocommerce-gateway-elepay' ), 'error' );
                }
            } else {
                // 将订单状态回退
                $order->update_status( 'pending' );

                if ( $status === 'cancelled' ) {
                    WC_Elepay_Logger::log( '[注文確認] Order cancelled.' );
                    WC_Elepay_Helper::redirect( $order->get_checkout_payment_url() );
                } else {
                    WC_Elepay_Logger::log( '[注文確認] ERROR::Unknown error.' );
                    wc_add_notice( __( 'Payment process encountered error, please contact us.', 'woocommerce-gateway-elepay' ), 'error' );
                }
            }
        }

        public function webhook() {
            $request_body = file_get_contents( 'php://input' );
            $data = json_decode( $request_body, true );
            $charge_id = $data['data']['object']['id'];
            $order_id = WC_Elepay_Helper::parse_order_no( $data['data']['object']['orderNo'] );

            if ( empty( $order_id ) || empty( $charge_id ) ) {
                status_header( 400 );
                $error_message = 'The necessary parameters are missing.';
                WC_Elepay_Logger::log( '[Webhook] ERROR::' . $error_message );
                echo $error_message;
                exit;
            }

            /** @var Order $order */
            $order = wc_get_order( $order_id );

            if ( empty( $order ) ) {
                status_header( 400 );
                $error_message = 'Order does not exist.';
                WC_Elepay_Logger::log( '[Webhook] ERROR::' . $error_message );
                echo $error_message;
                exit;
            }

            $charge_object = WC_Elepay_Helper::get_charge_object( $charge_id );

            if ( empty( $charge_object ) ) {
                status_header( 400 );
                $error_message = 'Charge does not exist.';
                WC_Elepay_Logger::log( '[Webhook] ERROR::' . $error_message );
                echo $error_message;
                exit;
            }

            $result = $this->order_validate( $order, $charge_object );

            if ( $result !== 'success' ) {
                status_header( 400 );
                $error_message = 'Order validation failed.';
                WC_Elepay_Logger::log( '[Webhook] ERROR::' . $error_message );
                echo $error_message;
                exit;
            }

            $this->order_complete( $order );
            status_header( 200 );
            WC_Elepay_Logger::log( '[Webhook] Success.' );
            echo 'Success';
            exit;
        }

        /**
         * @param Order $order
         * @param array $charge_object
         * @return string
         */
        private function order_validate( $order, $charge_object )
        {
            if ( $order->has_status( [ 'processing', 'completed' ] ) ) {
                return 'success';
            }

            $order_no = $order->get_order_number();
            $charge_order_no = WC_Elepay_Helper::parse_order_no($charge_object['orderNo']);
            if ( $order_no !== $charge_order_no ) {
                WC_Elepay_Logger::log( '[注文確認] ERROR::Verify payment order error.' . PHP_EOL . '  wc[order_no] : ' . $order_no . ' / elepay[order_no] : ' . $charge_order_no );
                return 'error';
            }

            $order_amount = (integer)$order->get_total();
            $charge_amount = (integer)$charge_object['amount'];
            if ( $order_amount !== $charge_amount ) {
                WC_Elepay_Logger::log( '[注文確認] ERROR::Verify payment amount error.' . PHP_EOL . '  wc[amount] : ' . $order_amount . ' / elepay[amount] : ' . $charge_amount );
                return 'error';
            }

            $charge_status = $charge_object['status'];
            if ( $charge_status !== 'captured' ) {
                WC_Elepay_Logger::log( '[注文確認] ERROR::Verify payment status error : status is ' . $charge_status );
                return 'error';
            }

            $payment_method_name = $charge_object['paymentMethod'];
            if ( $payment_method_name === 'creditcard' ) {
                $payment_method_name = 'creditcard_' . $charge_object['cardInfo']['brand'];
            }

            $payment_methods = WC_Elepay_Helper::get_payment_methods();
            foreach ( $payment_methods as $payment_method ) {
                if ( $payment_method['key'] === $payment_method_name ) {
                    $payment_method_name = $payment_method['name'];
                    break;
                }
            }

            // 不能使用 $order->update_meta_data，因為他會創建一條新的 _payment_method_title 數據
            update_post_meta( $order->get_id(), '_payment_method_title', $payment_method_name );

            $order->set_transaction_id( $charge_object['id'] );
            if ( is_callable( [ $order, 'save' ] ) ) {
                $order->save();
            }

            return 'success';
        }

        /**
         * @param Order $order
         */
        private function order_complete( $order ) {
            if ( $order->has_status( [ 'processing', 'completed' ] ) ) {
                return;
            }

            global $woocommerce;

            // Payment is complete, let WooCommerce handle the status.
            $order->payment_complete();
            $charge_id = $order->get_transaction_id();
            $order->add_order_note( sprintf( __( 'elepay charge complete (Charge ID: %s)', 'woocommerce-gateway-elepay' ), $charge_id ) );

            // Remove cart
            $woocommerce->cart->empty_cart();
        }

        public function get_transaction_url( $order ) {
            $charge_id = $order->get_transaction_id();
            $charge_object = WC_Elepay_Helper::get_charge_object($charge_id);
            $this->view_transaction_url = WC_Elepay_Helper::ADMIN_HOST .
                '/apps/' . $charge_object['appId'] . '/gw/payment/charges/' . $charge_id;
            return parent::get_transaction_url( $order );
        }
    }

    WC_Elepay_Gateway::get_instance();
}
