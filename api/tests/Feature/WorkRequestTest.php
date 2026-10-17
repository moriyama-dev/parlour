<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\RequestQueue;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼キュー。顧客が積み、開発者が会社の枠内で上から順に消化する。
 * ここで固定したいのは、順番・枠・状態の遷移と、他社の依頼と開発者メモが漏れないこと。
 */
class WorkRequestTest extends TestCase
{
    use RefreshDatabase;

    private function clientOf(Company $company): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($company->id);

        return $client;
    }

    public function test_a_client_can_queue_a_request_and_it_goes_to_the_back(): void
    {
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        WorkRequest::factory()->for($company)->create(['position' => 4]);
        User::factory()->developer()->create();

        $this->actingAs($client)->postJson('/requests', [
            'company_id' => $company->id,
            'title' => 'Change the hero photo',
            'category' => 'content',
            'video_url' => 'https://www.loom.com/share/abc',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.position', 5)
            ->assertJsonPath('data.queue_ahead', 1);

        // 開発者へお知らせ（スマホ通知もここから飛ぶ）
        $this->assertSame(1, Notification::where('type', 'request_created')->count());
    }

    public function test_a_client_cannot_queue_for_another_company_or_with_a_foreign_project(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $client = $this->clientOf($mine);
        $foreignProject = Project::factory()->for($theirs)->create();

        $this->actingAs($client)->postJson('/requests', ['company_id' => $theirs->id, 'title' => 'x'])
            ->assertForbidden();

        $this->actingAs($client)->postJson('/requests', [
            'company_id' => $mine->id,
            'project_id' => $foreignProject->id,
            'title' => 'x',
        ])->assertStatus(422);

        $this->assertDatabaseCount('work_requests', 0);
    }

    public function test_requests_of_other_companies_are_invisible(): void
    {
        $mine = Company::factory()->create();
        $client = $this->clientOf($mine);
        $foreign = WorkRequest::factory()->create();
        WorkRequest::factory()->for($mine)->create();

        $this->actingAs($client)->getJson('/requests')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($client)->getJson("/requests/{$foreign->id}")->assertNotFound();
        $this->actingAs($client)->postJson("/requests/{$foreign->id}/comments", ['body' => 'hi'])->assertNotFound();
        $this->actingAs($client)->getJson("/request-queues/{$foreign->company_id}")->assertNotFound();
    }

    public function test_the_client_reorders_the_queue_and_the_developer_takes_the_top(): void
    {
        $company = Company::factory()->create(['queue_active_limit' => 1]);
        $client = $this->clientOf($company);
        $dev = User::factory()->developer()->create();
        $a = WorkRequest::factory()->for($company)->create(['position' => 1]);
        $b = WorkRequest::factory()->for($company)->create(['position' => 2]);
        $c = WorkRequest::factory()->for($company)->create(['position' => 3]);

        $this->actingAs($client)->postJson('/requests/reorder', [
            'company_id' => $company->id,
            'ids' => [$c->id, $a->id],
        ])->assertOk();

        // 渡さなかった b は後ろに続く
        $this->assertSame([1, 2, 3], [$c->fresh()->position, $a->fresh()->position, $b->fresh()->position]);

        $this->actingAs($dev)->postJson("/request-queues/{$company->id}/next")
            ->assertOk()
            ->assertJsonPath('data.id', $c->id)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertNotNull($c->fresh()->due_at);
        $this->assertSame($dev->id, $c->fresh()->assignee_id);
    }

    public function test_reordering_rejects_ids_that_are_not_queued_in_that_company(): void
    {
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $started = WorkRequest::factory()->for($company)->create(['status' => 'in_progress']);
        $foreign = WorkRequest::factory()->create();

        $this->actingAs($client)->postJson('/requests/reorder', ['company_id' => $company->id, 'ids' => [$started->id]])
            ->assertStatus(422);
        $this->actingAs($client)->postJson('/requests/reorder', ['company_id' => $company->id, 'ids' => [$foreign->id]])
            ->assertStatus(422);
    }

    public function test_the_active_limit_and_pause_hold_back_new_work_unless_forced(): void
    {
        $company = Company::factory()->create(['queue_active_limit' => 1]);
        $dev = User::factory()->developer()->create();
        WorkRequest::factory()->for($company)->create(['status' => 'in_progress']);
        $waiting = WorkRequest::factory()->for($company)->create(['position' => 1]);

        $this->actingAs($dev)->postJson("/requests/{$waiting->id}/start")
            ->assertStatus(422)->assertJsonPath('reason', 'limit');
        $this->actingAs($dev)->postJson("/request-queues/{$company->id}/next")
            ->assertStatus(422)->assertJsonPath('reason', 'limit');

        // 急ぎの割り込みは force で
        $this->actingAs($dev)->postJson("/requests/{$waiting->id}/start", ['force' => true])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        $company->update(['queue_active_limit' => 5]);
        $client = $this->clientOf($company);
        $this->actingAs($client)->postJson("/request-queues/{$company->id}/pause", ['reason' => 'Busy season'])
            ->assertOk()->assertJsonPath('data.open_slots', 0);
        $next = WorkRequest::factory()->for($company)->create(['position' => 2]);
        $this->actingAs($dev)->postJson("/requests/{$next->id}/start")
            ->assertStatus(422)->assertJsonPath('reason', 'paused');

        $this->actingAs($client)->postJson("/request-queues/{$company->id}/resume")->assertOk();
        $this->actingAs($dev)->postJson("/requests/{$next->id}/start")->assertOk();
    }

    public function test_deliver_revise_and_approve(): void
    {
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $dev = User::factory()->developer()->create();
        $req = WorkRequest::factory()->for($company)->create(['status' => 'in_progress']);

        $this->actingAs($dev)->postJson("/requests/{$req->id}/deliver", [
            'delivery_note' => 'Updated on staging.',
            'delivery_url' => 'https://staging.example.com',
        ])->assertOk()->assertJsonPath('data.status', 'client_review');

        // 修正依頼はコメント必須
        $this->actingAs($client)->postJson("/requests/{$req->id}/revision", [])->assertStatus(422);
        $this->actingAs($client)->postJson("/requests/{$req->id}/revision", [
            'comment' => 'Brighter, please.',
            'video_url' => 'https://www.loom.com/share/xyz',
        ])->assertOk()
            ->assertJsonPath('data.status', 'revision')
            ->assertJsonPath('data.revision_count', 1);

        $this->actingAs($dev)->postJson("/requests/{$req->id}/deliver", ['delivery_note' => 'Brightened.'])->assertOk();

        $this->actingAs($client)->postJson("/requests/{$req->id}/approve", ['rating' => 5])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.rating', 5);

        $types = $req->events()->orderBy('id')->pluck('type')->all();
        $this->assertSame(['delivered', 'revision_requested', 'delivered', 'approved'], $types);
    }

    public function test_only_the_client_side_signs_off_and_only_the_studio_side_works(): void
    {
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $dev = User::factory()->developer()->create();
        $delivered = WorkRequest::factory()->for($company)->create(['status' => 'client_review']);
        $queued = WorkRequest::factory()->for($company)->create();

        $this->actingAs($dev)->postJson("/requests/{$delivered->id}/approve")->assertForbidden();
        $this->actingAs($client)->postJson("/requests/{$queued->id}/start")->assertForbidden();
        $this->actingAs($client)->postJson("/requests/{$queued->id}/time", ['minutes' => 30])->assertForbidden();
        $this->actingAs($client)->putJson("/request-queues/{$company->id}", [
            'active_limit' => 9, 'turnaround_hours' => 1,
        ])->assertForbidden();
    }

    public function test_internal_notes_and_time_logs_never_reach_the_client(): void
    {
        $company = Company::factory()->create(['queue_monthly_hours' => 2]);
        $client = $this->clientOf($company);
        $dev = User::factory()->developer()->create();
        $req = WorkRequest::factory()->for($company)->create(['status' => 'in_progress']);

        $this->actingAs($dev)->postJson("/requests/{$req->id}/comments", ['body' => 'Client is slow to reply', 'internal' => true])->assertOk();
        $this->actingAs($dev)->postJson("/requests/{$req->id}/time", ['minutes' => 45, 'note' => 'CSS'])->assertOk();
        $this->actingAs($dev)->postJson("/requests/{$req->id}/comments", ['body' => 'Started on this today.'])->assertOk();
        // 顧客が internal を送っても公開コメントになる
        $this->actingAs($client)->postJson("/requests/{$req->id}/comments", ['body' => 'Thanks!', 'internal' => true])->assertOk();

        $events = $this->actingAs($client)->getJson("/requests/{$req->id}")->assertOk()->json('data.events');
        $this->assertSame(['Started on this today.', 'Thanks!'], array_column($events, 'body'));

        $this->assertCount(4, $this->actingAs($dev)->getJson("/requests/{$req->id}")->json('data.events'));
        $this->assertSame(45, $req->fresh()->spent_minutes);
        // 月の枠の使用量は顧客にも合計だけ見せる
        $this->actingAs($client)->getJson("/request-queues/{$company->id}")
            ->assertOk()->assertJsonPath('data.minutes_this_month', 45);
    }

    public function test_a_client_may_edit_or_cancel_only_before_work_starts(): void
    {
        $company = Company::factory()->create();
        $client = $this->clientOf($company);
        $queued = WorkRequest::factory()->for($company)->create();
        $started = WorkRequest::factory()->for($company)->create(['status' => 'in_progress']);

        $this->actingAs($client)->putJson("/requests/{$queued->id}", ['title' => 'Renamed', 'estimate_hours' => 99])
            ->assertOk()->assertJsonPath('data.title', 'Renamed');
        // 見積もりは開発者の欄なので、顧客が送っても無視される
        $this->assertNull($queued->fresh()->estimate_hours);

        $this->actingAs($client)->putJson("/requests/{$started->id}", ['title' => 'Too late'])->assertStatus(422);
        $this->actingAs($client)->postJson("/requests/{$started->id}/cancel")->assertStatus(422);
        $this->actingAs($client)->postJson("/requests/{$queued->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_turnaround_skips_weekends_in_the_company_timezone(): void
    {
        $company = Company::factory()->create(['timezone' => 'America/Toronto', 'queue_turnaround_hours' => 72]);
        $queue = app(RequestQueue::class);

        // 金曜 17:00 トロント → 平日だけで 72 時間 → 水曜 17:00
        $friday = CarbonImmutable::parse('2026-10-16 17:00', 'America/Toronto');
        $due = $queue->dueFrom($company, $friday)->setTimezone('America/Toronto');
        $this->assertSame('2026-10-21 17:00', $due->format('Y-m-d H:i'));

        // 土曜に着手しても月曜 0 時から数える
        $saturday = CarbonImmutable::parse('2026-10-17 10:00', 'America/Toronto');
        $this->assertSame('2026-10-20 00:00', $queue->dueFrom($company, $saturday, 24)->setTimezone('America/Toronto')->format('Y-m-d H:i'));
    }

    public function test_the_studio_dashboard_lists_every_company_queue(): void
    {
        $dev = User::factory()->developer()->create();
        $company = Company::factory()->create(['queue_active_limit' => 2]);
        WorkRequest::factory()->for($company)->create(['status' => 'in_progress', 'due_at' => now()->subHour()]);
        WorkRequest::factory()->for($company)->create();

        $row = collect($this->actingAs($dev)->getJson('/request-queues')->assertOk()->json('data'))
            ->firstWhere('company_id', $company->id);

        $this->assertSame(1, $row['active']);
        $this->assertSame(1, $row['open_slots']);
        $this->assertSame(1, $row['overdue']);
        $this->assertSame(1, $row['counts']['queued']);

        $client = $this->clientOf($company);
        $this->actingAs($client)->getJson('/request-queues')->assertForbidden();
    }

    public function test_the_demo_seeder_fills_every_column_of_the_queue_and_is_idempotent(): void
    {
        config(['app.demo_mode' => true]);

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $company = Company::where('slug', 'demo-sakura-trading')->firstOrFail();
        $statuses = $company->workRequests()->pluck('status')->countBy();

        $this->assertSame(1, $statuses['client_review']);
        $this->assertSame(1, $statuses['in_progress']);
        $this->assertSame(3, $statuses['queued']);
        $this->assertSame(1, $statuses['done']);
        $this->assertDatabaseCount('testimonials', 1);
        $this->assertDatabaseCount('referrals', 1);

        // 顧客から開発者メモは見えない
        $client = User::where('email', DemoSeeder::CLIENT_EMAIL)->first();
        $inProgress = $company->workRequests()->where('status', 'in_progress')->first();
        $types = array_column($this->actingAs($client)->getJson("/requests/{$inProgress->id}")->json('data.events'), 'type');
        $this->assertNotContains('note', $types);
        $this->assertNotContains('time', $types);
    }
}
