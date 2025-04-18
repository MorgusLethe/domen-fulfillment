<?php
/**
 * Plugin Name: Fulfillio integracija
 * Description: Ta plugin omogoči izbiro novega custom topica za webhooke. Ta topic pošlje samo določena naročila ob določenih trenutkih. Preveri kodo za delovanje, ampak originalno gre tako: ko naročilo pride v status processing ali placilo-potrjeno, se preveri, če izpolnjuje pogoje za fulfillment. Ti pogoji so: samo en različen izdelek, količina = 1, sku=igre-111. Webhook se mora vseeno naštimat preko woocommerce backenda.
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

}, 10, 3);