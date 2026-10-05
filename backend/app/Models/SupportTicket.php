<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Buyer or Seller asking for help, or giving feedback, through the contact
 * form. Written only by App\Support\SupportTickets. Shared by the
 * municipality's LGU Admins and every Super Admin -- whoever picks it up
 * answers it, by email. Not real-time support and not a chat.
 */
class SupportTicket extends Model
{
    protected $fillable = [
        'ticket_number',
        'user_id',
        'user_role',
        'first_name',
        'last_name',
        'contact_email',
        'category',
        'subject',
        'municipality_id',
        'order_id',
        'status',
        'last_activity_at',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** open (waiting on staff) -> answered (staff replied) -> resolved (shown as "Closed"). */
    public const STATUSES = ['open', 'answered', 'resolved'];

    /**
     * What a ticket is about. Every topic can be answered by both the LGU and
     * the Super Admin who can see the ticket.
     */
    public const CATEGORIES = [
        'order_delivery' => ['label' => 'Order or delivery', 'roles' => ['buyer', 'seller']],
        'listing' => ['label' => 'Listing', 'roles' => ['buyer', 'seller']],
        'seller_registration' => ['label' => 'Seller registration or verification', 'roles' => ['seller']],
        'earnings' => ['label' => 'Seller earnings review', 'roles' => ['seller']],
        'payment_refund' => ['label' => 'Payment or refund', 'roles' => ['buyer', 'seller']],
        'wallet_withdrawal' => ['label' => 'Wallet or withdrawal', 'roles' => ['seller']],
        'account' => ['label' => 'Account or login', 'roles' => ['buyer', 'seller']],
        'technical' => ['label' => 'Technical problem or bug', 'roles' => ['buyer', 'seller']],
        'feedback' => ['label' => 'Feedback or suggestion', 'roles' => ['buyer', 'seller']],
        'other' => ['label' => 'Something else', 'roles' => ['buyer', 'seller']],
    ];

    public static function categoriesFor(string $role): array
    {
        return collect(self::CATEGORIES)
            ->filter(fn ($category) => in_array($role, $category['roles'], true))
            ->map(fn ($category, $key) => ['value' => $key, 'label' => $category['label']])
            ->values()
            ->all();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
