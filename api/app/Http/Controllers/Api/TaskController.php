<?php

namespace App\Http\Controllers\Api;

use App\Events\TaskStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskApproval;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Project $project)
    {
        $tasks = $project->tasks()->with('creator')->get();

        return TaskResource::collection($tasks);
    }

    public function store(Request $request, Project $project)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'staging_url' => ['nullable', 'url'],
            'due_date' => ['nullable', 'date'],
        ]);

        $task = $project->tasks()->create([
            ...$validated,
            'created_by' => $request->user()->id,
            'status' => 'pending_review',
        ]);

        // Notify company users
        $companyUsers = $project->company->users;
        foreach ($companyUsers as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'task_pending_review',
                'data' => ['task_id' => $task->id, 'task_title' => $task->title, 'project_id' => $project->id],
            ]);
        }

        broadcast(new TaskStatusUpdated($task->load('creator')))->toOthers();

        return new TaskResource($task->load('creator'));
    }

    public function show(Project $project, Task $task)
    {
        return new TaskResource($task->load('creator'));
    }

    public function update(Request $request, Project $project, Task $task)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'staging_url' => ['nullable', 'url'],
            'due_date' => ['nullable', 'date'],
        ]);

        $task->update($validated);

        return new TaskResource($task->load('creator'));
    }

    public function approve(Request $request, Project $project, Task $task)
    {
        if ($request->user()->role !== 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        TaskApproval::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'action' => 'approved',
            'comment' => $request->input('comment'),
        ]);

        $task->update(['status' => 'approved']);

        // Review complete: remove the client's pending-review notification for this task
        Notification::where('user_id', $request->user()->id)
            ->where('type', 'task_pending_review')
            ->where('data->task_id', $task->id)
            ->delete();

        // Notify developer (creator)
        if ($task->created_by) {
            Notification::create([
                'user_id' => $task->created_by,
                'type' => 'task_approved',
                'data' => ['task_id' => $task->id, 'task_title' => $task->title, 'project_id' => $project->id],
            ]);
        }

        broadcast(new TaskStatusUpdated($task->load('creator')))->toOthers();

        return new TaskResource($task->load('creator'));
    }

    public function reject(Request $request, Project $project, Task $task)
    {
        if ($request->user()->role !== 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'comment' => ['required', 'string'],
        ]);

        TaskApproval::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'action' => 'rejected',
            'comment' => $validated['comment'],
        ]);

        $task->update(['status' => 'rejected']);

        // Review complete: remove the client's pending-review notification for this task
        Notification::where('user_id', $request->user()->id)
            ->where('type', 'task_pending_review')
            ->where('data->task_id', $task->id)
            ->delete();

        // Notify developer (creator)
        if ($task->created_by) {
            Notification::create([
                'user_id' => $task->created_by,
                'type' => 'task_rejected',
                'data' => ['task_id' => $task->id, 'task_title' => $task->title, 'project_id' => $project->id, 'comment' => $validated['comment']],
            ]);
        }

        broadcast(new TaskStatusUpdated($task->load('creator')))->toOthers();

        return new TaskResource($task->load('creator'));
    }

    public function markDeployed(Request $request, Project $project, Task $task)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $task->update(['status' => 'deployed']);

        broadcast(new TaskStatusUpdated($task->load('creator')))->toOthers();

        return new TaskResource($task->load('creator'));
    }
}
