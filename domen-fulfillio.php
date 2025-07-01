<?php
/**
 * Plugin Name: Fulfillio integracija
 * Description: Ta plugin omogoči izbiro novega custom topica za webhooke. Ta topic pošlje samo določena naročila ob določenih trenutkih. Preveri kodo za delovanje, ampak originalno gre tako: ko naročilo pride v status processing ali placilo-potrjeno, se preveri, če izpolnjuje pogoje za fulfillment. Ti pogoji so: samo en različen izdelek, količina = 1, sku=igre-111. Webhook se mora vseeno naštimat preko woocommerce backenda. CUSTOM STATUS FULFILLIO JE NAREJEN V functions.php
 * Version: 1.0
 * Author: Domen
 */
// ---- STEP 1: Register the custom webhook topic ----

// --- Register new webhook topic: order.domen_fulfillment ---
add_filter('woocommerce_webhook_topic_hooks', function ($topic_hooks) {
    return array_merge($topic_hooks, [
        'order.domen_fulfillment' => ['trigger_fulfillment_webhook'],
    ]);
});

add_filter('woocommerce_valid_webhook_events', function ($events) {
    return array_merge($events, ['domen_fulfillment']);
});

add_filter('woocommerce_webhook_topics', function ($topics) {
    return array_merge($topics, [
        'order.domen_fulfillment' => __('Domen fulfillment', 'woocommerce'),
    ]);
});

// add_action('woocommerce_webhook_delivery', function($http_args, $response, $duration, $webhook_id, $payload, $resource_id) {
//     error_log("hello world" . wp_remote_retrieve_body($response));
// }, 10, 6);

// add_action('woocommerce_webhook_delivery', function() {
//     error_log('woocommerce_webhook_delivery fired!');
// }, 10, 0);

// --- Hook into status transitions, with extra logging in the native woocommerce backend---
add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
    $logger = wc_get_logger();
    $context = ['source' => 'domen-fulfillment'];

    $log_prefix = sprintf('[%s] Order #%d changed to status "%s".', current_time('mysql'), $order_id, $new_status);

    if (!in_array($new_status, ['processing', 'placilo-potrjeno'])) {
        $logger->info("$log_prefix Ignored – status is not one of the fulfillment triggers.", $context);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        $logger->error("$log_prefix Failed to load order object.", $context);
        return;
    }

    $items = $order->get_items();
    $item_count = count($items);
    $item_details = [];

    foreach ($items as $item) {
        if ($item instanceof WC_Order_Item_Product) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : 'unknown';
            $item_details[] = sprintf(
                'Item: "%s" | SKU: %s | Qty: %d',
                $item->get_name(),
                $sku,
                $item->get_quantity()
            );
        }
    }

    $log_items = implode(" || ", $item_details);

    if ($item_count !== 1) {
        $logger->warning("$log_prefix Skipped – expected exactly 1 different item, found $item_count. different items: $log_items", $context);
        return;
    }

    $item = array_values($items)[0];
    $product = $item->get_product();
    $sku = $product ? $product->get_sku() : '';

    if ($item->get_quantity() !== 1 || $sku !== 'igre-111') {
        $logger->info("$log_prefix Skipped – SKU mismatch or quantity not 1. SKU: $sku, Qty: {$item->get_quantity()}", $context);
        return;
    }

    $logger->info("$log_prefix Passed fulfillment conditions. Items: $log_items", $context);

    // Trigger webhook
    //do_action('trigger_fulfillment_webhook', $order_id, $order);
    //$order->update_status('wc-fulfillio', 'Plugin for fulfillment: changed order status to fulfillio after webhook trigger.');
    //$logger->info("Changed order status to fulfillio", $context);


}, 10, 3);

add_action('domen_fulfillio_daily_check', function () {
    $logger = wc_get_logger();
    $context = ['source' => 'domen-fulfillment'];
    $orders = wc_get_orders([
        'status' => 'fulfillio',
        'limit' => -1,
        'return' => 'objects',
    ]);

    //log the order count
    $logger->info(sprintf("Daily check: Found %d orders in 'fulfillio' status.", count($orders)), $context);


    //for each order, call the api
    foreach ($orders as $order) {
        $order_id = $order->get_id();

        $api_url = 'https://app.fulfillio.si/api/user/getOrderStatus';
        $api_key = trim(file_get_contents(plugin_dir_path(__FILE__) . 'api.key'));
        
        $body = [
            'apiKey' => $api_key,
            'externalOrderId' => $order_id,
        ];
        
        $logger->info("Calling Fulfillio API for order #$order_id", $context);

        $response = wp_remote_post($api_url, [
            'method' => 'POST',
            'body' => json_encode($body),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
        
        if (is_wp_error($response)) {
            $logger->error("Failed to call Fulfillio API for order #$order_id: " . $response->get_error_message(), $context);
            continue;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        //log the data
        $logger->info("Fulfillio API response for order #$order_id: " . print_r($data, true), $context);


    }
});