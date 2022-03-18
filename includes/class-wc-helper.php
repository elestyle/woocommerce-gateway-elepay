<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once(__DIR__ . '/../vendor/autoload.php');

use Automattic\WooCommerce\Admin\Overrides\Order;
use Elepay\Api\CodeApi;
use Elepay\Api\ChargeApi;
use Elepay\Api\CodeSettingApi;
use Elepay\ApiException;
use Elepay\Configuration;
use Elepay\Model\CodeDto;
use Elepay\Model\CodeReq;
use Elepay\Model\ChargeDto;
use Elepay\Model\CodePaymentMethodResponse;

/**
 * Provides static methods as helpers.
 *
 * @since 4.0.0
 */
class WC_Elepay_Helper {
	const PAYMENT_METHODS_INFO_URL = 'https://resource.elecdn.com/payment-methods/info.json';

    const ADMIN_HOST = 'https://dashboard.elepay.io';
//    const ADMIN_HOST = 'https://stg-dashboard.elepay.io';

    const API_HOST = 'https://api.elepay.io';
//    const API_HOST = 'https://stg-api.elepay.io';

    /**
     * @param string $key
     * @return array|string
     */
    public static function get_query($key) {
        return isset( $_GET[$key] ) ? wc_clean( wp_unslash( $_GET[$key] ) ) : '';
    }

    /**
     * @param string $url
     */
    public static function redirect($url) {
        ?>
        <script type="text/javascript">
            window.location.href = '<?php echo $url; ?>'
        </script>
        <?php
    }

    /**
     * @param Order $order
     * @return string
     */
    public static function get_order_no($order) {
        // Since orderNo in Wordpress is an increment number, Create Charge will fail if a database reset occurs
        // Add preOrderId here to prevent duplicate order numbers
        return $order->get_order_number() . '-' . date('His');
    }

    /**
     * @param string $orderNo
     * @return string
     */
    public static function parse_order_no($orderNo) {
        return explode('-', $orderNo)[0];
    }

    public static function get_elepay_sdk_config() {
        $settings = get_option('woocommerce_elepay_settings');
        $secretKey = $settings['secret_key'];
        return Configuration::getDefaultConfiguration()
            ->setUsername($secretKey)
            ->setPassword('')
            ->setHost(self::API_HOST);
    }

    /**
     * Create Code Object
     *
     * @param Order $order
     * @param string $frontUrl
     * @return array
     * @throws ApiException
     * @throws InvalidArgumentException
     */
    public static function create_code_object($order, $frontUrl) {
        /** @var CodeReq $codeReq */
        $codeReq = new CodeReq();
        $codeReq->setOrderNo(self::get_order_no($order));
        $codeReq->setAmount((integer)$order->get_total());
        $codeReq->setCurrency($order->get_currency());
        $codeReq->setFrontUrl($frontUrl);
        wc_elepay_log($codeReq);
        /** @var CodeApi $codeApi */
        $codeApi = new CodeApi(null, self::get_elepay_sdk_config());
        /** @var CodeDto $codeDto */
        $codeDto = $codeApi->createCode($codeReq);
        wc_elepay_log($codeDto);
        $json = (string)$codeDto;
        return json_decode($json, true);
    }

    /**
     * Get Code Object
     *
     * @param string $codeId
     * @return array
     * @throws ApiException
     */
    public static function get_code_object($codeId) {
        /** @var CodeApi $codeApi */
        $codeApi = new CodeApi(null, self::get_elepay_sdk_config());
        /** @var CodeDto $codeDto */
        $codeDto = $codeApi->retrieveCode($codeId);
        $json = (string)$codeDto;
        return json_decode($json, true);
    }

    /**
     * Verify Charge Object
     *
     * @param string $chargeId
     * @return array
     * @throws ApiException
     */
    public static function get_charge_object($chargeId) {
        /** @var ChargeApi $chargeApi */
        $chargeApi = new ChargeApi(null, self::get_elepay_sdk_config());
        /** @var ChargeDto $chargeDto */
        $chargeDto = $chargeApi->retrieveCharge($chargeId);
        $json = (string)$chargeDto;
        return json_decode($json, true);
    }

    /**
     * @return array
     */
    public static function get_payment_methods() {
        try {
            $response = wp_remote_get( self::PAYMENT_METHODS_INFO_URL);
            $content = $response['body'];
            /**
             * $paymentMethodMap 數據結構
             * {
             *   "alipay": {
             *     "name": {
             *       "ja": "アリペイ",
             *       "en": "Alipay",
             *       "zh-CN": "支付宝",
             *       "zh-TW": "支付寶"
             *     },
             *     "image": {
             *       "short": "https://resource.elecdn.com/payment-methods/img/alipay.svg",
             *       "long": "https://resource.elecdn.com/payment-methods/img/alipay_long.svg"
             *     }
             *   },
             *   ...
             * }
             */
            $paymentMethodMap = json_decode($content, true);

            /** @var CodeSettingApi $codeSettingApi */
            $codeSettingApi = new CodeSettingApi(null, self::get_elepay_sdk_config());
            /** @var CodePaymentMethodResponse $codePaymentMethodResponse */
            $codePaymentMethodResponse = $codeSettingApi->listCodePaymentMethods();
            $json = (string)$codePaymentMethodResponse;
            /**
             * $availablePaymentMethods 數據結構
             * [
             *   {
             *     "paymentMethod": "alipay",
             *     "resources": [ "ios", "android", "web" ],
             *     "brand": [],
             *     "ua": "",
             *     "channelProperties": {}
             *   },
             *   ...
             * ]
             */
            $availablePaymentMethods = json_decode($json, true)['paymentMethods'];

            $paymentMethods = [];
            foreach ($availablePaymentMethods as $item) {
                $key = $item['paymentMethod'];
                $paymentMethodInfo = $paymentMethodMap[$key];

                if (
                    empty($key) ||
                    empty($paymentMethodInfo) ||
                    empty($item['resources']) ||
                    !in_array('web', $item['resources'])
                ) continue;

                if ($key === 'creditcard') {
                    foreach ($item['brand'] as $brand) {
                        $key = 'creditcard_' . $brand;
                        $paymentMethodInfo = $paymentMethodMap[$key];
                        $paymentMethods []= self::get_payment_method_info($key, $paymentMethodInfo, $item);
                    }
                } else {
                    $paymentMethods []= self::get_payment_method_info($key, $paymentMethodInfo, $item);
                }
            }
        } catch (Exception $e) {
            WC_Elepay_Logger::log($e);
            $paymentMethods = [];
        }

        return $paymentMethods;
    }

    private static function get_payment_method_info ($key, $paymentMethodInfo, $metaData) {
        return [
            'key' => $key,
            'name' => $paymentMethodInfo['name']['ja'],
            'image' => $paymentMethodInfo['image']['short'],
            'min' => null,
            'max' => null,
            'ua' => empty($metaData['ua']) ? '' : $metaData['ua']
        ];
    }
}
