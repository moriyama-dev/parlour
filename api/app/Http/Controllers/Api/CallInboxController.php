<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallResource;
use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 「今、自分を呼んでいる通話はあるか」を答えるだけのエンドポイント。
 *
 * 共有ホスティングでは WebSocket で着信を押し込めないので、ログイン中の全員が
 * ここを数秒おきに引く。返すのは 0〜1 件で、インデックス（calls.status,
 * call_participants.user_id+state）だけで引けるように条件を絞ってある。
 */
class CallInboxController extends Controller
{
    public function ringing(Request $request)
    {
        $user = $request->user();
        $window = (int) config('webrtc.join_window_minutes');

        // 放置された通話を閉じる。ここは全ログインユーザーが数秒おきに叩くので、
        // 実際に走らせるのは 30 秒に 1 回だけにする（誰が当たり番でも結果は同じ）。
        if (Cache::add('calls:sweep', 1, 30)) {
            Call::sweepAbandoned();
        }

        $calls = Call::query()
            ->with(['creator', 'project', 'participants.user'])
            // 自分がかけた通話が自分の画面で鳴るのはおかしいので除く。
            ->where('created_by', '!=', $user->id)
            ->whereHas('participants', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->where(function ($q2) {
                        // まだ応答していない。
                        $q2->where('state', 'invited')
                            // または入室済みだがハートビートが途切れている＝タブを閉じた
                            // /ブラウザが落ちた。この場合は鳴らし直して復帰させる。
                            // 通話画面を開いている本人は毎秒 heartbeat を打つので鳴らない。
                            ->orWhere(function ($q3) {
                                $q3->where('state', 'joined')
                                    ->where(function ($q4) {
                                        $q4->whereNull('last_seen_at')
                                            ->orWhere('last_seen_at', '<', now()->subSeconds(Call::STALE_AFTER_SECONDS));
                                    });
                            });
                    });
                // 明示的に断った（declined）・自分から退出した（left）通話は鳴らし直さない。
            })
            ->where(function ($q) use ($window) {
                // 即時呼び出し。着信として鳴らすのは始まった直後だけにする。
                // 進行中の通話に後から入りたい場合は、案件ページの通話一覧から入る。
                $q->where(function ($q2) {
                    $q2->where('status', 'live')
                        ->where('started_at', '>=', now()->subMinutes(2));
                })
                    // 予約通話は開始 10 分前から入室可として出す
                    ->orWhere(function ($q2) use ($window) {
                        $q2->where('status', 'scheduled')
                            ->whereNotNull('scheduled_at')
                            ->where('scheduled_at', '<=', now()->addMinutes($window))
                            ->where('scheduled_at', '>=', now()->subHours(1));
                    });
            })
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return response()->json([
            // resolve() で未ロードのリレーション（MissingValue）を落としてから返す。
            'data' => CallResource::collection($calls)->resolve($request),
            'poll_interval_ms' => (int) config('webrtc.ring_poll_interval_ms'),
        ]);
    }

    /**
     * ブラウザの RTCPeerConnection にそのまま渡せる形で ICE 設定を返す。
     *
     * TURN の資格情報を JS に埋め込まずここで配るのは、後から TURN を足すときに
     * フロントの再ビルドを不要にするため（.env に 3 行足してキャッシュを切るだけ）。
     */
    public function ice(Request $request)
    {
        $iceServers = [];

        $stun = config('webrtc.stun_urls');
        if (! empty($stun)) {
            $iceServers[] = ['urls' => $stun];
        }

        $turnUrl = config('webrtc.turn_url');
        if ($turnUrl) {
            $iceServers[] = array_filter([
                'urls' => array_values(array_filter(array_map('trim', explode(',', $turnUrl)))),
                'username' => config('webrtc.turn_username'),
                'credential' => config('webrtc.turn_credential'),
            ]);
        }

        return response()->json([
            'ice_servers' => $iceServers,
            // TURN 未設定で relay 強制にすると誰ともつながらないので、両方揃ったときだけ効かせる。
            'ice_transport_policy' => ($turnUrl && config('webrtc.force_relay')) ? 'relay' : 'all',
            'has_turn' => (bool) $turnUrl,
            'max_participants' => (int) config('webrtc.max_participants'),
            'poll_interval_ms' => (int) config('webrtc.poll_interval_ms'),
        ]);
    }
}
