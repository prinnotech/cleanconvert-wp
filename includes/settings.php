<?php
defined('ABSPATH') || exit;

add_action('admin_menu', 'cleanconvert_add_menu');
add_action('admin_init', 'cleanconvert_register_settings');
add_action('update_option_' . CLEANCONVERT_OPTION_KEY, 'cleanconvert_on_settings_save', 10, 2);
add_action('wp_enqueue_scripts', 'cleanconvert_enqueue_pixel');

function cleanconvert_add_menu(): void
{
    add_options_page(
        'CleanConvert Settings',
        'CleanConvert',
        'manage_options',
        'cleanconvert',
        'cleanconvert_settings_page'
    );
}

function cleanconvert_register_settings(): void
{
    register_setting('cleanconvert_group', CLEANCONVERT_OPTION_KEY);
}

function cleanconvert_settings_page(): void
{
    $settings = get_option(CLEANCONVERT_OPTION_KEY, []);
    $token    = $settings['webhook_token'] ?? '';
    $ingest   = $settings['ingest_url']    ?? 'https://cleanconvert-backend-production.up.railway.app';
?>
    <div class="wrap">
        <h1>CleanConvert</h1>
        <form method="post" action="options.php">
            <?php settings_fields('cleanconvert_group'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="cleanconvert_token">Webhook Token</label></th>
                    <td>
                        <input
                            type="text"
                            id="cleanconvert_token"
                            name="<?= CLEANCONVERT_OPTION_KEY ?>[webhook_token]"
                            value="<?= esc_attr($token) ?>"
                            class="regular-text" />
                        <p class="description">Paste your webhook token from the CleanConvert dashboard.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>
        <h2>Webhook Status</h2>
        <?php cleanconvert_render_webhook_status(); ?>

        <form method="post">
            <?php wp_nonce_field('cleanconvert_reregister'); ?>
            <input type="hidden" name="cleanconvert_action" value="reregister">
            <?php submit_button('Re-register Webhooks', 'secondary'); ?>
        </form>
    </div>
<?php

    if (
        isset($_POST['cleanconvert_action'])
        && $_POST['cleanconvert_action'] === 'reregister'
        && check_admin_referer('cleanconvert_reregister')
    ) {
        cleanconvert_delete_webhooks();
        cleanconvert_register_webhooks();
        echo '<div class="notice notice-success"><p>Webhooks re-registered.</p></div>';
    }
}

function cleanconvert_render_webhook_status(): void
{
    $base = cleanconvert_ingest_base();
    $map  = cleanconvert_webhook_map();

    echo '<table class="widefat" style="max-width:600px"><thead><tr>
        <th>Topic</th><th>Delivery URL</th><th>Status</th>
    </tr></thead><tbody>';

    foreach ($map as $topic => $type) {
        $url    = "{$base}/webhook/wordpress?type={$type}";
        $id     = cleanconvert_find_webhook_id($url);
        $badge  = $id
            ? '<span style="color:green">● Active</span>'
            : '<span style="color:red">● Not registered</span>';

        echo "<tr>
            <td><code>{$topic}</code></td>
            <td><code>" . esc_html($url) . "</code></td>
            <td>{$badge}</td>
        </tr>";
    }

    echo '</tbody></table>';
}

function cleanconvert_on_settings_save(array $old, array $new): void
{
    $old_token = $old['webhook_token'] ?? '';
    $new_token = $new['webhook_token'] ?? '';

    if ($old_token !== $new_token) {
        cleanconvert_delete_webhooks();
        cleanconvert_register_webhooks();
    }
}

function cleanconvert_enqueue_pixel(): void
{
    if (!class_exists('WooCommerce')) return;

    $settings = get_option(CLEANCONVERT_OPTION_KEY, []);
    $token    = $settings['webhook_token'] ?? '';
    $base     = rtrim($settings['ingest_url'] ?? 'https://cleanconvert-backend-production.up.railway.app', '/');

    if (empty($token)) return;

    $order_data = null;
    if (is_wc_endpoint_url('order-received')) {
        global $wp;
        $order_id  = absint($wp->query_vars['order-received'] ?? 0);
        $order_key = sanitize_text_field($_GET['key'] ?? '');
        $order     = wc_get_order($order_id);
        if ($order && $order->key_is_valid($order_key)) {
            $order_data = [
                'order_id'   => $order_id,
                'total'      => $order->get_total(),
                'currency'   => $order->get_currency(),
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
            ];
        }
    }

    wp_enqueue_script(
        'cleanconvert-pixel',
        plugin_dir_url(dirname(__FILE__)) . 'includes/pixel.js',
        [],
        CLEANCONVERT_VERSION,
        true
    );

    wp_localize_script('cleanconvert-pixel', 'cleanconvert_vars', [
        'backend_url'       => $base,
        'token'             => $token,
        'is_checkout'       => is_checkout() && !is_wc_endpoint_url('order-received'),
        'is_order_received' => is_wc_endpoint_url('order-received'),
        'order'             => $order_data,
    ]);
}
