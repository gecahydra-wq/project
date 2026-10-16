import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { CalendarDays, MapPin, ShieldCheck, Sprout, Store, UserRound } from 'lucide-react'
import { Fragment, useRef, useState } from 'react'
import { CategoryBarChart, TimeSeriesChart } from '../../components/charts.jsx'
import { BankTransferFields, BelowMinimumWarning, ListingDetailsFields, StagedImagePicker } from '../../components/forms.jsx'
import { ListingEditModal, MarketplaceBrowser, SellerApprovalNotice } from '../../components/listings.jsx'
import { SuspendedAccountNotice } from '../../components/moderation.jsx'
import { SellerNoticesSection } from '../../components/notices.jsx'
import { MarkAllReadButton, NotificationHistory, NotificationStack, UnreadMessagesNotice } from '../../components/notifications.jsx'
import { OrderDetailPanel, OrderTable } from '../../components/orders.jsx'
import { PasswordResetNote, ProfileCard, ProfileField, ProfileHeader, ProfileSaveBar } from '../../components/profile.jsx'
import { EmptyState, LoadingState } from '../../components/states.jsx'
import { Badge, Dashboard, PeriodFilter, PeriodFilteredRows, RoleBadge, Section, StatsRow } from '../../components/ui.jsx'
import { FinalRejectionHelp, RejectedWithdrawalActions, WithdrawalConfirmModal } from '../../components/withdrawals.jsx'
import { api, queryClient } from '../../lib/api.js'
import { speciesChartColor, statusChartColor, statusChartLabel } from '../../lib/chartColors.js'
import { EMPTY_LISTING_FORM, MIN_WITHDRAWAL, REQUIRED_FIELDS_MESSAGE } from '../../lib/constants.js'
import { apiErrorMessage } from '../../lib/errors.js'
import { currency, formatQuantity, percentLabel } from '../../lib/format.js'
import { useOrderNumberLookup } from '../../lib/hooks.jsx'
import { listingPayload, minimumOrder, resolveListingImage, unitLabel } from '../../lib/listing.js'
import { notificationLinkFor } from '../../lib/notifications.js'
import { updateSessionUser } from '../../lib/session.js'
import { periodLabel, withdrawalStatusLabel } from '../../lib/status.js'
import { accountNumberPlaceholder, normalizeAccountNumber, withdrawalFormIsIncomplete, withdrawalFormIssue } from '../../lib/validation.js'
import { selectedBankName, withdrawalMethodLabel } from '../../lib/withdrawals.js'
import { MessagesPanel } from '../messaging/index.jsx'
import { SupportPanel } from '../support/index.jsx'



