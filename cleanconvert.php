<?php
/**
 * Plugin Name: CleanConvert
 * Description: Server-side tracking for WooCommerce
 * Version: 1.0.0
 * Author: CleanConvert
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

define('CLEANCONVERT_VERSION',    '1.0.5');
define('CLEANCONVERT_OPTION_KEY', 'cleanconvert_settings');

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
require_once plugin_dir_path(__FILE__) . 'includes/updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/webhooks.php';

register_activation_hook(__FILE__,   'cleanconvert_activate');
register_deactivation_hook(__FILE__, 'cleanconvert_deactivate');

function cleanconvert_activate(): void {
    if (class_exists('WooCommerce')) {
        cleanconvert_register_webhooks();
    }
}

function cleanconvert_deactivate(): void {
    cleanconvert_delete_webhooks();
}