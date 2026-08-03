<?php

namespace App\Support;

use App\Models\ActivityLogEntry;
use App\Models\LguWithdrawalRequest;
use App\Models\ModerationLog;
use App\Models\Review;
use App\Models\Settlement;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Global Activity Log / Audit Trail. Two responsibilities:
 *
 * 1. record() -- writes one row for an event type that has no existing
 *    table of its own (listing approval/rejection, seller verification,
 *    LGU Admin creation/update, municipality creation, user registration).
 *
 * 2. query() -- the single read path, which merges those written rows with
 *    events that already live in other tables, read-only: suspend/reinstate
 *    (moderation_logs, written by the untouched App\Support\AccountModeration),
 *    seller earnings approval / revenue distribution (settlements, written by
 *    LguController::approveEarnings), and seller/LGU payout lifecycle
 *    (withdrawal_requests / lgu_withdrawal_requests). This is deliberately a
 *    read-only UNION-by-normalization rather than a second write path into
 *    any of those tables, so Orders/Payments/Wallets/Moderation code is
 *    never touched.
 */
class ActivityLog
{
    /**
     * Groups every action into one of a small set of categories so the
     * frontend can offer "show me everything about Payments" as a single
     * click instead of making an admin pick one of 20+ specific actions.
     * Single source of truth for both the category filter itself and the
     * category options endpoint -- see categoryOptions()/query().
     */
    public const CATEGORIES = [
        'accounts' => [
            'label' => 'Accounts',
            'actions' => ['user_registered', 'lgu_admin_created', 'lgu_admin_updated', 'municipality_created'],
        ],
        'listings_sellers' => [
            'label' => 'Listings & Sellers',
            'actions' => [
                'listing_approved', 'listing_rejected', 'listing_archived', 'seller_verified',
                // Seller Registration Approval -- see App\Support\SellerApproval.
                'seller_registration_approved', 'seller_registration_rejected',
            ],
        ],
        'moderation' => [
            'label' => 'Moderation',
            'actions' => [
                'buyer_suspended', 'buyer_reinstated', 'seller_suspended', 'seller_reinstated',
                'lgu_admin_suspended', 'lgu_admin_reinstated',
                // Permanent account removals. Unlike the suspend/reinstate
                // actions above (read live from moderation_logs), these are
                // written into activity_logs -- a moderation_logs row would
                // cascade-delete along with the account it documents. See
                // App\Support\AccountModeration::remove.
                'buyer_removed', 'seller_removed',
            ],
        ],
        'payments' => [
            'label' => 'Payments',
            'actions' => [
                'seller_earnings_approved', 'seller_payout_requested', 'seller_payout_approved',
                'seller_payout_completed', 'lgu_payout_requested', 'lgu_payout_approved', 'lgu_payout_completed',
            ],
        ],
        'reviews' => [
            'label' => 'Reviews & Ratings',
            'actions' => ['review_submitted', 'buyer_rating_submitted', 'review_removed', 'buyer_rating_removed'],
        ],
        // User Reports (buyer <-> seller complaints) and the automatic
        // low-rating Notices to Explain -- see App\Support\UserReports and
        // App\Support\SellerReputation.
        'reports' => [
            'label' => 'Reports & Notices',
            'actions' => [
                'user_report_filed', 'user_report_reviewed', 'user_report_resolved', 'user_report_dismissed',
                'seller_notice_issued', 'seller_notice_updated',
            ],
        ],
    ];

    public static function record(array $data): void
    {
        ActivityLogEntry::create($data);
    }

