<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Project $project)
    {
        $messages = $project->messages()
            ->whereNull('parent_id')
            ->with(['user', 'replies.user', 'replies.replies.user'])
            ->orderBy('created_at')
            ->get();

        return MessageResource::collection($messages);
    }

    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'body'      => ['required', 'string'],
            'parent_id' => ['nullable', 'exists:messages,id'],
        ]);

        $parentId = $validated['parent_id'] ?? null;

        // Cap nesting at 2 levels (root -> reply -> reply-to-reply).
        // If the target is itself a level-2 reply, attach to its parent instead.
        if ($parentId) {
            $parent = Message::where('project_id', $project->id)->findOrFail($parentId);
            if ($parent->parent_id) {
                $grandparent = Message::find($parent->parent_id);
                if ($grandparent && $grandparent->parent_id) {
                    $parentId = $parent->parent_id;
                }
            }
        }

        $message = $project->messages()->create([
            'user_id'   => $request->user()->id,
            'parent_id' => $parentId,
            'body'      => $validated['body'],
        ]);

        $message->load(['user', 'replies.user', 'replies.replies.user']);

        broadcast(new MessageSent($message))->toOthers();

        return new MessageResource($message);
    }
}
