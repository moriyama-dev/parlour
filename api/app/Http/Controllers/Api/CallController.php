<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallParticipantResource;
use App\Http\Resources\CallResource;
use App\Http\Resources\UserResource;
use App\Models\Call;
use App\Models\CallSignal;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\PushNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ビデオ通話の「部屋」の管理。
 *
 * 通話の中身（映像・音声・画面共有・チャット）はブラウザ間の P2P で流れるので
 * サーバーは一切触らない。ここがやるのは部屋の作成・入退室・参加者名簿だけ。
 * SDP と ICE 候補の受け渡しは CallSignalController が担当する。
 *
 * WebSocket を使わずポーリングで組んであるのは、移行先の Dreamhost 共有ホスティングが
 * 常駐プロセスを持てないため。やり取りするのは接続確立の数秒間だけなので実用上足りる。
 */
class CallController extends Controller
{
    /**
     * このプロジェクトの通話一覧。終わったものは直近だけ返す。
     */
    public function index(Project $project)
    {
        Call::sweepAbandoned();

        $calls = $project->calls()
            ->with(['creator', 'participants.user'])
            ->where(function ($q) {
                $q->whereIn('status', ['scheduled', 'live'])
                    ->orWhere('ended_at', '>=', now()->subDays(7));
            })
            // 進行中 → 予約 → 終了 の順。FIELD() は MySQL 専用で CI の SQLite で
            // 落ちるため、両方で動く CASE 式にしてある。
            ->orderByRaw("CASE status WHEN 'live' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END")
            ->orderByRaw('COALESCE(scheduled_at, created_at) DESC')
            ->limit(50)
            ->get();

        return CallResource::collection($calls);
    }

    /**
     * 通話を作る。scheduled_at が無ければ即時呼び出し（作った瞬間から相手の画面が鳴る）。
     */
    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $invitable = $this->invitableUsers($project);

        // 招待先が未指定なら、このプロジェクトに関われる全員を呼ぶ。
        $invitedIds = isset($validated['user_ids'])
            ? array_values(array_intersect($validated['user_ids'], $invitable->pluck('id')->all()))
            : $invitable->pluck('id')->all();

        // 発信者は必ず参加者に含める。
        $invitedIds = array_values(array_unique(array_merge([$user->id], $invitedIds)));

        $max = (int) config('webrtc.max_participants');
        if (count($invitedIds) > $max) {
            throw ValidationException::withMessages([
                'user_ids' => ["この通話に参加できるのは {$max} 人までです。"],
            ]);
        }

        $isInstant = empty($validated['scheduled_at']);

        // 即時呼び出しは同じ相手に部屋を乱立させない。既に鳴っている通話があれば
        // それを返す（発信ボタンの二度押し対策）。
        if ($isInstant) {
            $existing = $project->calls()
                ->where('status', 'live')
                ->where('created_by', $user->id)
                ->whereNull('scheduled_at')
                ->first();

            if ($existing) {
                $existing->load(['creator', 'participants.user']);

                return new CallResource($existing);
            }
        }

        $call = $project->calls()->create([
            'created_by' => $user->id,
            'title' => $validated['title'] ?? null,
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            // 即時呼び出しは作った時点で「鳴っている」状態にする。
            'status' => $isInstant ? 'live' : 'scheduled',
            'started_at' => $isInstant ? now() : null,
        ]);

        foreach ($invitedIds as $id) {
            $call->participants()->create([
                'user_id' => $id,
                'state' => 'invited',
            ]);
        }

        /*
         * 即時呼び出しはベルに残さず（画面を開いていれば着信トーストが出る）、
         * スマホへの通知だけ送る。アプリを閉じていても鳴るように。
         */
        if ($isInstant) {
            foreach ($invitedIds as $id) {
                if ($id === $user->id) {
                    continue;
                }
                app(PushNotifier::class)->send($id, 'call_incoming', [
                    'call_id' => $call->id,
                    'project_id' => $project->id,
                    'project_title' => $project->title,
                    'host_name' => $user->name,
                ]);
            }
        }

        // 予約はベルの通知に載せる（即時呼び出しは着信トーストが出るので載せない）。
        if (! $isInstant) {
            foreach ($invitedIds as $id) {
                if ($id === $user->id) {
                    continue;
                }
                Notification::create([
                    'user_id' => $id,
                    'type' => 'call_scheduled',
                    'data' => [
                        'call_id' => $call->id,
                        'project_id' => $project->id,
                        'project_title' => $project->title,
                        'scheduled_at' => $call->scheduled_at?->toIso8601String(),
                        'host_name' => $user->name,
                    ],
                ]);
            }
        }

        $this->pruneOldSignals();

        $call->load(['creator', 'participants.user']);

