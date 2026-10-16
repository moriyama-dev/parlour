<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'issued_at' => $this->issued_at,
            'due_at' => $this->due_at,
            // 入金日が無い過去分は、記録が付く前のものなので更新日で代用する。
            'paid_at' => $this->status === 'paid' ? ($this->paid_at ?? $this->updated_at) : null,
            'receipt_number' => $this->status === 'paid' ? $this->receiptNumber() : null,
            'subtotal' => $this->subtotal,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'project_id' => $this->project_id,
            'project' => new ProjectResource($this->whenLoaded('project')),
        ];
    }
}
