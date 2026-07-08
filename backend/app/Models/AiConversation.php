<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    use HasFactory;

    protected $table = 'ai_chats';

    protected $fillable = ['user_id', 'language', 'message', 'response'];
}
