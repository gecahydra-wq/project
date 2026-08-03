<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Notice to Explain issued to a seller -- currently only for a low average
 * rating. Raised automatically by App\Support\SellerReputation; answered by
 * the seller and closed by their LGU Admin. Never affects account standing on
 * its own (see the seller_notices migration).
 */
class SellerNotice extends Model
{
    protected $fillable = [
        'seller_profile_id',
        'municipality_id',
        'type',
        'average_rating',
        'ratings_count',
        'details',
        'status',
        'seller_response',
        'responded_at',
        'lgu_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'average_rating' => 'decimal:2',
        'ratings_count' => 'integer',
        'responded_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public const STATUSES = ['open', 'under_review', 'resolved', 'dismissed'];

    /** Statuses that still count as an active notice against the seller. */
    public const OPEN_STATUSES = ['open', 'under_review'];

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
