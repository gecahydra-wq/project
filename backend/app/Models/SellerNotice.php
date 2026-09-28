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

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /**
     * 'resolved' and 'dismissed' predate the accept/reject decision and are
     * kept so existing rows stay valid. New decisions use 'accepted' (the
     * explanation was satisfactory) or 'rejected' (it was not -- the only
     * status that counts as an offense, see App\Support\SellerSanctions).
     */
    public const STATUSES = ['open', 'under_review', 'resolved', 'dismissed', self::STATUS_ACCEPTED, self::STATUS_REJECTED];

    /** Statuses that still count as an active notice against the seller. */
    public const OPEN_STATUSES = ['open', 'under_review'];

    /** Statuses an LGU may set through the generic update endpoint. The
     *  accept/reject decisions have their own endpoints because they carry
     *  consequences (offense counting, freezing, suspension). */
    public const MANUAL_STATUSES = ['open', 'under_review', 'dismissed'];

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
