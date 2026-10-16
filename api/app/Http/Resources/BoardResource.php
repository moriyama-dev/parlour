<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'is_starred' => $this->is_starred,
            'thumbnail' => $this->thumbnail,
            'last_opened_at' => $this->last_opened_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'owner' => new UserResource($this->whenLoaded('owner')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'is_owner' => $user?->id === $this->user_id,
            'can_delete' => $user && ($user->id === $this->user_id || $user->role === 'developer'),
            // 一覧は content 列を SELECT しない（重いので）。列が載っているときだけ返す。
            'content' => $this->when(
                array_key_exists('content', $this->resource->getAttributes()),
                fn () => $this->content ?? ['elements' => []],
            ),
        ];
    }
}
