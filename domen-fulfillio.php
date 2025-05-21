<?php
/**
 * Plugin Name: Fulfillio integration
 * Description: This plugin adds a new custom topic that can be selected for a webhook. This topic only fires under certain conditions. Currently it goes like this: When an order enters the "processing" status, we check what country it should be delivered to. If the country is Slovenia, then we send the data to Fulfillio. Don't forget you still have to create a webhook with the data that fulfillio provided - url and secret. To make sure this plugin works correctly, go to Woocommerce - status - logs and check the domen-fulfillment log.
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

    if (!in_array($new_status, ['processing'])) {
        $logger->info("$log_prefix Ignored – status is not one of the fulfillment triggers.", $context);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        $logger->error("$log_prefix Failed to load order object.", $context);
        return;
    }

    //if the shipping country is slovenia, then we send the data to Fulfillio
    $shipping_country = $order->get_shipping_country();
    if ($shipping_country !== 'SI') {
        $logger->info("$log_prefix Ignored – shipping country is not Slovenia.", $context);
        return;
    }
    $logger->info("$log_prefix Passed fulfillment conditions.", $context);

    // Trigger webhook
    do_action('trigger_fulfillment_webhook', $order_id, $order);
    $logger->info("$log_prefix Order sent to fulfillio.", $context);
    //add order note 
    $order->add_order_note('Order sent to fulfillio.');

}, 10, 3);