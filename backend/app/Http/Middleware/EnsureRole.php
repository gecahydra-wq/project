<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (! $request->user() || ! in_array($request->user()->role, $roles, true)) {
            return response()->json(['message' => 'You are not allowed to access this role area.'], 403);
        }

        return $next($request);
    }
}