export function SellerDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [form, setForm] = useState(EMPTY_LISTING_FORM)
  const [editingListingId, setEditingListingId] = useState(null)
  const [stagedImages, setStagedImages] = useState([])
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const [withdrawForm, setWithdrawForm] = useState({ method: 'gcash', bank_choice: '', bank_other: '', account_name: '', account_number: '', amount: '' })
  const dashboard = useQuery({
    queryKey: ['seller-dashboard'],
    queryFn: async () => (await api.get('/seller/dashboard')).data,
    retry: false,
    refetchInterval: 30000,
    // Structural keys only. The placeholder must never carry plausible
    // figures: with retry disabled a slow or failed load would otherwise show
    // invented counts as if they were the seller's own. The overview below
    // waits for the real payload instead.
    placeholderData: {
      seller: null,
      listings: [],
      orders: [],
      notifications: [],
    },
  })
  const [analyticsPeriod, setAnalyticsPeriod] = useState('monthly')
  const analytics = useQuery({
    queryKey: ['seller-analytics', analyticsPeriod],
    queryFn: async () => (await api.get('/seller/analytics', { params: { period: analyticsPeriod } })).data,
    retry: false,
    placeholderData: { summary: {}, sales_over_time: [], orders_by_status: [], top_species: [] },
  })
  const wallet = useQuery({
    queryKey: ['seller-wallet'],
    queryFn: async () => (await api.get('/seller/wallet')).data,
    retry: false,
    placeholderData: { available_balance: 0, pending_balance: 0, processing_amount: 0, total_earnings: 0, withdrawn_amount: 0, payment_history: [], withdrawal_requests: [] },
    // Accepted payout disputes reopen the request in another user's session.
    // Poll more often only while one is awaiting a decision so the seller sees
    // the new Pending status promptly, then return to the normal wallet cadence.
    refetchInterval: (query) => query.state.data?.withdrawal_requests?.some(
      (request) => request.status === 'rejected' && request.has_open_dispute
    ) ? 5000 : 30000,
  })
  const notifications = (dashboard.data?.notifications || []).filter((notification) => !visibleNotificationIds.includes(notification.id))
  const markRead = useMutation({
    mutationFn: async (id) => (await api.patch(`/seller/notifications/${id}/read`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
  })
  const handleMarkRead = (id) => {
    setVisibleNotificationIds((current) => (current.includes(id) ? current : [...current, id]))
    markRead.mutate(id)
  }
  const markAllRead = useMutation({
    mutationFn: async () => (await api.patch('/seller/notifications/read-all')).data,
    onSuccess: () => {
      setVisibleNotificationIds((current) => [...current, ...notifications.map((n) => n.id)])
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })
  const [withdrawFormError, setWithdrawFormError] = useState('')
  const [confirmingWithdrawal, setConfirmingWithdrawal] = useState(false)
  const requestWithdrawal = useMutation({
    mutationFn: async () => (await api.post('/seller/withdrawals', {
      method: withdrawForm.method,
      bank_name: selectedBankName(withdrawForm),
      account_name: withdrawForm.account_name,
      account_number: normalizeAccountNumber(withdrawForm.account_number),
      amount: Number(withdrawForm.amount),
    })).data,
    onSuccess: () => {
      setWithdrawForm({ method: 'gcash', bank_choice: '', bank_other: '', account_name: '', account_number: '', amount: '' })
      queryClient.invalidateQueries({ queryKey: ['seller-wallet'] })
    },
  })
  const confirmWithdrawalReceived = useMutation({
    mutationFn: async (id) => (await api.patch(`/seller/withdrawals/${id}/received`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-wallet'] }),
  })
  const submitWithdrawal = () => {
    if (withdrawalFormIsIncomplete(withdrawForm)) {
      setWithdrawFormError(REQUIRED_FIELDS_MESSAGE)
      return
    }
    const issue = withdrawalFormIssue(withdrawForm)
    if (issue) {
      setWithdrawFormError(issue)
      return
    }
    setWithdrawFormError('')
    setConfirmingWithdrawal(true)
  }
  const confirmWithdrawal = () => {
    setConfirmingWithdrawal(false)
    requestWithdrawal.mutate()
  }
  const withdrawRequestAmount = Number(withdrawForm.amount) || 0
  const addStagedImages = (files) => {
    setStagedImages((current) => [...current, ...files.map((file) => ({ file, previewUrl: URL.createObjectURL(file) }))])
  }
  const removeStagedImage = (index) => {
    setStagedImages((current) => {
      URL.revokeObjectURL(current[index].previewUrl)
      return current.filter((_, i) => i !== index)
    })
  }
  const clearStagedImages = () => {
    setStagedImages((current) => {
      current.forEach((staged) => URL.revokeObjectURL(staged.previewUrl))
      return []
    })
  }
  // Creating a listing. Editing an existing one is handled entirely by
  // ListingEditModal, which owns its own form and PATCH.
  const saveListing = useMutation({
    mutationFn: async () => {
      // Listing details AND photos go in ONE multipart request. A photo is
      // mandatory, and the backend creates both in a single transaction, so
      // there is no longer a window where a listing exists without one.
      const formData = new FormData()
      const payload = {
        ...listingPayload(form),
        scientific_name: '',
        average_size: '',
        availability_status: 'in_stock',
      }
      Object.entries(payload).forEach(([key, value]) => {
        // FormData cannot carry null -- omit the key instead, which is what
        // Laravel's 'nullable' rules expect for an absent optional field.
        if (value !== null && value !== undefined) formData.append(key, value)
      })
      stagedImages.forEach((staged) => formData.append('photos[]', staged.file))
      return (await api.post('/listings', formData)).data
    },
    onSuccess: () => {
      clearStagedImages()
      setForm(EMPTY_LISTING_FORM)
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })
  const deleteListing = useMutation({
    mutationFn: async (id) => (await api.delete(`/listings/${id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
  })
  const updateOrderStatus = useMutation({
    mutationFn: async ({ orderId, status, cancellationReason }) => (
      await api.patch(`/orders/${orderId}/status`, {
        status,
        ...(cancellationReason ? { cancellation_reason: cancellationReason } : {}),
      })
    ).data,
    onSuccess: (updatedOrder) => {
      // Update the visible order row immediately from the server response.
      // The detail modal derives its available actions from this row, so a
      // background refetch alone could leave its buttons showing the old
      // status until the dashboard request completed.
      queryClient.setQueryData(['seller-dashboard'], (current) => {
        if (!current || !Array.isArray(current.orders)) return current
        return {
          ...current,
          orders: current.orders.map((order) => order.id === updatedOrder.id
            ? { ...order, ...updatedOrder }
            : order),
        }
      })
      // Keeps the open View Order popup's detail panel in step with the
      // status the seller just changed.
      return Promise.all([
        queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['order-detail'] }),
      ])
    },
  })
  // The listing currently open in the edit popup, resolved from the dashboard
  // data so photo changes made inside the popup re-render it immediately.
  const editingListing = (dashboard.data?.listings || []).find((listing) => listing.id === editingListingId) || null
  // Listing creation/editing is only unlocked once the registration has been
  // approved -- the backend enforces this too (see
  // ListingController::guardRegistrationApproved).
  const canManageListings = (dashboard.data?.seller?.approval_status ?? 'approved') === 'approved'
  const updateProfile = useMutation({
    mutationFn: async (form) => (await api.patch('/seller/profile', {
      name: form.name,
      email: form.email,
      hatchery_name: form.hatchery_name,
      description: form.description,
      farming_methods: form.farming_methods,
      fish_raising_practices: form.fish_raising_practices,
      farm_history: form.farm_history,
      water_source: form.water_source,
      feeding_practices: form.feeding_practices,
      years_experience: form.years_experience === '' ? null : Number(form.years_experience),
      certifications: form.certifications,
      address: form.address,
      phone: form.phone,
    })).data,
    onSuccess: (result) => {
      updateSessionUser({ name: result.user.name, email: result.user.email, phone: result.user.phone })
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })
  return (
    <Dashboard
      title="Seller Dashboard"
      subtitle="Manage listings, orders, and analytics."
    >
      <SuspendedAccountNotice role="seller" />
      {tab === 'overview' && (
        <>
          <UnreadMessagesNotice dashboardPath="/seller/dashboard" />
          <SellerApprovalNotice seller={dashboard.data?.seller} lguContact={dashboard.data?.lgu_contact} />
          {(dashboard.data?.open_notices || []).length > 0 && (
            <div className="card approval-notice approval-notice-danger">
              <div className="card-row">
                <strong>You have a Notice to Explain</strong>
                <Badge tone="danger">Action needed</Badge>
              </div>
              <p className="helper-text">
                {dashboard.data.open_notices[0].type === 'user_report'
                  ? "Your LGU found a buyer's report against you valid and has asked you to explain."
                  : 'A low review has brought your average buyer rating below 3 stars, and your LGU has asked you to explain.'}
                {' '}Your account has not been suspended, but it will be if your explanation is rejected.{' '}
                <Link to="/seller/dashboard?tab=notices">Open Notices</Link> to respond.
              </p>
            </div>
          )}
          {dashboard.isError ? (
            <p className="error">Could not load your dashboard. Refresh to try again.</p>
          ) : dashboard.isPlaceholderData ? (
            <LoadingState label="Loading your dashboard..." />
          ) : (
            <StatsRow items={[
              ['Active Listings', dashboard.data?.active_listings ?? 0, false, '/seller/dashboard?tab=listings'],
              ['Pending Orders', dashboard.data?.pending_orders ?? 0, false, '/seller/dashboard?tab=orders'],
              ['Total Sales', currency(dashboard.data?.total_sales ?? 0), false, '/seller/dashboard?tab=analytics'],
              ['Unread Messages', dashboard.data?.unread_messages ?? 0, false, '/seller/dashboard?tab=messages'],
            ]}
            />
          )}
          {/* The seller's counterpart to the Buyer Dashboard's Recent Orders:
              the same table, told from the other side of the transaction. */}
          <Section title="Recent Orders" actions={<Link className="ghost" to="/seller/dashboard?tab=orders">View All Orders</Link>}>
            <OrderTable
              rows={(dashboard.data?.orders || []).slice(0, 5)}
              counterparty="buyer"
              detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
            />
          </Section>
        </>
      )}
      {tab === 'marketplace' && (
        <Section title="Marketplace">
          <p className="helper-text">Browse the marketplace to see what other hatcheries are offering. This view is read-only -- purchasing is reserved for buyer accounts.</p>
          <MarketplaceBrowser detailPath={(item) => `/seller/listings/${item.id}?source=marketplace`} />
        </Section>
      )}
      {tab === 'listings' && (
        <>
          <SellerApprovalNotice seller={dashboard.data?.seller} lguContact={dashboard.data?.lgu_contact} />
          {canManageListings && (
            <Section title="Create Listing">
              <div className="card listing-create-card">
                <ListingDetailsFields form={form} setForm={setForm} />
                <p className="helper-text">At least one photo is required. Add up to 5 photos or videos (JPG, PNG, WEBP up to 25MB; MP4, MOV, WEBM up to 25MB). They&apos;ll be uploaded together with the listing when you save.</p>
                <StagedImagePicker files={stagedImages} onAdd={addStagedImages} onRemove={removeStagedImage} />
                {!stagedImages.length && <p className="helper-text">Buyers pay before they ever see the fingerlings, so a listing cannot be posted without at least one photo.</p>}
                <button onClick={() => saveListing.mutate()} type="button" disabled={saveListing.isPending || !stagedImages.length}>{saveListing.isPending ? 'Saving...' : 'Save Listing'}</button>
                {saveListing.error && <p className="error">{saveListing.error.response?.data?.message || 'Could not save listing.'}</p>}
              </div>
            </Section>
          )}
          <Section title="My Listings">
            <div className="item-list">
              {(dashboard.data?.listings || []).map((listing) => (
                <div className="card action" key={listing.id}>
                  <img className="listing-thumb" src={resolveListingImage(listing)} alt={listing.title} />
                  <div>
                    <div className="card-row"><strong>{listing.title}</strong>{listing.approval_status !== 'approved' && <Badge status={listing.approval_status} />}</div>
                    <p>
                      {listing.species} · {formatQuantity(listing.quantity, listing)} · {currency(listing.price_per_piece)}/{unitLabel(listing)}
                      {minimumOrder(listing) > 1 ? ` · min ${formatQuantity(minimumOrder(listing), listing)}` : ''}
                    </p>
                    {listing.approval_status === 'rejected' && listing.rejection_reason && (
                      <p className="error">Reason: {listing.rejection_reason}</p>
                    )}
                  </div>
                  <div className="row-actions">
                    <button type="button" className="ghost" onClick={() => setEditingListingId(listing.id)} disabled={!canManageListings}>Edit</button>
                    <button type="button" className="ghost danger" onClick={() => deleteListing.mutate(listing.id)}>Delete</button>
                  </div>
                </div>
              ))}
              {!dashboard.data?.listings?.length && <EmptyState message="No listings yet." />}
            </div>
            {deleteListing.error && <p className="error">{deleteListing.error.response?.data?.message || 'Could not delete listing.'}</p>}
          </Section>
          {editingListing && (
            <ListingEditModal listing={editingListing} onClose={() => setEditingListingId(null)} />
          )}
        </>
      )}
      {tab === 'orders' && (
        <>
          <SellerOrderLookup />
          <Section title="Order Management">
            <PeriodFilteredRows rows={dashboard.data?.orders || []}>
              {(shown) => (
                <OrderTable
                  rows={shown}
                  counterparty="buyer"
                  detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
                  onUpdateStatus={(orderId, status, cancellationReason) => updateOrderStatus.mutateAsync({ orderId, status, cancellationReason })}
                  lguContact={dashboard.data?.lgu_contact}
                />
              )}
            </PeriodFilteredRows>
          </Section>
        </>
      )}
      {tab === 'notices' && <SellerNoticesSection lguContact={dashboard.data?.lgu_contact} />}
      {tab === 'support' && <SupportPanel role="seller" />}
      {tab === 'messages' && <Section title="Messages"><MessagesPanel initialUserId={searchParams.get('with') ? Number(searchParams.get('with')) : null} /></Section>}
      {tab === 'wallet' && (
        <>
          <StatsRow items={[
            ['Available Balance', currency(wallet.data?.available_balance ?? 0), true],
            ['Pending Balance', currency(wallet.data?.pending_balance ?? 0)],
            ['Processing Withdrawal', currency(wallet.data?.processing_amount ?? 0)],
            ...(Number(wallet.data?.on_hold_amount) > 0 ? [['On Hold (Rejected)', currency(wallet.data.on_hold_amount)]] : []),
            ...(Number(wallet.data?.repayment_due) > 0 ? [['Owed from a Refund', currency(wallet.data.repayment_due)]] : []),
            ['Withdrawn Amount', currency(wallet.data?.withdrawn_amount ?? 0)],
            ['Total Earnings', currency(wallet.data?.total_earnings ?? 0)],
          ]} />
          {Number(wallet.data?.repayment_due) > 0 && (
            <p className="helper-text">
              A refund reversed earnings of yours that had already been withdrawn against, so {currency(wallet.data.repayment_due)} is owed back.
              New earnings pay that down first; a withdrawal can only be paid once your balance covers it again.
            </p>
          )}
          <Section title="Request Withdrawal">
            <div className="form grid-form">
              <select value={withdrawForm.method} onChange={(e) => setWithdrawForm({ ...withdrawForm, method: e.target.value })}>
                <option value="gcash">GCash</option>
                <option value="maya">Maya</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
              <BankTransferFields form={withdrawForm} setForm={setWithdrawForm} />
              <input value={withdrawForm.account_name} onChange={(e) => setWithdrawForm({ ...withdrawForm, account_name: e.target.value })} placeholder="Account name" />
              <input value={withdrawForm.account_number} onChange={(e) => setWithdrawForm({ ...withdrawForm, account_number: e.target.value })} placeholder={accountNumberPlaceholder(withdrawForm.method)} inputMode="numeric" />
              <input value={withdrawForm.amount} onChange={(e) => setWithdrawForm({ ...withdrawForm, amount: e.target.value })} placeholder="Amount to withdraw" type="number" min={MIN_WITHDRAWAL} step="0.01" />
            </div>
            <p className="helper-text">Available to withdraw: {currency(wallet.data?.available_balance ?? 0)} · Minimum withdrawal: {currency(MIN_WITHDRAWAL)}</p>
            <BelowMinimumWarning amount={withdrawRequestAmount} />
            <button type="button" onClick={submitWithdrawal} disabled={requestWithdrawal.isPending || (withdrawRequestAmount > 0 && withdrawRequestAmount < MIN_WITHDRAWAL)}>{requestWithdrawal.isPending ? 'Submitting...' : 'Submit Withdrawal Request'}</button>
            {withdrawFormError && <p className="error">{withdrawFormError}</p>}
            {confirmingWithdrawal && (
              <WithdrawalConfirmModal form={withdrawForm} onConfirm={confirmWithdrawal} onClose={() => setConfirmingWithdrawal(false)} />
            )}
            {requestWithdrawal.error && <p className="error">{apiErrorMessage(requestWithdrawal.error, 'Could not submit withdrawal request.')}</p>}
            {requestWithdrawal.isSuccess && (
              <p className="helper-text">
                Withdrawal request submitted for {currency(requestWithdrawal.data?.amount)}. The full requested amount will be paid out once the Super Admin processes it.
              </p>
            )}
          </Section>
          <Section title="Withdrawal Requests">
            {confirmWithdrawalReceived.isError && <p className="error">{apiErrorMessage(confirmWithdrawalReceived.error, 'Could not confirm payout receipt.')}</p>}
            <PeriodFilteredRows rows={wallet.data?.withdrawal_requests || []} noun="withdrawal requests made">
              {(shown) => (shown.length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Amount Requested</span>
                  <span>You Receive</span>
                  <span>Method</span>
                  <span>Account</span>
                  <span>Status</span>
                  <span>Requested</span>
                  <span>Notes</span>
                </div>
                {shown.map((request) => (
                  <Fragment key={request.id}>
                  <div className="table-row">
                    <span>{currency(request.amount)}</span>
                    <span>{currency(request.net_amount)}</span>
                    <span>{withdrawalMethodLabel(request.method, request.bank_name)}</span>
                    <span>{request.account_name} · {request.account_number}</span>
                    <span><Badge status={request.status}>{withdrawalStatusLabel(request.status)}</Badge></span>
                    <span>{new Date(request.created_at).toLocaleDateString()}</span>
                    <span>
                      {['rejected', 'rejected_final'].includes(request.status) && request.rejection_reason && `Reason: ${request.rejection_reason}`}
                      {request.status === 'paid' && request.paid_at && `Paid on ${new Date(request.paid_at).toLocaleDateString()}`}
                      {request.status === 'paid' && request.auto_completed_at && ' '}
                      {request.status === 'pending' && 'Awaiting Super Admin review.'}
                      {request.status === 'approved' && 'Approved; waiting for the Super Admin to send the payout.'}
                      {request.status === 'sent' && (request.recipient_confirmed_at
                        ? `You confirmed receipt on ${new Date(request.recipient_confirmed_at).toLocaleDateString()}. Awaiting admin completion.`
                        : <>
                          <span>Payout sent {request.sent_at ? `on ${new Date(request.sent_at).toLocaleDateString()}` : ''}. Once it arrives, confirm it here.</span>{' '}
                          <button type="button" className="ghost" onClick={() => {
                            if (window.confirm(`Confirm you received ${currency(request.net_amount)}? This will complete the withdrawal.`)) confirmWithdrawalReceived.mutate(request.id)
                          }} disabled={confirmWithdrawalReceived.isPending}>Money Received</button>
                        </>)}
                      {request.status === 'paid' && request.auto_completed_at && (
                        <>
                          <span>Automatically marked paid after no confirmation. If you have not received it, </span>
                          {request.sent_by && <><Link className="link-action" to={`/seller/dashboard?tab=messages&with=${request.sent_by}`}>message the Super Admin</Link> or </>}
                          <Link className="link-action" to="/seller/dashboard?tab=support">contact Support</Link>.
                        </>
                      )}
                    </span>
                  </div>
                  {request.status === 'rejected' && (
                    <RejectedWithdrawalActions
                      request={request}
                      disputeEndpoint={`/withdrawals/${request.id}/dispute`}
                      invalidateKeys={['seller-wallet']}
                    />
                  )}
                  {request.status === 'rejected_final' && (
                    <FinalRejectionHelp
                      base="/seller/dashboard"
                      contact={dashboard.data?.support_contact}
                      contactLabel="the Super Admin"
                      topic="Wallet or withdrawal"
                      returnedAmount={request.amount}
                    />
                  )}
                  </Fragment>
                ))}
              </div>
            ) : <EmptyState message="No withdrawal requests yet." />)}
            </PeriodFilteredRows>
          </Section>
          <Section title="Payment History">
            <p className="helper-text">
              What each order earned you. From every order total, AbaiMarket keeps a 4% platform fee and your LGU gets a 2% share; the
              rest (94%) goes to your Available Balance once your LGU approves the order. Orders approved before October 9, 2026 used the
              old split (96% to you, 4% to the LGU). Withdrawals have no fee.
            </p>
            <PeriodFilteredRows rows={wallet.data?.payment_history || []} noun="payments received">
              {(shown) => (shown.length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Order ID</span>
                  <span>Buyer</span>
                  <span>Fish Listing</span>
                  <span>Order Total</span>
                  <span>Platform Fee</span>
                  <span>LGU Share</span>
                  <span>You Receive</span>
                  <span>Release Date</span>
                  <span>Status</span>
                </div>
                {shown.map((payment) => (
                  <div className="table-row" key={payment.id}>
                    <span>{payment.order?.order_number ? `#${payment.order.order_number}` : 'N/A'}</span>
                    <span>{payment.order?.buyer?.name || 'Unknown buyer'}</span>
                    <span>{payment.order?.listing?.title || payment.order?.listing?.species || 'Listing'}</span>
                    <span>{currency(payment.amount)}</span>
                    {/* Refunded, failed and unpaid payments earn nothing, so no split. */}
                    <span>{!payment.earnings ? '—' : Number(payment.earnings.platform_share) > 0 ? `−${currency(payment.earnings.platform_share)} (${percentLabel(payment.earnings.platform_percent)}%)` : 'None (older order)'}</span>
                    <span>{payment.earnings ? `−${currency(payment.earnings.lgu_share)} (${percentLabel(payment.earnings.lgu_percent)}%)` : '—'}</span>
                    <span>
                      {payment.earnings ? <strong>{currency(payment.earnings.seller_share)}</strong> : '—'}
                      {payment.earnings?.source === 'preview' && <small className="muted"> estimate</small>}
                    </span>
                    <span>{payment.released_at ? new Date(payment.released_at).toLocaleDateString() : 'Not released yet'}</span>
                    <span><Badge status={payment.status} /></span>
                  </div>
                ))}
              </div>
            ) : <EmptyState message="No payment history yet." />)}
            </PeriodFilteredRows>
          </Section>
        </>
      )}
      {tab === 'notifications' && (
        <>
          <Section
            title="Notifications"
            actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllRead.isPending} onClick={() => markAllRead.mutate()} />}
          >
            <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} getLink={notificationLinkFor('seller')} emptyMessage="No unread notifications." />
          </Section>
          <NotificationHistory getLink={notificationLinkFor('seller')} unreadCount={dashboard.data?.notifications?.length ?? 0} showTypeFilter />
        </>
      )}
      {tab === 'analytics' && (
        <Section title="Analytics" actions={<PeriodFilter period={analyticsPeriod} onChange={setAnalyticsPeriod} />}>
          <StatsRow items={[
            ['Total Sales', analytics.data?.summary?.total_sales ?? 0],
            ['Total Revenue', currency(analytics.data?.summary?.total_revenue ?? 0)],
            ['Total Orders', analytics.data?.summary?.total_orders ?? 0],
            ['Active Listings', analytics.data?.summary?.active_listings ?? 0],
          ]}
          />
          <div className="charts-grid">
            <TimeSeriesChart title="Sales Over Time" data={analytics.data?.sales_over_time} dataKey="count" color="var(--color-primary)" />
            <TimeSeriesChart title="Revenue Over Time" data={analytics.data?.sales_over_time} dataKey="amount" color="var(--color-teal)" valueFormatter={currency} />
            <CategoryBarChart title="Orders by Status" data={(analytics.data?.orders_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.status)} />
            <CategoryBarChart title="Top-Selling Fish Species" data={analytics.data?.top_species} dataKey="quantity" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} />
            <CategoryBarChart title={`Total Earnings (${periodLabel(analyticsPeriod)})`} data={analytics.data?.sales_over_time} dataKey="amount" nameKey="label" colorFor={() => 'var(--color-teal)'} valueFormatter={currency} />
          </div>
        </Section>
      )}
      {tab === 'profile' && (
        !dashboard.data?.seller || dashboard.isPlaceholderData ? (
          <LoadingState label="Loading profile..." />
        ) : (
          <SellerProfileForm
            key={dashboard.data.seller.id}
            seller={dashboard.data.seller}
            saving={updateProfile.isPending}
            success={updateProfile.isSuccess}
            error={updateProfile.error?.response?.data?.message}
            onSave={(values, options) => updateProfile.mutate(values, options)}
          />
        )
      )}
    </Dashboard>
  )
}

