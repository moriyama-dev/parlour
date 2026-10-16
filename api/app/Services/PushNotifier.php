<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

use function Illuminate\Support\defer;

/**
 * 端末への通知（Web Push）。
 *
 * 入口は2つ:
 *   - お知らせ（notifications 行）が作られたとき … Notification::booted から自動で呼ばれる。
 *     ベルに出るものはすべてスマホにも出る、を1か所で保証するため。
 *   - ベルに残さないもの（即時の着信）… 呼び出し側が send() を直接呼ぶ。
 *
 * 配信サーバーへの POST は defer() でレスポンスを返した後に行う。
 * 共有ホスティングにはキューワーカーが無く、cron 経由だと着信が最大1分遅れるため。
 * 配信に失敗しても本来の操作（メッセージ送信など）は成功させる。
 */
class PushNotifier
{
    /** お知らせの種類 → 利用者がオン・オフする分類（User::NOTIFICATION_CATEGORIES）。 */
    public const CATEGORY_OF = [
        'message_received' => 'message',
        'task_pending_review' => 'task',
        'task_approved' => 'task',
        'task_rejected' => 'task',
        'invoice_sent' => 'invoice',
        'call_scheduled' => 'call',
        'call_incoming' => 'call',
        'subscription_created' => 'subscription',
        'subscription_started' => 'subscription',
        'subscription_canceled' => 'subscription',
        'subscription_payment_failed' => 'subscription',
        // 設定画面の「テスト通知」。分類の設定に関係なく送る。
        'test' => null,
    ];

    public function enabled(): bool
    {
        return filled(config('webpush.public_key')) && filled(config('webpush.private_key'));
    }

    public function forNotification(Notification $notification): void
    {
        $this->send($notification->user_id, $notification->type, $notification->data ?? [], $notification->id);
    }

    public function send(User|int $user, string $type, array $data = [], ?int $notificationId = null): void
    {
        if (! $this->enabled() || ! array_key_exists($type, self::CATEGORY_OF)) {
            return;
        }

        $userId = $user instanceof User ? $user->id : $user;

        defer(fn () => $this->deliver($userId, $type, $data, $notificationId));
    }

