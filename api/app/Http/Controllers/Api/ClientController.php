<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index()
    {
        $clients = User::where('role', 'client')
            ->with(['companies' => fn($q) => $q->withCount('projects')])
            ->withCount(['companies'])
            ->get()
            ->map(fn($u) => [
                'id'          => $u->id,
                'name'        => $u->name,
                'email'       => $u->email,
                'avatar_path' => $u->avatar_path,
                'companies'   => $u->companies->map(fn($c) => [
                    'id'             => $c->id,
                    'name'           => $c->name,
                    'projects_count' => $c->projects_count,
                    'is_primary'     => (bool) $c->pivot->is_primary,
                ]),
                'created_at'  => $u->created_at,
            ]);

        return response()->json($clients);
    }

    public function update(Request $request, User $user)
    {
        if ($user->role !== 'client') {
            return response()->json(['message' => 'Not a client'], 422);
        }

        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
        ]);

        $user->update($data);

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->role !== 'client') {
            return response()->json(['message' => 'Not a client'], 422);
        }
        $user->delete();
        return response()->noContent();
    }
}
