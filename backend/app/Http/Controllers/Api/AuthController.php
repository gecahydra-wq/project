<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuyerProfile;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'role' => ['required', Rule::in(['buyer', 'seller'])],
            'municipality_id' => [Rule::requiredIf(fn () => $request->input('role') === 'seller'), 'nullable', 'exists:municipalities,id'],
            'phone' => ['nullable', 'string'],
        ]);

        $user = User::create($data);

        if ($user->role === 'buyer') {
            BuyerProfile::create([
                'user_id' => $user->id,
                'municipality_id' => $user->municipality_id,
            ]);
        }

        if ($user->role === 'seller') {
            SellerProfile::create([
                'user_id' => $user->id,
                'municipality_id' => $user->municipality_id,
                'hatchery_name' => $user->name,
                'description' => 'New hatchery profile pending LGU verification.',
                'status' => 'pending',
            ]);
        }

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('fishmarket')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        if ($user->role === 'seller') {
            $sellerProfile = SellerProfile::where('user_id', $user->id)->first();
            if ($sellerProfile?->status === 'suspended') {
                return response()->json(['message' => 'This seller account has been suspended by the LGU. Contact your local government unit for assistance.'], 403);
            }
        }

        if ($user->role === 'lgu_admin' && $user->status === 'disabled') {
            return response()->json(['message' => 'This LGU admin account has been disabled by the Super Admin.'], 403);
        }

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('fishmarket')->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'min:8'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated.']);
    }
}
