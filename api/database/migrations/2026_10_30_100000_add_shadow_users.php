<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Shadow ユーザー（CLAUDE.md §18）。顧客の画面をオーナーが同じ見た目で体験するための影。
 *
 * ロールを元ユーザーと同じにしないのは、「開発者全員」「会社の顧客」を引く問い合わせ
 * （お知らせの宛先・顧客一覧・通話の招待先）に Shadow が紛れ込まないようにするため。
 * 元ユーザーが消えたら外部キーで一緒に消える。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['developer', 'client', 'shadow'])->default('client')->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('shadow_of_id')->nullable()->unique()->after('role')
                ->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', DB::table('users')->where('role', 'shadow')->select('id'))
            ->delete();
        DB::table('users')->where('role', 'shadow')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shadow_of_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['developer', 'client'])->default('client')->change();
        });
    }
};