        return new CallResource($call);
    }

    public function show(Request $request, Project $project, Call $call)
    {
        $this->assertParticipant($request, $call);

        $call->load(['creator', 'participants.user', 'project']);

        return new CallResource($call);
    }

    /**
     * 入室。ここで初めて status が live になる（予約通話の場合）。
     */
    public function join(Request $request, Project $project, Call $call)
    {
        $participant = $this->assertParticipant($request, $call);

        if ($call->isOver()) {
            return response()->json(['message' => 'この通話は終了しています。'], 409);
        }

        // 満室チェック。既に入っている本人の再入室は数えない。
        $liveCount = $call->liveParticipants()
            ->where('user_id', '!=', $request->user()->id)
            ->count();

        if ($liveCount >= (int) config('webrtc.max_participants')) {
            return response()->json(['message' => 'この通話は満室です。'], 409);
        }

        $participant->update([
            'state' => 'joined',
            'joined_at' => $participant->joined_at ?? now(),
            'left_at' => null,
            'last_seen_at' => now(),
        ]);

        if ($call->status === 'scheduled') {
            $call->update(['status' => 'live', 'started_at' => now()]);
        }

        return $this->roomState($request, $call, 0);
    }

    /**
     * 通話中のポーリング先。名簿の更新と自分宛シグナルの取得を 1 往復にまとめてある。
     * 毎秒叩かれるので、ここだけは問い合わせを増やさないこと。
     */
    public function heartbeat(Request $request, Project $project, Call $call)
    {
        $participant = $this->assertParticipant($request, $call);

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        // 生存報告。これが途切れた参加者は他の人の画面から自動的に消える。
        $participant->forceFill(['last_seen_at' => now()])->save();

        return $this->roomState($request, $call, (int) ($validated['after'] ?? 0));
    }

    /**
     * 退出。ホストが抜けても通話は残す（他の人が話し続けられる）。
     * 全員抜けたら sweepAbandoned が終了扱いにする。
     */
    public function leave(Request $request, Project $project, Call $call)
    {
        $participant = $this->assertParticipant($request, $call);

        $participant->update([
            'state' => 'left',
            'left_at' => now(),
        ]);

        if ($call->status === 'live' && $call->liveParticipants()->count() === 0) {
            $call->update(['status' => 'ended', 'ended_at' => now()]);
            $call->signals()->delete();
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * 着信を断る。相手の画面には「応答なし」ではなく明示的に拒否と出る。
     */
    public function decline(Request $request, Project $project, Call $call)
    {
        $participant = $this->assertParticipant($request, $call);

        $participant->update(['state' => 'declined']);

        return response()->json(['status' => 'ok']);
    }

    /**
     * 通話を終了する。ホストだけが全員を切れる。
     */
    public function end(Request $request, Project $project, Call $call)
    {
        $this->assertParticipant($request, $call);

        if ($call->created_by !== $request->user()->id) {
            return response()->json(['message' => '通話を終了できるのは発信者だけです。'], 403);
        }

        $call->update([
            'status' => $call->status === 'scheduled' ? 'cancelled' : 'ended',
            'ended_at' => now(),
        ]);

        $call->participants()
            ->where('state', 'joined')
            ->update(['state' => 'left', 'left_at' => now()]);

        $call->signals()->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * この通話に呼べる人。開発者（スタジオ側）と、案件を持つ会社の担当者。
     */
    public function invitees(Request $request, Project $project)
    {
        return UserResource::collection($this->invitableUsers($project));
    }

    // ------------------------------------------------------------------

    private function invitableUsers(Project $project)
    {
        return User::query()
            ->where('role', 'developer')
            ->orWhereHas('companies', fn ($q) => $q->whereKey($project->company_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * 名簿と自分宛のシグナルをまとめて返す。join / heartbeat の共通の戻り値。
     */
    private function roomState(Request $request, Call $call, int $after)
    {
        $userId = $request->user()->id;

        /*
         * 1 回で持ち出す件数の上限。
         *
         * 書き込み側で 1 件あたりのサイズを縛っているので、ここは
         * 「最悪でも 50 × 64KB」を保証するための二重の歯止め。取りこぼしても
         * $after は進むので次の周期で続きが届く。4 人メッシュの ICE 集中時でも
         * 50 で足り、1対1なら 20 前後しか出ない。
         */
        $signals = $call->signals()
            ->select('id', 'from_user_id', 'type', 'payload')
            ->where('to_user_id', $userId)
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(50)
            ->get();

        $call->load(['participants.user']);

        return response()->json([
            // toArray() ではなく resolve() を通すこと。生の toArray() だと未ロードの
            // リレーションが MissingValue のまま配列に残り、JSON 化で落ちる。
            'call' => (new CallResource($call))->resolve($request),
            'participants' => CallParticipantResource::collection(
                $call->participants->load('user')
            )->resolve($request),
            'signals' => $signals->map(fn (CallSignal $s) => [
                'id' => $s->id,
                'from_user_id' => $s->from_user_id,
                'type' => $s->type,
                // payload は JSON 文字列で保存してあるので、そのまま復元して返す。
                'payload' => json_decode($s->payload, true),
            ])->all(),
            'last_signal_id' => $signals->max('id') ?? $after,
            'poll_interval_ms' => (int) config('webrtc.poll_interval_ms'),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function assertParticipant(Request $request, Call $call)
    {
        $participant = $call->participants()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_if(! $participant, 403, 'この通話には参加できません。');

        return $participant;
    }

    /**
     * 終わった通話のシグナルは意味を持たない。溜まると共有ホスティングの容量を
     * 無駄に食うので、通話を作るたびに古いものを落としておく。
     */
    private function pruneOldSignals(): void
    {
        CallSignal::where('created_at', '<', now()->subHours(6))->delete();
    }
}
