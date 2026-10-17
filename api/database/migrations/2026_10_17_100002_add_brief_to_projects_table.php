<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 案件の最初のヒアリング（Conte の 20 分ディスカバリーで聞く項目）を、
 * 顧客が案件を作るときにフォームで先に書いてもらう。キーは Project::BRIEF_FIELDS。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('brief')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('brief');
        });
    }
};
