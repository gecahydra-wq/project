

/* Whether the buyer can still cancel this row, and under which rule --
   mirrors OrderController::cancel, which is the authority:
     'unpaid' -- 'placed' with nothing captured, cancels with no reason;
     'paid'   -- money in escrow and the order has NOT left the farm yet, so
                 the cancel needs a reason and sends the payment to the refund
                 queue. 'confirmed' counts as still cancellable -- that is the
                 seller preparing the fish, not delivering them -- and
                 'placed' counts alongside 'paid' because a demo-mode checkout
                 marks the payment 'paid_held' a moment before the order status
                 flips.
   An 'in_transit' order is past that line: buyerCancelLocked() greys the
   button out (the API refuses it too, see buyerCancelBlockedMessage). Terminal
   states (completed, cancelled, failed) show no button at all. */
export function buyerCancelMode(row) {
  if (row.status === 'placed' && ['pending', 'checkout_created'].includes(row.payment_status)) return 'unpaid'
  if (['placed', 'paid', 'confirmed'].includes(row.status) && row.payment_status === 'paid_held') return 'paid'
  return null
}

/* Out for delivery: the fish are on their way, so cancelling is off the
   table. The button stays visible but disabled -- greying it out says the
   rule exists, where hiding it made the option look like it vanished. */
export function buyerCancelLocked(row) {
  return row.status === 'in_transit'
}

export function orderPeriodStart(period) {
  const now = new Date()
  if (period === 'today') return new Date(now.getFullYear(), now.getMonth(), now.getDate())
  if (period === 'week') {
    // Weeks start on Monday, like the analytics charts (Carbon's startOfWeek).
    const daysSinceMonday = (now.getDay() + 6) % 7
    return new Date(now.getFullYear(), now.getMonth(), now.getDate() - daysSinceMonday)
  }
  if (period === 'month') return new Date(now.getFullYear(), now.getMonth(), 1)
  if (period === 'year') return new Date(now.getFullYear(), 0, 1)
  return null
}