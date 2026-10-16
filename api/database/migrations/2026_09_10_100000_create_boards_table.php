<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // null = 作成者だけの個人ボード。値あり = その案件の関係者（顧客）にも見える。
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');

            // ホワイトボードの中身（要素の配列）。JSON 文字列をそのまま保持する。
            $table->longText('content')->nullable();

            // 一覧用のサムネイル（クライアントが生成した小さな SVG）。
            $table->mediumText('thumbnail')->nullable();

            $table->boolean('is_starred')->default(false);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_opened_at']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
