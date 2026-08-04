<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The developer/client split is the backbone of the portal: clients must never
 * reach the routes that manage companies and their members.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_cannot_list_companies(): void
    {
        $client = User::factory()->client()->create();

        $this->actingAs($client, 'sanctum')
            ->getJson('/companies')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');
    }

    public function test_a_client_cannot_create_a_company(): void
    {
        $client = User::factory()->client()->create();

        $this->actingAs($client, 'sanctum')
            ->postJson('/companies', ['name' => 'Acme Inc'])
            ->assertStatus(403);

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_a_developer_can_list_companies(): void
    {
        $developer = User::factory()->developer()->create();
        Company::factory()->count(2)->create();

        $this->actingAs($developer, 'sanctum')
            ->getJson('/companies')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_company_routes_require_authentication(): void
    {
        $this->getJson('/companies')->assertStatus(401);
    }
}
