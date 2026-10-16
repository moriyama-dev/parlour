<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Web Push を実際に使うための列。
 *
 * push_subscriptions は先行して作ってあったが、endpoint が text なので一意制約を
 * 張れなかった。同じ端末が購読し直すたびに行が増えないよう、ハッシュで一意にする。
 * locale は通知の本文をどちらの言語で作るか（端末ごとに違ってよい）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->char('endpoint_hash', 64)->nullable()->unique()->after('endpoint');
            $table->string('locale', 5)->default('ja')->after('device_name');
            $table->timestamp('last_used_at')->nullable()->after('locale');
        });

        Schema::table('users', function (Blueprint $table) {
            // {"message": true, "task": false, ...}。キーが無い分類はオン扱い。
            $table->json('notification_preferences')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint_hash']);
            $table->dropColumn(['endpoint_hash', 'locale', 'last_used_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
