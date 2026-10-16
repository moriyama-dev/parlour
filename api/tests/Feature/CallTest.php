<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallSignal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ビデオ通話まわり。
 *
 * 守りたいのは 2 点。
 *   1. 通話は「その案件に関われる人」だけのもので、id を当てても入れない。
 *   2. SDP は接続相手にしか渡らない。宛先を差し替えて他人の SDP を横取りできない。
 *
 * 映像そのものは P2P でサーバーを通らないため、ここで検証できるのは部屋の管理と
 * シグナルの配送だけ。裏を返すと、その 2 つがサーバー側の責任の全部でもある。
 */
class CallTest extends TestCase
{
    use RefreshDatabase;

    private function developer(): User
    {
        return User::factory()->developer()->create();
    }

    /** その案件を持つ会社の担当者。 */
    private function insider(Project $project): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($project->company_id);

        return $client;
    }

    /** 認証済み・ロールも正しいが、別会社の担当者。 */
    private function outsider(): User
    {
        $outsider = User::factory()->client()->create();
        $outsider->companies()->attach(Project::factory()->create()->company_id);

        return $outsider;
    }

    public function test_an_instant_call_starts_live_and_invites_the_project_members(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();
        $client = $this->insider($project);

        $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/calls", ['title' => 'キックオフ'])
            // 新規作成なので 201。二度押しで既存の通話を返すときだけ 200 になる。
            ->assertCreated()
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.title', 'キックオフ');

        $call = Call::first();

        $this->assertEqualsCanonicalizing(
            [$developer->id, $client->id],
            $call->participants()->pluck('user_id')->all()
        );
    }

    public function test_a_scheduled_call_waits_and_notifies_the_other_participants(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();
        $client = $this->insider($project);

        $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/calls", [
                'title' => '定例',
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled');

        // 予約はベルのお知らせに載る。発信者本人には出さない。
        $this->assertDatabaseHas('notifications', [
            'user_id' => $client->id,
            'type' => 'call_scheduled',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $developer->id,
            'type' => 'call_scheduled',
        ]);
    }

    public function test_the_invited_client_is_rung_but_the_host_and_outsiders_are_not(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();
        $client = $this->insider($project);

        $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/calls")
            ->assertCreated();

        $this->actingAs($client, 'sanctum')
            ->getJson('/calls/ringing')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 自分がかけた通話が自分の画面で鳴るのはおかしい。
        $this->actingAs($developer, 'sanctum')
            ->getJson('/calls/ringing')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->outsider(), 'sanctum')
            ->getJson('/calls/ringing')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_declining_stops_the_ringing(): void
    {
        $project = Project::factory()->create();
        $client = $this->insider($project);

        $this->actingAs($this->developer(), 'sanctum')
            ->postJson("/projects/{$project->id}/calls")
            ->assertCreated();

        $call = Call::first();

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/calls/{$call->id}/decline")
            ->assertOk();

        $this->actingAs($client, 'sanctum')
            ->getJson('/calls/ringing')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_signals_reach_only_the_addressee(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();
        $client = $this->insider($project);

        $this->actingAs($developer, 'sanctum')->postJson("/projects/{$project->id}/calls");
        $call = Call::first();
        $base = "/projects/{$project->id}/calls/{$call->id}";

        $this->actingAs($developer, 'sanctum')->postJson("{$base}/join")->assertOk();
        $this->actingAs($client, 'sanctum')->postJson("{$base}/join")->assertOk();

        $this->actingAs($developer, 'sanctum')
            ->postJson("{$base}/signals", ['signals' => [
                ['to_user_id' => $client->id, 'type' => 'offer', 'payload' => ['sdp' => 'v=0']],
            ]])
            ->assertOk()
            ->assertJsonPath('accepted', 1);

        // 宛先本人は受け取れる。
        $this->actingAs($client, 'sanctum')
            ->postJson("{$base}/heartbeat", ['after' => 0])
            ->assertOk()
            ->assertJsonCount(1, 'signals')
            ->assertJsonPath('signals.0.payload.sdp', 'v=0');

        // 差出人のポーリングには出てこない（自分宛ではないため）。
        $this->actingAs($developer, 'sanctum')
            ->postJson("{$base}/heartbeat", ['after' => 0])
            ->assertOk()
            ->assertJsonCount(0, 'signals');
    }

    public function test_a_signal_addressed_outside_the_call_is_dropped(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();
        $stranger = $this->outsider();

        $this->actingAs($developer, 'sanctum')->postJson("/projects/{$project->id}/calls");
        $call = Call::first();

        $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/calls/{$call->id}/signals", ['signals' => [
                ['to_user_id' => $stranger->id, 'type' => 'offer', 'payload' => ['sdp' => 'v=0']],
            ]])
            ->assertOk()
            ->assertJsonPath('accepted', 0);

        $this->assertDatabaseCount('call_signals', 0);
    }

    public function test_an_outsider_cannot_reach_a_call(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($this->developer(), 'sanctum')->postJson("/projects/{$project->id}/calls");
        $call = Call::first();
        $base = "/projects/{$project->id}/calls/{$call->id}";
        $outsider = $this->outsider();

        // project.access で会社ごと弾かれる。
        $this->actingAs($outsider, 'sanctum')->getJson($base)->assertStatus(403);
        $this->actingAs($outsider, 'sanctum')->postJson("{$base}/join")->assertStatus(403);
        $this->actingAs($outsider, 'sanctum')
            ->postJson("{$base}/signals", ['signals' => [
                ['to_user_id' => 1, 'type' => 'offer', 'payload' => ['sdp' => 'v=0']],
            ]])
            ->assertStatus(403);
    }

    public function test_only_the_host_can_end_the_call(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();
        $client = $this->insider($project);

        $this->actingAs($developer, 'sanctum')->postJson("/projects/{$project->id}/calls");
        $call = Call::first();
        $base = "/projects/{$project->id}/calls/{$call->id}";

        $this->actingAs($client, 'sanctum')->postJson("{$base}/end")->assertStatus(403);
        $this->assertSame('live', $call->fresh()->status);

        $this->actingAs($developer, 'sanctum')->postJson("{$base}/end")->assertOk();
        $this->assertSame('ended', $call->fresh()->status);
    }

    public function test_ending_a_call_clears_its_signals(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();
        $client = $this->insider($project);

        $this->actingAs($developer, 'sanctum')->postJson("/projects/{$project->id}/calls");
        $call = Call::first();
        $base = "/projects/{$project->id}/calls/{$call->id}";

        $this->actingAs($developer, 'sanctum')->postJson("{$base}/join");
        $this->actingAs($developer, 'sanctum')->postJson("{$base}/signals", ['signals' => [
            ['to_user_id' => $client->id, 'type' => 'candidate', 'payload' => ['candidate' => 'x']],
        ]]);

        $this->assertDatabaseCount('call_signals', 1);

        $this->actingAs($developer, 'sanctum')->postJson("{$base}/end")->assertOk();

        // SDP と候補は接続が終われば意味を持たない。共有ホスティングに溜めない。
        $this->assertSame(0, CallSignal::count());
    }

    public function test_a_call_cannot_be_created_with_more_people_than_the_mesh_allows(): void
    {
        config(['webrtc.max_participants' => 3]);

        $project = Project::factory()->create();
        $developer = $this->developer();
        $clients = collect(range(1, 3))->map(fn () => $this->insider($project));

        $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/calls", [
                'user_ids' => $clients->pluck('id')->all(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids');

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_a_second_instant_call_reuses_the_one_already_ringing(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();

        $first = $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/calls")->json('data.id');

        // 発信ボタンの二度押しで部屋が乱立しないこと。
        $second = $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/calls")->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('calls', 1);
    }

    public function test_a_call_everyone_walked_away_from_is_closed(): void
    {
        $project = Project::factory()->create();
        $developer = $this->developer();

        $this->actingAs($developer, 'sanctum')->postJson("/projects/{$project->id}/calls");
        $call = Call::first();

        // タブを閉じられると leave は飛んでこない。残るのは古い last_seen_at だけ。
        $call->update(['started_at' => now()->subMinutes(5)]);
        $call->participants()->where('user_id', $developer->id)->update([
            'state' => 'joined',
            'last_seen_at' => now()->subMinutes(3),
        ]);

        Call::sweepAbandoned();

        $this->assertSame('ended', $call->fresh()->status);
    }

    public function test_the_ice_configuration_never_forces_relay_without_a_turn_server(): void
    {
        config(['webrtc.turn_url' => null, 'webrtc.force_relay' => true]);

        // TURN が無いのに relay 強制にすると誰ともつながらない。設定ミスで
        // 通話機能ごと死ぬので、ここは設定より安全側を優先する。
        $this->actingAs($this->developer(), 'sanctum')
            ->getJson('/calls/ice')
            ->assertOk()
            ->assertJsonPath('ice_transport_policy', 'all')
            ->assertJsonPath('has_turn', false);
    }
}
