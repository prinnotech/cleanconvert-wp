<?php
/**
 * Plugin Name: CleanConvert
 * Description: Server-side tracking for WooCommerce
 * Version: 1.0.6
 * Author: CleanConvert
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

// Keep in sync with "Version:" above: the update checker reads the header.
define('CLEANCONVERT_VERSION',    '1.0.6');
define('CLEANCONVERT_OPTION_KEY', 'cleanconvert_settings');
define('CLEANCONVERT_VERSION_OPTION', 'cleanconvert_installed_version');

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
require_once plugin_dir_path(__FILE__) . 'includes/updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/webhooks.php';

register_activation_hook(__FILE__,   'cleanconvert_activate');
register_deactivation_hook(__FILE__, 'cleanconvert_deactivate');

function cleanconvert_activate(): void {
    if (class_exists('WooCommerce')) {
        cleanconvert_register_webhooks();
        update_option(CLEANCONVERT_VERSION_OPTION, CLEANCONVERT_VERSION);
    }
}

function cleanconvert_deactivate(): void {
    cleanconvert_delete_webhooks();
}

// Auto-updates don't run the activation hook. When the version changes, swap the old webhooks
// (which only sent an order id) for the new order webhook.
add_action('admin_init', 'cleanconvert_maybe_upgrade');

function cleanconvert_maybe_upgrade(): void {
    if (!class_exists('WooCommerce')) return;
    if (get_option(CLEANCONVERT_VERSION_OPTION) === CLEANCONVERT_VERSION) return;

    cleanconvert_delete_webhooks();
    cleanconvert_register_webhooks();
    update_option(CLEANCONVERT_VERSION_OPTION, CLEANCONVERT_VERSION);
}
