<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A complaint filed by one marketplace user about another -- Buyer about a
 * Seller, or Seller about a Buyer. Written only by App\Support\UserReports;
 * read by the LGU (own municipality) and Super Admin (platform-wide)
 * dashboards.
 */
class UserReport extends Model
{
    protected $fillable = [
        'reporter_id',
        'reported_user_id',
        'reporter_role',
        'reported_role',
        'municipality_id',
        'order_id',
        'reason',
        'description',
        'status',
        'resolution_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    /** The states a report moves through, and the two that close it. */
    public const STATUSES = ['pending', 'under_review', 'resolved', 'dismissed'];

    public const CLOSED_STATUSES = ['resolved', 'dismissed'];

    /**
     * Why a Buyer reports a Seller. Enumerated so the dashboards can group and
     * filter complaints instead of reading free text.
     */
    public const BUYER_REASONS = [
        'Item not as described',
        'Poor fingerling quality or health',
        'Order never delivered',
        'Seller unresponsive',
        'Overpricing or hidden charges',
        'Inappropriate behaviour',
        'Suspected fraud',
        'Other',
    ];

    /** Why a Seller reports a Buyer. */
    public const SELLER_REASONS = [
        'Payment issue',
        'Buyer did not receive or refused delivery',
        'Fake or repeated cancelled orders',
        'Buyer unresponsive',
        'Unfair or false review',
        'Inappropriate behaviour',
        'Suspected fraud',
        'Other',
    ];

    public static function reasonsFor(string $reporterRole): array
    {
        return $reporterRole === 'seller' ? self::SELLER_REASONS : self::BUYER_REASONS;
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportedUser()
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
