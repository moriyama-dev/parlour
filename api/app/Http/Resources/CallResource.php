<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'scheduled_at' => $this->scheduled_at,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'created_at' => $this->created_at,
            'created_by' => $this->created_by,
            'is_host' => $request->user()?->id === $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'participants' => CallParticipantResource::collection($this->whenLoaded('participants')),
            'project' => new ProjectResource($this->whenLoaded('project')),
        ];
    }
}
