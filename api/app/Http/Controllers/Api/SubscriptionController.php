<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\StripeBilling;
use App\Services\SubscriptionSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Stripe\Exception\ApiErrorException;

/**
 * 「料金」ページのサブスクタブの裏側。
 *
 * 開発者が会社ごとに契約（名前・金額・月払い/年払い）を作る → 顧客が Stripe Checkout で
 * カードを登録して開始 → 以後の課金・再試行は Stripe が行い、結果は Webhook
 * （StripeWebhookController）で届く。ここは作成・変更・解約と、Stripe 画面への入口だけ。
 *
 * 書き込みの開発者限定はルート側（role:developer）で掛けてある。
 */
class SubscriptionController extends Controller
{
    public function __construct(private StripeBilling $stripe, private SubscriptionSync $sync) {}

    public function index(Request $request)
    {
        $subscriptions = Subscription::visibleTo($request->user())
            ->with(['company', 'payments' => fn ($q) => $q->orderByDesc('period_start')->orderByDesc('id')])
            ->orderByRaw("case when status = 'canceled' then 1 else 0 end")
            ->orderByDesc('id')
            ->get();

        return SubscriptionResource::collection($subscriptions)
            ->additional(['meta' => ['stripe_enabled' => $this->stripe->enabled()]]);
    }

