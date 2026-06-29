<?php

use App\Models\Project;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('project.{projectId}', function ($user, $projectId) {
    $project = Project::find($projectId);
    if (!$project) return false;
    $companyIds = $user->companies()->pluck('companies.id');
    return $companyIds->contains($project->company_id) || $user->role === 'developer';
});

Broadcast::channel('tasks.{projectId}', function ($user, $projectId) {
    $project = Project::find($projectId);
    if (!$project) return false;
    $companyIds = $user->companies()->pluck('companies.id');
    return $companyIds->contains($project->company_id) || $user->role === 'developer';
});

Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