    /**
     * @param array{search?: ?string, date_from?: ?string, date_to?: ?string, user_id?: ?int, municipality_id?: ?int, category?: ?string, action?: ?string, per_page?: int, page?: int} $filters
     */
    public static function query(array $filters): array
    {
        $from = $filters['date_from'] ?? null ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $to = $filters['date_to'] ?? null ? Carbon::parse($filters['date_to'])->endOfDay() : null;
        $municipalityId = $filters['municipality_id'] ?? null;

        $rows = collect()
            ->merge(self::fromActivityLogs($municipalityId, $from, $to))
            ->merge(self::fromModerationLogs($municipalityId, $from, $to))
            ->merge(self::fromSettlements($municipalityId, $from, $to))
            ->merge(self::fromSellerWithdrawals($municipalityId, $from, $to))
            ->merge(self::fromLguWithdrawals($municipalityId, $from, $to))
            ->merge(self::fromReviews($municipalityId, $from, $to));

        // Category is the primary, easy filter (e.g. "Payments" covers every
        // payout/earnings action at once); the specific action dropdown, if
        // used, narrows further within that.
        if (! empty($filters['category']) && isset(self::CATEGORIES[$filters['category']])) {
            $allowed = self::CATEGORIES[$filters['category']]['actions'];
            $rows = $rows->filter(fn ($row) => in_array($row['action'], $allowed, true));
        }

        if (! empty($filters['action'])) {
            $rows = $rows->filter(fn ($row) => $row['action'] === $filters['action']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = strtolower($search);
            $rows = $rows->filter(function ($row) use ($needle) {
                // Also matches against the action's own words (e.g. typing
                // "payout" or "earnings" or "listing") -- not just names,
                // descriptions, and reference numbers -- so free-text search
                // finds results even without knowing the exact category.
                $actionWords = strtolower(str_replace('_', ' ', $row['action'] ?? ''));

                return str_contains(strtolower($row['administrator'] ?? ''), $needle)
                    || str_contains(strtolower($row['target_user'] ?? ''), $needle)
                    || str_contains(strtolower($row['description'] ?? ''), $needle)
                    || str_contains(strtolower($row['reference_number'] ?? ''), $needle)
                    || str_contains($actionWords, $needle);
            });
        }

        if (! empty($filters['user_id'])) {
            $rows = $rows->filter(fn ($row) => $row['actor_id'] === (int) $filters['user_id'] || $row['target_user_id'] === (int) $filters['user_id']);
        }

        $rows = $rows->sortByDesc('timestamp')->values();

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return [
            'data' => $rows->forPage($page, $perPage)->values()->all(),
            'total' => $rows->count(),
            'per_page' => $perPage,
            'current_page' => $page,
        ];
    }

    /**
     * Category options for the primary filter dropdown -- e.g.
     * [['value' => 'payments', 'label' => 'Payments'], ...].
     */
    public static function categoryOptions(): array
    {
        return collect(self::CATEGORIES)
            ->map(fn ($category, $key) => ['value' => $key, 'label' => $category['label']])
            ->values()
            ->all();
    }

    /**
     * The distinct action-type values a filter dropdown can offer -- kept in
     * one place so the frontend never has to hardcode/guess them.
     */
    public static function actionTypes(): array
    {
        return collect(self::CATEGORIES)->flatMap(fn ($category) => $category['actions'])->values()->all();
    }

    private static function fromActivityLogs(?int $municipalityId, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = ActivityLogEntry::with(['actor', 'targetUser', 'municipality'])
            ->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        return $query->get()->map(fn ($row) => [
            'id' => "log:{$row->id}",
            'timestamp' => $row->created_at,
            'administrator' => $row->actor?->name,
            'actor_id' => $row->actor_id,
            'role' => $row->actor_role,
            'action' => $row->action,
            'target_user' => $row->targetUser?->name,
            'target_user_id' => $row->target_user_id,
            'municipality' => $row->municipality?->name,
            'reference_type' => $row->reference_type,
            'reference_number' => $row->reference_number,
            'description' => $row->description,
        ]);
    }

    private static function fromModerationLogs(?int $municipalityId, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = ModerationLog::with(['user', 'moderator'])
            ->when($municipalityId, fn ($q) => $q->whereHas('user', fn ($q2) => $q2->where('municipality_id', $municipalityId)))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        return $query->get()->map(fn ($row) => [
            'id' => "moderation:{$row->id}",
            'timestamp' => $row->created_at,
            'administrator' => $row->moderator?->name,
            'actor_id' => $row->moderator_id,
            'role' => $row->role,
            'action' => "{$row->role}_{$row->action}",
            'target_user' => $row->user?->name,
            'target_user_id' => $row->user_id,
            'municipality' => $row->user?->municipality?->name,
            'reference_type' => null,
            'reference_number' => null,
            'description' => $row->reason ? "{$row->action} -- {$row->reason}" : ucfirst($row->action),
        ]);
    }

    /**
     * Buyer reviews, read-only -- the review's own table is the source of
     * truth (written by ReviewController), surfaced here as a 'review_submitted'
     * activity so the audit trail shows customer feedback alongside everything
     * else. Municipality-scoped via the reviewed seller's municipality, exactly
     * like fromSettlements/fromSellerWithdrawals, so an LGU Admin only ever
     * sees reviews for sellers in their own municipality.
     */
    private static function fromReviews(?int $municipalityId, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = Review::with(['buyer', 'sellerProfile.user', 'sellerProfile.municipality', 'order'])
            ->when($municipalityId, fn ($q) => $q->whereHas('sellerProfile', fn ($q2) => $q2->where('municipality_id', $municipalityId)))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        return $query->get()->map(fn ($row) => [
            'id' => "review:{$row->id}",
            'timestamp' => $row->created_at,
            'administrator' => $row->buyer?->name,
            'actor_id' => $row->buyer_id,
            'role' => 'buyer',
            'action' => 'review_submitted',
            'target_user' => $row->sellerProfile?->user?->name,
            'target_user_id' => $row->sellerProfile?->user_id,
            'municipality' => $row->sellerProfile?->municipality?->name,
            'reference_type' => 'ORD',
            'reference_number' => $row->order?->order_number,
            'description' => sprintf(
                '%d/5 rating for %s%s',
                (int) $row->rating,
                $row->sellerProfile?->hatchery_name ?? 'seller',
                $row->comment ? " -- \"{$row->comment}\"" : ''
            ),
        ]);
    }

    private static function fromSettlements(?int $municipalityId, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = Settlement::with(['order', 'sellerProfile.user', 'approvedBy', 'municipality'])
            ->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId))
            ->when($from, fn ($q) => $q->where('settled_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('settled_at', '<=', $to));

        // Earnings approval IS the revenue-distribution event in this system
        // (see App\Support\CommissionCalculator) -- one settlement row covers
        // both requested log categories, rather than two duplicate entries.
        return $query->get()->map(fn ($row) => [
            'id' => "settlement:{$row->id}",
            'timestamp' => $row->settled_at,
            'administrator' => $row->approvedBy?->name,
            'actor_id' => $row->approved_by,
            'role' => 'lgu_admin',
            'action' => 'seller_earnings_approved',
            'target_user' => $row->sellerProfile?->user?->name,
            'target_user_id' => $row->sellerProfile?->user_id,
            'municipality' => $row->municipality?->name,
            'reference_type' => 'ORD',
            'reference_number' => $row->order?->order_number,
            'description' => sprintf(
                'Earnings approved and distributed -- Seller ₱%s, LGU ₱%s (of ₱%s gross).',
                number_format((float) $row->seller_share, 2),
                number_format((float) $row->lgu_share, 2),
                number_format((float) $row->gross_amount, 2)
            ),
        ]);
    }

    private static function fromSellerWithdrawals(?int $municipalityId, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = WithdrawalRequest::with('sellerProfile.user')
            ->when($municipalityId, fn ($q) => $q->whereHas('sellerProfile', fn ($q2) => $q2->where('municipality_id', $municipalityId)));

        $entries = collect();
        foreach ($query->get() as $row) {
            $sellerName = $row->sellerProfile?->user?->name;
            $municipalityName = $row->sellerProfile?->municipality?->name;
            $ref = "PAY-{$row->id}";

            if (self::withinRange($row->created_at, $from, $to)) {
                $entries->push(self::sellerPayoutEntry($row, 'seller_payout_requested', $row->created_at, $sellerName, $municipalityName, $ref, "Payout of ₱{$row->amount} requested via {$row->method}."));
            }
            if ($row->reviewed_at && $row->status !== 'rejected' && self::withinRange($row->reviewed_at, $from, $to)) {
                $entries->push(self::sellerPayoutEntry($row, 'seller_payout_approved', $row->reviewed_at, $sellerName, $municipalityName, $ref, "Payout of ₱{$row->amount} approved."));
            }
            if ($row->paid_at && self::withinRange($row->paid_at, $from, $to)) {
                $entries->push(self::sellerPayoutEntry($row, 'seller_payout_completed', $row->paid_at, $sellerName, $municipalityName, $ref, "Payout of ₱{$row->amount} paid out (net ₱{$row->net_amount})."));
            }
        }

        return $entries;
    }

    private static function sellerPayoutEntry(WithdrawalRequest $row, string $action, $timestamp, ?string $sellerName, ?string $municipalityName, string $ref, string $description): array
    {
        return [
            'id' => "{$action}:{$row->id}",
            'timestamp' => $timestamp,
            'administrator' => null,
            'actor_id' => null,
            'role' => 'seller',
            'action' => $action,
            'target_user' => $sellerName,
            'target_user_id' => $row->sellerProfile?->user_id,
            'municipality' => $municipalityName,
            'reference_type' => 'PAY',
            'reference_number' => $ref,
            'description' => $description,
        ];
    }

    private static function fromLguWithdrawals(?int $municipalityId, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = LguWithdrawalRequest::with(['municipality', 'requestedBy'])
            ->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId));

