<?php

namespace App\Observers;

use App\Models\User;
use App\Support\ActivityLog;

/**
 * Logs "User registration" to the Global Activity Log purely by observing
 * the User model -- registered in AppServiceProvider::boot(). This is
 * deliberately NOT a change to AuthController/GoogleAuthController (both are
 * off-limits for this feature); it fires for every path that creates a User
 * row (email/password registration, Google sign-up) without either
 * controller knowing this observer exists.
 *
 * Only buyer/seller are logged as "registrations" -- lgu_admin/super_admin
 * accounts are always explicitly provisioned by a Super Admin (see
 * SuperAdminController::storeLguAdmin), which already writes its own
 * 'lgu_admin_created' entry, so logging them here too would double-count.
 */
class UserActivityObserver
{
    public function created(User $user): void
    {
        if (! in_array($user->role, ['buyer', 'seller'], true)) {
            return;
        }

        ActivityLog::record([
            'actor_id' => $user->id,
            'actor_role' => $user->role,
            'action' => 'user_registered',
            'target_user_id' => $user->id,
            'municipality_id' => $user->municipality_id,
            'description' => "New {$user->role} account registered: {$user->name}.",
        ]);
    }
}
