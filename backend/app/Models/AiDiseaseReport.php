<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiDiseaseReport extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'listing_id', 'symptoms', 'image_path', 'diagnosis', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function listing()
    {
        return $this->belongsTo(FingerlingListing::class, 'listing_id');
    }
}
