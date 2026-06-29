<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::withCount('users')
            ->withCount(['invitations as pending_invites_count' => fn($q) => $q->whereNull('accepted_at')->where('expires_at', '>', now())])
            ->get();
        return CompanyResource::collection($companies);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'slug'     => 'required|string|unique:companies,slug',
            'website'  => 'nullable|url',
            'timezone' => 'nullable|string',
        ]);
        $company = Company::create($validated);
        return new CompanyResource($company);
    }

    public function show(Company $company)
    {
        $company->load(['users', 'invitations' => fn($q) => $q->with('creator')->latest()]);
        return response()->json([
            'id'       => $company->id,
            'name'     => $company->name,
            'slug'     => $company->slug,
            'website'  => $company->website,
            'timezone' => $company->timezone,
            'users'    => $company->users->map(fn($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'is_primary' => (bool) $u->pivot->is_primary,
            ]),
            'invitations' => $company->invitations->map(fn($i) => [
                'id'          => $i->id,
                'email'       => $i->email,
                'expires_at'  => $i->expires_at,
                'accepted_at' => $i->accepted_at,
                'token'       => $i->token,
                'created_by'  => $i->creator?->name,
                'is_expired'  => $i->isExpired(),
            ]),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'slug'     => 'sometimes|string|unique:companies,slug,' . $company->id,
            'website'  => 'nullable|url',
            'timezone' => 'nullable|string',
        ]);
        $company->update($validated);
        return new CompanyResource($company);
    }

    public function addUser(Request $request, Company $company)
    {
        $data = $request->validate(['user_id' => 'required|exists:users,id']);
        $company->users()->syncWithoutDetaching([$data['user_id'] => ['is_primary' => false]]);
        return response()->json(['message' => 'User added']);
    }

    public function removeUser(Company $company, User $user)
    {
        $company->users()->detach($user->id);
        return response()->noContent();
    }
}
