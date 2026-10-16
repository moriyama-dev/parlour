<?php

/*
|--------------------------------------------------------------------------
| WebRTC（ビデオ通話）
|--------------------------------------------------------------------------
| 通話そのものはブラウザ間の P2P で、サーバーは接続の仲立ち（シグナリング）
| しかしない。ただし NAT 越えのためだけに ICE サーバーが要る。
|
| STUN … 自分のグローバルな見え方を教えてもらうだけ。無料の公開サーバーで足りる。
| TURN … 直接つながらない相手との通信を中継する。グローバル IP と UDP ポート開放が
|        必要なので Dreamhost 共有ホスティングには置けない。未設定でも通話は成立するが、
|        相手が対称 NAT（企業網・一部モバイル）だとつながらないことがある。
|
| つながらない顧客が出たら、VPS に coturn を立てて .env に TURN_* の 3 行を足すだけで
| 有効になる。アプリ側のコードは一切変えなくてよい。
*/

return [

    'stun_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'))
    ))),

    'turn_url' => env('TURN_URL'),
    'turn_username' => env('TURN_USERNAME'),
    'turn_credential' => env('TURN_CREDENTIAL'),

    /*
    | TURN しか使わせない検証モード。true にすると STUN 経由の直結を捨てて必ず中継を通す。
    | TURN を入れた直後に「本当に中継が効いているか」を確かめるためのもので、通常は false。
    */
    'force_relay' => (bool) env('TURN_FORCE_RELAY', false),

    /*
    | 通話中のポーリング間隔（ミリ秒）。フロントはこの値を使って heartbeat を打つ。
    | 短いほど接続が速く成立するが、共有ホスティングの負荷も上がる。
    */
    'poll_interval_ms' => (int) env('CALL_POLL_INTERVAL_MS', 1000),

    /*
    | 着信確認のポーリング間隔（ミリ秒）。通話していない全ユーザーが常時打つので、
    | こちらは長め。
    */
    'ring_poll_interval_ms' => (int) env('CALL_RING_POLL_INTERVAL_MS', 5000),

    /*
    | 予約通話を何分前から「入室可」として着信欄に出すか。
    */
    'join_window_minutes' => (int) env('CALL_JOIN_WINDOW_MINUTES', 10),

    /*
    | 通話に参加できる人数の上限。P2P メッシュなので接続数は人数の 2 乗で増える。
    | 4 人（自分＋3）までが実用範囲。
    */
    'max_participants' => (int) env('CALL_MAX_PARTICIPANTS', 4),

];
