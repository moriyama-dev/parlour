<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Stripe\Invoice as StripeInvoice;
use Stripe\Subscription as StripeSubscription;

/**
 * Stripe から届いたオブジェクトを DB の行に写す。
 *
 * Webhook と日次の照合コマンド（subscriptions:sync）の両方がここを通るので、
 * 同じイベントを何度受けても結果が変わらない（冪等）ように書いてある。
 * Stripe の API バージョンによって項目の場所が変わったもの（請求期間・請求書の
 * 親サブスク）は、新旧どちらの形でも読めるようにしている。
 */
class SubscriptionSync
{
    public function applySubscription(StripeSubscription $remote, ?Subscription $local = null): ?Subscription
    {
        $local ??= $this->findLocal($remote->id, data_get($remote, 'metadata.parlour_subscription_id'));

        if (! $local) {
            // Stripe のダッシュボードで直接作ったものなど、このポータル発ではない契約。
            return null;
        }

        $item = data_get($remote, 'items.data.0');
        $price = data_get($item, 'price');

        $attrs = [
            'stripe_subscription_id' => $remote->id,
            'status' => $remote->status,
            'cancel_at_period_end' => (bool) $remote->cancel_at_period_end,
            'canceled_at' => $this->time($remote->canceled_at ?? null),
            'started_at' => $this->time($remote->start_date ?? null),
            // 2025 年以降の API では請求期間はサブスクではなく品目（item）側にある。
            'current_period_end' => $this->time(data_get($item, 'current_period_end') ?? data_get($remote, 'current_period_end')),
        ];

        if ($item) {
            $attrs['stripe_item_id'] = $item->id;
        }

        // 紐づいた後は Stripe が正。ダッシュボードで金額を変えてもここに戻ってくる。
        if ($price) {
            $attrs['stripe_price_id'] = $price->id;
            $attrs['stripe_product_id'] = is_string($price->product) ? $price->product : data_get($price, 'product.id');
            if (isset($price->unit_amount)) {
                $attrs['amount_cents'] = (int) $price->unit_amount;
            }
            if ($interval = data_get($price, 'recurring.interval')) {
                $attrs['interval'] = $interval;
            }
            $attrs['currency'] = $price->currency ?? $local->currency;
        }

        $local->fill($attrs)->save();

        return $local;
    }

    /**
     * 請求書を履歴に写す。引き落としに失敗したのが「今回初めて」なら true を返す
     * （同じ失敗の Webhook が再送されても、お知らせを二重に出さないため）。
     *
     * @return array{0: ?SubscriptionPayment, 1: bool}
     */
    public function applyInvoice(StripeInvoice $invoice, bool $failed = false): array
    {
        $remoteSubId = data_get($invoice, 'parent.subscription_details.subscription') ?? data_get($invoice, 'subscription');
        if (! is_string($remoteSubId)) {
            $remoteSubId = data_get($remoteSubId, 'id');
        }
        $metaId = data_get($invoice, 'parent.subscription_details.metadata.parlour_subscription_id')
            ?? data_get($invoice, 'subscription_details.metadata.parlour_subscription_id');

        $local = $this->findLocal($remoteSubId, $metaId);
        if (! $local) {
            return [null, false];
        }
        if ($remoteSubId && ! $local->stripe_subscription_id) {
            $local->forceFill(['stripe_subscription_id' => $remoteSubId])->save();
        }

        $payment = SubscriptionPayment::firstOrNew(['stripe_invoice_id' => $invoice->id]);
        $firstFailure = $failed && $payment->failed_at === null;

        // 請求書自体の period は「前の期間の従量分」を指すことがあるので、明細の期間を優先する。
        $line = data_get($invoice, 'lines.data.0');

        $payment->fill([
            'subscription_id' => $local->id,
            'number' => $invoice->number ?? null,
            'status' => $invoice->status,
            'amount_due' => (int) ($invoice->amount_due ?? 0),
            'amount_paid' => (int) ($invoice->amount_paid ?? 0),
            'currency' => $invoice->currency ?? $local->currency,
            'period_start' => $this->time(data_get($line, 'period.start') ?? ($invoice->period_start ?? null)),
            'period_end' => $this->time(data_get($line, 'period.end') ?? ($invoice->period_end ?? null)),
            'paid_at' => $this->time(data_get($invoice, 'status_transitions.paid_at')),
            'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null,
            'invoice_pdf' => $invoice->invoice_pdf ?? null,
        ]);

        if ($firstFailure) {
            $payment->failed_at = now();
        }
        if ($invoice->status === 'paid') {
            // 後から払われた（カードを差し替えた）なら、失敗の印は消して良い。
            $payment->failed_at = null;
        }

        $payment->save();

        return [$payment, $firstFailure];
    }

    private function findLocal(?string $remoteId, mixed $metaId): ?Subscription
    {
        if ($remoteId && $found = Subscription::where('stripe_subscription_id', $remoteId)->first()) {
            return $found;
        }

        // まだ紐づいていない（checkout.session.completed より先に届いた）場合は metadata で引く。
        // 別の Stripe 契約に既に紐づいた行を奪わないよう、未紐づけのものに限る。
        if ($metaId && ctype_digit((string) $metaId)) {
            return Subscription::whereKey((int) $metaId)
                ->where(fn ($q) => $q->whereNull('stripe_subscription_id')->orWhere('stripe_subscription_id', $remoteId))
                ->first();
        }

        return null;
    }

    private function time(mixed $unix): ?Carbon
    {
        return is_numeric($unix) ? Carbon::createFromTimestamp((int) $unix) : null;
    }
}
