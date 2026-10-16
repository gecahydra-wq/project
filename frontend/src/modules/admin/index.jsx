import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Fish, MapPin, MessageCircle, Store } from 'lucide-react'
import { useRef, useState } from 'react'
import { ActivityLogEntryCard, ActivityLogPanel, ReportExportControls } from '../../components/activity.jsx'
import { ANNOUNCEMENT_MAX_INPUT } from '../../components/announcements.jsx'
import { CategoryBarChart, TimeSeriesChart } from '../../components/charts.jsx'
import { SellerEarningsPanel } from '../../components/earnings.jsx'
import { PasswordVisibilityToggle } from '../../components/forms.jsx'
import { ListingDetailPanel, MarketplaceBrowser } from '../../components/listings.jsx'
import { AccountRemovalAction, ModerationAction, SellerRegistrationQueue, UserReportsPanel } from '../../components/moderation.jsx'
import { SellerNoticesPanel } from '../../components/notices.jsx'
import { MarkAllReadButton, NotificationHistory, NotificationStack, UnreadMessagesNotice } from '../../components/notifications.jsx'
import { AdminOrderTable, OrderDetailPanel, RefundRow } from '../../components/orders.jsx'
import { AdminProfilePanel } from '../../components/profile.jsx'
import { ReviewsAndRatingsSection } from '../../components/reviews.jsx'
import { renderStars } from '../../components/stars.jsx'
import { EmptyState, LoadingState } from '../../components/states.jsx'
import { ActionGroup, Badge, Dashboard, DataTable, PeriodFilter, PeriodFilteredRows, Section, SellerLink, StatsRow, TopPerformerCard } from '../../components/ui.jsx'
import { DisputesPanel, WithdrawalRow } from '../../components/withdrawals.jsx'
import { ANNOUNCEMENT_CATEGORIES, EMPTY_ANNOUNCEMENT_FORM } from '../../lib/announcements.js'
import { api, queryClient } from '../../lib/api.js'
import { speciesChartColor, statusChartColor, statusChartLabel } from '../../lib/chartColors.js'
import { DEFAULT_AVATAR_IMAGE } from '../../lib/constants.js'
import { apiErrorMessage } from '../../lib/errors.js'
import { currency, localDateTimeInputToIso, roleLabel, toLocalDateTimeInput } from '../../lib/format.js'
import { useOrderNumberLookup } from '../../lib/hooks.jsx'
import { mapListing, resolveListingImage } from '../../lib/listing.js'
import { BUYER_REMOVAL_REASONS, BUYER_SUSPENSION_REASONS, SELLER_REMOVAL_REASONS } from '../../lib/moderation.js'
import { notificationLinkFor } from '../../lib/notifications.js'
import { periodLabel } from '../../lib/status.js'
import { PASSWORD_HELP, announcementScheduleIssue, blockSpaceKey, stripSpaces, validateEmail, validatePassword } from '../../lib/validation.js'
import { LguAdminRow } from '../lgu/index.jsx'
import { MessagesPanel } from '../messaging/index.jsx'
import { SupportTicketsAdminPanel } from '../support/index.jsx'



export function SuperAdminListingReviewPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')
  const [showDelete, setShowDelete] = useState(false)
  const [deleteReason, setDeleteReason] = useState('')

  const listingQuery = useQuery({
    queryKey: ['super-admin-listing', id],
    queryFn: async () => mapListing((await api.get(`/super-admin/listings/${id}`)).data),
    retry: false,
  })
  const listing = listingQuery.data

  const sellerQuery = useQuery({
    queryKey: ['super-admin-listing-seller', listing?.seller_profile_id],
    queryFn: async () => (await api.get(`/sellers/${listing.seller_profile_id}`)).data,
    enabled: !!listing?.seller_profile_id,
    retry: false,
  })

  const goToListingManagement = () => {
    queryClient.invalidateQueries({ queryKey: ['super-admin-listings'] })
    navigate('/admin/dashboard?tab=listings')
  }

  const approve = useMutation({
    mutationFn: async () => (await api.patch(`/super-admin/listings/${id}/approve`)).data,
    onSuccess: goToListingManagement,
  })
  const reject = useMutation({
    mutationFn: async () => (await api.patch(`/super-admin/listings/${id}/reject`, { reason: reason.trim() })).data,
    onSuccess: goToListingManagement,
  })
  const destroyListing = useMutation({
    mutationFn: async () => (await api.delete(`/super-admin/listings/${id}`, { data: { reason: deleteReason.trim() } })).data,
    onSuccess: goToListingManagement,
  })
  const confirmDelete = () => {
    if (!deleteReason.trim()) return
    if (!window.confirm(`Delete "${listing.title}"? This cannot be undone.`)) return
    destroyListing.mutate()
  }

  if (listingQuery.isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (listingQuery.isError || !listing) {
    return (
      <main className="auth-page">
        <section className="result-card">
          <h1>Listing not found</h1>
          <p>This listing may have been removed.</p>
          <Link className="button" to="/admin/dashboard?tab=listings">Back to Listing Management</Link>
        </section>
      </main>
    )
  }

  const seller = sellerQuery.data?.seller
  const busy = approve.isPending || reject.isPending || destroyListing.isPending

  return (
    <main>
      <div className="detail-page">
        <img className="detail-art" src={resolveListingImage(listing)} alt={listing.title || listing.species} />
        <div className="detail-stack">
          <ListingDetailPanel item={listing} />
          <p className="helper-text">Listed on {new Date(listing.created_at).toLocaleDateString()} · {listing.municipality}</p>
        </div>
      </div>

      <Section title="Seller Information">
        <section className="card seller-profile-header">
          <div className="seller-header-row">
            <img className="seller-avatar" src={seller?.profile_picture || DEFAULT_AVATAR_IMAGE} alt={`${seller?.hatchery_name || 'Seller'} profile`} />
            <div>
              <div className="card-row">
                <h3><SellerLink id={seller?.id || listing.seller_profile_id} name={seller?.hatchery_name || listing.seller} /></h3>
                {seller?.verified && <Badge tone="success">Verified Seller</Badge>}
              </div>
              <div className="detail-meta">
                {seller?.user?.name && seller.user.name !== seller?.hatchery_name && <span><strong>Seller Name:</strong> {seller.user.name}</span>}
                <span><strong>Municipality:</strong> {seller?.municipality?.name || listing.municipality}</span>
                <span><strong>Rating:</strong> {renderStars(seller?.rating)} {Number(seller?.rating || 0).toFixed(1)}/5</span>
                <span><strong>Contact:</strong> {seller?.user?.phone || 'Not provided'}</span>
                <span><strong>Email:</strong> {seller?.user?.email || 'Not provided'}</span>
              </div>
            </div>
          </div>
        </section>
      </Section>

      {listing.approval_status === 'pending' && (
        <Section title="Moderation Decision">
          <div className="card">
            <div className="row-actions">
              <button type="button" onClick={() => approve.mutate()} disabled={busy}>Approve Listing</button>
              <button type="button" className="ghost danger" onClick={() => setShowReject(!showReject)} disabled={busy}>Reject Listing</button>
            </div>
            {showReject && (
              <div className="form grid-form withdrawal-reject-form">
                <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for rejection (required, shown to the seller)" />
                <button type="button" className="danger" onClick={() => reject.mutate()} disabled={busy || !reason.trim()}>Confirm Reject</button>
              </div>
            )}
            {(approve.error || reject.error) && (
              <p className="error">{approve.error?.response?.data?.message || reject.error?.response?.data?.message || 'Could not update listing.'}</p>
            )}
          </div>
        </Section>
      )}

      <Section title="Remove Listing">
        <div className="card">
          <p className="helper-text">Deleting permanently removes the listing from the marketplace (only possible if it has no orders). The seller is notified.</p>
          <div className="row-actions">
            <button type="button" className="ghost danger" onClick={() => setShowDelete(!showDelete)} disabled={busy}>Delete Listing</button>
          </div>
          {showDelete && (
            <div className="form grid-form withdrawal-reject-form">
              <input value={deleteReason} onChange={(e) => setDeleteReason(e.target.value)} placeholder="Reason for deletion (required)" required />
              <button type="button" className="danger" onClick={confirmDelete} disabled={busy || !deleteReason.trim()}>Confirm Delete</button>
            </div>
          )}
          {destroyListing.error && (
            <p className="error">{destroyListing.error?.response?.data?.message || 'Could not update listing.'}</p>
          )}
        </div>
      </Section>

      <Link className="ghost" to="/admin/dashboard?tab=listings">Back to Listing Management</Link>
    </main>
  )
}

