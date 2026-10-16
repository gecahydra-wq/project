import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MessageCircle, ShieldAlert, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { EmptyState } from './states.jsx'
import { Badge, Modal, Section, SellerLink } from './ui.jsx'
import { api, queryClient } from '../lib/api.js'
import { useAccountSuspended, usePeriodFilter } from '../lib/hooks.jsx'



/**
 * Reusable suspend/reinstate control -- reveal-on-click form, same UX as
 * WithdrawalRow's reject flow. Suspending uses a required dropdown when
 * `reasons` is given (Buyer moderation) or an optional free-text field
 * otherwise (Seller/LGU Admin moderation). Reinstating always requires a
 * free-text reason, regardless of role -- same accountability expectation
 * as suspending: every status change needs a stated reason on the record.
 */
/**
 * Seller Registration Approval queue, shared by the LGU Admin and the Super
 * Admin. One approval is enough and both reviewers take the same two
 * decisions, so they share one component -- only the endpoints and the scope
 * of the queue differ (own municipality vs. platform-wide fallback). See
 * backend App\Support\SellerApproval.
 */
/** How a report/notice status reads on screen, and its badge colour. */
const REPORT_STATUS_META = {
  pending: { label: 'Pending', tone: 'warning' },
  open: { label: 'Open', tone: 'warning' },
  under_review: { label: 'Under Review', tone: 'info' },
  resolved: { label: 'Resolved', tone: 'success' },
  dismissed: { label: 'Dismissed', tone: 'neutral' },
  accepted: { label: 'Accepted', tone: 'success' },
  rejected: { label: 'Rejected', tone: 'danger' },
  // A notice for a seller who already had an explanation rejected: it
  // suspended them on arrival, with nothing to explain.
  repeat_offense: { label: 'Repeat Offense', tone: 'danger' },
}

export function ReportStatusBadge({ status }) {
  const meta = REPORT_STATUS_META[status] || { label: status, tone: 'neutral' }
  return <Badge tone={meta.tone}>{meta.label}</Badge>
}

/**
 * "Report this user" button + popup. One component for both directions -- a
 * Buyer reporting a Seller and a Seller reporting a Buyer post to the same
 * endpoint, which derives the direction from the caller's role (see backend
 * UserReportController).
 */
export function ReportUserAction({ userId, userName, label = 'Report User' }) {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ reason: '', description: '' })

  const reasons = useQuery({
    queryKey: ['report-reasons'],
    queryFn: async () => (await api.get('/reports/reasons')).data.reasons,
    enabled: open,
    retry: false,
    placeholderData: [],
  })

  const submit = useMutation({
    mutationFn: async () => (await api.post('/reports', {
      reported_user_id: userId,
      reason: form.reason,
      description: form.description,
    })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-reports'] })
      setForm({ reason: '', description: '' })
      setOpen(false)
    },
  })

  // No reason is pre-selected: the reporter must choose one themselves.
  const reason = form.reason
  const canSubmit = Boolean(reason) && form.description.trim().length >= 10

  return (
    <>
      <button type="button" className="ghost danger" onClick={() => setOpen(true)}>
        <ShieldAlert size={16} /> {label}
      </button>
      {open && (
        <Modal
          title={label}
          subtitle={userName}
          onClose={() => setOpen(false)}
          footer={
            <>
              <button type="button" className="danger" disabled={!canSubmit || submit.isPending} onClick={() => submit.mutate()}>
                {submit.isPending ? 'Submitting...' : 'Submit Report'}
              </button>
              <button type="button" className="ghost" disabled={submit.isPending} onClick={() => setOpen(false)}>Cancel</button>
            </>
          }
        >
          <p className="helper-text">
            Reports go to the LGU Admin for this municipality and to the Super Admin. Describe what happened as clearly as you can --
            they will review it and decide what action to take. Filing a report does not suspend anyone by itself. The person you
            report is notified right away of the reason and what you write below, but not of your name.
          </p>
          <label className="filter-label">
            Reason (required)
            <select value={reason} onChange={(e) => setForm({ ...form, reason: e.target.value })}>
              <option value="" disabled>Choose a reason</option>
              {(reasons.data || []).map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
          </label>
          <label className="filter-label">
            What happened?
            <textarea
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              placeholder="Give the details: dates, order numbers, and what went wrong (at least 10 characters)."
              rows={5}
            />
          </label>
          {submit.error && <p className="error">{submit.error.response?.data?.message || 'Could not submit this report.'}</p>}
        </Modal>
      )}
    </>
  )
}

