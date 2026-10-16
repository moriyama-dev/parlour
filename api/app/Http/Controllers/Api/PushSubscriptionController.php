<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 端末への通知（Web Push）の設定画面の裏側。
 *
 * 購読（endpoint と暗号鍵）はブラウザが発行し、ここはそれを預かるだけ。
 * どの分類を受け取るかは利用者ごと（端末ごとではない）。
 */
class PushSubscriptionController extends Controller
{
    public function show(Request $request, PushNotifier $push)
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $push->enabled(),
            'public_key' => config('webpush.public_key'),
            'preferences' => $user->notificationPreferences(),
            'devices' => $user->pushSubscriptions()
                ->latest()
                ->get(['id', 'endpoint_hash', 'device_name', 'locale', 'created_at', 'last_used_at']),
        ]);
    }

    /**
     * 購読の登録。同じ端末から何度呼ばれても1行のまま（鍵と言語だけ更新）。
     * 別のアカウントで使っていた端末なら、持ち主を付け替える
     * （1台の端末に2人分の通知が届かないように）。
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=]+$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=]+$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', 'in:ja,en'],
        ]);

        if (! $this->isAllowedEndpoint($validated['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => ['Unsupported push service.']]);
        }

        $user = $request->user();
        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($validated['endpoint'])],
            [
                'user_id' => $user->id,
                'endpoint' => $validated['endpoint'],
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'device_name' => $validated['device_name'] ?? null,
                'locale' => $validated['locale'] ?? 'ja',
            ]
        );

        $this->pruneExcess($user);

        return response()->json(['id' => $subscription->id], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    /** この端末で通知をオフにした・ログアウトした。endpoint で指す（id は端末側が知らない）。 */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:2048'],
            'id' => ['nullable', 'integer'],
        ]);

        $query = $request->user()->pushSubscriptions();
        if (! empty($validated['endpoint'])) {
            $query->where('endpoint_hash', PushSubscription::hashEndpoint($validated['endpoint']));
        } elseif (! empty($validated['id'])) {
            // 設定画面の端末一覧から、別の端末を外す。
            $query->whereKey($validated['id']);
        } else {
            throw ValidationException::withMessages(['endpoint' => ['endpoint or id is required.']]);
        }
        $query->delete();

        return response()->noContent();
    }

    public function updatePreferences(Request $request)
    {
        $rules = collect(User::NOTIFICATION_CATEGORIES)
            ->mapWithKeys(fn ($c) => [$c => ['sometimes', 'boolean']])
            ->all();
        $validated = $request->validate($rules);

        $user = $request->user();
        $user->notification_preferences = array_merge($user->notificationPreferences(), array_map('boolval', $validated));
        $user->save();

        return response()->json(['preferences' => $user->notificationPreferences()]);
    }

    /** 設定画面の「テスト通知」。その場で送って、届いた台数を返す。 */
    public function test(Request $request, PushNotifier $push)
    {
        if (! $push->enabled()) {
            return response()->json(['message' => 'Push is not configured.'], 503);
        }

        return response()->json(['delivered' => $push->deliver($request->user()->id, 'test', [])]);
    }

    /**
     * 送信先を主要ブラウザの配信サーバーに限る（config/webpush.php の allowed_hosts）。
     * サーバーはここに POST するので、任意の URL を受け付けると SSRF になる。
     */
    private function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        foreach (config('webpush.allowed_hosts', []) as $allowed) {
            if (Str::startsWith($allowed, '.') ? Str::endsWith($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    private function pruneExcess(User $user): void
    {
        $max = (int) config('webpush.max_per_user', 10);
        $stale = $user->pushSubscriptions()->latest('updated_at')->skip($max)->take(PHP_INT_MAX)->pluck('id');
        if ($stale->isNotEmpty()) {
            PushSubscription::whereKey($stale)->delete();
        }
    }
}