/**
 * Global Order Lookup -- lets the Super Admin investigate any transaction
 * platform-wide by Order Number without navigating the
 * municipality/seller/buyer hierarchy (see SuperAdminController::showOrder /
 * App\Support\OrderTransactionPresenter). Reuses the same search-box hook
 * and OrderDetailPanel as the Seller's own Order Lookup.
 */
function SuperAdminOrderLookup() {
  const { orderNumberInput, setOrderNumberInput, submit, query: lookup } = useOrderNumberLookup(
    (orderNumber) => `/super-admin/orders/${orderNumber}`,
    'super-admin-order-lookup'
  )

  return (
    <Section title="Global Order Lookup">
      <div className="card">
        <form className="order-lookup-form" onSubmit={submit}>
          <input placeholder="Enter Order Number (e.g. FG-AB12CD)" value={orderNumberInput} onChange={(e) => setOrderNumberInput(e.target.value)} />
          <button type="submit">Search</button>
        </form>
        {lookup.isFetching && <LoadingState label="Looking up order..." />}
        {lookup.isError && <p className="error">{lookup.error?.response?.data?.message || 'Order not found.'}</p>}
        {lookup.data && <OrderDetailPanel detail={lookup.data} />}
      </div>
    </Section>
  )
}

/**
 * Super Admin Announcements CRUD -- create/edit/delete (see
 * AnnouncementController). Creating (or updating into) a past-or-immediate
 * starts_at fires the notification fan-out right away on the backend; a
 * future starts_at is picked up later by the scheduled
 * announcements:publish command.
 */
