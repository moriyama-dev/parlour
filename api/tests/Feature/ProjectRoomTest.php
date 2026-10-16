<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 案件（＝顧客との「部屋」）まわり。
 *
 *   1. 顧客も部屋を立てられるが、自分の会社の分だけ。
 *   2. 一覧は進捗グラフ用の集計を持って返る（案件ごとにタスクを読み直さない）。
 *   3. ボードは案件で絞り込める。
 */
class ProjectRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_open_a_room_for_their_own_company_only(): void
    {
        $mine = Project::factory()->create();
        $theirs = Project::factory()->create();

        $client = User::factory()->client()->create();
        $client->companies()->attach($mine->company_id);

        $this->actingAs($client)->postJson('/projects', [
            'company_id' => $mine->company_id,
            'title' => 'サイトリニューアル',
        ])->assertCreated()->assertJsonPath('data.title', 'サイトリニューアル');

        $this->actingAs($client)->postJson('/projects', [
            'company_id' => $theirs->company_id,
            'title' => 'よその案件',
        ])->assertForbidden();

        $this->assertDatabaseMissing('projects', ['title' => 'よその案件']);
    }

    public function test_the_project_list_carries_task_counts_for_the_progress_chart(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();

        foreach (['approved', 'approved', 'deployed', 'pending_review', 'rejected'] as $status) {
            Task::factory()->create(['project_id' => $project->id, 'created_by' => $dev->id, 'status' => $status]);
        }

        $stats = $this->actingAs($dev)->getJson('/projects')->assertOk()->json('data.0.task_stats');

        $this->assertSame(5, $stats['total']);
        $this->assertSame(2, $stats['approved']);
        $this->assertSame(1, $stats['deployed']);
        $this->assertSame(1, $stats['pending_review']);
        $this->assertSame(1, $stats['rejected']);
        $this->assertSame(0, $stats['draft']);

        $this->actingAs($dev)->getJson("/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.task_stats.total', 5);
    }

    public function test_boards_can_be_filtered_to_one_project(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();

        Board::create(['user_id' => $dev->id, 'project_id' => $project->id, 'title' => 'リンク済み']);
        Board::create(['user_id' => $dev->id, 'title' => '個人用']);

        $res = $this->actingAs($dev)->getJson("/boards?project_id={$project->id}")->assertOk();
        $this->assertSame(['リンク済み'], array_column($res->json('data'), 'title'));

        $this->actingAs($dev)->getJson('/boards')->assertOk()->assertJsonCount(2, 'data');
    }
}
