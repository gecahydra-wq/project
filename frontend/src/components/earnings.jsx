import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CalendarDays } from 'lucide-react'
import { useState } from 'react'
import { OrderDetailPanel } from './orders.jsx'
import { EmptyState, LoadingState } from './states.jsx'
import { Avatar, Badge, PeriodFilteredRows, Section, SellerLink } from './ui.jsx'
import { api } from '../lib/api.js'
import { currency, formatOrderDate } from '../lib/format.js'



/**
 * One row in the LGU's Seller Earnings Awaiting Approval queue. "View
 * Details" lazy-fetches the full transaction (see LguController::showOrder
 * / App\Support\OrderTransactionPresenter) and renders it with the same
 * OrderDetailPanel every other role uses, so the LGU can review everything
 * about the transaction -- including the revenue distribution preview --
 * before approving or rejecting it.
 *
 * Placing a NEW hold was removed from this UI: approve and reject already
 * cover the decision, and a hold was just a reject the seller never got an
 * answer on. Clear Hold stays so any order held before that change can still
 * be released -- the /lgu/payments/{payment}/hold endpoint is likewise left
 * intact for backwards compatibility.
 */
function LguEarningsRow({ payment, onApprove, approvingId, onClearHold, onReject, base = '/lgu' }) {
  const [expanded, setExpanded] = useState(false)
  const [rejecting, setRejecting] = useState(false)
  const [reasonDraft, setReasonDraft] = useState('')
  const orderNumber = payment.order?.order_number
  const isOnHold = payment.order?.lgu_review_status === 'on_hold'

  const detail = useQuery({
    queryKey: [`${base}-order-detail`, orderNumber],
    queryFn: async () => (await api.get(`${base}/orders/${orderNumber}`)).data,
    enabled: expanded && Boolean(orderNumber),
  })

  const submitReason = () => {
    if (!reasonDraft.trim()) return
    onReject({ paymentId: payment.id, reason: reasonDraft })
    setRejecting(false)
    setReasonDraft('')
  }

  return (
    <div className={`card action${expanded ? ' action-stacked' : ''}`}>
      <div>
        <div className="card-row earnings-seller-row">
          <Avatar src={payment.order?.sellerProfile?.profile_picture} alt={payment.order?.sellerProfile?.hatchery_name} className="listing-seller-avatar" />
          <strong><SellerLink id={payment.order?.seller_profile_id || payment.order?.sellerProfile?.id} seller={payment.order?.sellerProfile} /></strong>
          {base !== '/lgu' && payment.order?.sellerProfile?.municipality?.name && <span className="muted">{payment.order.sellerProfile.municipality.name}</span>}
          {isOnHold && <Badge status="on_hold">On Hold</Badge>}
        </div>
        <p>
          Order #{orderNumber} · {payment.order?.listing?.title || payment.order?.listing?.species || 'Listing'} · Buyer: {payment.order?.buyer?.name || 'Unknown buyer'}
        </p>
        <p className="muted order-date-line"><CalendarDays size={14} /> Ordered {formatOrderDate(payment.order?.created_at)}</p>
        <p className="muted">{currency(payment.amount)} awaiting approval</p>
        {expanded && (
          <>
            {detail.isLoading && <LoadingState label="Loading transaction..." />}
            {detail.data && <OrderDetailPanel detail={detail.data} />}
          </>
        )}
      </div>
      <div className="row-actions">
        <button type="button" className="ghost" onClick={() => setExpanded((current) => !current)}>
          {expanded ? 'Hide Details' : 'View Details'}
        </button>
        {isOnHold ? (
          <button type="button" onClick={() => onClearHold(payment.id)}>Clear Hold</button>
        ) : (
          <>
            <button type="button" onClick={() => onApprove(payment.id)} disabled={approvingId === payment.id}>
              {approvingId === payment.id ? 'Approving...' : 'Approve Earnings'}
            </button>
            <button type="button" className="ghost danger" onClick={() => setRejecting(true)}>Reject</button>
          </>
        )}
      </div>
      {rejecting && (
        <div className="order-lookup-form">
          <input
            placeholder="Reason for rejecting (required)"
            value={reasonDraft}
            onChange={(e) => setReasonDraft(e.target.value)}
          />
          <button type="button" onClick={submitReason} disabled={!reasonDraft.trim()}>Confirm Reject</button>
          <button type="button" className="ghost" onClick={() => { setRejecting(false); setReasonDraft('') }}>Cancel</button>
        </div>
      )}
    </div>
  )
}

/**
 * One rejected-but-still-held transaction. The money is still sitting in
 * escrow ('paid_held') -- rejecting never moved it -- so this row exists to
 * make that visible and to offer the only way back out: Reopen Review, which
 * returns the order to the approval queue (see
 * LguController::reopenRejectedEarnings).
 */
