<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 端末への通知（Web Push）。
 *
 * 守りたいのは 3 点。
 *   1. 届くべき人に届き、送った本人と部外者には届かない。
 *   2. 利用者が切った分類は届かない（ベルの記録は残る）。
 *   3. サーバーが POST する先は主要ブラウザの配信サーバーだけ（SSRF を開けない）。
 *
 * 配信サーバーへの実際の送信は FakePushNotifier が横取りして記録する。
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private FakePushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webpush.public_key' => 'test-public',
            'webpush.private_key' => 'test-private',
        ]);
        $this->push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->push);
        $this->withoutDefer();
    }

    private function insider(Project $project): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($project->company_id);

        return $client;
    }

    private function subscribe(User $user, ?string $endpoint = null, string $locale = 'ja'): PushSubscription
    {
        $endpoint ??= 'https://fcm.googleapis.com/fcm/send/'.uniqid();

        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => 'p256dh',
            'auth_token' => 'auth',
            'locale' => $locale,
        ]);
    }

    private function body(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPk3-abc_def=', 'auth' => 'xyz-123'], 'locale' => 'en'];
    }

    public function test_subscribing_twice_from_the_same_device_keeps_one_row(): void
    {
        $user = User::factory()->client()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';

        $this->actingAs($user)->postJson('/push/subscriptions', $this->body($endpoint))->assertCreated();
        $this->actingAs($user)->postJson('/push/subscriptions', $this->body($endpoint))->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame('en', PushSubscription::first()->locale);
    }

    public function test_a_device_that_switches_accounts_only_notifies_the_new_owner(): void
    {
        $alice = User::factory()->client()->create();
        $bob = User::factory()->client()->create();
        $endpoint = 'https://web.push.apple.com/QGx1';

        $this->actingAs($alice)->postJson('/push/subscriptions', $this->body($endpoint))->assertCreated();
        $this->actingAs($bob)->postJson('/push/subscriptions', $this->body($endpoint))->assertOk();

        $this->assertSame(0, $alice->pushSubscriptions()->count());
        $this->assertSame(1, $bob->pushSubscriptions()->count());
    }

    public function test_endpoints_outside_the_known_push_services_are_refused(): void
    {
        $user = User::factory()->client()->create();

        foreach ([
            'http://fcm.googleapis.com/fcm/send/abc',          // 平文
            'https://169.254.169.254/latest/meta-data',        // クラウドのメタデータ
            'https://localhost/api/notifications',
            'https://fcm.googleapis.com.evil.example/x',       // 前方一致のすり抜け
            'https://evilpush.apple.com.example/x',
            'https://fcm.googleapis.com:8443/x',               // ポート指定
        ] as $endpoint) {
            $this->actingAs($user)->postJson('/push/subscriptions', $this->body($endpoint))
                ->assertUnprocessable();
        }

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_client_message_notifies_the_studio_but_not_the_sender_or_outsiders(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $client = $this->insider($project);
        $outsider = $this->insider(Project::factory()->create());
        foreach ([$dev, $client, $outsider] as $u) {
            $this->subscribe($u);
        }

        $this->actingAs($client)->postJson("/projects/{$project->id}/messages", ['body' => "Hello\nthere"])
            ->assertCreated();

        $this->assertSame([$dev->id], $this->push->recipients());
        $sent = $this->push->sent[0];
        $this->assertSame("/messages?project={$project->id}", strtok($sent['url'], '&'));
        $this->assertStringContainsString('Hello there', $sent['body']);
        $this->assertSame(1, Notification::where('user_id', $dev->id)->where('type', 'message_received')->count());
        $this->assertSame(0, Notification::where('user_id', $client->id)->count());
    }

    public function test_a_studio_message_reaches_the_client_with_a_client_side_link_in_their_language(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $client = $this->insider($project);
        $this->subscribe($client, locale: 'en');

        $this->actingAs($dev)->postJson("/projects/{$project->id}/messages", ['body' => 'Draft is ready'])
            ->assertCreated();

        $sent = $this->push->sent[0];
        $this->assertSame($client->id, $sent['user_id']);
        $this->assertStringStartsWith("/client/projects/{$project->id}/messages?notif=", $sent['url']);
        $this->assertSame("Message from {$dev->name}", $sent['title']);
    }

    public function test_a_category_switched_off_is_not_pushed_but_stays_in_the_bell(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $client = $this->insider($project);
        $this->subscribe($client);

        $this->actingAs($client)->putJson('/push/preferences', ['task' => false])
            ->assertOk()
            ->assertJsonPath('preferences.task', false)
            ->assertJsonPath('preferences.message', true);

        $this->actingAs($dev)->postJson("/projects/{$project->id}/tasks", ['title' => 'Hero image', 'type' => 'staging_review'])->assertCreated();
        $this->assertSame([], $this->push->sent);
        $this->assertSame(1, Notification::where('user_id', $client->id)->count());

        // 別の分類は届く。
        $this->actingAs($dev)->postJson("/projects/{$project->id}/messages", ['body' => 'hi'])->assertCreated();
        $this->assertSame([$client->id], $this->push->recipients());
    }

    public function test_an_instant_call_rings_the_phone_without_adding_to_the_bell(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $client = $this->insider($project);
        $this->subscribe($client);
        $this->subscribe($dev);

        $call = $this->actingAs($dev)->postJson("/projects/{$project->id}/calls", ['user_ids' => [$client->id]])
            ->assertCreated()->json('data.id');

        $this->assertSame([$client->id], $this->push->recipients());
        $this->assertSame('call_incoming', $this->push->sent[0]['type']);
        $this->assertSame("/projects/{$project->id}/calls/{$call}", $this->push->sent[0]['url']);
        $this->assertSame(0, Notification::count());
    }

    public function test_opening_a_thread_marks_its_message_notifications_read(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $client = $this->insider($project);

        $this->actingAs($client)->postJson("/projects/{$project->id}/messages", ['body' => 'hi'])->assertCreated();
        $this->assertSame(1, Notification::whereNull('read_at')->count());

        $this->actingAs($dev)->getJson("/projects/{$project->id}/messages")->assertOk();
        $this->assertSame(0, Notification::whereNull('read_at')->count());
    }

    public function test_one_user_cannot_remove_another_users_device(): void
    {
        $alice = User::factory()->client()->create();
        $bob = User::factory()->client()->create();
        $device = $this->subscribe($alice);

        $this->actingAs($bob)->deleteJson('/push/subscriptions', ['id' => $device->id])->assertNoContent();
        $this->assertModelExists($device);

        $this->actingAs($alice)->deleteJson('/push/subscriptions', ['endpoint' => $device->endpoint])->assertNoContent();
        $this->assertModelMissing($device);
    }

    public function test_nothing_is_sent_when_vapid_keys_are_not_configured(): void
    {
        config(['webpush.public_key' => null]);
        $user = User::factory()->client()->create();
        $this->subscribe($user);

        Notification::create(['user_id' => $user->id, 'type' => 'invoice_sent', 'data' => ['invoice_number' => 'INV-1']]);

        $this->assertSame([], $this->push->sent);
        $this->actingAs($user)->getJson('/push')->assertOk()->assertJsonPath('enabled', false);
    }
}

class FakePushNotifier extends PushNotifier
{
    /** @var array<int, array> 送ったつもりの payload（user_id 付き） */
    public array $sent = [];

    protected function transmit(array $messages, array $options): int
    {
        foreach ($messages as $m) {
            $this->sent[] = json_decode($m['payload'], true) + ['user_id' => $m['subscription']->user_id];
        }

        return count($messages);
    }

    public function recipients(): array
    {
        return array_values(array_unique(array_column($this->sent, 'user_id')));
    }
}
