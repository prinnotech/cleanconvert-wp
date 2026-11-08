<?php
defined('ABSPATH') || exit;

const CLEANCONVERT_SIGNALS_META = '_cleanconvert_signals';
const CLEANCONVERT_SENT_META    = '_cleanconvert_purchase_sent';

function cleanconvert_get_settings(): array
{
    return get_option(CLEANCONVERT_OPTION_KEY, []);
}

function cleanconvert_ingest_base(): string
{
    $settings = cleanconvert_get_settings();
    return rtrim($settings['ingest_url'] ?? 'https://cleanconvert-backend-production.up.railway.app', '/');
}

function cleanconvert_webhook_token(): string
{
    $settings = cleanconvert_get_settings();
    return $settings['webhook_token'] ?? '';
}

// One server-side webhook: the full order, sent once when it becomes paid. Add-to-cart, page views
// and checkout views come from the browser pixel (they need the visitor's cookies anyway).
// "order.updated" covers every payment method: card orders become paid at checkout, bank transfer
// and cash on delivery when the shop marks them processing/completed.
function cleanconvert_webhook_map(): array
{
    return [
        'order.updated' => 'purchase',
    ];
}

function cleanconvert_register_webhooks(): void
{
    if (!class_exists('WC_Webhook')) return;

    $token = cleanconvert_webhook_token();
    if (empty($token)) return;

    $base = cleanconvert_ingest_base();

    foreach (cleanconvert_webhook_map() as $topic => $type) {
        $delivery_url = "{$base}/webhook/wordpress?type={$type}";
        if (cleanconvert_find_webhook_id($delivery_url)) continue;

        $webhook = new WC_Webhook();
        $webhook->set_name("CleanConvert – {$type}");
        $webhook->set_topic($topic);
        $webhook->set_delivery_url($delivery_url);
        // WooCommerce signs every delivery with this secret; the backend checks the signature.
        $webhook->set_secret($token);
        $webhook->set_status('active');
        $webhook->set_api_version('wp_api_v3');
        $webhook->save();
    }
}

// Removes every CleanConvert webhook, including ones registered by older plugin versions
// (action.woocommerce_* topics that only sent an order id).
function cleanconvert_delete_webhooks(): void
{
    if (!class_exists('WC_Webhook')) return;

    global $wpdb;
    $table = $wpdb->prefix . 'wc_webhooks';
    $like  = $wpdb->esc_like(cleanconvert_ingest_base() . '/webhook/wordpress') . '%';
    $ids   = $wpdb->get_col($wpdb->prepare("SELECT webhook_id FROM {$table} WHERE delivery_url LIKE %s", $like));

    foreach ($ids as $id) {
        $webhook = wc_get_webhook((int) $id);
        $webhook?->delete(true);
    }
}

function cleanconvert_find_webhook_id(string $delivery_url): ?int
{
    global $wpdb;
    $table = $wpdb->prefix . 'wc_webhooks';
    $id    = $wpdb->get_var(
        $wpdb->prepare("SELECT webhook_id FROM {$table} WHERE delivery_url = %s LIMIT 1", $delivery_url)
    );
    return $id ? (int) $id : null;
}

function cleanconvert_is_our_webhook($webhook): bool
{
    return $webhook instanceof WC_Webhook
        && str_starts_with($webhook->get_delivery_url(), cleanconvert_ingest_base() . '/webhook/wordpress');
}

// ─── Send each paid order exactly once ────────────────────────────────────────

add_filter('woocommerce_webhook_should_deliver', 'cleanconvert_should_deliver', 10, 3);

function cleanconvert_should_deliver($should_deliver, $webhook, $arg)
{
    if (!$should_deliver || !cleanconvert_is_our_webhook($webhook)) return $should_deliver;

    $order = wc_get_order($arg);
    if (!$order || !$order->is_paid() || $order->get_meta(CLEANCONVERT_SENT_META)) return false;

    $order->update_meta_data(CLEANCONVERT_SENT_META, time());
    $order->save_meta_data();
    return true;
}

// ─── Browser signals for server-side matching ────────────────────────────────

// At checkout the visitor's ad cookies and click ids are saved on the order, so the server
// webhook can send them later (often long after the browser is gone, e.g. bank transfer).
add_action('woocommerce_checkout_order_processed', 'cleanconvert_save_signals', 10, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'cleanconvert_save_signals', 10, 1);

function cleanconvert_save_signals($order_or_id): void
{
    $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order($order_or_id);
    if (!$order) return;

    $signals = [];
    foreach (['_fbp' => 'fbp', '_fbc' => 'fbc', '_ttp' => 'ttp'] as $cookie => $key) {
        if (!empty($_COOKIE[$cookie])) {
            $signals[$key] = substr(sanitize_text_field(wp_unslash($_COOKIE[$cookie])), 0, 300);
        }
    }

    // Click ids captured by pixel.js from the landing page URL.
    if (!empty($_COOKIE['cc_params'])) {
        $params = json_decode(wp_unslash($_COOKIE['cc_params']), true);
        if (is_array($params)) {
            foreach (['fbclid' => 'fbclid', 'ttclid' => 'ttclid', 'gclid' => 'gclid', 'gbraid' => 'gbraid', 'wbraid' => 'wbraid', 'msclkid' => 'msclkid', 'ScCid' => 'sc_click_id'] as $param => $key) {
                if (!empty($params[$param]) && is_string($params[$param])) {
                    $signals[$key] = substr(sanitize_text_field($params[$param]), 0, 300);
                }
            }
        }
    }

    if ($signals) {
        $order->update_meta_data(CLEANCONVERT_SIGNALS_META, $signals);
        $order->save_meta_data();
    }
}

add_filter('woocommerce_webhook_payload', 'cleanconvert_add_signals_to_payload', 10, 4);

function cleanconvert_add_signals_to_payload($payload, $resource, $resource_id, $webhook_id)
{
    if ($resource !== 'order' || !is_array($payload)) return $payload;

    $webhook = wc_get_webhook($webhook_id);
    if (!cleanconvert_is_our_webhook($webhook)) return $payload;

    $order   = wc_get_order($resource_id);
    $signals = $order ? $order->get_meta(CLEANCONVERT_SIGNALS_META) : null;
    if (is_array($signals)) $payload['cleanconvert'] = $signals;

    return $payload;
}
