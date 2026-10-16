import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Clock, Timer } from 'lucide-react'
import { useEffect, useState } from 'react'
import { renderStars } from './stars.jsx'
import { EmptyState, LoadingState } from './states.jsx'
import { Avatar, Badge, Modal, PeriodFilteredRows, SellerLink } from './ui.jsx'
import { DisputeAction } from './withdrawals.jsx'
import { api } from '../lib/api.js'
import { statusChartLabel } from '../lib/chartColors.js'
import { currency, formatOrderDate, formatQuantity, percentLabel } from '../lib/format.js'
import { buyerCancelLocked, buyerCancelMode } from '../lib/orders.js'
import { getSession } from '../lib/session.js'
import { BUYER_PAYMENT_VIEW, ORDER_STATUS_TRANSITIONS } from '../lib/status.js'



// One row of the Super Admin refund queue (see App\Support\OrderCancellation).
export function RefundRow({ refund, onMarkRefunded }) {
  const [reference, setReference] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const pending = refund.status === 'refund_pending'

  const submit = async () => {
    setSaving(true)
    setError('')
    try {
      await onMarkRefunded(refund.id, reference.trim() || null)
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not mark as refunded.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="card action">
      <div>
        <strong>{refund.order_number}</strong>
        <p>{refund.buyer?.name || 'Buyer'} · <SellerLink id={refund.seller_profile_id} name={refund.hatchery_name} fallback="Seller" /> · {currency(refund.amount)}</p>
        {refund.reason && <p className="muted">{refund.reason}</p>}
        {refund.provider_reference && <p className="muted">PayMongo checkout: {refund.provider_reference}</p>}
        {refund.refund_reference && <p className="muted">Refund reference: {refund.refund_reference}</p>}
        <Badge status={refund.status}>{statusChartLabel(refund.status)}</Badge>
        {error && <p className="error">{error}</p>}
      </div>
      {pending && (
        <div className="row-actions">
          <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Refund reference (optional)" />
          <button type="button" disabled={saving} onClick={submit}>{saving ? 'Saving...' : 'Mark Refunded'}</button>
        </div>
      )}
    </div>
  )
}

/**
 * Order Details expander for one OrderTable row -- lazy-fetches the shared
 * Order Details payload (see App\Support\OrderTransactionPresenter) only
 * once expanded, and renders it with OrderDetailPanel/OrderTimelineView
 * exactly like every other role's lookup view.
 */
/**
 * The order popup every role opens from an orders table -- "View Order" lists
 * the rows, and this is where the order is read and acted on. One component
 * for all four roles: the action props the caller passes decide the footer
 * (Pay Now / Confirm Received / Cancel Order for the buyer, the status
 * transitions for the seller, Mark as Received for the LGU and Super Admin),
 * and the detail payload is the same OrderTransactionPresenter shape
 * everywhere, so only the actions differ between roles. Rating deliberately
 * does NOT happen in here: the buyer rates from the table's Rating column,
 * or from the prompt that opens once a Confirm Received popup closes (see
 * OrderTable and RateSellerModal).
 *
 * The Cancel Order dialog is a SIBLING of this dialog, never nested inside
 * it: .modal-panel animates with `transform: scale(1)` that persists, and a
 * transformed ancestor becomes the containing block for position: fixed
 * children -- a nested overlay would anchor to the panel instead of the
 * viewport. Modal itself keeps Escape on the topmost dialog and body scroll
 * locked while any dialog is open.
 */
function OrderDetailsModal({
  row, detailsEndpoint, paymentView = 'escrow', counterparty = 'seller',
  onConfirmReceived, confirmPendingOrderId,
  onPay, payPendingOrderId,
  onCancelOrder, cancelPendingOrderId,
  onMarkReceived, markReceivedPendingOrderId,
  onUpdateStatus, lguContact,
  onClose,
}) {
  const order = row.raw || {}
  const sellerTransitions = onUpdateStatus ? (ORDER_STATUS_TRANSITIONS[order.status] || []) : []
  const canCancelAsSeller = sellerTransitions.some(([status]) => status === 'cancelled')
  const buyerMode = onCancelOrder ? buyerCancelMode(row) : null
  const buyerCancelGreyed = Boolean(onCancelOrder) && !buyerMode && buyerCancelLocked(row)

  const [cancelOpen, setCancelOpen] = useState(false)
  const [cancelReason, setCancelReason] = useState('')
  const [cancelError, setCancelError] = useState('')
  const [savingStatus, setSavingStatus] = useState(null)
  const [statusError, setStatusError] = useState('')

  const details = useQuery({
    queryKey: ['order-detail', detailsEndpoint, row.order_number],
    queryFn: async () => (await api.get(detailsEndpoint(row.order_number))).data,
    enabled: Boolean(row.order_number && detailsEndpoint),
  })

  // A seller's forward transition runs straight away; cancelling needs a
  // reason (the API requires one too), so it opens the shared Cancel dialog
  // to collect it.
  const applyStatus = async (status) => {
    setSavingStatus(status)
    setStatusError('')
    try {
      await onUpdateStatus(order.id, status)
    } catch (err) {
      setStatusError(err?.response?.data?.message || 'Could not update order.')
    } finally {
      setSavingStatus(null)
    }
  }

  const submitCancel = async () => {
    setCancelError('')
    try {
      if (onUpdateStatus) {
        await onUpdateStatus(order.id, 'cancelled', cancelReason.trim())
      } else {
        // Only a paid buyer cancellation carries a reason; unpaid needs none.
        await onCancelOrder(row.orderId, buyerMode === 'paid' ? cancelReason.trim() : null)
      }
      setCancelOpen(false)
      setCancelReason('')
    } catch (err) {
      setCancelError(err?.response?.data?.message || 'Could not cancel this order.')
    }
  }

  // The buyer's Confirm Received and the LGU/Super Admin's Mark as Received
  // both act on the same state: payment captured and the order out for
  // delivery. Confirm releases the payment into the seller's LGU earnings
  // queue; Mark as Received is the backstop for a buyer who goes silent (see
  // OrderController::confirmReceived / markOrderDelivered).
  const outForDelivery = row.status === 'in_transit' && ['paid_held', 'released'].includes(row.payment_status)
  const awaitingReceipt = Boolean(onConfirmReceived) && outForDelivery
  const canMarkReceived = Boolean(onMarkReceived) && outForDelivery

  const footer = (
    <>
      <button type="button" className="ghost" onClick={onClose}>Close</button>
      {canMarkReceived && (
        <button
          type="button"
          onClick={() => onMarkReceived(row)}
          disabled={markReceivedPendingOrderId === row.orderId}
          title="The buyer has not confirmed this delivery. Marking it received sends it to Seller Earnings for approval."
        >
          {markReceivedPendingOrderId === row.orderId ? 'Marking...' : 'Mark as Received'}
        </button>
      )}
      {(buyerMode || canCancelAsSeller) && (
        <button
          type="button"
          className="ghost danger"
          onClick={() => setCancelOpen(true)}
          disabled={cancelPendingOrderId === row.orderId}
          title={onUpdateStatus
            ? 'Cancelling returns the reserved stock to your listing and refunds the buyer if they already paid.'
            : buyerMode === 'paid'
              ? 'You can cancel until the order is out for delivery. Your payment is then refunded.'
              : 'You have not paid yet -- cancelling returns the stock immediately.'}
        >
          Cancel Order
        </button>
      )}
      {buyerCancelGreyed && (
        <button
          type="button"
          className="ghost danger"
          disabled
          title="This order is already out for delivery, so it can no longer be cancelled. Message the seller if you need help with the delivery."
        >
          Cancel Order
        </button>
      )}
      {sellerTransitions.filter(([status]) => status !== 'cancelled').map(([status, label]) => (
        <button key={status} type="button" onClick={() => applyStatus(status)} disabled={savingStatus !== null}>
          {label}
        </button>
      ))}
      {awaitingReceipt && (
        <button
          type="button"
          onClick={() => onConfirmReceived(row)}
          disabled={confirmPendingOrderId === row.orderId}
          title="Confirm you received the fingerlings. This releases the payment to your seller's LGU for approval."
        >
          {confirmPendingOrderId === row.orderId ? 'Confirming...' : 'Confirm Received'}
        </button>
      )}
      {onPay && <PayNowAction row={row} pending={payPendingOrderId === row.orderId} onPay={onPay} />}
    </>
  )

  return (
    <>
      <Modal title={`Order #${row.order_number}`} className="order-details-modal" onClose={onClose} footer={footer}>
        <div className="review-modal-product">
          <Avatar
            src={counterparty === 'buyer' ? row.buyer_avatar : row.seller_avatar}
            alt={counterparty === 'buyer' ? row.buyer_name : row.seller_name}
            className="listing-seller-avatar"
          />
          <div>
            <strong>{row.order_name}</strong>
            <p className="muted">{row.quantity_label} · {counterparty === 'buyer' ? `Buyer: ${row.buyer_name}` : `Seller: ${row.seller_name}`}</p>
          </div>
        </div>

        {/* The seller's waiting / rejection notices, moved here from the old
            order cards. A rejected review leaves the payment in 'paid_held'
            too, so without excluding it the notice would claim to be waiting
            on an approval that had already been refused. */}
        {onUpdateStatus && order.payment?.status === 'paid_held' && order.status === 'completed' && order.lgu_review_status !== 'rejected' && (
          <p className="muted">
            {order.auto_received_at
              ? 'The system automatically marked this order as received because the buyer did not click Order Received within the confirmation window. Waiting for your LGU to approve the earnings before the payment is released.'
              : 'The buyer confirmed delivery. Waiting for your LGU to approve the earnings before the payment is released.'}
          </p>
        )}
        {onUpdateStatus && order.status === 'placed' && (
          <p className="muted">Waiting for the buyer's payment. You can confirm this order once the payment is held in escrow.</p>
        )}
        {onUpdateStatus && order.lgu_review_status === 'rejected' && (
          <div className="dispute-notice">
            <p className="error">
              Your LGU declined to approve the earnings for this order.
              {order.lgu_review_reason ? ` Reason: ${order.lgu_review_reason}` : ''}
            </p>
            {/* One in-app dispute is allowed for a rejected earnings review. If
                it is still open, show the waiting state; once it has been
                decided, the next step is outside this flow: message the LGU or
                send a support ticket. */}
            {order.latestDispute?.status === 'open' ? (
              <span className="dispute-submitted">
                <Clock size={15} /> Dispute submitted -- waiting for your LGU's response.
              </span>
            ) : order.latestDispute ? (
              <p className="helper-text dispute-outcome">
                {order.latestDispute.status === 'accepted'
                  ? 'Your previous dispute was accepted, but this earnings review was rejected again.'
                  : 'Your previous dispute was reviewed and declined.'}
                {order.latestDispute.resolution_note ? ` Note: ${order.latestDispute.resolution_note}` : ''}
                {' '}If you want it reviewed again, message{' '}
                {lguContact
                  ? <Link to={`/seller/dashboard?tab=messages&with=${lguContact.id}`}>{lguContact.municipality ? `${lguContact.municipality} LGU` : 'your LGU'}</Link>
                  : 'your LGU'}
                {' '}or send a support ticket from{' '}
                <Link to="/seller/dashboard?tab=support">Help &amp; Support</Link>.
              </p>
            ) : (
              <DisputeAction
                endpoint={`/orders/${order.id}/dispute-earnings`}
                invalidateKeys={['seller-dashboard']}
                label="Explain My Side"
              />
            )}
          </div>
        )}

        {statusError && <p className="error">{statusError}</p>}

        {details.isLoading && <LoadingState label="Loading order details..." />}
        {details.isError && <p className="error">Could not load order details.</p>}
        {details.data && <OrderDetailPanel detail={details.data} paymentView={paymentView} />}
      </Modal>
      {cancelOpen && (
        <CancelOrderModal
          row={row}
          mode={onUpdateStatus ? (order.payment?.status === 'paid_held' ? 'paid' : 'unpaid') : buyerMode}
          audience={onUpdateStatus ? 'seller' : 'buyer'}
          reason={cancelReason}
          error={cancelError}
          submitting={cancelPendingOrderId === row.orderId}
          onReasonChange={setCancelReason}
          onSubmit={submitCancel}
          onClose={() => { setCancelOpen(false); setCancelError('') }}
        />
      )}
    </>
  )
}

/**
 * Why the seller receives less than the order total: order total, minus the
 * platform fee, minus the LGU share, equals what the seller gets. Fed by
 * OrderTransactionPresenter::earningsBreakdown on the backend -- the same
 * numbers Order Details, the seller's Payment History and the earnings email
 * show. `source: 'preview'` means the LGU has not approved the order yet.
 */
function EarningsBreakdown({ breakdown, viewerIsSeller = false }) {
  if (!breakdown) return null
  const platformShare = Number(breakdown.platform_share || 0)
  return (
    <div className="earnings-breakdown">
      <div className="earnings-breakdown-row"><span>Order total</span><span>{currency(breakdown.gross_amount)}</span></div>
      <div className="earnings-breakdown-row muted">
        {/* Orders approved before 2026-10-09 had no platform share (the fee was taken on withdrawal then). */}
        <span>{platformShare > 0 ? `Platform fee (${percentLabel(breakdown.platform_percent)}%)` : 'Platform fee'}</span>
        <span>{platformShare > 0 ? `−${currency(platformShare)}` : 'None (older order)'}</span>
      </div>
      <div className="earnings-breakdown-row muted">
        <span>LGU share ({percentLabel(breakdown.lgu_percent)}%)</span>
        <span>−{currency(breakdown.lgu_share)}</span>
      </div>
      <div className="earnings-breakdown-row total">
        <span>{viewerIsSeller ? 'You receive' : 'Seller receives'} ({percentLabel(breakdown.seller_percent)}%)</span>
        <span>{currency(breakdown.seller_share)}</span>
      </div>
      <p className="helper-text">
        {breakdown.source === 'preview'
          ? 'Estimate -- the amounts are fixed when the LGU approves the earnings for this order.'
          : 'Fixed when the LGU approved the earnings for this order.'}
      </p>
    </div>
  )
}

export function OrderTable({ rows, onReview, onConfirmReceived, confirmPendingOrderId, onMarkReceived, markReceivedPendingOrderId, onPay, payPendingOrderId, onCancelOrder, cancelPendingOrderId, onUpdateStatus, lguContact, detailsEndpoint, initialOrderNumber = null, paymentView = 'escrow', counterparty = 'seller' }) {
  const [viewedOrderNumber, setViewedOrderNumber] = useState(initialOrderNumber || null)
  // Rating lives here, not in the popup: ratingOrderNumber is the order whose
  // rating dialog is open (from the Rating column or the post-confirm prompt),
  // and ratingPromptOrderNumber is an order that was just confirmed and is
  // waiting for its details popup to close before the prompt opens.
  const [ratingOrderNumber, setRatingOrderNumber] = useState(null)
  const [ratingPromptOrderNumber, setRatingPromptOrderNumber] = useState(null)

  const normalized = (rows || []).map((row) => {
    const sellerProfile = row.sellerProfile || row.listing?.sellerProfile || null
    const hatcheryName = sellerProfile?.hatchery_name || null
    const sellerPersonName = sellerProfile?.user?.name || null
    return {
      id: row.order_number || row.id,
      orderId: row.id,
      order_name: row.listing?.title || row.listing?.species || row.species || 'Order',
      order_number: row.order_number || row.id,
      seller_name: hatcheryName || sellerPersonName || row.seller || 'Unknown seller',
      seller_contact_name: sellerPersonName && sellerPersonName !== hatcheryName ? sellerPersonName : null,
      seller_avatar: sellerProfile?.profile_picture || null,
      seller_profile_id: row.seller_profile_id || sellerProfile?.id || null,
      buyer_name: row.buyer?.name || 'Unknown buyer',
      buyer_avatar: row.buyer?.profile_picture || null,
      buyer_id: row.buyer?.id || null,
      quantity: row.quantity,
      quantity_label: formatQuantity(row.quantity, row.listing),
      status: row.status,
      payment_status: row.payment?.status || row.payment_status || 'pending',
      total_amount: row.total_amount || row.amount || 0,
      created_at: row.created_at || row.date || '',
      review: row.review || null,
      // Appended by the Order model; null unless the order is awaiting payment.
      payment_expires_at: row.payment_expires_at || null,
      // The full row as the API returned it -- the popup's seller notices and
      // transition buttons read fields the flat row does not carry (the
      // payment object, lgu_review_status, latestDispute, auto_received_at).
      raw: row,
    }
  })

  if (!normalized.length) return <EmptyState message="No orders yet." />

  // The open popup follows the list: when an action refreshes the rows, the
  // row found here is the fresh one, so the popup's status and buttons update
  // with it (a cancelled order loses its Cancel button, and so on). The open
  // rating dialog follows the list the same way -- it reads the row for
  // display only, so it keeps working while the refetch is in flight.
  const viewedRow = viewedOrderNumber ? normalized.find((row) => row.order_number === viewedOrderNumber) : null
  const ratingRow = ratingOrderNumber ? normalized.find((row) => row.order_number === ratingOrderNumber) : null

  // Confirm Received still happens in the popup; the rating it leads to does
  // not. On success the order is queued below, so no failure can queue it.
  const handleConfirmReceived = async (row) => {
    try {
      await onConfirmReceived(row.orderId)
      setRatingPromptOrderNumber(row.order_number)
    } catch {
      // The parent's mutation state carries the error; nothing to prompt.
    }
  }

  // Closing the details popup right after confirming swaps it for the rating
  // prompt. Dismissing that is fine: the Rating column keeps its Rate Seller
  // button until the order is actually rated.
  const closeDetails = () => {
    const closingNumber = viewedOrderNumber
    setViewedOrderNumber(null)
    if (closingNumber && closingNumber === ratingPromptOrderNumber) {
      setRatingPromptOrderNumber(null)
      setRatingOrderNumber(closingNumber)
    }
  }

  return (
    <div className="table">
      <div className="table-row first">
        <span>Order Name</span>
        <span>Order #</span>
        <span>{counterparty === 'buyer' ? 'Buyer' : 'Seller'}</span>
        <span>Qty</span>
        <span>Order Date</span>
        <span>Status</span>
        {onReview && <span>Rating</span>}
        <span>Details</span>
      </div>
      {normalized.map((row) => (
        <div className="table-row" key={row.id}>
          <span>{row.order_name}</span>
          <span>{row.order_number}</span>
          {counterparty === 'buyer' ? (
            <span className="order-seller-cell">
              <Avatar src={row.buyer_avatar} alt={row.buyer_name} className="order-seller-avatar" />
              {row.buyer_id ? <Link to={`/seller/buyers/${row.buyer_id}`}>{row.buyer_name}</Link> : row.buyer_name}
            </span>
          ) : (
            <span className="order-seller-cell">
              <Avatar src={row.seller_avatar} alt={row.seller_name} className="order-seller-avatar" />
              <SellerLink id={row.seller_profile_id} name={row.seller_name} />
            </span>
          )}
          <span>{row.quantity_label}</span>
          <span className="order-date-cell">
            {formatOrderDate(row.created_at, { withTime: false })}
            {row.created_at && <small>{new Date(row.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small>}
          </span>
          <span><Badge status={row.status}>{statusChartLabel(row.status)}</Badge></span>
          {onReview && (
            <span>
              {row.review ? (
                <span className="review-given" title={`You rated this seller ${row.review.rating}/5`}>
                  {renderStars(row.review.rating)} ({row.review.rating}/5)
                </span>
              ) : row.status === 'completed' ? (
                <button type="button" className="ghost" onClick={() => setRatingOrderNumber(row.order_number)}>Rate Seller</button>
              ) : (
                <span className="muted">—</span>
              )}
            </span>
          )}
          <span>
            <button type="button" className="ghost" onClick={() => setViewedOrderNumber(row.order_number)}>View Order</button>
          </span>
        </div>
      ))}
      {viewedRow && (
        <OrderDetailsModal
          row={viewedRow}
          detailsEndpoint={detailsEndpoint}
          paymentView={paymentView}
          counterparty={counterparty}
          onConfirmReceived={onConfirmReceived ? handleConfirmReceived : undefined}
          confirmPendingOrderId={confirmPendingOrderId}
          onPay={onPay}
          payPendingOrderId={payPendingOrderId}
          onCancelOrder={onCancelOrder}
          cancelPendingOrderId={cancelPendingOrderId}
          onMarkReceived={onMarkReceived}
          markReceivedPendingOrderId={markReceivedPendingOrderId}
          onUpdateStatus={onUpdateStatus}
          lguContact={lguContact}
          onClose={closeDetails}
        />
      )}
      {ratingRow && (
        <RateSellerModal row={ratingRow} onReview={onReview} onClose={() => setRatingOrderNumber(null)} />
      )}
    </div>
  )
}

export function AdminOrderTable({ rows, base, invalidateKeys, initialOrderNumber = null }) {
  const queryClient = useQueryClient()
  const markReceived = useMutation({
    mutationFn: async (orderId) => (await api.patch(`${base}/orders/${orderId}/mark-delivered`)).data,
    onSuccess: () => {
      invalidateKeys.forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }))
      // Keeps the open View Order popup's detail panel in step -- without it
      // the panel behind the button kept showing the order Out for Delivery.
      queryClient.invalidateQueries({ queryKey: ['order-detail'] })
    },
  })
  const handleMarkReceived = (row) => {
    if (!window.confirm(`Mark order ${row.order_number} as received on the buyer's behalf? Only do this once you have confirmed the fingerlings were delivered. The order will move to Seller Earnings for approval.`)) return
    markReceived.mutate(row.orderId)
  }

  return (
    <>
      {markReceived.error && <p className="error">{markReceived.error.response?.data?.message || 'Could not mark this order as received.'}</p>}
      <PeriodFilteredRows rows={rows}>
        {(shown) => (
          <OrderTable
            rows={shown}
            detailsEndpoint={(orderNumber) => `${base}/orders/${orderNumber}`}
            initialOrderNumber={initialOrderNumber}
            onMarkReceived={handleMarkReceived}
            markReceivedPendingOrderId={markReceived.isPending ? markReceived.variables : null}
          />
        )}
      </PeriodFilteredRows>
    </>
  )
}

