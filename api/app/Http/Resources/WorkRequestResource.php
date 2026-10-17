<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'position' => $this->position,
            'reference_url' => $this->reference_url,
            'video_url' => $this->video_url,
            'requested_due_on' => $this->requested_due_on?->toDateString(),
            'estimate_hours' => $this->estimate_hours,
            'spent_minutes' => $this->spent_minutes,
            'checklist' => $this->checklist ?? [],
            'delivery_note' => $this->delivery_note,
            'delivery_url' => $this->delivery_url,
            'due_at' => $this->due_at,
            'started_at' => $this->started_at,
            'delivered_at' => $this->delivered_at,
            'completed_at' => $this->completed_at,
            'revision_count' => $this->revision_count,
            'rating' => $this->rating,
            'is_overdue' => $this->isOverdue(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // 順番待ちのとき、自分より前に何件あるか（WorkRequestController::show だけが載せる）。
            'queue_ahead' => $this->when(isset($this->queue_ahead), fn () => $this->queue_ahead),
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'title' => $this->project->title] : null),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'events' => WorkRequestEventResource::collection($this->whenLoaded('events')),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
