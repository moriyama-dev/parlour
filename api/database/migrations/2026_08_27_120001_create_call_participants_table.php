<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // invited: 呼ばれたが未応答 / joined: 入室中 / left: 退出 / declined: 拒否
            $table->enum('state', ['invited', 'joined', 'left', 'declined'])->default('invited');

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            // ハートビートの最終時刻。WebSocket が無いので「落ちた」判定はこれの古さで行う。
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['call_id', 'user_id']);
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_participants');
    }
};
