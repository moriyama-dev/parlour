<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
