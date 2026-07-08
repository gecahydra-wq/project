<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListingMedia extends Model
{
    use HasFactory;

    protected $fillable = ['listing_id', 'type', 'title', 'url'];
}