function LguRejectedEarningsRow({ payment, base = '/lgu', dashboardPath = '/lgu/dashboard', onReopen, reopeningId }) {
  const [expanded, setExpanded] = useState(false)
  const order = payment.order
  const orderNumber = order?.order_number
  const isReopening = reopeningId === payment.id

  const detail = useQuery({
    queryKey: [`${base}-order-detail`, orderNumber],
    queryFn: async () => (await api.get(`${base}/orders/${orderNumber}`)).data,
    enabled: expanded && Boolean(orderNumber),
  })

  return (
    <div className={`card action${expanded ? ' action-stacked' : ''}`}>
      <div>
        <div className="card-row earnings-seller-row">
          <Avatar src={order?.sellerProfile?.profile_picture} alt={order?.sellerProfile?.hatchery_name} className="listing-seller-avatar" />
          <strong><SellerLink id={order?.seller_profile_id || order?.sellerProfile?.id} seller={order?.sellerProfile} /></strong>
          {base !== '/lgu' && order?.sellerProfile?.municipality?.name && <span className="muted">{order.sellerProfile.municipality.name}</span>}
          <Badge status="rejected">Rejected</Badge>
        </div>
        <p>
          Order #{orderNumber} · {order?.listing?.title || order?.listing?.species || 'Listing'} · Buyer: {order?.buyer?.name || 'Unknown buyer'}
        </p>
        <p className="muted order-date-line"><CalendarDays size={14} /> Ordered {formatOrderDate(order?.created_at)}</p>
        <p className="muted">{currency(payment.amount)} still held · Rejected {order?.lgu_reviewed_at ? new Date(order.lgu_reviewed_at).toLocaleDateString() : ''}{order?.reviewedBy?.name ? ` by ${order.reviewedBy.name}` : ''}</p>
        {order?.lgu_review_reason && <p className="error">Reason: {order.lgu_review_reason}</p>}
        {expanded && (
          <>
            {detail.isLoading && <LoadingState label="Loading transaction..." />}
            {detail.data && <OrderDetailPanel detail={detail.data} />}
          </>
        )}
      </div>
      <div className="row-actions">
        <button type="button" className="ghost" onClick={() => setExpanded((current) => !current)}>
          {expanded ? 'Hide Details' : 'View Details'}
        </button>
        {/* The seller has answered this rejection. The appeal is read and
            decided on the Disputes tab, so this only points there rather than
            duplicating accept/reject beside the existing Reopen action. */}
        {order?.latestDispute?.status === 'open' && (
          <Link className="button dispute-pending-link" to={`${dashboardPath}?tab=disputes&dispute=${order.latestDispute.id}`}>
            Review Dispute
          </Link>
        )}
        {order?.latestDispute?.status !== 'open' && (
          <button type="button" onClick={() => onReopen(payment.id)} disabled={isReopening}>
            {isReopening ? 'Reopening...' : 'Reopen Review'}
          </button>
        )}
      </div>
    </div>
  )
}

/**
 * The Seller Earnings queue: completed orders awaiting approval, plus the
 * rejected-but-still-held list. The LGU sees its own municipality; the Super
 * Admin (scope 'super-admin') sees every municipality and acts as the
 * platform-wide fallback -- see LguController::reviewsSeller.
 */
