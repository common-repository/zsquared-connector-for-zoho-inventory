<?php
/*
Plugin Name: ZSquared Connector for Zoho Inventory
Plugin URI: https://zsquared.ca
Description: Syncs sales orders from Woocommerce to Zoho Inventory
Version: 1.0.4
Requires PHP: 7.0
Author: PCIS
Author URI: https://pcis.com
License: GPL2.0
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

define('ZSQ_INV_PLUGIN_VERSION', '1.0.4');
define('ZSQ_INV_PLUGIN_PATH', dirname(__FILE__));
define("ZSQ_INV_PLUGIN_ASSETS", plugin_dir_url(__FILE__));
$zsq_conn_host_info = parse_url(get_site_url());
define("ZSQ_INV_HOST", $zsq_conn_host_info['host']);

require_once __DIR__ . '/include/settings.php';

$zsq_inv_daily_sync_price = get_option('zsq_inv_daily_sync_price');
if(!$zsq_inv_daily_sync_price || is_null($zsq_inv_daily_sync_price)) {
    update_option('zsq_inv_daily_sync_price', 0);
}

$zsq_inv_daily_sync_qty = get_option('zsq_inv_daily_sync_qty');
if(!$zsq_inv_daily_sync_qty || is_null($zsq_inv_daily_sync_qty)) {
    update_option('zsq_inv_daily_sync_qty', 0);
}

if ( ! wp_next_scheduled( 'zsq_inv_daily_sync' ) ) {
    wp_schedule_event( strtotime('01:00:00'), 'daily', 'zsq_inv_daily_sync' );
}

require_once __DIR__ . '/include/ZsqOrderSync.php';
require_once __DIR__ . '/include/ZsqConnectorOptions.php';
require_once __DIR__ . '/include/ZsqSlackNotifications.php';
require_once __DIR__ . '/include/ZsqProductSync.php';

function zsq_inv_sync_order($order_id, $old_status, $new_status)
{
    // making allowances for the weird way that WP/WC handles status

    $trigger_status = str_replace("wc_", "", get_option('zsq_inv_hook_trigger'));
    $trigger_status = str_replace("wc-", "", $trigger_status);

    $new_status = str_replace("wc_", "", $new_status);
    $new_status = str_replace("wc-", "", $new_status);

    if (!$order_id || strtolower($new_status) != strtolower($trigger_status)) {
        return null;
    }
    $order = wc_get_order($order_id);
    if($order == false) {
        return "Order $order_id not found";
    }
    $obj = new \PCIS\ZSquared\Inventory\ZsqOrderSync($order);
    if(!$obj->getState()) {
        return $obj->error_message;
    }
    return $obj->getState();
}

function zsq_inv_daily_sync() {
    $obj = new \PCIS\ZSquared\Inventory\ZsqProductSync();
    $response = $obj->dailySync(1);
    if(is_string($response) && $response != 'Daily product sync complete') {
        \PCIS\ZSquared\Inventory\ZsqSlackNotifications::send('Daily sync failed; ERROR: '.$response);
    }
}

function zsq_enqueue_custom_admin_style($hook_suffix) {
    if($hook_suffix != 'settings_page_zsq_inv') {
        return;
    }
    // Load your css.
    wp_register_style( 'zsq_admin_css', ZSQ_INV_PLUGIN_ASSETS . 'assets/css/style.css');
    wp_enqueue_style( 'zsq_admin_css' );
}

function zsq_check_wc_function() {
    if(!function_exists('wc_get_order')) {
        include ZSQ_INV_PLUGIN_PATH."/templates/activation_notice.php";
    }
}

function zsq_check_api_key() {
    $zsq_api_key = get_option('zsq_inv_api_key');
    if(empty($zsq_api_key) && !isset($_REQUEST['zsq_inv_api_key'])) {
        include ZSQ_INV_PLUGIN_PATH."/templates/missing_api_key.php";
    }
}

add_action( 'admin_enqueue_scripts', 'zsq_enqueue_custom_admin_style' );
add_action('woocommerce_order_status_changed', 'zsq_inv_sync_order', 10, 3);
add_action('admin_notices', 'zsq_check_wc_function');
add_action('admin_notices', 'zsq_check_api_key');

new \PCIS\ZSquared\Inventory\ZsqConnectorOptions();
