<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The approval log is the product: it is what a client and a developer point at
 * months later when they disagree about what was signed off. These tests pin
 * down that it is append-only and that only the client side can write to it.
 */
class TaskApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Approvals broadcast over Reverb; the socket is not under test here.
        Event::fake();
    }

    /**
     * A client of the company that owns the project — the only person allowed
     * to sign work off. See ProjectAccessTest for the outsider case.
     */
    private function clientOf(Project $project): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($project->company_id);

        return $client;
    }

    public function test_a_client_can_approve_a_task(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/approve", [
                'comment' => 'Looks good, ship it.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $task->fresh()->status);

        $this->assertDatabaseHas('task_approvals', [
            'task_id' => $task->id,
            'user_id' => $client->id,
            'action' => 'approved',
            'comment' => 'Looks good, ship it.',
        ]);
    }

    public function test_a_client_can_reject_a_task_with_a_reason(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/reject", [
                'comment' => 'The header colour is wrong.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame('rejected', $task->fresh()->status);
        $this->assertDatabaseHas('task_approvals', [
            'task_id' => $task->id,
            'action' => 'rejected',
            'comment' => 'The header colour is wrong.',
        ]);
    }

    public function test_rejecting_requires_a_comment(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);

        $this->assertDatabaseCount('task_approvals', 0);
        $this->assertSame('pending_review', $task->fresh()->status);
    }

    public function test_a_developer_cannot_approve_their_own_work(): void
    {
        $developer = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($developer, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/approve")
            ->assertStatus(403);

        $this->assertDatabaseCount('task_approvals', 0);
        $this->assertSame('pending_review', $task->fresh()->status);
    }

    public function test_approval_history_is_append_only(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/reject", [
                'comment' => 'Not yet.',
            ])->assertOk();

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/approve", [
                'comment' => 'Fixed — approved.',
            ])->assertOk();

        // The later decision must not overwrite or erase the earlier one.
        $approvals = TaskApproval::where('task_id', $task->id)->orderBy('id')->get();

        $this->assertCount(2, $approvals);
        $this->assertSame('rejected', $approvals[0]->action);
        $this->assertSame('Not yet.', $approvals[0]->comment);
        $this->assertSame('approved', $approvals[1]->action);
        $this->assertSame('approved', $task->fresh()->status);
    }

    public function test_the_approval_log_is_readable_through_the_api(): void
    {
        $project = Project::factory()->create();
        $client = $this->clientOf($project);
        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($client, 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/approve")
            ->assertOk();

        $this->actingAs($client, 'sanctum')
            ->getJson("/projects/{$project->id}/tasks/{$task->id}/approvals")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_approving_requires_authentication(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();

        $this->postJson("/projects/{$project->id}/tasks/{$task->id}/approve")
            ->assertStatus(401);
    }
}