export function SellerEarningsPanel({ scope = 'lgu' }) {
  const queryClient = useQueryClient()
  const isSuperAdmin = scope === 'super-admin'
  const base = isSuperAdmin ? '/super-admin' : '/lgu'
  const dashboardPath = isSuperAdmin ? '/admin/dashboard' : '/lgu/dashboard'
  const earningsKey = `${scope}-earnings`
  const rejectedKey = `${scope}-rejected-earnings`
  const dashboardKey = `${scope}-dashboard`

  const pendingEarnings = useQuery({
    queryKey: [earningsKey],
    queryFn: async () => (await api.get(`${base}/earnings`)).data,
    retry: false,
    placeholderData: [],
  })
  const rejectedEarnings = useQuery({
    queryKey: [rejectedKey],
    queryFn: async () => (await api.get(`${base}/earnings/rejected`)).data,
    retry: false,
    placeholderData: [],
  })
  const approveEarnings = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`${base}/payments/${paymentId}/approve`)).data,
    onSuccess: (data, paymentId) => {
      // Remove the row instantly rather than waiting on the invalidated
      // query's network refetch -- that gap is what let a second click land
      // on an already-approved (now stale) row and surface a confusing
      // "not awaiting approval" error right after the first click succeeded.
      queryClient.setQueryData([earningsKey], (old) => (old || []).filter((payment) => payment.id !== paymentId))
      queryClient.invalidateQueries({ queryKey: [earningsKey] })
      queryClient.invalidateQueries({ queryKey: [dashboardKey] })
    },
    onError: (error, paymentId) => {
      // A 422 here almost always means this payment was already approved
      // (e.g. a second click on a row before the list refreshed) -- refresh
      // the list so the stale, already-approved row disappears immediately
      // instead of leaving it clickable.
      if (error.response?.status === 422) {
        queryClient.setQueryData([earningsKey], (old) => (old || []).filter((payment) => payment.id !== paymentId))
        queryClient.invalidateQueries({ queryKey: [earningsKey] })
        queryClient.invalidateQueries({ queryKey: [dashboardKey] })
      }
    },
  })
  const clearHoldEarnings = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`${base}/payments/${paymentId}/clear-hold`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [earningsKey] }),
  })
  const rejectEarnings = useMutation({
    mutationFn: async ({ paymentId, reason }) => (await api.patch(`${base}/payments/${paymentId}/reject`, { reason })).data,
    onSuccess: (data, { paymentId }) => {
      queryClient.setQueryData([earningsKey], (old) => (old || []).filter((payment) => payment.id !== paymentId))
      queryClient.invalidateQueries({ queryKey: [earningsKey] })
      queryClient.invalidateQueries({ queryKey: [rejectedKey] })
    },
  })
  const reopenEarnings = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`${base}/payments/${paymentId}/reopen`)).data,
    onSuccess: (data, paymentId) => {
      queryClient.setQueryData([rejectedKey], (old) => (old || []).filter((payment) => payment.id !== paymentId))
      queryClient.invalidateQueries({ queryKey: [rejectedKey] })
      queryClient.invalidateQueries({ queryKey: [earningsKey] })
      queryClient.invalidateQueries({ queryKey: [dashboardKey] })
    },
  })

  return (
    <>
      <Section title="Seller Earnings Awaiting Approval">
        <p className="helper-text">
          {isSuperAdmin
            ? 'Completed (delivered) orders from sellers in every municipality. Each LGU normally approves its own sellers; you can act on any of them, for example where a municipality has no active LGU Admin.'
            : 'Only completed (delivered) orders from sellers in your municipality appear here.'}
          {' '}Approving moves the earnings from the seller&apos;s Pending Balance into their Available Balance.
        </p>
        {(pendingEarnings.data || []).length ? (
          <div className="item-list">
            {pendingEarnings.data.map((payment) => (
              <LguEarningsRow
                key={payment.id}
                payment={payment}
                base={base}
                onApprove={(id) => approveEarnings.mutate(id)}
                approvingId={approveEarnings.isPending ? approveEarnings.variables : null}
                onClearHold={(id) => clearHoldEarnings.mutate(id)}
                onReject={(vars) => rejectEarnings.mutate(vars)}
              />
            ))}
          </div>
        ) : <EmptyState message="No completed orders awaiting earnings approval." />}
        {approveEarnings.error && approveEarnings.error.response?.status !== 422 && (
          <p className="error">{approveEarnings.error.response?.data?.message || 'Could not approve earnings.'}</p>
        )}
        {clearHoldEarnings.error && <p className="error">{clearHoldEarnings.error.response?.data?.message || 'Could not clear the hold.'}</p>}
        {rejectEarnings.error && <p className="error">{rejectEarnings.error.response?.data?.message || 'Could not reject this order.'}</p>}
      </Section>
      {(rejectedEarnings.data || []).length > 0 && (
        <Section title="Rejected Transactions">
          <p className="helper-text">
            {isSuperAdmin ? 'Rejected orders across every municipality.' : 'Orders you rejected.'} The buyer&apos;s payment is still held for these -- rejecting doesn&apos;t refund it or release it to the seller,
            and no revenue is distributed. If a seller disputes one, accepting their explanation on the Disputes tab reopens it and puts it
            back in the queue above. If the seller messages you or opens a support ticket and you decide to reconsider,
            use Reopen Review, then approve it from the approval queue.
          </p>
          <PeriodFilteredRows rows={rejectedEarnings.data} noun="orders rejected" dateKey={(payment) => payment.order?.lgu_reviewed_at}>
            {(shown) => (shown.length ? (
              <div className="item-list">
                {shown.map((payment) => (
                  <LguRejectedEarningsRow
                    key={payment.id}
                    payment={payment}
                    base={base}
                    dashboardPath={dashboardPath}
                    onReopen={(id) => reopenEarnings.mutate(id)}
                    reopeningId={reopenEarnings.isPending ? reopenEarnings.variables : null}
                  />
                ))}
              </div>
            ) : <EmptyState message="No rejected orders in this period." />)}
          </PeriodFilteredRows>
          {reopenEarnings.error && <p className="error">{reopenEarnings.error.response?.data?.message || 'Could not reopen this order.'}</p>}
        </Section>
      )}
    </>
  )
}