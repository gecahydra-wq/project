<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class DemoAuth
{
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
