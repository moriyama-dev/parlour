<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Laravel 雛形の Test User 作成は削除した。本番で `php artisan db:seed`（クラス指定なし）
     * を誤って実行しても、既知メールのユーザーが実データに紛れ込まないようにするため。
     * デモ環境ではクラス指定なしでも架空データが入るよう DemoSeeder に委譲する
     * （DemoSeeder 側にも同じ demo_mode ガードがあるので二重に安全）。
     */
    public function run(): void
    {
        if (! config('app.demo_mode')) {
            $this->command?->warn('DatabaseSeeder does nothing outside demo mode. To seed the demo data, run: php artisan db:seed --class=DemoSeeder');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