function SuperAdminAnnouncements() {
  const [form, setForm] = useState(EMPTY_ANNOUNCEMENT_FORM)
  const [original, setOriginal] = useState(EMPTY_ANNOUNCEMENT_FORM)
  const [editingId, setEditingId] = useState(null)
  const startsAtRef = useRef(null)
  const expiresAtRef = useRef(null)
  // Real date problems (past dates, end before start, year after 2100) gray out Publish.
  const scheduleIssue = announcementScheduleIssue(form, original)

  // A half-typed date makes the browser report an EMPTY value, which would
  // otherwise publish as "no date". No message for it; the click just moves
  // the cursor back to the unfinished field.
  const isIncompleteDate = (input) => Boolean(input && input.value === '' && input.validity.badInput)

  const publish = () => {
    // The date inputs themselves are the source of truth at click time, so a
    // date the form state never heard about (e.g. set by a native picker) is
    // still validated and sent, never silently published as "no end date".
    const current = {
      ...form,
      starts_at: startsAtRef.current ? startsAtRef.current.value : form.starts_at,
      expires_at: expiresAtRef.current ? expiresAtRef.current.value : form.expires_at,
    }
    setForm(current)
    const unfinished = [startsAtRef.current, expiresAtRef.current].find(isIncompleteDate)
    if (unfinished) {
      unfinished.focus()
      return
    }
    if (announcementScheduleIssue(current, original)) return
    save.mutate(current)
  }

  const list = useQuery({
    queryKey: ['super-admin-announcements'],
    queryFn: async () => (await api.get('/super-admin/announcements')).data,
    retry: false,
    placeholderData: [],
  })

  const resetForm = () => {
    setForm(EMPTY_ANNOUNCEMENT_FORM)
    setOriginal(EMPTY_ANNOUNCEMENT_FORM)
    setEditingId(null)
  }

  const save = useMutation({
    mutationFn: async (values) => {
      const payload = {
        title: values.title,
        body: values.body,
        category: values.category,
        starts_at: localDateTimeInputToIso(values.starts_at),
        expires_at: localDateTimeInputToIso(values.expires_at),
      }
      return editingId
        ? (await api.patch(`/super-admin/announcements/${editingId}`, payload)).data
        : (await api.post('/super-admin/announcements', payload)).data
    },
    onSuccess: () => {
      resetForm()
      queryClient.invalidateQueries({ queryKey: ['super-admin-announcements'] })
      queryClient.invalidateQueries({ queryKey: ['announcements-active'] })
    },
  })

  const remove = useMutation({
    mutationFn: async (id) => (await api.delete(`/super-admin/announcements/${id}`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-announcements'] })
      queryClient.invalidateQueries({ queryKey: ['announcements-active'] })
    },
  })

  const startEdit = (a) => {
    const loaded = {
      title: a.title,
      body: a.body,
      category: a.category,
      starts_at: toLocalDateTimeInput(a.starts_at),
      expires_at: toLocalDateTimeInput(a.expires_at),
    }
    setEditingId(a.id)
    setForm(loaded)
    setOriginal(loaded)
  }

  return (
    <>
      <Section title={editingId ? 'Edit Announcement' : 'Create Announcement'}>
        <div className="form grid-form">
          <input placeholder="Title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
            {ANNOUNCEMENT_CATEGORIES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
          <label className="profile-field">
            <span className="profile-field-label">Starts at</span>
            <input
              ref={startsAtRef}
              type="datetime-local"
              value={form.starts_at}
              min={toLocalDateTimeInput(new Date())}
              max={ANNOUNCEMENT_MAX_INPUT}
              onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
            />
            <span className="profile-field-hint">Optional. Leave blank to publish right away.</span>
          </label>
          <label className="profile-field">
            <span className="profile-field-label">Ends at</span>
            <input
              ref={expiresAtRef}
              type="datetime-local"
              value={form.expires_at}
              min={form.starts_at || toLocalDateTimeInput(new Date())}
              max={ANNOUNCEMENT_MAX_INPUT}
              onChange={(e) => setForm({ ...form, expires_at: e.target.value })}
            />
            <span className="profile-field-hint">Optional. Leave blank to keep it up until you delete it.</span>
          </label>
          <textarea placeholder="Announcement body" value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
        </div>
        {scheduleIssue && <p className="error" role="alert">{scheduleIssue}</p>}
        <p className="helper-text">Leave Starts at blank to notify Buyers, Sellers, and LGU Admins immediately. Leave Ends at blank for no expiration.</p>
        <button type="button" onClick={publish} disabled={save.isPending || !form.title || !form.body || Boolean(scheduleIssue)}>
          {save.isPending ? 'Saving...' : editingId ? 'Update Announcement' : 'Publish Announcement'}
        </button>
        {editingId && <button type="button" className="ghost" onClick={resetForm}>Cancel</button>}
        {save.error && <p className="error">{save.error.response?.data?.message || 'Could not save announcement.'}</p>}
      </Section>
      <Section title="All Announcements">
        <PeriodFilteredRows rows={list.data || []} noun="announcements created">
          {(shown) => (shown.length ? (
          <div className="item-list">
            {shown.map((a) => (
              <div className="card action" key={a.id}>
                <div>
                  <div className="card-row"><strong>{a.title}</strong><Badge tone="neutral">{ANNOUNCEMENT_CATEGORIES.find(([value]) => value === a.category)?.[1] || a.category}</Badge></div>
                  <p>{a.body}</p>
                  <p className="muted">
                    {a.starts_at ? `Starts ${new Date(a.starts_at).toLocaleString()}` : 'Starts immediately'}
                    {a.expires_at ? ` · Expires ${new Date(a.expires_at).toLocaleString()}` : ' · No expiration'}
                    {' · '}{a.notified_at ? 'Notifications sent' : 'Notifications pending'}
                  </p>
                </div>
                <div className="row-actions">
                  <button type="button" className="ghost" onClick={() => startEdit(a)}>Edit</button>
                  <button type="button" className="ghost danger" onClick={() => remove.mutate(a.id)}>Delete</button>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState message="No announcements yet." />)}
        </PeriodFilteredRows>
      </Section>
    </>
  )
}

/**
 * Minimal Municipality creation -- municipalities were previously only ever
 * seeded (see SuperAdminController::storeMunicipality); this adds a runtime
 * way to create one, purely additive to the existing read-only list below.
 */
function MunicipalityCreateForm() {
  const [form, setForm] = useState({ name: '', province: '' })

  const create = useMutation({
    mutationFn: async () => (await api.post('/super-admin/municipalities', form)).data,
    onSuccess: () => {
      setForm({ name: '', province: '' })
      queryClient.invalidateQueries({ queryKey: ['municipalities'] })
    },
  })

  return (
    <Section title="Add Municipality">
      <div className="form grid-form">
        <input placeholder="Municipality name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <input placeholder="Province (optional)" value={form.province} onChange={(e) => setForm({ ...form, province: e.target.value })} />
      </div>
      <button type="button" onClick={() => create.mutate()} disabled={create.isPending || !form.name}>
        {create.isPending ? 'Adding...' : 'Add Municipality'}
      </button>
      {create.error && <p className="error">{create.error.response?.data?.message || 'Could not add municipality.'}</p>}
    </Section>
  )
}

export function SuperAdminDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [lguForm, setLguForm] = useState({ name: '', email: '', password: '', password_confirmation: '', municipality_id: '' })
  const [showLguPasswords, setShowLguPasswords] = useState(false)
  const [lguFormError, setLguFormError] = useState('')
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const dashboard = useQuery({
    queryKey: ['super-admin-dashboard'],
    queryFn: async () => (await api.get('/super-admin/dashboard')).data,
    retry: false,
    refetchInterval: 30000,
    placeholderData: { lgu_admins: 8, total_sellers: 142, transactions: [], platform_revenue: null, pending_seller_withdrawals: 0, pending_lgu_withdrawals: 0, completed_seller_withdrawals: 0, completed_lgu_withdrawals: 0 },
  })
  const [reportsPeriod, setReportsPeriod] = useState('monthly')
  const [reportsModerationRole, setReportsModerationRole] = useState('')
  const [reportsModerationStatus, setReportsModerationStatus] = useState('')
  const reports = useQuery({
    queryKey: ['super-admin-reports', reportsPeriod, reportsModerationRole, reportsModerationStatus],
    queryFn: async () => (await api.get('/super-admin/reports', { params: { period: reportsPeriod, moderation_role: reportsModerationRole || undefined, moderation_status: reportsModerationStatus || undefined } })).data,
    retry: false,
    placeholderData: {
      total_lgus: 8, total_sellers: 142, total_buyers: 1240, total_listings: 87, total_transactions: 18, pending_payouts: 18, transactions: [], lgu_admins: [],
      listings_by_status: [], listings_by_species: [], sellers_by_status: [], orders_over_time: [], listings_by_municipality: [], sellers_by_municipality: [], orders_by_municipality: [],
      revenue_cards: null, platform_revenue_over_time: [], gross_revenue_over_time: [], revenue_by_municipality: [], revenue_by_species: [], revenue_by_seller: [], commission_distribution: [],
      moderation_summary: null, moderation_actions_over_time: [], moderation_log: [],
    },
  })
  const lguAdmins = useQuery({
    queryKey: ['super-admin-lgu-admins'],
    queryFn: async () => (await api.get('/super-admin/lgu-admins')).data,
    retry: false,
    placeholderData: [],
  })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
    placeholderData: [],
  })
  const sellersQuery = useQuery({
    queryKey: ['super-admin-sellers'],
    queryFn: async () => (await api.get('/super-admin/sellers')).data,
    retry: false,
    placeholderData: [],
  })
  const withdrawals = useQuery({
    queryKey: ['super-admin-withdrawals'],
    queryFn: async () => (await api.get('/super-admin/withdrawals')).data,
    retry: false,
    placeholderData: [],
    refetchInterval: 30000,
  })
  const approveWithdrawal = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/withdrawals/${id}/approve`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-withdrawals'] }),
  })
  const rejectWithdrawal = useMutation({
    mutationFn: async ({ id, reason }) => (await api.patch(`/super-admin/withdrawals/${id}/reject`, { reason })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-withdrawals'] }),
  })
  const sendWithdrawalReceipt = useMutation({
    mutationFn: async ({ id, note, attachment }) => {
      const form = new FormData()
      form.append('payout_sent', '1')
      if (note.trim()) form.append('note', note.trim())
      if (attachment) form.append('attachment', attachment)
      return (await api.post(`/super-admin/withdrawals/${id}/send-receipt`, form)).data
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-withdrawals'] }),
  })
  const refunds = useQuery({
    queryKey: ['super-admin-refunds'],
    queryFn: async () => (await api.get('/super-admin/refunds')).data,
    retry: false,
    placeholderData: [],
  })
  const markRefunded = useMutation({
    mutationFn: async ({ id, reference }) => (await api.patch(`/super-admin/refunds/${id}/refunded`, { reference })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-refunds'] }),
  })
  const lguWithdrawals = useQuery({
    queryKey: ['super-admin-lgu-withdrawals'],
    queryFn: async () => (await api.get('/super-admin/lgu-withdrawals')).data,
    retry: false,
    placeholderData: [],
    refetchInterval: 30000,
  })
  const approveLguWithdrawal = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/lgu-withdrawals/${id}/approve`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-withdrawals'] }),
  })
  const rejectLguWithdrawal = useMutation({
    mutationFn: async ({ id, reason }) => (await api.patch(`/super-admin/lgu-withdrawals/${id}/reject`, { reason })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-withdrawals'] }),
  })
  const sendLguWithdrawalReceipt = useMutation({
    mutationFn: async ({ id, note, attachment }) => {
      const form = new FormData()
      form.append('payout_sent', '1')
      if (note.trim()) form.append('note', note.trim())
      if (attachment) form.append('attachment', attachment)
      return (await api.post(`/super-admin/lgu-withdrawals/${id}/send-receipt`, form)).data
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-withdrawals'] }),
  })
  const createLguAdmin = useMutation({
    mutationFn: async (payload) => (await api.post('/super-admin/lgu-admins', payload)).data,
    onSuccess: () => {
      setLguForm({ name: '', email: '', password: '', password_confirmation: '', municipality_id: '' })
      setLguFormError('')
      queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] })
    },
  })
  const submitLguAdmin = () => {
    // Same email + password validation as normal registration.
    const emailError = validateEmail(lguForm.email)
    if (emailError) {
      setLguFormError(emailError)
      return
    }
    const pwError = validatePassword(lguForm.password)
    if (pwError) {
      setLguFormError(pwError)
      return
    }
    if (lguForm.password !== lguForm.password_confirmation) {
      setLguFormError('The passwords do not match.')
      return
    }
    setLguFormError('')
    // password_confirmation is a UI-only guard; the API takes just the password.
    createLguAdmin.mutate({
      name: lguForm.name,
      email: (lguForm.email || '').trim(),
      password: lguForm.password,
      municipality_id: lguForm.municipality_id,
    })
  }
  const updateLguAdmin = useMutation({
    mutationFn: async ({ id, payload }) => (await api.patch(`/super-admin/lgu-admins/${id}`, payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] }),
  })
  const disableLguAdmin = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/super-admin/lgu-admins/${id}/disable`, { reason, notes })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
    },
  })
  const enableLguAdmin = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/super-admin/lgu-admins/${id}/enable`, { reason, notes })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
    },
  })
  const suspendBuyer = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/super-admin/buyers/${id}/suspend`, { reason, notes })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
    },
  })
  const reinstateBuyer = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/super-admin/buyers/${id}/reinstate`, { reason, notes })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
    },
  })
  const suspendSellerGlobal = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/super-admin/sellers/${id}/suspend`, { reason, notes })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-sellers'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
    },
  })
  const reinstateSellerGlobal = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/super-admin/sellers/${id}/reinstate`, { reason, notes })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-sellers'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
    },
  })
  const removeBuyer = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.delete(`/super-admin/buyers/${id}`, { data: { reason, notes } })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-activity-log'] })
    },
  })
  const removeSeller = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.delete(`/super-admin/sellers/${id}`, { data: { reason, notes } })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin-sellers'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['super-admin-activity-log'] })
    },
  })
  const listingManagement = useQuery({
    queryKey: ['super-admin-listings'],
    queryFn: async () => (await api.get('/super-admin/listings')).data,
    retry: false,
    placeholderData: [],
  })
  const usersQuery = useQuery({
    queryKey: ['super-admin-users'],
    queryFn: async () => (await api.get('/super-admin/users')).data,
    retry: false,
    placeholderData: { buyers: [] },
  })
  const reviews = useQuery({
    queryKey: ['super-admin-reviews'],
    queryFn: async () => (await api.get('/super-admin/reviews')).data,
    retry: false,
    placeholderData: { buyer_reviews: [], seller_ratings: [] },
  })
  const notificationsQuery = useQuery({
    queryKey: ['super-admin-notifications'],
    queryFn: async () => (await api.get('/super-admin/notifications')).data,
    retry: false,
    placeholderData: [],
  })
  const notifications = (notificationsQuery.data || []).filter((notification) => !visibleNotificationIds.includes(notification.id))
  const markNotificationRead = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/notifications/${id}/read`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-notifications'] }),
  })
  const markAllNotificationsRead = useMutation({
    mutationFn: async () => (await api.patch('/super-admin/notifications/read-all')).data,
    onSuccess: () => {
      setVisibleNotificationIds((current) => [...current, ...notifications.map((n) => n.id)])
      queryClient.invalidateQueries({ queryKey: ['super-admin-notifications'] })
    },
  })
  const handleMarkRead = (id) => {
    setVisibleNotificationIds((current) => (current.includes(id) ? current : [...current, id]))
    markNotificationRead.mutate(id)
  }

  return (
    <Dashboard
      title="Super Admin Dashboard"
      subtitle="Platform-wide control, transaction review, and payout release."
    >
      {tab === 'overview' && (
        <>
          <UnreadMessagesNotice dashboardPath="/admin/dashboard" />
          {/* Executive at-a-glance -- today's pulse and GROSS marketplace
              revenue (today / month / all-time). These are the full buyer-paid
              value, NOT the platform's own income; the Super Admin's actual
              revenue (its 4% share of each settled order) is the "Platform Revenue" cards in the
              Marketplace Revenue section below. Labels say "Gross" so the top
              figures are never mistaken for the platform's cut. */}
          <StatsRow items={[
            ["Today's Orders", dashboard.data?.executive?.todays_orders ?? 0, true, '/admin/dashboard?tab=transactions'],
            ["Today's Gross Revenue", currency(dashboard.data?.executive?.todays_gross_revenue ?? 0), true, '/admin/dashboard?tab=reports'],
            ['Monthly Gross Revenue', currency(dashboard.data?.executive?.monthly_gross_revenue ?? 0), true, '/admin/dashboard?tab=reports'],
            ['Gross Marketplace Revenue (All-Time)', currency(dashboard.data?.platform_revenue?.gross_marketplace_revenue ?? 0), false, '/admin/dashboard?tab=reports'],
          ]} />
          <StatsRow items={[
            ['Total LGUs', reports.data?.total_lgus ?? 0, false, '/admin/dashboard?tab=lgu-admins&focus=registered-lgu-admins'],
            ['Total Sellers', reports.data?.total_sellers ?? 0, false, '/admin/dashboard?tab=sellers&focus=all-sellers'],
            ['Total Buyers', reports.data?.total_buyers ?? 0, false, '/admin/dashboard?tab=users'],
            ['Total Settled Orders', dashboard.data?.platform_revenue?.total_settled_orders ?? 0, false, '/admin/dashboard?tab=earnings'],
          ]} />
          <Section title="Action Required">
            <p className="helper-text">Everything waiting on the Super Admin or an LGU across the platform, grouped by what it is about. Click a number to open the page that handles it.</p>
            <div className="action-groups">
              <ActionGroup
                title="Seller registrations and listings"
                items={[
                  ['Pending Seller Approvals', dashboard.data?.pending_seller_approvals ?? 0, '/admin/dashboard?tab=sellers'],
                  ['Pending Listing Approvals', dashboard.data?.pending_listing_approvals ?? 0, '/admin/dashboard?tab=listings'],
                ]}
              />
              <ActionGroup
                title="Seller earnings"
                action={['Go to Seller Earnings', '/admin/dashboard?tab=earnings']}
                items={[
                  ['Earnings Awaiting LGU Approval', dashboard.data?.pending_lgu_approvals ?? 0, '/admin/dashboard?tab=earnings'],
                ]}
              />
              <ActionGroup
                title="Reports, notices and disputes"
                items={[
                  ['Open User Reports', dashboard.data?.open_user_reports ?? 0, '/admin/dashboard?tab=user-reports'],
                  ['Open Notices to Explain', dashboard.data?.open_seller_notices ?? 0, '/admin/dashboard?tab=notices'],
                  ['Explanations to Review', dashboard.data?.notice_explanations_to_review ?? 0, '/admin/dashboard?tab=notices'],
                  ['Pending Seller Disputes', dashboard.data?.pending_seller_disputes ?? 0, '/admin/dashboard?tab=disputes'],
                ]}
              />
              <ActionGroup
                title="Payouts"
                action={['Manage Payouts', '/admin/dashboard?tab=payouts']}
                items={[
                  ['Pending Seller Withdrawals', dashboard.data?.pending_seller_withdrawals ?? 0, '/admin/dashboard?tab=payouts&focus=seller-payouts'],
                  ['Pending LGU Withdrawals', dashboard.data?.pending_lgu_withdrawals ?? 0, '/admin/dashboard?tab=payouts&focus=lgu-payouts'],
                ]}
              />
            </div>
          </Section>
          <Section title="Marketplace Revenue" actions={<Link className="ghost" to="/admin/dashboard?tab=reports">View Analytics</Link>}>
            <p className="helper-text">Platform Revenue is the platform&apos;s 4% share of every order, recognized when the LGU approves the seller&apos;s earnings (the seller keeps 94%, the LGU 2%). It also includes the 6% payout fees on withdrawals requested before October 9, 2026. Gross Marketplace Revenue is the full value paid by buyers before revenue sharing, recognized at settlement.</p>
            <StatsRow items={[
              ["Today's Platform Revenue", currency(dashboard.data?.platform_revenue?.today_platform_revenue ?? 0), false, '/admin/dashboard?tab=reports'],
              ['Monthly Platform Revenue', currency(dashboard.data?.platform_revenue?.monthly_platform_revenue ?? 0), false, '/admin/dashboard?tab=reports'],
              ['Total Platform Revenue', currency(dashboard.data?.platform_revenue?.total_platform_revenue ?? 0), false, '/admin/dashboard?tab=reports'],
              ['Avg Realized Revenue / Settled Order', currency(dashboard.data?.platform_revenue?.average_platform_revenue_per_order ?? 0), false, '/admin/dashboard?tab=reports'],
            ]} />
          </Section>
          <Section title="Top Performers">
            <p className="helper-text">Leading municipality, seller, and species by gross settled marketplace value, all-time.</p>
            <div className="top-performers">
              <TopPerformerCard eyebrow="Top Municipality" icon={MapPin} performer={dashboard.data?.executive?.top_municipality} />
              <TopPerformerCard eyebrow="Top Seller" icon={Store} performer={dashboard.data?.executive?.top_seller} />
              <TopPerformerCard eyebrow="Top Fish Species" icon={Fish} performer={dashboard.data?.executive?.top_species} />
            </div>
          </Section>
          <Section title="Account Moderation" actions={<Link className="ghost" to="/admin/dashboard?tab=activity-log&category=moderation">View Moderation History</Link>}>
            <StatsRow items={[
              ['Active Buyers', dashboard.data?.active_buyers ?? 0, false, '/admin/dashboard?tab=users'],
              ['Suspended Buyers', dashboard.data?.suspended_buyers ?? 0, false, '/admin/dashboard?tab=users'],
              ['Active Sellers', dashboard.data?.active_sellers ?? 0, false, '/admin/dashboard?tab=sellers&focus=all-sellers'],
              ['Suspended Sellers', dashboard.data?.suspended_sellers ?? 0, false, '/admin/dashboard?tab=sellers&focus=all-sellers'],
              ['Active LGU Admins', dashboard.data?.active_lgu_admins ?? 0, false, '/admin/dashboard?tab=lgu-admins&focus=registered-lgu-admins'],
              ['Suspended LGU Admins', dashboard.data?.suspended_lgu_admins ?? 0, false, '/admin/dashboard?tab=lgu-admins&focus=registered-lgu-admins'],
            ]} />
            {(dashboard.data?.recent_moderation_actions || []).length ? (
              <div className="item-list">
                {dashboard.data.recent_moderation_actions.map((log) => (
                  <div className="card" key={log.id}>
                    <div className="card-row"><strong>{log.user?.name || 'Unknown account'}</strong><Badge status={log.action === 'suspended' ? 'suspended' : 'active'} /></div>
                    <p>{roleLabel(log.role)} · {log.action === 'suspended' ? 'Suspended' : 'Reinstated'} by {log.moderator?.name || 'Unknown'}{log.reason ? ` · ${log.reason}` : ''}</p>
                    <p className="muted">{log.created_at ? new Date(log.created_at).toLocaleString() : ''}</p>
                  </div>
                ))}
              </div>
            ) : <EmptyState message="No moderation actions yet." />}
          </Section>
          <Section title="Recent Activity" actions={<Link className="ghost" to="/admin/dashboard?tab=activity-log">View Activity Log</Link>}>
            {(dashboard.data?.recent_activity || []).length ? (
              <div className="item-list">
                {dashboard.data.recent_activity.map((entry) => (
                  <ActivityLogEntryCard key={entry.id} scope="super-admin" entry={entry} />
                ))}
              </div>
            ) : <EmptyState message="No recent activity yet." />}
          </Section>
        </>
      )}
      {tab === 'marketplace' && (
        <Section title="Marketplace">
          <p className="helper-text">Browse the platform-wide marketplace for moderation and testing. This is read-only -- purchasing is a buyer-only action.</p>
          <MarketplaceBrowser detailPath={(item) => `/admin/listings/${item.id}`} />
        </Section>
      )}
      {tab === 'listings' && (
        <Section title="Listing Management">
          <p className="helper-text">All listings platform-wide, across every municipality. Listings go live as soon as they are posted. Open one to review it, or delete it if it breaks the rules.</p>
          {(listingManagement.data || []).length ? (
            <div className="item-list">
              {listingManagement.data.map((item) => (
                <div className="card action" key={item.id}>
                  <div>
                    <div className="card-row"><Link className="seller-name-link" to={`/admin/listings/${item.id}`}><strong>{item.title}</strong></Link>{item.approval_status !== 'approved' && <Badge status={item.approval_status} />}</div>
                    <p><SellerLink id={item.seller_profile_id} name={item.sellerProfile?.hatchery_name} /> · {item.species} · {item.municipality?.name}</p>
                  </div>
                  <div className="row-actions">
                    <Link className="ghost" to={`/admin/listings/${item.id}`}>Manage</Link>
                  </div>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No listings on the platform yet." />}
        </Section>
      )}
      {tab === 'users' && (
        <Section title="Buyers (Platform-Wide)">
          <p className="helper-text">Suspending a buyer blocks placing orders, payments, messaging, reviews, and contacting sellers -- they can still log in, and they are notified with the reason. Existing completed orders are unaffected. Reinstating needs a reason. Removing deletes the account permanently and is only possible for buyers with no order history; suspend anyone who has already traded.</p>
          {(usersQuery.data?.buyers || []).length ? (
            <div className="item-list">
              {usersQuery.data.buyers.map((user) => (
                <div className="card action" key={user.id}>
                  <div>
                    <div className="card-row"><strong>{user.name}</strong><Badge status={user.status === 'suspended' ? 'suspended' : 'active'} /></div>
                    <p>{user.email} · {user.phone || 'Not Available'}</p>
                    <p className="muted">Joined {user.created_at ? new Date(user.created_at).toLocaleDateString() : 'Not Available'}</p>
                  </div>
                  <div className="row-actions">
                    <Link className="ghost" to={`/admin/dashboard?tab=messages&with=${user.id}`}><MessageCircle size={16} /> Message</Link>
                    <ModerationAction
                      suspended={user.status === 'suspended'}
                      reasons={BUYER_SUSPENSION_REASONS}
                      onSuspend={(reason, notes) => suspendBuyer.mutate({ id: user.id, reason, notes })}
                      onReinstate={(reason, notes) => reinstateBuyer.mutate({ id: user.id, reason, notes })}
                    />
                    <AccountRemovalAction
                      accountName={user.name}
                      reasons={BUYER_REMOVAL_REASONS}
                      removing={removeBuyer.isPending && removeBuyer.variables?.id === user.id}
                      error={removeBuyer.variables?.id === user.id ? removeBuyer.error?.response?.data?.message : null}
                      onRemove={(reason, notes) => removeBuyer.mutate({ id: user.id, reason, notes })}
                    />
                  </div>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No buyers registered yet." />}
        </Section>
      )}
      {tab === 'municipalities' && (
        <>
          <MunicipalityCreateForm />
          <Section title="Municipalities">
            <DataTable rows={(municipalitiesQuery.data || []).map((m) => ({ name: m.name, province: m.province || 'Not Available' }))} />
          </Section>
        </>
      )}
      {tab === 'messages' && <Section title="Messages"><MessagesPanel initialUserId={searchParams.get('with') ? Number(searchParams.get('with')) : null} /></Section>}
      {tab === 'notifications' && (
        <>
          <Section
            title="Notifications"
            actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllNotificationsRead.isPending} onClick={() => markAllNotificationsRead.mutate()} />}
          >
            <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} getLink={notificationLinkFor('super_admin')} emptyMessage="No unread notifications." />
          </Section>
          <NotificationHistory getLink={notificationLinkFor('super_admin')} unreadCount={notificationsQuery.data?.length ?? 0} />
        </>
      )}
      {/* The old Moderation Log page is now the Activity Log's Moderation tab. */}
      {tab === 'moderation' && <Navigate to="/admin/dashboard?tab=activity-log&category=moderation" replace />}
      {tab === 'reviews' && <ReviewsAndRatingsSection data={reviews.data} scope="super-admin" scopeLabel="on the platform" />}
      {tab === 'activity-log' && <ActivityLogPanel scope="super-admin" />}
      {tab === 'profile' && <AdminProfilePanel endpointBase="/super-admin" />}
      {tab === 'announcements' && <SuperAdminAnnouncements />}
      {tab === 'lgu-admins' && (
        <>
          <Section title="Add LGU Admin">
            <div className="form grid-form">
              <input value={lguForm.name} onChange={(e) => setLguForm({ ...lguForm, name: e.target.value })} placeholder="Full name" />
              <input value={lguForm.email} onChange={(e) => setLguForm({ ...lguForm, email: e.target.value })} placeholder="Email" />
              <input value={lguForm.password} onChange={(e) => setLguForm({ ...lguForm, password: stripSpaces(e.target.value) })} onKeyDown={blockSpaceKey} type={showLguPasswords ? 'text' : 'password'} placeholder="Temporary password" />
              <input value={lguForm.password_confirmation} onChange={(e) => setLguForm({ ...lguForm, password_confirmation: stripSpaces(e.target.value) })} onKeyDown={blockSpaceKey} type={showLguPasswords ? 'text' : 'password'} placeholder="Confirm temporary password" />
              <select value={lguForm.municipality_id} onChange={(e) => setLguForm({ ...lguForm, municipality_id: e.target.value })}>
                <option value="">Select municipality</option>
                {(municipalitiesQuery.data || []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <PasswordVisibilityToggle shown={showLguPasswords} onToggle={() => setShowLguPasswords(!showLguPasswords)} />
            <p className="helper-text">{PASSWORD_HELP}</p>
            <p className="helper-text">Give this temporary password to the LGU Admin directly. It is stored as a one-way hash, so it can never be read back from this page -- if it is lost, the account has to reset it through Forgot Password.</p>
            <button type="button" onClick={submitLguAdmin}>Create LGU Admin</button>
            {lguFormError && <p className="error">{lguFormError}</p>}
            {createLguAdmin.error && <p className="error">{apiErrorMessage(createLguAdmin.error, 'Could not create LGU admin.')}</p>}
          </Section>
          <Section title="Registered LGU Admins" id="registered-lgu-admins">
            {lguAdmins.data?.length ? (
              <div className="item-list">
                {lguAdmins.data.map((admin) => (
                  <LguAdminRow
                    key={admin.id}
                    admin={admin}
                    municipalities={municipalitiesQuery.data || []}
                    onUpdate={(id, payload) => updateLguAdmin.mutateAsync({ id, payload })}
                    onDisable={(id, reason, notes) => disableLguAdmin.mutate({ id, reason, notes })}
                    onEnable={(id, reason, notes) => enableLguAdmin.mutate({ id, reason, notes })}
                  />
                ))}
              </div>
            ) : <EmptyState message="No LGU admins registered yet." />}
          </Section>
        </>
      )}
      {tab === 'sellers' && (
        <>
          <SellerRegistrationQueue
            endpointBase="/super-admin"
            queryKey="super-admin-seller-registrations"
            stageLabel="Seller registrations awaiting review across every municipality. These are normally handled by each municipality's own LGU Admin -- approve one here when that LGU Admin is unavailable. Approving verifies the seller and lets them start creating listings."
            approveLabel="Approve Registration"
            emptyMessage="No seller registrations awaiting review."
            extraInvalidateKeys={['super-admin-sellers', 'super-admin-dashboard']}
          />
          <Section title="All Sellers (Platform-Wide)" id="all-sellers">
          <p className="helper-text">Super Admin may suspend any seller regardless of municipality. Suspended sellers cannot create, edit, or publish listings, receive new orders, update orders, request withdrawals, or message buyers -- they can still log in to see why, send a support ticket, or file a dispute. Existing completed orders are unaffected. Reinstating needs a reason. Removing deletes the account and its listings permanently and is only possible for sellers with no order history; suspend anyone who has already traded.</p>
          {(sellersQuery.data || []).length ? (
            <div className="item-list">
              {sellersQuery.data.map((seller) => (
                <div className="card action" key={seller.id}>
                  <div>
                    <div className="card-row">
                      <strong><SellerLink seller={seller} name={seller.hatchery_name} /></strong>
                      <Badge status={seller.status} />
                      <Badge status={seller.approval_status}>{seller.approval_status_label}</Badge>
                    </div>
                    <p>{seller.municipality?.name || 'Unknown'} · {seller.verified ? 'Verified' : 'Not verified'} · {seller.listings?.length ?? 0} listings</p>
                    <p className="muted">{seller.reviews_count > 0 ? <>Seller rating: {renderStars(seller.rating)} {Number(seller.rating).toFixed(1)}/5 · {seller.reviews_count} rating{seller.reviews_count === 1 ? '' : 's'}</> : 'No seller ratings yet'}</p>
                  </div>
                  <div className="row-actions">
                    {seller.user_id && <Link className="ghost" to={`/admin/dashboard?tab=messages&with=${seller.user_id}`}><MessageCircle size={16} /> Message</Link>}
                    <ModerationAction
                      suspended={seller.status === 'suspended'}
                      onSuspend={(reason, notes) => suspendSellerGlobal.mutate({ id: seller.id, reason, notes })}
                      onReinstate={(reason, notes) => reinstateSellerGlobal.mutate({ id: seller.id, reason, notes })}
                    />
                    <AccountRemovalAction
                      accountName={seller.hatchery_name}
                      reasons={SELLER_REMOVAL_REASONS}
                      removing={removeSeller.isPending && removeSeller.variables?.id === seller.id}
                      error={removeSeller.variables?.id === seller.id ? removeSeller.error?.response?.data?.message : null}
                      onRemove={(reason, notes) => removeSeller.mutate({ id: seller.id, reason, notes })}
                    />
                  </div>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No sellers on the platform yet." />}
          </Section>
        </>
      )}
      {tab === 'user-reports' && (
        <UserReportsPanel
          endpointBase="/super-admin"
          queryKey="super-admin-user-reports"
          scopeLabel="Every complaint filed across the platform, in every municipality. LGU Admins handle the reports for their own municipality; you can act on any of them. Reviewing a report never changes an account's standing on its own -- suspend from the Sellers or Users tab if that is what the case calls for."
        />
      )}
      {tab === 'notices' && <SellerNoticesPanel scope="super_admin" />}
      {tab === 'disputes' && <DisputesPanel scope="super-admin" />}
      {tab === 'support' && <SupportTicketsAdminPanel scope="super-admin" />}
      {tab === 'earnings' && <SellerEarningsPanel scope="super-admin" />}
      {tab === 'transactions' && (
        <>
          <SuperAdminOrderLookup />
          <Section title="All Orders">
            <AdminOrderTable rows={dashboard.data?.transactions || []} base="/super-admin" invalidateKeys={['super-admin-dashboard']} />
          </Section>
        </>
      )}
      {tab === 'payouts' && (
        <>
        <Section title="Seller Payouts" id="seller-payouts">
          <p className="helper-text">Approve the request and send the payout through Messages (a screenshot is optional). The seller’s Money Received confirmation completes the withdrawal.</p>
          <PeriodFilteredRows rows={withdrawals.data || []} noun="withdrawal requests made">
            {(shown) => (shown.length ? (
            <div className="item-list">
              {shown.map((request) => (
                <WithdrawalRow
                  key={request.id}
                  request={request}
                  onApprove={(id) => approveWithdrawal.mutateAsync(id)}
                  onReject={(id, reason) => rejectWithdrawal.mutateAsync({ id, reason })}
                  onSendReceipt={(id, payload) => sendWithdrawalReceipt.mutateAsync({ id, ...payload })}
                />
              ))}
            </div>
          ) : <EmptyState message="No withdrawal requests yet." />)}
          </PeriodFilteredRows>
        </Section>
        <Section title="LGU Payouts" id="lgu-payouts">
          <p className="helper-text">Approve the request and send the payout through Messages (a screenshot is optional). An LGU admin’s Money Received confirmation completes the withdrawal.</p>
          <PeriodFilteredRows rows={lguWithdrawals.data || []} noun="withdrawal requests made">
            {(shown) => (shown.length ? (
            <div className="item-list">
              {shown.map((request) => (
                <WithdrawalRow
                  key={request.id}
                  request={request}
                  type="lgu"
                  onApprove={(id) => approveLguWithdrawal.mutateAsync(id)}
                  onReject={(id, reason) => rejectLguWithdrawal.mutateAsync({ id, reason })}
                  onSendReceipt={(id, payload) => sendLguWithdrawalReceipt.mutateAsync({ id, ...payload })}
                />
              ))}
            </div>
          ) : <EmptyState message="No LGU withdrawal requests yet." />)}
          </PeriodFilteredRows>
        </Section>
        <Section title="Refunds">
          <p className="helper-text">Paid orders that were cancelled or expired, including every unfinished order of a seller who was suspended. Refund the buyer in the PayMongo dashboard, then mark it refunded here.</p>
          <PeriodFilteredRows rows={refunds.data || []} noun="refunds updated" dateKey="updated_at">
            {(shown) => (shown.length ? (
            <div className="item-list">
              {shown.map((refund) => (
                <RefundRow key={refund.id} refund={refund} onMarkRefunded={(id, reference) => markRefunded.mutateAsync({ id, reference })} />
              ))}
            </div>
          ) : <EmptyState message="No refunds needed." />)}
          </PeriodFilteredRows>
        </Section>
        </>
      )}
      {tab === 'reports' && (
        <Section title="Platform Analytics" actions={<PeriodFilter period={reportsPeriod} onChange={setReportsPeriod} />}>
          <p className="helper-text">Graphs reflect platform-wide activity for the selected period, across every municipality. The summary below remains all-time.</p>
          <div className="charts-grid">
            <CategoryBarChart title="Listings by Status" data={(reports.data?.listings_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.approval_status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.approval_status)} />
            <CategoryBarChart title="Listings by Species" data={reports.data?.listings_by_species} dataKey="total" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} />
            <CategoryBarChart title="Sellers by Verification Status" data={(reports.data?.sellers_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.status)} />
            <TimeSeriesChart title={`Orders Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.orders_over_time} dataKey="count" color="var(--color-primary)" />
            <CategoryBarChart title="Listings by Municipality" data={reports.data?.listings_by_municipality} dataKey="total" nameKey="municipality" colorFor={() => 'var(--color-primary)'} />
            <CategoryBarChart title="Sellers by Municipality" data={reports.data?.sellers_by_municipality} dataKey="total" nameKey="municipality" colorFor={() => 'var(--color-teal)'} />
            <CategoryBarChart title="Orders by Municipality" data={reports.data?.orders_by_municipality} dataKey="total" nameKey="municipality" colorFor={() => 'var(--chart-violet)'} />
          </div>
          <StatsRow items={[
            ['LGU Admins', reports.data?.total_lgus ?? 0, false, '/admin/dashboard?tab=lgu-admins&focus=registered-lgu-admins'],
            ['Transactions', reports.data?.total_transactions ?? 0, false, '/admin/dashboard?tab=transactions'],
            ['Pending Payouts', reports.data?.pending_payouts ?? 0, false, '/admin/dashboard?tab=payouts'],
            ['Listings', reports.data?.total_listings ?? 0, false, '/admin/dashboard?tab=listings'],
          ]} />

          <h3>Marketplace Revenue</h3>
          <p className="helper-text">Platform Revenue is the platform&apos;s 4% share of every settled order (plotted by settlement date), plus the 6% payout fees on withdrawals requested before October 9, 2026 (plotted by payout date), for the selected period. Gross Marketplace Revenue is the full value paid by buyers before revenue sharing, recognized at settlement.</p>
          <div className="charts-grid">
            <TimeSeriesChart title={`Platform Revenue Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.platform_revenue_over_time} dataKey="amount" color="var(--color-primary)" valueFormatter={currency} />
            <TimeSeriesChart title={`Gross Marketplace Revenue Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.gross_revenue_over_time} dataKey="amount" color="var(--color-teal)" valueFormatter={currency} />
            <CategoryBarChart title="Revenue by Municipality" data={reports.data?.revenue_by_municipality} dataKey="amount" nameKey="municipality" colorFor={() => 'var(--chart-violet)'} valueFormatter={currency} />
            <CategoryBarChart title="Revenue by Fish Species" data={reports.data?.revenue_by_species} dataKey="amount" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} valueFormatter={currency} />
            <CategoryBarChart title="Revenue by Seller" data={reports.data?.revenue_by_seller} dataKey="amount" nameKey="seller" colorFor={() => 'var(--color-teal)'} valueFormatter={currency} />
            <CategoryBarChart title="Commission Distribution" data={reports.data?.commission_distribution} dataKey="amount" nameKey="label" colorFor={() => 'var(--color-primary)'} valueFormatter={currency} />
          </div>

          <h3>Account Moderation</h3>
          <p className="helper-text">Filter moderation activity by role and status. Actions Over Time reflects the filters below, scoped to the selected period above.</p>
          <div className="form grid-form">
            <select value={reportsModerationRole} onChange={(e) => setReportsModerationRole(e.target.value)}>
              <option value="">All roles</option>
              <option value="buyer">Buyers</option>
              <option value="seller">Sellers</option>
              <option value="lgu_admin">LGU Admins</option>
            </select>
            <select value={reportsModerationStatus} onChange={(e) => setReportsModerationStatus(e.target.value)}>
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="reinstated">Reinstated</option>
            </select>
          </div>
          {reports.data?.moderation_summary && (
            <StatsRow items={[
              ['Active Buyers', reports.data.moderation_summary.active_buyers, false, '/admin/dashboard?tab=users'],
              ['Suspended Buyers', reports.data.moderation_summary.suspended_buyers, false, '/admin/dashboard?tab=users'],
              ['Active Sellers', reports.data.moderation_summary.active_sellers, false, '/admin/dashboard?tab=sellers&focus=all-sellers'],
              ['Suspended Sellers', reports.data.moderation_summary.suspended_sellers, false, '/admin/dashboard?tab=sellers&focus=all-sellers'],
              ['Active LGU Admins', reports.data.moderation_summary.active_lgu_admins, false, '/admin/dashboard?tab=lgu-admins&focus=registered-lgu-admins'],
              ['Suspended LGU Admins', reports.data.moderation_summary.suspended_lgu_admins, false, '/admin/dashboard?tab=lgu-admins&focus=registered-lgu-admins'],
            ]} />
          )}
          <div className="charts-grid">
            <TimeSeriesChart title={`Moderation Actions Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.moderation_actions_over_time} dataKey="count" color="var(--color-danger)" />
          </div>
          {(reports.data?.moderation_log || []).length ? (
            <div className="item-list">
              {reports.data.moderation_log.slice(0, 10).map((log) => (
                <div className="card" key={log.id}>
                  <div className="card-row"><strong>{log.user?.name || 'Unknown account'}</strong><Badge status={log.action === 'suspended' ? 'suspended' : 'active'} /></div>
                  <p>{roleLabel(log.role)} · {log.action === 'suspended' ? 'Suspended' : 'Reinstated'} by {log.moderator?.name || 'Unknown'}{log.reason ? ` · ${log.reason}` : ''}</p>
                  <p className="muted">{log.created_at ? new Date(log.created_at).toLocaleString() : ''}</p>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No moderation actions match these filters." />}

          <h3>Export Reports</h3>
          <p className="helper-text">Exports respect the period selected above.</p>
          <ReportExportControls
            typeOptions={[
              { value: 'marketplace-revenue', label: 'Marketplace Revenue' },
              { value: 'municipality-revenue', label: 'Municipality Revenue' },
              { value: 'buyers', label: 'Buyer Statistics' },
              { value: 'sellers', label: 'Seller Statistics' },
              { value: 'orders', label: 'Orders' },
              { value: 'listings', label: 'Listings' },
              { value: 'payouts', label: 'Payouts' },
            ]}
            exportEndpoint="/super-admin/reports/export"
            period={reportsPeriod}
          />
        </Section>
      )}
    </Dashboard>
  )
}