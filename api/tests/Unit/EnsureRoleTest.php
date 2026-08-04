<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class EnsureRoleTest extends TestCase
{
    private function handleAs(?User $user, string $requiredRole): int
    {
        if ($user) {
            Auth::setUser($user);
        }

        $response = (new EnsureRole)->handle(
            Request::create('/companies', 'GET'),
            fn () => response()->json(['ok' => true]),
            $requiredRole
        );

        return $response->getStatusCode();
    }

    public function test_it_passes_a_user_with_the_required_role(): void
    {
        $developer = new User(['role' => 'developer']);

        $this->assertSame(200, $this->handleAs($developer, 'developer'));
    }

    public function test_it_blocks_a_user_with_a_different_role(): void
    {
        $client = new User(['role' => 'client']);

        $this->assertSame(403, $this->handleAs($client, 'developer'));
    }

    public function test_it_blocks_a_guest(): void
    {
        $this->assertSame(403, $this->handleAs(null, 'developer'));
    }
}
