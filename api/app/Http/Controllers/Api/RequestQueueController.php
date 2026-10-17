<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkRequestResource;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Services\RequestQueue;
use Illuminate\Http\Request;

/**
 * 会社ごとの依頼キューの約束事（同時進行数・納期目安・月の作業時間枠）と、休止。
 *
 * 休止は Conte の「解約ではなく休止」。依頼が途切れた顧客に、契約を切らずに
 * 作業だけを止める選択肢を渡す。休止中も依頼は積めるが、こちらは着手しない。
 * 顧客も開発者も休止・再開できる（作業を止めたいのはどちらの都合もありうる）。
 * Stripe の課金はここでは止めない（料金の扱いは個別に相談して決めるため）。
 */
class RequestQueueController extends Controller
{
    public function __construct(private RequestQueue $queue) {}

    /** 開発者のダッシュボード用。全社の集計。依頼が1件も無い会社も並べる（枠の設定のため）。 */
    public function index()
    {
        return response()->json([
            'data' => Company::orderBy('name')->get()->map(fn (Company $c) => $this->queue->summary($c))->values(),
        ]);
    }

    /** 顧客は自社のものだけ。所属が1社なら company を省略できるよう /request-queues/mine も用意。 */
    public function show(Request $request, Company $company)
    {
        abort_unless($company->isVisibleTo($request->user()), 404);

        return response()->json(['data' => $this->queue->summary($company)]);
    }

    public function mine(Request $request)
    {
        $companies = $request->user()->role === 'developer'
            ? Company::orderBy('name')->get()
            : $request->user()->companies()->orderBy('name')->get();

        return response()->json([
            'data' => $companies->map(fn (Company $c) => $this->queue->summary($c))->values(),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'active_limit' => ['required', 'integer', 'between:1,10'],
            'turnaround_hours' => ['required', 'integer', 'between:1,720'],
            'monthly_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ]);

        $company->update([
            'queue_active_limit' => $validated['active_limit'],
            'queue_turnaround_hours' => $validated['turnaround_hours'],
            'queue_monthly_hours' => $validated['monthly_hours'] ?? null,
        ]);

        return response()->json(['data' => $this->queue->summary($company->fresh())]);
    }

    public function pause(Request $request, Company $company)
    {
        $user = $request->user();
        abort_unless($company->isVisibleTo($user), 404);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if (! $company->queueIsPaused()) {
            $company->update(['queue_paused_at' => now(), 'queue_pause_reason' => $validated['reason'] ?? null]);
            $this->notifyOtherSide($company, $user, 'queue_paused', ['excerpt' => (string) ($validated['reason'] ?? '')]);
        }

        return response()->json(['data' => $this->queue->summary($company->fresh())]);
    }

    public function resume(Request $request, Company $company)
    {
        $user = $request->user();
        abort_unless($company->isVisibleTo($user), 404);

        if ($company->queueIsPaused()) {
            $company->update(['queue_paused_at' => null, 'queue_pause_reason' => null]);
            $this->notifyOtherSide($company, $user, 'queue_resumed');
        }

        return response()->json(['data' => $this->queue->summary($company->fresh())]);
    }

    /** 「次の依頼に着手」（開発者）。順番待ちの先頭を取る。枠と休止は start と同じく守る。 */
    public function next(Request $request, Company $company)
    {
        if ($company->queueIsPaused()) {
            return response()->json(['message' => 'This queue is paused.', 'reason' => 'paused'], 422);
        }
        if ($this->queue->openSlots($company) < 1) {
            return response()->json(['message' => 'The active limit for this company is reached.', 'reason' => 'limit'], 422);
        }

        $next = $this->queue->nextQueued($company);
        if (! $next) {
            return response()->json(['message' => 'Nothing is waiting in the queue.', 'reason' => 'empty'], 422);
        }

        $this->queue->start($next, $request->user());

        return new WorkRequestResource($next->fresh()->load(['company', 'project', 'creator', 'assignee']));
    }

    private function notifyOtherSide(Company $company, User $actor, string $type, array $data = []): void
    {
        $recipients = $actor->role === 'developer'
            ? $company->users()->where('role', 'client')->pluck('users.id')
            : User::where('role', 'developer')->pluck('id');

        foreach ($recipients->unique() as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'data' => ['company_id' => $company->id, 'company_name' => $company->name, 'actor_name' => $actor->name, ...$data],
            ]);
        }
    }
}
