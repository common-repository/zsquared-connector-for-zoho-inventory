<?php

namespace PCIS\ZSquared\Inventory;

use WP_Error;
use WC_Product;

class ZsqProductSync
{
    public function manualSync($page = 1) {
        $api_key = get_option('zsq_inv_api_key');
        $wh_select = get_option('zsq_inv_wh_select');
        if(empty($wh_select) || strlen($wh_select) == 0) {
            return "Please set the warehouse selection in the API Settings tab before attempting to sync products.";
        }
        if(!empty($api_key) && $api_key != "") {
            $url = ZSQ_INV_API_ENDPOINT."product/manual?api_key=".$api_key."&zsq_conn_host=".ZSQ_INV_HOST."&page=".$page;
            $response = wp_remote_get($url,
                array('sslverify' => FALSE));
            if(is_a($response, WP_Error::class)) {
                return "ERROR: " . $response->get_error_message();
            }
            $output = json_decode($response['body'], true);
            if (isset($output['data']['items']) && !empty($output['data']['items'])) {
                foreach ($output['data']['items'] as $product) {
                    $product_id = wc_get_product_id_by_sku($product['sku']);
                    if($product_id > 0) {
                        $wc_product = new WC_Product($product_id);
                        $this->updateProduct($wc_product, $product);
                    }
                    else {
                        $this->addProduct($product);
                    }
                }
                if($output['data']['more'] === 'true') {
                    return 'more';
                }
                return "Product sync complete";
            }
            else if (isset($output['message']) && $output['message'] != 'done') {
                return $output['message'];
            }
            else if (isset($output['message']) && $output['message'] == 'done') {
                return "Product sync complete";
            }
            else {
                return "No products found! Please make sure that your API key is correct. Could not sync at this time.";
            }
        }
        return "No connection set up";
    }

    public function dailySync($page = 1) {
        $api_key = get_option('zsq_inv_api_key');
        $price_sync = get_option('zsq_inv_daily_sync_price');
        $qty_sync = get_option('zsq_inv_daily_sync_qty');
        $wh_select = get_option('zsq_inv_wh_select');
        if(empty($wh_select) || strlen($wh_select) == 0) {
            // do not sync if there is no warehouse set
            return false;
        }
        if($price_sync == 1 || $qty_sync == 1) {
            if (!empty($api_key) && $api_key != "") {
                $url = ZSQ_INV_API_ENDPOINT . "product/daily?api_key=" . $api_key . "&zsq_conn_host=" . ZSQ_INV_HOST . "&page=" . $page;
                $response = wp_remote_get($url,
                    array('sslverify' => FALSE));
                if (is_a($response, WP_Error::class)) {
                    echo "ERROR: " . $response->get_error_message();
                    die;
                }
                $output = json_decode($response['body'], true);
                if (isset($output['data']['items']) && !empty($output['data']['items'])) {
                    foreach ($output['data']['items'] as $product) {
                        $product_id = wc_get_product_id_by_sku($product['sku']);
                        if ($product_id > 0) {
                            $wc_product = new WC_Product($product_id);
                            $this->updateProduct($wc_product, $product);
                        }
                    }
                    if ($output['data']['more'] === 'true') {
                        $page++;
                        return $this->dailySync($page);
                    }
                    return "Daily product sync complete";
                }
                else if (isset($output['message']) && $output != 'done') {
                    return $output['message'];
                }
                else if (isset($output['message']) && $output['message'] == 'done') {
                    return "Daily product sync complete";
                }
                else {
                    return "No products found! Could not sync at this time";
                }
            }
            return "Sync active but no connection has been set up.";
        }
        // just return false if no sync is set up
        return false;
    }

    private function updateProduct(WC_Product $product, $product_data) {
        if(get_option('zsq_inv_daily_sync_price') == 1) {
            $product->set_regular_price($product_data['rate']);
            $product->set_price($product_data['rate']);
        }
        if(get_option('zsq_inv_daily_sync_qty') == 1) {
            $product->set_stock_quantity($this->getQtyFromProductData($product_data));
        }
        $product->save();
    }

    private function addProduct($product_data) {
        try {
            $product = new WC_Product();
            $product->set_regular_price($product_data['rate']);
            $product->set_price($product_data['rate']);
            $product->set_name($product_data['name']);
            $product->set_sku($product_data['sku']);
            $product->set_stock_quantity($this->getQtyFromProductData($product_data));
            $product->set_manage_stock(true);
            $product->set_catalog_visibility('hidden');
            $product->save();
        }
        catch (\Exception $e) {
            ZsqSlackNotifications::send('Error on adding WP_Product, SKU = '.$product_data['sku'].': '.$e->getMessage());
            error_log('Error on adding WP_Product, SKU = '.$product_data['sku'].': '.$e->getMessage());
        }
    }

    private function getQtyFromProductData($product_data) {
        $qty = 0;
        if(isset($product_data['actual_available_stock'])) {
            $qty = $product_data['actual_available_stock'];
        }
        if(isset($product_data['warehouse_data'])) {
            $warehouses = json_decode($product_data['warehouse_data']);
            $selected_wh = get_option('zsq_inv_wh_select');
            if(!empty($selected_wh) && strlen($selected_wh) > 0) {
                foreach ($warehouses as $w) {
                    if($w['warehouse_id'] == $selected_wh) {
                        $qty = $w['stock'];
                    }
                }
            }
        }
        return $qty;
    }
}