const ORDER_TIMELINE_TERMINAL_LABELS = {
  cancelled: 'Order Cancelled',
  failed: 'Payment Failed',
  on_hold: 'On Hold for Investigation',
  rejected: 'Transaction Rejected by LGU',
}

/**
 * Marketplace-progress timeline only -- not courier/GPS tracking. Renders
 * whatever stages the backend (App\Support\OrderTimeline) says are reached
 * for this order; reused by every role's Order Details view.
 */
function OrderTimelineView({ timeline }) {
  if (!timeline?.stages?.length) return null
  return (
    <div className="order-timeline">
      {timeline.stages.map((stage) => (
        <div key={stage.key} className={`order-timeline-step${stage.reached ? ' reached' : ''}`}>
          <strong>{stage.label}</strong>
          {stage.timestamp && <div className="order-timeline-step-time">{new Date(stage.timestamp).toLocaleString()}</div>}
        </div>
      ))}
      {timeline.terminal_status && (
        <p className="error">
          {ORDER_TIMELINE_TERMINAL_LABELS[timeline.terminal_status] || timeline.terminal_status}
          {timeline.terminal_reason ? `: ${timeline.terminal_reason}` : ''}
        </p>
      )}
    </div>
  )
}

/**
 * The single Order Details view reused across Buyer/Seller/LGU/Super Admin
 * (see App\Support\OrderTransactionPresenter on the backend, which is the
 * one place that assembles this payload). Revenue distribution / LGU
 * verification / seller payout status simply aren't present in the payload
 * for Buyer/Seller, so no client-side role branching is needed here.
 */
