<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One message in a support ticket's thread, from the user or from staff. */
class SupportTicketMessage extends Model
{
    protected $fillable = ['support_ticket_id', 'user_id', 'author_role', 'body', 'attachment_url', 'edited_at', 'deleted_at'];

    protected $casts = ['edited_at' => 'datetime', 'deleted_at' => 'datetime'];

    /** Same window as user-to-user messages (MessageController). */
    public const EDIT_WINDOW_MINUTES = 15;

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
