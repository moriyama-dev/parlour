<?php

/*
|--------------------------------------------------------------------------
| Shadow ユーザー（CLAUDE.md §18）
|--------------------------------------------------------------------------
| 登録した利用者ごとに「影」を1人ずつ自動で作り、オーナーがそれでログインすると
| 元の利用者と全く同じ画面になる（閲覧専用）。顧客の UX を自分で体験するためのもの。
|
| パスワードは全 Shadow 共通で、.env の SHADOW_PASSWORD にだけ置く。
| 空なら機能ごと無効（Shadow は作られず、ログインもできない）。デモホストは
| env.demo にこの値を置かないことで無効にしている。
*/

return [

    'password' => (string) env('SHADOW_PASSWORD', ''),

    // ログインID は shadow+<元ユーザーID>@<domain>
    'email_domain' => 'takumi.ca',

    /*
    | Shadow を作らない利用者（管理者とテスト用）。デモアカウント
    | （config/demo.php の accounts）もこれとは別に必ず除外される。
    */
    'excluded_emails' => [
        'yoshi@takumi.ca',
        'test1@takumi.ca',
        'test2@takumi.ca',
    ],

];
