<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * レート制限。
     *
     * グローバルな throttleApi() は **付けない**。ビデオ通話が仕様として
     * 毎秒 heartbeat を打つため（1人あたり 60 req/分、ICE 中はさらに
     * signals が最大 8 req/秒）、一律 60/分のような制限を被せると
     * 通話中の本人が自分の通話から 429 で蹴り出される。
     * 代わりに、経路ごとに実際の正常値から逆算した名前付きリミッタを貼る。
     */
    protected function configureRateLimiting(): void
    {
        /*
         * ログイン。実測でメールの実在に関わらず 1 リクエスト約 200ms の CPU を
         * 使う（bcrypt cost 12 が実在しないユーザーでも走る）。素の状態では
         * 毎秒 5 リクエストで 1 コアを飽和させられる。
         */
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            $limits = [
                // 標的型の総当たり対策。鍵はメールなので、攻撃者が接続元を
                // 変えても 1 アカウントあたり 5 回/分に抑えられる。
                Limit::perMinute(5)->by('login:email:'.$email),
            ];

            $limits[] = $this->clientsAreIndistinguishable($request)
                // Funnel 経由では全員が 127.0.0.1 に見えるので、IP ごとに
                // 分けられない。ここで厳しくすると「攻撃者が枠を埋めて
                // オーナーがログインできない」になるため、締め出しではなく
                // CPU の天井として緩く置く（90 × 0.2秒 = 1 コアの約 30%）。
                // オーナーは Tailscale 直結（下の分岐）で常に抜けられる。
                ? Limit::perMinute(90)->by('login:public')
                : Limit::perMinute(20)->by('login:addr:'.$request->ip());

            return $limits;
        });

        // 招待の閲覧・受諾は未認証で叩ける唯一の書き込み経路。
        RateLimiter::for('invite', fn (Request $request) => Limit::perMinute(10)
            ->by('invite:'.$request->ip()));

        // 通話の作成。正常な使い方では数分に 1 回。着信スパムの上限。
        RateLimiter::for('calls-create', fn (Request $request) => Limit::perMinute(6)
            ->by('calls:'.$this->actorKey($request)));

        /*
         * シグナルの送信。正常値は「接続確立の 1〜2 秒に集中して数往復」で、
         * 4 人メッシュ＋画面共有の再交渉を含めても分あたり数十回。
         * 300 は誤爆しない余裕を取った上限で、本命の歯止めは
         * CallSignalController 側の 1 通話あたりの行数上限。
         */
        RateLimiter::for('signals', fn (Request $request) => Limit::perMinute(300)
            ->by('signals:'.$this->actorKey($request)));

        // 通話中の heartbeat。仕様上 60/分なので、その 3 倍を天井にする。
        RateLimiter::for('call-poll', fn (Request $request) => Limit::perMinute(180)
            ->by('poll:'.$this->actorKey($request)));

        // 着信確認。仕様上 12/分。
        RateLimiter::for('ringing', fn (Request $request) => Limit::perMinute(60)
            ->by('ring:'.$this->actorKey($request)));

        // Stripe の Webhook。未認証の窓口なので天井だけは付ける。署名検証は安いが、
        // Stripe 自身は数件/分しか送ってこないので、これを超えるのは Stripe ではない。
        RateLimiter::for('stripe-webhook', fn (Request $request) => Limit::perMinute(120)
            ->by('stripe:'.$request->ip()));

        // 支払い登録・カード管理画面への遷移。Stripe 側にセッションを作る呼び出しなので抑える。
        RateLimiter::for('billing-redirect', fn (Request $request) => Limit::perMinute(10)
            ->by('billing:'.$this->actorKey($request)));

        // 依頼キュー・推薦文・紹介の書き込み。人の手で打つ操作なので、正常なら数件/分。
        RateLimiter::for('work-requests', fn (Request $request) => Limit::perMinute(60)
            ->by('requests:'.$this->actorKey($request)));

        // 通知の購読・設定・テスト送信。テスト送信は外部の配信サーバーを叩くので抑える。
        RateLimiter::for('push', fn (Request $request) => Limit::perMinute(20)
            ->by('push:'.$this->actorKey($request)));
    }

    /**
     * 認証済み経路の集計キー。
     *
     * throttle ミドルウェアは Authenticate より先に走るので `$request->user()`
     * では取れない。sanctum ガードを名指しするとベアラトークンをその場で
     * 解決してくれるため、そちらを使う。取れない場合だけ接続元に落とす。
     */
    protected function actorKey(Request $request): string
    {
        $id = $request->user('sanctum')?->id;

        return $id ? 'user:'.$id : 'addr:'.$request->ip();
    }

    /**
     * 接続元アドレスで客を区別できない状態か。
     *
     * Tailscale Funnel は TLS を終端して 127.0.0.1 から proxy するため、
     * 公開経路の客は全員このアドレスに見える。X-Forwarded-For を信頼すれば
     * 分離できるが、Funnel がそれを付けるかは仕様として保証されておらず、
     * 信頼すると偽装でいくらでも枠を作られる。REMOTE_ADDR は偽装できないので
     * これだけで判断する。
     *
     * 副次的な利点として、Tailscale で直結しているオーナーの接続元は
     * 100.64.0.0/10 の実アドレスとして届くため、公開経路がどれだけ攻撃を
     * 受けていてもオーナーのログイン枠は影響を受けない。
     */
    protected function clientsAreIndistinguishable(Request $request): bool
    {
        return in_array($request->ip(), ['127.0.0.1', '::1', null], true);
    }
}
