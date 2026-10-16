<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();

            // 宛先。メッシュなので基本は 1 対 1 の指名配送。
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();

            // offer / answer / candidate / bye
            $table->string('type', 16);

            // SDP は数KBになるのでテキスト。JSON をそのまま入れて中身は触らない。
            $table->longText('payload');

            $table->timestamp('created_at')->nullable();

            // ポーリングの主クエリ「自分宛で、前回の最大 id より新しいもの」用。
            $table->index(['call_id', 'to_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_signals');
    }
};
