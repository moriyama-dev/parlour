<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 依頼キュー（Conte Studios の VIP 方式）。
 *
 * 顧客が「やってほしいこと」を好きなだけ積み、こちらは会社ごとの同時進行数の枠内で
 * 上から順に1件ずつ消化する。並び順（position）は顧客が自分で入れ替えられる。
 * 案件（projects）とは別系統で、会社に直接ぶら下がる（案件は任意で紐づけるだけ）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            // 値の一覧は WorkRequest::CATEGORIES / PRIORITIES / STATUSES。enum にしないのは、
            // 種類を足すたびにマイグレーションを書かずに済ませるため（検証はアプリ側）。
            $table->string('category', 32)->default('change');
            $table->string('priority', 16)->default('normal');
            $table->string('status', 24)->default('queued');

            // 順番待ちの中での並び。小さいほど先に着手する。
            $table->unsignedInteger('position')->default(0);

            $table->string('reference_url', 2048)->nullable();
            // Loom などの画面録画での説明（Conte はフィードバックを動画でも受ける）。
            $table->string('video_url', 2048)->nullable();
            $table->date('requested_due_on')->nullable();

            // 開発者側の作業メモ。チェックリストは [{text, done}] の配列で顧客にも見せる（進み具合）。
            $table->decimal('estimate_hours', 6, 2)->nullable();
            $table->unsignedInteger('spent_minutes')->default(0);
            $table->json('checklist')->nullable();

            $table->text('delivery_note')->nullable();
            $table->string('delivery_url', 2048)->nullable();

            // 着手時に会社の納期目安（平日のみ数える）から計算する。
            $table->timestamp('due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('revision_count')->default(0);

            // 顧客が完了を承認するときの満足度（1〜5）。
            $table->unsignedTinyInteger('rating')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status', 'position']);
            $table->index('project_id');
        });

        /*
         * 依頼ごとの履歴とコメント。1本の時系列にまとめる（ClickUp のアクティビティ欄と同じ）。
         * internal=true は開発者だけに見えるメモと作業時間の記録で、顧客の API には返さない。
         */
        Schema::create('work_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->boolean('internal')->default(false);
            $table->timestamps();

            $table->index(['work_request_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_request_events');
        Schema::dropIfExists('work_requests');
    }
};
