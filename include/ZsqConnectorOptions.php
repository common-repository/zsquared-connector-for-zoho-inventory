<?php

namespace PCIS\ZSquared\Inventory;

use WP_Error;
use WC_Tax;

class ZsqConnectorOptions
{
    private $api_key;
    private $order_prefix;
    private $hook_trigger;
    private $server_online = false;
    private $account_connect = false;
    private $warehouse;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'pluginMenu'));

        add_action('admin_init', array($this, 'setupSections'));
        add_action('admin_init', array($this, 'setupFields'));

        add_action('wp_ajax_zsq_inv_manual_sync', array($this, 'manualSync'));

        $this->api_key = get_option('zsq_inv_api_key');
        $this->order_prefix = get_option('zsq_inv_order_prefix');
        $this->hook_trigger = get_option('zsq_inv_hook_trigger');
        $this->warehouse = get_option('zsq_inv_wh_select');
    }

    public function pluginMenu()
    {
        add_options_page(
            'ZSquared Connector to Zoho Inventory',
            'ZSquared Connector to Zoho Inventory',
            'manage_options',
            'zsq_inv.php',
            array($this, 'optionsMenu')
        );
    }

    public function optionsMenu()
    {
        if (isset($_REQUEST['replay']) && $_REQUEST['replay'] == 1 && isset($_REQUEST['zsq_inv_sales_order_id'])) {
            $order_number = sanitize_text_field($_REQUEST['zsq_inv_sales_order_id']);
            $check = zsq_inv_sync_order($order_number, '', $this->hook_trigger);
            if (false === $check) {
                echo "<div class=\"notice notice-error is-dismissible\"><p>Order replay failed. Please see Slack output or error output for details.</p></div>";
            } else if (true === $check) {
                echo "<div class=\"notice notice-success is-dismissible\"><p>Order replay accepted. ZSquared will now attempt to transfer the order into Zoho Inventory again.</p></div>";
            } else if (is_null($check)) {
                echo "<div class=\"notice notice-warning is-dismissible\"><p>Order replay not triggered due to status. Please review your settings.</p></div>";
            } else if (is_string($check)) {
                echo "<div class=\"notice notice-error is-dismissible\"><p>Order replay failed: $check</p></div>";
            }
        } else if (isset($_REQUEST['taxupdate']) && $_REQUEST['taxupdate'] == 1) {
            foreach ($_REQUEST as $idx => $r) {
                if (strpos($idx, 'zsq_inv_ex_to_woo_tax_map_') !== false) {
                    update_option(sanitize_text_field($idx), sanitize_text_field($r));
                }
            }
        } else {
            if (isset($_REQUEST['zsq_inv_setting_supdate']) && $_REQUEST['zsq_inv_setting_supdate'] == 1) {
                if (isset($_REQUEST['zsq_inv_api_key'])) {
                    update_option('zsq_inv_api_key', sanitize_text_field($_REQUEST['zsq_inv_api_key']));
                    $this->api_key = sanitize_text_field($_REQUEST['zsq_inv_api_key']);
                }
                if (isset($_REQUEST['zsq_inv_order_prefix'])) {
                    update_option('zsq_inv_order_prefix', sanitize_text_field($_REQUEST['zsq_inv_order_prefix']));
                    $this->order_prefix = sanitize_text_field($_REQUEST['zsq_inv_order_prefix']);
                }
                if (isset($_REQUEST['zsq_inv_slack_channel'])) {
                    update_option('zsq_inv_slack_channel', sanitize_text_field($_REQUEST['zsq_inv_slack_channel']));
                }
                if (isset($_REQUEST['zsq_inv_slack_url'])) {
                    update_option('zsq_inv_slack_url', esc_url($_REQUEST['zsq_inv_slack_url']));
                }
                if (isset($_REQUEST['zsq_inv_hook_trigger'])) {
                    update_option('zsq_inv_hook_trigger', sanitize_text_field($_REQUEST['zsq_inv_hook_trigger']));
                    $this->hook_trigger = sanitize_text_field($_REQUEST['zsq_inv_hook_trigger']);
                }
                if (isset($_REQUEST['zsq_inv_wh_select'])) {
                    update_option('zsq_inv_wh_select', sanitize_text_field($_REQUEST['zsq_inv_wh_select']));
                    $this->warehouse = sanitize_text_field($_REQUEST['zsq_inv_wh_select']);
                }
                if (isset($_REQUEST['zsq_inv_daily_sync_price']) && $_REQUEST['zsq_inv_daily_sync_price'] == 1) {
                    update_option('zsq_inv_daily_sync_price', 1);
                } else {
                    update_option('zsq_inv_daily_sync_price', 0);
                }
                if (isset($_REQUEST['zsq_inv_daily_sync_qty']) && $_REQUEST['zsq_inv_daily_sync_qty'] == 1) {
                    update_option('zsq_inv_daily_sync_qty', 1);
                } else {
                    update_option('zsq_inv_daily_sync_qty', 0);
                }
            }
        }
        if (function_exists('wc_get_order')) {
            $woo_taxes = $this->getWooTaxes();
            $server = $this->getServerStatus();
            if (is_null($this->api_key) || $this->api_key == "") {
                $ex_taxes = [];
                $warehouses = [];
                $status = 'No connection set up';
            } else if ($server != 'Connected') {
                $ex_taxes = [];
                $warehouses = [];
                $status = 'Could not reach ZSquared Server';
            } else {
                $status = $this->getConnectionStatus();
                $ex_taxes = $this->getExternalTaxes();
            }
            $orders = $this->getRecentOrders();
            include(ZSQ_INV_PLUGIN_PATH . "/templates/scripts.php");
            include(ZSQ_INV_PLUGIN_PATH . "/templates/options.php");
        }
    }

    public function setupSections()
    {
        add_settings_section('zsq_inv_api_section', 'API Settings', false, 'zsq_inv_fields');
        add_settings_section('zsq_inv_slack_section', 'Slack Notification Settings (Optional)', array($this, 'displaySlackInfo'), 'zsq_inv_slack_fields');
        add_settings_section('zsq_inv_sync_section', 'Inventory Sync Settings (Optional)', array($this, 'invSync'), 'zsq_inv_inv_sync');
    }

    public function setupFields()
    {
        add_settings_field('zsq_inv_api_key', 'Connector API Key *', array($this, 'getApiKey'), 'zsq_inv_fields', 'zsq_inv_api_section');
        add_settings_field('zsq_inv_hook_trigger', 'Woocommerce Trigger Status *', array($this, 'getTriggerStatus'), 'zsq_inv_fields', 'zsq_inv_api_section', ['required' => true]);
        add_settings_field('zsq_inv_order_prefix', 'Order Number Prefix', array($this, 'getOrderPrefix'), 'zsq_inv_fields', 'zsq_inv_api_section');
        add_settings_field('zsq_inv_wh_select', 'Warehouse Selection', array($this, 'getWarehouseSelection'), 'zsq_inv_fields', 'zsq_inv_api_section');

        add_settings_field('zsq_inv_slack_channel', 'Slack Channel', array($this, 'getSlackChannel'), 'zsq_inv_slack_fields', 'zsq_inv_slack_section');
        add_settings_field('zsq_inv_slack_url', 'Slack Webhook URL', array($this, 'getSlackWebhook'), 'zsq_inv_slack_fields', 'zsq_inv_slack_section');

        add_settings_field('zsq_inv_daily_sync_price', 'Update price daily', array($this, 'getPriceSync'), 'zsq_inv_inv_sync', 'zsq_inv_sync_section');
        add_settings_field('zsq_inv_daily_sync_qty', 'Update stock quantity daily', array($this, 'getQtySync'), 'zsq_inv_inv_sync', 'zsq_inv_sync_section');
    }

    public function getApiKey($arguments)
    {
        echo '<input name="zsq_inv_api_key" id="zsq_inv_api_key" type="text" value="' . $this->api_key . '" required />';
        register_setting('zsq_inv_fields', 'zsq_inv_api_key');
    }

    public function getTriggerStatus($arguments)
    {
        $statuses = wc_get_order_statuses();
        ?>
        <select name="zsq_inv_hook_trigger" id="zsq_inv_hook_trigger" required>
            <option>-</option>
            <?php foreach ($statuses as $key => $label) : ?>
                <option value="<?php echo $key; ?>"<?php echo($key == $this->hook_trigger ? " selected" : ""); ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <br/>
        <small>When an order is updated to this WooCommerce status, the ZSquared connector will send the sales order to
            Zoho inventory.</small>
        <?php
        register_setting('zsq_inv_fields', 'zsq_inv_hook_trigger');
    }

    public function getWarehouseSelection($arguments)
    {
        $warehouses = $this->getExternalWarehouses();
        if (!empty($warehouses)) : ?>
            <select name="zsq_inv_wh_select" id="zsq_inv_wh_select">
                <?php foreach ($warehouses as $w) : ?>
                    <?php
                    $selected_wh = "";
                    if (!empty($this->warehouse) && $this->warehouse == $w['warehouse_id']) {
                        $selected_wh = " selected";
                    } else if ($w['is_primary'] == 1) {
                        $selected_wh = " selected";
                    }
                    ?>
                    <option value="<?php echo esc_attr($w['warehouse_id']); ?>" <?php echo esc_attr($selected_wh); ?>><?php echo esc_html($w['warehouse_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <br/>
            <small>Select the warehouse through which orders should be fulfiled. If none are found, the orders will be transferred without a warehouse ID; otherwise the primary warehouse will be used.</small>
        <?php else: ?>
            <p>No warehouses found; orders will be transferred without an associated warehouse ID.</p>
        <?php endif;
        register_setting('zsq_inv_fields', 'zsq_inv_wh_select');
    }

    public function getSlackChannel($arguments)
    {
        echo '<input name="zsq_inv_slack_channel" id="zsq_inv_slack_channel" type="text" value="' . get_option('zsq_inv_slack_channel') . '" />';
        register_setting('zsq_inv_slack_fields', 'zsq_inv_slack_channel');
    }

    public function getSlackWebhook($arguments)
    {
        echo '<input name="zsq_inv_slack_url" id="zsq_inv_slack_url" type="text" value="' . get_option('zsq_inv_slack_url') . '" />';
        register_setting('zsq_inv_slack_fields', 'zsq_inv_slack_url');
    }

    public function getPriceSync($arguments)
    {
        $price = get_option('zsq_inv_daily_sync_price');
        if ($price == 1) {
            echo '<input name="zsq_inv_daily_sync_price" id="zsq_inv_daily_sync_price" type="checkbox" checked value="1" />';
        } else {
            echo '<input name="zsq_inv_daily_sync_price" id="zsq_inv_daily_sync_price" type="checkbox" value="1" />';
        }
        register_setting('zsq_inv_inv_sync', 'zsq_inv_daily_sync_price');
    }

    public function getQtySync($arguments)
    {
        $qty = get_option('zsq_inv_daily_sync_qty');
        if ($qty == 1) {
            echo '<input name="zsq_inv_daily_sync_qty" id="zsq_inv_daily_sync_qty" type="checkbox" checked value="1" />';
        } else {
            echo '<input name="zsq_inv_daily_sync_qty" id="zsq_inv_daily_sync_qty" type="checkbox" value="1" />';
        }
        register_setting('zsq_inv_inv_sync', 'zsq_inv_daily_sync_qty');
    }

    public function displaySlackInfo()
    {
        echo "<p>Add settings here for error output and order summaries to be reported to your Slack channel. For more information, see <a href=\"https://api.slack.com/incoming-webhooks\" target=\"_blank\">Slack Documentation</a>.</p>";
    }

    public function invSync()
    {
        echo "<p>Keep Woocommerce products up to date by automatically syncing changes from Zoho Inventory. <br /><strong>Note that products will be updated every 24 hrs.</strong></p>";
    }

    public function getOrderPrefix($arguments)
    {
        $order_prefix = $this->order_prefix;
        if (empty($order_prefix)) {
            $order_prefix = "ZC";
        }
        echo '<input name="zsq_inv_order_prefix" id="zsq_inv_order_prefix" type="text" placeholder="ZC" value="' . $order_prefix . '" />';
        echo '<br /><small>Order Numbers will be sent to Zoho Inventory in the format ' . $order_prefix . '-XXXX-DDMMYY</small>';
        register_setting('zsq_inv_fields', 'zsq_inv_order_prefix');
    }

    private function getExternalTaxes() {
        if(empty($this->api_key) || $this->api_key == "") {
            // can't get taxes without the api key
            return [];
        }
        if(!$this->server_online || !$this->account_connect) {
            // can't get taxes if the server/account are not responding
            return [];
        }
        $url = ZSQ_INV_API_ENDPOINT."taxes?api_key=".$this->api_key."&zsq_conn_host=".ZSQ_INV_HOST;
        $response = wp_remote_get($url,
            array('sslverify' => FALSE));
        if(is_a($response, WP_Error::class)) {
            ZsqSlackNotifications::send('Error on taxes API call: '.$response->get_error_message());
            return [];
        }
        $output = json_decode($response['body'], true);
        if (isset($output['data']['taxes']) && is_array($output['data']['taxes'])) {
            return $output['data']['taxes'];
        }
        else {
            ZsqSlackNotifications::send('Error on taxes API call: '.print_r($output['message'], true));
        }
        return [];
    }

    private function getExternalWarehouses()
    {
        if (empty($this->api_key) || $this->api_key == "") {
            // can't get warehouses without the api key
            return [];
        }
        if (!$this->server_online || !$this->account_connect) {
            // can't get warehouses if the server/account are not responding
            return [];
        }
        $url = ZSQ_INV_API_ENDPOINT . "warehouses?api_key=" . $this->api_key . "&zsq_conn_host=" . ZSQ_INV_HOST;
        $response = wp_remote_get($url,
            array('sslverify' => FALSE));
        if (is_a($response, WP_Error::class)) {
            ZsqSlackNotifications::send('Error on warehouses API call: ' . $response->get_error_message());
            return [];
        }
        $output = json_decode($response['body'], true);
        if (isset($output['data']['warehouses']) && is_array($output['data']['warehouses'])) {
            return $output['data']['warehouses'];
        } else {
            ZsqSlackNotifications::send('Error on warehouses API call: ' . print_r($output, true));
        }
        return [];
    }

    private function getWooTaxes()
    {
        $classes = WC_Tax::get_tax_classes();
        $taxes = WC_Tax::get_rates_for_tax_class('');
        foreach ($classes as $c) {
            $taxes_in_class = WC_Tax::get_rates_for_tax_class($c);
            foreach ($taxes_in_class as $t) {
                $taxes[] = $t;
            }
        }
        return $taxes;
    }

    private function getConnectionStatus()
    {
        if (!empty($this->api_key) && $this->api_key != "") {
            $url = ZSQ_INV_API_ENDPOINT . "status?api_key=" . $this->api_key . "&zsq_conn_host=" . ZSQ_INV_HOST;
            $response = wp_remote_get($url,
                array('sslverify' => FALSE));
            if (is_a($response, WP_Error::class)) {
                return "WP ERROR: " . $response->get_error_message();
            }
            $output = json_decode($response['body'], true);
            if (isset($output['data']['status']) && !empty($output['data']['status'])) {
                $this->account_connect = true;
                return $output['data']['status'];
            }
            if (isset($output['message']) && !empty($output['message'])) {
                return $output['message'];
            }
            return "Could not determine status at this time";
        }
        return "No connection set up";
    }

    private function getServerStatus()
    {
        if (!empty($this->api_key) && $this->api_key != "") {
            $url = ZSQ_INV_API_ENDPOINT . "server?api_key=" . $this->api_key . "&zsq_conn_host=" . ZSQ_INV_HOST;
            $response = wp_remote_get($url,
                array('sslverify' => FALSE));
            if (is_a($response, WP_Error::class)) {
                return "WP ERROR: " . $response->get_error_message();
            }
            $output = json_decode($response['body'], true);
            if (isset($output['data']['server']) && !empty($output['data']['server'])) {
                $this->server_online = true;
                return $output['data']['server'];
            }
            return "Not connected";
        }
        return "No connection set up";
    }

    private function getRecentOrders()
    {
        if (!empty($this->api_key) && $this->api_key != "") {
            $url = ZSQ_INV_API_ENDPOINT . "recent?api_key=" . $this->api_key . "&zsq_conn_host=" . ZSQ_INV_HOST;
            $response = wp_remote_get($url,
                array('sslverify' => FALSE));
            if (is_a($response, WP_Error::class)) {
                [];
            }
            $output = json_decode($response['body'], true);
            if (isset($output['data']['orders']) && !empty($output['data']['orders'])) {
                return $output['data']['orders'];
            }
            return [];
        }
        return [];
    }

    public function manualSync()
    {
        if (isset($_REQUEST['page'])) {
            $page = sanitize_text_field($_REQUEST['page']);
        } else {
            $page = 1;
        }
        $manualsync = new ZsqProductSync();
        echo $manualsync->manualSync($page);
        die;
    }
}
