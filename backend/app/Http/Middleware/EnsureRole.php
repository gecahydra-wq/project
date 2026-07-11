<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Role-based authorization gate, registered as the `role:` route middleware
 * (see bootstrap/app.php). Runs AFTER `auth:sanctum` has resolved the user, so
 * it only decides *which* authenticated roles may enter a route group -- the
 * four roles are buyer, seller, lgu_admin, and super_admin.
 *
 * Usage in routes: `->middleware('role:seller')` or
 * `->middleware('role:buyer,seller')` for multi-role groups. A user whose role
 * isn't listed gets a 403 (not a 401 -- they are authenticated, just not
 * permitted here).
 */
class EnsureRole
{
    /**
     * @param  string  ...$roles  The roles allowed through this gate.
     * @return \Symfony\Component\HttpFoundation\Response 403 JSON if the caller
     *         has no user or a role outside $roles; otherwise the next response.
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (! $request->user() || ! in_array($request->user()->role, $roles, true)) {
            return response()->json(['message' => 'You are not allowed to access this role area.'], 403);
        }

        return $next($request);
    }
}
