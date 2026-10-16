<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

/**
 * サブスク（会社ごとの定期契約）。
 *
 * Stripe への通信は StripeBilling をモックして止めるが、Webhook の署名検証だけは
 * 本物を通す（偽の POST を弾けることが、この窓口の安全性のすべてなので）。
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    private function clientOf(Company $company): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($company->id);

        return $client;
    }

    private function subscription(Company $company, array $attrs = []): Subscription
    {
        return Subscription::create([
            'company_id' => $company->id,
            'name' => 'Hosting & maintenance',
            'amount_cents' => 4900,
            'currency' => 'cad',
            'interval' => 'month',
            'status' => 'pending',
            ...$attrs,
        ]);
    }

    private function linked(Company $company, array $attrs = []): Subscription
    {
        return $this->subscription($company, [
            'status' => 'active',
            'stripe_subscription_id' => 'sub_123',
            'stripe_item_id' => 'si_123',
            'stripe_product_id' => 'prod_123',
            ...$attrs,
        ]);
    }

    /** Stripe の subscription オブジェクト（2025 年以降の形: 請求期間は item 側）。 */
    private function remoteSubscription(array $overrides = [], int $amount = 4900, string $interval = 'month', ?int $localId = null): StripeSubscription
    {
        return StripeSubscription::constructFrom([
            'id' => 'sub_123',
            'object' => 'subscription',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'start_date' => 1_790_000_000,
            'metadata' => $localId ? ['parlour_subscription_id' => (string) $localId] : [],
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_123',
                    'object' => 'subscription_item',
                    'current_period_end' => 1_792_592_000,
                    'price' => [
                        'id' => 'price_123',
                        'object' => 'price',
                        'product' => 'prod_123',
                        'currency' => 'cad',
                        'unit_amount' => $amount,
                        'recurring' => ['interval' => $interval],
                    ],
                ]],
            ],
            ...$overrides,
        ]);
    }

    private function postWebhook(array $event, ?string $secret = self::WEBHOOK_SECRET)
    {
        $payload = json_encode($event);
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, $secret);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sig,
        ], $payload);
    }

    private function invoiceEvent(string $type, string $status, int $localId, array $extra = []): array
    {
        return [
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => [
                'id' => 'in_123',
                'object' => 'invoice',
                'number' => 'ABC-0001',
                'status' => $status,
                'amount_due' => 4900,
                'amount_paid' => $status === 'paid' ? 4900 : 0,
                'currency' => 'cad',
                'hosted_invoice_url' => 'https://invoice.stripe.com/i/test',
                'invoice_pdf' => 'https://pay.stripe.com/invoice/test/pdf',
                'status_transitions' => ['paid_at' => $status === 'paid' ? 1_790_000_100 : null],
                'parent' => [
                    'type' => 'subscription_details',
                    'subscription_details' => [
                        'subscription' => 'sub_123',
                        'metadata' => ['parlour_subscription_id' => (string) $localId],
                    ],
                ],
                'lines' => ['object' => 'list', 'data' => [[
                    'id' => 'il_1', 'object' => 'line_item',
                    'period' => ['start' => 1_790_000_000, 'end' => 1_792_592_000],
                ]]],
                ...$extra,
            ]],
        ];
    }

    // ------------------------------------------------------------------
    // 作成・閲覧の権限
    // ------------------------------------------------------------------

    public function test_a_developer_creates_a_subscription_per_company_and_the_client_is_told(): void
    {
        $dev = User::factory()->developer()->create();
        $company = Company::factory()->create();
        $client = $this->clientOf($company);

        $res = $this->actingAs($dev)->postJson('/billing/subscriptions', [
            'company_id' => $company->id,
            'name' => 'Annual hosting',
            'amount' => 588.5,
            'interval' => 'year',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 588.5)
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonPath('data.interval', 'year')
            ->assertJsonPath('data.awaits_checkout', true);

        $this->assertSame(58850, Subscription::find($res->json('data.id'))->amount_cents);
        $this->assertTrue(Notification::where('user_id', $client->id)->where('type', 'subscription_created')->exists());
    }

    public function test_an_active_row_counts_as_live_even_before_stripe_ids_arrive(): void
    {
        // デモの架空データや、Webhook で Stripe の id が届く前の行。集計が 0 にならないこと。
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $this->subscription($company, ['status' => 'active', 'current_period_end' => now()->addDays(12)]);
        $this->subscription($company, ['name' => 'Not yet']);

        $res = $this->actingAs($client)->getJson('/billing/subscriptions')->assertOk();
        $live = collect($res->json('data'))->pluck('is_live', 'name');
        $this->assertTrue($live['Hosting & maintenance']);
        $this->assertFalse($live['Not yet']);
    }

    public function test_amount_and_interval_are_validated(): void
    {
        $dev = User::factory()->developer()->create();
        $company = Company::factory()->create();

        $this->actingAs($dev)->postJson('/billing/subscriptions', [
            'company_id' => $company->id, 'name' => 'x', 'amount' => 0.1, 'interval' => 'week',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'interval']);
    }

    public function test_a_client_cannot_create_change_or_cancel_subscriptions(): void
    {
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $sub = $this->subscription($company);

        $this->actingAs($client)->postJson('/billing/subscriptions', [
            'company_id' => $company->id, 'name' => 'Free', 'amount' => 1, 'interval' => 'month',
        ])->assertForbidden();
        $this->actingAs($client)->putJson("/billing/subscriptions/{$sub->id}", ['amount' => 1])->assertForbidden();
        $this->actingAs($client)->postJson("/billing/subscriptions/{$sub->id}/cancel")->assertForbidden();

        $this->assertSame(4900, $sub->fresh()->amount_cents);
    }

    public function test_a_client_sees_only_their_own_companys_subscriptions(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $client = $this->clientOf($mine);
        $own = $this->subscription($mine, ['name' => 'Mine']);
        $other = $this->subscription($theirs, ['name' => 'Theirs']);

        $res = $this->actingAs($client)->getJson('/billing/subscriptions')->assertOk();
        $this->assertSame(['Mine'], array_column($res->json('data'), 'name'));

        $this->actingAs($client)->getJson("/billing/subscriptions/{$other->id}")->assertForbidden();
        $this->actingAs($client)->getJson("/billing/subscriptions/{$own->id}")->assertOk();

        $dev = User::factory()->developer()->create();
        $this->actingAs($dev)->getJson('/billing/subscriptions')->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------------
    // 支払い登録（Stripe Checkout）
    // ------------------------------------------------------------------

    public function test_the_client_gets_a_checkout_url_for_a_pending_subscription(): void
    {
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $sub = $this->subscription($company);

        $this->mock(StripeBilling::class, function (MockInterface $m) use ($sub) {
            $m->shouldReceive('enabled')->andReturnTrue();
            $m->shouldReceive('ensureCustomer')->once()->andReturn('cus_123');
            $m->shouldReceive('checkoutUrl')->once()
                ->withArgs(fn ($s, $cus, $ok, $cancel) => $s->is($sub) && $cus === 'cus_123'
                    && str_contains($ok, 'checkout=success') && str_contains($cancel, 'checkout=canceled'))
                ->andReturn('https://checkout.stripe.com/c/pay/test');
        });

        $this->actingAs($client)->postJson("/billing/subscriptions/{$sub->id}/checkout")
            ->assertOk()->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/test');
    }

    public function test_checkout_is_refused_for_developers_outsiders_and_already_started_subscriptions(): void
    {
        $company = Company::factory()->create();
        $sub = $this->subscription($company);
        $started = $this->linked($company, ['stripe_subscription_id' => 'sub_other']);

        $this->mock(StripeBilling::class, function (MockInterface $m) {
            $m->shouldReceive('enabled')->andReturnTrue();
            $m->shouldNotReceive('checkoutUrl');
        });

        $dev = User::factory()->developer()->create();
        $this->actingAs($dev)->postJson("/billing/subscriptions/{$sub->id}/checkout")->assertForbidden();

        $outsider = $this->clientOf(Company::factory()->create());
        $this->actingAs($outsider)->postJson("/billing/subscriptions/{$sub->id}/checkout")->assertForbidden();

        $client = $this->clientOf($company);
        $this->actingAs($client)->postJson("/billing/subscriptions/{$started->id}/checkout")->assertUnprocessable();
    }

    public function test_checkout_is_unavailable_without_stripe_keys(): void
    {
        config(['services.stripe.secret' => null]);
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $sub = $this->subscription($company);

        $this->actingAs($client)->getJson('/billing/subscriptions')->assertJsonPath('meta.stripe_enabled', false);
        $this->actingAs($client)->postJson("/billing/subscriptions/{$sub->id}/checkout")->assertStatus(503);
    }

    // ------------------------------------------------------------------
    // Webhook
    // ------------------------------------------------------------------

    public function test_a_webhook_with_a_bad_signature_is_rejected_without_side_effects(): void
    {
        $company = Company::factory()->create();
        $sub = $this->subscription($company);

        $this->postWebhook($this->invoiceEvent('invoice.paid', 'paid', $sub->id), secret: 'whsec_wrong')
            ->assertStatus(400);

        $this->assertSame(0, $sub->payments()->count());
    }

    public function test_checkout_completed_links_the_subscription_and_tells_the_developer_once(): void
    {
        $dev = User::factory()->developer()->create();
        $company = Company::factory()->create();
        $sub = $this->subscription($company);

        $this->partialMock(StripeBilling::class, function (MockInterface $m) use ($sub) {
            $m->shouldReceive('retrieveSubscription')->with('sub_123')
                ->andReturn($this->remoteSubscription(localId: $sub->id));
        });

        $event = [
            'id' => 'evt_1', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_1', 'object' => 'checkout.session', 'mode' => 'subscription',
                'subscription' => 'sub_123', 'client_reference_id' => (string) $sub->id,
                'metadata' => ['parlour_subscription_id' => (string) $sub->id],
            ]],
        ];

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk(); // Stripe の再送

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame('sub_123', $sub->stripe_subscription_id);
        $this->assertSame('si_123', $sub->stripe_item_id);
        $this->assertNotNull($sub->current_period_end);
        $this->assertSame(1, Notification::where('user_id', $dev->id)->where('type', 'subscription_started')->count());
    }

    public function test_subscription_created_arriving_before_checkout_is_matched_by_metadata(): void
    {
        $company = Company::factory()->create();
        $sub = $this->subscription($company);

        $this->postWebhook([
            'id' => 'evt_2', 'object' => 'event', 'type' => 'customer.subscription.created',
            'data' => ['object' => $this->remoteSubscription(localId: $sub->id)->toArray()],
        ])->assertOk();

        $this->assertSame('sub_123', $sub->fresh()->stripe_subscription_id);
    }

    public function test_metadata_cannot_hijack_a_row_already_linked_to_another_stripe_subscription(): void
    {
        $company = Company::factory()->create();
        $sub = $this->linked($company, ['stripe_subscription_id' => 'sub_original']);

        $this->postWebhook([
            'id' => 'evt_3', 'object' => 'event', 'type' => 'customer.subscription.updated',
            'data' => ['object' => $this->remoteSubscription(['status' => 'canceled'], localId: $sub->id)->toArray()],
        ])->assertOk();

        $sub->refresh();
        $this->assertSame('sub_original', $sub->stripe_subscription_id);
        $this->assertSame('active', $sub->status);
    }

    public function test_a_failed_payment_is_recorded_and_announced_once_then_cleared_when_paid(): void
    {
        $dev = User::factory()->developer()->create();
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $sub = $this->linked($company);

        $failed = $this->invoiceEvent('invoice.payment_failed', 'open', $sub->id);
        $this->postWebhook($failed)->assertOk();
        $this->postWebhook($failed)->assertOk(); // 再送

        $this->actingAs($client)->getJson("/billing/subscriptions/{$sub->id}")
            ->assertJsonPath('data.payments.0.status', 'failed')
            ->assertJsonPath('data.payments.0.amount_due', 49);
        $this->assertSame(1, Notification::where('user_id', $client->id)->where('type', 'subscription_payment_failed')->count());
        $this->assertSame(1, Notification::where('user_id', $dev->id)->where('type', 'subscription_payment_failed')->count());

        // カードを差し替えて再試行が通った。
        $this->postWebhook($this->invoiceEvent('invoice.paid', 'paid', $sub->id))->assertOk();

        $this->assertSame(1, $sub->payments()->count());
        $this->actingAs($client)->getJson("/billing/subscriptions/{$sub->id}")
            ->assertJsonPath('data.payments.0.status', 'paid')
            ->assertJsonPath('data.payments.0.amount_paid', 49)
            ->assertJsonPath('data.payments.0.hosted_invoice_url', 'https://invoice.stripe.com/i/test');
    }

    public function test_a_price_change_made_in_the_stripe_dashboard_flows_back(): void
    {
        $company = Company::factory()->create();
        $sub = $this->linked($company);

        $this->postWebhook([
            'id' => 'evt_4', 'object' => 'event', 'type' => 'customer.subscription.updated',
            'data' => ['object' => $this->remoteSubscription(amount: 59900, interval: 'year')->toArray()],
        ])->assertOk();

        $sub->refresh();
        $this->assertSame(59900, $sub->amount_cents);
        $this->assertSame('year', $sub->interval);
    }

    // ------------------------------------------------------------------
    // 変更・解約
    // ------------------------------------------------------------------

    public function test_changing_a_pending_subscription_does_not_touch_stripe(): void
    {
        $dev = User::factory()->developer()->create();
        $sub = $this->subscription(Company::factory()->create());

        $this->mock(StripeBilling::class, fn (MockInterface $m) => $m->shouldNotReceive('changePrice'));

        $this->actingAs($dev)->putJson("/billing/subscriptions/{$sub->id}", ['amount' => 79, 'interval' => 'year'])
            ->assertOk()->assertJsonPath('data.amount', 79)->assertJsonPath('data.interval', 'year');
    }

    public function test_changing_the_price_of_a_live_subscription_updates_stripe_but_keeps_the_local_name(): void
    {
        $dev = User::factory()->developer()->create();
        $sub = $this->linked(Company::factory()->create());

        $this->mock(StripeBilling::class, function (MockInterface $m) {
            $m->shouldReceive('changePrice')->once()
                ->withArgs(fn ($s) => $s->amount_cents === 7900)
                ->andReturn($this->remoteSubscription(amount: 7900));
        });

        $this->actingAs($dev)->putJson("/billing/subscriptions/{$sub->id}", ['amount' => 79, 'name' => 'Care plan'])
            ->assertOk()->assertJsonPath('data.amount', 79)->assertJsonPath('data.name', 'Care plan');
    }

    public function test_cancel_defaults_to_period_end_and_can_be_resumed(): void
    {
        $dev = User::factory()->developer()->create();
        $sub = $this->linked(Company::factory()->create());

        $this->mock(StripeBilling::class, function (MockInterface $m) {
            $m->shouldReceive('setCancelAtPeriodEnd')->once()->with('sub_123', true)
                ->andReturn($this->remoteSubscription(['cancel_at_period_end' => true]));
            $m->shouldReceive('setCancelAtPeriodEnd')->once()->with('sub_123', false)
                ->andReturn($this->remoteSubscription());
            $m->shouldNotReceive('cancelNow');
        });

        $this->actingAs($dev)->postJson("/billing/subscriptions/{$sub->id}/cancel")
            ->assertOk()->assertJsonPath('data.cancel_at_period_end', true)->assertJsonPath('data.status', 'active');
        $this->actingAs($dev)->postJson("/billing/subscriptions/{$sub->id}/resume")
            ->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
    }

    public function test_canceling_a_pending_subscription_just_closes_it(): void
    {
        $dev = User::factory()->developer()->create();
        $sub = $this->subscription(Company::factory()->create());

        $this->mock(StripeBilling::class, fn (MockInterface $m) => $m->shouldNotReceive('cancelNow', 'setCancelAtPeriodEnd'));

        $this->actingAs($dev)->postJson("/billing/subscriptions/{$sub->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->actingAs($dev)->putJson("/billing/subscriptions/{$sub->id}", ['amount' => 10])->assertUnprocessable();
    }
}
