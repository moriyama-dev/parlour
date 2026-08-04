<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A client is a client *of a particular company*. Checking the role alone would
 * let any client reach any other company's project by guessing an id, so these
 * tests cover the outsider: authenticated, correct role, wrong company.
 */
class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
    }

    /** A client belonging to some *other* company than the given project. */
    private function outsider(): User
    {
        $outsider = User::factory()->client()->create();
        $outsider->companies()->attach(Project::factory()->create()->company_id);

        return $outsider;
    }

    private function insider(Project $project): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($project->company_id);

        return $client;
    }

    public function test_an_outside_client_cannot_read_another_companys_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($this->outsider(), 'sanctum')
            ->getJson("/projects/{$project->id}")
            ->assertStatus(403);
    }

    public function test_an_outside_client_cannot_approve_another_companys_task(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['status' => 'pending_review']);

        $this->actingAs($this->outsider(), 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$task->id}/approve")
            ->assertStatus(403);

        $this->assertDatabaseCount('task_approvals', 0);
        $this->assertSame('pending_review', $task->fresh()->status);
    }

    public function test_an_outside_client_cannot_read_another_companys_messages_or_invoices(): void
    {
        $project = Project::factory()->create();
        $outsider = $this->outsider();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/projects/{$project->id}/messages")
            ->assertStatus(403);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/projects/{$project->id}/invoices")
            ->assertStatus(403);
    }

    public function test_a_client_of_the_company_can_read_the_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($this->insider($project), 'sanctum')
            ->getJson("/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
    }

    public function test_a_developer_can_read_any_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->developer()->create(), 'sanctum')
            ->getJson("/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
    }

    public function test_a_task_from_another_project_does_not_resolve(): void
    {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        $foreignTask = Task::factory()->for($otherProject)->create(['status' => 'pending_review']);

        // The client is legitimately inside $project, but the task id belongs
        // elsewhere: nested bindings are scoped, so it must not resolve.
        $this->actingAs($this->insider($project), 'sanctum')
            ->postJson("/projects/{$project->id}/tasks/{$foreignTask->id}/approve")
            ->assertStatus(404);

        $this->assertSame('pending_review', $foreignTask->fresh()->status);
    }

    public function test_the_client_directory_is_developer_only(): void
    {
        $client = User::factory()->client()->create();
        $victim = User::factory()->client()->create();

        $this->actingAs($client, 'sanctum')
            ->getJson('/clients')
            ->assertStatus(403);

        $this->actingAs($client, 'sanctum')
            ->putJson("/clients/{$victim->id}", ['name' => 'Hacked', 'email' => 'hacked@example.com'])
            ->assertStatus(403);

        $this->actingAs($client, 'sanctum')
            ->deleteJson("/clients/{$victim->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'email' => $victim->email]);
    }

    public function test_a_developer_can_still_use_the_client_directory(): void
    {
        User::factory()->client()->count(2)->create();

        $this->actingAs(User::factory()->developer()->create(), 'sanctum')
            ->getJson('/clients')
            ->assertOk()
            ->assertJsonCount(2);
    }
}
