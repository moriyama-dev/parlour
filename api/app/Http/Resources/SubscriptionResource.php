<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'name' => $this->name,
            'description' => $this->description,
            // 画面は金額をドルで扱う。セントのまま返すと、フロントの全箇所で割り忘れが起きる。
            'amount' => round($this->amount_cents / 100, 2),
            'currency' => strtoupper($this->currency),
            'interval' => $this->interval,
            'status' => $this->status,
            'awaits_checkout' => $this->awaitsCheckout(),
            'is_live' => $this->isLive(),
            'started_at' => $this->started_at,
            'current_period_end' => $this->current_period_end,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'canceled_at' => $this->canceled_at,
            'created_at' => $this->created_at,
            'payments' => SubscriptionPaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
