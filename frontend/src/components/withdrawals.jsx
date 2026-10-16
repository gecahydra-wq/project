import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { EmptyState } from './states.jsx'
import { Avatar, Badge, Modal, RoleBadge, Section, SellerLink } from './ui.jsx'
import { api, queryClient } from '../lib/api.js'
import { apiErrorMessage } from '../lib/errors.js'
import { currency, formatOrderDate } from '../lib/format.js'
import { usePeriodFilter } from '../lib/hooks.jsx'
import { withdrawalStatusLabel } from '../lib/status.js'
import { normalizeAccountNumber } from '../lib/validation.js'
import { selectedBankName, withdrawalMethodLabel } from '../lib/withdrawals.js'



/**
 * "Are these details correct?" step before a withdrawal request is sent, for
 * both the seller and the LGU wallet. Money sent to a mistyped account cannot
 * be pulled back, so the requester sees exactly what will be submitted first.
 * Neither seller nor LGU payouts carry a fee (since 2026-10-09 the Platform
 * takes its 4% at settlement), so the requester receives the amount shown.
 */
export function WithdrawalConfirmModal({ form, pending = false, onConfirm, onClose }) {
  const amount = Number(form.amount) || 0
  const rows = [
    ['Payout method', withdrawalMethodLabel(form.method)],
    ...(form.method === 'bank_transfer' ? [['Bank', selectedBankName(form)]] : []),
    ['Account name', form.account_name.trim()],
    [form.method === 'bank_transfer' ? 'Bank account number' : 'Mobile number', normalizeAccountNumber(form.account_number)],
    ['Amount requested', currency(amount)],
    ['You will receive', currency(amount)],
  ]
  return (
    <Modal
      title="Are these details correct?"
      subtitle="Check everything before you send the request."
      onClose={onClose}
      footer={
        <>
          <button type="button" disabled={pending} onClick={onConfirm}>{pending ? 'Submitting...' : 'Yes, submit request'}</button>
          <button type="button" className="ghost" disabled={pending} onClick={onClose}>Go back and edit</button>
        </>
      }
    >
      <div className="withdrawal-confirm-list">
        {rows.map(([label, value]) => (
          <div key={label} className="order-detail-field">
            <span className="order-detail-field-label">{label}</span>
            <strong>{value}</strong>
          </div>
        ))}
      </div>
      <p className="helper-text">Money sent to a wrong account number may not be recoverable, so make sure the name and number match the account exactly.</p>
    </Modal>
  )
}

export function WithdrawalRow({ request, onApprove, onReject, onSendReceipt, type = 'seller' }) {
  const [showDetails, setShowDetails] = useState(false)
  const seller = request.sellerProfile
  const isLgu = type === 'lgu'

  return (
    <div className="card action withdrawal-row">
      <div>
        <div className="card-row">
          {isLgu ? <strong>{request.municipality?.name || 'Unknown municipality'}</strong> : <strong><SellerLink seller={seller} /></strong>}
          <Badge status={request.status}>{request.auto_completed_at ? 'Auto-completed' : withdrawalStatusLabel(request.status)}</Badge>
        </div>
        <p>Request #{request.id} · {currency(request.amount)} via {withdrawalMethodLabel(request.method, request.bank_name)}</p>
        <p className="muted">{isLgu ? `Requested by ${request.requestedBy?.name || 'Unknown'} · ` : ''}Requested {new Date(request.created_at).toLocaleDateString()}</p>
        {request.status === 'sent' && request.recipient_confirmed_at && (
          <p className="helper-text">Receipt confirmed by {request.recipientConfirmedBy?.name || (isLgu ? 'an LGU admin' : 'the seller')} on {new Date(request.recipient_confirmed_at).toLocaleDateString()}.</p>
        )}
        {request.status === 'paid' && request.paid_at && (
          <p className="helper-text">{request.auto_completed_at ? `Auto-completed ${new Date(request.auto_completed_at).toLocaleDateString()} after no receipt confirmation.` : `Paid on ${new Date(request.paid_at).toLocaleDateString()}`}</p>
        )}
        {['rejected', 'rejected_final'].includes(request.status) && request.rejection_reason && <p className="error">Reason: {request.rejection_reason}</p>}
      </div>
      <div className="row-actions">
        <button type="button" className="ghost" onClick={() => setShowDetails(true)}>Review Request</button>
        {request.status === 'paid' && request.auto_completed_at && (
          <Link className="ghost" to={`/admin/dashboard?tab=messages&with=${request.sent_by}`}>Open Payout Message</Link>
        )}
      </div>
      {showDetails && (
        <WithdrawalReviewModal
          request={request}
          type={type}
          onClose={() => setShowDetails(false)}
          onApprove={onApprove}
          onReject={onReject}
          onSendReceipt={onSendReceipt}
        />
      )}
    </div>
  )
}

