# 互換性検証記録：WordPress 7.1.4 + WooCommerce 11.2.2

- 検証日：2026-11-11
- プラグインバージョン：1.0.4（`cbdf147`）
- 結論：**クラシックチェックアウト（`[woocommerce_checkout]` ショートコード）では注文から EasyCheckout の表示まで正常に動作する。WooCommerce 標準のブロックチェックアウトでは elepay が表示されず、新規ストアの初期設定のままでは利用できない。**

## 環境

| コンポーネント | バージョン |
|---|---|
| WordPress | 7.1.4（検証時点の最新） |
| WooCommerce | 11.2.2（検証時点の最新） |
| PHP | 8.3.36 |
| MariaDB | 11.8.9 |
| テーマ | Twenty Twenty-Five 1.5（WordPress 標準のブロックテーマ） |

ストア設定：国 JP、通貨 JPY、小数点以下 0 桁、言語 ja。テスト商品の単価は ¥100。

## 検証結果

### 正常

| 項目 | 結果 | 確認方法 |
|---|---|---|
| プラグイン有効化 | エラーなし。`WC_Elepay_Gateway` が登録され、`is_available()` は true | WP-CLI |
| 管理画面の設定ページ | 各項目が正常に表示され、スタイルシート・`admin.js`・ClipboardJS が読み込まれる。Webhook URL は自動入力される | ブラウザ |
| チェックアウト画面の決済方法アイコン | 本番キー使用時に正常に取得・表示される | ブラウザ |
| 注文 → createCode | リクエストパラメータ（amount / JPY / orderNo / frontUrl）が正しく、`liveMode: true` の code が返る | プラグインログ |
| EasyCheckout への遷移 | `easyqr.elepay.io/checkout.html?mode=auto&code=…` が開き、金額も正しい | ブラウザ |
| EasyCheckout でのキャンセル | 注文支払いページに戻り、注文は `on-hold` から `pending` に戻る。ログに `Order cancelled.` が記録される | ブラウザ + WP-CLI |
| 注文支払いページからの再支払い | code が再作成され、再び EasyCheckout が正常に開く | ブラウザ |
| Webhook のパラメータチェック | パラメータ不足・注文が存在しない場合はいずれも 400 を返す | curl |

### Webhook（elepay API をモックして検証）

elepay からローカル環境へは Webhook が届かないため、テスト環境にだけ mu-plugin を置き、`pre_http_request` で `GET https://api.elepay.io/charges/{id}` を横取りして、用意した charge JSON を返すようにした。そのうえで curl から `/?wc-api=wc_elepay_paid` に Webhook ボディを POST し、レスポンス・注文ステータス・取引 ID・注文メモを確認した。

| ケース | レスポンス | 注文 |
|---|---|---|
| 正常（captured、金額・注文番号一致） | 200 `Success` | `processing` になり、取引 ID と「elepay決済完了」メモが記録される |
| 同じ charge の重複通知 | 200 `Success` | 変化なし、メモの重複もなし（冪等） |
| 金額不一致 / status が captured 以外 / 注文番号不一致 | 400 `Order validation failed.` | `on-hold` のまま |
| elepay API が 500 を返す | 500 `Failed to retrieve charge.` | `on-hold` のまま |
| charge が存在しない（API が 404） | 500 `Failed to retrieve charge.` | `on-hold` のまま |

### 問題

1. **ブロックチェックアウトに elepay が表示されない（利用可否に影響）**
   WooCommerce の新規ストアでは、チェックアウトページが標準で Checkout Block になる。プラグインがブロックチェックアウト用の決済方法（`Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType`）を登録していないため、ブロック決済方法の登録一覧には `cheque, paypal, bacs, cod` しかなく、チェックアウト画面に「利用可能な支払い方法がありません」と表示される。API キーとは無関係で、同じ設定のままクラシックのショートコードに切り替えれば表示される。

2. **HPOS 互換性が未宣言。HPOS 有効時に決済方法名が書き込まれない**（本 PR で修正済み）
   - `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )` による宣言がないため、WooCommerce は uncertain と判定し、プラグイン有効中は `wp wc hpos enable` が拒否される（`Some installed plugins are incompatible`）。
   - HPOS を強制的に有効化して `order_validate` を呼び出したところ、`transaction_id` は正しく保存されたが、`update_post_meta( ..., '_payment_method_title', ... )` は旧来の postmeta にしか書き込まれず、注文の決済方法名は変わらなかった。
   - Webhook 経由の決済完了でも再現：HPOS 有効時は決済方法名が「QRコード決済」のまま、HPOS 無効時は「PayPay」に正しく更新された。

3. **createCode 失敗時に注文が on-hold のまま残る**
   `process_payment` は注文を `on-hold` に変更してから createCode を呼び出すため、呼び出しが失敗すると（実測は 401。ネットワーク障害や elepay 側の一時障害でも同様）注文が元に戻らない。

4. **エラーメッセージが重複表示される**：「決済に失敗しました。サポートまでお問い合わせください。」が 2 回表示される。

5. **PHP Notice / Deprecated（動作には影響なし）**
   - `Translation loading for the woocommerce-gateway-elepay domain was triggered too early`（WordPress 6.7 以降のチェック）。
   - elepay-php-sdk が PHP 8.1 以降で出す警告：`Return type of Elepay\Model\CodeReq::offsetExists($offset) should either be compatible with ArrayAccess::offsetExists(mixed $offset): bool ...`

6. **UI：elepay のロゴが大きすぎる**
   `$this->icon` が出力する `<img>` にサイズ指定がなく、SVG 自体にも固有サイズがないため、Twenty Twenty-Five などのブロックテーマではコンテナ幅いっぱいに広がる。

7. **Webhook：存在しない charge にも 500 を返す（軽微）**（本 PR で修正済み）
   charge 取得の失敗はすべて一時障害として 500 を返すため、charge が存在しない（404）場合も elepay 側でリトライが続く。404 は 400 として扱うのが望ましい。

### 未検証

- 決済完了後のリダイレクト（`status=captured` → 注文完了ページ → 注文が processing になる）。実際の決済が必要。
- elepay からの実際の Webhook 配信（実際のリクエストボディがモックで想定した形式と一致するかを含む）。ngrok や cloudflared などで公開 URL を用意し、実際の決済とあわせて検証する必要がある。

## 再現環境

`vendor/` に依存関係をインストール済みであることを前提に（README 参照）、以下の `docker-compose.yml` を使う。`PLUGIN_DIR` は本リポジトリを指す。

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
# クラシックチェックアウトで検証する場合：
wp post update "$(wp option get woocommerce_checkout_page_id)" \
  --post_content='<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->'
```

## 修正後の再検証（本 PR）

問題 2・7 の修正後、同じモック環境で再検証した。

| ケース | 結果 |
|---|---|
| HPOS 互換性 | WooCommerce が compatible と判定し、`wp wc hpos enable` がそのまま成功する |
| HPOS 有効時の Webhook 決済完了 | 決済方法名が「PayPay」に更新され、注文メモも「PayPay での支払い」になる |
| HPOS 無効時の Webhook 決済完了 | 決済方法名が「PayPay」に更新され、postmeta の `_payment_method_title` は 1 件のみ（重複なし） |
| charge が存在しない（API が 404） | 400 `Charge does not exist.` |
| API が 500 / 金額不一致 / 重複通知 | それぞれ 500 / 400 / 200（変更なし） |
