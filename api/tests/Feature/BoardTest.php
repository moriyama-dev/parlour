<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ホワイトボード（「イメージ」）。
 *
 * 守りたいのは「誰のボードが誰に見えるか」だけ。中身の JSON はブラウザの持ち物で、
 * サーバーは大きさ以外を検査しない。
 */
class BoardTest extends TestCase
{
    use RefreshDatabase;

    private function insider(Project $project): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($project->company_id);

        return $client;
    }

    public function test_a_board_starts_private_to_its_creator(): void
    {
        $dev = User::factory()->developer()->create();
        $client = User::factory()->client()->create();

        $res = $this->actingAs($client)->postJson('/boards', ['title' => 'Ideas']);
        $res->assertCreated()->assertJsonPath('data.title', 'Ideas')->assertJsonPath('data.is_owner', true);
        $id = $res->json('data.id');

        $other = User::factory()->client()->create();
        $this->actingAs($other)->getJson("/boards/{$id}")->assertForbidden();
        $this->actingAs($other)->getJson('/boards')->assertOk()->assertJsonCount(0, 'data');

        // スタジオ側は全部見える。
        $this->actingAs($dev)->getJson("/boards/{$id}")->assertOk();
    }

    public function test_a_board_attached_to_a_project_is_visible_to_that_projects_client(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $insider = $this->insider($project);
        $outsider = User::factory()->client()->create();

        $board = Board::create([
            'user_id' => $dev->id,
            'project_id' => $project->id,
            'title' => 'Homepage layout',
            'content' => ['elements' => []],
        ]);

        $this->actingAs($insider)->getJson("/boards/{$board->id}")
            ->assertOk()
            ->assertJsonPath('data.content.elements', []);
        $this->actingAs($insider)->getJson('/boards')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($outsider)->getJson("/boards/{$board->id}")->assertForbidden();
    }

    public function test_only_the_owner_or_a_developer_can_delete_or_reshare(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $insider = $this->insider($project);

        $board = Board::create([
            'user_id' => $dev->id,
            'project_id' => $project->id,
            'title' => 'Shared',
        ]);

        // 顧客は共有されたボードに描き込める。
        $this->actingAs($insider)->putJson("/boards/{$board->id}", [
            'content' => ['elements' => [['id' => 'a', 'type' => 'sticky']]],
        ])->assertOk();
        $this->assertSame('a', $board->fresh()->content['elements'][0]['id']);

        // が、共有先の付け替えと削除はできない。
        $this->actingAs($insider)->putJson("/boards/{$board->id}", ['project_id' => null])->assertForbidden();
        $this->actingAs($insider)->deleteJson("/boards/{$board->id}")->assertForbidden();

        $this->actingAs($dev)->deleteJson("/boards/{$board->id}")->assertNoContent();
        $this->assertDatabaseMissing('boards', ['id' => $board->id]);
    }

    public function test_a_client_cannot_share_a_board_with_someone_elses_project(): void
    {
        $project = Project::factory()->create();
        $client = User::factory()->client()->create();

        $this->actingAs($client)->postJson('/boards', [
            'title' => 'Sneaky',
            'project_id' => $project->id,
        ])->assertForbidden();
    }

    public function test_the_list_omits_content_and_sorts_by_last_opened(): void
    {
        $dev = User::factory()->developer()->create();
        $old = Board::create(['user_id' => $dev->id, 'title' => 'Old', 'last_opened_at' => now()->subDay()]);
        $new = Board::create(['user_id' => $dev->id, 'title' => 'New', 'last_opened_at' => now()]);

        $res = $this->actingAs($dev)->getJson('/boards')->assertOk();
        $this->assertSame([$new->id, $old->id], array_column($res->json('data'), 'id'));
        $this->assertArrayNotHasKey('content', $res->json('data.0'));
    }

    public function test_duplicate_copies_the_content_to_the_caller(): void
    {
        $dev = User::factory()->developer()->create();
        $board = Board::create(['user_id' => $dev->id, 'title' => 'Orig', 'content' => ['elements' => [['id' => 'x']]]]);

        $res = $this->actingAs($dev)->postJson("/boards/{$board->id}/duplicate")->assertCreated();
        $this->assertSame('Orig (copy)', $res->json('data.title'));
        $this->assertSame('x', Board::find($res->json('data.id'))->content['elements'][0]['id']);
    }
}
