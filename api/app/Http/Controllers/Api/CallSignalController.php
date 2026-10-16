<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallSignal;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * SDP と ICE 候補の受け渡しだけを担当する。
 *
 * サーバーは中身を解釈しない郵便受けで、payload はブラウザが作った JSON を
 * そのまま預かって宛先に渡すだけ。取り出しは CallController@heartbeat 側
 * （名簿の取得と 1 往復にまとめてある）。
 *
 * ICE 候補は接続確立の一瞬に十数個まとめて出るので、1 件ずつ POST せず
 * 配列で受ける。これをやらないとポーリング間隔ごとに数十リクエストが飛ぶ。
 */
class CallSignalController extends Controller
{
    /**
     * 1 通話・1 送信者あたりのシグナル行数の上限。実測の正常値（4人メッシュ＋
     * 画面共有で 150 行未満）に対して十分な余裕を取った異常検知用のしきい値。
     */
    private const MAX_SIGNALS_PER_SENDER = 500;

    public function store(Request $request, Project $project, Call $call)
    {
        $userId = $request->user()->id;

        $isParticipant = $call->participants()->where('user_id', $userId)->exists();
        abort_if(! $isParticipant, 403, 'この通話には参加できません。');

        // 開始前の通話にシグナルだけ積むことができないようにする。
        if ($call->status !== 'live') {
            return response()->json(['message' => 'この通話は進行中ではありません。'], 409);
        }

        $validated = $request->validate([
            'signals' => ['required', 'array', 'max:50'],
            'signals.*.to_user_id' => ['required', 'integer'],
            'signals.*.type' => ['required', 'string', 'in:offer,answer,candidate,bye'],
            // ブラウザが送るのは RTCSessionDescription / RTCIceCandidate を
            // toJSON() したオブジェクトだけ。array に限定しておくと、巨大な
            // 文字列を 1 個投げ込む経路がここで閉じる。
            'signals.*.payload' => ['required', 'array'],
        ]);

        /*
         * 1 通話・1 送信者あたりの行数上限。
         *
         * シグナルは接続確立の数秒で終わる通信で、4 人メッシュに画面共有の
         * 再交渉を足しても実測で 150 行に届かない。500 を超えるのは異常系なので、
         * DB を膨らませる用途に使われる前にここで止める。共有ホスティングには
         * 容量クォータがあり、アプリ層以外に歯止めが無い。
         */
        $mine = CallSignal::where('call_id', $call->id)->where('from_user_id', $userId)->count();

        if ($mine >= self::MAX_SIGNALS_PER_SENDER) {
            return response()->json(['message' => 'シグナルの送信量が上限を超えました。'], 429);
        }

        // 宛先はこの通話の参加者に限る。id を差し替えて他人に SDP を送り込めないようにする。
        $allowed = $call->participants()->pluck('user_id')->all();

        $now = now();
        $rows = [];

        foreach ($validated['signals'] as $signal) {
            $to = (int) $signal['to_user_id'];

            if ($to === $userId || ! in_array($to, $allowed, true)) {
                continue;
            }

            $payload = json_encode($signal['payload']);

            /*
             * 種類ごとのサイズ上限。
             *
             * 保存先が longText なので、上限が無いと 1 リクエストで数MBを投入でき、
             * それを受け取る側は heartbeat でまとめて json_decode するため
             * メモリ上限に当たって 500 を返し続ける（＝相手のブラウザを通話から
             * 締め出せる）。一律 8KB にすると画面共有込みの SDP が壊れるので、
             * SDP は広く、候補は狭く取る。
             */
            $limit = match ($signal['type']) {
                'offer', 'answer' => 65536,
                'candidate' => 4096,
                default => 1024,
            };

            if (strlen($payload) > $limit) {
                return response()->json([
                    'message' => "シグナル({$signal['type']})が大きすぎます。",
                ], 422);
            }

            $rows[] = [
                'call_id' => $call->id,
                'from_user_id' => $userId,
                'to_user_id' => $to,
                'type' => $signal['type'],
                'payload' => $payload,
                'created_at' => $now,
            ];
        }

        if ($rows) {
            CallSignal::insert($rows);
        }

        return response()->json(['accepted' => count($rows)]);
    }
}
