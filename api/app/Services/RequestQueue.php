<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 会社ごとの依頼キューの計算。
 *
 * 約束は Conte の VIP と同じ形で3つ:
 *   - 同時に作業中にできる件数（queue_active_limit）
 *   - 着手から納品までの目安（queue_turnaround_hours。土日は数えない＝平日のみ対応）
 *   - 月あたりの作業時間の枠（queue_monthly_hours。null なら枠なし）
 */
class RequestQueue
{
    /** 作業中＋修正対応中の件数。 */
    public function activeCount(Company $company): int
    {
        return $company->workRequests()->whereIn('status', WorkRequest::ACTIVE_STATUSES)->count();
    }

    /** あと何件着手できるか。休止中は 0。 */
    public function openSlots(Company $company): int
    {
        if ($company->queueIsPaused()) {
            return 0;
        }

        return max(0, $company->queue_active_limit - $this->activeCount($company));
    }

    /** 順番待ちの先頭（次に着手するもの）。 */
    public function nextQueued(Company $company): ?WorkRequest
    {
        return $company->workRequests()
            ->where('status', 'queued')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    /**
     * 着手にする。納期目安は今から数える（順番待ちの間は時計を動かさない）。
     * 担当が未設定なら着手した人。枠と休止の確認は呼び出し側の責任。
     */
    public function start(WorkRequest $workRequest, User $user): void
    {
        $workRequest->update([
            'status' => 'in_progress',
            'started_at' => $workRequest->started_at ?? now(),
            'due_at' => $this->dueFrom($workRequest->company, now()),
            'assignee_id' => $workRequest->assignee_id ?? $user->id,
        ]);
        $workRequest->log('started', $user);
        $workRequest->notify('request_started', 'clients', $user, ['due_at' => $workRequest->due_at?->toIso8601String()]);
    }

    /** 順番待ちの末尾の番号。新しい依頼はその次に並ぶ。 */
    public function nextPosition(Company $company): int
    {
        return (int) $company->workRequests()->where('status', 'queued')->max('position') + 1;
    }

    /**
     * 並べ替え。$ids の順に 1, 2, 3… を振り、ここに無い順番待ちはその後ろに元の順で続ける。
     * 渡された id はすべてこの会社の順番待ちでなければならない（呼び出し側で検証済み）。
     *
     * @param  array<int>  $ids
     */
    public function reorder(Company $company, array $ids): void
    {
        DB::transaction(function () use ($company, $ids) {
            $rest = $company->workRequests()
                ->where('status', 'queued')
                ->whereNotIn('id', $ids)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ([...$ids, ...$rest] as $i => $id) {
                WorkRequest::whereKey($id)->update(['position' => $i + 1]);
            }
        });
    }

    /**
     * 今から $hours 時間後の締め。土曜・日曜の時間は数えない。会社のタイムゾーンで数える
     * （金曜の夕方に着手した 72 時間は、翌週の水曜の夕方になる）。
     */
    public function dueFrom(Company $company, CarbonInterface $from, ?int $hours = null): CarbonImmutable
    {
        $tz = $company->timezone ?: config('app.timezone');
        $t = CarbonImmutable::instance($from)->setTimezone($tz);
        $remaining = ($hours ?? $company->queue_turnaround_hours) * 60;

        // 1日ずつ進める。72時間なら高々 5 周程度。
        while ($remaining > 0) {
            if ($t->isWeekend()) {
                $t = $t->addDay()->startOfDay();

                continue;
            }
            $untilMidnight = (int) $t->diffInMinutes($t->addDay()->startOfDay());
            if ($remaining <= $untilMidnight) {
                $t = $t->addMinutes($remaining);
                $remaining = 0;
            } else {
                $remaining -= $untilMidnight;
                $t = $t->addDay()->startOfDay();
            }
        }

        return $t->utc();
    }

    /** 今月（会社のタイムゾーンで）記録した作業時間（分）。 */
    public function minutesThisMonth(Company $company): int
    {
        $tz = $company->timezone ?: config('app.timezone');
        $start = CarbonImmutable::now($tz)->startOfMonth()->utc();

        return (int) WorkRequestEvent::query()
            ->where('type', 'time')
            ->where('created_at', '>=', $start)
            ->whereIn('work_request_id', $company->workRequests()->select('id'))
            ->sum('minutes');
    }

    /** 画面に出す会社ごとの集計。 */
    public function summary(Company $company): array
    {
        $counts = $company->workRequests()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $overdue = $company->workRequests()
            ->whereIn('status', WorkRequest::ACTIVE_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        $active = (int) (($counts['in_progress'] ?? 0) + ($counts['revision'] ?? 0));

        return [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'active_limit' => $company->queue_active_limit,
            'turnaround_hours' => $company->queue_turnaround_hours,
            'monthly_hours' => $company->queue_monthly_hours,
            'paused_at' => $company->queue_paused_at,
            'pause_reason' => $company->queue_pause_reason,
            'active' => $active,
            'open_slots' => $company->queueIsPaused() ? 0 : max(0, $company->queue_active_limit - $active),
            'counts' => collect(WorkRequest::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'overdue' => $overdue,
            'minutes_this_month' => $this->minutesThisMonth($company),
        ];
    }
}
