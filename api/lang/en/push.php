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
    'request_created' => [
        'title' => 'New request',
        'body' => ':company_name: ":request_title"',
    ],
    'request_started' => [
        'title' => 'Work has started',
        'body' => '":request_title"',
    ],
    'request_delivered' => [
        'title' => 'Ready for your review',
        'body' => '":request_title" has been delivered',
    ],
    'request_revision' => [
        'title' => 'Revision requested',
        'body' => '":request_title" :excerpt',
    ],
    'request_approved' => [
        'title' => 'Request completed',
        'body' => '":request_title" was approved',
    ],
    'request_comment' => [
        'title' => 'Comment from :actor_name',
        'body' => '":request_title" :excerpt',
    ],
    'request_on_hold' => [
        'title' => 'Request put on hold',
        'body' => '":request_title" :excerpt',
    ],
    'request_cancelled' => [
        'title' => 'Request cancelled',
        'body' => '":request_title"',
    ],
    'queue_paused' => [
        'title' => 'Request queue paused',
        'body' => ':company_name :excerpt',
    ],
    'queue_resumed' => [
        'title' => 'Request queue resumed',
        'body' => ':company_name',
    ],
    'testimonial_received' => [
        'title' => 'New testimonial (:rating★)',
        'body' => ':company_name: :excerpt',
    ],
    'referral_received' => [
        'title' => 'New referral',
        'body' => ':referred_name, from :company_name',
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
