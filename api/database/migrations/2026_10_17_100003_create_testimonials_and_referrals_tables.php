<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 納品後の推薦文と紹介（Conte が 450 件まで増やした核心は、契約後の紹介の連鎖）。
 * どちらも顧客が書き、開発者が読む。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            // サイトに載せるときの名前（例「田中 葵／Sakura Trading」）。
            $table->string('display_name')->nullable();
            // 公開してよいか。本人の許可が無いものは載せない。
            $table->boolean('allow_publish')->default(false);
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('referred_name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('note')->nullable();
            // new → contacted → won / lost。開発者だけが動かす。
            $table->string('status', 16)->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('testimonials');
    }
};
