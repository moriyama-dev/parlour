<?php

/*
 * 端末への通知（Web Push）の文面。キー構造は en/push.php と完全一致させる。
 * :name 形式はお知らせの data の値で置き換わる（App\Services\PushNotifier::payload）。
 */
return [
    'message_received' => [
        'title' => ':sender_name さんからメッセージ',
        'body' => '【:project_title】:excerpt',
    ],
    'task_pending_review' => [
        'title' => '確認をお願いします',
        'body' => '「:task_title」の確認待ちです',
    ],
    'task_approved' => [
        'title' => 'タスクが承認されました',
        'body' => '「:task_title」',
    ],
    'task_rejected' => [
        'title' => 'タスクが差し戻されました',
        'body' => '「:task_title」:comment',
    ],
    'request_created' => [
        'title' => '新しいご依頼',
        'body' => ':company_name：「:request_title」',
    ],
    'request_started' => [
        'title' => 'ご依頼に着手しました',
        'body' => '「:request_title」',
    ],
    'request_delivered' => [
        'title' => 'ご確認をお願いします',
        'body' => '「:request_title」を納品しました',
    ],
    'request_revision' => [
        'title' => '修正のご依頼',
        'body' => '「:request_title」:excerpt',
    ],
    'request_approved' => [
        'title' => 'ご依頼が完了しました',
        'body' => '「:request_title」が承認されました',
    ],
    'request_comment' => [
        'title' => ':actor_name さんからコメント',
        'body' => '「:request_title」:excerpt',
    ],
    'request_on_hold' => [
        'title' => 'ご依頼を保留にしました',
        'body' => '「:request_title」:excerpt',
    ],
    'request_cancelled' => [
        'title' => 'ご依頼が取り下げられました',
        'body' => '「:request_title」',
    ],
    'queue_paused' => [
        'title' => '依頼キューが休止されました',
        'body' => ':company_name :excerpt',
    ],
    'queue_resumed' => [
        'title' => '依頼キューが再開されました',
        'body' => ':company_name',
    ],
    'testimonial_received' => [
        'title' => '推薦文が届きました（★:rating）',
        'body' => ':company_name：:excerpt',
    ],
    'referral_received' => [
        'title' => 'ご紹介が届きました',
        'body' => ':company_name から「:referred_name」',
    ],
    'invoice_sent' => [
        'title' => '請求書が届きました',
        'body' => '請求書 :invoice_number',
    ],
    'call_scheduled' => [
        'title' => '通話の予定が入りました',
        'body' => ':host_name さん・:at（:project_title）',
    ],
    'call_incoming' => [
        'title' => ':host_name さんから着信',
        'body' => ':project_title — タップして参加',
    ],
    'subscription_created' => [
        'title' => 'サブスクのご案内',
        'body' => '「:subscription_name」の支払い方法の登録をお願いします',
    ],
    'subscription_started' => [
        'title' => 'サブスクが開始されました',
        'body' => '「:subscription_name」',
    ],
    'subscription_canceled' => [
        'title' => 'サブスクが解約されました',
        'body' => '「:subscription_name」',
    ],
    'subscription_payment_failed' => [
        'title' => 'お支払いに失敗しました',
        'body' => '「:subscription_name」:amount',
    ],
    'test' => [
        'title' => 'テスト通知',
        'body' => 'この端末で通知を受け取れます',
    ],
];