    /** 実際に配信サーバーへ送る。同期。届いた端末の数を返す。 */
    public function deliver(int $userId, string $type, array $data, ?int $notificationId = null): int
    {
        try {
            $user = User::find($userId);
            $category = self::CATEGORY_OF[$type] ?? null;
            if (! $user || ($category && ! $user->wantsPush($category))) {
                return 0;
            }

            $subscriptions = $user->pushSubscriptions()->get();
            if ($subscriptions->isEmpty()) {
                return 0;
            }

            $messages = $subscriptions->map(fn (PushSubscription $s) => [
                'subscription' => $s,
                'payload' => json_encode(
                    $this->payload($user, $type, $data, $notificationId, $s->locale),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ])->all();

            return $this->transmit($messages, $this->options($type));
        } catch (Throwable $e) {
            Log::warning('web push failed', ['type' => $type, 'user' => $userId, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * 端末で表示する内容。sw.js がこの形をそのまま showNotification に渡す。
     * 配信サーバーの上限は 4KB なので本文は短く切る。
     */
    public function payload(User $user, string $type, array $data, ?int $notificationId, string $locale): array
    {
        $locale = in_array($locale, ['ja', 'en'], true) ? $locale : 'ja';
        $replace = collect($data)
            ->filter(fn ($v) => is_scalar($v))
            ->map(fn ($v) => Str::limit((string) $v, 120))
            ->all() + ['comment' => '', 'excerpt' => ''];

        // 日時は受け取った端末のタイムゾーンで出したいので、整形は sw.js に任せる
        // （本文には目印だけ入れ、ISO 文字列を別に渡す）。
        $at = null;
        if ($type === 'call_scheduled' && ! empty($data['scheduled_at'])) {
            $replace['at'] = '{{at}}';
            $at = $data['scheduled_at'];
        }
        if ($type === 'subscription_payment_failed' && isset($data['amount'])) {
            $replace['amount'] = number_format((float) $data['amount'], 2).' '.($data['currency'] ?? 'CAD');
        }

        $url = $this->url($user, $type, $data);
        if ($notificationId) {
            // 開いたときにベルの既読も付ける（SW からは API トークンが見えないので SPA 側で）。
            $url .= (str_contains($url, '?') ? '&' : '?').'notif='.$notificationId;
        }

        return [
            'title' => trim(__("push.{$type}.title", $replace, $locale)),
            'body' => trim(__("push.{$type}.body", $replace, $locale)),
            'url' => $url,
            // 同じ tag の通知は端末上で置き換わる。メッセージは案件ごとにまとめる。
            'tag' => match ($type) {
                'message_received' => 'message-'.($data['project_id'] ?? ''),
                'call_incoming' => 'call-'.($data['call_id'] ?? ''),
                default => $type.'-'.($notificationId ?? Str::random(6)),
            },
            'type' => $type,
            'at' => $at,
            'locale' => $locale,
        ];
    }

    /** 通知を押したときに開く画面。AppNav の notifPath と揃える。 */
    public function url(User $user, string $type, array $data): string
    {
        $project = $data['project_id'] ?? null;
        $isClient = $user->role === 'client';

        return match (true) {
            $type === 'message_received' => $isClient
                ? "/client/projects/{$project}/messages"
                : "/messages?project={$project}",
            $type === 'task_pending_review' => $isClient
                ? '/client/tasks/'.($data['task_id'] ?? '')
                : "/projects/{$project}",
            $type === 'invoice_sent' => '/billing/invoices/'.($data['invoice_id'] ?? ''),
            $type === 'call_incoming' => "/projects/{$project}/calls/".($data['call_id'] ?? ''),
            str_starts_with($type, 'subscription_') => '/billing?tab=Subscriptions',
            $type === 'test' => '/settings/notifications',
            $project !== null => "/projects/{$project}",
            default => '/',
        };
    }

    /** 着信は数十秒で意味を失うので短命・最優先にする。 */
    protected function options(string $type): array
    {
        return match ($type) {
            'call_incoming' => ['TTL' => 60, 'urgency' => 'high'],
            'message_received' => ['TTL' => 86400, 'urgency' => 'high'],
            default => ['TTL' => 86400, 'urgency' => 'normal'],
        };
    }

    /**
     * @param  array<int, array{subscription: PushSubscription, payload: string}>  $messages
     */
    protected function transmit(array $messages, array $options): int
    {
        $webPush = new WebPush(
            ['VAPID' => [
                'subject' => config('webpush.subject'),
                'publicKey' => config('webpush.public_key'),
                'privateKey' => config('webpush.private_key'),
            ]],
            $options,
            new Client(['timeout' => 5, 'connect_timeout' => 3]),
            // ライブラリの注意（GMP/BCMath が無い等）を trigger_error ではなくログへ。
            // Laravel は notice も例外に変えるので、渡さないと送信ごと失敗する。
            logger: app('log'),
        );

        $byEndpoint = [];
        foreach ($messages as $m) {
            $s = $m['subscription'];
            $byEndpoint[$s->endpoint] = $s;
            $webPush->queueNotification(new Subscription($s->endpoint, $s->public_key, $s->auth_token, 'aes128gcm'), $m['payload']);
        }

        $delivered = [];
        foreach ($webPush->flush() as $report) {
            $sub = $byEndpoint[$report->getEndpoint()] ?? null;
            if (! $sub) {
                continue;
            }
            if ($report->isSuccess()) {
                $delivered[] = $sub->id;
            } elseif ($report->isSubscriptionExpired()) {
                // 端末側で通知を切った・アプリを消した。二度と届かないので片付ける。
                $sub->delete();
            } else {
                Log::info('web push rejected', ['subscription' => $sub->id, 'reason' => $report->getReason()]);
            }
        }

        if ($delivered) {
            PushSubscription::whereKey($delivered)->update(['last_used_at' => now()]);
        }

        return count($delivered);
    }
}
