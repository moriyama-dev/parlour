<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_token_and_the_user(): void
    {
        $user = User::factory()->client()->create([
            'email' => 'client@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'client@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'client');

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'client@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/auth/login', [
            'email' => 'client@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_login_validates_its_input(): void
    {
        $this->postJson('/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_the_password_is_never_returned(): void
    {
        User::factory()->create([
            'email' => 'client@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'client@example.com',
            'password' => 'password',
        ]);

        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/auth/me')->assertStatus(401);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->developer()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'developer');
    }
}
