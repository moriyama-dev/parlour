<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Project::with('company')->withCount(self::taskCounts());

        if ($user->role !== 'developer') {
            $query->whereIn('company_id', $user->companies()->pluck('companies.id'));
        }

        return ProjectResource::collection($query->get());
    }

    /**
     * 進捗の棒グラフ用の集計。
     *
     * 一覧で案件ごとにタスクを読み直すと N+1 になるので、件数だけを SQL 側で数える。
     * 状態名は tasks テーブルの enum と同じ並び。
     */
    private static function taskCounts(): array
    {
        $counts = ['tasks'];

        foreach (['draft', 'pending_review', 'approved', 'rejected', 'deployed'] as $status) {
            $counts["tasks as tasks_{$status}_count"] = fn (Builder $q) => $q->where('status', $status);
        }

        return $counts;
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'staging_url' => ['nullable', 'url'],
            'production_url' => ['nullable', 'url'],
        ]);

        /*
         * 顧客も「部屋」を立てられる。ただし自分の会社の分だけ。
         *
         * ここを開発者専用のままにすると、顧客は案件の作成をメールで依頼するしかなく、
         * ポータルの外にやり取りが漏れる。会社の照合は必ずサーバー側で行う
         * （company_id はリクエストから来るので、UI の選択肢を絞るだけでは守れない）。
         */
        if ($user->role !== 'developer'
            && ! $user->companies()->whereKey($validated['company_id'])->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $project = Project::create($validated);

        return new ProjectResource($project->load('company')->loadCount(self::taskCounts()));
    }

    public function show(Project $project)
    {
        return new ProjectResource($project->load('company')->loadCount(self::taskCounts()));
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
