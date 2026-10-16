import { unitLabelPlural } from './listing.js'



/** e.g. "500 pcs", "12 kg". */
export function formatQuantity(quantity, item) {
  return `${Number(quantity || 0).toLocaleString()} ${unitLabelPlural(item)}`
}

/**
 * Stock, in the words a buyer needs. Everything is counted in single fish, so
 * the number is the number -- but when the seller has stated a bulk size we
 * also say what that buys, because a buyer thinking in bulks should not have
 * to divide.
 */
export function formatStock(item) {
  const fish = Number(item?.quantity || 0)
  const bulk = Number(item?.pieces_per_unit || 0)
  const base = `${fish.toLocaleString()} qty`
  if (bulk <= 0) return base
  const bulks = Number(item?.available_bulks ?? Math.floor(fish / bulk))
  return `${base} (${bulks.toLocaleString()} bulk${bulks === 1 ? '' : 's'})`
}

export function currency(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

/**
 * When an order was placed, formatted the same way for every role -- Buyer,
 * Seller, LGU Admin, and Super Admin all read the same string for the same
 * order. `withTime` is off for tight table cells that render the time
 * separately.
 */
export function formatOrderDate(value, { withTime = true } = {}) {
  if (!value) return '--'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '--'
  const day = date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
  return withTime ? `${day}, ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : day
}

export function truncate(text, length = 110) {
  if (!text || text.length <= length) return text
  return `${text.slice(0, length).trimEnd()}...`
}

export function roleLabel(role) {
  return ({ buyer: 'Buyer', seller: 'Seller', lgu_admin: 'LGU Admin', super_admin: 'Super Admin' })[role]
}

/* ---------------------------------------------------------------------------
   Profile page building blocks, shared by all four roles' profile tabs:
   a header card (avatar you click to change, optional cover banner), titled
   section cards, labelled fields, and a sticky save bar that only appears
   when something actually changed.
   ------------------------------------------------------------------------- */

export function formatMonthYear(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
}

/** YYYY-MM-DD in local time, for the date inputs. */
export function localDateInput(date) {
  if (!date) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

// datetime-local inputs work in the viewer's local time with no timezone.
// The API receives ISO strings with an offset, and stored UTC values are
// converted back to local time before they are put in the inputs.
export function toLocalDateTimeInput(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export function localDateTimeInputToIso(value) {
  return value ? new Date(value).toISOString() : null
}

export const asList = (data) => (Array.isArray(data) ? data : [])

export function timeAgo(value) {
  const then = value ? new Date(value).getTime() : NaN
  if (Number.isNaN(then)) return ''
  const seconds = Math.max(0, Math.round((Date.now() - then) / 1000))
  if (seconds < 60) return 'just now'
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.round(hours / 24)
  if (days < 7) return `${days}d ago`
  return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * The shared order list, used by every role's orders section: the Buyer's
 * My Orders, the Seller's Recent Orders / Order Management, the LGU's Orders
 * and the Super Admin's All Orders. Rows are deliberately minimal -- what,
 * who, how many, when -- plus the Status badge every role needs at a glance,
 * so a completed order reads as completed (and a fresh one as placed) without
 * opening the popup. "View Order" opens the OrderDetailsModal popup, where
 * the full details and the actions live, so one design holds across all four
 * roles. The buyer's table adds one column the others do not have, Rating
 * (rendered only when onReview is passed): mid-order rows show a dash, a
 * completed unrated order offers Rate Seller, and a rated one shows the
 * stars given. Rating deliberately does not live in the popup -- a rating
 * prompt also opens when the details popup closes right after Confirm
 * Received.
 *
 * paymentView picks WHOSE view of the payment the popup's details panel
 * shows. 'escrow' (the default, used by seller / LGU / Super Admin) is the
 * raw lifecycle: paid_held -> released, i.e. when the SELLER's money is
 * verified and released. 'buyer' collapses that to what the buyer actually
 * needs -- Paid / Unpaid / Refunded -- because a buyer who has paid should not
 * sit at "Paid Held" until their LGU settles the order, which reads like
 * something is still owed. See BUYER_PAYMENT_VIEW.
 *
 * counterparty names the OTHER side of the transaction: 'seller' for a buyer
 * or admin reading the table, 'buyer' for the seller's own orders, where the
 * useful name is who bought it rather than who sold it.
 *
 * The action props onPay, onCancelOrder, onConfirmReceived, onMarkReceived
 * and onUpdateStatus are passed straight to the popup -- see OrderDetailsModal
 * for what each one renders; onReview stays in this component and drives the
 * Rating column and the rating dialog. A caller that passes none gets a
 * read-only popup, which is what the dashboards' Recent Orders previews use.
 */
/** 94.00 -> "94", 2.5 -> "2.5". */
export function percentLabel(value) {
  return String(Math.round(Number(value || 0) * 100) / 100)
}

export function periodDateValue(value) {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
    ? new Date(`${value}T00:00:00`)
    : new Date(value)
}

/**
 * Date + time for one message, shown on every bubble for both sender and
 * receiver. Today's messages read "Today, 2:34 PM" and this year's drop the
 * year, so a long thread stays scannable while still always carrying a date.
 */
export function formatMessageTimestamp(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  const today = new Date()
  const isSameDay = (a, b) => a.toDateString() === b.toDateString()
  const yesterday = new Date(today)
  yesterday.setDate(today.getDate() - 1)

  if (isSameDay(date, today)) return `Today, ${time}`
  if (isSameDay(date, yesterday)) return `Yesterday, ${time}`

  const day = date.toLocaleDateString(undefined, {
    year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    month: 'short',
    day: 'numeric',
  })
  return `${day}, ${time}`
}