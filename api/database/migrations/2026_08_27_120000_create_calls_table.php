<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();

            // null = 即時呼び出し（今すぐ鳴らす）。値あり = 予約。
            $table->timestamp('scheduled_at')->nullable();

            // scheduled -> live -> ended。cancelled は開始前に取り消したもの。
            $table->enum('status', ['scheduled', 'live', 'ended', 'cancelled'])->default('scheduled');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // 「今この人を呼んでいる通話はあるか」を毎秒引くので、この2列で引けるようにする。
            $table->index(['project_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