function WithdrawalReviewModal({ request, type, onClose, onApprove, onReject, onSendReceipt }) {
  const [attachment, setAttachment] = useState(null)
  const [note, setNote] = useState('')
  const [payoutSent, setPayoutSent] = useState(false)
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const isLgu = type === 'lgu'
  const accountNumberLabel = request.method === 'bank_transfer' ? 'Bank account number' : 'Mobile number'
  const rows = [
    ['Request number', `#${request.id}`],
    [isLgu ? 'Municipality' : 'Seller', isLgu ? (request.municipality?.name || 'Unknown') : (request.sellerProfile?.hatchery_name || request.sellerProfile?.user?.name || 'Unknown')],
    ['Requested by', isLgu ? (request.requestedBy?.name || 'Unknown') : (request.sellerProfile?.user?.name || 'Unknown')],
    ['Amount requested', currency(request.amount)],
    ...(!isLgu ? [['Seller receives', currency(request.net_amount)]] : []),
    ['Payout method', withdrawalMethodLabel(request.method, request.bank_name)],
    ...(request.method === 'bank_transfer' ? [['Bank', request.bank_name || '—']] : []),
    ['Account name', request.account_name],
    [accountNumberLabel, request.account_number],
    ['Requested on', new Date(request.created_at).toLocaleString()],
    ['Status', request.auto_completed_at ? 'Auto-completed after no confirmation' : withdrawalStatusLabel(request.status)],
    ...(request.sent_at ? [['Payout sent on', new Date(request.sent_at).toLocaleString()]] : []),
    ...(request.recipient_confirmed_at ? [['Receipt confirmed on', new Date(request.recipient_confirmed_at).toLocaleString()]] : []),
    ...(request.paid_at ? [['Completed on', new Date(request.paid_at).toLocaleString()]] : []),
    ...(request.rejection_reason ? [['Rejection reason', request.rejection_reason]] : []),
  ]

  const run = async (action) => {
    setBusy(true)
    setError('')
    try {
      await action()
    } catch (err) {
      setError(apiErrorMessage(err, 'Could not update this withdrawal request.'))
    } finally {
      setBusy(false)
    }
  }

  const footer = request.status === 'pending' ? (
    <>
      <button type="button" onClick={() => run(() => onApprove(request.id))} disabled={busy}>Approve Request</button>
      <button type="button" className="ghost danger" onClick={() => setShowReject(true)} disabled={busy}>Reject</button>
      <button type="button" className="ghost" onClick={onClose} disabled={busy}>Close</button>
    </>
  ) : request.status === 'approved' ? (
    <>
      <button type="button" onClick={() => run(async () => {
        if (!payoutSent) throw new Error('Confirm that the payout has already been sent before sending its receipt.')
        await onSendReceipt(request.id, { note, attachment })
      })} disabled={busy || !payoutSent}>{busy ? 'Sending...' : 'Send Payout'}</button>
      <button type="button" className="ghost danger" onClick={() => setShowReject(true)} disabled={busy}>Reject Request</button>
      <button type="button" className="ghost" onClick={onClose} disabled={busy}>Close</button>
    </>
  ) : <button type="button" className="ghost" onClick={onClose}>Close</button>

  return (
    <Modal
      title={`${isLgu ? 'LGU' : 'Seller'} Withdrawal Request`}
      subtitle="Review the submitted account and withdrawal details before acting."
      onClose={busy ? () => {} : onClose}
      footer={footer}
    >
      <div className="withdrawal-confirm-list">
        {rows.map(([label, value]) => (
          <div key={label} className="order-detail-field">
            <span className="order-detail-field-label">{label}</span>
            <strong>{value}</strong>
          </div>
        ))}
      </div>
      {request.status === 'approved' && (
        <div className="form grid-form payout-receipt-form">
          <p className="helper-text">Send the payout to the account above first. Then confirm it below and send a direct message. The receipt image is optional; this dedicated action starts the inactivity timer.</p>
          <label className="checkbox-row"><input type="checkbox" checked={payoutSent} onChange={(event) => setPayoutSent(event.target.checked)} /> I have sent this payout to the listed account.</label>
          <textarea value={note} onChange={(event) => setNote(event.target.value)} placeholder="Optional note or transfer reference" rows={3} />
          <label className="file-input-label">Receipt screenshot (optional)<input type="file" accept="image/*" onChange={(event) => setAttachment(event.target.files?.[0] || null)} disabled={busy} /></label>
          {attachment && <span className="muted">{attachment.name}</span>}
        </div>
      )}
      {request.status === 'sent' && (
        <p className="helper-text">Payout message sent {request.sent_at ? new Date(request.sent_at).toLocaleString() : ''}. The request completes when the recipient confirms Money Received, or automatically after the confirmation window.</p>
      )}
      {request.status === 'paid' && request.auto_completed_at && (
        <p className="helper-text">Automatically completed after the recipient did not confirm within the configured window. If they report a missing payout, investigate the linked Messages conversation.</p>
      )}
      {showReject && (
        <div className="form grid-form withdrawal-reject-form">
          <input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Reason for rejection" />
          <button type="button" className="danger" disabled={busy || !reason.trim()} onClick={() => run(async () => { await onReject(request.id, reason.trim()); onClose() })}>Confirm Reject</button>
        </div>
      )}
      {error && <p className="error">{error}</p>}
    </Modal>
  )
}

