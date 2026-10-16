import { roleRoutes } from './api.js'
import { sellerProfilePath } from './session.js'



/** Notification click-through for the support ticket notifications. */
function supportNotificationLink(dashboardPath) {
  return (notification) => {
    if (notification.type === 'new_message') return `${dashboardPath}?tab=messages`
    return notification.type?.startsWith('support_ticket') ? `${dashboardPath}?tab=support&view=tickets` : null
  }
}

/**
 * Which tab a notification opens, per role, by the start of its type (types
 * are often suffixed with an id, e.g. "withdrawal_requested:12"). The first
 * matching prefix wins, so more specific prefixes come first.
 */
const NOTIFICATION_TABS = {
  buyer: [
    ['order_', 'orders'], ['payment_', 'orders'], ['refund_', 'orders'],
  ],
  seller: [
    ['withdrawal_auto_completed', 'wallet'],
    ['withdrawal_', 'wallet'], ['earnings_', 'wallet'], ['dispute_resolved', 'wallet'],
    ['order_', 'orders'],
    ['seller_notice', 'notices'], ['low_rating', 'notices'], ['seller_low_rating', 'notices'], ['notice_dispute_resolved', 'notices'],
    ['listing_', 'listings'],
  ],
  lgu_admin: [
    ['earnings_pending_approval', 'earnings'],
    ['seller_registration_submitted', 'sellers'],
    ['user_report', 'user-reports'],
    ['seller_notice', 'notices'], ['seller_report_notice', 'notices'],
    ['dispute_filed', 'disputes'],
    ['lgu_withdrawal_auto_completed', 'wallet'], ['lgu_withdrawal_', 'wallet'], ['dispute_resolved', 'wallet'],
  ],
  super_admin: [
    ['lgu_withdrawal_received', 'payouts&focus=lgu-payouts'],
    ['lgu_withdrawal_requested', 'payouts&focus=lgu-payouts'],
    ['withdrawal_received', 'payouts&focus=seller-payouts'],
    ['withdrawal_requested', 'payouts&focus=seller-payouts'],
    ['refund_pending', 'refunds'],
    ['refund_request', 'refunds'],
    ['seller_registration_submitted', 'sellers'],
    ['user_report', 'user-reports'],
    ['seller_notice', 'notices'],
    ['dispute_filed', 'disputes'],
  ],
}

/** Where clicking a notification goes, per role -- the same rules each dashboard's Notifications page uses. */
export function notificationLinkFor(role) {
  const base = roleRoutes[role] || '/'
  const support = supportNotificationLink(base)
  const tabs = NOTIFICATION_TABS[role] || []
  return (notification) => {
    const supportLink = support(notification)
    if (supportLink) return supportLink
    // "review_received:<seller profile id>:<review id>" opens that review on the profile.
    const review = /^review_received:(\d+):(\d+)$/.exec(notification.type || '')
    if (review) return `${sellerProfilePath(review[1])}?review=${review[2]}`
    const match = tabs.find(([prefix]) => notification.type?.startsWith(prefix))
    return match ? `${base}?tab=${match[1]}` : null
  }
}

export function notificationHistoryType(notification) {
  const type = String(notification?.type || '').toLowerCase()
  if (type.includes('message')) return 'message'
  if (type.includes('report')) return 'report'
  if (type.includes('order') || type.includes('earnings')) return 'order'
  if (type.includes('review') || type.includes('rating')) return 'review'
  if (type.includes('listing')) return 'listing'
  if (type.includes('notice')) return 'notice'
  if (type.includes('account') || type.includes('seller_registration')) return 'account'
  if (type.includes('withdrawal') || type.includes('payout') || type.includes('refund')) return 'payout'
  if (type.includes('support_ticket') || type.includes('support')) return 'support'
  if (type.includes('announcement')) return 'announcement'
  if (type.includes('dispute')) return 'dispute'
  return 'other'
}