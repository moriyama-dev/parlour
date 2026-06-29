<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'project_id' => $this->project_id,
            'parent_id'  => $this->parent_id,
            'body'       => $this->body,
            'created_at' => $this->created_at,
            'user'       => new UserResource($this->whenLoaded('user')),
            'replies'    => MessageResource::collection($this->whenLoaded('replies')),
        ];
    }
}