/**
 * Reports Dashboard, shared by the LGU Admin (own municipality) and the Super
 * Admin (platform-wide). Shows every column the two roles need to act --
 * Reporter, Reported User, Reason, Description, Date, Status -- and lets them
 * move a report through under review / resolved / dismissed with notes.
 */
export function UserReportsPanel({ endpointBase, queryKey, scopeLabel }) {
  const [statusFilter, setStatusFilter] = useState('open')
  const [actingId, setActingId] = useState(null)
  const [decision, setDecision] = useState({ status: 'under_review', notes: '' })
  // 'notice' is not a report status: it saves the report as resolved and asks
  // the reported seller to explain (SellerReputation::raiseReportNotice).
  const sendsNotice = decision.status === 'notice'

  const reports = useQuery({
    queryKey: [queryKey],
    queryFn: async () => (await api.get(`${endpointBase}/user-reports`)).data,
    retry: false,
    placeholderData: [],
  })

  const updateReport = useMutation({
    mutationFn: async ({ id, status, notes }) => (await api.patch(`${endpointBase}/user-reports/${id}`, {
      status: status === 'notice' ? 'resolved' : status,
      issue_notice: status === 'notice' || undefined,
      resolution_notes: notes || undefined,
    })).data,
    onSuccess: () => {
      setActingId(null)
      setDecision({ status: 'under_review', notes: '' })
      queryClient.invalidateQueries({ queryKey: [queryKey] })
    },
  })

  const rows = (reports.data || []).filter((report) => {
    if (statusFilter === 'all') return true
    if (statusFilter === 'open') return !['resolved', 'dismissed'].includes(report.status)
    return report.status === statusFilter
  })
  const { shown: periodRows, controls: periodControls, periodEmpty } = usePeriodFilter(rows, { noun: 'reports filed' })

  return (
    <Section title="User Reports">
      <p className="helper-text">{scopeLabel}</p>
      <div className="filters">
        <label className="filter-label">
          Status
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="open">Open (pending &amp; under review)</option>
            <option value="pending">Pending</option>
            <option value="under_review">Under Review</option>
            <option value="resolved">Resolved</option>
            <option value="dismissed">Dismissed</option>
            <option value="all">All</option>
          </select>
        </label>
      </div>
      {periodControls}
      {periodRows.length ? (
        <div className="item-list">
          {periodRows.map((report) => (
            <div className="card report-card" key={report.id}>
              <div className="card-row">
                <strong>
                  <SellerLink id={report.reporter?.sellerProfile?.id} name={report.reporter?.name} fallback="Unknown" /> <span className="muted">({report.reporter_role})</span>
                  {' → '}
                  <SellerLink id={report.reportedUser?.sellerProfile?.id} name={report.reportedUser?.name} fallback="Unknown" /> <span className="muted">({report.reported_role})</span>
                </strong>
                <ReportStatusBadge status={report.status} />
              </div>
              <p className="report-reason"><strong>Reason:</strong> {report.reason}</p>
              <p className="report-description">{report.description}</p>
              <p className="muted">
                Filed {new Date(report.created_at).toLocaleString()}
                {report.municipality?.name ? ` · ${report.municipality.name}` : ''}
                {report.order?.order_number ? ` · Order ${report.order.order_number}` : ''}
              </p>
              {report.resolution_notes && (
                <p className="helper-text"><strong>Notes:</strong> {report.resolution_notes}{report.reviewer?.name ? ` — ${report.reviewer.name}` : ''}</p>
              )}
              {actingId === report.id ? (
                <div className="form grid-form">
                  <select value={decision.status} onChange={(e) => setDecision({ ...decision, status: e.target.value })}>
                    <option value="under_review">Mark Under Review</option>
                    {report.reported_role === 'seller' && <option value="notice">Valid: send Notice to Explain</option>}
                    <option value="dismissed">Dismiss</option>
                  </select>
                  <textarea
                    value={decision.notes}
                    onChange={(e) => setDecision({ ...decision, notes: e.target.value })}
                    placeholder={decision.status === 'dismissed'
                      ? 'Reason for dismissing (required, shared with the reporter)'
                      : sendsNotice
                        ? 'What you found (required -- the seller and the reporter both see this)'
                        : 'Notes on your decision (optional, shared with the reporter)'}
                    rows={2}
                  />
                  {sendsNotice && (
                    <p className="helper-text">
                      The seller must explain under Notices to Explain. If you later reject the explanation, the seller is suspended.
                    </p>
                  )}
                  <div className="row-actions">
                    <button type="button" disabled={updateReport.isPending || ((decision.status === 'dismissed' || sendsNotice) && !decision.notes.trim())} onClick={() => updateReport.mutate({ id: report.id, status: decision.status, notes: decision.notes.trim() })}>
                      Save Decision
                    </button>
                    <button type="button" className="ghost" disabled={updateReport.isPending} onClick={() => setActingId(null)}>Cancel</button>
                  </div>
                </div>
              ) : (
                <div className="row-actions">
                  <button type="button" className="ghost" onClick={() => { setActingId(report.id); setDecision({ status: 'under_review', notes: '' }) }}>Take Action</button>
                  {report.reported_user_id && (
                    <Link className="ghost" to={`${endpointBase === '/lgu' ? '/lgu' : '/admin'}/dashboard?tab=messages&with=${report.reported_user_id}`}>
                      <MessageCircle size={16} /> Message Reported User
                    </Link>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      ) : periodEmpty || <EmptyState message="No reports match this filter." />}
      {updateReport.error && <p className="error">{updateReport.error.response?.data?.message || 'Could not update this report.'}</p>}
    </Section>
  )
}

export function SellerRegistrationQueue({ endpointBase, queryKey, stageLabel, approveLabel, emptyMessage, extraInvalidateKeys = [] }) {
  const [rejectingId, setRejectingId] = useState(null)
  const [reason, setReason] = useState('')

  const registrations = useQuery({
    queryKey: [queryKey],
    queryFn: async () => (await api.get(`${endpointBase}/seller-registrations`)).data,
    retry: false,
    placeholderData: [],
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: [queryKey] })
    extraInvalidateKeys.forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }))
  }

  const approve = useMutation({
    mutationFn: async (id) => (await api.patch(`${endpointBase}/sellers/${id}/approve-registration`)).data,
    onSuccess: refresh,
  })
  const reject = useMutation({
    mutationFn: async ({ id, reason: why }) => (await api.patch(`${endpointBase}/sellers/${id}/reject-registration`, { reason: why })).data,
    onSuccess: () => {
      setRejectingId(null)
      setReason('')
      refresh()
    },
  })

  const busy = approve.isPending || reject.isPending
  const { shown, controls: periodControls, periodEmpty } = usePeriodFilter(registrations.data, { noun: 'registrations submitted' })

  return (
    <Section title="Seller Registration Approvals">
      <p className="helper-text">{stageLabel}</p>
      {periodControls}
      {shown.length ? (
        <div className="item-list">
          {shown.map((seller) => (
            <div className="card action" key={seller.id}>
              <div>
                <div className="card-row">
                  <strong><SellerLink seller={seller} name={seller.hatchery_name} /></strong>
                  <Badge status={seller.approval_status}>{seller.approval_status_label}</Badge>
                </div>
                <p>{seller.user?.name} · {seller.user?.email}{seller.user?.phone ? ` · ${seller.user.phone}` : ''}</p>
                <p className="muted">
                  {seller.municipality?.name || 'Unknown municipality'} · Registered {new Date(seller.created_at).toLocaleDateString()}
                </p>
                {seller.approval_status === 'rejected' && seller.registration_rejection_reason && (
                  <p className="error">Rejected: {seller.registration_rejection_reason}</p>
                )}
              </div>
              <div className="row-actions">
                {seller.user_id && <Link className="ghost" to={`${endpointBase === '/lgu' ? '/lgu' : '/admin'}/dashboard?tab=messages&with=${seller.user_id}`}><MessageCircle size={16} /> Message</Link>}
                {rejectingId === seller.id ? (
                  <div className="moderation-form">
                    <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for rejection (required)" />
                    <div className="row-actions">
                      <button type="button" className="danger" disabled={busy || !reason.trim()} onClick={() => reject.mutate({ id: seller.id, reason: reason.trim() })}>Confirm Reject</button>
                      <button type="button" className="ghost" disabled={busy} onClick={() => { setRejectingId(null); setReason('') }}>Cancel</button>
                    </div>
                  </div>
                ) : (
                  <>
                    <button type="button" disabled={busy} onClick={() => approve.mutate(seller.id)}>{approveLabel}</button>
                    <button type="button" className="ghost danger" disabled={busy} onClick={() => { setRejectingId(seller.id); setReason('') }}>Reject</button>
                  </>
                )}
              </div>
            </div>
          ))}
        </div>
      ) : periodEmpty || <EmptyState message={emptyMessage} />}
      {(approve.error || reject.error) && (
        <p className="error">{approve.error?.response?.data?.message || reject.error?.response?.data?.message || 'Could not update this registration.'}</p>
      )}
    </Section>
  )
}

export function ModerationAction({ suspended, reasons, onSuspend, onReinstate }) {
  const [mode, setMode] = useState(null) // null | 'suspend' | 'reinstate'
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')

  const openForm = (nextMode) => {
    setMode(nextMode)
    setReason(nextMode === 'suspend' && reasons ? reasons[0] : '')
    setNotes('')
  }

  if (!mode) {
    return suspended
      ? <button type="button" onClick={() => openForm('reinstate')}>Reinstate</button>
      : <button type="button" className="ghost danger" onClick={() => openForm('suspend')}>Suspend</button>
  }

  // Every suspend, disable and reinstate carries a reason the account owner is told.
  const canSubmit = Boolean(reason.trim())

  const submit = () => {
    if (!canSubmit) return
    if (mode === 'suspend') {
      onSuspend(reason.trim(), notes.trim() || undefined)
    } else {
      onReinstate(reason.trim(), notes.trim() || undefined)
    }
    setMode(null)
  }

  return (
    <div className="moderation-form">
      {mode === 'suspend' && reasons ? (
        <select value={reason} onChange={(e) => setReason(e.target.value)}>
          {reasons.map((r) => <option key={r} value={r}>{r}</option>)}
        </select>
      ) : (
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder={mode === 'reinstate' ? 'Reason for reinstating (required)' : 'Reason (required, shown to the account owner)'}
        />
      )}
      <textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Additional notes (optional)" rows={2} />
      <div className="row-actions">
        <button type="button" className={mode === 'suspend' ? 'danger' : ''} onClick={submit} disabled={!canSubmit}>
          {mode === 'suspend' ? 'Confirm Suspend' : 'Confirm Reinstate'}
        </button>
        <button type="button" className="ghost" onClick={() => setMode(null)}>Cancel</button>
      </div>
    </div>
  )
}

/**
 * Super Admin-only permanent account removal, with a required reason -- the
 * escalation beyond ModerationAction's reversible suspend/reinstate.
 *
 * Reveal-on-click like every other reason-taking control in the app, but
 * deliberately heavier: the reason is a required dropdown (never free text
 * alone, so the audit trail stays filterable) and the confirm step names the
 * account, since this can't be undone. The backend refuses removal outright
 * once the account has order history and says so -- that 422 is surfaced here
 * via `error` rather than pre-guessed in the UI, so the rule lives in exactly
 * one place (App\Support\AccountModeration).
 */
export function AccountRemovalAction({ accountName, reasons, onRemove, error, removing }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState(reasons[0])
  const [notes, setNotes] = useState('')

  const close = () => {
    setOpen(false)
    setReason(reasons[0])
    setNotes('')
  }

  if (!open) {
    return <button type="button" className="ghost danger" onClick={() => setOpen(true)}><Trash2 size={15} /> Remove</button>
  }

  return (
    <div className="moderation-form">
      <p className="helper-text">
        Permanently remove <strong>{accountName}</strong>? This cannot be undone. Suspend instead if you may want to restore this account later.
      </p>
      <select value={reason} onChange={(e) => setReason(e.target.value)}>
        {reasons.map((r) => <option key={r} value={r}>{r}</option>)}
      </select>
      <textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Additional notes (optional)" rows={2} />
      {error && <p className="error">{error}</p>}
      <div className="row-actions">
        <button type="button" className="danger" disabled={removing} onClick={() => onRemove(reason, notes.trim() || undefined)}>
          {removing ? 'Removing...' : 'Confirm Remove'}
        </button>
        <button type="button" className="ghost" onClick={close}>Cancel</button>
      </div>
    </div>
  )
}

/** The "your account is suspended" notice shown across a buyer's or seller's dashboard. */
export function SuspendedAccountNotice({ role }) {
  const suspended = useAccountSuspended()
  if (!suspended) return null
  const base = role === 'seller' ? '/seller/dashboard' : '/buyer/dashboard'
  return (
    <div className="card approval-notice approval-notice-danger">
      <div className="card-row">
        <strong>Your account is suspended</strong>
        <Badge tone="danger">Suspended</Badge>
      </div>
      <p className="helper-text">
        {role === 'seller'
          ? 'Your listings are off the marketplace, your unfinished orders were cancelled and the buyers refunded, and you cannot add or edit listings, update orders, request withdrawals, post or message buyers until you are reinstated. You can still sign in, see your account, dispute rejected earnings or withdrawals, and message your LGU or the Super Admin. You cannot answer a Notice to Explain while suspended.'
          : 'You cannot place orders, pay, message sellers or leave reviews until you are reinstated. You can still sign in, browse and see your orders.'}
        {' '}The reason is in your <Link to={`${base}?tab=notifications`}>Notifications</Link> and in the email we sent. If you think this is a mistake,{' '}
        <Link to={`${base}?tab=messages`}>message your LGU</Link> or <Link to={`${base}?tab=support`}>send a support ticket</Link>.
      </p>
    </div>
  )
}