/**
 * Appeal a rejection.
 *
 * A rejected earnings review or withdrawal used to be the end of the
 * conversation -- the party was told the reason and had no way to answer it.
 * `endpoint` differs per subject (order, seller withdrawal, LGU withdrawal);
 * the backend decides who may file, so this only collects the explanation.
 */
/**
 * A rejected withdrawal keeps its amount on hold while it can be disputed, so
 * one amount can never be requested twice (see App\Support\WithdrawalRejection).
 * The owner disputes it once within the window; otherwise it releases itself
 * when the window closes. There is no "accept the rejection" button for
 * anyone: an owner would always rather defend their money (user decision).
 */
export function RejectedWithdrawalActions({ request, disputeEndpoint, invalidateKeys, rejectedBy = 'the Super Admin' }) {
  const deadline = request.dispute_deadline ? new Date(request.dispute_deadline).toLocaleDateString() : null

  return (
    <div className="table-row-appeal">
      <div className="table-row-appeal-head">
        {request.has_open_dispute ? (
          <p className="helper-text">Your dispute is waiting for a decision. The {currency(request.amount)} stays on hold until then.</p>
        ) : request.can_dispute ? (
          <>
            <p className="error">
              This withdrawal was rejected by {rejectedBy}. The {currency(request.amount)} is on hold{deadline ? ` until ${deadline}` : ''} so
              you can dispute it once if you think it should be reconsidered. If you don&apos;t, it returns to your Available
              Balance{deadline ? ' on that date' : ''}.
            </p>
            <div className="row-actions">
              <DisputeAction endpoint={disputeEndpoint} invalidateKeys={invalidateKeys} label="Dispute This Rejection" />
            </div>
          </>
        ) : (
          <p className="helper-text">The dispute window has closed. The {currency(request.amount)} will return to your Available Balance shortly.</p>
        )}
      </div>
    </div>
  )
}

/**
 * Shown once a rejection is final in the app (a rejected Notice to Explain, or
 * a withdrawal whose one dispute is used up or whose dispute window closed).
 * Like a rejected registration, the next step is a person: a direct message or
 * a support ticket. LGU Admins have no ticket form of their own, so only the
 * message link is offered without a `topic`. `returnedAmount` (withdrawals
 * only) says the held money is back, so nobody thinks it was lost.
 */
export function FinalRejectionHelp({ base, contact = null, contactLabel, topic = null, returnedAmount = null }) {
  const messageLink = `${base}?tab=messages${contact?.id ? `&with=${contact.id}` : ''}`
  return (
    <div className="table-row-appeal">
      <p className="helper-text">
        This rejection is final.
        {returnedAmount != null && ` The ${currency(returnedAmount)} was returned to your Available Balance, so you can request it again.`}
        {' '}If you still think it is wrong,{' '}
        <Link to={messageLink}>message {contactLabel}{contact?.name ? ` (${contact.name})` : ''}</Link>
        {topic && (
          <>
            {' '}or <Link to={`${base}?tab=support&view=contact`}>send a support ticket</Link> under &quot;{topic}&quot;
          </>
        )}
        .
      </p>
    </div>
  )
}

