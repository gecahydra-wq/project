import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { Bot, Fish, MessageCircle, Search, ShoppingBag, ShoppingCart, Star, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { CategoryBarChart, TimeSeriesChart } from '../../components/charts.jsx'
import { MarketplaceBrowser } from '../../components/listings.jsx'
import { SuspendedAccountNotice } from '../../components/moderation.jsx'
import { MarkAllReadButton, NotificationHistory, NotificationStack, UnreadMessagesNotice } from '../../components/notifications.jsx'
import { OrderTable } from '../../components/orders.jsx'
import { BuyerSettingsForm } from '../../components/profile.jsx'
import { EmptyState, LoadingState } from '../../components/states.jsx'
import { Badge, Dashboard, PeriodFilter, PeriodFilteredRows, Section, SellerLink, StatsRow } from '../../components/ui.jsx'
import { api, queryClient } from '../../lib/api.js'
import { speciesChartColor, statusChartColor, statusChartLabel } from '../../lib/chartColors.js'
import { currency, formatOrderDate, formatQuantity } from '../../lib/format.js'
import { minimumOrder, resolveListingImage, unitLabel, unitLabelPlural } from '../../lib/listing.js'
import { notificationLinkFor } from '../../lib/notifications.js'
import { updateSessionUser } from '../../lib/session.js'
import { MessagesPanel } from '../messaging/index.jsx'
import { SupportPanel } from '../support/index.jsx'



/**
 * One saved line in the Buyer's cart. Quantity edits are committed on blur
 * rather than on every keystroke, so typing "150" doesn't fire three PATCHes
 * (and three stock checks) on the way there.
 */
function CartItemRow({ item, onUpdateQuantity, onRemove, onBuy, busy }) {
  const [qty, setQty] = useState(item.quantity)
  // Re-sync the input when the server's quantity changes under us -- e.g. a
  // rejected over-stock edit, which snaps the field back to what's actually
  // saved. Adjusting state during render (rather than in an effect) is the
  // supported way to do this; it re-renders before anything is painted.
  const [syncedQuantity, setSyncedQuantity] = useState(item.quantity)
  if (item.quantity !== syncedQuantity) {
    setSyncedQuantity(item.quantity)
    setQty(item.quantity)
  }
  const listing = item.listing

  // Never send below the seller's Minimum Order -- the backend refuses it and
  // the buyer would just see an error for something we can round up for them.
  const minimum = minimumOrder(listing)
  const commitQuantity = () => {
    const next = Math.max(minimum, Number(qty) || minimum)
    if (next !== Number(qty)) setQty(next)
    if (next === item.quantity) return
    onUpdateQuantity(next)
  }

  return (
    <div className="card action">
      <div className="cart-item-main">
        <img className="listing-thumb" src={resolveListingImage(listing)} alt={listing?.title || 'Listing'} />
        <div>
          <div className="card-row">
            <strong>{listing?.id ? <Link to={`/buyer/listings/${listing.id}?source=cart`}>{listing.title}</Link> : (listing?.title || 'Listing no longer available')}</strong>
            {!item.available && <Badge tone="danger">Unavailable</Badge>}
          </div>
          <p className="muted">
            <SellerLink id={listing?.seller_profile_id || listing?.sellerProfile?.id} name={listing?.sellerProfile?.hatchery_name} />
            {listing?.municipality?.name ? ` · ${listing.municipality.name}` : ''} · {currency(item.unit_price)}/{unitLabel(listing)}
            {minimum > 1 ? ` · min ${formatQuantity(minimum, listing)}` : ''}
          </p>
          {item.issue && <p className="error">{item.issue}</p>}
        </div>
      </div>
      <div className="row-actions cart-item-actions">
        <label className="cart-qty">
          Qty ({unitLabelPlural(listing)})
          <input
            type="number"
            min={minimum}
            max={listing?.quantity || undefined}
            value={qty}
            onChange={(e) => setQty(e.target.value)}
            onBlur={commitQuantity}
          />
        </label>
        <strong className="price">{currency(item.line_total)}</strong>
        <button type="button" disabled={!item.available || busy} onClick={onBuy}>
          {busy ? 'Starting...' : 'Buy Now'}
        </button>
        <button type="button" className="ghost danger" onClick={onRemove}><Trash2 size={15} /> Remove</button>
      </div>
    </div>
  )
}

/**
 * The Buyer's "buy later" cart -- a shortlist of saved listings, NOT a
 * separate way to buy.
 *
 * Nothing here is reserved: saving a listing doesn't hold stock, so a saved
 * item can sell out or be taken down, and the backend re-checks price and
 * availability on every read (see CartController). Buy Now hands off to the
 * exact same place-order-then-checkout flow as buying from a listing page --
 * one order per listing, because an order IS a single listing in this system
 * (see App\Http\Controllers\Api\OrderController). That's also why there's no
 * "check out everything" button: it would have to silently fan out into N
 * orders and N PayMongo sessions, which isn't what a combined total implies.
 */
function CartPanel() {
  const [buyingId, setBuyingId] = useState(null)

  const cart = useQuery({
    queryKey: ['buyer-cart'],
    queryFn: async () => (await api.get('/cart')).data,
    retry: false,
    placeholderData: { items: [], subtotal: 0, count: 0 },
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['buyer-cart'] })

  const updateQuantity = useMutation({
    mutationFn: async ({ id, quantity }) => (await api.patch(`/cart/${id}`, { quantity })).data,
    onSuccess: invalidate,
    onError: invalidate,
  })
  const removeItem = useMutation({
    mutationFn: async (id) => (await api.delete(`/cart/${id}`)).data,
    onSuccess: invalidate,
  })
  const clearCart = useMutation({
    mutationFn: async () => (await api.delete('/cart')).data,
    onSuccess: invalidate,
  })

  // Mirrors BuyerListingDetailPage's buy flow exactly: place the order, then
  // start checkout and hand the buyer to PayMongo. The cart line is dropped
  // once the order exists -- from that point the order itself is the record,
  // and leaving it saved would invite a duplicate order on the way back.
  const buyNow = useMutation({
    mutationFn: async (item) => {
      const order = await api.post('/orders', { fingerling_listing_id: item.listing.id, quantity: item.quantity })
      await api.delete(`/cart/${item.id}`).catch(() => {})
      return (await api.post(`/orders/${order.data.id}/checkout`)).data
    },
    onSuccess: (data) => window.location.assign(data.checkout_url),
    onError: () => { setBuyingId(null); invalidate() },
  })

  const items = cart.data?.items || []

  return (
    <Section
      title="Cart"
      actions={items.length ? (
        <button type="button" className="ghost" onClick={() => { if (window.confirm('Remove every saved item from your cart?')) clearCart.mutate() }}>
          Clear Cart
        </button>
      ) : null}
    >
      <p className="helper-text">Listings you&apos;ve saved to buy later. Saving doesn&apos;t reserve stock or hold the price -- both are checked again when you buy, and each item checks out as its own order.</p>
      {cart.isLoading && <LoadingState label="Loading your cart..." />}
      {buyNow.isError && <p className="error">{buyNow.error?.response?.data?.message || 'Could not start checkout for that item.'}</p>}
      {updateQuantity.isError && <p className="error">{updateQuantity.error?.response?.data?.message || 'Could not update that quantity.'}</p>}
      {items.length ? (
        <>
          <div className="item-list">
            {items.map((item) => (
              <CartItemRow
                key={item.id}
                item={item}
                busy={buyingId === item.id}
                onUpdateQuantity={(quantity) => updateQuantity.mutate({ id: item.id, quantity })}
                onRemove={() => removeItem.mutate(item.id)}
                onBuy={() => { setBuyingId(item.id); buyNow.mutate(item) }}
              />
            ))}
          </div>
          <div className="checkout-bar">
            <strong>Available items total: {currency(cart.data?.subtotal ?? 0)}</strong>
            <span className="helper-text">Each item is paid for separately.</span>
          </div>
        </>
      ) : !cart.isLoading && (
        <EmptyState
          icon={ShoppingBag}
          title="Your cart is empty"
          message="Browse the marketplace and use Add to Cart to save fingerlings you want to buy later."
        />
      )}
    </Section>
  )
}

export function BuyerDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const { data, isPlaceholderData } = useQuery({
    queryKey: ['buyer-dashboard'],
    queryFn: async () => (await api.get('/buyer/dashboard')).data,
    retry: false,
    refetchInterval: 30000,
    placeholderData: {
      active_orders: 2,
      completed_orders: 8,
      unread_messages: 0,
      notifications: [],
      recent_orders: [],
      recent_reviews: [],
    },
  })

  const orders = data?.recent_orders || []
  // The dashboard payload carries only the five most recent orders (for the
  // overview); the Orders tab needs every one of them.
  const allOrders = useQuery({
    queryKey: ['buyer-orders'],
    queryFn: async () => (await api.get('/orders')).data,
    enabled: tab === 'orders',
    retry: false,
    refetchInterval: 30000,
  })
  const refreshOrders = () => {
    queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
    queryClient.invalidateQueries({ queryKey: ['buyer-orders'] })
    /* The open View Order popup fetches the full transaction under this key;
       an action taken inside it (confirm, cancel, review) must refresh the
       panel it just changed. */
    queryClient.invalidateQueries({ queryKey: ['order-detail'] })
  }
  const notifications = (data?.notifications || []).filter((notification) => !visibleNotificationIds.includes(notification.id))
  const handleMarkRead = (id) => {
    setVisibleNotificationIds((current) => (current.includes(id) ? current : [...current, id]))
    markRead.mutate(id)
  }
  const markRead = useMutation({
    mutationFn: async (id) => (await api.patch(`/buyer/notifications/${id}/read`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] }),
  })
  const markAllRead = useMutation({
    mutationFn: async () => (await api.patch('/buyer/notifications/read-all')).data,
    onSuccess: () => {
      setVisibleNotificationIds((current) => [...current, ...notifications.map((n) => n.id)])
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
    },
  })
  const submitReview = useMutation({
    mutationFn: async ({ orderId, rating, title, comment }) => (await api.post(`/orders/${orderId}/review`, { rating, title, comment })).data,
    onSuccess: refreshOrders,
  })
  const handleReview = (orderId, { rating, title, comment }) => submitReview.mutateAsync({ orderId, rating, title, comment })

  // Abandoning a PayMongo checkout no longer fails the order (see
  // OrderController::markPaymentCancelled), so the buyer needs a way back in.
  // /checkout mints a fresh PayMongo session for any order still 'placed'.
  const resumePayment = useMutation({
    mutationFn: async (orderId) => (await api.post(`/orders/${orderId}/checkout`)).data,
    onSuccess: (data) => window.location.assign(data.checkout_url),
    onError: refreshOrders,
  })
  // The buyer confirms the fingerlings arrived. This is what releases the
  // payment into the seller's LGU earnings queue, so it is the buyer's call
  // alone -- the seller has no equivalent action.
  const confirmReceived = useMutation({
    mutationFn: async (orderId) => (await api.patch(`/orders/${orderId}/confirm-received`)).data,
    onSettled: refreshOrders,
  })
  // The buyer backs out of their own order while it is still fully in their
  // control: unpaid, or paid but not yet confirmed by the seller (a reason is
  // then required -- see OrderController::cancel, which enforces both). The
  // refresh below is what makes the row show the Cancelled status, with the
  // reason on View Details.
  const cancelOrder = useMutation({
    mutationFn: async ({ orderId, reason }) => (await api.patch(`/orders/${orderId}/cancel`, reason ? { cancellation_reason: reason } : {})).data,
    onSuccess: refreshOrders,
  })
  const handleCancelOrder = (orderId, reason) => cancelOrder.mutateAsync({ orderId, reason })
  const cancelPendingOrderId = cancelOrder.isPending ? cancelOrder.variables?.orderId : null
  const updateBuyerProfile = useMutation({
    mutationFn: async (form) => (await api.patch('/buyer/profile', {
      name: form.name,
      email: form.email,
      phone: form.phone,
      address: form.address,
      bio: form.bio,
    })).data,
    onSuccess: (result) => {
      updateSessionUser({ name: result.user.name, email: result.user.email, phone: result.user.phone, profile_picture: result.user.profile_picture })
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
    },
  })

  const [analyticsPeriod, setAnalyticsPeriod] = useState('monthly')
  // Farm assumptions behind the ROI projection. Blank means "use the backend's
  // documented default" (see App\Support\BuyerInvestmentReport), so the farmer
  // only has to type the figures they actually want to change.
  const [assumptions, setAssumptions] = useState({ survival_rate: '', harvest_value_per_piece: '' })
  // The inputs update on every keystroke, but the QUERY only follows once
  // typing settles. Without this, each character was a new query key and so a
  // new request.
  const [appliedAssumptions, setAppliedAssumptions] = useState(assumptions)
  useEffect(() => {
    const timer = setTimeout(() => setAppliedAssumptions(assumptions), 400)
    return () => clearTimeout(timer)
  }, [assumptions])

  const analytics = useQuery({
    queryKey: ['buyer-analytics', analyticsPeriod, appliedAssumptions.survival_rate, appliedAssumptions.harvest_value_per_piece],
    queryFn: async () => (await api.get('/buyer/analytics', {
      params: {
        period: analyticsPeriod,
        // Entered as a percentage, sent as the 0-1 rate the API expects.
        survival_rate: appliedAssumptions.survival_rate === '' ? undefined : Number(appliedAssumptions.survival_rate) / 100,
        harvest_value_per_piece: appliedAssumptions.harvest_value_per_piece === '' ? undefined : Number(appliedAssumptions.harvest_value_per_piece),
      },
    })).data,
    retry: false,
    // Hold on to the last result while the next one loads. Returning the
    // previous data (rather than a fixed skeleton with no `investment` key)
    // is what keeps the ROI panel mounted between keystrokes -- otherwise it
    // unmounted, the input lost focus, and the page jumped to the top.
    placeholderData: (previous) => previous ?? { summary: {}, purchases_over_time: [], orders_by_status: [], top_species: [] },
  })

  return (
    <Dashboard
      title="Buyer Dashboard"
      subtitle="Browse, order, pay, review, and track notifications."
    >
      <SuspendedAccountNotice role="buyer" />
      {tab === 'overview' && (
        <>
          <UnreadMessagesNotice dashboardPath="/buyer/dashboard" />
          <StatsRow items={[
            ['Active Orders', data?.active_orders ?? 0, false, '/buyer/dashboard?tab=orders'],
            ['Completed Orders', data?.completed_orders ?? 0, false, '/buyer/dashboard?tab=orders'],
            ['Unread Messages', data?.unread_messages ?? 0, false, '/buyer/dashboard?tab=messages'],
          ]}
          />
          {/* Mirrors the Seller Dashboard's Recent Orders: the five most recent,
              with the full list a click away through View Order. The Orders tab
              below is still where every order lives. */}
          <Section title="Recent Orders" actions={<Link className="ghost" to="/buyer/dashboard?tab=orders">View All Orders</Link>}>
            <OrderTable
              rows={(orders || []).slice(0, 5)}
              onReview={handleReview}
              onConfirmReceived={(orderId) => confirmReceived.mutateAsync(orderId)}
              confirmPendingOrderId={confirmReceived.isPending ? confirmReceived.variables : null}
              onPay={(orderId) => resumePayment.mutate(orderId)}
              payPendingOrderId={resumePayment.isPending ? resumePayment.variables : null}
              onCancelOrder={handleCancelOrder}
              cancelPendingOrderId={cancelPendingOrderId}
              detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
              paymentView="buyer"
            />
          </Section>
        </>
      )}
      {tab === 'browse' && (
        <Section title="Browse Listings">
          <MarketplaceBrowser detailPath={(item) => `/buyer/listings/${item.id}?source=browse`} />
        </Section>
      )}
      {tab === 'cart' && <CartPanel />}
      {tab === 'support' && <SupportPanel role="buyer" />}
      {tab === 'orders' && (
        <Section title="My Orders">
          {allOrders.isLoading ? <LoadingState label="Loading orders..." /> : (
            <PeriodFilteredRows rows={allOrders.data || orders}>
              {(shown) => (
                <OrderTable
                  rows={shown}
                  onReview={handleReview}
                  onConfirmReceived={(orderId) => confirmReceived.mutateAsync(orderId)}
                  confirmPendingOrderId={confirmReceived.isPending ? confirmReceived.variables : null}
                  onPay={(orderId) => resumePayment.mutate(orderId)}
                  payPendingOrderId={resumePayment.isPending ? resumePayment.variables : null}
                  onCancelOrder={handleCancelOrder}
                  cancelPendingOrderId={cancelPendingOrderId}
                  detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
                  initialOrderNumber={searchParams.get('order')}
                  paymentView="buyer"
                />
              )}
            </PeriodFilteredRows>
          )}
        </Section>
      )}
      {tab === 'messages' && <Section title="Messages"><MessagesPanel initialUserId={searchParams.get('with') ? Number(searchParams.get('with')) : null} /></Section>}
      {tab === 'notifications' && (
        <>
          <Section
            title="Notifications"
            actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllRead.isPending} onClick={() => markAllRead.mutate()} />}
          >
            <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} getLink={notificationLinkFor('buyer')} emptyMessage="No unread notifications." />
          </Section>
          <NotificationHistory getLink={notificationLinkFor('buyer')} unreadCount={data?.notifications?.length ?? 0} showTypeFilter />
        </>
      )}
      {tab === 'analytics' && (
        <Section title="Analytics" actions={<PeriodFilter period={analyticsPeriod} onChange={setAnalyticsPeriod} />}>
          <StatsRow items={[
            ['Total Purchases', analytics.data?.summary?.total_purchases ?? 0],
            ['Total Orders', analytics.data?.summary?.total_orders ?? 0],
            ['Total Spending', currency(analytics.data?.summary?.total_spending ?? 0)],
            ['Favorite Species', analytics.data?.summary?.favorite_species || 'None'],
          ]}
          />
          <div className="charts-grid">
            <TimeSeriesChart title="Purchases Over Time" data={analytics.data?.purchases_over_time} dataKey="count" color="var(--color-primary)" />
            <TimeSeriesChart title="Spending Over Time" data={analytics.data?.purchases_over_time} dataKey="amount" color="var(--color-teal)" valueFormatter={currency} />
            <CategoryBarChart title="Orders by Status" data={(analytics.data?.orders_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.status)} />
            <CategoryBarChart title="Most Purchased Fish Species" data={analytics.data?.top_species} dataKey="quantity" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} />
          </div>
        </Section>
      )}
      {tab === 'analytics' && (
        <BuyerInvestmentPanel
          data={analytics.data}
          assumptions={assumptions}
          setAssumptions={setAssumptions}
          updating={analytics.isFetching}
        />
      )}
      {tab === 'ai' && (
        <Section title="AI Assistant">
          <p>Meet the <strong>AbaiMarket AI Assistant</strong> -- your built-in guide for buying fingerlings and learning fish-farming basics. Open it any time from the floating <strong>AI</strong> button at the bottom-right of every page; your buyer session stays intact.</p>
          <div className="card ai-help-card">
            <div className="ai-capability-head"><span className="top-performer-icon"><Bot size={18} /></span><strong>How to use it</strong></div>
            <ul className="ai-help-list">
              <li>Tap the <strong>AI</strong> button (bottom-right), type your question, and press <strong>Enter</strong> to send.</li>
              <li>Ask naturally and follow up -- it remembers your recent messages, so "and how much is it?" works.</li>
              <li>Write in <strong>English, Filipino, or Cebuano</strong> -- it replies in the language you use.</li>
              <li>It answers <strong>AbaiMarket questions only</strong> and reads live marketplace data, so prices, sellers, and order details stay up to date.</li>
            </ul>
          </div>
          <h3>What you can ask</h3>
          <div className="action-grid">
            <div className="card ai-capability">
              <div className="ai-capability-head"><span className="top-performer-icon"><Search size={18} /></span><strong>Find &amp; compare fingerlings</strong></div>
              <p>Which sellers stock a species, current prices, and what's available right now.</p>
              <p className="ai-example">Try: "Which sellers have tilapia fingerlings?"</p>
            </div>
            <div className="card ai-capability">
              <div className="ai-capability-head"><span className="top-performer-icon"><ShoppingCart size={18} /></span><strong>Track your orders</strong></div>
              <p>Check the status, payment, and delivery of any order by its order number.</p>
              <p className="ai-example">Try: "What's the status of order ORD-1052?"</p>
            </div>
            <div className="card ai-capability">
              <div className="ai-capability-head"><span className="top-performer-icon"><Star size={18} /></span><strong>Check sellers &amp; reviews</strong></div>
              <p>A seller's rating, municipality, and whether they're verified before you buy.</p>
              <p className="ai-example">Try: "Is this seller verified and how are their reviews?"</p>
            </div>
            <div className="card ai-capability">
              <div className="ai-capability-head"><span className="top-performer-icon"><Fish size={18} /></span><strong>Fish-farming guidance</strong></div>
              <p>Species suitability, water quality, stocking, and feeding basics for fingerlings.</p>
              <p className="ai-example">Try: "What water conditions do bangus fingerlings need?"</p>
            </div>
            <div className="card ai-capability">
              <div className="ai-capability-head"><span className="top-performer-icon"><MessageCircle size={18} /></span><strong>Buying &amp; contacting sellers</strong></div>
              <p>How to place an order, pay securely, message a seller, or leave a review.</p>
              <p className="ai-example">Try: "How do I place an order and pay?"</p>
            </div>
          </div>
        </Section>
      )}
      {tab === 'settings' && (
        !data?.profile || isPlaceholderData ? (
          <LoadingState label="Loading profile..." />
        ) : (
          <BuyerSettingsForm
            key={data.profile.id}
            user={data.profile}
            buyerProfile={data.buyer_profile}
            saving={updateBuyerProfile.isPending}
            success={updateBuyerProfile.isSuccess}
            error={updateBuyerProfile.error?.response?.data?.message}
            onSave={(values, options) => updateBuyerProfile.mutate(values, options)}
          />
        )
      )}
    </Dashboard>
  )
}

