<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Stripe の Customer。サブスクは会社単位なので、支払い方法も会社に1つ。
            $table->string('stripe_customer_id')->nullable()->unique()->after('timezone');
        });

        /*
         * 顧客企業ごとの定期契約（保守・ホスティングなど）。
         *
         * 金額と周期は契約ごとに違うので、Stripe のダッシュボードで商品を作らず、
         * ここに持った値から Stripe の価格をその場で作る。Stripe と紐づいた後は
         * Stripe 側が正で、Webhook が届くたびにこの行を上書きする。
         */
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // 最小通貨単位（セント）。Stripe と同じ表現にして丸め誤差を持ち込まない。
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('cad');
            $table->enum('interval', ['month', 'year']);
            // pending = 顧客の支払い登録待ち。それ以外は Stripe の status をそのまま写す
            // （incomplete / active / past_due / canceled / unpaid など）。
            $table->string('status')->default('pending');
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_item_id')->nullable();
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });

        // Stripe が周期ごとに発行する請求書の写し。顧客と開発者の両方が履歴として見る。
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->string('number')->nullable();
            // Stripe の invoice.status（draft / open / paid / void / uncollectible）。
            // 引き落としに失敗すると open のまま failed_at が付く。
            $table->string('status');
            $table->unsignedInteger('amount_due');
            $table->unsignedInteger('amount_paid')->default(0);
            $table->string('currency', 3);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn('stripe_customer_id');
        });
    }
};
