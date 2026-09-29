<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Municipality extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'province'];

    /**
     * The LGU Admin accounts provisioned for this municipality by the Super
     * Admin. A municipality is only "partnered" while at least one of these is
     * active -- 'disabled' is what AccountModeration::suspendLguAdmin sets, and
     * a municipality whose only admin is suspended has nobody to verify sellers
     * or approve earnings, so it must stop being advertised as covered.
     */
    public function lguAdmins()
    {
        return $this->hasMany(User::class)->where('role', 'lgu_admin');
    }

    public function activeLguAdmins()
    {
        return $this->lguAdmins()->where('status', '!=', 'disabled');
    }

    /** Sellers the LGU has actually verified -- the useful public signal. */
    public function verifiedSellers()
    {
        return $this->hasMany(SellerProfile::class)
            ->where('verified', true)
            ->where('status', '!=', 'suspended');
    }
}
