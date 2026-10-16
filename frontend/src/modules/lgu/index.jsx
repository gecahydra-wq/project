import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { MessageCircle } from 'lucide-react'
import { Fragment, useState } from 'react'
import { ActivityLogPanel, ReportExportControls } from '../../components/activity.jsx'
import { CategoryBarChart, TimeSeriesChart } from '../../components/charts.jsx'
import { SellerEarningsPanel } from '../../components/earnings.jsx'
import { BankTransferFields, BelowMinimumWarning } from '../../components/forms.jsx'
import { ListingDetailPanel, MarketplaceBrowser } from '../../components/listings.jsx'
import { ModerationAction, SellerRegistrationQueue, UserReportsPanel } from '../../components/moderation.jsx'
import { SellerNoticesPanel } from '../../components/notices.jsx'
import { MarkAllReadButton, NotificationHistory, NotificationStack, UnreadMessagesNotice } from '../../components/notifications.jsx'
import { AdminOrderTable } from '../../components/orders.jsx'
import { AdminProfilePanel } from '../../components/profile.jsx'
import { ReviewsAndRatingsSection } from '../../components/reviews.jsx'
import { renderStars } from '../../components/stars.jsx'
import { EmptyState, LoadingState } from '../../components/states.jsx'
import { ActionGroup, Badge, Dashboard, PeriodFilter, PeriodFilteredRows, Section, SellerLink, Stat, StatsRow, UserDirectoryList } from '../../components/ui.jsx'
import { DisputesPanel, FinalRejectionHelp, RejectedWithdrawalActions, WithdrawalConfirmModal } from '../../components/withdrawals.jsx'
import { api, queryClient } from '../../lib/api.js'
import { speciesChartColor, statusChartColor, statusChartLabel } from '../../lib/chartColors.js'
import { DEFAULT_AVATAR_IMAGE, MIN_WITHDRAWAL, REQUIRED_FIELDS_MESSAGE } from '../../lib/constants.js'
import { apiErrorMessage } from '../../lib/errors.js'
import { currency, percentLabel } from '../../lib/format.js'
import { mapListing, resolveListingImage } from '../../lib/listing.js'
import { notificationLinkFor } from '../../lib/notifications.js'
import { getSession } from '../../lib/session.js'
import { periodLabel, withdrawalStatusLabel } from '../../lib/status.js'
import { accountNumberPlaceholder, normalizeAccountNumber, withdrawalFormIsIncomplete, withdrawalFormIssue } from '../../lib/validation.js'
import { selectedBankName, withdrawalMethodLabel } from '../../lib/withdrawals.js'
import { MessagesPanel } from '../messaging/index.jsx'
import { SupportTicketsAdminPanel } from '../support/index.jsx'