const SELLER_APPROVAL_BADGES = {
  approved: ['approved', 'Approved seller'],
  pending: ['pending', 'Pending approval'],
  rejected: ['rejected', 'Registration rejected'],
}

function SellerProfileForm({ seller, onSave, saving, success, error }) {
  const initialValues = {
    name: seller.user?.name || '',
    email: seller.user?.email || '',
    hatchery_name: seller.hatchery_name || '',
    description: seller.description || '',
    farming_methods: seller.farming_methods || '',
    fish_raising_practices: seller.fish_raising_practices || '',
    farm_history: seller.farm_history || '',
    water_source: seller.water_source || '',
    feeding_practices: seller.feeding_practices || '',
    years_experience: seller.years_experience != null ? String(seller.years_experience) : '',
    certifications: seller.certifications || '',
    address: seller.address || '',
    phone: seller.user?.phone || '',
  }
  // `saved` is the last known server state; the save bar compares against it.
  const [saved, setSaved] = useState(initialValues)
  const [form, setForm] = useState(initialValues)
  const dirty = JSON.stringify(form) !== JSON.stringify(saved)
  const setField = (key) => (e) => setForm({ ...form, [key]: e.target.value })
  const [approvalTone, approvalLabel] = SELLER_APPROVAL_BADGES[seller.approval_status] || SELLER_APPROVAL_BADGES.pending

  const uploadPicture = useMutation({
    mutationFn: async (file) => {
      const formData = new FormData()
      formData.append('photo', file)
      return (await api.post('/seller/profile/picture', formData)).data
    },
    onSuccess: (updatedSeller) => {
      updateSessionUser({ profile_picture: updatedSeller.profile_picture })
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })
  const removePicture = useMutation({
    mutationFn: async () => (await api.delete('/seller/profile/picture')).data,
    onSuccess: (updatedSeller) => {
      updateSessionUser({ profile_picture: updatedSeller.profile_picture })
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })
  const uploadCover = useMutation({
    mutationFn: async (file) => {
      const formData = new FormData()
      formData.append('photo', file)
      return (await api.post('/seller/profile/cover-photo', formData)).data
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
  })
  const removeCover = useMutation({
    mutationFn: async () => (await api.delete('/seller/profile/cover-photo')).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
  })

  return (
    <div className="profile-page">
      <ProfileHeader
        name={saved.hatchery_name || saved.name || 'Your hatchery'}
        email={saved.email}
        badges={<><RoleBadge role="seller" /><Badge status={approvalTone}>{approvalLabel}</Badge></>}
        meta={[
          saved.name && saved.hatchery_name && [UserRound, saved.name],
          seller.municipality?.name && [MapPin, seller.municipality.name],
          saved.years_experience !== '' && [CalendarDays, `${saved.years_experience} ${saved.years_experience === '1' ? 'year' : 'years'} of experience`],
        ]}
        actions={<Link className="ghost" to={`/seller/sellers/${seller.id}`}><Store size={16} aria-hidden="true" /> View public profile</Link>}
        picture={seller.profile_picture}
        pictureUploading={uploadPicture.isPending || removePicture.isPending}
        onPictureUpload={(file) => uploadPicture.mutate(file)}
        onPictureRemove={() => removePicture.mutate()}
        pictureError={uploadPicture.error?.response?.data?.message || removePicture.error?.response?.data?.message}
        cover={seller.cover_photo}
        coverUploading={uploadCover.isPending || removeCover.isPending}
        onCoverUpload={(file) => uploadCover.mutate(file)}
        onCoverRemove={() => removeCover.mutate()}
        coverError={uploadCover.error?.response?.data?.message || removeCover.error?.response?.data?.message}
      />

      <ProfileCard icon={UserRound} title="Account" description="The person behind the hatchery, and how buyers and your LGU reach you.">
        <div className="profile-fields">
          <ProfileField label="Owner's full name">
            <input value={form.name} onChange={setField('name')} autoComplete="name" />
          </ProfileField>
          <ProfileField label="Email address" hint="Used to log in and to receive order and payout emails.">
            <input type="email" value={form.email} onChange={setField('email')} autoComplete="email" />
          </ProfileField>
          <ProfileField label="Contact phone" hint="Shown to buyers so they can coordinate pickup or delivery.">
            <input type="tel" value={form.phone} onChange={setField('phone')} autoComplete="tel" placeholder="e.g. 0917 123 4567" />
          </ProfileField>
          <ProfileField label="Municipality" hint="Set at registration. Your LGU is based on it.">
            <input value={seller.municipality?.name || 'Not set'} disabled />
          </ProfileField>
        </div>
        <PasswordResetNote />
      </ProfileCard>

      <ProfileCard icon={Store} title="Hatchery" description="What buyers see first on your public profile.">
        <div className="profile-fields">
          <ProfileField label="Hatchery / farm name">
            <input value={form.hatchery_name} onChange={setField('hatchery_name')} />
          </ProfileField>
          <ProfileField label="Years of experience">
            <input type="number" min="0" max="200" value={form.years_experience} onChange={setField('years_experience')} placeholder="e.g. 5" />
          </ProfileField>
          <ProfileField label="Farm address" wide>
            <input value={form.address} onChange={setField('address')} autoComplete="street-address" placeholder="Barangay, municipality, province" />
          </ProfileField>
          <ProfileField label="About the farm" hint="A short introduction: what you raise and what makes your fingerlings good." wide>
            <textarea value={form.description} onChange={setField('description')} rows={4} />
          </ProfileField>
        </div>
      </ProfileCard>

      <ProfileCard icon={Sprout} title="Farming practices" description="Helps buyers trust the quality of your stock. Short, plain answers are fine.">
        <div className="profile-fields">
          <ProfileField label="Farming methods">
            <textarea value={form.farming_methods} onChange={setField('farming_methods')} rows={3} placeholder="e.g. Earthen ponds with partial water exchange" />
          </ProfileField>
          <ProfileField label="Fish raising practices">
            <textarea value={form.fish_raising_practices} onChange={setField('fish_raising_practices')} rows={3} placeholder="e.g. Graded by size before sale" />
          </ProfileField>
          <ProfileField label="Water source">
            <textarea value={form.water_source} onChange={setField('water_source')} rows={3} placeholder="e.g. Deep well and river water, filtered" />
          </ProfileField>
          <ProfileField label="Feeding practices">
            <textarea value={form.feeding_practices} onChange={setField('feeding_practices')} rows={3} placeholder="e.g. Commercial starter feed, three times a day" />
          </ProfileField>
        </div>
      </ProfileCard>

      <ProfileCard icon={ShieldCheck} title="Credentials & history" description="Optional, but certifications and a track record help you stand out.">
        <div className="profile-fields">
          <ProfileField label="Certifications" hint="Optional">
            <textarea value={form.certifications} onChange={setField('certifications')} rows={3} />
          </ProfileField>
          <ProfileField label="Farm history">
            <textarea value={form.farm_history} onChange={setField('farm_history')} rows={3} placeholder="How and when the hatchery started" />
          </ProfileField>
        </div>
      </ProfileCard>

      <ProfileSaveBar
        dirty={dirty}
        saving={saving}
        success={success}
        error={error}
        onDiscard={() => setForm(saved)}
        onSave={() => onSave(form, { onSuccess: () => setSaved(form) })}
      />
    </div>
  )
}

/**
 * Order Lookup by Order Number for Sellers -- lets a seller quickly locate
 * a customer transaction when a buyer references an Order Number (e.g. in a
 * support chat), and attach an internal note to it. Reuses the same
 * GET/PATCH /orders/{order_number} endpoints as the Buyer Order Details
 * view (see OrderController::show/updateSellerNotes) and the shared
 * OrderDetailPanel for rendering.
 */
function SellerOrderLookup() {
  const { orderNumberInput, setOrderNumberInput, submit, query: lookup, searchedOrderNumber } = useOrderNumberLookup(
    (orderNumber) => `/orders/${orderNumber}`,
    'seller-order-lookup'
  )
  const notesRef = useRef(null)

  const saveNotes = useMutation({
    mutationFn: async () => (await api.patch(`/orders/${searchedOrderNumber}/notes`, { seller_notes: notesRef.current?.value || '' })).data,
    onSuccess: (data) => queryClient.setQueryData(['seller-order-lookup', searchedOrderNumber], data),
  })

  return (
    <Section title="Order Lookup">
      <div className="card">
        <form className="order-lookup-form" onSubmit={submit}>
          <input placeholder="Enter Order Number (e.g. FG-AB12CD)" value={orderNumberInput} onChange={(e) => setOrderNumberInput(e.target.value)} />
          <button type="submit">Search</button>
        </form>
        {lookup.isFetching && <LoadingState label="Looking up order..." />}
        {lookup.isError && <p className="error">{lookup.error?.response?.data?.message || 'Order not found, or it does not belong to one of your listings.'}</p>}
        {lookup.data && (
          <>
            <OrderDetailPanel detail={lookup.data} />
            <div className="form">
              <label htmlFor="seller-order-notes">Seller Notes</label>
              {/* Uncontrolled + keyed on the searched order so switching orders
                  resets the field to that order's own saved note, without
                  syncing controlled state from a query result inside an effect. */}
              <textarea
                id="seller-order-notes"
                key={searchedOrderNumber}
                ref={notesRef}
                defaultValue={lookup.data.seller_notes || ''}
                placeholder="Internal note for this order (e.g. buyer requested morning pickup)"
              />
              <button type="button" onClick={() => saveNotes.mutate()} disabled={saveNotes.isPending}>
                {saveNotes.isPending ? 'Saving...' : 'Save Notes'}
              </button>
              {saveNotes.error && <p className="error">{saveNotes.error.response?.data?.message || 'Could not save notes.'}</p>}
            </div>
          </>
        )}
      </div>
    </Section>
  )
}
