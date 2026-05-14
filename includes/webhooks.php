<?php
defined('ABSPATH') || exit;

function cleanconvert_get_settings(): array {
    return get_option(CLEANCONVERT_OPTION_KEY, []);
}

function cleanconvert_ingest_base(): string {
    $settings = cleanconvert_get_settings();
    return rtrim($settings['ingest_url'] ?? 'https://cleanconvert-backend-production.up.railway.app', '/');
}

function cleanconvert_webhook_token(): string {
    $settings = cleanconvert_get_settings();
    return $settings['webhook_token'] ?? '';
}

function cleanconvert_webhook_map(): array {
    return [
        'order.created'    => 'purchase',
        'checkout.created' => 'initiateCheckout',
        'cart.created'     => 'addToCart',
    ];
}

function cleanconvert_register_webhooks(): void {
    if (!class_exists('WC_Webhook')) return;

    $token = cleanconvert_webhook_token();
    if (empty($token)) return;

    $base = cleanconvert_ingest_base();
    $map  = cleanconvert_webhook_map();

    foreach ($map as $topic => $type) {
        $delivery_url = "{$base}/webhook/wordpress?type={$type}";

        if (cleanconvert_webhook_exists($delivery_url)) continue;

        $webhook = new WC_Webhook();
        $webhook->set_name("CleanConvert – {$type}");
        $webhook->set_topic($topic);
        $webhook->set_delivery_url($delivery_url);
        $webhook->set_secret($token);
        $webhook->set_status('active');
        $webhook->set_api_version('wp_api_v3');
        $webhook->save();
    }
}

function cleanconvert_delete_webhooks(): void {
    if (!class_exists('WC_Webhook')) return;

    $base = cleanconvert_ingest_base();
    $map  = cleanconvert_webhook_map();

    foreach ($map as $topic => $type) {
        $delivery_url = "{$base}/webhook/wordpress?type={$type}";
        $id = cleanconvert_find_webhook_id($delivery_url);
        if ($id) {
            $webhook = wc_get_webhook($id);
            $webhook?->delete(true);
        }
    }
}

function cleanconvert_webhook_exists(string $delivery_url): bool {
    return (bool) cleanconvert_find_webhook_id($delivery_url);
}

function cleanconvert_find_webhook_id(string $delivery_url): ?int {
    global $wpdb;
    $table = $wpdb->prefix . 'wc_webhooks';
    $id    = $wpdb->get_var(
        $wpdb->prepare("SELECT webhook_id FROM {$table} WHERE delivery_url = %s LIMIT 1", $delivery_url)
    );
    return $id ? (int) $id : null;
}