<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 会社ごとの依頼キューの約束事。Conte の VIP プランの「同時◯件・納期平均◯時間」に当たる。
 * サブスク（Stripe）の金額とは独立に持つ。料金と作業量の約束を別々に変えられるように。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // 同時に作業中にできる件数（作業中＋修正対応中）。
            $table->unsignedTinyInteger('queue_active_limit')->default(1);
            // 着手から納品までの目安。土日は数えない。
            $table->unsignedSmallInteger('queue_turnaround_hours')->default(72);
            // 月あたりの作業時間の枠（保守プランの「軽微な修正 月1時間まで」）。null は枠なし。
            $table->decimal('queue_monthly_hours', 6, 2)->nullable();
            // 「解約ではなく休止」。休止中も依頼は積めるが、こちらは着手しない。
            $table->timestamp('queue_paused_at')->nullable();
            $table->string('queue_pause_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'queue_active_limit',
                'queue_turnaround_hours',
                'queue_monthly_hours',
                'queue_paused_at',
                'queue_pause_reason',
            ]);
        });
    }
};