/**
 * paymentView matches OrderTable's: 'escrow' (default -- seller, LGU, Super
 * Admin) shows the raw lifecycle, 'buyer' collapses it so a buyer reads "Paid"
 * here exactly as they do in the column above. Display only, so it applies to
 * every past order without touching a single stored row.
 */
export function OrderDetailPanel({ detail, paymentView = 'escrow' }) {
  if (!detail) return null
  return (
    <div className="order-detail-panel">
      <div className="order-detail-grid">
        <div className="order-detail-field"><span className="order-detail-field-label">Order Number</span><span>{detail.order_number}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Order Date</span><span>{formatOrderDate(detail.created_at)}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Listing</span><span>{detail.listing?.title || detail.listing?.species || 'N/A'}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Seller</span><span><SellerLink seller={detail.seller} fallback="N/A" /></span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Buyer</span><span>{detail.buyer?.name || 'N/A'}</span></div>
        {detail.municipality && <div className="order-detail-field"><span className="order-detail-field-label">Municipality</span><span>{detail.municipality.name}</span></div>}
        <div className="order-detail-field"><span className="order-detail-field-label">Quantity</span><span>{formatQuantity(detail.quantity, detail.listing)}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Total Amount</span><span className="price">{currency(detail.total_amount)}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Payment Status</span><span><PaymentStatusBadge status={detail.payment_status || 'pending'} view={paymentView} /></span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Order Status</span><span><Badge status={detail.order_status}>{statusChartLabel(detail.order_status)}</Badge></span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Delivery Status</span><span>{detail.delivery_status}</span></div>
        <div className="order-detail-field">
          <span className="order-detail-field-label">Review Status</span>
          <span>{detail.review ? <>{renderStars(detail.review.rating)} ({detail.review.rating}/5)</> : 'Not yet reviewed'}</span>
        </div>
        {detail.seller_notes && (
          <div className="order-detail-field"><span className="order-detail-field-label">Seller Notes</span><span>{detail.seller_notes}</span></div>
        )}
        {/* This panel is the same component for the buyer, the LGU and the
            Super Admin -- they differ only in which endpoint fills it -- so
            stating the reason once here shows it to all three. */}
        {detail.cancellation_reason && (
          <div className="order-detail-field">
            <span className="order-detail-field-label">Cancellation Reason</span>
            <span className="error">{detail.cancellation_reason}</span>
          </div>
        )}
      </div>
      {detail.revenue_distribution_preview && (
        <div className="order-detail-grid">
          <div className="order-detail-field">
            <span className="order-detail-field-label">{getSession()?.role === 'seller' ? 'Your Earnings' : 'Earnings Breakdown'}</span>
            <EarningsBreakdown breakdown={detail.revenue_distribution_preview} viewerIsSeller={getSession()?.role === 'seller'} />
          </div>
          {detail.lgu_verification && (
            <div className="order-detail-field">
              <span className="order-detail-field-label">LGU Verification Status</span>
              <span>
                <Badge status={detail.lgu_verification?.status}>{statusChartLabel(detail.lgu_verification?.status)}</Badge>
                {detail.lgu_verification?.review_reason ? ` — ${detail.lgu_verification.review_reason}` : ''}
              </span>
            </div>
          )}
        </div>
      )}
      {detail.seller_payout_status && (
        <div className="order-detail-field">
          <span className="order-detail-field-label">Seller Payout Status</span>
          <span><Badge status={detail.seller_payout_status}>{statusChartLabel(detail.seller_payout_status)}</Badge></span>
        </div>
      )}
      <div>
        <span className="order-detail-field-label">Order Timeline</span>
        <OrderTimelineView timeline={detail.timeline} />
      </div>
    </div>
  )
}