        $entries = collect();
        foreach ($query->get() as $row) {
            $ref = "LGU-{$row->id}";

            if (self::withinRange($row->created_at, $from, $to)) {
                $entries->push(self::lguPayoutEntry($row, 'lgu_payout_requested', $row->created_at, $ref, "Municipality payout of ₱{$row->amount} requested via {$row->method}."));
            }
            if ($row->reviewed_at && $row->status !== 'rejected' && self::withinRange($row->reviewed_at, $from, $to)) {
                $entries->push(self::lguPayoutEntry($row, 'lgu_payout_approved', $row->reviewed_at, $ref, "Municipality payout of ₱{$row->amount} approved."));
            }
            if ($row->paid_at && self::withinRange($row->paid_at, $from, $to)) {
                $entries->push(self::lguPayoutEntry($row, 'lgu_payout_completed', $row->paid_at, $ref, "Municipality payout of ₱{$row->amount} paid out."));
            }
        }

        return $entries;
    }

    private static function lguPayoutEntry(LguWithdrawalRequest $row, string $action, $timestamp, string $ref, string $description): array
    {
        return [
            'id' => "{$action}:{$row->id}",
            'timestamp' => $timestamp,
            'administrator' => $row->requestedBy?->name,
            'actor_id' => $row->requested_by,
            'role' => 'lgu_admin',
            'action' => $action,
            'target_user' => $row->requestedBy?->name,
            'target_user_id' => $row->requested_by,
            'municipality' => $row->municipality?->name,
            'reference_type' => 'LGU',
            'reference_number' => $ref,
            'description' => $description,
        ];
    }

    private static function withinRange($timestamp, ?Carbon $from, ?Carbon $to): bool
    {
        if (! $timestamp) {
            return false;
        }
        if ($from && $timestamp->lt($from)) {
            return false;
        }
        if ($to && $timestamp->gt($to)) {
            return false;
        }

        return true;
    }
}
