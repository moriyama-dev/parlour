<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Stripe が周期ごとに発行した請求書の写し（1行＝1回分の引き落とし）。 */
class SubscriptionPayment extends Model
{
    protected $fillable = [
        'subscription_id',
        'stripe_invoice_id',
        'number',
        'status',
        'amount_due',
        'amount_paid',
        'currency',
        'period_start',
        'period_end',
        'paid_at',
        'failed_at',
        'hosted_invoice_url',
        'invoice_pdf',
    ];

    protected function casts(): array
    {
        return [
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
