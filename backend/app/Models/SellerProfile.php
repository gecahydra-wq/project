<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'municipality_id',
        'hatchery_name',
        'description',
        'farming_methods',
        'fish_raising_practices',
        'farm_history',
        'water_source',
        'feeding_practices',
        'years_experience',
        'certifications',
        'address',
        'profile_picture',
        'cover_photo',
        'gallery',
        'rating',
        'verified',
        'status',
        // Two-stage registration approval -- see App\Support\SellerApproval.
        // 'status'/'verified' above stay the account-standing columns.
        'approval_status',
        'lgu_reviewed_at',
        'lgu_reviewed_by',
        'super_admin_reviewed_at',
        'super_admin_reviewed_by',
        'registration_rejection_reason',
        'rejected_by_role',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'rating' => 'decimal:2',
        'gallery' => 'array',
        'lgu_reviewed_at' => 'datetime',
        'super_admin_reviewed_at' => 'datetime',
    ];

    /**
     * Registration review notes are internal: the public seller profile
     * (SellerProfileController::show) serializes this whole model, and a
     * reviewer's rejection reason must never appear on a public page. The
     * seller's own dashboard and the two admin review queues re-expose them
     * with makeVisible(self::REVIEW_FIELDS).
     */
    protected $hidden = ['registration_rejection_reason', 'rejected_by_role'];

    public const REVIEW_FIELDS = ['registration_rejection_reason', 'rejected_by_role'];

    /**
     * Human label for the registration approval stage ("Pending LGU",
     * "Pending Super Admin", "Approved", "Rejected"), appended to every JSON
     * response so the LGU/Super Admin/Seller dashboards all render the same
     * wording without each duplicating the mapping.
     */
    protected $appends = ['approval_status_label'];

    public function getApprovalStatusLabelAttribute(): string
    {
        return \App\Support\SellerApproval::label($this->approval_status);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function lguReviewer()
    {
        return $this->belongsTo(User::class, 'lgu_reviewed_by');
    }

    public function superAdminReviewer()
    {
        return $this->belongsTo(User::class, 'super_admin_reviewed_by');
    }

    public function listings()
    {
        return $this->hasMany(FingerlingListing::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function posts()
    {
        return $this->hasMany(SellerPost::class)->latest();
    }

    public function withdrawalRequests()
    {
        return $this->hasMany(WithdrawalRequest::class);
    }
}
