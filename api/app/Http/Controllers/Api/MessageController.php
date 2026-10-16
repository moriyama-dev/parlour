<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function index(Request $request, Project $project)
    {
        // スレッドを開いた＝読んだ。この案件のメッセージのお知らせは既読にする。
        Notification::where('user_id', $request->user()->id)
            ->where('type', 'message_received')
            ->where('data->project_id', $project->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

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
            'body' => ['required', 'string'],
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
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'body' => $validated['body'],
        ]);

        $message->load(['user', 'replies.user', 'replies.replies.user']);

        broadcast(new MessageSent($message))->toOthers();

        // 案件に関わる自分以外の全員へ。スマホへの通知は Notification 作成時に飛ぶ。
        $sender = $request->user();
        $recipients = $project->members()->whereKeyNot($sender->id)->pluck('id');
        foreach ($recipients as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => 'message_received',
                'data' => [
                    'message_id' => $message->id,
                    'project_id' => $project->id,
                    'project_title' => $project->title,
                    'sender_name' => $sender->name,
                    'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $message->body), 80),
                ],
            ]);
        }

        return new MessageResource($message);
    }
}
