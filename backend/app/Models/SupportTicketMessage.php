<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry on a support ticket: the user's original query, a staff response
 * (emailed to the user), or a staff-only internal note (is_internal).
 */
class SupportTicketMessage extends Model
{
    protected $fillable = ['support_ticket_id', 'user_id', 'author_role', 'body', 'is_internal', 'attachment_url'];

    protected $casts = ['is_internal' => 'boolean', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
