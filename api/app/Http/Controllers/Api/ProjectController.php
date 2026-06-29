<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'developer') {
            $projects = Project::with('company')->get();
        } else {
            $companyIds = $user->companies()->pluck('companies.id');
            $projects = Project::with('company')->whereIn('company_id', $companyIds)->get();
        }

        return ProjectResource::collection($projects);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'staging_url' => ['nullable', 'url'],
            'production_url' => ['nullable', 'url'],
        ]);

        $project = Project::create($validated);

        return new ProjectResource($project->load('company'));
    }

    public function show(Project $project)
    {
        return new ProjectResource($project->load('company'));
    }

    public function update(Request $request, Project $project)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'staging_url' => ['nullable', 'url'],
            'production_url' => ['nullable', 'url'],
        ]);

        $project->update($validated);

        return new ProjectResource($project->load('company'));
    }

    public function updateStatus(Request $request, Project $project)
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $project->update(['status' => $validated['status']]);

        return new ProjectResource($project->load('company'));
    }
}