/** One payment status, told from either the buyer's or the escrow's point of view. */
function PaymentStatusBadge({ status, view }) {
  if (view !== 'buyer') return <Badge status={status}>{statusChartLabel(status)}</Badge>
  const [label, tone] = BUYER_PAYMENT_VIEW[status] || ['Unpaid', 'warning']
  return <Badge tone={tone}>{label}</Badge>
}

/**
 * The way back into PayMongo for an order whose checkout was abandoned or
 * declined -- shown in the order popup's footer (OrderDetailsModal) while the
 * payment window is open. The scheduler runs every five minutes, so an order
 * can sit briefly after its window has closed; don't offer a payment the API
 * would refuse. Renders nothing when there is no payment to offer: the
 * details panel above already shows the order as Unpaid in that case.
 */
function PayNowAction({ row, pending, onPay }) {
  const expiresAtMs = row.payment_expires_at ? Date.parse(row.payment_expires_at) : null
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!expiresAtMs) return undefined
    const timer = setInterval(() => setNow(Date.now()), 30000)
    return () => clearInterval(timer)
  }, [expiresAtMs])

  const minutesLeft = expiresAtMs === null ? null : Math.floor((expiresAtMs - now) / 60000)
  if (row.status !== 'placed' || minutesLeft === null || minutesLeft < 0) return null

  return (
    <span className="order-pay-cell">
      <button type="button" onClick={() => onPay(row.orderId)} disabled={pending}>
        {pending ? 'Opening...' : 'Pay Now'}
      </button>
      {/* Turns amber under five minutes: the same number, but by then it is a
          warning rather than a note. */}
      <small className={minutesLeft < 5 ? 'order-pay-countdown urgent' : 'order-pay-countdown'}>
        <Timer size={12} aria-hidden="true" />
        {minutesLeft < 1 ? 'under a minute' : `${minutesLeft} min left`}
      </small>
    </span>
  )
}


