<?php

namespace App\Http\Resources;

use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'state' => $this->state,
            'joined_at' => $this->joined_at,
            'last_seen_at' => $this->last_seen_at,

            // フロントはこれを見て相手を接続対象に入れる。state だけだと
            // ブラウザを強制終了した相手が 'joined' のまま残る。
            'is_live' => $this->state === 'joined'
                && $this->last_seen_at
                && $this->last_seen_at->gt(now()->subSeconds(Call::STALE_AFTER_SECONDS)),

            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
