<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkRequestResource;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\RequestQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * 依頼キュー（Conte Studios の VIP 方式）。
 *
 * 顧客は依頼を好きなだけ積み、順番を自分で並べ替え、納品されたら承認するか修正を頼む。
 * 開発者は会社ごとの同時進行数の枠内で、順番待ちの先頭から1件ずつ着手して納品する。
 *
 * 役割で閉じる操作（start / deliver / hold / time）は routes/api.php の role:developer
 * グループに入れてある。ここでは「どの会社の依頼に触れてよいか」と
 * 「今の状態からその操作ができるか」を見る。
 */
class WorkRequestController extends Controller
{
    public function __construct(private RequestQueue $queue) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:200'],
            'scope' => ['nullable', Rule::in(['open', 'closed', 'all'])],
        ]);

        $query = WorkRequest::visibleTo($request->user())
            ->with(['company', 'project', 'creator', 'assignee'])
            ->withCount(['events as comments_count' => fn ($q) => $q->where('type', 'comment')->where('internal', false)]);

        if (! empty($validated['company_id'])) {
            $query->where('company_id', $validated['company_id']);
        }
        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }
        if (! empty($validated['status'])) {
            $query->whereIn('status', array_intersect(explode(',', $validated['status']), WorkRequest::STATUSES));
        }
        match ($validated['scope'] ?? 'all') {
            'open' => $query->whereIn('status', WorkRequest::OPEN_STATUSES),
            'closed' => $query->whereIn('status', ['done', 'cancelled']),
            default => null,
        };

        // 順番待ちは並び順、それ以外は新しい順。画面側は状態ごとに分けて出す。
        $rows = $query->orderBy('position')->orderByDesc('updated_at')->limit(500)->get();

        return WorkRequestResource::collection($rows);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            ...$this->contentRules(),
            'title' => ['required', 'string', 'max:255'],
        ]);

        $company = Company::findOrFail($validated['company_id']);
        // company_id はリクエストから来るので、所属の照合は必ずここで（ProjectController::store と同じ理由）。
        abort_unless($company->isVisibleTo($user), 403, 'Forbidden');
        $this->assertProjectBelongs($validated['project_id'] ?? null, $company);

        $workRequest = $company->workRequests()->create([
            ...$validated,
            'created_by' => $user->id,
            'status' => 'queued',
            'position' => $this->queue->nextPosition($company),
            'category' => $validated['category'] ?? 'change',
            'priority' => $validated['priority'] ?? 'normal',
        ]);

        $workRequest->log('created', $user);
        $workRequest->notify('request_created', $user->role === 'developer' ? 'clients' : 'developers', $user);

        return $this->respond($workRequest, $user);
    }

    public function show(Request $request, WorkRequest $workRequest)
    {
        $this->assertVisible($request, $workRequest);

        // 依頼を開いた＝読んだ。この依頼のお知らせは既読にする。
        $request->user()->notifications()
            ->where('type', 'like', 'request_%')
            ->where('data->request_id', $workRequest->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->respond($workRequest, $request->user());
    }

    public function update(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $this->assertVisible($request, $workRequest);
        $isDeveloper = $user->role === 'developer';

        // 顧客が書き換えられるのは、まだ着手前のものだけ。着手後の変更はコメントか修正依頼で。
        if (! $isDeveloper && $workRequest->status !== 'queued') {
            return response()->json(['message' => 'This request is already being worked on. Please add a comment instead.'], 422);
        }

        $rules = [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            ...$this->contentRules(),
            'title' => ['sometimes', 'string', 'max:255'],
        ];
        if ($isDeveloper) {
            $rules += [
                'estimate_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
                'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'developer')],
                'checklist' => ['nullable', 'array', 'max:50'],
                'checklist.*.text' => ['required', 'string', 'max:500'],
                'checklist.*.done' => ['boolean'],
            ];
        }
        $validated = $request->validate($rules);

        if (array_key_exists('project_id', $validated)) {
            $this->assertProjectBelongs($validated['project_id'], $workRequest->company);
        }
        if (isset($validated['checklist'])) {
            $validated['checklist'] = collect($validated['checklist'])
                ->map(fn ($i) => ['text' => $i['text'], 'done' => (bool) ($i['done'] ?? false)])
                ->values()
                ->all();
        }

        $workRequest->update($validated);

        return $this->respond($workRequest, $user);
    }

    /** 順番待ちの並べ替え。顧客は自社のものだけ、開発者はどの会社でも。 */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $company = Company::findOrFail($validated['company_id']);
        abort_unless($company->isVisibleTo($request->user()), 403, 'Forbidden');

        $owned = $company->workRequests()->where('status', 'queued')->whereIn('id', $validated['ids'])->count();
        if ($owned !== count($validated['ids'])) {
            return response()->json(['message' => 'Only queued requests of this company can be reordered.'], 422);
        }

        $this->queue->reorder($company, $validated['ids']);

        return response()->json(['ok' => true]);
    }

    public function comment(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $this->assertVisible($request, $workRequest);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'internal' => ['boolean'],
        ]);

        // 顧客のコメントは常に公開。internal を送ってきても無視する。
        $internal = $user->role === 'developer' && ($validated['internal'] ?? false);

        $workRequest->log(
            $internal ? 'note' : 'comment',
            $user,
            $validated['body'],
            array_filter(['video_url' => $validated['video_url'] ?? null]),
            $internal,
        );
        $workRequest->touch();

        if (! $internal) {
            $workRequest->notify('request_comment', $user->role === 'developer' ? 'clients' : 'developers', $user, [
                'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $validated['body']), 80),
            ]);
        }

        return $this->respond($workRequest, $user);
    }

    /** 着手（開発者）。同時進行数の枠と休止を見る。force で枠を超えて着手できる（急ぎの割り込み）。 */
    public function start(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $validated = $request->validate(['force' => ['boolean']]);

        if (! in_array($workRequest->status, ['queued', 'on_hold'], true)) {
            return response()->json(['message' => 'Only queued or on-hold requests can be started.'], 422);
        }

        if (! ($validated['force'] ?? false)) {
            $company = $workRequest->company;
            if ($company->queueIsPaused()) {
                return response()->json(['message' => 'This queue is paused.', 'reason' => 'paused'], 422);
            }
            if ($this->queue->openSlots($company) < 1) {
                return response()->json(['message' => 'The active limit for this company is reached.', 'reason' => 'limit'], 422);
            }
        }

        $this->queue->start($workRequest, $user);

        return $this->respond($workRequest, $user);
    }

    /** 納品（開発者）。顧客の確認待ちにする。 */
    public function deliver(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $validated = $request->validate([
            'delivery_note' => ['required', 'string', 'max:10000'],
            'delivery_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if (! $workRequest->isActive()) {
            return response()->json(['message' => 'Only requests in progress can be delivered.'], 422);
        }

        $workRequest->update([
            ...$validated,
            'status' => 'client_review',
            'delivered_at' => now(),
        ]);
        $workRequest->log('delivered', $user, $validated['delivery_note'], array_filter(['url' => $validated['delivery_url'] ?? null]));
        $workRequest->notify('request_delivered', 'clients', $user);

        return $this->respond($workRequest, $user);
    }

    /** 顧客の承認。満足度（任意）を一緒に受け取る。 */
    public function approve(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $this->assertVisible($request, $workRequest);
        abort_unless($user->role === 'client', 403, 'Forbidden');

        $validated = $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);

        if ($workRequest->status !== 'client_review') {
            return response()->json(['message' => 'Only delivered requests can be approved.'], 422);
        }

        $workRequest->update([
            'status' => 'done',
            'completed_at' => now(),
            'rating' => $validated['rating'] ?? null,
        ]);
        $workRequest->log('approved', $user, $validated['comment'] ?? null, array_filter(['rating' => $validated['rating'] ?? null]));
        $workRequest->notify('request_approved', 'developers', $user, array_filter(['rating' => $validated['rating'] ?? null]));

        return $this->respond($workRequest, $user);
    }

    /** 顧客の修正依頼。回数は無制限（Conte の「修正無制限」）。納期目安はここから数え直す。 */
    public function revision(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $this->assertVisible($request, $workRequest);
        abort_unless($user->role === 'client', 403, 'Forbidden');

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:10000'],
            'video_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if ($workRequest->status !== 'client_review') {
            return response()->json(['message' => 'Only delivered requests can be sent back.'], 422);
        }

        $workRequest->update([
            'status' => 'revision',
            'revision_count' => $workRequest->revision_count + 1,
            'due_at' => $this->queue->dueFrom($workRequest->company, now()),
        ]);
        $workRequest->log('revision_requested', $user, $validated['comment'], array_filter(['video_url' => $validated['video_url'] ?? null]));
        $workRequest->notify('request_revision', 'developers', $user, [
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $validated['comment']), 80),
        ]);

        return $this->respond($workRequest, $user);
    }

    /** 保留（開発者）。顧客の返事待ち・素材待ちなどで手が止まったとき。枠を空ける。 */
    public function hold(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        if (! in_array($workRequest->status, ['queued', 'in_progress', 'revision'], true)) {
            return response()->json(['message' => 'This request cannot be put on hold.'], 422);
        }

        $workRequest->update(['status' => 'on_hold']);
        $workRequest->log('on_hold', $user, $validated['reason'] ?? null);
        $workRequest->notify('request_on_hold', 'clients', $user, ['excerpt' => Str::limit((string) ($validated['reason'] ?? ''), 80)]);

        return $this->respond($workRequest, $user);
    }

    /** 取り下げ。顧客は着手前の自社のものだけ、開発者は完了以外どれでも。 */
    public function cancel(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $this->assertVisible($request, $workRequest);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $allowed = $user->role === 'developer'
            ? $workRequest->isOpen()
            : $workRequest->status === 'queued';
        if (! $allowed) {
            return response()->json(['message' => 'This request cannot be cancelled.'], 422);
        }

        $workRequest->update(['status' => 'cancelled', 'completed_at' => now()]);
        $workRequest->log('cancelled', $user, $validated['reason'] ?? null);
        $workRequest->notify('request_cancelled', $user->role === 'developer' ? 'clients' : 'developers', $user);

        return $this->respond($workRequest, $user);
    }

    /** 作業時間の記録（開発者）。月の作業時間の枠に数える。顧客には合計だけを見せる。 */
    public function logTime(Request $request, WorkRequest $workRequest)
    {
        $user = $request->user();
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'between:1,1440'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $workRequest->log('time', $user, $validated['note'] ?? null, [], true, $validated['minutes']);
        $workRequest->increment('spent_minutes', $validated['minutes']);

        return $this->respond($workRequest, $user);
    }

    /** 顧客にも開発者にも共通の入力欄。 */
    private function contentRules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:20000'],
            'category' => ['nullable', Rule::in(WorkRequest::CATEGORIES)],
            'priority' => ['nullable', Rule::in(WorkRequest::PRIORITIES)],
            'reference_url' => ['nullable', 'url', 'max:2048'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'requested_due_on' => ['nullable', 'date'],
        ];
    }

    /** 他社の依頼は存在ごと隠す（id の総当たりで件数を探られないように 404）。 */
    private function assertVisible(Request $request, WorkRequest $workRequest): void
    {
        abort_unless($workRequest->company->isVisibleTo($request->user()), 404);
    }

    private function assertProjectBelongs(?int $projectId, Company $company): void
    {
        if ($projectId !== null) {
            abort_unless(Project::whereKey($projectId)->where('company_id', $company->id)->exists(), 422, 'The project does not belong to this company.');
        }
    }

    private function respond(WorkRequest $workRequest, ?User $viewer = null)
    {
        $workRequest->refresh()->load(['company', 'project', 'creator', 'assignee']);
        $workRequest->load(['events' => function ($q) use ($viewer) {
            $q->with('user')->orderBy('created_at')->orderBy('id');
            // 開発者メモと作業時間の記録は顧客に返さない。
            if ($viewer?->role !== 'developer') {
                $q->where('internal', false);
            }
        }]);

        if ($workRequest->status === 'queued') {
            $workRequest->queue_ahead = $workRequest->company->workRequests()
                ->where('status', 'queued')
                ->where(fn ($q) => $q->where('position', '<', $workRequest->position)
                    ->orWhere(fn ($q) => $q->where('position', $workRequest->position)->where('id', '<', $workRequest->id)))
                ->count();
        }

        return new WorkRequestResource($workRequest);
    }
}
