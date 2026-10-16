<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

/**
 * Stripe API の呼び出しをここに集める。
 *
 * コントローラーやコマンドは StripeClient を直接触らない。テストではこのクラスを
 * モックに差し替えるので、Stripe への通信は1か所だけ見ればよい。
 * Stripe から届いたオブジェクトを DB に写すのは SubscriptionSync の仕事。
 */
class StripeBilling
{
    private ?StripeClient $client = null;

    /** 鍵が無い環境（デモ・ローカル）では支払い登録を出さない。 */
    public function enabled(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
    }

    /** 会社の Stripe Customer。無ければ作って会社に覚えさせる。 */
    public function ensureCustomer(Company $company): string
    {
        if ($company->stripe_customer_id) {
            return $company->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'name' => $company->name,
            'metadata' => ['parlour_company_id' => (string) $company->id],
        ]);

        $company->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /**
     * 顧客がカードを登録してサブスクを始める Stripe Checkout の URL。
     *
     * 価格は契約ごとに違うので price_data でその場で作る。metadata に契約の id を
     * 積んでおくと、Webhook がどの順で届いても（checkout より先に subscription.created
     * が来ても）どの行の話か引ける。
     */
    public function checkoutUrl(Subscription $subscription, string $customerId, string $successUrl, string $cancelUrl): string
    {
        $meta = ['parlour_subscription_id' => (string) $subscription->id];

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $subscription->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $subscription->currency,
                    'unit_amount' => $subscription->amount_cents,
                    'recurring' => ['interval' => $subscription->interval],
                    'product_data' => ['name' => $subscription->name],
                ],
            ]],
            'subscription_data' => ['metadata' => $meta],
            'metadata' => $meta,
            'locale' => 'auto',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return $session->url;
    }

    public function retrieveSubscription(string $id): StripeSubscription
    {
        return $this->client()->subscriptions->retrieve($id);
    }

    /**
     * 金額・周期の変更。日割りはしない（次回の請求から新しい金額になる）。
     * 料金ページの規約「日割りの返金は行っていません」と揃えている。
     */
    public function changePrice(Subscription $subscription): StripeSubscription
    {
        return $this->client()->subscriptions->update($subscription->stripe_subscription_id, [
            'items' => [[
                'id' => $subscription->stripe_item_id,
                'price_data' => [
                    'currency' => $subscription->currency,
                    'product' => $subscription->stripe_product_id,
                    'unit_amount' => $subscription->amount_cents,
                    'recurring' => ['interval' => $subscription->interval],
                ],
            ]],
            'proration_behavior' => 'none',
        ]);
    }

    public function setCancelAtPeriodEnd(string $id, bool $cancel): StripeSubscription
    {
        return $this->client()->subscriptions->update($id, ['cancel_at_period_end' => $cancel]);
    }

    public function cancelNow(string $id): StripeSubscription
    {
        return $this->client()->subscriptions->cancel($id);
    }

    /** カードの変更・Stripe 発行の請求書の閲覧ができる Stripe のカスタマーポータル。 */
    public function portalUrl(string $customerId, string $returnUrl): string
    {
        $params = ['customer' => $customerId, 'return_url' => $returnUrl];

        // API で作ったポータル設定は Stripe の「既定」にならないので、id を明示して使う。
        // 未設定ならダッシュボードで保存した既定の設定が使われる。
        if ($configuration = config('services.stripe.portal_configuration')) {
            $params['configuration'] = $configuration;
        }

        return $this->client()->billingPortal->sessions->create($params)->url;
    }

    /** @return iterable<Invoice> */
    public function invoicesFor(string $subscriptionId): iterable
    {
        return $this->client()->invoices->all([
            'subscription' => $subscriptionId,
            'limit' => 100,
        ])->autoPagingIterator();
    }

    /**
     * Webhook の署名を検証してイベントにする。偽装された POST はここで例外になる。
     *
     * @throws \UnexpectedValueException|SignatureVerificationException
     */
    public function constructEvent(string $payload, string $signature): Event
    {
        return Webhook::constructEvent($payload, $signature, (string) config('services.stripe.webhook_secret'));
    }
}
