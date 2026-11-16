# Compatibility Test Report: WordPress 7.1.4 + WooCommerce 11.2.2

- Test date: 2026-11-11
- Plugin version: 1.0.4 (`cbdf147`)
- Conclusion: **With the classic checkout (the `[woocommerce_checkout]` shortcode), everything from placing an order to showing EasyCheckout works correctly. With WooCommerce's default block checkout, elepay is not shown, so the plugin cannot be used on a new store with its default settings.**

The store was tested in Japanese. Text that appears on screen is quoted as the original Japanese UI string, followed by its English source string or meaning in parentheses.

## Environment

| Component | Version |
|---|---|
| WordPress | 7.1.4 (latest at the time of testing) |
| WooCommerce | 11.2.2 (latest at the time of testing) |
| PHP | 8.3.36 |
| MariaDB | 11.8.9 |
| Theme | Twenty Twenty-Five 1.5 (WordPress's default block theme) |

Store settings: country JP, currency JPY, 0 decimal places, language ja. The test product costs ¥100.

## Results

### Working

| Item | Result | Verified with |
|---|---|---|
| Plugin activation | No errors. `WC_Elepay_Gateway` is registered and `is_available()` returns true | WP-CLI |
| Admin settings page | All fields render correctly, and the stylesheet, `admin.js` and ClipboardJS are loaded. The Webhook URL is filled in automatically | Browser |
| Payment method icons on the checkout page | Fetched and displayed correctly when using live keys | Browser |
| Order → createCode | Request parameters (amount / JPY / orderNo / frontUrl) are correct, and a code with `liveMode: true` is returned | Plugin log |
| Redirect to EasyCheckout | `easyqr.elepay.io/checkout.html?mode=auto&code=…` opens with the correct amount | Browser |
| Cancelling in EasyCheckout | Returns to the order payment page, and the order goes from `on-hold` back to `pending`. `Order cancelled.` is written to the log | Browser + WP-CLI |
| Paying again from the order payment page | A new code is created and EasyCheckout opens correctly again | Browser |
| Webhook parameter checks | Returns 400 both when parameters are missing and when the order does not exist | curl |

### Webhook (tested with a mocked elepay API)

Webhooks from elepay cannot reach a local environment, so a mu-plugin was added to the test environment only. It uses `pre_http_request` to intercept `GET https://api.elepay.io/charges/{id}` and return a prepared charge JSON. A Webhook body was then POSTed with curl to `/?wc-api=wc_elepay_paid`, and the response, order status, transaction ID and order notes were checked.

| Case | Response | Order |
|---|---|---|
| Normal (captured, amount and order number match) | 200 `Success` | Becomes `processing`; the transaction ID and the note 「elepay決済完了」 ("elepay charge complete") are recorded |
| Duplicate notification for the same charge | 200 `Success` | No change and no duplicate note (idempotent) |
| Amount mismatch / status other than captured / order number mismatch | 400 `Order validation failed.` | Stays `on-hold` |
| elepay API returns 500 | 500 `Failed to retrieve charge.` | Stays `on-hold` |
| Charge does not exist (API returns 404) | 500 `Failed to retrieve charge.` | Stays `on-hold` |

### Issues

1. **elepay is not shown in the block checkout (affects usability)**
   On a new WooCommerce store, the checkout page uses the Checkout Block by default. The plugin does not register a payment method for the block checkout (`Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType`), so the list of registered block payment methods contains only `cheque, paypal, bacs, cod`, and the checkout page shows 「利用可能な支払い方法がありません」 ("There are no payment methods available"). This is unrelated to the API keys: with the same settings, switching to the classic shortcode makes elepay appear.

2. **HPOS compatibility is not declared, and the payment method title is not saved when HPOS is enabled** (fixed in this PR)
   - Because compatibility is not declared with `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`, WooCommerce treats the plugin as uncertain and refuses `wp wc hpos enable` while the plugin is active (`Some installed plugins are incompatible`).
   - With HPOS force-enabled, calling `order_validate` saved `transaction_id` correctly, but `update_post_meta( ..., '_payment_method_title', ... )` only wrote to the legacy postmeta, so the order's payment method title did not change.
   - Also reproduced when payment completes through the Webhook: with HPOS enabled the payment method title stayed 「QRコード決済」 ("QR Code Payment"), while with HPOS disabled it was correctly updated to "PayPay".

3. **The order stays on-hold when createCode fails**
   `process_payment` changes the order to `on-hold` before calling createCode, so when the call fails (observed with 401; network failures or temporary elepay outages behave the same) the order is not reverted.

4. **The error message is shown twice**: 「決済に失敗しました。サポートまでお問い合わせください。」 ("Payment process encountered error, please contact us.") is displayed twice.

5. **PHP Notice / Deprecated (no functional impact)**
   - `Translation loading for the woocommerce-gateway-elepay domain was triggered too early` (a check added in WordPress 6.7).
   - A warning raised by elepay-php-sdk on PHP 8.1 and later: `Return type of Elepay\Model\CodeReq::offsetExists($offset) should either be compatible with ArrayAccess::offsetExists(mixed $offset): bool ...`

6. **UI: the elepay logo is too large**
   The `<img>` output for `$this->icon` has no size, and the SVG itself has no intrinsic size, so in block themes such as Twenty Twenty-Five it stretches to the full width of its container.

7. **Webhook: returns 500 even for a charge that does not exist (minor)** (fixed in this PR)
   Every failure to retrieve the charge is treated as a temporary fault and returns 500, so elepay keeps retrying even when the charge does not exist (404). A 404 should be treated as 400.

### Not verified

- The redirect after payment completes (`status=captured` → order received page → order becomes processing). This needs a real payment.
- Real Webhook delivery from elepay, including whether the real request body matches the format assumed by the mock. This needs a public URL (via ngrok, cloudflared or similar) and a real payment.

## Reproduction environment

Assuming the dependencies are installed in `vendor/` (see README), use the following `docker-compose.yml`. `PLUGIN_DIR` points to this repository.

```yaml
name: elepay-wp-test
services:
  db:
    image: mariadb:11
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wp
      MARIADB_PASSWORD: wp
    volumes: [db:/var/lib/mysql]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      retries: 20
  wordpress:
    image: wordpress:latest
    depends_on: { db: { condition: service_healthy } }
    ports: ["8080:80"]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wp
      WORDPRESS_DB_PASSWORD: wp
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DEBUG: "1"
    volumes:
      - wp:/var/www/html
      - ${PLUGIN_DIR}:/var/www/html/wp-content/plugins/woocommerce-gateway-elepay
  cli:
    image: wordpress:cli
    user: "33:33"
    depends_on: [wordpress]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wp
      WORDPRESS_DB_PASSWORD: wp
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - wp:/var/www/html
      - ${PLUGIN_DIR}:/var/www/html/wp-content/plugins/woocommerce-gateway-elepay
    entrypoint: ["sleep", "infinity"]
volumes: { db: {}, wp: {} }
```

```shell
PLUGIN_DIR=/path/to/woocommerce-gateway-elepay docker compose up -d
wp() { docker compose exec -T cli wp "$@"; }
wp core install --url=http://localhost:8080 --title="elepay test" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
wp rewrite structure '/%postname%/'
wp plugin install woocommerce --activate
wp plugin activate woocommerce-gateway-elepay
wp option update woocommerce_currency JPY
wp option update woocommerce_default_country JP:JP13
wp option update woocommerce_price_num_decimals 0
# To test with the classic checkout:
wp post update "$(wp option get woocommerce_checkout_page_id)" \
  --post_content='<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->'
```

## Re-test after the fixes (this PR)

After fixing issues 2 and 7, the plugin was re-tested in the same mocked environment.

| Case | Result |
|---|---|
| HPOS compatibility | WooCommerce reports the plugin as compatible, and `wp wc hpos enable` succeeds as is |
| Webhook payment completion with HPOS enabled | The payment method title is updated to "PayPay", and the order note becomes 「PayPay での支払い」 ("Payment via PayPay") |
| Webhook payment completion with HPOS disabled | The payment method title is updated to "PayPay", and there is exactly one `_payment_method_title` in postmeta (no duplicates) |
| Charge does not exist (API returns 404) | 400 `Charge does not exist.` |
| API returns 500 / amount mismatch / duplicate notification | 500 / 400 / 200 respectively (unchanged) |
