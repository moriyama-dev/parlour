<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            // 引き落とし失敗は Stripe 上は open のまま。画面では区別したいので failed に読み替える。
            'status' => $this->status === 'open' && $this->failed_at ? 'failed' : $this->status,
            'amount_due' => round($this->amount_due / 100, 2),
            'amount_paid' => round($this->amount_paid / 100, 2),
            'currency' => strtoupper($this->currency),
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'paid_at' => $this->paid_at,
            'failed_at' => $this->failed_at,
            'hosted_invoice_url' => $this->hosted_invoice_url,
            'invoice_pdf' => $this->invoice_pdf,
        ];
    }
}
