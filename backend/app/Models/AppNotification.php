<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    protected $fillable = ['user_id', 'type', 'title', 'body', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
