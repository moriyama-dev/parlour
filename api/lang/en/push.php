<?php

/*
 * Web Push copy. Keep the key structure identical to ja/push.php.
 * :name placeholders are filled from the notification's data (App\Services\PushNotifier::payload).
 */
return [
    'message_received' => [
        'title' => 'Message from :sender_name',
        'body' => '[:project_title] :excerpt',
    ],
    'task_pending_review' => [
        'title' => 'Review requested',
        'body' => '":task_title" is waiting for your review',
    ],
    'task_approved' => [
        'title' => 'Task approved',
        'body' => '":task_title"',
    ],
    'task_rejected' => [
        'title' => 'Changes requested',
        'body' => '":task_title" :comment',
    ],
    'invoice_sent' => [
        'title' => 'New invoice',
        'body' => 'Invoice :invoice_number',
    ],
    'call_scheduled' => [
        'title' => 'Call scheduled',
        'body' => ':host_name · :at (:project_title)',
    ],
    'call_incoming' => [
        'title' => ':host_name is calling',
        'body' => ':project_title — tap to join',
    ],
    'subscription_created' => [
        'title' => 'Subscription',
        'body' => 'Please add a payment method for ":subscription_name"',
    ],
    'subscription_started' => [
        'title' => 'Subscription started',
        'body' => '":subscription_name"',
    ],
    'subscription_canceled' => [
        'title' => 'Subscription canceled',
        'body' => '":subscription_name"',
    ],
    'subscription_payment_failed' => [
        'title' => 'Payment failed',
        'body' => '":subscription_name" :amount',
    ],
    'test' => [
        'title' => 'Test notification',
        'body' => 'This device can receive notifications',
    ],
];
