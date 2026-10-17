<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestimonialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'project_id' => $this->project_id,
            'rating' => $this->rating,
            'body' => $this->body,
            'display_name' => $this->display_name,
            'allow_publish' => $this->allow_publish,
            'created_at' => $this->created_at,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'title' => $this->project->title] : null),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