function ReviewModal({ row, rating, title, comment, error, submitting, onRatingChange, onTitleChange, onCommentChange, onSubmit, onClose }) {
  return (
    <Modal
      title="Rate Seller"
      subtitle={`Order #${row.order_number}`}
      onClose={submitting ? () => {} : onClose}
      footer={(
        <>
          <button type="button" className="ghost" onClick={onClose} disabled={submitting}>Cancel</button>
          <button type="button" onClick={onSubmit} disabled={submitting}>{submitting ? 'Submitting...' : 'Submit'}</button>
        </>
      )}
    >
      <div className="review-modal-product">
        <Avatar src={row.seller_avatar} alt={row.seller_name} className="listing-seller-avatar" />
        <div>
          <strong>{row.order_name}</strong>
          <p className="muted">{row.quantity_label} · Seller: {row.seller_name}</p>
        </div>
      </div>

      <div className="review-modal-rating">
        <span>Seller Quality</span>
        <div className="review-star-picker" role="radiogroup" aria-label="Seller rating">
          {[1, 2, 3, 4, 5].map((star) => (
            <button
              key={star}
              type="button"
              className={star <= rating ? 'active' : ''}
              onClick={() => onRatingChange(star)}
              aria-label={`${star} star${star > 1 ? 's' : ''}`}
              aria-checked={rating === star}
              role="radio"
            >
              ★
            </button>
          ))}
        </div>
        <strong>{rating === 5 ? 'Amazing' : rating >= 4 ? 'Good' : rating >= 3 ? 'Okay' : rating >= 2 ? 'Poor' : 'Bad'}</strong>
      </div>

      <div className="review-modal-box">
        <label>
          <span>Topic</span>
          <input placeholder="Review title (optional)" value={title} onChange={(e) => onTitleChange(e.target.value)} />
        </label>
        <label>
          <span>Review</span>
          <textarea
            placeholder="Share more thoughts about this seller to help other buyers."
            value={comment}
            onChange={(e) => onCommentChange(e.target.value)}
            rows={6}
          />
        </label>
      </div>

      {error && <p className="error">{error}</p>}
    </Modal>
  )
}

