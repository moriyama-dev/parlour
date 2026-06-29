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
        ];
    }
}
