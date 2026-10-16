<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Subscription;
use App\Services\StripeBilling;
use App\Services\SubscriptionSync;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;

/**
 * Stripe からの通知（POST /stripe/webhook）。
 *
 * 認証の外にある窓口なので、信用の根拠は Stripe-Signature の署名だけ。検証に通らない
 * ものは中身を一切見ずに 400 を返す。Stripe 側は同じイベントを再送することがあり、
 * 順番も保証されないので、処理はすべて SubscriptionSync の冪等な上書きで行う。
 *
 * Stripe ダッシュボードで購読するイベント:
 *   checkout.session.completed
 *   customer.subscription.created / updated / deleted
 *   invoice.finalized / paid / payment_failed / voided / marked_uncollectible
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeBilling $stripe, SubscriptionSync $sync)
    {
        try {
            $event = $stripe->constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $object = $event->data->object;

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->checkoutCompleted($object, $stripe, $sync);
                    break;

                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $local = $sync->applySubscription($object);
                    if ($local && $event->type === 'customer.subscription.deleted') {
                        $local->notify('subscription_canceled', developers: true);
                    }
                    break;

                case 'invoice.payment_failed':
                    [$payment, $firstFailure] = $sync->applyInvoice($object, failed: true);
                    if ($payment && $firstFailure) {
                        $payment->subscription->notify('subscription_payment_failed', [
                            'amount' => round($payment->amount_due / 100, 2),
                            'currency' => strtoupper($payment->currency),
                        ], developers: true);
                    }
                    break;

                case 'invoice.finalized':
                case 'invoice.paid':
                case 'invoice.voided':
                case 'invoice.marked_uncollectible':
                    $sync->applyInvoice($object);
                    break;
            }
        } catch (ApiErrorException $e) {
            // Stripe に問い合わせ直す途中で失敗した。500 を返せば Stripe が後で再送してくれる。
            report($e);

            return response()->json(['message' => 'Temporary failure.'], 500);
        }

        // 関係ないイベントも 200。返さないと Stripe が再送を続け、やがて無効化される。
        return response()->json(['received' => true]);
    }

    private function checkoutCompleted(object $session, StripeBilling $stripe, SubscriptionSync $sync): void
    {
        if (($session->mode ?? null) !== 'subscription' || empty($session->subscription)) {
            return;
        }

        $localId = data_get($session, 'metadata.parlour_subscription_id') ?? ($session->client_reference_id ?? null);
        $local = ctype_digit((string) $localId) ? Subscription::find((int) $localId) : null;
        if (! $local) {
            return;
        }

        $remoteId = is_string($session->subscription) ? $session->subscription : $session->subscription->id;

        $local->forceFill(['stripe_subscription_id' => $remoteId])->save();
        $sync->applySubscription($stripe->retrieveSubscription($remoteId), $local);

        // 「顧客が支払いを登録した」を開発者へ。このイベントは再送されうるので1回だけ。
        $alreadyTold = Notification::where('type', 'subscription_started')
            ->where('data->subscription_id', $local->id)
            ->exists();
        if (! $alreadyTold) {
            $local->notify('subscription_started', developers: true, clients: false);
        }
    }
}
