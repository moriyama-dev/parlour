<?php

/*
|--------------------------------------------------------------------------
| Web Push（スマホ・PC への通知）
|--------------------------------------------------------------------------
| ブラウザ（またはホーム画面に追加した PWA）が購読すると、Google / Apple / Mozilla の
| 配信サーバーの URL（endpoint）が届く。お知らせを作るたびに、そこへ暗号化した
| 本文を HTTPS で POST するだけなので、常駐プロセスの無い共有ホスティングでも動く。
|
| VAPID 鍵は「この送り主は前回と同じサーバーだ」と配信サーバーに示すための署名鍵。
| 生成は `php artisan push:vapid`。本番とデモで別の鍵にすること。
| 鍵を作り直すと、既存の購読はすべて無効になる（端末側で再度オンにしてもらう）。
|
| 公開鍵が空のときは機能ごと無効になり、設定画面にも「未設定」と出る。
*/

return [

    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),

    // 配信サーバーが送り主に連絡するための宛先（mailto: か https:）。
    'subject' => env('VAPID_SUBJECT', env('APP_URL')),

    // 端末で通知を押したときに開く URL の基点。
    'app_url' => env('PUSH_APP_URL', env('APP_URL')),

    /*
     * 送信先として受け付ける配信サーバー。
     *
     * endpoint は購読したブラウザが申告してくる値で、サーバーはそこへ POST する。
     * 何でも受け付けると、認証済みの利用者がサーバーに任意の URL（社内ネットワーク・
     * クラウドのメタデータ）を叩かせられる（SSRF）。主要ブラウザの配信先だけに絞る。
     * 先頭が "." のものはそのドメイン配下すべてに一致する。
     */
    'allowed_hosts' => [
        'fcm.googleapis.com',            // Chrome / Edge(Android) / Samsung Internet
        'android.googleapis.com',
        'updates.push.services.mozilla.com', // Firefox
        '.push.apple.com',               // Safari（iOS 16.4+ のホーム画面アプリ・macOS）
        '.notify.windows.com',           // Edge(Windows)
    ],

    // 1人あたりの購読（端末）数の上限。超えたら古いものから消す。
    'max_per_user' => 10,

];
