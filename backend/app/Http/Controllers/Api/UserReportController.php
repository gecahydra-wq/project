<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\UserReport;
use App\Support\UserReports;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Filing side of User Reports: a Buyer reports a Seller, a Seller reports a
 * Buyer. The review side lives on the LGU and Super Admin controllers, since
 * those are scoped differently (own municipality vs. platform-wide).
 *
 * A report may only be filed across the two directions above -- buyers cannot
 * report buyers, nobody reports an admin, and nobody reports themselves. A
 * reporter may only have one open report against the same person at a time,
 * so a dispute produces one case rather than a queue of duplicates.
 */
class UserReportController extends Controller
{
    /** The reasons the caller may choose from, for their role's direction. */
    public function reasons(Request $request)
    {
        return response()->json([
            'reasons' => UserReport::reasonsFor($request->user()->role),
        ]);
    }

    /** The caller's own filed reports, so they can follow the outcome. */
    public function mine(Request $request)
    {
        return response()->json(
            UserReport::with(['reportedUser:id,name,role', 'order:id,order_number'])
                ->where('reporter_id', $request->user()->id)
                ->latest()
                ->get()
        );
    }

    public function store(Request $request)
    {
        $reporter = $request->user();
        $expectedTargetRole = $reporter->role === 'buyer' ? 'seller' : 'buyer';

        $data = $request->validate([
            'reported_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', Rule::in(UserReport::reasonsFor($reporter->role))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        abort_if((int) $data['reported_user_id'] === $reporter->id, 422, 'You cannot report yourself.');

        $reported = User::findOrFail($data['reported_user_id']);

        abort_unless(
            $reported->role === $expectedTargetRole,
            422,
            $reporter->role === 'buyer'
                ? 'Buyers can only report sellers.'
                : 'Sellers can only report buyers.'
        );

        $duplicate = UserReport::where('reporter_id', $reporter->id)
            ->where('reported_user_id', $reported->id)
            ->whereNotIn('status', UserReport::CLOSED_STATUSES)
            ->exists();

        abort_if($duplicate, 422, 'You already have an open report against this user. It is still being reviewed.');

        // An order can only be attached when the reporter was actually part of
        // it -- otherwise a report could point at someone else's transaction.
        $order = null;
        if (! empty($data['order_id'])) {
            $order = Order::find($data['order_id']);
            if ($order && ! $this->reporterOwnsOrder($reporter, $order)) {
                $order = null;
            }
        }

        $report = UserReports::file($reporter, $reported, $data['reason'], $data['description'], $order);

        return response()->json($report->load(['reportedUser:id,name,role', 'order:id,order_number']), 201);
    }

    private function reporterOwnsOrder(User $reporter, Order $order): bool
    {
        if ($reporter->role === 'buyer') {
            return $order->buyer_id === $reporter->id;
        }

        return $order->sellerProfile?->user_id === $reporter->id;
    }
}
