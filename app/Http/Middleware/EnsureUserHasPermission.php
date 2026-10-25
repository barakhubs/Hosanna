<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the request when the user's role has any of the given permissions.
 * Uses the same lookup as HandleInertiaRequests so the menu and the routes agree.
 */
class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $granted = $request->attributes->get('user_permissions');
        if ($granted === null) {
            $role = Role::where('name', $user->user_role)->first();
            $granted = $role ? $role->permissions->pluck('name')->all() : [];
            $request->attributes->set('user_permissions', $granted);
        }

        if (empty(array_intersect($permissions, $granted))) {
            abort(403, 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}
