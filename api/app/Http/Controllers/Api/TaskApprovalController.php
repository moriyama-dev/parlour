<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskApprovalResource;
use App\Models\Project;
use App\Models\Task;

class TaskApprovalController extends Controller
{
    public function index(Project $project, Task $task)
    {
        $approvals = $task->approvals()->with('user')->get();

        return TaskApprovalResource::collection($approvals);
    }
}
