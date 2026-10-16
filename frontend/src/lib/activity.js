import { Archive, CheckCircle, Flag, History, LifeBuoy, MapPin, Megaphone, MessageCircle, Scale, Send, ShieldAlert, ShieldCheck, ShoppingCart, Star, Store, Trash2, UserPlus, Users as UsersIcon, Wallet, XCircle } from 'lucide-react'
import { statusChartLabel } from './chartColors.js'



/**
 * Friendly presentation for each Activity Log action -- label, icon, badge
 * tone, and which category it belongs to (used to group the filter
 * dropdown). Keys must match App\Support\ActivityLog::actionTypes() on the
 * backend.
 */
export const ACTIVITY_ACTION_META = {
  // Orders
  order_placed: { label: 'Order Placed', icon: ShoppingCart, tone: 'info', category: 'orders' },
  order_paid: { label: 'Order Paid', icon: Wallet, tone: 'success', category: 'orders' },
  order_confirmed: { label: 'Order Confirmed by Seller', icon: CheckCircle, tone: 'info', category: 'orders' },
  order_out_for_delivery: { label: 'Out for Delivery', icon: Send, tone: 'info', category: 'orders' },
  order_confirmed_received: { label: 'Order Received by Buyer', icon: CheckCircle, tone: 'success', category: 'orders' },
  order_marked_received_by_admin: { label: 'Order Marked Received by Admin', icon: CheckCircle, tone: 'warning', category: 'orders' },
  order_cancelled: { label: 'Order Cancelled', icon: XCircle, tone: 'danger', category: 'orders' },
  // Payments & Payouts
  seller_earnings_approved: { label: 'Seller Earnings Approved', icon: Wallet, tone: 'success', category: 'payments' },
  seller_earnings_held: { label: 'Seller Earnings Put on Hold', icon: ShieldAlert, tone: 'warning', category: 'payments' },
  seller_earnings_hold_cleared: { label: 'Earnings Hold Cleared', icon: ShieldCheck, tone: 'info', category: 'payments' },
  seller_earnings_rejected: { label: 'Seller Earnings Rejected', icon: XCircle, tone: 'danger', category: 'payments' },
  seller_earnings_reopened: { label: 'Rejected Earnings Reopened', icon: ShieldCheck, tone: 'info', category: 'payments' },
  seller_payout_requested: { label: 'Seller Payout Requested', icon: Wallet, tone: 'warning', category: 'payments' },
  seller_payout_approved: { label: 'Seller Payout Approved', icon: Wallet, tone: 'info', category: 'payments' },
  seller_payout_rejected: { label: 'Seller Payout Rejected', icon: XCircle, tone: 'danger', category: 'payments' },
  seller_payout_reopened: { label: 'Seller Payout Reopened', icon: ShieldCheck, tone: 'info', category: 'payments' },
  seller_payout_completed: { label: 'Seller Payout Completed', icon: Wallet, tone: 'success', category: 'payments' },
  lgu_payout_requested: { label: 'Municipality Payout Requested', icon: Wallet, tone: 'warning', category: 'payments' },
  lgu_payout_approved: { label: 'Municipality Payout Approved', icon: Wallet, tone: 'info', category: 'payments' },
  lgu_payout_rejected: { label: 'Municipality Payout Rejected', icon: XCircle, tone: 'danger', category: 'payments' },
  lgu_payout_reopened: { label: 'Municipality Payout Reopened', icon: ShieldCheck, tone: 'info', category: 'payments' },
  lgu_payout_completed: { label: 'Municipality Payout Completed', icon: Wallet, tone: 'success', category: 'payments' },
  withdrawal_rejection_final: { label: 'Rejected Payout Returned to Balance', icon: Wallet, tone: 'neutral', category: 'payments' },
  order_refunded: { label: 'Order Refunded', icon: Wallet, tone: 'warning', category: 'payments' },
  // Listings & Sellers
  listing_created: { label: 'Listing Created', icon: Store, tone: 'info', category: 'listings_sellers' },
  listing_updated: { label: 'Listing Edited', icon: Store, tone: 'neutral', category: 'listings_sellers' },
  listing_deleted: { label: 'Listing Deleted', icon: Trash2, tone: 'danger', category: 'listings_sellers' },
  listing_approved: { label: 'Listing Approved', icon: CheckCircle, tone: 'success', category: 'listings_sellers' },
  listing_rejected: { label: 'Listing Rejected', icon: XCircle, tone: 'danger', category: 'listings_sellers' },
  listing_archived: { label: 'Listing Archived', icon: Archive, tone: 'neutral', category: 'listings_sellers' },
  seller_verified: { label: 'Seller Verified', icon: ShieldCheck, tone: 'success', category: 'listings_sellers' },
  seller_registration_submitted: { label: 'Seller Registration Submitted', icon: UserPlus, tone: 'warning', category: 'listings_sellers' },
  seller_registration_approved: { label: 'Seller Registration Approved', icon: ShieldCheck, tone: 'success', category: 'listings_sellers' },
  seller_registration_rejected: { label: 'Seller Registration Rejected', icon: XCircle, tone: 'danger', category: 'listings_sellers' },
  // Reports & Notices
  user_report_filed: { label: 'User Report Filed', icon: Flag, tone: 'warning', category: 'reports' },
  user_report_reviewed: { label: 'User Report Under Review', icon: Flag, tone: 'info', category: 'reports' },
  user_report_resolved: { label: 'User Report Resolved', icon: CheckCircle, tone: 'success', category: 'reports' },
  user_report_dismissed: { label: 'User Report Dismissed', icon: XCircle, tone: 'neutral', category: 'reports' },
  seller_notice_issued: { label: 'Notice to Explain Issued', icon: ShieldAlert, tone: 'danger', category: 'reports' },
  seller_notice_answered: { label: 'Seller Sent Explanation', icon: MessageCircle, tone: 'info', category: 'reports' },
  seller_notice_updated: { label: 'Notice to Explain Updated', icon: ShieldCheck, tone: 'info', category: 'reports' },
  seller_notice_accepted: { label: 'Explanation Accepted', icon: CheckCircle, tone: 'success', category: 'reports' },
  seller_notice_rejected: { label: 'Explanation Rejected', icon: XCircle, tone: 'danger', category: 'reports' },
  seller_notice_repeat_offense: { label: 'Repeat Offense — Suspended', icon: ShieldAlert, tone: 'danger', category: 'reports' },
  // Disputes
  dispute_filed: { label: 'Dispute Filed', icon: Scale, tone: 'warning', category: 'disputes' },
  dispute_accepted: { label: 'Dispute Accepted', icon: Scale, tone: 'success', category: 'disputes' },
  dispute_rejected: { label: 'Dispute Rejected', icon: Scale, tone: 'danger', category: 'disputes' },
  // Moderation
  buyer_suspended: { label: 'Buyer Suspended', icon: ShieldAlert, tone: 'danger', category: 'moderation' },
  buyer_reinstated: { label: 'Buyer Reinstated', icon: ShieldCheck, tone: 'success', category: 'moderation' },
  seller_suspended: { label: 'Seller Suspended', icon: ShieldAlert, tone: 'danger', category: 'moderation' },
  seller_reinstated: { label: 'Seller Reinstated', icon: ShieldCheck, tone: 'success', category: 'moderation' },
  lgu_admin_suspended: { label: 'LGU Admin Disabled', icon: ShieldAlert, tone: 'danger', category: 'moderation' },
  lgu_admin_reinstated: { label: 'LGU Admin Re-enabled', icon: ShieldCheck, tone: 'success', category: 'moderation' },
  buyer_removed: { label: 'Buyer Account Removed', icon: Trash2, tone: 'danger', category: 'moderation' },
  seller_removed: { label: 'Seller Account Removed', icon: Trash2, tone: 'danger', category: 'moderation' },
  // Help & Support
  support_ticket_opened: { label: 'Support Ticket Opened', icon: LifeBuoy, tone: 'warning', category: 'support' },
  support_ticket_replied: { label: 'Support Ticket Reply', icon: MessageCircle, tone: 'info', category: 'support' },
  support_ticket_note_added: { label: 'Internal Note Added', icon: LifeBuoy, tone: 'neutral', category: 'support' },
  support_ticket_resolved: { label: 'Support Ticket Closed', icon: CheckCircle, tone: 'success', category: 'support' },
  // Reviews & Ratings
  review_submitted: { label: 'Review Submitted', icon: Star, tone: 'info', category: 'reviews' },
  buyer_rating_submitted: { label: 'Buyer Rated', icon: Star, tone: 'info', category: 'reviews' },
  review_removed: { label: 'Review Removed', icon: Trash2, tone: 'danger', category: 'reviews' },
  buyer_rating_removed: { label: 'Buyer Rating Removed', icon: Trash2, tone: 'danger', category: 'reviews' },
  // Accounts
  user_registered: { label: 'New Account Registered', icon: UserPlus, tone: 'info', category: 'accounts' },
  lgu_admin_created: { label: 'LGU Admin Added', icon: UserPlus, tone: 'info', category: 'accounts' },
  lgu_admin_updated: { label: 'LGU Admin Updated', icon: UsersIcon, tone: 'info', category: 'accounts' },
  municipality_created: { label: 'Municipality Added', icon: MapPin, tone: 'info', category: 'accounts' },
  announcement_posted: { label: 'Announcement Posted', icon: Megaphone, tone: 'info', category: 'accounts' },
  announcement_updated: { label: 'Announcement Edited', icon: Megaphone, tone: 'neutral', category: 'accounts' },
  announcement_deleted: { label: 'Announcement Deleted', icon: Megaphone, tone: 'danger', category: 'accounts' },
}

