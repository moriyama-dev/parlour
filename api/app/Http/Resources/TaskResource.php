<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'staging_url' => $this->staging_url,
            'due_date' => $this->due_date,
            'created_at' => $this->created_at,
            'creator' => new UserResource($this->whenLoaded('creator')),
        ];
    }
}
