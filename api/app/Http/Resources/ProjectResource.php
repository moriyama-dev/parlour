<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'staging_url' => $this->staging_url,
            'production_url' => $this->production_url,
            'created_at' => $this->created_at,
            'company' => new CompanyResource($this->whenLoaded('company')),
            // 進捗グラフ用。withCount を付けて読んだときだけ載る。
            'task_stats' => $this->when(
                array_key_exists('tasks_count', $this->resource->getAttributes()),
                fn () => [
                    'total' => (int) $this->tasks_count,
                    'draft' => (int) $this->tasks_draft_count,
                    'pending_review' => (int) $this->tasks_pending_review_count,
                    'approved' => (int) $this->tasks_approved_count,
                    'rejected' => (int) $this->tasks_rejected_count,
                    'deployed' => (int) $this->tasks_deployed_count,
                ],
            ),
        ];
    }
}
