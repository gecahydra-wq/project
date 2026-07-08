<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FingerlingListing;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function index(Request $request)
    {
        $query = FingerlingListing::query()->with(['sellerProfile', 'municipality', 'media']);

        $query->when($request->species, fn ($q, $species) => $q->where('species', $species));
        $query->when($request->municipality_id, fn ($q, $id) => $q->where('municipality_id', $id));
        $query->when($request->max_price, fn ($q, $price) => $q->where('price_per_piece', '<=', $price));
        $query->when($request->search, function ($q, $search) {
            $q->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhere('species', 'like', "%{$search}%"));
        });

        return response()->json($query->latest()->get());
    }

    public function show(FingerlingListing $listing)
    {
        return response()->json($listing->load(['sellerProfile.user', 'municipality', 'media']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'seller_profile_id' => ['required', 'exists:seller_profiles,id'],
            'municipality_id' => ['required', 'exists:municipalities,id'],
            'species' => ['required', 'string'],
            'scientific_name' => ['nullable', 'string'],
            'title' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price_per_piece' => ['required', 'numeric', 'min:0.01'],
            'average_size' => ['nullable', 'string'],
            'availability_status' => ['nullable', 'string'],
        ]);

        return response()->json(FingerlingListing::create($data), 201);
    }
}
