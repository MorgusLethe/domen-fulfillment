<?php
/**
 * Plugin Name: Fulfillio integracija
 * Description: Ta plugin omogoči pošiljanje samo določenih naročil v fulfillment center. Tehnično je to izvedeno tako, da plugin registrira custom webhook, ki se sproži samo ob točno določenih pogojih. Preveri kodo za točne pogoje. Webhook se mora vseeno naštimat preko woocommerce backenda. Poleg webhooka koda tudi komunicira z zunanjim sistemom in avtomatsko zaključuje naročila. CUSTOM STATUS FULFILLIO JE NAREJEN V functions.php od teme dazzle. Če želiš izklopiti pošiljanje naročil v zunanji sistem, samo izklopi webhook, in ne celega plugina.
 * Version: 2.1
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
        $now = current_time('timestamp'); // WP local time
        $next_17 = strtotime('today 17:00', $now);

        if ($next_17 <= $now) {
            $next_17 = strtotime('tomorrow 17:00', $now);
        }

        if ($next_17 !== false) {
            wp_schedule_event($next_17, 'daily', 'domen_fulfillio_daily_check');

            $logger2 = wc_get_logger();
            $context = ['source' => 'domen-fulfillment'];
            $logger2->info("Scheduled daily check for Fulfillio at " . date('Y-m-d H:i:s', $next_17), $context);
        }
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

    // Collect info for admin alert
    $alert = [
        'something_wrong' => False,
        'sent_over_2_days' => [],
    ];

    $now = time();


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
            
            $alert['something_wrong'] = "yes! check logs";

            continue;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        //example response:
        /*
        id              : 1719
        status          : sent
        courier_status  : APL-Registration
        courier         : gls
        tracking_number : 7006022919
        sent_at         : 7. 07. 2025 10:35:10
        */

        //log the data
        $logger->info("Fulfillio API response for order #$order_id: " . print_r($data, true), $context);

        if(isset($data['message']) && $data['message'] === 'Order not found') {
            $logger->warning("Order #$order_id not found in Fulfillio: " . $data['message'], $context);
            $alert['something_wrong'] = "yes! check logs";
            continue;
        }
        
        //check if key id is not empty, add it to the order meta
        if (!empty($data['id'])){
            $order->update_meta_data('fulfillio_id', $data['id']);
        }
        else{
            //no id in response. something may be wrong.
            $logger->warning("No id in response.", $context);
            $alert['something_wrong'] = "yes! check logs";
            continue;
        }

        //check if tracking_number is not empty and doesn't exist yet as meta
        //TODO THERE IS A BUG HERE FIX IT, TRACKING NUMBER CONSTANTLY BEING WRITTEN
        if (!empty($data['tracking_number']) && !$order->get_meta('fulfillio_tracking_number')) {
            $order->update_meta_data('fulfillio_tracking_number', $data['tracking_number']);
            //add order note with tracking number
            $order->add_order_note(sprintf('Tracking number: %s', $data['tracking_number']));

            //todo if customer paid with paypal, add tracking number to paypal
            
        }

        //if status is sent or transit and the payment is not cod, set order status to completed
        if (($data['status'] === 'sent' || $data['status'] === 'transit') && $order->get_payment_method() !== 'cod') {
            //if the order status is not already completed, change it
            if ($order->get_status() !== 'completed') {
                $order->update_status('completed', 'Order sent and marked as completed by Fulfillio integration.');
                $logger->info("Order #$order_id marked as completed by Fulfillio integration", $context);
            }
        }

        //if status is sent, check if more than 2 days have passed since the order was sent
        if (($data['status'] === 'sent' || $data['status'] === 'transit') && isset($data['sent_at'])) {
            //parse the sent_at date
            try {
                $sent_at = new DateTime($data['sent_at']);
                $sent_at_ts = $sent_at->getTimestamp();
            } catch (Exception $e) {
                $logger->error("Cannot parse sent_at date for order #$order_id: " . $e->getMessage(), $context);
                continue;
            }
            $days_passed = ($now - $sent_at_ts) / (60 * 60 * 24);

            if ($days_passed > 2) {
                //add order id, tracking number, name of purchaser, phone number, to the alert
                $customer_name = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
                $customer_phone = $order->get_billing_phone();
                $alert['sent_over_2_days'][] = sprintf(
                    "Order #%d | Sent at %s | Tracking: %s | Customer: %s | Phone: %s",
                    $order_id,
                    $data['sent_at'],
                    $data['tracking_number'],
                    $customer_name,
                    $customer_phone
                );
                
            }
        
        }

        //if the status is 'delivered', change the order status to completed
        if ($data['status'] === 'delivered') {
            //if the order status is not already completed, change it
            if ($order->get_status() !== 'completed') {
                $order->update_status('completed', 'Order delivered and marked as completed by Fulfillio integration.');
            }
            $logger->info("Order #$order_id marked as completed by Fulfillio integration", $context);
        }
        
        //todo if status is "returned", what shall we do? cancel the order for sure ,but what else?

    }
    if ($alert['something_wrong'] || !empty($alert['sent_over_2_days'])) {
        domen_fulfillio_send_admin_alert(json_encode($alert, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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