<?php

use App\Models\CallSignal;
use App\Models\Subscription;
use App\Services\StripeBilling;
use App\Services\SubscriptionSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Minishlink\WebPush\VAPID;
use Stripe\Exception\ApiErrorException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// デモサイトのみ: 訪問者がデータを荒らしてもきれいに戻るよう、DemoSeeder を定期リセット。
// DemoSeeder 自体が demo_mode ガードを持つため、本番でこの行が実行されても no-op。
// 有効化にはデモホスト側の cron に「* * * * * php artisan schedule:run」を1行登録する。
if (config('app.demo_mode')) {
    Schedule::call(function () {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    })->dailyAt('04:00')->name('demo-reset')->withoutOverlapping();
}

// 通話のシグナル（SDP / ICE 候補）は接続が確立した時点で用済み。CallController も
// 通話作成時に古い行を落とすが、通話が長く作られない期間に取り残しが残らないよう
// 日次でも掃除する。schedule:run が動いていなくても実害は無い（容量だけの話）。
Schedule::call(function () {
    CallSignal::where('created_at', '<', now()->subDay())->delete();
})->dailyAt('04:30')->name('prune-call-signals')->withoutOverlapping();

/*
 * サブスクの照合。課金そのものは Stripe が自分の時計で行うので、ここが止まっても
 * 引き落としは止まらない。これは Webhook を取りこぼした（サーバー停止中に届いた等）
 * ときに、ポータル上の状態と請求履歴を Stripe に合わせ直すための保険。
 */
Artisan::command('subscriptions:sync', function (StripeBilling $stripe, SubscriptionSync $sync) {
    if (! $stripe->enabled()) {
        $this->warn('STRIPE_SECRET is not set — nothing to sync.');

        return;
    }

    $rows = Subscription::whereNotNull('stripe_subscription_id')
        // 解約から日が経ったものは Stripe 側でももう動かない。
        ->where(fn ($q) => $q->where('status', '!=', 'canceled')->orWhere('canceled_at', '>', now()->subDays(45)))
        ->get();

    foreach ($rows as $row) {
        try {
            $sync->applySubscription($stripe->retrieveSubscription($row->stripe_subscription_id), $row);
            foreach ($stripe->invoicesFor($row->stripe_subscription_id) as $invoice) {
                $sync->applyInvoice($invoice);
            }
            $this->line("synced #{$row->id} {$row->name}: {$row->fresh()->status}");
        } catch (ApiErrorException $e) {
            report($e);
            $this->error("failed #{$row->id}: {$e->getMessage()}");
        }
    }
})->purpose('Re-sync subscriptions and their invoices from Stripe');

Schedule::command('subscriptions:sync')->dailyAt('05:00')->name('subscriptions-sync')->withoutOverlapping();

// Web Push の VAPID 鍵を作る。出力された3行を、設置先の .env（env.production / env.demo）に貼る。
// 鍵は本番とデモで別にすること。作り直すと既存の購読はすべて届かなくなる。
Artisan::command('push:vapid', function () {
    $keys = VAPID::createVapidKeys();
    $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
    $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
    $this->line('VAPID_SUBJECT='.config('app.url'));
})->purpose('Generate a VAPID key pair for Web Push');