/**
 * The stateful rating dialog behind OrderTable's Rating column and its
 * post-Confirm-Received prompt; ReviewModal above stays purely presentational
 * so both entry points share one form. Skipping is always safe -- the column
 * keeps its Rate Seller button until the order is actually rated.
 */
function RateSellerModal({ row, onReview, onClose }) {
  const [rating, setRating] = useState(5)
  const [title, setTitle] = useState('')
  const [comment, setComment] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    setSubmitting(true)
    setError('')
    try {
      await onReview(row.orderId, { rating, title, comment })
      setSubmitting(false)
      onClose()
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not submit review.')
      setSubmitting(false)
    }
  }

  return (
    <ReviewModal
      row={row}
      rating={rating}
      title={title}
      comment={comment}
      error={error}
      submitting={submitting}
      onRatingChange={setRating}
      onTitleChange={setTitle}
      onCommentChange={setComment}
      onSubmit={submit}
      onClose={onClose}
    />
  )
}

/**
 * Cancelling an order, from either side of it (see OrderDetailsModal, the one
 * caller). An unpaid order needs only a confirmation -- there is nothing to
 * explain and no money to move. A paid order -- including one the seller has
 * confirmed but not yet shipped -- requires a reason, because it pushes the
 * payment into the Super Admin's refund queue and the other party is told why.
 * The seller always gives a reason, paid or not (the API requires it, and the
 * buyer deserves to know why their order stopped).
 */