    public function show(Request $request, Subscription $subscription)
    {
        $this->authorizeView($request, $subscription);

        return new SubscriptionResource($subscription->load(['company', 'payments' => fn ($q) => $q->orderByDesc('period_start')]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            ...$this->termsRules(required: true),
        ]);

        $subscription = Subscription::create([
            'company_id' => $data['company_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'amount_cents' => $this->toCents($data['amount']),
            'currency' => config('services.stripe.currency', 'cad'),
            'interval' => $data['interval'],
            'status' => 'pending',
        ]);

        // 顧客に「支払い方法の登録をお願いします」を出す。
        $subscription->load('company')->notify('subscription_created');

        return (new SubscriptionResource($subscription->load('payments')))->response()->setStatusCode(201);
    }

    /**
     * 名前・説明・金額・周期の変更。
     *
     * 登録前ならこの行を書き換えるだけ。Stripe で動いているものは Stripe 側の価格を
     * 差し替え、次回の請求から新しい金額になる（日割りなし）。
     */
    public function update(Request $request, Subscription $subscription)
    {
        $data = $request->validate($this->termsRules(required: false));

        if ($subscription->status === 'canceled') {
            return response()->json(['message' => 'A canceled subscription cannot be changed.'], 422);
        }

        $subscription->fill(collect($data)->only(['name', 'description', 'interval'])->all());
        if (array_key_exists('amount', $data)) {
            $subscription->amount_cents = $this->toCents($data['amount']);
        }

        $priceChanged = $subscription->isDirty(['amount_cents', 'interval']);

        if ($priceChanged && ! $subscription->isPending()) {
            $remote = $this->callStripe(fn () => $this->stripe->changePrice($subscription));
            if ($remote instanceof JsonResponse) {
                return $remote;
            }
            // 名前・説明はこのポータルだけの情報なので、Stripe の値で上書きされる前に保存する。
            $subscription->save();
            $this->sync->applySubscription($remote, $subscription);
        } else {
            $subscription->save();
        }

        return new SubscriptionResource($subscription->fresh(['company', 'payments']));
    }

    /**
     * 解約。既定は「今の期間の終わりで止める」（前払い分はそのまま使える）。
     * immediately=true は即時に止める（返金はしない。必要なら Stripe 画面で行う）。
     */
    public function cancel(Request $request, Subscription $subscription)
    {
        $immediately = $request->boolean('immediately');

        if ($subscription->status === 'canceled') {
            return new SubscriptionResource($subscription->load(['company', 'payments']));
        }

        if ($subscription->isPending()) {
            // Stripe に実体が無いので、行を閉じるだけ。
            $subscription->update(['status' => 'canceled', 'canceled_at' => now()]);
        } else {
            $remote = $this->callStripe(fn () => $immediately
                ? $this->stripe->cancelNow($subscription->stripe_subscription_id)
                : $this->stripe->setCancelAtPeriodEnd($subscription->stripe_subscription_id, true));
            if ($remote instanceof JsonResponse) {
                return $remote;
            }
            $this->sync->applySubscription($remote, $subscription);
        }

        return new SubscriptionResource($subscription->fresh(['company', 'payments']));
    }

    /** 期間末での解約予約を取り消す。 */
    public function resume(Subscription $subscription)
    {
        if ($subscription->isPending() || ! $subscription->cancel_at_period_end || $subscription->status === 'canceled') {
            return response()->json(['message' => 'This subscription is not scheduled to cancel.'], 422);
        }

        $remote = $this->callStripe(fn () => $this->stripe->setCancelAtPeriodEnd($subscription->stripe_subscription_id, false));
        if ($remote instanceof JsonResponse) {
            return $remote;
        }
        $this->sync->applySubscription($remote, $subscription);

        return new SubscriptionResource($subscription->fresh(['company', 'payments']));
    }

    /** Webhook の取りこぼしが疑われるとき用。Stripe から状態と請求履歴を引き直す。 */
    public function refresh(Subscription $subscription)
    {
        if (! $subscription->isPending()) {
            $result = $this->callStripe(function () use ($subscription) {
                $this->sync->applySubscription($this->stripe->retrieveSubscription($subscription->stripe_subscription_id), $subscription);
                foreach ($this->stripe->invoicesFor($subscription->stripe_subscription_id) as $invoice) {
                    $this->sync->applyInvoice($invoice);
                }

                return true;
            });
            if ($result instanceof JsonResponse) {
                return $result;
            }
        }

        return new SubscriptionResource($subscription->fresh(['company', 'payments']));
    }

    /**
     * 顧客が「支払い方法を登録して開始」を押したとき。Stripe Checkout の URL を返し、
     * ブラウザはそこへ移動する。カード番号はこのサーバーを一切通らない。
     *
     * カードを入れるのは契約先の顧客本人なので、開発者からは呼べない。
     */
    public function checkout(Request $request, Subscription $subscription)
    {
        $user = $request->user();
        if ($user->role !== 'client' || ! $this->belongsToUser($request, $subscription)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (! $subscription->awaitsCheckout()) {
            return response()->json(['message' => 'This subscription has already been set up.'], 422);
        }
        if (! $this->stripe->enabled()) {
            return response()->json(['message' => 'Online payment is not available on this site.'], 503);
        }

        $url = $this->callStripe(function () use ($subscription) {
            $customerId = $this->stripe->ensureCustomer($subscription->company);

            return $this->stripe->checkoutUrl(
                $subscription,
                $customerId,
                $this->returnUrl(['checkout' => 'success']),
                $this->returnUrl(['checkout' => 'canceled']),
            );
        });
        if ($url instanceof JsonResponse) {
            return $url;
        }

        return response()->json(['url' => $url]);
    }

    /** カードの変更と Stripe 発行の請求書・領収書の閲覧（Stripe カスタマーポータル）。 */
    public function portal(Request $request)
    {
        $data = $request->validate(['company_id' => ['required', 'integer']]);
        $user = $request->user();

        $company = Company::find($data['company_id']);
        $allowed = $company && ($user->role === 'developer'
            || $user->companies()->whereKey($company->id)->exists());
        if (! $allowed) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (! $this->stripe->enabled() || ! $company->stripe_customer_id) {
            return response()->json(['message' => 'No payment method has been registered yet.'], 422);
        }

        $url = $this->callStripe(fn () => $this->stripe->portalUrl($company->stripe_customer_id, $this->returnUrl()));
        if ($url instanceof JsonResponse) {
            return $url;
        }

        return response()->json(['url' => $url]);
    }

    private function termsRules(bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Stripe の CAD の下限は 0.50。上限は誤入力（桁の打ち間違い）よけ。
            'amount' => [$req, 'numeric', 'min:0.5', 'max:100000'],
            'interval' => [$req, Rule::in(Subscription::INTERVALS)],
        ];
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function authorizeView(Request $request, Subscription $subscription): void
    {
        abort_unless($request->user()->role === 'developer' || $this->belongsToUser($request, $subscription), 403, 'Forbidden');
    }

    private function belongsToUser(Request $request, Subscription $subscription): bool
    {
        return $request->user()->companies()->whereKey($subscription->company_id)->exists();
    }

    /** Stripe から戻ってくる先（SPA の料金ページ・サブスクタブ）。 */
    private function returnUrl(array $query = []): string
    {
        return rtrim((string) config('app.url'), '/').'/billing?'.http_build_query(['tab' => 'Subscriptions', ...$query]);
    }

    /**
     * Stripe の失敗を 502 にして返す。中身（Stripe のエラー文）は利用者に見せず、ログにだけ残す。
     */
    private function callStripe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json(['message' => 'The payment service could not complete the request. Please try again later.'], 502);
        }
    }
}