/**
 * Buyer Turnout / ROI -- the investment view for farmers.
 *
 * Split deliberately into two halves that must never be confused: everything
 * above the "Earnings Calculator" is recorded fact from their own order
 * history, and everything under it is an estimate driven by the two
 * assumptions the farmer sets there. AbaiMarket only sees what they BUY -- it
 * has no idea what they later sold their grown fish for -- so the calculator's
 * outputs stay labelled "Estimated" / "Projected" individually, and its
 * disclaimer says plainly that these are not realised earnings.
 */
function BuyerInvestmentPanel({ data, assumptions, setAssumptions, updating = false }) {
  const investment = data?.investment
  if (!investment) return null

  const { investment: money, turnout, units = [], projection, assumptions: applied, purchase_history: history = [], has_orders: hasOrders = true } = investment
  // With nothing bought, every figure the projection produces is a multiple of
  // zero -- which renders as a real, and terrible, forecast rather than "no
  // data yet". So the calculator is locked until there is at least one order.
  // Deliberately all-time (see BuyerInvestmentReport): the inputs must not
  // enable and disable themselves as the buyer changes the period filter.
  const calculatorLocked = !hasOrders
  const survivalPercent = Math.round((applied?.survival_rate ?? 0.8) * 100)
  const harvestValue = applied?.harvest_value_per_piece ?? 0
  const returnPositive = (projection?.projected_return ?? 0) >= 0

  return (
    <>
      <Section title="Investment Overview">
        <p className="helper-text">
          What you have actually put into fingerlings, taken straight from your completed orders.
        </p>
        <StatsRow items={[
          ['Total Invested', currency(money.total_invested), true],
          ['Committed (Orders in Progress)', currency(money.committed_investment)],
          ['Total Exposure', currency(money.total_exposure)],
          ['Lifetime Invested', currency(money.lifetime_invested)],
          ['Average Order Value', currency(money.average_order_value)],
        ]} />
      </Section>

      <Section title="Buyer Turnout">
        <p className="helper-text">How actively you are buying, and how much of what you start actually completes.</p>
        <StatsRow items={[
          ['Total Orders', turnout.total_orders],
          ['Completed', turnout.completed_orders],
          ['In Progress', turnout.active_orders],
          ['Completion Rate', `${turnout.completion_rate}%`],
          ['Hatcheries Bought From', turnout.sellers_engaged],
        ]} />
        <p className="helper-text">
          {turnout.first_purchase
            ? `First purchase ${formatOrderDate(turnout.first_purchase, { withTime: false })} · Most recent ${formatOrderDate(turnout.last_purchase, { withTime: false })}`
            : 'No completed purchases in this period yet.'}
        </p>
        {units.length > 0 && (
          <div className="unit-breakdown">
            {units.map((unit) => (
              <div className="card unit-breakdown-card" key={unit.unit_type}>
                <strong>{Number(unit.quantity).toLocaleString()} {unit.unit_label}</strong>
                <span className="muted">{unit.orders} order{unit.orders === 1 ? '' : 's'} · {currency(unit.amount)}</span>
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="Earnings Calculator">
        {calculatorLocked && (
          <div className="card roi-locked">
            <p>
              <strong>Buy your first batch of fingerlings to unlock this.</strong>
            </p>
            <p className="helper-text">
              The calculator projects what a batch you already own might return at harvest, so it needs a real purchase to
              work from -- it cannot forecast from nothing. Place an order and it turns on by itself.
            </p>
            <Link className="button" to="/browse">Browse Listings</Link>
          </div>
        )}
        <div className={`card roi-assumptions${calculatorLocked ? ' is-locked' : ''}`} aria-disabled={calculatorLocked}>
          <p className="helper-text">
            <strong>These are estimates, not earnings.</strong> AbaiMarket only records what you buy -- it cannot know what you sell your
            grown fish for. Enter your own survival rate and farm-gate price below and the projection recalculates. This maths needs a
            number of fish, so it covers every purchase whose fish count is known -- stock bought per piece, and stock bought by bulk
            where the seller states how many fish are in one bulk. Anything with no stated count is left out and reported below.
          </p>
          <div className="form grid-form">
            <label className="filter-label">
              Expected survival rate (%)
              <input
                type="number"
                min="0"
                max="100"
                value={assumptions.survival_rate}
                onChange={(e) => setAssumptions({ ...assumptions, survival_rate: e.target.value })}
                placeholder={String(Math.round((applied?.defaults?.survival_rate ?? 0.8) * 100))}
                disabled={calculatorLocked}
              />
              <span className="helper-text">Share of fingerlings you expect to reach harvest.</span>
            </label>
            <label className="filter-label">
              Farm-gate value per harvested fish (₱)
              <input
                type="number"
                min="0"
                step="0.01"
                value={assumptions.harvest_value_per_piece}
                onChange={(e) => setAssumptions({ ...assumptions, harvest_value_per_piece: e.target.value })}
                placeholder={String(applied?.defaults?.harvest_value_per_piece ?? 25)}
                disabled={calculatorLocked}
              />
              <span className="helper-text">What one grown fish sells for in your area.</span>
            </label>
          </div>
          <p className="helper-text roi-applied-line">
            <span>
              {calculatorLocked
                ? 'Projection unavailable until you have an order.'
                : <>Currently projecting at <strong>{survivalPercent}% survival</strong> and <strong>{currency(harvestValue)} per fish</strong>.</>}
            </span>
            {updating && <span className="muted">Updating…</span>}
            {(assumptions.survival_rate || assumptions.harvest_value_per_piece) && (
              <button type="button" className="link-action" onClick={() => setAssumptions({ survival_rate: '', harvest_value_per_piece: '' })}>
                Reset to defaults
              </button>
            )}
          </p>
        </div>

        {projection.pieces_purchased > 0 ? (
          <>
            <StatsRow items={[
              ['Fingerlings Bought', Number(projection.pieces_purchased).toLocaleString()],
              ['Projected Survivors', Number(projection.projected_survivors).toLocaleString()],
              ['Projected Harvest Value', currency(projection.projected_revenue)],
              ['Estimated Return', currency(projection.projected_return), returnPositive],
              ['Estimated ROI', `${projection.projected_roi_percent}%`, returnPositive],
            ]} />
            <div className="roi-insight-grid">
              <div className="card roi-insight">
                <span className="muted">Your cost per fingerling</span>
                <strong>{currency(projection.cost_per_piece)}</strong>
              </div>
              <div className="card roi-insight">
                <span className="muted">Break-even price per harvested fish</span>
                <strong>{currency(projection.break_even_value_per_piece)}</strong>
                <span className="helper-text">Sell above this and this stock turns a profit.</span>
              </div>
              <div className="card roi-insight">
                <span className="muted">Expected losses at {survivalPercent}% survival</span>
                <strong>{Number(projection.projected_losses).toLocaleString()} pcs</strong>
              </div>
            </div>
            {projection.excluded_orders > 0 && (
              <p className="helper-text">
                {projection.excluded_orders} order{projection.excluded_orders === 1 ? ' has' : 's have'} no fish count -- bought by
                kilogram, or by bulk without the seller stating how many fish one bulk holds -- so
                {projection.excluded_orders === 1 ? ' it is' : ' they are'} not included in this projection.
              </p>
            )}
          </>
        ) : (
          <EmptyState message="No purchases with a known fish count in this period yet, so there is nothing to project." />
        )}
      </Section>

      <Section title="Purchase History">
        <p className="helper-text">Your completed purchases in this period.</p>
        {history.length ? (
          <div className="table">
            <div className="table-row first">
              <span>Date</span>
              <span>Order #</span>
              <span>Hatchery</span>
              <span>Species</span>
              <span>Quantity</span>
              <span>Unit Price</span>
              <span>Total</span>
            </div>
            {history.map((row) => (
              <div className="table-row" key={row.order_number}>
                <span className="order-date-cell">
                  {formatOrderDate(row.date, { withTime: false })}
                  <small>{new Date(row.date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small>
                </span>
                <span>{row.order_number}</span>
                <span><SellerLink id={row.seller_profile_id} name={row.seller} fallback="Unknown" /></span>
                <span>{row.species || 'Fingerlings'}</span>
                <span>{Number(row.quantity).toLocaleString()} {row.unit_label}</span>
                <span>{currency(row.unit_price)}</span>
                <span>{currency(row.total_amount)}</span>
              </div>
            ))}
          </div>
        ) : <EmptyState message="No completed purchases in this period." />}
      </Section>
    </>
  )
}