export function DisputeAction({ endpoint, invalidateKeys = [], label = 'Dispute This' }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  // Submitting used to just close the form, which was indistinguishable from
  // the click not registering. The refetch below usually replaces this with
  // the order's own "awaiting review" state, but the acknowledgement must not
  // depend on that having arrived yet.
  const [submitted, setSubmitted] = useState(false)

  const file = useMutation({
    mutationFn: async () => (await api.post(endpoint, { reason: reason.trim() })).data,
    onSuccess: () => {
      invalidateKeys.forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }))
      setOpen(false)
      setReason('')
      setSubmitted(true)
    },
  })

  if (submitted) {
    return (
      <span className="dispute-submitted">
        <CheckCircle size={15} /> Dispute submitted -- waiting for a response.
      </span>
    )
  }

  if (!open) {
    return <button type="button" className="ghost" onClick={() => setOpen(true)}>{label}</button>
  }

  return (
    <div className="form grid-form dispute-form">
      <p className="helper-text full-span">
        Explain your side. Your LGU (or the Super Admin) will read this and either reopen the decision or let it stand.
      </p>
      <textarea
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder="What happened, and why you think the decision should be reconsidered."
      />
      {file.error && <p className="error full-span">{file.error.response?.data?.message || 'Could not submit your dispute.'}</p>}
      <button type="button" onClick={() => file.mutate()} disabled={!reason.trim() || file.isPending}>
        {file.isPending ? 'Submitting...' : 'Submit Dispute'}
      </button>
      <button type="button" className="ghost" onClick={() => { setOpen(false); setReason('') }}>Cancel</button>
    </div>
  )
}

/* The backend sends the model class as disputable_type; these are the only
   three things that can be rejected and therefore disputed. */
const DISPUTE_SUBJECT_LABEL = {
  'App\\Models\\Order': 'Rejected earnings approval',
  'App\\Models\\WithdrawalRequest': 'Rejected withdrawal',
  'App\\Models\\LguWithdrawalRequest': 'Rejected LGU withdrawal',
}

/**
 * The reviewer's side: read the explanation, then accept (which REOPENS the
 * rejected item so it can be decided again -- it does not approve it) or
 * reject (the original decision stands). Shared by the LGU and Super Admin
 * dashboards, which differ only in scope; `scope` picks the API prefix.
 */
