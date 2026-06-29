<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    // Developer: list ALL pending invitations across all companies
    public function globalIndex(Request $request)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invitations = Invitation::with('company')
            ->whereNull('accepted_at')
            ->latest()
            ->get()
            ->map(fn($i) => [
                'id'         => $i->id,
                'email'      => $i->email,
                'token'      => $i->token,
                'company'    => $i->company->only(['id', 'name']),
                'expires_at' => $i->expires_at,
                'is_expired' => $i->isExpired(),
                'created_at' => $i->created_at,
            ]);

        return response()->json($invitations);
    }

    // Developer: cancel a pending invitation
    public function destroy(Request $request, Invitation $invitation)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $invitation->delete();
        return response()->noContent();
    }

    // Developer: generate invite token for a company
    public function store(Request $request, Company $company)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate(['email' => 'nullable|email']);

        $invitation = Invitation::create([
            'company_id' => $company->id,
            'created_by' => $request->user()->id,
            'email'      => $data['email'] ?? null,
            'token'      => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'token'      => $invitation->token,
            'expires_at' => $invitation->expires_at,
        ], 201);
    }

    // Anyone: view invite info by token (no auth required)
    public function show(string $token)
    {
        $invitation = Invitation::with('company')->where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], 410);
        }
        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation has already been used.'], 409);
        }

        return response()->json([
            'company'    => $invitation->company->only(['id', 'name']),
            'email'      => $invitation->email,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    // Anyone: register as client via token
    public function accept(Request $request, string $token)
    {
        $invitation = Invitation::with('company')->where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], 410);
        }
        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation has already been used.'], 409);
        }

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => bcrypt($data['password']),
            'role'     => 'client',
        ]);

        $invitation->company->users()->attach($user->id, ['is_primary' => false]);
        $invitation->update(['accepted_at' => now()]);

        $authToken = $user->createToken('client')->plainTextToken;

        return response()->json([
            'token' => $authToken,
            'user'  => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ], 201);
    }

    // Developer: list invitations for a specific company
    public function index(Request $request, Company $company)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invitations = $company->invitations()->latest()->get()->map(fn($i) => [
            'id'          => $i->id,
            'email'       => $i->email,
            'token'       => $i->token,
            'expires_at'  => $i->expires_at,
            'accepted_at' => $i->accepted_at,
        ]);

        return response()->json($invitations);
    }
}
