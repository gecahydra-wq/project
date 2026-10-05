<?php

namespace App\Http\Middleware;

use App\Models\SellerProfile;
use Closure;
use Illuminate\Http\Request;

/**
 * A suspended seller stays signed in (to read why, send a support ticket or
 * file a dispute) but cannot sell. Registered as `active-seller` and put on
 * the selling routes only: listings, order updates, withdrawals and posts.
 */
class EnsureActiveSeller
{
    public function handle(Request $request, Closure $next)
    {
        $status = SellerProfile::where('user_id', $request->user()?->id)->value('status');

        if ($status === 'suspended') {
            return response()->json([
                'message' => 'Your seller account is suspended, so you cannot do this. Send a support ticket from Help & Support if you think this is a mistake.',
            ], 403);
        }

        return $next($request);
    }
}
