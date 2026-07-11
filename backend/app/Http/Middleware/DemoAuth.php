<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Lightweight bearer-token auth against a plain users.api_token column, with an
 * optional role check baked in. This predates the app's move to Laravel
 * Sanctum (see the `auth:sanctum` + `role:` combination used across
 * routes/api.php) and is kept only for any legacy/demo endpoint still wired to
 * it. New routes should use Sanctum + EnsureRole instead of this.
 */
class DemoAuth
{
    /**
     * @param  string  ...$roles  Optional allowed roles; empty means any
     *                            authenticated user passes.
     * @return \Symfony\Component\HttpFoundation\Response 401 when no valid
     *         token matches a user, 403 when the user's role isn't allowed,
     *         otherwise the next response with the resolved user attached.
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $token = $request->bearerToken();
        $user = $token ? User::where('api_token', $token)->first() : null;

        if (! $user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        if ($roles && ! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'This account cannot access that role area.'], 403);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
