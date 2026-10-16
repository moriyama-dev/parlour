<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallSignal;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * このVMは Tailscale Funnel で公開インターネットに出す運用がある。
 * つまり「社内だから」「tailnet 内だから」で守られている前提は置けない。
 *
 * ここに書いてあるのは、公開して初めて意味を持つ防御（デモ資格情報の遮断・
 * ログインのレート制限）と、公開に関係なく成立していた権限の穴（請求書・
 * プロジェクトの書き込み、メールの露出、シグナルの投入量）の両方。
 * どれも一度は実際に成立していたものなので、消さないこと。
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function insider(Project $project): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($project->company_id);

        return $client;
    }

    private function invoiceFor(Project $project): Invoice
    {
        return Invoice::create([
            'project_id' => $project->id,
            'company_id' => $project->company_id,
            'invoice_number' => 'INV-TEST-1',
            'status' => 'draft',
            'issued_at' => now(),
            'due_at' => now()->addDays(30),
            'subtotal' => 100000,
            'tax_rate' => 10,
            'tax_amount' => 10000,
            'total' => 110000,
        ]);
    }

    // ---------------------------------------------------------- デモ資格情報

    public function test_demo_credentials_are_refused_outside_the_demo_host(): void
    {
        config(['demo.enabled' => false]);

        User::factory()->developer()->create([
            'email' => 'demo-dev@parlour.takumi.ca',
            'password' => 'demoparlour',
        ]);

        // このパスワードは公開リポジトリに平文で載っている。デモホスト以外で
        // 通ると、匿名の 1 リクエストで developer 権限を渡すことになる。
        $this->postJson('/auth/login', [
            'email' => 'demo-dev@parlour.takumi.ca',
            'password' => 'demoparlour',
        ])->assertStatus(401);
    }

    public function test_demo_credentials_still_work_on_the_demo_host(): void
    {
        config(['demo.enabled' => true]);

        User::factory()->developer()->create([
            'email' => 'demo-dev@parlour.takumi.ca',
            'password' => 'demoparlour',
        ]);

        $this->postJson('/auth/login', [
            'email' => 'demo-dev@parlour.takumi.ca',
            'password' => 'demoparlour',
        ])->assertOk();
    }

    public function test_the_demo_account_list_matches_the_seeder(): void
    {
        // ずれるとデモホストでログインできない / 本番で塞げない、のどちらかが起きる。
        $this->assertContains(DemoSeeder::DEV_EMAIL, config('demo.accounts'));
        $this->assertContains(DemoSeeder::CLIENT_EMAIL, config('demo.accounts'));
    }

    // -------------------------------------------------------- レート制限

    public function test_login_is_rate_limited_per_email_address(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/login', [
                'email' => 'victim@example.com',
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        // 6 回目は弾く。失敗 1 回につき bcrypt が約 200ms 動くので、
        // 無制限だと総当たりだけでなく CPU 枯渇の手段にもなる。
        $this->postJson('/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_throttling_one_account_does_not_lock_out_another(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'password']);
        User::factory()->create(['email' => 'owner@example.com', 'password' => 'password']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/auth/login', ['email' => 'victim@example.com', 'password' => 'wrong']);
        }

        // 鍵がメールなので、他人を撃たれてもオーナーは締め出されない。
        $this->postJson('/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ])->assertOk();
    }

    // ------------------------------------------------------------ 権限

    public function test_a_client_cannot_write_to_invoices_or_project_status(): void
    {
        $project = Project::factory()->create();
        $client = $this->insider($project);
        $invoice = $this->invoiceFor($project);

        $base = "/projects/{$project->id}";

        $this->actingAs($client, 'sanctum')
            ->patchJson("{$base}/invoices/{$invoice->id}/paid")->assertStatus(403);

        $this->actingAs($client, 'sanctum')
            ->patchJson("{$base}/invoices/{$invoice->id}/send")->assertStatus(403);

        $this->actingAs($client, 'sanctum')
            ->putJson("{$base}/invoices/{$invoice->id}", ['total' => 1])->assertStatus(403);

        $this->actingAs($client, 'sanctum')
            ->patchJson("{$base}/status", ['status' => 'archived'])->assertStatus(403);

        // 何も書き換わっていないこと。
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame(110000, (int) $invoice->fresh()->total);
        $this->assertSame('active', $project->fresh()->status);
    }

    public function test_a_client_can_still_read_and_approve(): void
    {
        $project = Project::factory()->create();
        $client = $this->insider($project);
        $this->invoiceFor($project);

        // 権限を締めた副作用でクライアントの通常操作を壊していないこと。
        $this->actingAs($client, 'sanctum')
            ->getJson("/projects/{$project->id}/invoices")->assertOk();

        $this->actingAs($client, 'sanctum')
            ->getJson("/projects/{$project->id}/tasks")->assertOk();

        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/approve")->assertOk();
    }

    public function test_a_developer_can_still_write(): void
    {
        $project = Project::factory()->create();
        $invoice = $this->invoiceFor($project);

        $this->actingAs(User::factory()->developer()->create(), 'sanctum')
            ->patchJson("/projects/{$project->id}/invoices/{$invoice->id}/paid")->assertOk();
    }

    // ------------------------------------------------------ 情報の露出

    public function test_a_client_never_sees_another_users_email(): void
    {
        $project = Project::factory()->create();
        $client = $this->insider($project);
        User::factory()->developer()->create(['email' => 'studio@example.com']);

        $response = $this->actingAs($client, 'sanctum')
            ->getJson("/projects/{$project->id}/calls/invitees")
            ->assertOk();

        // filter() は元のキーを保持する。invitees は名前順で返り、名前は
        // ファクトリのランダム値なので、values() で詰め直さないと
        // 「クライアントが何番目に並んだか」でキーが変わり assertSame が落ちる。
        $emails = collect($response->json('data'))->pluck('email')->filter()->values()->all();

        // 自分のメールは返る（設定画面などで要る）が、他人のは返らない。
        $this->assertSame([$client->email], $emails);
    }

    public function test_a_developer_still_sees_emails(): void
    {
        $project = Project::factory()->create();
        $this->insider($project);

        $response = $this->actingAs(User::factory()->developer()->create(), 'sanctum')
            ->getJson("/projects/{$project->id}/calls/invitees")
            ->assertOk();

        $this->assertNotEmpty(collect($response->json('data'))->pluck('email')->filter()->all());
    }

    public function test_the_framework_welcome_page_is_gone(): void
    {
        // 既定ページはフレームワークの正確なバージョンを表示し、web グループを
        // 通るため未認証リクエストごとに sessions 行を 1 行書いていた。
        $this->get('/')->assertStatus(404);
    }

    // ------------------------------------------ シグナルの投入量（通話）

    private function liveCall(Project $project, User $host): Call
    {
        $call = $project->calls()->create([
            'created_by' => $host->id,
            'status' => 'live',
            'started_at' => now(),
        ]);
        $call->participants()->create(['user_id' => $host->id, 'state' => 'joined', 'last_seen_at' => now()]);

        return $call;
    }

    public function test_an_oversized_signal_payload_is_rejected(): void
    {
        $project = Project::factory()->create();
        $host = User::factory()->developer()->create();
        $client = $this->insider($project);
        $call = $this->liveCall($project, $host);
        $call->participants()->create(['user_id' => $client->id, 'state' => 'joined', 'last_seen_at' => now()]);

        // 候補は数百バイト。無制限だと受信側が heartbeat でまとめて
        // json_decode したときにメモリ上限に当たり、通話から締め出される。
        $this->actingAs($host, 'sanctum')
            ->postJson("/projects/{$project->id}/calls/{$call->id}/signals", ['signals' => [
                ['to_user_id' => $client->id, 'type' => 'candidate', 'payload' => ['x' => str_repeat('A', 8192)]],
            ]])
            ->assertStatus(422);

        $this->assertSame(0, CallSignal::count());
    }

    public function test_a_signal_payload_must_be_structured_not_a_giant_string(): void
    {
        $project = Project::factory()->create();
        $host = User::factory()->developer()->create();
        $call = $this->liveCall($project, $host);

        $this->actingAs($host, 'sanctum')
            ->postJson("/projects/{$project->id}/calls/{$call->id}/signals", ['signals' => [
                ['to_user_id' => $host->id, 'type' => 'offer', 'payload' => str_repeat('A', 1000)],
            ]])
            ->assertStatus(422);
    }

    public function test_signals_are_refused_before_the_call_starts(): void
    {
        $project = Project::factory()->create();
        $host = User::factory()->developer()->create();

        $call = $project->calls()->create([
            'created_by' => $host->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
        $call->participants()->create(['user_id' => $host->id, 'state' => 'invited']);

        $this->actingAs($host, 'sanctum')
            ->postJson("/projects/{$project->id}/calls/{$call->id}/signals", ['signals' => [
                ['to_user_id' => $host->id, 'type' => 'offer', 'payload' => ['sdp' => 'v=0']],
            ]])
            ->assertStatus(409);
    }

    public function test_a_scheduled_call_nobody_joined_stops_ringing(): void
    {
        $project = Project::factory()->create();
        $host = User::factory()->developer()->create();
        $client = $this->insider($project);

        // scheduled_at を過去にすると二重発信ガードをすり抜けて着信欄に居座れた。
        $call = $project->calls()->create([
            'created_by' => $host->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subHours(3),
        ]);
        $call->participants()->create(['user_id' => $client->id, 'state' => 'invited']);

        Call::sweepAbandoned();

        $this->assertSame('cancelled', $call->fresh()->status);

        $this->actingAs($client, 'sanctum')
            ->getJson('/calls/ringing')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
