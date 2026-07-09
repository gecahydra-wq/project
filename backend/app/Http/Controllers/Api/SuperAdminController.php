<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    public function dashboard()
    {
        return response()->json([
            'lgu_admins' => User::where('role', 'lgu_admin')->count(),
            'total_sellers' => SellerProfile::count(),
            'transactions' => Order::with(['listing', 'payment', 'sellerProfile.user'])->latest()->get(),
        ]);
    }

    public function withdrawals()
    {
        return response()->json(
            WithdrawalRequest::with('sellerProfile.user')->latest()->get()
        );
    }

    public function approveWithdrawal(WithdrawalRequest $withdrawal)
    {
        abort_unless($withdrawal->status === 'pending', 422, 'Only pending withdrawal requests can be approved.');

        $withdrawal->update(['status' => 'approved', 'reviewed_at' => Carbon::now()]);
        $withdrawal->load('sellerProfile');

        AppNotification::firstOrCreate([
            'user_id' => $withdrawal->sellerProfile->user_id,
            'type' => 'withdrawal_approved',
            'title' => 'Withdrawal Approved',
            'body' => sprintf(
                'Your withdrawal request of ₱%s via %s has been approved and is being processed.',
                number_format((float) $withdrawal->amount, 2),
                $withdrawal->method
            ),
        ]);

        return response()->json($withdrawal->fresh());
    }

    public function rejectWithdrawal(Request $request, WithdrawalRequest $withdrawal)
    {
        abort_if(in_array($withdrawal->status, ['paid', 'rejected'], true), 422, 'This withdrawal request has already been finalized.');

        $data = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $withdrawal->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
            'reviewed_at' => Carbon::now(),
        ]);
        $withdrawal->load('sellerProfile');

        AppNotification::firstOrCreate([
            'user_id' => $withdrawal->sellerProfile->user_id,
            'type' => 'withdrawal_rejected',
            'title' => 'Withdrawal Rejected',
            'body' => sprintf(
                'Your withdrawal request of ₱%s via %s was rejected. Reason: %s',
                number_format((float) $withdrawal->amount, 2),
                $withdrawal->method,
                $data['reason']
            ),
        ]);

        return response()->json($withdrawal->fresh());
    }

    public function markWithdrawalPaid(WithdrawalRequest $withdrawal)
    {
        abort_unless($withdrawal->status === 'approved', 422, 'Only approved withdrawal requests can be marked as paid.');

        $paidAt = Carbon::now();
        $withdrawal->update(['status' => 'paid', 'paid_at' => $paidAt, 'reviewed_at' => $paidAt]);
        $withdrawal->load('sellerProfile');

        AppNotification::firstOrCreate([
            'user_id' => $withdrawal->sellerProfile->user_id,
            'type' => 'withdrawal_paid',
            'title' => 'Withdrawal Completed',
            'body' => sprintf(
                'Your withdrawal request of ₱%s via %s has been paid out on %s.',
                number_format((float) $withdrawal->amount, 2),
                $withdrawal->method,
                $paidAt->format('M d, Y')
            ),
        ]);

        return response()->json($withdrawal->fresh());
    }

    public function storeLguAdmin(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'municipality_id' => ['required', 'exists:municipalities,id'],
            'phone' => ['nullable', 'string'],
        ]);

        $admin = User::create([
            ...$data,
            'role' => 'lgu_admin',
        ]);

        return response()->json($admin, 201);
    }

    public function updateLguAdmin(Request $request, User $admin)
    {
        abort_unless($admin->role === 'lgu_admin', 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($admin->id)],
            'municipality_id' => ['sometimes', 'exists:municipalities,id'],
            'phone' => ['nullable', 'string'],
        ]);

        $admin->update($data);

        return response()->json($admin);
    }

    public function disableLguAdmin(User $admin)
    {
        abort_unless($admin->role === 'lgu_admin', 404);

        $admin->update(['status' => 'disabled']);
        $admin->tokens()->delete();

        return response()->json($admin);
    }

    public function enableLguAdmin(User $admin)
    {
        abort_unless($admin->role === 'lgu_admin', 404);

        $admin->update(['status' => 'active']);

        return response()->json($admin);
    }
}