export function DisputesPanel({ scope = 'lgu' }) {
  const [note, setNote] = useState({})
  const base = scope === 'super-admin' ? '/super-admin' : '/lgu'
  const queryKey = `${scope}-disputes`
  // Arriving from a specific rejected transaction ("Review Dispute"), so the
  // reviewer lands on the one they clicked rather than hunting a queue for it.
  const [searchParams] = useSearchParams()
  const focusId = searchParams.get('dispute')
  const focusRef = useRef(null)

  const disputes = useQuery({
    queryKey: [queryKey],
    queryFn: async () => (await api.get(`${base}/disputes`)).data,
    placeholderData: [],
  })

  // Runs once the list has actually rendered -- the row does not exist on the
  // first pass, while the query is still resolving.
  useEffect(() => {
    if (!focusId || !focusRef.current) return
    focusRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }, [focusId, disputes.data])

  const resolve = useMutation({
    mutationFn: async ({ id, action }) => (await api.patch(`${base}/disputes/${id}/${action}`, { note: note[id]?.trim() || undefined })).data,
    onSettled: () => queryClient.invalidateQueries({ queryKey: [queryKey] }),
  })

  const rows = disputes.data || []
  const openRows = rows.filter((row) => row.status === 'open')
  const { shown: resolvedRows, controls: resolvedPeriodControls, periodEmpty: resolvedPeriodEmpty } = usePeriodFilter(rows.filter((row) => row.status !== 'open'), { noun: 'resolved disputes filed' })

  return (
    <>
      <Section title="Open Disputes">
        <p className="helper-text">
          Accepting a dispute <strong>reopens</strong> the rejected item so it comes back to you for a fresh decision -- it does
          not approve it, and no money moves. Rejecting leaves your original decision exactly as it was.
        </p>
        {openRows.length ? (
          <div className="item-list">
            {openRows.map((row) => (
              <div
                className={`card dispute-item${String(row.id) === focusId ? ' is-focused' : ''}`}
                key={row.id}
                ref={String(row.id) === focusId ? focusRef : null}
              >
                <div className="dispute-head">
                  <Avatar src={row.filedBy?.profile_picture} alt={row.filedBy?.name} className="dispute-avatar" />
                  <div className="dispute-head-text">
                    <div className="card-row">
                      <strong><SellerLink id={row.filedBy?.sellerProfile?.id} name={row.filedBy?.name} fallback="Unknown" /></strong>
                      {row.filedBy?.role && <RoleBadge role={row.filedBy.role} />}
                      <Badge tone="warning">Open</Badge>
                    </div>
                    <p className="muted">
                      {row.subject_label || DISPUTE_SUBJECT_LABEL[row.disputable_type] || 'Rejected item'}
                      {row.subject_reference ? ` · ${row.subject_reference}` : ''}
                      {` · filed ${formatOrderDate(row.created_at)}`}
                    </p>
                  </div>
                </div>
                {/* The appeal reads as an answer, so the decision it answers
                    has to be next to it -- otherwise the reviewer is recalling
                    their own wording from memory. */}
                {row.subject_rejection_reason && (
                  <p className="dispute-original">
                    <span className="dispute-field-label">Reason given for rejecting</span>
                    {row.subject_rejection_reason}
                  </p>
                )}
                <blockquote className="dispute-letter">
                  <span className="dispute-field-label">Their explanation</span>
                  {row.reason}
                </blockquote>
                <div className="form grid-form dispute-decision">
                  <textarea
                    value={note[row.id] || ''}
                    onChange={(e) => setNote({ ...note, [row.id]: e.target.value })}
                    placeholder="Your note (required to reject, optional to accept)"
                  />
                  {resolve.error && <p className="error full-span">{resolve.error.response?.data?.message || 'Could not resolve this dispute.'}</p>}
                  <button type="button" disabled={resolve.isPending} onClick={() => resolve.mutate({ id: row.id, action: 'accept' })}>
                    Accept &amp; Reopen
                  </button>
                  <button
                    type="button"
                    className="ghost danger"
                    disabled={resolve.isPending || !(note[row.id] || '').trim()}
                    onClick={() => resolve.mutate({ id: row.id, action: 'reject' })}
                  >
                    Reject Dispute
                  </button>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState message="No open disputes." />}
      </Section>
      <Section title="Resolved Disputes">
        {resolvedPeriodControls}
        {resolvedRows.length ? (
          <div className="item-list">
            {resolvedRows.map((row) => (
              <div
                className={`card dispute-item is-resolved${String(row.id) === focusId ? ' is-focused' : ''}`}
                key={row.id}
                ref={String(row.id) === focusId ? focusRef : null}
              >
                <div className="dispute-head">
                  <Avatar src={row.filedBy?.profile_picture} alt={row.filedBy?.name} className="dispute-avatar" />
                  <div className="dispute-head-text">
                    <div className="card-row">
                      <strong><SellerLink id={row.filedBy?.sellerProfile?.id} name={row.filedBy?.name} fallback="Unknown" /></strong>
                      {row.filedBy?.role && <RoleBadge role={row.filedBy.role} />}
                      <Badge status={row.status} tone={row.status === 'accepted' ? 'success' : undefined}>{row.status === 'accepted' ? 'Accepted' : 'Rejected'}</Badge>
                    </div>
                    <p className="muted">
                      {row.subject_label || DISPUTE_SUBJECT_LABEL[row.disputable_type] || 'Rejected item'}
                      {row.subject_reference ? ` · ${row.subject_reference}` : ''}
                      {row.resolvedBy?.name ? ` · resolved by ${row.resolvedBy.name}` : ''}
                      {row.resolved_at ? ` on ${formatOrderDate(row.resolved_at)}` : ''}
                    </p>
                  </div>
                </div>
                <blockquote className="dispute-letter">
                  <span className="dispute-field-label">Their explanation</span>
                  {row.reason}
                </blockquote>
                {row.resolution_note && (
                  <p className="dispute-original">
                    <span className="dispute-field-label">Decision note</span>
                    {row.resolution_note}
                  </p>
                )}
              </div>
            ))}
          </div>
        ) : resolvedPeriodEmpty || <EmptyState message="No resolved disputes yet." />}
      </Section>
    </>
  )
}