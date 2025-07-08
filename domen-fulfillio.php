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
    do_action('trigger_fulfillment_webhook', $order_id, $order);
    $order->update_status('wc-fulfillio', 'Plugin for fulfillment: changed order status to fulfillio after webhook trigger.');
    $logger->info("Changed order status to fulfillio", $context);


}, 10, 3);

// Schedule the daily event at 17:00 if not already scheduled
add_action('init', function () {
    if (!wp_next_scheduled('domen_fulfillio_daily_check')) {
        // Get the next 17:00 timestamp (today or tomorrow)
        $now = current_time('timestamp');
        $next_17 = strtotime('17:00', $now);
        if ($now > $next_17) {
            $next_17 = strtotime('tomorrow 17:00', $now);
        }
        wp_schedule_event($next_17, 'daily', 'domen_fulfillio_daily_check');
    }
});

// Unschedule on plugin deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('domen_fulfillio_daily_check');
});


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
            //todo ALERT THE ADMIN that the API call failed
            continue;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        //log the data
        $logger->info("Fulfillio API response for order #$order_id: " . print_r($data, true), $context);

        if(isset($data['message']) && $data['message'] === 'Order not found') {
            //todo ALERT THE ADMIN that the order was not found in Fulfillio
            $logger->warning("Order #$order_id not found in Fulfillio: " . $data['message'], $context);
            continue;
        }
        
        //check if key id is not empty, add it to the order meta
        if (!empty($data['id'])){
            $order->update_meta_data('fulfillio_id', $data['id']);
        }

        //check if tracking_number is not empty and doesn't exist yet as meta
        if (!empty($data['tracking_number']) && !$order->get_meta('fulfillio_tracking_number')) {
            $order->update_meta_data('fulfillio_tracking_number', $data['tracking_number']);
            //add order note with tracking number
            $order->add_order_note(sprintf('Tracking number: %s', $data['tracking_number']));

            //todo if customer paid with paypal, add tracking number to paypal
            
        }

        //if status is sent and the payment is not cod, set order status to completed
        if ($data['status'] === 'sent' && $order->get_payment_method() !== 'cod') {
            //if the order status is not already completed, change it
            if ($order->get_status() !== 'completed') {
                $order->update_status('completed', 'Order sent and marked as completed by Fulfillio integration.');
                $logger->info("Order #$order_id marked as completed by Fulfillio integration", $context);
            }
        }
        //check the fulfillio_status. if the status is 'delivered', change the order status to completed
        if ($data['status'] === 'delivered') {
            //if the order status is not already completed, change it
            if ($order->get_status() !== 'completed') {
                $order->update_status('completed', 'Order delivered and marked as completed by Fulfillio integration.');
            }
            $logger->info("Order #$order_id marked as completed by Fulfillio integration", $context);
        }
        //todo if status is "returned", alert the admin that he has to send email

        //todo if status is "sent" and a lot of time has passed, alert the admin that he has to send email
    }
});

function domen_fulfillio_send_admin_alert($message) {
    $admin_email = "domen@hofman.si";

    $body = array(
        'sender' => array(
            'email' => 'info@dazzle.si', //must be valid Brevo sender
            'name' => 'dazzle.si',
        ),
        'to' => array(
            array(
                'email' => $admin_email,
            )
        ),
        'subject' => "Domen alert",
        'textContent' => $message,
    );

    $api_key_path = dirname(__FILE__) . '/brevo-api.key';
    
    if (!file_exists($api_key_path)) {
        error_log('Brevo API key file not found: ' . $api_key_path);
        return;
    }

    $api_key = trim(file_get_contents($api_key_path));

    $response = wp_remote_post('https://api.brevo.com/v3/smtp/email', array(
        'method'    => 'POST',
        'headers'   => array(
            'Content-Type'  => 'application/json',
            'api-key'       => $api_key
        ),
        'body'      => json_encode($body)
    ));

    if (is_wp_error($response)) {
        error_log('Failed to send admin alert email: ' . $response->get_error_message());
    }
}