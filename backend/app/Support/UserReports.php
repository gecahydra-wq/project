<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filing and reviewing User Reports (Buyer about a Seller, Seller about a
 * Buyer) -- the one place that decides which municipality a report belongs to,
 * who is allowed to see it, and what happens when its status changes.
 *
 * Resolving a report never changes an account's standing: an admin who decides
 * a complaint is founded still suspends through App\Support\AccountModeration,
 * which keeps every account action in moderation_logs where it has always
 * been. This class only moves the complaint's own status.
 */
class UserReports
{
    /**
     * File a report. The reporter's role decides the direction, and the
     * SELLER side of the pair -- whichever party that is -- decides which
     * municipality the report is scoped to, so an LGU Admin always sees the
     * complaints that concern the sellers they oversee.
     */
    public static function file(User $reporter, User $reported, string $reason, string $description, ?Order $order = null): UserReport
    {
        $sellerUser = $reporter->role === 'seller' ? $reporter : $reported;
        $municipalityId = SellerProfile::where('user_id', $sellerUser->id)->value('municipality_id')
            ?? $sellerUser->municipality_id;

        $report = UserReport::create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'reporter_role' => $reporter->role,
            'reported_role' => $reported->role,
            'municipality_id' => $municipalityId,
            'order_id' => $order?->id,
            'reason' => $reason,
            'description' => $description,
            'status' => 'pending',
        ]);

        self::notifyReviewers($report, $reporter, $reported, $municipalityId);

        ActivityLog::record([
            'actor_id' => $reporter->id,
            'actor_role' => $reporter->role,
            'action' => 'user_report_filed',
            'target_user_id' => $reported->id,
            'municipality_id' => $municipalityId,
            'reference_type' => $order?->order_number ? 'ORD' : null,
            'reference_number' => $order?->order_number,
            'description' => sprintf('%s reported %s -- %s.', ucfirst($reporter->role), $reported->name, $reason),
        ]);

        return $report;
    }

    /**
     * Move a report along and record who decided it. Closing one (resolved or
     * dismissed) also tells the reporter the outcome, so a complaint never
     * disappears silently.
     */
    public static function updateStatus(UserReport $report, User $reviewer, string $status, ?string $notes = null): UserReport
    {
        $report->update([
            'status' => $status,
            'resolution_notes' => $notes ?? $report->resolution_notes,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        if (in_array($status, UserReport::CLOSED_STATUSES, true)) {
            AppNotification::create([
                'user_id' => $report->reporter_id,
                'type' => 'user_report_'.$status,
                'title' => $status === 'resolved' ? 'Your Report Was Resolved' : 'Your Report Was Reviewed',
                'body' => sprintf(
                    'Your report (%s) has been %s by %s.%s',
                    $report->reason,
                    $status,
                    $reviewer->name,
                    $notes ? " Notes: {$notes}" : ''
                ),
            ]);
        }

        ActivityLog::record([
            'actor_id' => $reviewer->id,
            'actor_role' => $reviewer->role,
            'action' => 'user_report_'.($status === 'under_review' ? 'reviewed' : $status),
            'target_user_id' => $report->reported_user_id,
            'municipality_id' => $report->municipality_id,
            'description' => sprintf('Report #%d marked %s.%s', $report->id, str_replace('_', ' ', $status), $notes ? " {$notes}" : ''),
        ]);

        return $report->fresh(['reporter', 'reportedUser', 'municipality', 'order', 'reviewer']);
    }

    /**
     * The shared read query for both dashboards, with the relations the list
     * columns need (Reporter / Reported User / Reason / Description / Date /
     * Status). Pass a municipality to scope it to one LGU; pass null for the
     * Super Admin's platform-wide view.
     */
    public static function query(?int $municipalityId = null): Builder
    {
        return UserReport::with(['reporter', 'reportedUser', 'municipality', 'order:id,order_number', 'reviewer'])
            ->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId))
            ->latest();
    }

    /**
     * Notify whoever can act on this report: the LGU Admins of the relevant
     * municipality, and every Super Admin (who oversees all of them).
     */
    private static function notifyReviewers(UserReport $report, User $reporter, User $reported, ?int $municipalityId): void
    {
        $recipients = User::query()
            ->where(fn ($q) => $q
                ->where('role', 'super_admin')
                ->orWhere(fn ($q2) => $q2->where('role', 'lgu_admin')->where('municipality_id', $municipalityId)))
            ->get();

        foreach ($recipients as $recipient) {
            AppNotification::create([
                'user_id' => $recipient->id,
                'type' => 'user_report_filed',
                'title' => 'New User Report',
                'body' => sprintf(
                    '%s %s reported %s %s. Reason: %s.',
                    ucfirst($reporter->role),
                    $reporter->name,
                    $reported->role,
                    $reported->name,
                    $report->reason
                ),
            ]);
        }
    }
}
