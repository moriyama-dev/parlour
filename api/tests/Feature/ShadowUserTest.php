<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\ShadowUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shadow ユーザー（CLAUDE.md §18）。オーナーが顧客と全く同じ画面を体験するための影で、
 * 閲覧専用。顧客の実データを変えないこと、本人と同じ範囲しか見えないことが要点。
 */
class ShadowUserTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shadow-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        // Event::fake() は使わない。モデルイベント（User::created）まで止まり、Shadow が作られなくなる。
        config(['shadow.password' => self::SECRET]);
    }

    private function clientOf(Project $project, array $attributes = []): User
    {
        $client = User::factory()->client()->create($attributes);
        $client->companies()->attach($project->company_id);

        return $client;
    }

    private function shadowToken(User $original): string
    {
        return $this->postJson('/auth/login', [
            'email' => 'shadow+'.$original->id.'@takumi.ca',
            'password' => self::SECRET,
        ])->assertOk()->json('token');
    }

    /**
     * 本番では1リクエスト＝1プロセスだが、テストではアプリが使い回され、ガードが
     * 前のリクエストの利用者（差し替え後の元ユーザー）を覚えている。毎回忘れさせる。
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function getAs(string $token, string $uri): TestResponse
    {
        return $this->as($token)->getJson($uri);
    }

    // ------------------------------------------------------------ 作成と除外

    public function test_a_new_user_gets_a_shadow_automatically(): void
    {
        $client = User::factory()->client()->create();

        $shadow = $client->shadow;
        $this->assertNotNull($shadow);
        $this->assertSame('shadow+'.$client->id.'@takumi.ca', $shadow->email);
        $this->assertSame('shadow', $shadow->role);
    }

    public function test_the_owner_test_users_and_demo_accounts_get_no_shadow(): void
    {
        foreach (['yoshi@takumi.ca', 'test1@takumi.ca', 'test2@takumi.ca', 'demo-client@parlour.takumi.ca'] as $email) {
            $user = User::factory()->client()->create(['email' => $email]);
            $this->assertNull($user->shadow, $email);
        }
    }

    public function test_nothing_is_created_when_the_feature_is_disabled(): void
    {
        config(['shadow.password' => '']);

        $this->assertNull(User::factory()->client()->create()->shadow);
    }

    public function test_sync_fills_in_existing_users_and_removes_shadows_that_should_not_exist(): void
    {
        config(['shadow.password' => '']);
        $client = User::factory()->client()->create();
        $owner = User::factory()->developer()->create(['email' => 'yoshi@takumi.ca']);
        // 除外対象に紛れ込んだ Shadow（設定変更前に作られた等）。
        User::create([
            'name' => 'stray', 'email' => 'shadow+'.$owner->id.'@takumi.ca', 'password' => 'x',
            'role' => 'shadow', 'shadow_of_id' => $owner->id,
        ]);
        config(['shadow.password' => self::SECRET]);

        $this->assertSame(['created' => 1, 'removed' => 1], app(ShadowUsers::class)->sync());
        $this->assertNotNull($client->fresh()->shadow);
        $this->assertNull($owner->fresh()->shadow);
        // 2回目は何もしない。
        $this->assertSame(['created' => 0, 'removed' => 0], app(ShadowUsers::class)->sync());
    }

    public function test_shadows_never_show_up_as_clients_or_developers(): void
    {
        $developer = User::factory()->developer()->create();
        User::factory()->client()->create();

        $this->actingAs($developer, 'sanctum')->getJson('/clients')
            ->assertOk()
            ->assertJsonMissing(['role' => 'shadow']);
    }

    // ------------------------------------------------------------ ログインと表示

    public function test_logging_in_as_a_shadow_shows_the_original_user(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);

        $response = $this->postJson('/auth/login', [
            'email' => 'shadow+'.$client->id.'@takumi.ca',
            'password' => self::SECRET,
        ])->assertOk()
            ->assertJsonPath('user.id', $client->id)
            ->assertJsonPath('user.role', 'client');

        $this->getAs($response->json('token'), '/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.email', $client->email);
    }

    public function test_a_shadow_sees_exactly_what_the_original_sees(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $otherProject = Project::factory()->create();
        $token = $this->shadowToken($client);

        $this->getAs($token, "/projects/{$project->id}")->assertOk();
        // 本人が入れない他社の案件は Shadow でも入れない。
        $this->getAs($token, "/projects/{$otherProject->id}")->assertStatus(403);
        // 本人が client なので、developer 専用の画面も開けない。
        $this->getAs($token, '/clients')->assertStatus(403);
    }

    public function test_a_wrong_password_or_an_unknown_shadow_is_refused(): void
    {
        $client = User::factory()->client()->create();

        $this->postJson('/auth/login', ['email' => 'shadow+'.$client->id.'@takumi.ca', 'password' => 'nope'])
            ->assertStatus(401);
        $this->postJson('/auth/login', ['email' => 'shadow+999999@takumi.ca', 'password' => self::SECRET])
            ->assertStatus(401);
        // 共通パスワードで普通の利用者には入れない。
        $this->postJson('/auth/login', ['email' => $client->email, 'password' => self::SECRET])
            ->assertStatus(401);
    }

    public function test_shadow_login_is_off_when_the_password_is_not_configured(): void
    {
        $client = User::factory()->client()->create();
        config(['shadow.password' => '']);

        $this->postJson('/auth/login', ['email' => 'shadow+'.$client->id.'@takumi.ca', 'password' => ''])
            ->assertStatus(422);
        $this->postJson('/auth/login', ['email' => 'shadow+'.$client->id.'@takumi.ca', 'password' => self::SECRET])
            ->assertStatus(401);
    }

    // ------------------------------------------------------------ 閲覧専用

    public function test_a_shadow_cannot_write_anything(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $token = $this->shadowToken($client);

        $this->as($token)->postJson("/projects/{$project->id}/messages", ['body' => 'hello'])
            ->assertStatus(403)
            ->assertJsonPath('shadow', true);
        $this->as($token)->postJson('/requests', ['company_id' => $project->company_id, 'title' => 'x'])
            ->assertStatus(403);
        $this->as($token)->postJson('/push/subscriptions', [])->assertStatus(403);

        $this->assertSame(0, Message::count());
    }

    public function test_side_effects_of_reads_are_rolled_back(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $notification = Notification::create([
            'user_id' => $client->id,
            'type' => 'message_received',
            'data' => ['project_id' => $project->id],
        ]);
        $token = $this->shadowToken($client);

        // スレッドを開く＝本人のお知らせを既読にする GET。Shadow が開いても本人の未読は残る。
        $this->getAs($token, "/projects/{$project->id}/messages")->assertOk();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_a_shadow_can_log_out(): void
    {
        $client = User::factory()->client()->create();
        $token = $this->shadowToken($client);

        $this->as($token)->postJson('/auth/logout')->assertNoContent();
        $this->assertSame(0, $client->shadow->tokens()->count());
    }

    // ------------------------------------------------------------ 後始末

    public function test_deleting_the_original_removes_the_shadow_and_its_sessions(): void
    {
        $developer = User::factory()->developer()->create(['email' => 'yoshi@takumi.ca']);
        $client = User::factory()->client()->create();
        $shadowId = $client->shadow->id;
        $token = $this->shadowToken($client);

        $this->actingAs($developer, 'sanctum')->deleteJson("/clients/{$client->id}")->assertNoContent();

        $this->assertNull(User::find($shadowId));
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/auth/me')->assertStatus(401);
    }

    public function test_nobody_can_register_with_a_shadow_address(): void
    {
        $project = Project::factory()->create();
        $developer = User::factory()->developer()->create();
        $token = $this->actingAs($developer, 'sanctum')
            ->postJson("/companies/{$project->company_id}/invitations", [])
            ->assertCreated()
            ->json('token');

        $this->postJson("/invitations/{$token}/accept", [
            'name' => 'Impostor',
            'email' => 'shadow+1@takumi.ca',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