function CancelOrderModal({ row, mode, audience = 'buyer', reason, error, submitting, onReasonChange, onSubmit, onClose }) {
  const needsReason = audience === 'seller' || mode === 'paid'
  return (
    <Modal
      title="Cancel Order"
      subtitle={`Order #${row.order_number}`}
      onClose={submitting ? () => {} : onClose}
      footer={(
        <>
          <button type="button" className="ghost" onClick={onClose} disabled={submitting}>Keep Order</button>
          <button
            type="button"
            className="danger"
            onClick={onSubmit}
            disabled={submitting || (needsReason && !reason.trim())}
          >
            {submitting ? 'Cancelling...' : 'Cancel Order'}
          </button>
        </>
      )}
    >
      <div className="review-modal-product">
        <div>
          <strong>{row.order_name}</strong>
          <p className="muted">{row.quantity_label} · {audience === 'seller' ? `Buyer: ${row.buyer_name}` : `Seller: ${row.seller_name}`}</p>
        </div>
      </div>

      {needsReason ? (
        <div className="cancel-form">
          <p className="helper-text">
            {audience === 'seller'
              ? (mode === 'paid'
                ? 'Cancelling a paid order returns the stock to your listing and refunds the buyer. Tell them why -- they will see this reason.'
                : 'Cancelling returns the stock to your listing. Tell the buyer why -- they will see this reason.')
              : 'Your payment will be refunded. Refunds are processed by the Super Admin. Tell the seller why you are cancelling -- they will see this reason.'}
          </p>
          <textarea
            value={reason}
            onChange={(e) => onReasonChange(e.target.value)}
            placeholder={audience === 'seller'
              ? 'e.g. Our pond had a fish kill overnight and we cannot fulfil this order.'
              : 'e.g. I found the same fingerlings closer to my farm.'}
            autoFocus
          />
        </div>
      ) : (
        <p className="helper-text">
          The reserved stock goes back to the seller&apos;s listing. You have not paid for this
          order, so nothing needs refunding, and you can place a new order at any time.
        </p>
      )}

      {error && <p className="error">{error}</p>}
    </Modal>
  )
}