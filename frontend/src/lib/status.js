

const BADGE_TONES = {
  approved: 'success',
  verified: 'success',
  completed: 'success',
  released: 'success',
  paid: 'success',
  active: 'success',
  confirmed: 'info',
  in_transit: 'info',
  paid_held: 'info',
  checkout_created: 'info',
  placed: 'warning',
  pending: 'warning',
  on_hold: 'warning',
  refund_pending: 'warning',
  // Green, not grey: a completed refund is a resolved good outcome for the
  // buyer, the same class of event as 'released' or 'completed'. Grey read as
  // "nothing happened here", which is the opposite of what it means.
  refunded: 'success',
  rejected: 'danger',
  rejected_final: 'danger',
  cancelled: 'danger',
  failed: 'danger',
  suspended: 'danger',
  disabled: 'danger',
}

/**
 * A rejected withdrawal first holds its money while it can still be disputed
 * ('rejected'), then becomes final and releases it ('rejected_final') -- see
 * App\Support\WithdrawalRejection.
 */
const WITHDRAWAL_STATUS_LABELS = {
  sent: 'Payout Sent',
  rejected: 'Rejected · On hold',
  rejected_final: 'Rejected',
}

export function withdrawalStatusLabel(status) {
  return WITHDRAWAL_STATUS_LABELS[status] || status
}

export function badgeTone(status) {
  return BADGE_TONES[String(status || '').toLowerCase().replace(/\s+/g, '_')] || 'neutral'
}

/**
 * Display labels for status values whose stored name doesn't read the way it
 * should on screen. The stored value is never touched -- orders.status stays
 * 'in_transit' -- so this is purely what humans see, and only statuses listed
 * here differ from the default Title Case of their raw value (see
 * statusChartLabel, the single place this is applied).
 *
 * 'in_transit' reads "Out for Delivery" to match what the backend has always
 * called that stage on the order timeline and delivery status (see
 * App\Support\OrderTimeline and App\Support\OrderTransactionPresenter).
 */
export const STATUS_LABELS = {
  in_transit: 'Out for Delivery',
  refund_pending: 'Refund Pending',
}

/**
 * What the BUYER is told about payment, as [label, tone].
 *
 * The stored payment status tracks the ESCROW lifecycle: money captured
 * ('paid_held'), then released to the seller once their LGU approves the
 * earnings ('released'). That is the seller's concern. A buyer who has paid
 * should read "Paid" and keep reading "Paid" -- showing them "Paid Held" until
 * their LGU settles the order reads like they still owe something. The seller
 * and Super Admin keep the raw escrow status (see OrderTable's paymentView).
 */
export const BUYER_PAYMENT_VIEW = {
  pending: ['Unpaid', 'warning'],
  checkout_created: ['Unpaid', 'warning'],
  paid_held: ['Paid', 'success'],
  released: ['Paid', 'success'],
  refund_pending: ['Refund Pending', 'warning'],
  refunded: ['Refunded', 'success'],
  failed: ['Not Paid', 'danger'],
  cancelled: ['Not Paid', 'danger'],
}

/* 'placed' is the UNPAID state -- an order only becomes 'paid' once money is
   in escrow (OrderController::markOrderPaid). Confirming from here used to be
   offered and would strand the order: it leaves 'placed', so the buyer can no
   longer check out and orders:expire-unpaid no longer sees it, and the order
   runs to 'completed' with nothing captured. Cancelling is the only move a
   seller has until the buyer pays; the API enforces the same rule. */
export const ORDER_STATUS_TRANSITIONS = {
  placed: [['cancelled', 'Cancel Order']],
  paid: [['confirmed', 'Confirm Order'], ['cancelled', 'Cancel Order']],
  confirmed: [['in_transit', 'Mark Out for Delivery'], ['cancelled', 'Cancel Order']],
  /* No 'Mark Completed' here on purpose. Completing an order is what releases
     its payment into the LGU earnings queue, so a seller who could mark their
     own delivery complete could start their own payout without the buyer ever
     confirming the fingerlings arrived. Only the buyer confirms receipt (see
     OrderController::confirmReceived), with an LGU/Super Admin backstop for a
     buyer who goes silent. The API rejects 'completed' from this endpoint
     outright, so offering it here would only produce a validation error. */
  /* The last stop: a delivery can fail or be refused, so the seller keeps a
     way out even after the fish leave the farm -- there is no other move
     while the order is on the road. Cancelling here releases the order's
     reserved stock back to the listing and refunds the buyer, exactly like
     any other cancellation; the API and App\Support\OrderCancellation handle
     both. Otherwise the order ends with the buyer confirming receipt (or the
     LGU/Super Admin backstop). */
  in_transit: [['cancelled', 'Cancel Order']],
}

/**
 * The LGU / Super Admin Orders list, with the backstop for a silent buyer:
 * "Mark as Received" completes a paid order that is Out for Delivery on the
 * buyer's behalf (LguController / SuperAdminController::markOrderDelivered),
 * which puts it in the Seller Earnings queue. It does not release any money
 * itself -- earnings approval is still a separate step.
 */
/**
 * Date filter for history lists -- see usePeriodFilter below.
 */
export const ORDER_PERIODS = [
  ['all', 'All'],
  ['today', 'Today'],
  ['week', 'This Week'],
  ['month', 'This Month'],
  ['year', 'This Year'],
  ['custom', 'Custom range'],
]

export const PERIOD_OPTIONS = [
  ['daily', 'Daily'],
  ['weekly', 'Weekly'],
  ['monthly', 'Monthly'],
  ['yearly', 'Yearly'],
]

export function periodLabel(period) {
  return PERIOD_OPTIONS.find(([value]) => value === period)?.[1] || period
}