export function LguDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const lgu = useQuery({
    queryKey: ['lgu-dashboard'],
    queryFn: async () => (await api.get('/lgu/dashboard')).data,
    retry: false,
    refetchInterval: 30000,
    placeholderData: { registered_sellers: 24, active_listings: 87, pending_approvals: [], notifications: [], municipality_revenue: null },
  })
  const [reportsPeriod, setReportsPeriod] = useState('monthly')
  const reports = useQuery({
    queryKey: ['lgu-reports', reportsPeriod],
    queryFn: async () => (await api.get('/lgu/reports', { params: { period: reportsPeriod } })).data,
    retry: false,
    placeholderData: { registered_sellers: 24, listings: 87, pending_approvals: 5, listings_by_status: [], listings_by_species: [], sellers_by_status: [], orders_over_time: [], revenue_cards: null, lgu_revenue_over_time: [], lgu_withdrawal_trends: [], revenue_by_species: [], revenue_by_seller: [] },
  })
  const listingManagement = useQuery({
    queryKey: ['lgu-listings'],
    queryFn: async () => (await api.get('/lgu/listings')).data,
    retry: false,
    placeholderData: [],
  })
  const lguOrders = useQuery({
    queryKey: ['lgu-orders'],
    queryFn: async () => (await api.get('/lgu/orders')).data,
    enabled: tab === 'orders',
    retry: false,
    refetchInterval: 30000,
    placeholderData: [],
  })
  const notifications = (lgu.data?.notifications || []).filter((notification) => !visibleNotificationIds.includes(notification.id))
  const handleMarkRead = (id) => {
    setVisibleNotificationIds((current) => (current.includes(id) ? current : [...current, id]))
    markRead.mutate(id)
  }
  const markRead = useMutation({
    mutationFn: async (id) => (await api.patch(`/lgu/notifications/${id}/read`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] }),
  })
  const markAllRead = useMutation({
    mutationFn: async () => (await api.patch('/lgu/notifications/read-all')).data,
    onSuccess: () => {
      setVisibleNotificationIds((current) => [...current, ...notifications.map((n) => n.id)])
      queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
    },
  })
  const notificationLink = notificationLinkFor('lgu_admin')
  const reviews = useQuery({
    queryKey: ['lgu-reviews'],
    queryFn: async () => (await api.get('/lgu/reviews')).data,
    retry: false,
    placeholderData: { buyer_reviews: [], seller_ratings: [] },
  })
  const sellersDirectory = useQuery({
    queryKey: ['lgu-sellers'],
    queryFn: async () => (await api.get('/lgu/sellers')).data,
    retry: false,
    placeholderData: [],
  })
  const usersDirectory = useQuery({
    queryKey: ['lgu-users'],
    queryFn: async () => (await api.get('/lgu/users')).data,
    retry: false,
    placeholderData: { buyers: [], sellers: [] },
  })
  const suspendSeller = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/lgu/sellers/${id}/suspend`, { reason, notes })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-sellers'] }),
  })
  const reinstateSeller = useMutation({
    mutationFn: async ({ id, reason, notes }) => (await api.patch(`/lgu/sellers/${id}/reinstate`, { reason, notes })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-sellers'] }),
  })
  const pendingEarnings = useQuery({
    queryKey: ['lgu-earnings'],
    queryFn: async () => (await api.get('/lgu/earnings')).data,
    retry: false,
    placeholderData: [],
  })
  const pendingEarningsCount = pendingEarnings.data?.length ?? 0
  const pendingEarningsAmount = (pendingEarnings.data || []).reduce((sum, payment) => sum + Number(payment.amount || 0), 0)

  const wallet = useQuery({
    queryKey: ['lgu-wallet'],
    queryFn: async () => (await api.get('/lgu/wallet')).data,
    retry: false,
    placeholderData: { available_balance: 0, pending_balance: 0, processing_amount: 0, total_revenue: 0, withdrawn_amount: 0, revenue_history: [], withdrawal_requests: [] },
    refetchInterval: 30000,
  })
  const [lguWithdrawForm, setLguWithdrawForm] = useState({ method: 'gcash', bank_choice: '', bank_other: '', account_name: '', account_number: '', amount: '' })
  const [lguWithdrawFormError, setLguWithdrawFormError] = useState('')
  const [confirmingLguWithdrawal, setConfirmingLguWithdrawal] = useState(false)
  const requestLguWithdrawal = useMutation({
    mutationFn: async () => (await api.post('/lgu/withdrawals', {
      method: lguWithdrawForm.method,
      bank_name: selectedBankName(lguWithdrawForm),
      account_name: lguWithdrawForm.account_name,
      account_number: normalizeAccountNumber(lguWithdrawForm.account_number),
      amount: Number(lguWithdrawForm.amount),
    })).data,
    onSuccess: () => {
      setLguWithdrawForm({ method: 'gcash', bank_choice: '', bank_other: '', account_name: '', account_number: '', amount: '' })
      queryClient.invalidateQueries({ queryKey: ['lgu-wallet'] })
    },
  })
  const confirmLguWithdrawalReceived = useMutation({
    mutationFn: async (id) => (await api.patch(`/lgu/withdrawals/${id}/received`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-wallet'] }),
  })
  const submitLguWithdrawal = () => {
    if (withdrawalFormIsIncomplete(lguWithdrawForm)) {
      setLguWithdrawFormError(REQUIRED_FIELDS_MESSAGE)
      return
    }
    const issue = withdrawalFormIssue(lguWithdrawForm)
    if (issue) {
      setLguWithdrawFormError(issue)
      return
    }
    setLguWithdrawFormError('')
    setConfirmingLguWithdrawal(true)
  }
  const confirmLguWithdrawal = () => {
    setConfirmingLguWithdrawal(false)
    requestLguWithdrawal.mutate()
  }

  return (
    <Dashboard
      title="LGU Admin Dashboard"
      subtitle="Municipality-scoped approvals, reports, and reviews."
    >
      {tab === 'overview' && (
        <>
          <UnreadMessagesNotice dashboardPath="/lgu/dashboard" />
          <StatsRow items={[
            ['Registered Sellers', reports.data?.registered_sellers ?? 0, false, '/lgu/dashboard?tab=sellers'],
            ['Listings', reports.data?.listings ?? 0, false, '/lgu/dashboard?tab=listings'],
          ]} />
          <Section title="Action Required">
            <p className="helper-text">Everything in your municipality waiting on you, grouped by what it is about. Click a number to open the page that handles it.</p>
            <div className="action-groups">
              <ActionGroup
                title="Seller registrations and listings"
                items={[
                  ['Pending Seller Approvals', lgu.data?.pending_seller_registrations ?? 0, '/lgu/dashboard?tab=sellers'],
                  ['Pending Listing Approvals', lgu.data?.pending_approvals?.length ?? 0, '/lgu/dashboard?tab=listings'],
                ]}
              />
              <ActionGroup
                title="Reports, notices and disputes"
                items={[
                  ['Open User Reports', lgu.data?.open_user_reports ?? 0, '/lgu/dashboard?tab=user-reports'],
                  ['Open Notices to Explain', lgu.data?.open_seller_notices ?? 0, '/lgu/dashboard?tab=notices'],
                  ['Explanations to Review', lgu.data?.notice_explanations_to_review ?? 0, '/lgu/dashboard?tab=notices'],
                  ['Pending Seller Disputes', lgu.data?.pending_seller_disputes ?? 0, '/lgu/dashboard?tab=disputes'],
                ]}
              />
            </div>
          </Section>
          <Section title="Municipality Revenue" actions={<Link className="ghost" to="/lgu/dashboard?tab=wallet">Go to LGU Wallet</Link>}>
            <p className="helper-text">Your municipality&apos;s share of settled orders. Request a withdrawal of your Available Balance any time from the LGU Wallet page.</p>
            <StatsRow items={[
              ["Today's Revenue", currency(lgu.data?.municipality_revenue?.today_revenue ?? 0), false, '/lgu/dashboard?tab=reports'],
              ['Monthly Revenue', currency(lgu.data?.municipality_revenue?.monthly_revenue ?? 0), false, '/lgu/dashboard?tab=reports'],
              ['Total Revenue', currency(lgu.data?.municipality_revenue?.total_revenue ?? 0), false, '/lgu/dashboard?tab=wallet'],
              ['Available Balance', currency(lgu.data?.municipality_revenue?.available_balance ?? 0), true, '/lgu/dashboard?tab=wallet'],
              ['Completed Orders', lgu.data?.municipality_revenue?.total_completed_orders ?? 0, false, '/lgu/dashboard?tab=orders'],
              ['Avg Revenue / Order', currency(lgu.data?.municipality_revenue?.average_revenue_per_order ?? 0), false, '/lgu/dashboard?tab=reports'],
            ]} />
          </Section>
          <Section title="Seller Earnings Approval">
            <Link to="/lgu/dashboard?tab=earnings" className="card seller-earnings-card">
              <div className="card-row">
                <h3>Completed deliveries awaiting approval</h3>
                {pendingEarningsCount > 0 && <Badge tone="warning">{pendingEarningsCount} pending</Badge>}
              </div>
              <div className="stats-inline">
                <Stat value={pendingEarningsCount} label="Deliveries awaiting approval" highlight={pendingEarningsCount > 0} />
                <Stat value={currency(pendingEarningsAmount)} label="Total pending earnings" highlight={pendingEarningsCount > 0} />
              </div>
            </Link>
          </Section>
        </>
      )}
      {tab === 'marketplace' && (
        <Section title="Marketplace">
          <p className="helper-text">Browse the platform-wide marketplace for moderation and testing. This is read-only -- purchasing is a buyer-only action.</p>
          <MarketplaceBrowser detailPath={(item) => `/lgu/listings/${item.id}`} />
        </Section>
      )}
      {tab === 'listings' && (
        <Section title="Listing Management">
          <p className="helper-text">All listings from sellers in your municipality. Listings go live as soon as they are posted. Open one to review it, or delete it if it breaks the rules.</p>
          {(listingManagement.data || []).length ? (
            <div className="item-list">
              {listingManagement.data.map((item) => (
                <div className="card action" key={item.id}>
                  <div>
                    <div className="card-row"><Link className="seller-name-link" to={`/lgu/listings/${item.id}`}><strong>{item.title}</strong></Link>{item.approval_status !== 'approved' && <Badge status={item.approval_status} />}</div>
                    <p><SellerLink id={item.seller_profile_id} name={item.sellerProfile?.hatchery_name} /> · {item.species}</p>
                  </div>
                  <div className="row-actions">
                    <Link className="ghost" to={`/lgu/listings/${item.id}`}>Manage</Link>
                  </div>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No listings in your municipality yet." />}
        </Section>
      )}
      {tab === 'messages' && <Section title="Messages"><MessagesPanel initialUserId={searchParams.get('with') ? Number(searchParams.get('with')) : null} /></Section>}
      {tab === 'sellers' && (
        <>
          <SellerRegistrationQueue
            endpointBase="/lgu"
            queryKey="lgu-seller-registrations"
            stageLabel="New seller registrations in your municipality, waiting on your review. Approving verifies the seller right away and lets them start creating listings."
            approveLabel="Approve Registration"
            emptyMessage="No seller registrations awaiting your review."
            extraInvalidateKeys={['lgu-sellers', 'lgu-dashboard']}
          />
          <Section title="Manage Sellers">
          {sellersDirectory.data?.length ? (
            <div className="item-list">
              {sellersDirectory.data.map((seller) => (
                <div className="card action" key={seller.id}>
                  <div>
                    <div className="card-row">
                      <strong><SellerLink seller={seller} name={seller.hatchery_name} /></strong>
                      <Badge status={seller.status} />
                      <Badge status={seller.approval_status}>{seller.approval_status_label}</Badge>
                    </div>
                    <p>{seller.user?.email}</p>
                    <p className="muted">{seller.reviews_count > 0 ? <>Seller rating: {renderStars(seller.rating)} {Number(seller.rating).toFixed(1)}/5 · {seller.reviews_count} rating{seller.reviews_count === 1 ? '' : 's'}</> : 'No seller ratings yet'}</p>
                  </div>
                  <div className="row-actions">
                    {seller.user_id && <Link className="ghost" to={`/lgu/dashboard?tab=messages&with=${seller.user_id}`}><MessageCircle size={16} /> Message</Link>}
                    <ModerationAction
                      suspended={seller.status === 'suspended'}
                      onSuspend={(reason, notes) => suspendSeller.mutate({ id: seller.id, reason, notes })}
                      onReinstate={(reason, notes) => reinstateSeller.mutate({ id: seller.id, reason, notes })}
                    />
                  </div>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No sellers registered in your municipality yet." />}
          </Section>
        </>
      )}
      {tab === 'user-reports' && (
        <UserReportsPanel
          endpointBase="/lgu"
          queryKey="lgu-user-reports"
          scopeLabel="Complaints filed by buyers about sellers in your municipality, and by your sellers about buyers. Reviewing a report never changes an account's standing on its own -- suspend from the Sellers or Users tab if that is what the case calls for."
        />
      )}
      {tab === 'notices' && <SellerNoticesPanel />}
      {tab === 'disputes' && <DisputesPanel />}
      {tab === 'support' && <SupportTicketsAdminPanel scope="lgu" />}
      {tab === 'orders' && (
        <Section title="Orders">
          <p className="helper-text">Every order placed with a seller in your municipality. If a buyer never confirms a delivery that is Out for Delivery, you can mark it as received on their behalf once you have confirmed it arrived. It then moves to Seller Earnings for approval.</p>
          <AdminOrderTable rows={lguOrders.data || []} base="/lgu" invalidateKeys={['lgu-orders', 'lgu-earnings', 'lgu-dashboard']} initialOrderNumber={searchParams.get('order')} />
        </Section>
      )}
      {tab === 'earnings' && <SellerEarningsPanel />}
      {tab === 'wallet' && (
        <>
          <StatsRow items={[
            ['Available Balance', currency(wallet.data?.available_balance ?? 0), true],
            ['Pending Balance', currency(wallet.data?.pending_balance ?? 0)],
            ['Processing Withdrawal', currency(wallet.data?.processing_amount ?? 0)],
            ...(Number(wallet.data?.on_hold_amount) > 0 ? [['On Hold (Rejected)', currency(wallet.data.on_hold_amount)]] : []),
            ['Total Revenue', currency(wallet.data?.total_revenue ?? 0)],
            ['Withdrawn Amount', currency(wallet.data?.withdrawn_amount ?? 0)],
          ]} />
          <Section title="Request Withdrawal">
            <p className="helper-text">Withdraws from your municipality&apos;s shared LGU revenue balance. Every LGU admin for your municipality sees the same wallet and withdrawal history.</p>
            <div className="form grid-form">
              <select value={lguWithdrawForm.method} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, method: e.target.value })}>
                <option value="gcash">GCash</option>
                <option value="maya">Maya</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
              <BankTransferFields form={lguWithdrawForm} setForm={setLguWithdrawForm} />
              <input value={lguWithdrawForm.account_name} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, account_name: e.target.value })} placeholder="Account name" />
              <input value={lguWithdrawForm.account_number} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, account_number: e.target.value })} placeholder={accountNumberPlaceholder(lguWithdrawForm.method)} inputMode="numeric" />
              <input value={lguWithdrawForm.amount} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, amount: e.target.value })} placeholder="Amount to withdraw" type="number" min={MIN_WITHDRAWAL} step="0.01" />
            </div>
            <p className="helper-text">Available to withdraw: {currency(wallet.data?.available_balance ?? 0)} · Minimum withdrawal: {currency(MIN_WITHDRAWAL)}</p>
            <BelowMinimumWarning amount={Number(lguWithdrawForm.amount) || 0} />
            <button type="button" onClick={submitLguWithdrawal} disabled={requestLguWithdrawal.isPending || ((Number(lguWithdrawForm.amount) || 0) > 0 && (Number(lguWithdrawForm.amount) || 0) < MIN_WITHDRAWAL)}>{requestLguWithdrawal.isPending ? 'Submitting...' : 'Submit Withdrawal Request'}</button>
            {lguWithdrawFormError && <p className="error">{lguWithdrawFormError}</p>}
            {confirmingLguWithdrawal && (
              <WithdrawalConfirmModal form={lguWithdrawForm} onConfirm={confirmLguWithdrawal} onClose={() => setConfirmingLguWithdrawal(false)} />
            )}
            {requestLguWithdrawal.error && <p className="error">{apiErrorMessage(requestLguWithdrawal.error, 'Could not submit withdrawal request.')}</p>}
            {requestLguWithdrawal.isSuccess && <p className="helper-text">Withdrawal request submitted for {currency(requestLguWithdrawal.data?.amount)}. You&apos;ll be notified once the Super Admin pays it out.</p>}
          </Section>
          <Section title="Withdrawal Requests">
            {confirmLguWithdrawalReceived.isError && <p className="error">{apiErrorMessage(confirmLguWithdrawalReceived.error, 'Could not confirm payout receipt.')}</p>}
            <PeriodFilteredRows rows={wallet.data?.withdrawal_requests || []} noun="withdrawal requests made">
              {(shown) => (shown.length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Amount</span>
                  <span>Method</span>
                  <span>Account</span>
                  <span>Status</span>
                  <span>Requested By</span>
                  <span>Requested</span>
                  <span>Notes</span>
                </div>
                {shown.map((request) => (
                  <Fragment key={request.id}>
                  <div className="table-row">
                    <span>{currency(request.amount)}</span>
                    <span>{withdrawalMethodLabel(request.method, request.bank_name)}</span>
                    <span>{request.account_name} · {request.account_number}</span>
                    <span><Badge status={request.status}>{withdrawalStatusLabel(request.status)}</Badge></span>
                    <span>{request.requestedBy?.name || 'Unknown'}</span>
                    <span>{new Date(request.created_at).toLocaleDateString()}</span>
                    <span>
                      {['rejected', 'rejected_final'].includes(request.status) && request.rejection_reason && `Reason: ${request.rejection_reason}`}
                      {request.status === 'paid' && request.paid_at && `Paid on ${new Date(request.paid_at).toLocaleDateString()}`}
                      {request.status === 'paid' && request.auto_completed_at && ' '}
                      {request.status === 'pending' && 'Awaiting Super Admin review.'}
                      {request.status === 'approved' && 'Approved; waiting for the Super Admin to send the payout.'}
                      {request.status === 'sent' && (request.recipient_confirmed_at
                        ? `An LGU admin confirmed receipt on ${new Date(request.recipient_confirmed_at).toLocaleDateString()}. Awaiting Super Admin completion.`
                        : <>
                          Payout sent {request.sent_at ? `on ${new Date(request.sent_at).toLocaleDateString()}` : ''}. Once it arrives, confirm it here.{' '}
                          <button type="button" className="ghost" onClick={() => {
                            if (window.confirm(`Confirm your municipality received ${currency(request.amount)}? This will complete the withdrawal.`)) confirmLguWithdrawalReceived.mutate(request.id)
                          }} disabled={confirmLguWithdrawalReceived.isPending}>Money Received</button>
                        </>)}
                      {request.status === 'paid' && request.auto_completed_at && (
                        <>
                          <span>Automatically marked paid after no confirmation. If your municipality has not received it, </span>
                          {request.sent_by && <><Link className="link-action" to={`/lgu/dashboard?tab=messages&with=${request.sent_by}`}>message the Super Admin</Link> or </>}
                          <Link className="link-action" to="/lgu/dashboard?tab=support">contact Support</Link>.
                        </>
                      )}
                    </span>
                  </div>
                  {request.status === 'rejected' && (
                    <RejectedWithdrawalActions
                      request={request}
                      disputeEndpoint={`/lgu/lgu-withdrawals/${request.id}/dispute`}
                      invalidateKeys={['lgu-wallet', 'lgu-dashboard']}
                      rejectedBy="the Super Admin"
                    />
                  )}
                  {request.status === 'rejected_final' && (
                    <FinalRejectionHelp base="/lgu/dashboard" contactLabel="the Super Admin" returnedAmount={request.amount} />
                  )}
                  </Fragment>
                ))}
              </div>
            ) : <EmptyState message="No withdrawal requests yet." />)}
            </PeriodFilteredRows>
          </Section>
          <Section title="Revenue History">
            <p className="helper-text">
              How your municipality earned each amount: when you approve a seller&apos;s order earnings, the LGU gets 2% of the order total
              (4% for orders approved before October 9, 2026). Click an order number to see its details.
            </p>
            <PeriodFilteredRows rows={wallet.data?.revenue_history || []} noun="settlements" dateKey="settled_at">
              {(shown) => (shown.length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Order</span>
                  <span>Seller</span>
                  <span>Order Total</span>
                  <span>LGU Share</span>
                  <span>Seller Received</span>
                  <span>Settled Date</span>
                </div>
                {shown.map((settlement) => (
                  <div className="table-row" key={settlement.id}>
                    <span>
                      {settlement.order?.order_number
                        ? <Link className="seller-name-link" to={`/lgu/dashboard?tab=orders&order=${settlement.order.order_number}`}>#{settlement.order.order_number}</Link>
                        : 'N/A'}
                    </span>
                    <span><SellerLink id={settlement.seller_profile_id} name={settlement.sellerProfile?.hatchery_name} /></span>
                    <span>{currency(settlement.gross_amount)}</span>
                    <span><strong>{currency(settlement.lgu_share)}</strong> <small className="muted">({percentLabel(settlement.lgu_percent)}% of {currency(settlement.gross_amount)})</small></span>
                    <span>{currency(settlement.seller_share)}</span>
                    <span>{settlement.settled_at ? new Date(settlement.settled_at).toLocaleDateString() : 'N/A'}</span>
                  </div>
                ))}
              </div>
            ) : <EmptyState message="No settled revenue yet." />)}
            </PeriodFilteredRows>
          </Section>
        </>
      )}
      {tab === 'users' && (
        <>
          <Section title="Buyers in Your Municipality">
            <UserDirectoryList users={usersDirectory.data?.buyers} messageBasePath="/lgu/dashboard" emptyMessage="No buyers registered in your municipality yet." />
          </Section>
          <Section title="Sellers in Your Municipality">
            <UserDirectoryList users={usersDirectory.data?.sellers} messageBasePath="/lgu/dashboard" emptyMessage="No sellers registered in your municipality yet." />
          </Section>
        </>
      )}
      {tab === 'reports' && (
        <Section title="Analytics" actions={<PeriodFilter period={reportsPeriod} onChange={setReportsPeriod} />}>
          <p className="helper-text">Graphs reflect activity in your municipality for the selected period. The summary below remains all-time.</p>
          <div className="charts-grid">
            <CategoryBarChart title="Listings by Status" data={(reports.data?.listings_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.approval_status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.approval_status)} />
            <CategoryBarChart title="Listings by Species" data={reports.data?.listings_by_species} dataKey="total" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} />
            <CategoryBarChart title="Sellers by Verification Status" data={(reports.data?.sellers_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.status)} />
            <TimeSeriesChart title={`Orders Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.orders_over_time} dataKey="count" color="var(--color-primary)" />
          </div>
          <StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0, false, '/lgu/dashboard?tab=sellers'], ['Listings', reports.data?.listings ?? 0, false, '/lgu/dashboard?tab=listings']]} />

          <h3>Municipality Revenue (LGU Share)</h3>
          <p className="helper-text">Revenue values represent your municipality&apos;s LGU Share only, for the selected period.</p>
          <StatsRow items={[
            ['Total Revenue', currency(reports.data?.revenue_cards?.total_revenue ?? 0), false, '/lgu/dashboard?tab=wallet'],
            ['Available Balance', currency(reports.data?.revenue_cards?.available_balance ?? 0), true, '/lgu/dashboard?tab=wallet'],
            ['Total Withdrawn', currency(reports.data?.revenue_cards?.total_withdrawn ?? 0), false, '/lgu/dashboard?tab=wallet'],
          ]} />
          <div className="charts-grid">
            <TimeSeriesChart title={`LGU Revenue Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.lgu_revenue_over_time} dataKey="amount" color="var(--color-teal)" valueFormatter={currency} />
            <TimeSeriesChart title={`Completed Orders (${periodLabel(reportsPeriod)})`} data={reports.data?.lgu_revenue_over_time} dataKey="count" color="var(--color-primary)" />
            <TimeSeriesChart title={`Withdrawal Trends (${periodLabel(reportsPeriod)})`} data={reports.data?.lgu_withdrawal_trends} dataKey="amount" color="var(--chart-violet)" valueFormatter={currency} />
            <CategoryBarChart title="Revenue by Fish Species" data={reports.data?.revenue_by_species} dataKey="amount" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} valueFormatter={currency} />
            <CategoryBarChart title="Revenue by Seller" data={reports.data?.revenue_by_seller} dataKey="amount" nameKey="seller" colorFor={() => 'var(--color-teal)'} valueFormatter={currency} />
          </div>
          <h3>Export Reports</h3>
          <p className="helper-text">Exports respect the period selected above.</p>
          <ReportExportControls
            typeOptions={[
              { value: 'sales', label: 'Sales Report' },
              { value: 'revenue', label: 'Revenue Report' },
              { value: 'sellers', label: 'Seller Report' },
            ]}
            exportEndpoint="/lgu/reports/export"
            period={reportsPeriod}
          />
        </Section>
      )}
      {tab === 'activity-log' && <ActivityLogPanel scope="lgu" />}
      {tab === 'reviews' && <ReviewsAndRatingsSection data={reviews.data} scope="lgu" scopeLabel="in your municipality" />}
      {tab === 'notifications' && (
        <>
          <Section
            title="Notifications"
            actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllRead.isPending} onClick={() => markAllRead.mutate()} />}
          >
            <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} getLink={notificationLink} emptyMessage="No unread notifications." />
          </Section>
          <NotificationHistory getLink={notificationLink} unreadCount={lgu.data?.notifications?.length ?? 0} />
        </>
      )}
      {tab === 'profile' && <AdminProfilePanel endpointBase="/lgu" />}
    </Dashboard>
  )
}

export function LguListingReviewPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')
  const [showDelete, setShowDelete] = useState(false)
  const [deleteReason, setDeleteReason] = useState('')

  const listingQuery = useQuery({
    queryKey: ['lgu-listing', id],
    queryFn: async () => mapListing((await api.get(`/lgu/listings/${id}`)).data),
    retry: false,
  })
  const listing = listingQuery.data

  const sellerQuery = useQuery({
    queryKey: ['lgu-listing-seller', listing?.seller_profile_id],
    queryFn: async () => (await api.get(`/sellers/${listing.seller_profile_id}`)).data,
    enabled: !!listing?.seller_profile_id,
    retry: false,
  })

  const goToApprovals = () => {
    queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
    navigate('/lgu/dashboard?tab=approvals')
  }
  const goToListingManagement = () => {
    queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
    queryClient.invalidateQueries({ queryKey: ['lgu-listings'] })
    navigate('/lgu/dashboard?tab=listings')
  }

  const approve = useMutation({
    mutationFn: async () => (await api.patch(`/lgu/listings/${id}/approve`)).data,
    onSuccess: goToApprovals,
  })
  const reject = useMutation({
    mutationFn: async () => (await api.patch(`/lgu/listings/${id}/reject`, { reason: reason.trim() })).data,
    onSuccess: goToApprovals,
  })
  const destroyListing = useMutation({
    mutationFn: async () => (await api.delete(`/lgu/listings/${id}`, { data: { reason: deleteReason.trim() } })).data,
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
          <p>This listing may have been removed, or is outside your municipality.</p>
          <Link className="button" to="/lgu/dashboard?tab=approvals">Back to Approvals</Link>
        </section>
      </main>
    )
  }

  const seller = sellerQuery.data?.seller
  const busy = approve.isPending || reject.isPending || destroyListing.isPending
  const canModerate = listing.municipality_id === getSession()?.municipality_id

  return (
    <main>
      <div className="detail-page">
        <img className="detail-art" src={resolveListingImage(listing)} alt={listing.title || listing.species} />
        <div className="detail-stack">
          <ListingDetailPanel item={listing} />
          <p className="helper-text">Listed on {new Date(listing.created_at).toLocaleDateString()}</p>
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

      {canModerate ? (
        <>
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
        </>
      ) : (
        <p className="helper-text">This listing belongs to a seller outside your municipality. You can view it here as part of the marketplace, but moderation actions are only available for listings within your own municipality.</p>
      )}

      <Link className="ghost" to="/lgu/dashboard?tab=approvals">Back to Approvals</Link>
    </main>
  )
}

export function LguAdminRow({ admin, municipalities, onUpdate, onDisable, onEnable }) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(admin.name)
  const [municipalityId, setMunicipalityId] = useState(admin.municipality_id || '')

  const save = () => {
    onUpdate(admin.id, { name, municipality_id: municipalityId }).then(() => setEditing(false))
  }

  return (
    <div className="card action">
      <div>
        {editing ? (
          <div className="form grid-form">
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name" />
            <select value={municipalityId} onChange={(e) => setMunicipalityId(e.target.value)}>
              <option value="">Select municipality</option>
              {municipalities.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
          </div>
        ) : (
          <>
            <div className="card-row"><strong>{admin.name}</strong><Badge status={admin.status} /></div>
            <p>{admin.email} · {admin.municipality?.name || 'Unassigned'}</p>
          </>
        )}
      </div>
      <div className="row-actions">
        <Link className="ghost" to={`/admin/dashboard?tab=messages&with=${admin.id}`}><MessageCircle size={16} /> Message</Link>
        {editing ? (
          <button type="button" onClick={save}>Save</button>
        ) : (
          <button type="button" className="ghost" onClick={() => setEditing(true)}>Edit</button>
        )}
        <ModerationAction
          suspended={admin.status === 'disabled'}
          onSuspend={(reason, notes) => onDisable(admin.id, reason, notes)}
          onReinstate={(reason, notes) => onEnable(admin.id, reason, notes)}
        />
      </div>
    </div>
  )
}