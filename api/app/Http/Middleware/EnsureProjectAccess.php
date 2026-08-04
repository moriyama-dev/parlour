<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every route nested under /projects/{project}.
 *
 * Role alone is not enough: a client is a client *of a particular company*, and
 * must never reach another company's project by guessing an id. Developers run
 * the studio and see every project.
 */
class EnsureProjectAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if (! $project instanceof Project) {
            return $next($request);
        }

        $user = $request->user();

        if ($user?->role === 'developer') {
            return $next($request);
        }

        $isMember = $user
            ? $user->companies()->whereKey($project->company_id)->exists()
            : false;

        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
