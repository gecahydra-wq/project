import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MessageCircle } from 'lucide-react'
import { useState } from 'react'
import { ReportStatusBadge } from './moderation.jsx'
import { LowReviewsList } from './reviews.jsx'
import { EmptyState } from './states.jsx'
import { Badge, Section, SellerLink } from './ui.jsx'
import { FinalRejectionHelp } from './withdrawals.jsx'
import { api, queryClient } from '../lib/api.js'
import { useAccountSuspended, usePeriodFilter } from '../lib/hooks.jsx'



/**
 * Notices to Explain -- the LGU's dedicated dashboard for sellers flagged for
 * a low average rating or a valid buyer report (backend
 * App\Support\SellerReputation). Raising a notice never suspends anyone;
 * rejecting the explanation does (App\Support\SellerSanctions), and the
 * seller's one dispute then appears under Disputes.
 */
export function SellerNoticesPanel({ scope = 'lgu' }) {
  // The Super Admin sees every municipality through the same panel -- the
  // decision, its consequences and the wording are identical, only the API
  // prefix differs. See SuperAdminController::sellerNotices for why they are
  // a fallback reviewer here.
  const base = scope === 'super_admin' ? '/super-admin' : '/lgu'
  const dashboardKey = scope === 'super_admin' ? 'super-admin-dashboard' : 'lgu-dashboard'
  const noticesKey = `${scope}-seller-notices`
  const [actingId, setActingId] = useState(null)
  const [decision, setDecision] = useState({ status: 'under_review', notes: '' })

  const notices = useQuery({
    queryKey: [noticesKey],
    queryFn: async () => (await api.get(`${base}/seller-notices`)).data,
    retry: false,
    placeholderData: [],
  })

  const { shown: shownNotices, controls: periodControls, periodEmpty } = usePeriodFilter(notices.data, { noun: 'notices issued' })

  const refreshNotices = () => {
    setActingId(null)
    setDecision({ status: 'under_review', notes: '' })
    queryClient.invalidateQueries({ queryKey: [noticesKey] })
    queryClient.invalidateQueries({ queryKey: [dashboardKey] })
  }
  const updateNotice = useMutation({
    mutationFn: async ({ id, status, notes }) => (await api.patch(`${base}/seller-notices/${id}`, {
      status,
      lgu_notes: notes || undefined,
    })).data,
    onSuccess: refreshNotices,
  })
  // Accept and reject are separate endpoints because they carry consequences:
  // a rejection is an offense and suspends the seller.
  const acceptNotice = useMutation({
    mutationFn: async ({ id, notes }) => (await api.patch(`${base}/seller-notices/${id}/accept`, { notes: notes || undefined })).data,
    onSuccess: refreshNotices,
  })
  const rejectNotice = useMutation({
    mutationFn: async ({ id, reason }) => (await api.patch(`${base}/seller-notices/${id}/reject`, { reason })).data,
    onSuccess: refreshNotices,
  })

  return (
    <Section title="Notices to Explain">
      <p className="helper-text">
        Sellers {scope === 'super_admin' ? 'across every municipality' : 'in your municipality'} get a Notice to Explain when a review of 3 stars
        or fewer drops their average buyer rating <strong>below 3</strong>, or when a buyer&apos;s report against them is found valid under User
        Reports. On a seller&apos;s notice they are not suspended and their listings stay up while they send{' '}
        <strong>one</strong> explanation. Read it and decide: <strong>accept</strong> closes the notice with no offense recorded;{' '}
        <strong>reject</strong> records an offense and <strong>suspends the seller</strong>. A seller who <strong>already had an explanation
        rejected</strong> is suspended automatically on their next notice, with nothing to explain (<strong>Repeat Offense</strong>). There is
        no in-app dispute: a suspended seller messages you or sends a support ticket, and you can reinstate them from the Sellers tab.
      </p>
      {periodControls}
      {shownNotices.length ? (
        <div className="item-list">
          {shownNotices.map((notice) => (
            <div className="card report-card" key={notice.id}>
              <div className="card-row">
                <strong><SellerLink seller={notice.sellerProfile} /></strong>
                {notice.type === 'user_report'
                  ? <Badge tone="danger">Buyer report</Badge>
                  : <Badge tone="danger">{Number(notice.average_rating || 0).toFixed(2)}/5</Badge>}
                <ReportStatusBadge status={notice.status} />
              </div>
              <p className="report-description">{notice.details}</p>
              <p className="muted">
                Issued {new Date(notice.created_at).toLocaleString()}
                {notice.type !== 'user_report' && ` · ${notice.ratings_count} review${notice.ratings_count === 1 ? '' : 's'} at the time`}
              </p>
              <p className="muted">
                Offenses on record: {notice.seller_offense_count ?? 0}
                {notice.sellerProfile?.status === 'suspended' ? ' · Account suspended' : ''}
              </p>
              <LowReviewsList notice={notice} sellerProfileId={notice.sellerProfile?.id ?? notice.seller_profile_id} />
              {notice.seller_response ? (
                <div className="notice-response">
                  <strong>Seller&apos;s explanation</strong>
                  <p>{notice.seller_response}</p>
                  <p className="muted">Responded {notice.responded_at ? new Date(notice.responded_at).toLocaleString() : ''}</p>
                </div>
              ) : (
                <p className="helper-text">The seller has not responded yet.</p>
              )}
              {notice.lgu_notes && <p className="helper-text"><strong>Your notes:</strong> {notice.lgu_notes}</p>}
              {['accepted', 'rejected', 'repeat_offense'].includes(notice.status) ? (
                <p className="helper-text">
                  {notice.status === 'accepted' && 'Explanation accepted. No offense was recorded.'}
                  {notice.status === 'rejected' && 'Explanation rejected -- an offense was recorded and the seller was suspended. To lift it, reinstate them from the Sellers tab.'}
                  {notice.status === 'repeat_offense' && 'Repeat offense -- this seller already had an explanation rejected, so this notice suspended them automatically and they cannot explain it. They will message you or send a support ticket; to lift it, reinstate them from the Sellers tab.'}
                  {notice.reviewer?.name ? ` Decided by ${notice.reviewer.name}.` : ''}
                </p>
              ) : actingId === notice.id ? (
                <div className="form grid-form">
                  <textarea
                    value={decision.notes}
                    onChange={(e) => setDecision({ ...decision, notes: e.target.value })}
                    placeholder="Reason for rejecting (required, at least 10 characters) -- or optional notes when accepting. Either way the seller sees this."
                    rows={2}
                  />
                  <div className="row-actions">
                    <button
                      type="button"
                      disabled={acceptNotice.isPending || !notice.seller_response}
                      title={notice.seller_response ? undefined : 'The seller has not explained yet.'}
                      onClick={() => acceptNotice.mutate({ id: notice.id, notes: decision.notes.trim() })}
                    >
                      Accept Explanation
                    </button>
                    <button
                      type="button"
                      className="ghost danger"
                      disabled={rejectNotice.isPending || decision.notes.trim().length < 10}
                      onClick={() => {
                        if (window.confirm('Reject this explanation? The seller will be suspended until someone reinstates them.')) {
                          rejectNotice.mutate({ id: notice.id, reason: decision.notes.trim() })
                        }
                      }}
                    >
                      Reject Explanation
                    </button>
                    <button type="button" className="ghost" onClick={() => setActingId(null)}>Cancel</button>
                  </div>
                  <p className="helper-text">
                    Rejecting records an offense and suspends this seller. You can reinstate them later from the Sellers tab.
                  </p>
                </div>
              ) : (
                <div className="row-actions">
                  <button type="button" className="ghost" onClick={() => { setActingId(notice.id); setDecision({ status: 'under_review', notes: '' }) }}>Decide</button>
                  {notice.sellerProfile?.user_id && (
                    <Link className="ghost" to={`${scope === 'super_admin' ? '/admin' : '/lgu'}/dashboard?tab=messages&with=${notice.sellerProfile.user_id}`}><MessageCircle size={16} /> Message Seller</Link>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      ) : periodEmpty || <EmptyState message={`No sellers ${scope === 'super_admin' ? '' : 'in your municipality '}have a Notice to Explain.`} />}
      {(updateNotice.error || acceptNotice.error || rejectNotice.error) && (
        <p className="error">
          {(updateNotice.error || acceptNotice.error || rejectNotice.error).response?.data?.message || 'Could not update this notice.'}
        </p>
      )}
    </Section>
  )
}

export function SellerNoticesSection({ lguContact = null }) {
  const [drafts, setDrafts] = useState({})
  // However the suspension came about, a suspended seller cannot explain in
  // the app any more: they message their LGU or send a support ticket.
  const suspended = useAccountSuspended()

  const notices = useQuery({
    queryKey: ['seller-notices'],
    queryFn: async () => (await api.get('/seller/notices')).data,
    retry: false,
    placeholderData: [],
  })

  const { shown: shownNotices, controls: periodControls, periodEmpty } = usePeriodFilter(notices.data, { noun: 'notices issued' })

  const respond = useMutation({
    mutationFn: async ({ id, response }) => (await api.post(`/seller/notices/${id}/respond`, { response })).data,
    onSuccess: (_data, variables) => {
      setDrafts((current) => ({ ...current, [variables.id]: '' }))
      queryClient.invalidateQueries({ queryKey: ['seller-notices'] })
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })

  return (
    <Section title="Notices to Explain">
      <p className="helper-text">
        You get a Notice to Explain if a review of 3 stars or fewer drops your average buyer rating <strong>below 3</strong>, or if your LGU
        finds a buyer&apos;s report against you valid. You are not suspended and your listings stay on the marketplace while you send{' '}
        <strong>one</strong> explanation. If it is <strong>rejected, your seller account is suspended</strong>, and you can no longer explain
        in the app -- message your LGU or send a support ticket. After a rejected explanation, your <strong>next</strong> notice suspends
        you right away with nothing to explain, so contact your LGU or support straight away.
      </p>
      {periodControls}
      {shownNotices.length ? (
        <div className="item-list">
          {shownNotices.map((notice) => {
            const open = ['open', 'under_review'].includes(notice.status)
            const draft = drafts[notice.id] ?? ''
            // Who decided -- the Super Admin can review a notice too.
            const reviewer = notice.reviewed_by_label || 'Your LGU'
            return (
              <div className="card report-card" key={notice.id}>
                <div className="card-row">
                  <strong>{notice.type === 'user_report' ? 'Buyer Report Notice' : 'Low Rating Notice'}</strong>
                  {notice.type !== 'user_report' && <Badge tone="danger">{Number(notice.average_rating || 0).toFixed(2)}/5</Badge>}
                  <ReportStatusBadge status={notice.status} />
                </div>
                <p className="report-description">{notice.details}</p>
                <p className="muted">Issued {new Date(notice.created_at).toLocaleString()}</p>
                <LowReviewsList notice={notice} sellerProfileId={notice.seller_profile_id} />
                {notice.seller_response && (
                  <div className="notice-response">
                    <strong>Your explanation</strong>
                    <p>{notice.seller_response}</p>
                  </div>
                )}
                {notice.lgu_notes && <p className="helper-text"><strong>{reviewer === 'Your LGU' ? 'LGU' : 'Super Admin'} notes:</strong> {notice.lgu_notes}</p>}
                {notice.status === 'accepted' && (
                  <p className="helper-text">{reviewer} accepted this explanation. No offense was recorded.</p>
                )}
                {['rejected', 'repeat_offense'].includes(notice.status) && (
                  <>
                    <p className="error">
                      {notice.status === 'rejected'
                        ? `${reviewer} rejected this explanation, so an offense was recorded and your seller account was suspended.`
                        : 'You already had an explanation rejected before, so this notice is a repeat offense: your seller account was suspended and this notice cannot be answered in the app.'}
                    </p>
                    {notice.reinstated_at ? (
                      <p className="helper-text">
                        {notice.reinstated_by_label || 'Your LGU'} reinstated your account on {new Date(notice.reinstated_at).toLocaleDateString()}. You can sell again; the offense stays on record.
                      </p>
                    ) : (
                      <FinalRejectionHelp base="/seller/dashboard" contact={lguContact} contactLabel="your LGU" topic="Account or login" />
                    )}
                  </>
                )}
                {open && suspended ? (
                  <>
                    <p className="error">Your seller account is suspended, so you can no longer send an explanation here.</p>
                    <FinalRejectionHelp base="/seller/dashboard" contact={lguContact} contactLabel="your LGU" topic="Account or login" />
                  </>
                ) : open && notice.seller_response ? (
                  <p className="helper-text">Your explanation was sent. {reviewer === 'Your LGU' ? 'Your LGU' : 'The Super Admin'} will review it and let you know.</p>
                ) : open ? (
                  <div className="form grid-form">
                    <p className="helper-text">You can send one explanation, so make it complete.</p>
                    <textarea
                      value={draft}
                      onChange={(e) => setDrafts((current) => ({ ...current, [notice.id]: e.target.value }))}
                      placeholder="Explain what happened and what you are doing about it (at least 10 characters)"
                      rows={4}
                    />
                    <button
                      type="button"
                      disabled={draft.trim().length < 10 || respond.isPending}
                      onClick={() => { if (window.confirm('Send this explanation? You cannot change or add to it afterwards.')) respond.mutate({ id: notice.id, response: draft.trim() }) }}
                    >
                      {respond.isPending ? 'Sending...' : 'Send Explanation'}
                    </button>
                  </div>
                ) : ['resolved', 'dismissed'].includes(notice.status) ? (
                  <p className="helper-text">This notice has been closed by {reviewer === 'Your LGU' ? 'your LGU' : 'the Super Admin'}.</p>
                ) : null}
              </div>
            )
          })}
        </div>
      ) : periodEmpty || <EmptyState message="You have no notices. Keep your average rating at 3 stars or above and none will be raised." />}
      {respond.error && <p className="error">{respond.error.response?.data?.message || 'Could not send your explanation.'}</p>}
    </Section>
  )
}