export function activityActionMeta(action) {
  return ACTIVITY_ACTION_META[action] || { label: statusChartLabel(action), icon: History, tone: 'neutral', category: 'other' }
}

/**
 * Where clicking an entry should go -- e.g. any payment/payout entry sends a
 * Super Admin to Payout Management (every municipality's payouts) and an LGU
 * Admin to their own Seller Earnings Approval queue (already scoped
 * server-side to their municipality), rather than only ever showing a plain
 * text description.
 */
export function activityLogLink(scope, entry) {
  const base = scope === 'lgu' ? '/lgu/dashboard' : '/admin/dashboard'
  const action = entry.action
  const category = ACTIVITY_ACTION_META[action]?.category
  const ticket = entry.reference_type === 'SUP' && entry.reference_number ? `&ticket=${entry.reference_number}` : ''

  if (action?.startsWith('listing_')) return `${base}?tab=listings`
  if (action === 'seller_verified' || action?.startsWith('seller_registration_')) return `${base}?tab=sellers`
  if (action === 'user_registered') return `${base}?tab=users`
  if (category === 'reviews') return `${base}?tab=reviews`
  if (category === 'reports') return `${base}?tab=${action?.startsWith('user_report_') ? 'user-reports' : 'notices'}`
  if (category === 'disputes') return `${base}?tab=disputes`
  if (category === 'support') return `${base}?tab=support${ticket}`
  if (category === 'orders') return `${base}?tab=${scope === 'lgu' ? 'orders' : 'transactions'}`

  if (scope === 'super-admin') {
    if (['lgu_admin_created', 'lgu_admin_updated', 'lgu_admin_suspended', 'lgu_admin_reinstated'].includes(action)) return `${base}?tab=lgu-admins`
    if (action === 'municipality_created') return `${base}?tab=municipalities`
    if (action?.startsWith('announcement_')) return `${base}?tab=announcements`
    if (['buyer_suspended', 'buyer_reinstated', 'buyer_removed'].includes(action)) return `${base}?tab=users`
    if (['seller_suspended', 'seller_reinstated', 'seller_removed'].includes(action)) return `${base}?tab=sellers`
    if (action?.startsWith('seller_earnings_')) return `${base}?tab=earnings`
    if (category === 'payments') return `${base}?tab=payouts`
  } else {
    if (['buyer_suspended', 'buyer_reinstated', 'buyer_removed'].includes(action)) return `${base}?tab=users`
    if (['seller_suspended', 'seller_reinstated', 'seller_removed'].includes(action)) return `${base}?tab=sellers`
    // Earnings decisions are the Seller Earnings Approval queue -- already
    // scoped server-side to this LGU's own municipality.
    if (action?.startsWith('seller_earnings_') || action === 'order_refunded') return `${base}?tab=earnings`
    if (action?.startsWith('lgu_payout_')) return `${base}?tab=wallet`
    // seller_payout_* has no LGU-facing page -- Super Admin owns seller payouts.
  }

  return null
}
