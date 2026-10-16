<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardResource;
use App\Models\Board;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * ホワイトボード（「イメージ」）。
 *
 * 中身の JSON はブラウザが作りブラウザが読む。サーバーは「誰に見えるか」と
 * 「どこまで大きくしてよいか」だけを見る。共有ホスティングなのでサイズ上限は
 * アプリ層で持つ（§14-5 のシグナルと同じ理由）。
 */
class BoardController extends Controller
{
    /** 1ボードの中身（JSON 文字列）の上限。画像を data URL で埋めるので余裕を持たせる。 */
    public const MAX_CONTENT_BYTES = 8 * 1024 * 1024;

    /** 一覧サムネイル（SVG 文字列）の上限。 */
    public const MAX_THUMBNAIL_BYTES = 200 * 1024;

    public function index(Request $request)
    {
        $request->validate(['project_id' => ['nullable', 'integer']]);

        $boards = Board::visibleTo($request->user())
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->select(['id', 'user_id', 'project_id', 'title', 'thumbnail', 'is_starred', 'last_opened_at', 'created_at', 'updated_at'])
            ->with(['owner', 'project'])
            ->orderByRaw('COALESCE(last_opened_at, updated_at) DESC')
            ->get();

        return BoardResource::collection($boards);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'content' => ['nullable', 'array'],
        ]);

        $this->assertContentSize($validated['content'] ?? null);
        $this->assertProjectAccess($request, $validated['project_id'] ?? null);

        $board = Board::create([
            'user_id' => $request->user()->id,
            'project_id' => $validated['project_id'] ?? null,
            'title' => $validated['title'] ?: 'Untitled',
            'content' => $validated['content'] ?? ['elements' => []],
            'last_opened_at' => now(),
        ]);

        return (new BoardResource($board->load(['owner', 'project'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Board $board)
    {
        $this->assertVisible($request, $board);

        // last_opened_at は "最近" の並び順に使うだけなので、updated_at を動かさない。
        $board->timestamps = false;
        $board->forceFill(['last_opened_at' => now()])->save();
        $board->timestamps = true;

        return new BoardResource($board->load(['owner', 'project']));
    }

    public function update(Request $request, Board $board)
    {
        $this->assertVisible($request, $board);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'content' => ['sometimes', 'array'],
            'thumbnail' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_THUMBNAIL_BYTES],
            'is_starred' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('content', $validated)) {
            $this->assertContentSize($validated['content']);
        }

        // 共有先（案件）を付け替えられるのは持ち主と開発者だけ。
        if (array_key_exists('project_id', $validated)) {
            if (! $board->isOwnedBy($request->user()) && $request->user()->role !== 'developer') {
                abort(403, 'Forbidden');
            }
            $this->assertProjectAccess($request, $validated['project_id']);
        }

        $board->fill($validated)->save();

        return new BoardResource($board->load(['owner', 'project']));
    }

    public function destroy(Request $request, Board $board)
    {
        $this->assertVisible($request, $board);

        if (! $board->isOwnedBy($request->user()) && $request->user()->role !== 'developer') {
            abort(403, 'Forbidden');
        }

        $board->delete();

        return response()->noContent();
    }

    public function duplicate(Request $request, Board $board)
    {
        $this->assertVisible($request, $board);

        $copy = Board::create([
            'user_id' => $request->user()->id,
            'project_id' => $board->project_id,
            'title' => $board->title.' (copy)',
            'content' => $board->content ?? ['elements' => []],
            'thumbnail' => $board->thumbnail,
            'last_opened_at' => now(),
        ]);

        return (new BoardResource($copy->load(['owner', 'project'])))
            ->response()
            ->setStatusCode(201);
    }

    private function assertVisible(Request $request, Board $board): void
    {
        if (! $board->isVisibleTo($request->user())) {
            abort(403, 'Forbidden');
        }
    }

    /** 案件に紐づけるには、その案件を見られる人でなければならない。 */
    private function assertProjectAccess(Request $request, ?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $user = $request->user();
        if ($user->role === 'developer') {
            return;
        }

        $project = Project::findOrFail($projectId);
        if (! $user->companies()->whereKey($project->company_id)->exists()) {
            abort(403, 'Forbidden');
        }
    }

    private function assertContentSize(?array $content): void
    {
        if ($content === null) {
            return;
        }

        if (strlen(json_encode($content)) > self::MAX_CONTENT_BYTES) {
            abort(413, 'Board content is too large.');
        }
    }
}
