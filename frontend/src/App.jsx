import axios from 'axios'
import { QueryClient, QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import {
  BrowserRouter,
  Link,
  Navigate,
  Outlet,
  Route,
  Routes,
  useLocation,
  useNavigate,
  useSearchParams,
  useParams,
} from 'react-router-dom'
import {
  Archive,
  BarChart3,
  Bell,
  Bot,
  CheckCircle,
  ChevronLeft,
  ChevronRight,
  CircleUserRound,
  Fish,
  Heart,
  History,
  Image as ImageIcon,
  LayoutDashboard,
  LogOut,
  MapPin,
  Megaphone,
  MessageCircle,
  PlayCircle,
  Search,
  ShieldAlert,
  ShieldCheck,
  ShoppingBag,
  ShoppingCart,
  Star,
  Store,
  Trash2,
  UserPlus,
  Users as UsersIcon,
  Video as VideoIcon,
  Wallet,
  X,
  XCircle,
} from 'lucide-react'
import { Fragment, useCallback, useEffect, useRef, useState } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import './App.css'

const queryClient = new QueryClient()
const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

const api = axios.create({ baseURL: API_URL })
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('fishmarket_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const hadSession = localStorage.getItem('fishmarket_token')
    if (error.response?.status === 401 && hadSession) {
      localStorage.removeItem('fishmarket_user')
      localStorage.removeItem('fishmarket_token')
      if (window.location.pathname !== '/login') {
        window.location.replace('/login')
      }
    }
    return Promise.reject(error)
  },
)

const roleRoutes = {
  buyer: '/buyer/dashboard',
  seller: '/seller/dashboard',
  lgu_admin: '/lgu/dashboard',
  super_admin: '/admin/dashboard',
}

const demoUsers = {
  'lgu@gmail.com': { id: 1, name: 'LGU Admin', role: 'lgu_admin', email: 'lgu@gmail.com', municipality: 'Mandaue' },
  'superadmin@gmail.com': { id: 2, name: 'Super Admin', role: 'super_admin', email: 'superadmin@gmail.com', municipality: 'All LGUs' },
}

const SPECIES_PLACEHOLDERS = [
  [/bangus|milkfish/i, '/placeholders/bangus.svg'],
  [/tilapia/i, '/placeholders/tilapia.svg'],
  [/catfish/i, '/placeholders/catfish.svg'],
  [/carp/i, '/placeholders/carp.svg'],
  [/grouper/i, '/placeholders/grouper.svg'],
  [/sea.?bass/i, '/placeholders/sea-bass.svg'],
]
const DEFAULT_PLACEHOLDER_IMAGE = '/placeholders/default.svg'
const DEFAULT_AVATAR_IMAGE = '/placeholders/avatar.svg'
const DEFAULT_COVER_IMAGE = '/placeholders/cover.svg'
// System placeholder bio assigned at seller registration (see AuthController).
// Once the seller is verified it's stale/misleading, so it's hidden then --
// only a real, seller-written description shows on a verified profile.
const DEFAULT_SELLER_DESCRIPTION = 'New hatchery profile pending LGU verification.'
const IMAGE_UPLOAD_ACCEPT = 'image/jpeg,image/png,image/webp'
const LISTING_MEDIA_ACCEPT = 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm'

function resolveListingImage(item) {
  const uploaded = item?.media?.find((media) => media.type === 'photo' && media.url)
  if (uploaded) return uploaded.url
  const species = item?.species || ''
  const match = SPECIES_PLACEHOLDERS.find(([pattern]) => pattern.test(species))
  return match ? match[1] : DEFAULT_PLACEHOLDER_IMAGE
}

function mapListing(item) {
  return {
    ...item,
    seller: item.sellerProfile?.hatchery_name || item.seller_profile_id,
    sellerContactName: item.sellerProfile?.user?.name || '',
    municipality: item.municipality?.name || 'Unknown',
    price: item.price_per_piece,
    status: item.approval_status === 'approved' ? 'Approved' : item.approval_status === 'pending' ? 'Pending' : 'Rejected',
    rating: item.sellerProfile?.rating ?? 0,
    description: item.description || '',
  }
}

function renderStars(rating) {
  const rounded = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)))
  return <span className="stars">{'★'.repeat(rounded)}{'☆'.repeat(5 - rounded)}</span>
}

function currency(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

function withdrawalMethodLabel(method) {
  return ({ gcash: 'GCash', maya: 'Maya', bank_transfer: 'Bank Transfer' })[method] || method
}

/**
 * Turns an axios error into a message a user should actually see -- never a
 * raw framework/validation-exception string. Prefers the first field-specific
 * Laravel validation message (e.g. "This email address is already
 * registered.") over the top-level summary Laravel returns when several
 * fields fail, which reads like "The account name field is required. (and 2
 * more errors)" and tells the user nothing about the other two.
 *
 * Forms that can fail on several fields at once should still check for empty
 * required inputs before submitting (see REQUIRED_FIELDS_MESSAGE), so the
 * common "submitted a blank form" case never has to be described one field at
 * a time.
 */
function apiErrorMessage(err, fallback = 'Something went wrong. Please try again.') {
  if (!err?.response) {
    return 'Cannot reach the AbaiMarket server right now. Please check your connection and try again.'
  }
  const data = err.response.data
  const firstFieldError = data?.errors && Object.values(data.errors)[0]
  if (Array.isArray(firstFieldError) && firstFieldError[0]) return firstFieldError[0]
  if (typeof data?.message === 'string' && data.message) return data.message
  return fallback
}

const REQUIRED_FIELDS_MESSAGE = 'Please fill in all required fields.'

/**
 * True when a withdrawal form is missing anything the server requires, so the
 * form can say "fill in all required fields" once instead of submitting a
 * blank form and getting back a one-field-at-a-time validation summary.
 *
 * This is a UX shortcut, never the authority -- the same rules are enforced
 * server-side (see SellerController::requestWithdrawal and
 * LguController::requestWithdrawal), which also own the checks the client
 * can't make, like the available-balance ceiling.
 */
function withdrawalFormIsIncomplete(form) {
  return !form.method
    || !form.account_name.trim()
    || !form.account_number.trim()
    || !String(form.amount).trim()
    || Number(form.amount) <= 0
}

const BADGE_TONES = {
  approved: 'success',
  verified: 'success',
  completed: 'success',
  released: 'success',
  paid: 'success',
  active: 'success',
  confirmed: 'info',
  in_transit: 'info',
  paid_held: 'info',
  checkout_created: 'info',
  placed: 'warning',
  pending: 'warning',
  on_hold: 'warning',
  rejected: 'danger',
  cancelled: 'danger',
  failed: 'danger',
  suspended: 'danger',
  disabled: 'danger',
}

function badgeTone(status) {
  return BADGE_TONES[String(status || '').toLowerCase().replace(/\s+/g, '_')] || 'neutral'
}

/**
 * Display labels for status values whose stored name doesn't read the way it
 * should on screen. The stored value is never touched -- orders.status stays
 * 'in_transit' -- so this is purely what humans see, and only statuses listed
 * here differ from the default Title Case of their raw value (see
 * statusChartLabel, the single place this is applied).
 *
 * 'in_transit' reads "Out for Delivery" to match what the backend has always
 * called that stage on the order timeline and delivery status (see
 * App\Support\OrderTimeline and App\Support\OrderTransactionPresenter).
 */
const STATUS_LABELS = {
  in_transit: 'Out for Delivery',
}

function Badge({ status, tone, children }) {
  const resolvedTone = tone || badgeTone(status)
  return <span className={`badge badge-${resolvedTone}`}>{children ?? status}</span>
}

// Short, colour-coded role labels -- each role gets its own distinct badge
// colour (see .badge-role-* in App.css) for author headers and comments.
const ROLE_BADGE = {
  buyer: { label: 'Buyer', className: 'badge-role-buyer' },
  seller: { label: 'Seller', className: 'badge-role-seller' },
  lgu_admin: { label: 'LGU', className: 'badge-role-lgu' },
  super_admin: { label: 'Admin', className: 'badge-role-admin' },
}

function RoleBadge({ role }) {
  const info = ROLE_BADGE[role]
  if (!info) return null
  return <span className={`badge ${info.className}`}>{info.label}</span>
}

function EmptyState({ title, message, icon: Icon }) {
  return (
    <div className="empty-state">
      {Icon && <span className="empty-state-icon"><Icon size={24} /></span>}
      {title && <strong>{title}</strong>}
      {message && <p>{message}</p>}
    </div>
  )
}

function LoadingState({ label = 'Loading...' }) {
  return (
    <div className="loading-state">
      <span className="spinner" />
      <span>{label}</span>
    </div>
  )
}

function truncate(text, length = 110) {
  if (!text || text.length <= length) return text
  return `${text.slice(0, length).trimEnd()}...`
}

function getSession() {
  const stored = localStorage.getItem('fishmarket_user')
  return stored ? JSON.parse(stored) : null
}

function updateSessionUser(partial) {
  const current = getSession()
  if (!current) return
  localStorage.setItem('fishmarket_user', JSON.stringify({ ...current, ...partial }))
}

function getHomeRoute() {
  const session = getSession()
  return session?.role ? roleRoutes[session.role] || '/' : '/'
}

function sellerProfilePath(id) {
  const session = getSession()
  if (session?.role === 'buyer') return `/buyer/sellers/${id}`
  if (session?.role === 'seller') return `/seller/sellers/${id}`
  if (session?.role === 'lgu_admin') return `/lgu/sellers/${id}`
  if (session?.role === 'super_admin') return `/admin/sellers/${id}`
  return `/sellers/${id}`
}

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route element={<PublicLayout />}>
            <Route path="/" element={<LandingPage />} />
            <Route path="/browse" element={<BrowsePage />} />
            <Route path="/sellers" element={<SellersPage />} />
            <Route path="/about" element={<AboutPage />} />
            <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />
          <Route path="/auth/google/callback" element={<GoogleCallbackPage />} />
          <Route path="/listing/:id" element={<ListingDetailPage />} />
          <Route path="/sellers/:id" element={<SellerProfilePage />} />
          <Route path="/payment-success" element={<PaymentSuccessPage />} />
          <Route path="/payment-cancelled" element={<PaymentCancelledPage />} />
        </Route>
          <Route path="/buyer/dashboard" element={<Protected allowed={['buyer']}><BuyerDashboard /></Protected>} />
          <Route path="/buyer/listings/:id" element={<Protected allowed={['buyer']}><BuyerListingDetailPage /></Protected>} />
          <Route path="/buyer/sellers/:id" element={<Protected allowed={['buyer']}><SellerProfilePage /></Protected>} />
          <Route path="/seller/dashboard" element={<Protected allowed={['seller']}><SellerDashboard /></Protected>} />
          <Route path="/seller/sellers/:id" element={<Protected allowed={['seller']}><SellerProfilePage /></Protected>} />
          <Route path="/seller/buyers/:id" element={<Protected allowed={['seller']}><BuyerProfileForSellerPage /></Protected>} />
          <Route path="/lgu/dashboard" element={<Protected allowed={['lgu_admin']}><LguDashboard /></Protected>} />
          <Route path="/lgu/listings/:id" element={<Protected allowed={['lgu_admin']}><LguListingReviewPage /></Protected>} />
          <Route path="/lgu/sellers/:id" element={<Protected allowed={['lgu_admin']}><SellerProfilePage /></Protected>} />
          <Route path="/admin/dashboard" element={<Protected allowed={['super_admin']}><SuperAdminDashboard /></Protected>} />
          <Route path="/admin/listings/:id" element={<Protected allowed={['super_admin']}><SuperAdminListingReviewPage /></Protected>} />
          <Route path="/admin/sellers/:id" element={<Protected allowed={['super_admin']}><SellerProfilePage /></Protected>} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

function PublicLayout() {
  const homeRoute = getHomeRoute()
  const session = getSession()
  return (
    <>
      <header className="site-header">
        <Link className="brand" to={homeRoute}><span><Fish size={22} /></span>AbaiMarket</Link>
        <nav>
          <Link to="/">Home</Link>
          <Link to="/browse">Browse</Link>
          <Link to="/sellers">Sellers</Link>
          <Link to="/about">About</Link>
        </nav>
        {!session && (
          <div className="nav-actions">
            <Link className="ghost" to="/login">Login</Link>
            <Link className="button" to="/register">Register</Link>
          </div>
        )}
      </header>
      <Outlet />
      <FloatingAi />
    </>
  )
}

function Protected({ allowed, children }) {
  const session = getSession()
  if (!session) return <Navigate to="/login" replace />
  if (!allowed.includes(session.role)) return <Navigate to={roleRoutes[session.role] || '/login'} replace />
  return <AppShell user={session}>{children}</AppShell>
}

function AppShell({ user, children }) {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const homeRoute = roleRoutes[user.role] || '/'
  const menu = {
    buyer: [['Dashboard', '/buyer/dashboard?tab=overview', LayoutDashboard], ['Browse', '/buyer/dashboard?tab=browse', Search], ['Cart', '/buyer/dashboard?tab=cart', ShoppingBag], ['Orders', '/buyer/dashboard?tab=orders', ShoppingCart], ['Messages', '/buyer/dashboard?tab=messages', MessageCircle], ['Notifications', '/buyer/dashboard?tab=notifications', Bell], ['Analytics', '/buyer/dashboard?tab=analytics', BarChart3], ['AI Assistant', '/buyer/dashboard?tab=ai', Bot], ['Profile', '/buyer/dashboard?tab=settings', ShieldCheck]],
    seller: [['Dashboard', '/seller/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/seller/dashboard?tab=marketplace', Search], ['Listings', '/seller/dashboard?tab=listings', Store], ['Orders', '/seller/dashboard?tab=orders', ShoppingCart], ['Messages', '/seller/dashboard?tab=messages', MessageCircle], ['Wallet', '/seller/dashboard?tab=wallet', Wallet], ['Notifications', '/seller/dashboard?tab=notifications', Bell], ['Analytics', '/seller/dashboard?tab=analytics', BarChart3], ['Profile', '/seller/dashboard?tab=profile', ShieldCheck]],
    lgu_admin: [['Dashboard', '/lgu/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/lgu/dashboard?tab=marketplace', Search], ['Listing Management', '/lgu/dashboard?tab=listings', Store], ['Approvals', '/lgu/dashboard?tab=approvals', CheckCircle], ['Sellers', '/lgu/dashboard?tab=sellers', ShieldCheck], ['Seller Earnings', '/lgu/dashboard?tab=earnings', Wallet], ['LGU Wallet', '/lgu/dashboard?tab=wallet', Wallet], ['Messages', '/lgu/dashboard?tab=messages', MessageCircle], ['Notifications', '/lgu/dashboard?tab=notifications', Bell], ['Reports', '/lgu/dashboard?tab=reports', BarChart3], ['Activity Log', '/lgu/dashboard?tab=activity-log', History], ['Reviews & Ratings', '/lgu/dashboard?tab=reviews', Star], ['Users', '/lgu/dashboard?tab=users', UsersIcon], ['Profile', '/lgu/dashboard?tab=profile', CircleUserRound]],
    super_admin: [['Dashboard', '/admin/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/admin/dashboard?tab=marketplace', Search], ['Listing Management', '/admin/dashboard?tab=listings', Store], ['LGU Admins', '/admin/dashboard?tab=lgu-admins', ShieldCheck], ['Sellers', '/admin/dashboard?tab=sellers', Store], ['Users', '/admin/dashboard?tab=users', UsersIcon], ['Reviews & Ratings', '/admin/dashboard?tab=reviews', Star], ['Transactions', '/admin/dashboard?tab=transactions', Wallet], ['Payout Management', '/admin/dashboard?tab=payouts', Wallet], ['Municipalities', '/admin/dashboard?tab=municipalities', MapPin], ['Announcements', '/admin/dashboard?tab=announcements', Megaphone], ['Messages', '/admin/dashboard?tab=messages', MessageCircle], ['Notifications', '/admin/dashboard?tab=notifications', Bell], ['Moderation Log', '/admin/dashboard?tab=moderation', ShieldAlert], ['Activity Log', '/admin/dashboard?tab=activity-log', History], ['Reports', '/admin/dashboard?tab=reports', BarChart3], ['Profile', '/admin/dashboard?tab=profile', CircleUserRound]],
  }[user.role]

  async function logout() {
    try {
      await api.post('/auth/logout')
    } catch {
      // Token may already be expired or revoked; proceed with local logout regardless.
    } finally {
      localStorage.removeItem('fishmarket_user')
      localStorage.removeItem('fishmarket_token')
      navigate('/login')
    }
  }

  return (
    <div className="shell">
      <aside className="sidebar">
        <Link className="brand" to={homeRoute}><span><Fish size={22} /></span>AbaiMarket</Link>
        <div className="profile-chip">
          <Avatar src={user.profile_picture} alt={user.name} className="profile-chip-avatar" />
          <div className="profile-chip-info">
            <strong className="profile-chip-name">{user.name}</strong>
            <span className="profile-chip-meta">
              <RoleBadge role={user.role} />
              {user.municipality && <span className="profile-chip-municipality">{user.municipality}</span>}
            </span>
          </div>
        </div>
        <nav className="side-nav">
          {menu.map(([label, path, Icon]) => <Link key={label} to={path} className={path.includes(tab) ? 'active' : ''}><Icon size={18} />{label}</Link>)}
        </nav>
        <button className="ghost full" onClick={logout} type="button"><LogOut size={18} />Logout</button>
      </aside>
      <main className="app-main">{children}</main>
      <FloatingAi />
    </div>
  )
}

function roleLabel(role) {
  return ({ buyer: 'Buyer', seller: 'Seller', lgu_admin: 'LGU Admin', super_admin: 'Super Admin' })[role]
}

function LandingPage() {
  const listingsQuery = useQuery({
    queryKey: ['listings'],
    queryFn: async () => (await api.get('/listings')).data.map(mapListing),
    retry: false,
    placeholderData: [],
  })
  const sellersQuery = useQuery({
    queryKey: ['sellers'],
    queryFn: async () => (await api.get('/sellers')).data,
    retry: false,
    placeholderData: [],
  })
  const featured = listingsQuery.data || []
  const featuredSellers = (sellersQuery.data || []).map((seller) => ({
    id: seller.id,
    name: seller.hatchery_name,
    municipality: seller.municipality?.name || 'Unknown',
    rating: seller.rating,
    verified: seller.verified,
    listings: seller.listings_count ?? 0,
    profile_picture: seller.profile_picture,
  }))
  const verifiedSellerCount = featuredSellers.filter((seller) => seller.verified).length

  return (
    <main>
      <section className="hero">
        <div className="hero-copy">
          <p className="eyebrow">Government-supported aquaculture marketplace</p>
          <h1>Fresh fish fingerlings from verified local hatcheries.</h1>
          <p className="lead">Search species, compare sellers, coordinate orders, pay through PayMongo, and get farming guidance from Gemini AI.</p>
          <div className="hero-search"><Search size={20} /><input placeholder="Search Bangus, Tilapia, Mandaue..." /><Link className="button" to="/browse">Search</Link></div>
          <div className="hero-actions"><Link className="button" to="/register">Start buying</Link><Link className="ghost" to="/register">Register hatchery</Link></div>
        </div>
        <div className="market-panel">
          <Stat value={featured.length} label="Active listings" />
          <Stat value={verifiedSellerCount} label="Verified sellers" />
          <Stat value="Escrow" label="Secure PayMongo checkout" />
        </div>
      </section>
      <Section title="Featured Listings">
        {featured.length ? <ListingGrid items={featured.slice(0, 3)} /> : <EmptyState message="No listings available yet. Check back soon as verified sellers add their stock." />}
      </Section>
      <Section title="Featured Sellers">
        {featuredSellers.length ? <SellerGrid items={featuredSellers.slice(0, 3)} /> : <EmptyState message="No sellers registered yet." />}
      </Section>
      <Section title="How It Works"><div className="steps"><Step n="1" t="Register" d="Buyers and sellers create verified marketplace accounts." /><Step n="2" t="Order & Pay" d="Buyers place orders and pay through PayMongo Checkout." /><Step n="3" t="LGU Oversight" d="LGU admins verify sellers and approve local listings." /><Step n="4" t="Release" d="Super Admin releases held seller funds after completion." /></div></Section>
      <Section title="Supported Species"><div className="species-list">{['Bangus', 'Tilapia', 'Grouper', 'Catfish', 'Sea Bass', 'Carp'].map((s) => <span key={s}>{s}</span>)}</div></Section>
      <AboutPage compact />
      <footer>AbaiMarket - LGU, Sellers, and Fish Farmers working together for local aquaculture.</footer>
    </main>
  )
}

function BrowsePage() {
  const [filters, setFilters] = useState({ q: '', species: 'All', municipality: 'All' })
  const { data = [] } = useQuery({
    queryKey: ['listings'],
    queryFn: async () => (await api.get('/listings')).data.map(mapListing),
    retry: false,
    placeholderData: [],
  })
  const filtered = data.filter((item) => {
    const haystack = `${item.title} ${item.species} ${item.seller} ${item.municipality}`.toLowerCase()
    return haystack.includes(filters.q.toLowerCase()) && (filters.species === 'All' || item.species === filters.species) && (filters.municipality === 'All' || item.municipality === filters.municipality)
  })
  return (
    <main className="page-grid">
      <aside className="filter-card">
        <h2>Advanced Filters</h2>
        <label className="filter-label">Search<input placeholder="Search listings" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} /></label>
        <label className="filter-label">Species<select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value })}><option>All</option>{['Bangus', 'Tilapia', 'Grouper', 'Catfish', 'Sea Bass', 'Carp'].map((s) => <option key={s}>{s}</option>)}</select></label>
        <label className="filter-label">Municipality<select value={filters.municipality} onChange={(e) => setFilters({ ...filters, municipality: e.target.value })}><option>All</option>{['Mandaue', 'Consolacion', 'Compostela', 'Talisay', 'Lapu-Lapu', 'Carmen'].map((s) => <option key={s}>{s}</option>)}</select></label>
      </aside>
      {filtered.length ? <ListingGrid items={filtered} /> : <EmptyState message="No listings match your filters yet." />}
    </main>
  )
}

function MarketplaceBrowser({ detailPath }) {
  const [filters, setFilters] = useState({ q: '', species: 'All', municipality: 'All' })
  const { data = [] } = useQuery({
    queryKey: ['listings'],
    queryFn: async () => (await api.get('/listings')).data.map(mapListing),
    retry: false,
    placeholderData: [],
  })
  const filtered = data.filter((item) => {
    const haystack = `${item.title} ${item.species} ${item.seller} ${item.municipality}`.toLowerCase()
    return haystack.includes(filters.q.toLowerCase()) && (filters.species === 'All' || item.species === filters.species) && (filters.municipality === 'All' || item.municipality === filters.municipality)
  })
  return (
    <div className="buyer-browse">
      <div className="filter-card inline">
        <label className="filter-label">Search<input placeholder="Search listings" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} /></label>
        <label className="filter-label">Species<select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value })}><option>All</option>{['Bangus', 'Tilapia', 'Grouper', 'Catfish', 'Sea Bass', 'Carp'].map((s) => <option key={s}>{s}</option>)}</select></label>
        <label className="filter-label">Municipality<select value={filters.municipality} onChange={(e) => setFilters({ ...filters, municipality: e.target.value })}><option>All</option>{['Mandaue', 'Consolacion', 'Compostela', 'Talisay', 'Lapu-Lapu', 'Carmen'].map((s) => <option key={s}>{s}</option>)}</select></label>
      </div>
      {filtered.length ? (
        <ListingGrid items={filtered} detailPath={detailPath} />
      ) : (
        <EmptyState message="No listings available yet. Check back soon as verified sellers add their stock." />
      )}
    </div>
  )
}

function ListingGrid({ items, mode = 'public', onSelect, detailPath }) {
  return <div className="listing-grid">{items.map((item) => <ListingCard key={item.id} item={item} mode={mode} onSelect={onSelect} detailPath={detailPath} />)}</div>
}

function ListingCard({ item, mode = 'public', onSelect, detailPath }) {
  const linkTarget = typeof detailPath === 'function' ? detailPath(item) : detailPath || `/listing/${item.id}`
  return (
    <article className="card listing">
      <div className="listing-media">
        <img className="listing-image" src={resolveListingImage(item)} alt={item.title || item.species || 'Fingerlings listing'} />
        <span className="listing-status-tag"><Badge status={item.status} /></span>
      </div>
      <h3>{item.title}</h3>
      <p className="listing-seller-row">
        <Avatar src={item.sellerProfile?.profile_picture} alt={item.seller} className="listing-seller-avatar" />
        {item.sellerProfile?.id ? <Link className="seller-name-link" to={sellerProfilePath(item.sellerProfile.id)} onClick={(e) => e.stopPropagation()}>{item.seller}</Link> : item.seller}{item.sellerContactName && item.sellerContactName !== item.seller ? ` (${item.sellerContactName})` : ''} · {item.municipality}
      </p>
      <p className="listing-rating"><span title={`${item.rating}/5`}>{renderStars(item.rating)}</span> <span className="muted">{Number(item.rating || 0).toFixed(1)}/5</span></p>
      {item.description && (
        <p className="listing-description-preview">
          {truncate(item.description)}
          {item.description.length > 110 && <Link className="read-more" to={linkTarget}>Read more</Link>}
        </p>
      )}
      <div className="listing-price-row">
        <span className="listing-price">{currency(item.price)}<small>/pc</small></span>
        {Number(item.quantity) <= 0 ? (
          <Badge tone="danger">Out of Stock</Badge>
        ) : (
          <span className="listing-stock">{Number(item.quantity).toLocaleString()} pcs</span>
        )}
      </div>
      {mode === 'buyer' ? (
        <button className="button full" type="button" onClick={() => onSelect?.(item)}>View Details</button>
      ) : (
        <Link className="button full" to={linkTarget}>View Details</Link>
      )}
    </article>
  )
}

function ListingDetailPanel({ item, isBuyer = false, checkout, qty, setQty, onPay, addToCart }) {
  const navigate = useNavigate()
  const session = getSession()
  const outOfStock = Number(item.quantity) <= 0
  const safeQty = Math.min(Math.max(Number(qty) || 1, 1), Number(item.quantity) || 1)
  const sellerUserId = item.sellerProfile?.user_id
  const canChat = sellerUserId && (!session || ['buyer', 'lgu_admin', 'super_admin'].includes(session.role))
  const chatSeller = () => {
    const chatPath = `${roleRoutes[session?.role] || '/buyer/dashboard'}?tab=messages&with=${sellerUserId}`
    if (!session) {
      navigate('/login', { state: { from: chatPath } })
    } else {
      navigate(chatPath)
    }
  }
  return (
    <article className="card listing-detail-panel">
      <div className="card-row">
        <h3>{item.title}</h3>
        {outOfStock && <Badge tone="danger">Out of Stock</Badge>}
        <Badge status={item.status} />
      </div>
      {item.description ? (
        <p className="listing-description">{item.description}</p>
      ) : (
        <p className="helper-text">No description provided by the seller.</p>
      )}
      <div className="stats-inline">
        <Stat value={currency(item.price)} label="Per piece" highlight />
        <Stat value={item.quantity.toLocaleString()} label="Available" />
        <Stat value={`${item.rating}/5`} label="Seller rating" />
      </div>
      <div className="detail-meta">
        <span className="listing-seller-row"><strong>Hatchery/Farm:</strong> <Avatar src={item.sellerProfile?.profile_picture} alt={item.seller} className="listing-seller-avatar" /> {item.sellerProfile?.id ? <Link to={sellerProfilePath(item.sellerProfile.id)}>{item.seller}</Link> : item.seller}</span>
        {item.sellerContactName && item.sellerContactName !== item.seller && <span><strong>Seller:</strong> {item.sellerContactName}</span>}
        <span><strong>Municipality:</strong> {item.municipality}</span>
      </div>
      <MediaGallery media={item.media} />
      {isBuyer && (
        <>
          {outOfStock ? (
            <p className="helper-text">This item is currently unavailable.</p>
          ) : (
            <label>Quantity<input type="number" min="1" max={item.quantity} value={qty} onChange={(e) => setQty(e.target.value)} /></label>
          )}
          <div className="checkout-bar">
            <strong>Total: {currency(outOfStock ? 0 : safeQty * item.price)}</strong>
            {addToCart && (
              <button className="ghost" type="button" disabled={outOfStock || addToCart.isPending} onClick={() => addToCart.mutate(safeQty)}>
                <ShoppingBag size={16} /> {addToCart.isPending ? 'Adding...' : 'Add to Cart'}
              </button>
            )}
            <button onClick={onPay} type="button" disabled={outOfStock}>{outOfStock ? 'Out of Stock' : 'Pay with PayMongo'}</button>
          </div>
          {addToCart?.isSuccess && (
            <p className="helper-text">
              Saved to your cart. <Link to="/buyer/dashboard?tab=cart">View cart</Link>
            </p>
          )}
          {addToCart?.error && <p className="error">{addToCart.error.response?.data?.message || 'Could not add this listing to your cart.'}</p>}
          {checkout?.error && <p className="error">{checkout.error.message}</p>}
        </>
      )}
      {!isBuyer && <p className="helper-text">Payment is reserved for buyer accounts only.</p>}
      {canChat && (
        <button className="ghost" type="button" onClick={chatSeller}>
          <MessageCircle size={16} /> Chat Seller
        </button>
      )}
    </article>
  )
}

function MediaGallery({ media, title = 'Seller Care Photos & Videos' }) {
  const [enlargedIndex, setEnlargedIndex] = useState(null)
  const viewable = (media || []).filter((item) => item.url)

  const showPrev = useCallback(() => setEnlargedIndex((i) => (i - 1 + viewable.length) % viewable.length), [viewable.length])
  const showNext = useCallback(() => setEnlargedIndex((i) => (i + 1) % viewable.length), [viewable.length])

  useEffect(() => {
    if (enlargedIndex == null) return undefined
    const handleKey = (e) => {
      if (e.key === 'Escape') setEnlargedIndex(null)
      if (e.key === 'ArrowLeft') showPrev()
      if (e.key === 'ArrowRight') showNext()
    }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [enlargedIndex, showPrev, showNext])

  if (!media?.length) return null
  const enlarged = enlargedIndex != null ? viewable[enlargedIndex] : null

  return (
    <div className="media-gallery">
      {title && <h4>{title}</h4>}
      <div className="media-grid">
        {media.map((item) => (
          <div
            className={`media-tile ${item.url ? 'clickable' : ''}`}
            key={item.id}
            onClick={() => item.url && setEnlargedIndex(viewable.indexOf(item))}
          >
            {item.url ? (
              item.type === 'video' ? (
                <div className="media-video-preview">
                  <video src={item.url} muted playsInline preload="metadata" />
                  <span className="media-play-badge"><PlayCircle size={40} /></span>
                </div>
              ) : (
                <img src={item.url} alt="Farm photo" />
              )
            ) : (
              <span className="media-placeholder">{item.type === 'video' ? <VideoIcon size={22} /> : <ImageIcon size={22} />}</span>
            )}
          </div>
        ))}
      </div>
      {enlarged && (
        <div className="lightbox-overlay" onClick={() => setEnlargedIndex(null)}>
          <button type="button" className="lightbox-close" onClick={() => setEnlargedIndex(null)} aria-label="Close preview"><X size={22} /></button>
          {viewable.length > 1 && (
            <>
              <button type="button" className="lightbox-nav lightbox-prev" onClick={(e) => { e.stopPropagation(); showPrev() }} aria-label="Previous media"><ChevronLeft size={28} /></button>
              <button type="button" className="lightbox-nav lightbox-next" onClick={(e) => { e.stopPropagation(); showNext() }} aria-label="Next media"><ChevronRight size={28} /></button>
            </>
          )}
          {enlarged.type === 'video' ? (
            <video className="lightbox-video" src={enlarged.url} controls autoPlay onClick={(e) => e.stopPropagation()} />
          ) : (
            <img className="lightbox-image" src={enlarged.url} alt="Farm photo" onClick={(e) => e.stopPropagation()} />
          )}
        </div>
      )}
    </div>
  )
}

function Avatar({ src, alt, className = '' }) {
  return <img className={`avatar ${className}`} src={src || DEFAULT_AVATAR_IMAGE} alt={alt} />
}

function ImageUploadControl({ src, placeholder, alt, label, shape = 'circle', uploading, onUpload, onRemove, error }) {
  const inputRef = useRef(null)
  const [preview, setPreview] = useState(null)
  const displaySrc = (uploading && preview) || src || placeholder

  const handleChange = (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setPreview(URL.createObjectURL(file))
    onUpload(file)
  }

  return (
    <div className={`image-upload image-upload-${shape}`}>
      <img className={`image-upload-preview image-upload-preview-${shape}`} src={displaySrc} alt={alt} />
      <div className="image-upload-actions">
        <button type="button" className="ghost" onClick={() => inputRef.current?.click()} disabled={uploading}>
          {uploading ? 'Uploading...' : src ? `Replace ${label}` : `Upload ${label}`}
        </button>
        {src && onRemove && (
          <button type="button" className="ghost" onClick={onRemove} disabled={uploading}>Remove</button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={IMAGE_UPLOAD_ACCEPT} hidden onChange={handleChange} />
      {error && <p className="error">{error}</p>}
    </div>
  )
}

function ListingImageManager({ listingId, media, onChange }) {
  const inputRef = useRef(null)
  const items = media || []
  const maxImages = 5

  const uploadMedia = useMutation({
    mutationFn: async (files) => {
      const formData = new FormData()
      files.forEach((file) => formData.append('photos[]', file))
      return (await api.post(`/listings/${listingId}/media`, formData)).data
    },
    onSuccess: (listing) => onChange(listing.media),
  })
  const deleteMedia = useMutation({
    mutationFn: async (mediaId) => (await api.delete(`/listings/${listingId}/media/${mediaId}`)).data,
    onSuccess: (listing) => onChange(listing.media),
  })
  const reorderMedia = useMutation({
    mutationFn: async (order) => (await api.patch(`/listings/${listingId}/media/reorder`, { order })).data,
    onSuccess: (listing) => onChange(listing.media),
  })

  const remainingSlots = maxImages - items.length

  const handleFiles = (e) => {
    const files = Array.from(e.target.files || []).slice(0, remainingSlots)
    e.target.value = ''
    if (files.length) uploadMedia.mutate(files)
  }

  const move = (index, direction) => {
    const target = index + direction
    if (target < 0 || target >= items.length) return
    const reordered = [...items]
    ;[reordered[index], reordered[target]] = [reordered[target], reordered[index]]
    reorderMedia.mutate(reordered.map((item) => item.id))
  }

  const busy = uploadMedia.isPending || deleteMedia.isPending || reorderMedia.isPending

  return (
    <div className="listing-image-manager">
      <div className="listing-image-grid">
        {items.map((item, index) => (
          <div className="listing-image-thumb" key={item.id}>
            {item.type === 'video' ? <video src={item.url} controls /> : <img src={item.url} alt="Farm photo" />}
            {index === 0 && <span className="pill listing-image-primary">Primary</span>}
            <div className="listing-image-thumb-actions">
              <button type="button" onClick={() => move(index, -1)} disabled={busy || index === 0} title="Move earlier">‹</button>
              <button type="button" onClick={() => deleteMedia.mutate(item.id)} disabled={busy} title="Remove media">Remove</button>
              <button type="button" onClick={() => move(index, 1)} disabled={busy || index === items.length - 1} title="Move later">›</button>
            </div>
          </div>
        ))}
        {remainingSlots > 0 && (
          <button type="button" className="listing-image-add" onClick={() => inputRef.current?.click()} disabled={busy}>
            + Add media<span className="muted">{items.length}/{maxImages}</span>
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={LISTING_MEDIA_ACCEPT} multiple hidden onChange={handleFiles} />
      {(uploadMedia.error || deleteMedia.error || reorderMedia.error) && (
        <p className="error">
          {uploadMedia.error?.response?.data?.message || deleteMedia.error?.response?.data?.message || reorderMedia.error?.response?.data?.message || 'Could not update listing images.'}
        </p>
      )}
    </div>
  )
}

function StagedImagePicker({ files, onAdd, onRemove, maxImages = 5 }) {
  const inputRef = useRef(null)
  const remainingSlots = maxImages - files.length

  const handleFiles = (e) => {
    const selected = Array.from(e.target.files || []).slice(0, remainingSlots)
    e.target.value = ''
    if (selected.length) onAdd(selected)
  }

  return (
    <div className="listing-image-manager">
      <div className="listing-image-grid">
        {files.map((staged, index) => (
          <div className="listing-image-thumb" key={staged.previewUrl}>
            {staged.file.type.startsWith('video/') ? <video src={staged.previewUrl} controls /> : <img src={staged.previewUrl} alt="Selected photo" />}
            {index === 0 && <span className="pill listing-image-primary">Primary</span>}
            <div className="listing-image-thumb-actions">
              <button type="button" onClick={() => onRemove(index)}>Remove</button>
            </div>
          </div>
        ))}
        {remainingSlots > 0 && (
          <button type="button" className="listing-image-add" onClick={() => inputRef.current?.click()}>
            + Add media<span className="muted">{files.length}/{maxImages}</span>
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={LISTING_MEDIA_ACCEPT} multiple hidden onChange={handleFiles} />
    </div>
  )
}

function ListingDetailPage() {
  const { id } = useParams()
  const session = getSession()
  const isBuyer = session?.role === 'buyer'
  const [qty, setQty] = useState(1)

  const { data: item, isLoading, isError } = useQuery({
    queryKey: ['listing', id],
    queryFn: async () => mapListing((await api.get(`/listings/${id}`)).data),
    retry: false,
  })

  const checkout = useMutation({
    mutationFn: async () => {
      if (!isBuyer) throw new Error('Buyer login required to pay with PayMongo.')
      const safeQty = Math.min(Math.max(Number(qty) || 1, 1), Number(item.quantity) || 1)
      const order = await api.post('/orders', { fingerling_listing_id: item.id, quantity: safeQty })
      return (await api.post(`/orders/${order.data.id}/checkout`)).data
    },
    onSuccess: (data) => window.location.assign(data.checkout_url),
  })

  if (isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (isError || !item) return <main className="auth-page"><section className="result-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to="/browse">Back to Browse</Link></section></main>

  return (
    <main className="detail-page">
      <img className="detail-art" src={resolveListingImage(item)} alt={item.title || item.species} />
      <ListingDetailPanel item={item} isBuyer={isBuyer} checkout={checkout} qty={qty} setQty={setQty} onPay={() => checkout.mutate()} />
    </main>
  )
}

function BuyerListingDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const sourceTab = searchParams.get('source') || 'browse'
  const [qty, setQty] = useState(1)
  const { data: item, isLoading, isError } = useQuery({
    queryKey: ['buyer-listing', id],
    queryFn: async () => mapListing((await api.get(`/listings/${id}`)).data),
    retry: false,
  })
  const buyListing = useMutation({
    mutationFn: async () => {
      const safeQty = Math.min(Math.max(Number(qty) || 1, 1), Number(item.quantity) || 1)
      const order = await api.post('/orders', { fingerling_listing_id: item.id, quantity: safeQty })
      return (await api.post(`/orders/${order.data.id}/checkout`)).data
    },
    onSuccess: (data) => window.location.assign(data.checkout_url),
  })
  const addToCart = useMutation({
    mutationFn: async (quantity) => (await api.post('/cart', { fingerling_listing_id: item.id, quantity })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['buyer-cart'] }),
  })

  if (isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (isError || !item) return <main className="auth-page"><section className="result-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to={`/buyer/dashboard?tab=${sourceTab}`}>Back to Browse</Link></section></main>

  return (
    <main className="detail-page">
      <img className="detail-art" src={resolveListingImage(item)} alt={item.title || item.species} />
      <div className="detail-stack">
        <ListingDetailPanel item={item} isBuyer checkout={buyListing} qty={qty} setQty={setQty} onPay={() => buyListing.mutate()} addToCart={addToCart} />
        <Link className="ghost" to={`/buyer/dashboard?tab=${sourceTab}`}>{sourceTab === 'cart' ? 'Back to Cart' : 'Back to Browse'}</Link>
      </div>
    </main>
  )
}

function GoogleIcon(props) {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" {...props}>
      <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.9c1.7-1.57 2.7-3.87 2.7-6.62Z" />
      <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.9-2.26c-.8.54-1.84.86-3.06.86-2.35 0-4.34-1.59-5.05-3.72H.9v2.33A9 9 0 0 0 9 18Z" />
      <path fill="#FBBC05" d="M3.95 10.7A5.4 5.4 0 0 1 3.67 9c0-.59.1-1.17.28-1.7V4.97H.9A9 9 0 0 0 0 9c0 1.45.35 2.83.9 4.03l3.05-2.33Z" />
      <path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .9 4.97l3.05 2.33C4.66 5.17 6.65 3.58 9 3.58Z" />
    </svg>
  )
}

function VerificationNotice({ email, lead }) {
  const [sent, setSent] = useState(false)
  const resend = useMutation({
    mutationFn: async () => (await api.post('/email/resend', { email })).data,
    onSuccess: () => setSent(true),
  })
  return (
    <div className="verification-notice">
      <p>{lead}</p>
      <p className="helper-text">
        We sent a verification link to <strong>{email}</strong>. Open it to activate your account, then come back and log in.
      </p>
      {sent && <p className="helper-text verification-sent">Verification email sent again -- check your inbox and spam folder.</p>}
      <div className="success-actions">
        <button type="button" className="ghost" onClick={() => resend.mutate()} disabled={resend.isPending || !email}>
          {resend.isPending ? 'Sending...' : 'Resend Verification Email'}
        </button>
        <Link className="button" to="/login">Back to Login</Link>
      </div>
      {resend.isError && <p className="error">Could not resend the verification email. Please try again.</p>}
    </div>
  )
}

function LoginPage() {
  const { register, handleSubmit, formState: { errors } } = useForm({ defaultValues: { email: '', password: '' } })
  // Strip whitespace from the password as it's typed/pasted, exactly like the
  // registration form -- see blockSpaceKey/stripSpaces above.
  const passwordField = register('password')
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const session = getSession()
  const [unverifiedEmail, setUnverifiedEmail] = useState(searchParams.get('resend_email') || null)
  useEffect(() => {
    if (session?.role) {
      navigate(roleRoutes[session.role] || '/', { replace: true })
    }
  }, [session, navigate])
  const login = useMutation({
    mutationFn: async (values) => {
      try {
        const { data } = await api.post('/auth/login', values)
        return data
      } catch (err) {
        if (err.response) {
          throw new Error(apiErrorMessage(err, 'Invalid email or password.'), { cause: err })
        }
        // No response at all (backend unreachable) -- fall back to the
        // built-in demo accounts (LGU/Super Admin) so the app stays usable
        // for a quick look without a running API, same as before.
        const user = demoUsers[values.email]
        if (!user) throw new Error(apiErrorMessage(err), { cause: err })
        return { user, token: `demo-${user.role}` }
      }
    },
    onSuccess: ({ user, token }) => {
      localStorage.setItem('fishmarket_user', JSON.stringify(user))
      localStorage.setItem('fishmarket_token', token)
      window.location.replace(location.state?.from || roleRoutes[user.role] || '/')
    },
    onError: (err) => {
      if (err.cause?.response?.data?.unverified) {
        setUnverifiedEmail(err.cause.response.data.email)
      }
    },
  })

  if (unverifiedEmail) {
    return (
      <AuthCard title="Verify Your Email" subtitle="One more step before you can log in.">
        <VerificationNotice email={unverifiedEmail} lead="Please verify your email address before logging in." />
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Login" subtitle="One account gateway for all AbaiMarket roles.">
      <form onSubmit={handleSubmit((v) => login.mutate({ email: (v.email || '').trim(), password: stripSpaces(v.password) }))} className="form">
        <input {...register('email', { validate: (value) => validateEmail(value) || true })} placeholder="Email" />
        <input
          {...passwordField}
          type="password"
          placeholder="Password"
          onKeyDown={blockSpaceKey}
          onChange={(e) => { e.target.value = stripSpaces(e.target.value); passwordField.onChange(e) }}
        />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <button type="submit" disabled={login.isPending}>{login.isPending ? 'Logging in...' : 'Login'}</button>
        {login.error && <p className="error">{login.error.message}</p>}
        {searchParams.get('google_error') && <p className="error">Google sign-in didn't go through. Please try again or use your email and password.</p>}
      </form>
      <div className="auth-divider"><span>or</span></div>
      <a className="ghost full google-button" href={`${API_URL}/auth/google/redirect`}>
        <GoogleIcon /> Continue with Google
      </a>
    </AuthCard>
  )
}

// Passwords may not contain whitespace. blockSpaceKey stops the space key from
// typing anything; stripSpaces removes any whitespace that slips in via paste
// or autofill. Applied to every password field -- including login -- so the
// behaviour is identical everywhere. This is safe: registration has always
// forbidden whitespace in passwords, so no stored password contains any and
// stripping it on login can never lock a real account out.
function blockSpaceKey(e) {
  if (e.key === ' ') e.preventDefault()
}

function stripSpaces(value) {
  return (value || '').replace(/\s/g, '')
}

// Shared password policy -- mirrors App\Rules\StrongPassword on the backend so
// the same rules and messages are enforced client-side before submitting.
// Returns a user-facing error message, or '' when the password is valid.
const PASSWORD_HELP = 'Use 8-64 characters with an uppercase letter, a lowercase letter, a number, and a special character. No spaces.'

function validatePassword(value) {
  const v = value || ''
  if (/\s/.test(v)) return 'Password cannot contain spaces.'
  if (v.length < 8) return 'Password must be at least 8 characters.'
  if (v.length > 64) return 'Password must be at most 64 characters.'
  if (!/[A-Z]/.test(v)) return 'Password must contain an uppercase letter.'
  if (!/[a-z]/.test(v)) return 'Password must contain a lowercase letter.'
  if (!/[0-9]/.test(v)) return 'Password must contain a number.'
  if (!/[^A-Za-z0-9]/.test(v)) return 'Password must contain a special character.'
  return ''
}

// Shared email policy -- mirrors App\Support\AuthValidation on the backend.
// Leading/trailing spaces are trimmed first (the backend trims them too), then
// any internal space is rejected, then the basic address format is checked.
// Returns a user-facing error message, or '' when the email is valid.
function validateEmail(value) {
  const v = (value || '').trim()
  if (/\s/.test(v)) return 'Email address must not contain spaces.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Please enter a valid email address.'
  return ''
}

function RegisterPage() {
  const { register, handleSubmit, watch, formState: { errors } } = useForm({ defaultValues: { role: 'buyer', municipality_id: '' } })
  const role = watch('role')
  const isSeller = role === 'seller'
  const passwordField = register('password', {
    validate: (value) => validatePassword(value) || true,
  })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
    placeholderData: [],
  })
  const [registeredEmail, setRegisteredEmail] = useState(null)
  const registerUser = useMutation({
    mutationFn: async (values) => {
      // Buyers/Farmers don't have a municipality -- never send the field
      // for them, even if a stale value lingers from switching roles.
      const payload = { ...values, email: (values.email || '').trim() }
      if (payload.role !== 'seller') delete payload.municipality_id
      try {
        return (await api.post('/auth/register', payload)).data
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'Could not create your account. Please try again.'), { cause: err })
      }
    },
    onSuccess: (data) => setRegisteredEmail(data.user?.email || null),
  })

  if (registeredEmail) {
    return (
      <AuthCard title="Check Your Email" subtitle="You're almost there.">
        <VerificationNotice email={registeredEmail} lead="Your AbaiMarket account has been created." />
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Register" subtitle="Registration is available only for buyers and sellers.">
      <form onSubmit={handleSubmit((v) => registerUser.mutate(v))} className="form">
        <input {...register('name')} placeholder="Full name / Hatchery name" />
        <input {...register('email', { validate: (value) => validateEmail(value) || true })} placeholder="Email" />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <input
          {...passwordField}
          type="password"
          placeholder="Password"
          onKeyDown={blockSpaceKey}
          onChange={(e) => { e.target.value = stripSpaces(e.target.value); passwordField.onChange(e) }}
        />
        <p className="helper-text">{PASSWORD_HELP}</p>
        {errors.password && <p className="error">{errors.password.message}</p>}
        <select {...register('role')}><option value="buyer">Buyer / Fish Farmer</option><option value="seller">Seller / Hatchery</option></select>
        {isSeller && (
          <>
            <select {...register('municipality_id', { required: isSeller })} defaultValue="">
              <option value="" disabled>Select municipality</option>
              {(municipalitiesQuery.data || []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
            {errors.municipality_id && <p className="error">Please select your hatchery's municipality.</p>}
          </>
        )}
        <button type="submit" disabled={registerUser.isPending}>{registerUser.isPending ? 'Creating account...' : 'Create Account'}</button>
        {registerUser.error && <p className="error">{registerUser.error.message}</p>}
      </form>
      <div className="auth-divider"><span>or</span></div>
      <a className="ghost full google-button" href={`${API_URL}/auth/google/redirect`}>
        <GoogleIcon /> Continue with Google
      </a>
    </AuthCard>
  )
}

function GoogleCallbackPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    const token = searchParams.get('token')
    if (!token) {
      navigate('/login?google_error=1', { replace: true })
      return
    }
    localStorage.setItem('fishmarket_token', token)
    api.get('/auth/me')
      .then(({ data: user }) => {
        localStorage.setItem('fishmarket_user', JSON.stringify(user))
        window.location.replace(roleRoutes[user.role] || '/')
      })
      .catch(() => {
        localStorage.removeItem('fishmarket_token')
        setFailed(true)
        setTimeout(() => navigate('/login?google_error=1', { replace: true }), 1500)
      })
    // Runs once: exchanges the one-time token in the URL for the session.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <main className="auth-page">
      <section className="result-card">
        {failed ? (
          <>
            <p className="eyebrow">Google Sign-In</p>
            <h1>Sign-in failed</h1>
            <p>Redirecting you back to login...</p>
          </>
        ) : (
          <LoadingState label="Finishing Google sign-in..." />
        )}
      </section>
    </main>
  )
}

const AUTH_BENEFITS = [
  [ShieldCheck, 'LGU-verified sellers in every municipality'],
  [Wallet, 'Secure PayMongo escrow checkout'],
  [Bot, 'Gemini AI farming assistant built in'],
]

function AuthCard({ title, subtitle, children }) {
  return (
    <main className="auth-page">
      <div className="auth-layout">
        <section className="auth-brand-panel">
          <Link className="brand" to="/"><span><Fish size={22} /></span>AbaiMarket</Link>
          <h2>Fresh fingerlings, verified hatcheries, one marketplace.</h2>
          <ul className="auth-benefits">
            {AUTH_BENEFITS.map(([Icon, text]) => (
              <li key={text}><Icon size={18} />{text}</li>
            ))}
          </ul>
        </section>
        <section className="auth-card">
          <p className="eyebrow">AbaiMarket Access</p>
          <h1>{title}</h1>
          <p>{subtitle}</p>
          {children}
        </section>
      </div>
    </main>
  )
}

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

  const commitQuantity = () => {
    const next = Math.max(1, Number(qty) || 1)
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
            {listing?.sellerProfile?.hatchery_name || 'Unknown seller'}
            {listing?.municipality?.name ? ` · ${listing.municipality.name}` : ''} · {currency(item.unit_price)}/pc
          </p>
          {item.issue && <p className="error">{item.issue}</p>}
        </div>
      </div>
      <div className="row-actions cart-item-actions">
        <label className="cart-qty">
          Qty
          <input
            type="number"
            min="1"
            max={listing?.quantity || undefined}
            value={qty}
            onChange={(e) => setQty(e.target.value)}
            onBlur={commitQuantity}
          />
        </label>
        <strong>{currency(item.line_total)}</strong>
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

function BuyerDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const { data, isPlaceholderData } = useQuery({
    queryKey: ['buyer-dashboard'],
    queryFn: async () => (await api.get('/buyer/dashboard')).data,
    retry: false,
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
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] }),
  })
  const handleReview = (orderId, { rating, title, comment }) => submitReview.mutateAsync({ orderId, rating, title, comment })
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
  const analytics = useQuery({
    queryKey: ['buyer-analytics', analyticsPeriod],
    queryFn: async () => (await api.get('/buyer/analytics', { params: { period: analyticsPeriod } })).data,
    retry: false,
    placeholderData: { summary: {}, purchases_over_time: [], orders_by_status: [], top_species: [] },
  })

  return (
    <Dashboard
      title="Buyer Dashboard"
      subtitle="Browse, order, pay, review, and track notifications."
    >
      {tab === 'overview' && (
        <>
          <AnnouncementBanner />
          <StatsRow items={[['Active Orders', data?.active_orders ?? 0], ['Completed Orders', data?.completed_orders ?? 0], ['Unread Messages', data?.unread_messages ?? 0]]} />
          <Section title="Recent Orders"><OrderTable rows={orders} onReview={handleReview} showPaymentStatus={false} /></Section>
          <Section title="Notifications"><NotificationStack notifications={notifications.slice(0, 3)} onMarkRead={handleMarkRead} /></Section>
        </>
      )}
      {tab === 'browse' && (
        <Section title="Browse Listings">
          <MarketplaceBrowser detailPath={(item) => `/buyer/listings/${item.id}?source=browse`} />
        </Section>
      )}
      {tab === 'cart' && <CartPanel />}
      {tab === 'orders' && (
        <Section title="My Orders">
          <OrderTable
            rows={orders}
            onReview={handleReview}
            detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
            initialExpandedOrderNumber={searchParams.get('order')}
            showPaymentStatus={false}
          />
        </Section>
      )}
      {tab === 'messages' && <Section title="Messages"><MessagesPanel initialUserId={searchParams.get('with') ? Number(searchParams.get('with')) : null} /></Section>}
      {tab === 'notifications' && (
        <Section
          title="Notifications"
          actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllRead.isPending} onClick={() => markAllRead.mutate()} />}
        >
          <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} />
        </Section>
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
        <>
          <Section title="Profile Settings">
            {!data?.profile || isPlaceholderData ? (
              <LoadingState label="Loading profile..." />
            ) : (
              <BuyerSettingsForm
                key={data.profile.id}
                user={data.profile}
                buyerProfile={data.buyer_profile}
                saving={updateBuyerProfile.isPending}
                success={updateBuyerProfile.isSuccess}
                error={updateBuyerProfile.error?.response?.data?.message}
                onSave={(values) => updateBuyerProfile.mutate(values)}
              />
            )}
          </Section>
          <Section title="Change Password"><ChangePasswordForm /></Section>
        </>
      )}
    </Dashboard>
  )
}

function BuyerSettingsForm({ user, buyerProfile, onSave, saving, success, error }) {
  const [form, setForm] = useState({
    name: user.name || '',
    email: user.email || '',
    phone: user.phone || '',
    address: buyerProfile?.address || '',
    bio: buyerProfile?.bio || '',
  })

  const uploadPicture = useMutation({
    mutationFn: async (file) => {
      const formData = new FormData()
      formData.append('photo', file)
      return (await api.post('/buyer/profile/picture', formData)).data
    },
    onSuccess: (updatedUser) => {
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
    },
  })
  const removePicture = useMutation({
    mutationFn: async () => (await api.delete('/buyer/profile/picture')).data,
    onSuccess: (updatedUser) => {
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
    },
  })

  return (
    <>
      <ImageUploadControl
        src={user.profile_picture}
        placeholder={DEFAULT_AVATAR_IMAGE}
        alt="Your profile picture"
        label="Profile Picture"
        shape="circle"
        uploading={uploadPicture.isPending || removePicture.isPending}
        onUpload={(file) => uploadPicture.mutate(file)}
        onRemove={user.profile_picture ? () => removePicture.mutate() : null}
        error={uploadPicture.error?.response?.data?.message}
      />
      <div className="form grid-form">
        <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Full name" />
        <input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="Email" />
        <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="Phone number" />
        <input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} placeholder="Address" />
        <textarea value={form.bio} onChange={(e) => setForm({ ...form, bio: e.target.value })} placeholder="About me / bio (optional)" />
      </div>
      <p className="helper-text">Municipality: {user.municipality?.name || 'Not set'}. This is assigned at registration and can&apos;t be changed here.</p>
      <button type="button" onClick={() => onSave(form)} disabled={saving}>{saving ? 'Saving...' : 'Save Profile'}</button>
      {success && <p className="helper-text">Profile updated.</p>}
      {error && <p className="error">{error}</p>}
    </>
  )
}

function ChangePasswordForm() {
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [localError, setLocalError] = useState('')

  const changePassword = useMutation({
    mutationFn: async () => (await api.patch('/auth/password', { current_password: currentPassword, password: newPassword })).data,
    onSuccess: () => {
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      setLocalError('')
    },
  })

  const submit = () => {
    const pwError = validatePassword(newPassword)
    if (pwError) {
      setLocalError(pwError)
      return
    }
    if (newPassword !== confirmPassword) {
      setLocalError('New password and confirmation do not match.')
      return
    }
    setLocalError('')
    changePassword.mutate()
  }

  return (
    <div className="form grid-form">
      <input type="password" placeholder="Current password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} />
      <input type="password" placeholder="New password" value={newPassword} onChange={(e) => setNewPassword(stripSpaces(e.target.value))} onKeyDown={blockSpaceKey} />
      <input type="password" placeholder="Confirm new password" value={confirmPassword} onChange={(e) => setConfirmPassword(stripSpaces(e.target.value))} onKeyDown={blockSpaceKey} />
      <p className="helper-text">{PASSWORD_HELP}</p>
      <button type="button" onClick={submit} disabled={changePassword.isPending || !currentPassword || !newPassword}>{changePassword.isPending ? 'Saving...' : 'Change Password'}</button>
      {localError && <p className="error">{localError}</p>}
      {changePassword.isSuccess && <p className="helper-text">Password updated.</p>}
      {changePassword.error && <p className="error">{changePassword.error.response?.data?.message || 'Could not update password.'}</p>}
    </div>
  )
}

/**
 * Profile section for LGU Admins and the Super Admin -- picture-only, since
 * (unlike sellers) they have no public profile info to maintain. Reuses the
 * shared ImageUploadControl and the same /profile/picture endpoints exposed
 * per role (endpointBase is '/lgu' or '/super-admin'). Also offers Change
 * Password, for parity with the Buyer/Seller profile tabs.
 */
function AdminProfilePanel({ endpointBase }) {
  const session = getSession()
  const [picture, setPicture] = useState(session?.profile_picture || null)

  const uploadPicture = useMutation({
    mutationFn: async (file) => {
      const formData = new FormData()
      formData.append('photo', file)
      return (await api.post(`${endpointBase}/profile/picture`, formData)).data
    },
    onSuccess: (updatedUser) => {
      setPicture(updatedUser.profile_picture)
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
    },
  })
  const removePicture = useMutation({
    mutationFn: async () => (await api.delete(`${endpointBase}/profile/picture`)).data,
    onSuccess: (updatedUser) => {
      setPicture(updatedUser.profile_picture)
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
    },
  })

  return (
    <>
      <Section title="Profile">
        <p className="helper-text">Update the profile picture shown across AbaiMarket -- in the sidebar, messages, and anywhere your account appears.</p>
        <div className="admin-profile-card">
          <ImageUploadControl
            src={picture}
            placeholder={DEFAULT_AVATAR_IMAGE}
            alt="Your profile picture"
            label="Profile Picture"
            shape="circle"
            uploading={uploadPicture.isPending || removePicture.isPending}
            onUpload={(file) => uploadPicture.mutate(file)}
            onRemove={picture ? () => removePicture.mutate() : null}
            error={uploadPicture.error?.response?.data?.message || removePicture.error?.response?.data?.message}
          />
          <div className="admin-profile-meta">
            <div className="card-row"><h3>{session?.name}</h3><RoleBadge role={session?.role} /></div>
            {session?.email && <p className="muted">{session.email}</p>}
            {session?.municipality && <p className="muted">{session.municipality}</p>}
          </div>
        </div>
      </Section>
      <Section title="Change Password"><ChangePasswordForm /></Section>
    </>
  )
}

function SellerDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [form, setForm] = useState({ species: '', quantity: '', price: '', description: '' })
  const [editingListingId, setEditingListingId] = useState(null)
  const [stagedImages, setStagedImages] = useState([])
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const [withdrawForm, setWithdrawForm] = useState({ method: 'gcash', account_name: '', account_number: '', amount: '' })
  const dashboard = useQuery({
    queryKey: ['seller-dashboard'],
    queryFn: async () => (await api.get('/seller/dashboard')).data,
    retry: false,
    placeholderData: {
      seller: { id: 1, hatchery_name: "Juan's Hatchery" },
      active_listings: 12,
      pending_orders: 4,
      total_sales: 28500,
      ratings: 4.8,
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
  const requestWithdrawal = useMutation({
    mutationFn: async () => (await api.post('/seller/withdrawals', {
      method: withdrawForm.method,
      account_name: withdrawForm.account_name,
      account_number: withdrawForm.account_number,
      amount: Number(withdrawForm.amount),
    })).data,
    onSuccess: () => {
      setWithdrawForm({ method: 'gcash', account_name: '', account_number: '', amount: '' })
      queryClient.invalidateQueries({ queryKey: ['seller-wallet'] })
    },
  })
  const submitWithdrawal = () => {
    if (withdrawalFormIsIncomplete(withdrawForm)) {
      setWithdrawFormError(REQUIRED_FIELDS_MESSAGE)
      return
    }
    setWithdrawFormError('')
    requestWithdrawal.mutate()
  }
  // Platform payout fee is fixed (see CommissionCalculator::WITHDRAWAL_FEE_PERCENT
  // on the backend) -- this is a display-only preview so the seller can see it
  // before submitting; the backend computes and freezes the authoritative fee.
  const withdrawRequestAmount = Number(withdrawForm.amount) || 0
  const withdrawFeePreview = Math.round(withdrawRequestAmount * 0.06 * 100) / 100
  const withdrawNetPreview = Math.round((withdrawRequestAmount - withdrawFeePreview) * 100) / 100
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
  const saveListing = useMutation({
    mutationFn: async () => {
      const payload = {
        species: form.species,
        title: `${form.species} Fingerlings`,
        description: form.description,
        quantity: Number(form.quantity),
        price_per_piece: Number(form.price),
      }
      if (editingListingId) {
        return (await api.patch(`/listings/${editingListingId}`, payload)).data
      }
      const created = (await api.post('/listings', {
        ...payload,
        scientific_name: '',
        average_size: '',
        availability_status: 'in_stock',
      })).data
      if (stagedImages.length) {
        try {
          const formData = new FormData()
          stagedImages.forEach((staged) => formData.append('photos[]', staged.file))
          await api.post(`/listings/${created.id}/media`, formData)
        } catch (uploadError) {
          // The listing itself was created successfully; let the seller retry
          // attaching photos from the edit view instead of losing the listing.
          setEditingListingId(created.id)
          clearStagedImages()
          queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
          throw uploadError
        }
      }
      return created
    },
    onSuccess: (listing) => {
      clearStagedImages()
      if (!editingListingId) {
        // Newly created listing: stay in edit mode so the seller can attach more photos right away.
        setEditingListingId(listing.id)
      } else {
        setForm({ species: '', quantity: '', price: '', description: '' })
        setEditingListingId(null)
      }
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })
  const deleteListing = useMutation({
    mutationFn: async (id) => (await api.delete(`/listings/${id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
  })
  const updateOrderStatus = useMutation({
    mutationFn: async ({ orderId, status }) => (await api.patch(`/orders/${orderId}/status`, { status })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
  })
  const startEdit = (listing) => {
    setEditingListingId(listing.id)
    setForm({ species: listing.species || '', quantity: String(listing.quantity ?? ''), price: String(listing.price_per_piece ?? ''), description: listing.description || '' })
  }
  const cancelEdit = () => {
    setEditingListingId(null)
    setForm({ species: '', quantity: '', price: '', description: '' })
    clearStagedImages()
  }
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
      {tab === 'overview' && (
        <>
          <AnnouncementBanner />
          <StatsRow items={[['Active Listings', dashboard.data?.active_listings ?? 0], ['Pending Orders', dashboard.data?.pending_orders ?? 0], ['Total Sales', currency(dashboard.data?.total_sales ?? 0)], ['Unread Messages', dashboard.data?.unread_messages ?? 0]]} />
        </>
      )}
      {tab === 'marketplace' && (
        <Section title="Marketplace">
          <p className="helper-text">Browse the marketplace to see what other hatcheries are offering. This view is read-only -- purchasing is reserved for buyer accounts.</p>
          <MarketplaceBrowser />
        </Section>
      )}
      {tab === 'listings' && (
        <>
          <Section title={editingListingId ? 'Edit Listing' : 'Create Listing'}>
            <div className="form grid-form">
              <input value={form.species} onChange={(e) => setForm({ ...form, species: e.target.value })} placeholder="Species" />
              <input value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} placeholder="Quantity" />
              <input value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} placeholder="Price" />
              <textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Describe the fingerlings: health, feeding, size consistency, etc." />
            </div>
            {!editingListingId && (
              <>
                <p className="helper-text">Add up to 5 photos or videos (JPG, PNG, WEBP up to 5MB; MP4, MOV, WEBM up to 100MB). They&apos;ll be uploaded when you save the listing.</p>
                <StagedImagePicker files={stagedImages} onAdd={addStagedImages} onRemove={removeStagedImage} />
              </>
            )}
            <p className="helper-text">Listings are posted automatically under your registered municipality, {dashboard.data?.seller?.municipality?.name || 'your account municipality'}.</p>
            <button onClick={() => saveListing.mutate()} type="button">{editingListingId ? 'Update Listing' : 'Save Listing'}</button>
            {editingListingId && <button className="ghost" onClick={cancelEdit} type="button">Cancel</button>}
            {saveListing.error && <p className="error">{saveListing.error.response?.data?.message || 'Could not save listing.'}</p>}
          </Section>
          {editingListingId && (
            <Section title="Listing Photos & Videos">
              <p className="helper-text">Upload up to 5 photos or videos (JPG, PNG, WEBP up to 5MB; MP4, MOV, WEBM up to 100MB). The first item is used as the primary image in the marketplace.</p>
              <ListingImageManager
                listingId={editingListingId}
                media={dashboard.data?.listings?.find((listing) => listing.id === editingListingId)?.media || []}
                onChange={() => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })}
              />
            </Section>
          )}
          <Section title="My Listings">
            <div className="item-list">
              {(dashboard.data?.listings || []).map((listing) => (
                <div className="card action" key={listing.id}>
                  <img className="listing-thumb" src={resolveListingImage(listing)} alt={listing.title} />
                  <div>
                    <div className="card-row"><strong>{listing.title}</strong><Badge status={listing.approval_status} /></div>
                    <p>{listing.species} · {Number(listing.quantity).toLocaleString()} pcs · {currency(listing.price_per_piece)}/pc</p>
                    {listing.approval_status === 'rejected' && listing.rejection_reason && (
                      <p className="error">Reason: {listing.rejection_reason}</p>
                    )}
                  </div>
                  <div className="row-actions">
                    <button type="button" className="ghost" onClick={() => startEdit(listing)}>Edit</button>
                    <button type="button" className="ghost danger" onClick={() => deleteListing.mutate(listing.id)}>Delete</button>
                  </div>
                </div>
              ))}
              {!dashboard.data?.listings?.length && <EmptyState message="No listings yet." />}
            </div>
            {deleteListing.error && <p className="error">{deleteListing.error.response?.data?.message || 'Could not delete listing.'}</p>}
          </Section>
        </>
      )}
      {tab === 'orders' && (
        <>
          <SellerOrderLookup />
          <Section title="Order Management">
            <SellerOrderTable
              rows={dashboard.data?.orders || []}
              onUpdateStatus={(orderId, status) => updateOrderStatus.mutateAsync({ orderId, status })}
            />
          </Section>
        </>
      )}
      {tab === 'messages' && <Section title="Messages"><MessagesPanel initialUserId={searchParams.get('with') ? Number(searchParams.get('with')) : null} /></Section>}
      {tab === 'wallet' && (
        <>
          <StatsRow items={[['Available Balance', currency(wallet.data?.available_balance ?? 0), true], ['Pending Balance', currency(wallet.data?.pending_balance ?? 0)], ['Processing Withdrawal', currency(wallet.data?.processing_amount ?? 0)], ['Withdrawn Amount', currency(wallet.data?.withdrawn_amount ?? 0)], ['Total Earnings', currency(wallet.data?.total_earnings ?? 0)]]} />
          <Section title="Request Withdrawal">
            <div className="form grid-form">
              <select value={withdrawForm.method} onChange={(e) => setWithdrawForm({ ...withdrawForm, method: e.target.value })}>
                <option value="gcash">GCash</option>
                <option value="maya">Maya</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
              <input value={withdrawForm.account_name} onChange={(e) => setWithdrawForm({ ...withdrawForm, account_name: e.target.value })} placeholder="Account name" />
              <input value={withdrawForm.account_number} onChange={(e) => setWithdrawForm({ ...withdrawForm, account_number: e.target.value })} placeholder="Account number" />
              <input value={withdrawForm.amount} onChange={(e) => setWithdrawForm({ ...withdrawForm, amount: e.target.value })} placeholder="Amount to withdraw" type="number" min="0" step="0.01" />
            </div>
            <p className="helper-text">Available to withdraw: {currency(wallet.data?.available_balance ?? 0)}</p>
            {withdrawRequestAmount > 0 && (
              <p className="helper-text">
                A 6% platform payout fee applies to every withdrawal: you&apos;re requesting {currency(withdrawRequestAmount)}, a {currency(withdrawFeePreview)} fee will be deducted, and you&apos;ll receive approximately {currency(withdrawNetPreview)}.
              </p>
            )}
            <button type="button" onClick={submitWithdrawal} disabled={requestWithdrawal.isPending}>{requestWithdrawal.isPending ? 'Submitting...' : 'Submit Withdrawal Request'}</button>
            {withdrawFormError && <p className="error">{withdrawFormError}</p>}
            {requestWithdrawal.error && <p className="error">{apiErrorMessage(requestWithdrawal.error, 'Could not submit withdrawal request.')}</p>}
            {requestWithdrawal.isSuccess && (
              <p className="helper-text">
                Withdrawal request submitted for {currency(requestWithdrawal.data?.amount)}. Platform payout fee: {currency(requestWithdrawal.data?.platform_fee)}. You'll receive {currency(requestWithdrawal.data?.net_amount)} once the Super Admin pays it out.
              </p>
            )}
          </Section>
          <Section title="Withdrawal Requests">
            {(wallet.data?.withdrawal_requests || []).length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Amount Requested</span>
                  <span>Platform Fee (6%)</span>
                  <span>You Receive</span>
                  <span>Method</span>
                  <span>Account</span>
                  <span>Status</span>
                  <span>Requested</span>
                  <span>Notes</span>
                </div>
                {wallet.data.withdrawal_requests.map((request) => (
                  <div className="table-row" key={request.id}>
                    <span>{currency(request.amount)}</span>
                    <span>{currency(request.platform_fee)}</span>
                    <span>{currency(request.net_amount)}</span>
                    <span>{withdrawalMethodLabel(request.method)}</span>
                    <span>{request.account_name} · {request.account_number}</span>
                    <span><Badge status={request.status} /></span>
                    <span>{new Date(request.created_at).toLocaleDateString()}</span>
                    <span>
                      {request.status === 'rejected' && request.rejection_reason && `Reason: ${request.rejection_reason}`}
                      {request.status === 'paid' && request.paid_at && `Paid on ${new Date(request.paid_at).toLocaleDateString()}`}
                      {(request.status === 'pending' || request.status === 'approved') && '—'}
                    </span>
                  </div>
                ))}
              </div>
            ) : <EmptyState message="No withdrawal requests yet." />}
          </Section>
          <Section title="Payment History">
            {(wallet.data?.payment_history || []).length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Order ID</span>
                  <span>Buyer</span>
                  <span>Fish Listing</span>
                  <span>Amount</span>
                  <span>Release Date</span>
                  <span>Status</span>
                </div>
                {wallet.data.payment_history.map((payment) => (
                  <div className="table-row" key={payment.id}>
                    <span>{payment.order?.order_number ? `#${payment.order.order_number}` : 'N/A'}</span>
                    <span>{payment.order?.buyer?.name || 'Unknown buyer'}</span>
                    <span>{payment.order?.listing?.title || payment.order?.listing?.species || 'Listing'}</span>
                    <span>{currency(payment.amount)}</span>
                    <span>{payment.released_at ? new Date(payment.released_at).toLocaleDateString() : 'Not released yet'}</span>
                    <span><Badge status={payment.status} /></span>
                  </div>
                ))}
              </div>
            ) : <EmptyState message="No payment history yet." />}
          </Section>
        </>
      )}
      {tab === 'notifications' && (
        <Section
          title="Notifications"
          actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllRead.isPending} onClick={() => markAllRead.mutate()} />}
        >
          <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} />
        </Section>
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
        <>
          <Section title="Seller Profile" actions={dashboard.data?.seller?.id && !dashboard.isPlaceholderData ? <Link className="ghost" to={`/seller/sellers/${dashboard.data.seller.id}`}><Store size={16} /> View & Manage Public Profile</Link> : null}>
            {!dashboard.data?.seller || dashboard.isPlaceholderData ? (
              <LoadingState label="Loading profile..." />
            ) : (
              <SellerProfileForm
                key={dashboard.data.seller.id}
                seller={dashboard.data.seller}
                saving={updateProfile.isPending}
                success={updateProfile.isSuccess}
                error={updateProfile.error?.response?.data?.message}
                onSave={(values) => updateProfile.mutate(values)}
              />
            )}
          </Section>
          <Section title="Change Password"><ChangePasswordForm /></Section>
        </>
      )}
    </Dashboard>
  )
}

function SellerProfileForm({ seller, onSave, saving, success, error }) {
  const [form, setForm] = useState({
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
  })

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
    <>
      <div className="profile-image-uploads">
        <ImageUploadControl
          src={seller.profile_picture}
          placeholder={DEFAULT_AVATAR_IMAGE}
          alt="Your profile picture"
          label="Profile Picture"
          shape="circle"
          uploading={uploadPicture.isPending || removePicture.isPending}
          onUpload={(file) => uploadPicture.mutate(file)}
          onRemove={seller.profile_picture ? () => removePicture.mutate() : null}
          error={uploadPicture.error?.response?.data?.message}
        />
        <ImageUploadControl
          src={seller.cover_photo}
          placeholder={DEFAULT_COVER_IMAGE}
          alt="Farm cover photo"
          label="Cover Photo"
          shape="wide"
          uploading={uploadCover.isPending || removeCover.isPending}
          onUpload={(file) => uploadCover.mutate(file)}
          onRemove={seller.cover_photo ? () => removeCover.mutate() : null}
          error={uploadCover.error?.response?.data?.message}
        />
      </div>
      <div className="form grid-form">
        <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Full name" />
        <input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="Email" />
        <input value={form.hatchery_name} onChange={(e) => setForm({ ...form, hatchery_name: e.target.value })} placeholder="Hatchery / Farm name" />
        <input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} placeholder="Farm address / location" />
        <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="Contact phone" />
        <input value={form.years_experience} onChange={(e) => setForm({ ...form, years_experience: e.target.value })} placeholder="Years of experience" type="number" min="0" />
        <textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="About the farm" />
        <textarea value={form.farming_methods} onChange={(e) => setForm({ ...form, farming_methods: e.target.value })} placeholder="Farming methods" />
        <textarea value={form.fish_raising_practices} onChange={(e) => setForm({ ...form, fish_raising_practices: e.target.value })} placeholder="Fish raising practices" />
        <textarea value={form.water_source} onChange={(e) => setForm({ ...form, water_source: e.target.value })} placeholder="Water source" />
        <textarea value={form.feeding_practices} onChange={(e) => setForm({ ...form, feeding_practices: e.target.value })} placeholder="Feeding practices" />
        <textarea value={form.certifications} onChange={(e) => setForm({ ...form, certifications: e.target.value })} placeholder="Certifications (optional)" />
        <textarea value={form.farm_history} onChange={(e) => setForm({ ...form, farm_history: e.target.value })} placeholder="Farm history" />
      </div>
      <button type="button" onClick={() => onSave(form)} disabled={saving}>{saving ? 'Saving...' : 'Save Profile'}</button>
      {success && <p className="helper-text">Profile updated.</p>}
      {error && <p className="error">{error}</p>}
    </>
  )
}

const ORDER_STATUS_TRANSITIONS = {
  placed: [['confirmed', 'Confirm Order'], ['cancelled', 'Cancel Order']],
  paid: [['confirmed', 'Confirm Order'], ['cancelled', 'Cancel Order']],
  confirmed: [['in_transit', 'Mark Out for Delivery'], ['cancelled', 'Cancel Order']],
  in_transit: [['completed', 'Mark Completed'], ['cancelled', 'Cancel Order']],
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
    </Section>
  )
}

function SellerOrderTable({ rows, onUpdateStatus }) {
  if (!rows?.length) return <EmptyState message="No orders yet." />
  return <div className="item-list">{rows.map((order) => <SellerOrderRow key={order.id} order={order} onUpdateStatus={onUpdateStatus} />)}</div>
}

function SellerOrderRow({ order, onUpdateStatus }) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [rating, setRating] = useState(false)
  const transitions = ORDER_STATUS_TRANSITIONS[order.status] || []
  // Mirrors the buyer's ReviewCell rules exactly, and the server enforces the
  // same two (completed only, once per order) in SellerController::rateBuyer.
  const canRateBuyer = order.status === 'completed' && !order.buyerRating

  const applyStatus = async (status) => {
    setSaving(true)
    setError('')
    try {
      await onUpdateStatus(order.id, status)
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not update order.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className={`card action${rating ? ' action-stacked' : ''}`}>
      <div>
        <strong>{order.order_number}</strong>
        <p>
          {order.listing?.title || order.listing?.species || 'Listing'} ·{' '}
          {order.buyer?.id ? <Link to={`/seller/buyers/${order.buyer.id}`}>{order.buyer.name}</Link> : (order.buyer?.name || 'Buyer')} ·{' '}
          {Number(order.quantity).toLocaleString()} pcs · {currency(order.total_amount)}
        </p>
        <Badge status={order.status}>{statusChartLabel(order.status)}</Badge>
        {order.buyerRating && (
          <p className="review-given">You rated this buyer: {renderStars(order.buyerRating.rating)} ({order.buyerRating.rating}/5)</p>
        )}
        {error && <p className="error">{error}</p>}
      </div>
      <div className="row-actions">
        {transitions.map(([status, label]) => (
          <button key={status} type="button" className={status === 'cancelled' ? 'ghost danger' : ''} disabled={saving} onClick={() => applyStatus(status)}>
            {label}
          </button>
        ))}
        {canRateBuyer && !rating && (
          <button type="button" className="ghost" onClick={() => setRating(true)}><Star size={15} /> Rate Buyer</button>
        )}
        {transitions.length === 0 && !canRateBuyer && !rating && <span className="muted">No further action</span>}
      </div>
      {rating && (
        <BuyerRateOrderForm
          order={order}
          invalidateKey="seller-dashboard"
          showHeader={false}
          onDone={() => setRating(false)}
        />
      )}
    </div>
  )
}

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
function LguEarningsRow({ payment, onApprove, approvingId, onClearHold, onReject }) {
  const [expanded, setExpanded] = useState(false)
  const [rejecting, setRejecting] = useState(false)
  const [reasonDraft, setReasonDraft] = useState('')
  const orderNumber = payment.order?.order_number
  const isOnHold = payment.order?.lgu_review_status === 'on_hold'

  const detail = useQuery({
    queryKey: ['lgu-order-detail', orderNumber],
    queryFn: async () => (await api.get(`/lgu/orders/${orderNumber}`)).data,
    enabled: expanded && Boolean(orderNumber),
  })

  const submitReason = () => {
    if (!reasonDraft.trim()) return
    onReject({ paymentId: payment.id, reason: reasonDraft })
    setRejecting(false)
    setReasonDraft('')
  }

  return (
    <div className="card action">
      <div>
        <div className="card-row">
          <Avatar src={payment.order?.sellerProfile?.profile_picture} alt={payment.order?.sellerProfile?.hatchery_name} className="listing-seller-avatar" />
          <strong>{payment.order?.sellerProfile?.hatchery_name || payment.order?.sellerProfile?.user?.name || 'Unknown seller'}</strong>
          {isOnHold && <Badge status="on_hold">On Hold</Badge>}
        </div>
        <p>
          Order #{orderNumber} · {payment.order?.listing?.title || payment.order?.listing?.species || 'Listing'} · Buyer: {payment.order?.buyer?.name || 'Unknown buyer'}
        </p>
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
function LguRejectedEarningsRow({ payment, onReopen, reopeningId }) {
  const [expanded, setExpanded] = useState(false)
  const order = payment.order
  const orderNumber = order?.order_number

  const detail = useQuery({
    queryKey: ['lgu-order-detail', orderNumber],
    queryFn: async () => (await api.get(`/lgu/orders/${orderNumber}`)).data,
    enabled: expanded && Boolean(orderNumber),
  })

  return (
    <div className="card action">
      <div>
        <div className="card-row">
          <Avatar src={order?.sellerProfile?.profile_picture} alt={order?.sellerProfile?.hatchery_name} className="listing-seller-avatar" />
          <strong>{order?.sellerProfile?.hatchery_name || order?.sellerProfile?.user?.name || 'Unknown seller'}</strong>
          <Badge status="rejected">Rejected</Badge>
        </div>
        <p>
          Order #{orderNumber} · {order?.listing?.title || order?.listing?.species || 'Listing'} · Buyer: {order?.buyer?.name || 'Unknown buyer'}
        </p>
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
        <button type="button" onClick={() => onReopen(payment.id)} disabled={reopeningId === payment.id}>
          {reopeningId === payment.id ? 'Reopening...' : 'Reopen Review'}
        </button>
      </div>
    </div>
  )
}

function reviewListingPath(scope, listingId) {
  if (!listingId) return null
  return scope === 'lgu' ? `/lgu/listings/${listingId}` : `/admin/listings/${listingId}`
}

function LguReviewCard({ review, onRemove, scope }) {
  const buyer = review.buyer
  const seller = review.sellerProfile
  const listing = review.order?.listing
  const listingPath = reviewListingPath(scope, listing?.id)

  return (
    <div className="card review-card review-card-buyer">
      <div className="review-card-head">
        <span className="review-badge"><RoleBadge role="buyer" /> reviewed seller</span>
        <span className="muted">{new Date(review.created_at).toLocaleDateString()}</span>
      </div>
      <div className="review-rating-line">
        {renderStars(review.rating)}
        <strong className="review-score">{Number(review.rating).toFixed(1)}<span>/5</span></strong>
      </div>
      {review.title && <p className="review-title">{review.title}</p>}
      <blockquote className="review-quote">{review.comment || 'No comment left.'}</blockquote>
      <div className="lgu-review-parties">
        <div className="lgu-review-party">
          <span className="lgu-review-party-label">Buyer</span>
          <Avatar src={buyer?.profile_picture} alt={buyer?.name} className="review-avatar" />
          <span>{buyer?.name || 'Unknown buyer'}</span>
        </div>
        <div className="lgu-review-party">
          <span className="lgu-review-party-label">Seller</span>
          <Avatar src={seller?.profile_picture} alt={seller?.hatchery_name} className="review-avatar" />
          <span>
            {seller?.hatchery_name || 'Unknown seller'}
            {seller?.user?.name && seller.user.name !== seller?.hatchery_name ? ` (${seller.user.name})` : ''}
          </span>
        </div>
      </div>
      <div className="detail-meta">
        {listing?.species && <span><strong>Species:</strong> {listing.species}</span>}
        {listing?.title && <span><strong>Listing:</strong> {listing.title}</span>}
        {review.order?.order_number && <span><strong>Order ID:</strong> #{review.order.order_number}</span>}
      </div>
      <div className="review-card-footer">
        {listingPath && <Link className="ghost" to={listingPath}><Store size={15} /> View Listing</Link>}
        {onRemove && <button type="button" className="ghost danger" onClick={onRemove}><Trash2 size={15} /> Remove</button>}
      </div>
    </div>
  )
}

/**
 * A seller's rating of a buyer (the reverse of LguReviewCard) -- shown in the
 * LGU / Super Admin "Reviews & Ratings" view so admins see both directions of
 * feedback. Same layout as LguReviewCard, with the rater (seller) and the
 * rated party (buyer) labelled explicitly.
 */
function BuyerRatingCard({ rating, onRemove, scope }) {
  const buyer = rating.buyer
  const seller = rating.sellerProfile
  const listing = rating.order?.listing
  const listingPath = reviewListingPath(scope, listing?.id)

  return (
    <div className="card review-card review-card-seller">
      <div className="review-card-head">
        <span className="review-badge"><RoleBadge role="seller" /> rated buyer</span>
        <span className="muted">{new Date(rating.created_at).toLocaleDateString()}</span>
      </div>
      <div className="review-rating-line">
        {renderStars(rating.rating)}
        <strong className="review-score">{Number(rating.rating).toFixed(1)}<span>/5</span></strong>
      </div>
      <blockquote className="review-quote">{rating.comment || 'No comment left.'}</blockquote>
      <div className="lgu-review-parties">
        <div className="lgu-review-party">
          <span className="lgu-review-party-label">Seller (rater)</span>
          <Avatar src={seller?.profile_picture} alt={seller?.hatchery_name} className="review-avatar" />
          <span>
            {seller?.hatchery_name || 'Unknown seller'}
            {seller?.user?.name && seller.user.name !== seller?.hatchery_name ? ` (${seller.user.name})` : ''}
          </span>
        </div>
        <div className="lgu-review-party">
          <span className="lgu-review-party-label">Buyer (rated)</span>
          <Avatar src={buyer?.profile_picture} alt={buyer?.name} className="review-avatar" />
          <span>{buyer?.name || 'Unknown buyer'}</span>
        </div>
      </div>
      <div className="detail-meta">
        {listing?.species && <span><strong>Species:</strong> {listing.species}</span>}
        {rating.order?.order_number && <span><strong>Order ID:</strong> #{rating.order.order_number}</span>}
      </div>
      <div className="review-card-footer">
        {listingPath && <Link className="ghost" to={listingPath}><Store size={15} /> View Listing</Link>}
        {onRemove && <button type="button" className="ghost danger" onClick={onRemove}><Trash2 size={15} /> Remove</button>}
      </div>
    </div>
  )
}

/**
 * Unified "Reviews & Ratings" view for LGU Admin and Super Admin -- shows both
 * directions of feedback: buyers reviewing sellers (LguReviewCard) and sellers
 * rating buyers (BuyerRatingCard). Backed by GET {scope}/reviews, which now
 * returns { buyer_reviews, seller_ratings }.
 */
const REVIEW_FILTERS = [['all', 'All'], ['review', 'Buyer Reviews'], ['rating', 'Seller Ratings']]

function ReviewsAndRatingsSection({ data, scope, scopeLabel = 'on the platform' }) {
  const [filter, setFilter] = useState('all')
  const apiBase = scope === 'lgu' ? '/lgu' : '/super-admin'
  const queryKey = scope === 'lgu' ? ['lgu-reviews'] : ['super-admin-reviews']

  const removeReview = useMutation({
    mutationFn: async (id) => (await api.delete(`${apiBase}/reviews/${id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })
  const removeRating = useMutation({
    mutationFn: async (id) => (await api.delete(`${apiBase}/buyer-ratings/${id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })

  // Merge both directions into one uniform, newest-first feed, tagged by type
  // so the filter and the right card/remove action can be picked per entry.
  const entries = [
    ...(data?.buyer_reviews || []).map((item) => ({ type: 'review', item })),
    ...(data?.seller_ratings || []).map((item) => ({ type: 'rating', item })),
  ].sort((a, b) => new Date(b.item.created_at) - new Date(a.item.created_at))
  const filtered = filter === 'all' ? entries : entries.filter((entry) => entry.type === filter)

  const emptyLabel = filter === 'review' ? 'buyer reviews' : filter === 'rating' ? 'seller ratings' : 'reviews or ratings'

  return (
    <Section
      title="Reviews & Ratings"
      actions={(
        <div className="tab-bar">
          {REVIEW_FILTERS.map(([value, label]) => (
            <button key={value} type="button" className={filter === value ? 'tab active' : 'tab'} onClick={() => setFilter(value)}>{label}</button>
          ))}
        </div>
      )}
    >
      <p className="helper-text">Both directions of feedback -- buyers reviewing sellers and sellers rating buyers. Remove any entry that isn&apos;t fair to either party; the affected rating is recalculated automatically.</p>
      {(removeReview.error || removeRating.error) && <p className="error">{removeReview.error?.response?.data?.message || removeRating.error?.response?.data?.message || 'Could not remove that entry.'}</p>}
      {filtered.length ? (
        <div className="review-list">
          {filtered.map((entry) => entry.type === 'review' ? (
            <LguReviewCard
              key={`review-${entry.item.id}`}
              review={entry.item}
              scope={scope}
              onRemove={() => { if (window.confirm('Remove this buyer review? The seller’s rating will be recalculated.')) removeReview.mutate(entry.item.id) }}
            />
          ) : (
            <BuyerRatingCard
              key={`rating-${entry.item.id}`}
              rating={entry.item}
              scope={scope}
              onRemove={() => { if (window.confirm('Remove this seller rating? The buyer’s rating will be recalculated.')) removeRating.mutate(entry.item.id) }}
            />
          ))}
        </div>
      ) : <EmptyState message={`No ${emptyLabel} yet ${scopeLabel}.`} />}
    </Section>
  )
}

/**
 * Friendly presentation for each Activity Log action -- label, icon, badge
 * tone, and which category it belongs to (used to group the filter
 * dropdown). Keys must match App\Support\ActivityLog::actionTypes() on the
 * backend.
 */
const ACTIVITY_ACTION_META = {
  user_registered: { label: 'New Account Registered', icon: UserPlus, tone: 'info', category: 'accounts' },
  lgu_admin_created: { label: 'LGU Admin Added', icon: UserPlus, tone: 'info', category: 'accounts' },
  lgu_admin_updated: { label: 'LGU Admin Updated', icon: UsersIcon, tone: 'info', category: 'accounts' },
  municipality_created: { label: 'Municipality Added', icon: MapPin, tone: 'info', category: 'accounts' },
  listing_approved: { label: 'Listing Approved', icon: CheckCircle, tone: 'success', category: 'listings_sellers' },
  listing_rejected: { label: 'Listing Rejected', icon: XCircle, tone: 'danger', category: 'listings_sellers' },
  listing_archived: { label: 'Listing Archived', icon: Archive, tone: 'neutral', category: 'listings_sellers' },
  seller_verified: { label: 'Seller Verified', icon: ShieldCheck, tone: 'success', category: 'listings_sellers' },
  buyer_suspended: { label: 'Buyer Suspended', icon: ShieldAlert, tone: 'danger', category: 'moderation' },
  buyer_reinstated: { label: 'Buyer Reinstated', icon: ShieldCheck, tone: 'success', category: 'moderation' },
  seller_suspended: { label: 'Seller Suspended', icon: ShieldAlert, tone: 'danger', category: 'moderation' },
  seller_reinstated: { label: 'Seller Reinstated', icon: ShieldCheck, tone: 'success', category: 'moderation' },
  lgu_admin_suspended: { label: 'LGU Admin Suspended', icon: ShieldAlert, tone: 'danger', category: 'moderation' },
  lgu_admin_reinstated: { label: 'LGU Admin Reinstated', icon: ShieldCheck, tone: 'success', category: 'moderation' },
  seller_earnings_approved: { label: 'Seller Earnings Approved', icon: Wallet, tone: 'success', category: 'payments' },
  seller_payout_requested: { label: 'Seller Payout Requested', icon: Wallet, tone: 'warning', category: 'payments' },
  seller_payout_approved: { label: 'Seller Payout Approved', icon: Wallet, tone: 'info', category: 'payments' },
  seller_payout_completed: { label: 'Seller Payout Completed', icon: Wallet, tone: 'success', category: 'payments' },
  lgu_payout_requested: { label: 'Municipality Payout Requested', icon: Wallet, tone: 'warning', category: 'payments' },
  lgu_payout_approved: { label: 'Municipality Payout Approved', icon: Wallet, tone: 'info', category: 'payments' },
  lgu_payout_completed: { label: 'Municipality Payout Completed', icon: Wallet, tone: 'success', category: 'payments' },
  review_submitted: { label: 'Review Submitted', icon: Star, tone: 'info', category: 'reviews' },
  buyer_rating_submitted: { label: 'Buyer Rated', icon: Star, tone: 'info', category: 'reviews' },
  review_removed: { label: 'Review Removed', icon: Trash2, tone: 'danger', category: 'reviews' },
  buyer_rating_removed: { label: 'Buyer Rating Removed', icon: Trash2, tone: 'danger', category: 'reviews' },
}

// Matches App\Support\ActivityLog::CATEGORIES on the backend -- this is the
// primary, one-click filter ("show me everything about Payments") that
// replaces having to hunt through 20+ individual action names.
const ACTIVITY_CATEGORIES = [
  ['accounts', 'Accounts'],
  ['listings_sellers', 'Listings & Sellers'],
  ['moderation', 'Moderation'],
  ['payments', 'Payments'],
  ['reviews', 'Reviews & Ratings'],
]

function activityActionMeta(action) {
  return ACTIVITY_ACTION_META[action] || { label: statusChartLabel(action), icon: History, tone: 'neutral', category: 'other' }
}

/**
 * Where clicking an entry should go -- e.g. any payment/payout entry sends a
 * Super Admin to Payout Management (every municipality's payouts) and an LGU
 * Admin to their own Seller Earnings Approval queue (already scoped
 * server-side to their municipality), rather than only ever showing a plain
 * text description.
 */
function activityLogLink(scope, entry) {
  const base = scope === 'lgu' ? '/lgu/dashboard' : '/admin/dashboard'
  const action = entry.action

  if (['listing_approved', 'listing_rejected', 'listing_archived'].includes(action)) return `${base}?tab=listings`
  if (action === 'seller_verified') return `${base}?tab=sellers`
  if (action === 'user_registered') return `${base}?tab=users`
  if (action === 'review_submitted') return `${base}?tab=reviews`
  if (action === 'buyer_rating_submitted') return `${base}?tab=users`

  if (scope === 'super-admin') {
    if (['lgu_admin_created', 'lgu_admin_updated', 'lgu_admin_suspended', 'lgu_admin_reinstated'].includes(action)) return `${base}?tab=lgu-admins`
    if (action === 'municipality_created') return `${base}?tab=municipalities`
    if (['buyer_suspended', 'buyer_reinstated', 'seller_suspended', 'seller_reinstated'].includes(action)) return `${base}?tab=moderation`
    if (action === 'seller_earnings_approved') return `${base}?tab=transactions`
    if (['seller_payout_requested', 'seller_payout_approved', 'seller_payout_completed', 'lgu_payout_requested', 'lgu_payout_approved', 'lgu_payout_completed'].includes(action)) return `${base}?tab=payouts`
  } else {
    if (['buyer_suspended', 'buyer_reinstated'].includes(action)) return `${base}?tab=users`
    if (['seller_suspended', 'seller_reinstated'].includes(action)) return `${base}?tab=sellers`
    // Earnings approval is exactly the Seller Earnings Approval queue --
    // already scoped server-side to this LGU's own municipality.
    if (action === 'seller_earnings_approved') return `${base}?tab=earnings`
    if (['lgu_payout_requested', 'lgu_payout_approved', 'lgu_payout_completed'].includes(action)) return `${base}?tab=wallet`
    // seller_payout_* has no LGU-facing page -- Super Admin owns seller payouts.
  }

  return null
}

function ActivityLogEntryCard({ scope, entry }) {
  const meta = activityActionMeta(entry.action)
  const Icon = meta.icon
  const link = activityLogLink(scope, entry)

  const content = (
    <>
      <span className={`activity-log-icon tone-${meta.tone}`}><Icon size={16} /></span>
      <div className="activity-log-body">
        <div className="activity-log-title-row">
          <strong>{meta.label}</strong>
          {entry.reference_number && <Badge tone="neutral">{entry.reference_number}</Badge>}
        </div>
        <p className="muted">
          {entry.administrator ? `${entry.administrator} (${roleLabel(entry.role)})` : roleLabel(entry.role)}
          {entry.target_user ? ` → ${entry.target_user}` : ''}
          {entry.municipality ? ` · ${entry.municipality}` : ''}
        </p>
        {entry.description && <p className="muted">{entry.description}</p>}
        <p className="muted">{entry.timestamp ? new Date(entry.timestamp).toLocaleString() : ''}</p>
      </div>
      {link && <ChevronRight size={18} className="activity-log-chevron" />}
    </>
  )

  return link
    ? <Link className="card activity-log-item" to={link}>{content}</Link>
    : <div className="card activity-log-item">{content}</div>
}

/**
 * Global Activity Log / Audit Trail -- reused by both LGU Admin (municipality-
 * scoped server-side) and Super Admin (platform-wide, with an extra
 * municipality filter). Backed by GET {scope}/activity-log and
 * {scope}/activity-log/actions (see App\Support\ActivityLog on the backend).
 * Every entry is clickable through to the relevant existing page for that
 * action (see activityLogLink above) so an admin never has to go hunting for
 * the underlying record.
 */
const ACTIVITY_LOG_DEFAULT_FILTERS = { category: '', action: '', date_from: '', date_to: '', municipality_id: '' }

function ActivityLogPanel({ scope }) {
  const [filters, setFilters] = useState(ACTIVITY_LOG_DEFAULT_FILTERS)
  const [searchDraft, setSearchDraft] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [page, setPage] = useState(1)
  const base = scope === 'lgu' ? '/lgu' : '/super-admin'

  const municipalities = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
    placeholderData: [],
    enabled: scope === 'super-admin',
  })

  const log = useQuery({
    queryKey: ['activity-log', scope, filters, appliedSearch, page],
    queryFn: async () => (await api.get(`${base}/activity-log`, { params: { ...filters, search: appliedSearch, page, per_page: 20 } })).data,
    retry: false,
  })

  const updateFilter = (key, value) => {
    setPage(1)
    setFilters((current) => (key === 'category' ? { ...current, category: value, action: '' } : { ...current, [key]: value }))
  }

  const submitSearch = (e) => {
    e.preventDefault()
    setPage(1)
    setAppliedSearch(searchDraft.trim())
  }

  const clearFilters = () => {
    setFilters(ACTIVITY_LOG_DEFAULT_FILTERS)
    setSearchDraft('')
    setAppliedSearch('')
    setPage(1)
  }

  const total = log.data?.total ?? 0
  const perPage = log.data?.per_page ?? 20
  const totalPages = Math.max(1, Math.ceil(total / perPage))
  const hasActiveFilters = Boolean(filters.category || filters.action || filters.date_from || filters.date_to || filters.municipality_id || appliedSearch)

  // Only the actions belonging to the selected category, so picking a
  // category first ("Payments") narrows the action list to just those --
  // otherwise every action across all categories is offered, grouped.
  const actionOptions = filters.category
    ? ACTIVITY_ACTION_META && Object.entries(ACTIVITY_ACTION_META).filter(([, meta]) => meta.category === filters.category)
    : Object.entries(ACTIVITY_ACTION_META)

  return (
    <Section title="Activity Log">
      <p className="helper-text">Unified audit trail across registrations, approvals, moderation, earnings, and payouts{scope === 'lgu' ? ' in your municipality' : ''}. Click an entry to jump to where it's managed.</p>

      <div className="tab-bar activity-log-category-bar">
        <button type="button" className={filters.category === '' ? 'tab active' : 'tab'} onClick={() => updateFilter('category', '')}>All</button>
        {ACTIVITY_CATEGORIES.map(([value, label]) => (
          <button key={value} type="button" className={filters.category === value ? 'tab active' : 'tab'} onClick={() => updateFilter('category', value)}>
            {label}
          </button>
        ))}
      </div>

      <form className="form grid-form activity-log-filters" onSubmit={submitSearch}>
        <input placeholder="Search by name, description, or reference #" value={searchDraft} onChange={(e) => setSearchDraft(e.target.value)} />
        <button type="submit"><Search size={16} /> Search</button>
        <select value={filters.action} onChange={(e) => updateFilter('action', e.target.value)}>
          <option value="">{filters.category ? `All ${ACTIVITY_CATEGORIES.find(([v]) => v === filters.category)?.[1]} actions` : 'All action types'}</option>
          {actionOptions.map(([action, meta]) => <option key={action} value={action}>{meta.label}</option>)}
        </select>
        <input type="date" value={filters.date_from} onChange={(e) => updateFilter('date_from', e.target.value)} title="From date" />
        <input type="date" value={filters.date_to} onChange={(e) => updateFilter('date_to', e.target.value)} title="To date" />
        {scope === 'super-admin' && (
          <select value={filters.municipality_id} onChange={(e) => updateFilter('municipality_id', e.target.value)}>
            <option value="">All municipalities</option>
            {(municipalities.data || []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </select>
        )}
        {hasActiveFilters && <button type="button" className="ghost" onClick={clearFilters}>Clear Filters</button>}
      </form>

      {log.isLoading && <LoadingState label="Loading activity log..." />}
      {log.isError && (
        <p className="error">
          Could not load the activity log ({log.error?.response?.data?.message || log.error?.message || 'unknown error'}).{' '}
          <button type="button" className="ghost" onClick={() => log.refetch()}>Retry</button>
        </p>
      )}
      {!log.isLoading && !log.isError && (
        (log.data?.data || []).length ? (
          <div className="item-list">
            {log.data.data.map((entry) => <ActivityLogEntryCard key={entry.id} scope={scope} entry={entry} />)}
          </div>
        ) : <EmptyState message="No activity matches these filters." />
      )}
      {total > perPage && (
        <div className="row-actions">
          <button type="button" className="ghost" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
          <span className="muted">Page {page} of {totalPages}</span>
          <button type="button" className="ghost" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>Next</button>
        </div>
      )}
    </Section>
  )
}

/**
 * Export Reports -- report-type select + PDF/Excel download buttons, reused
 * by both LGU and Super Admin Reports sections. Downloads via axios blob
 * (auth header already attached by the shared api instance) rather than a
 * plain <a href>, since the export endpoints require a Bearer token.
 */
function ReportExportControls({ typeOptions, exportEndpoint, period }) {
  const [type, setType] = useState(typeOptions[0]?.value || '')
  const [downloading, setDownloading] = useState(null)
  const [error, setError] = useState('')

  const download = async (format) => {
    setDownloading(format)
    setError('')
    try {
      const response = await api.get(exportEndpoint, { params: { type, format, period }, responseType: 'blob' })
      const disposition = response.headers['content-disposition'] || ''
      const match = disposition.match(/filename="?([^"]+)"?/)
      const filename = match?.[1] || `report.${format === 'pdf' ? 'pdf' : 'xlsx'}`
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } catch {
      setError('Could not export this report.')
    } finally {
      setDownloading(null)
    }
  }

  return (
    <div className="order-lookup-form">
      <select value={type} onChange={(e) => setType(e.target.value)}>
        {typeOptions.map((opt) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
      </select>
      <button type="button" className="ghost" onClick={() => download('pdf')} disabled={downloading !== null}>
        {downloading === 'pdf' ? 'Exporting...' : 'Export PDF'}
      </button>
      <button type="button" className="ghost" onClick={() => download('xlsx')} disabled={downloading !== null}>
        {downloading === 'xlsx' ? 'Exporting...' : 'Export Excel'}
      </button>
      {error && <p className="error">{error}</p>}
    </div>
  )
}

/**
 * Announcement banner shown at the top of the Buyer/Seller/LGU Overview tab
 * -- one shared component, three call sites. Backed by GET
 * /announcements/active (see App\Models\Announcement::scopeActive), which
 * only ever returns announcements currently within their display window.
 */
function AnnouncementBanner() {
  const { data } = useQuery({
    queryKey: ['announcements-active'],
    queryFn: async () => (await api.get('/announcements/active')).data,
    retry: false,
    placeholderData: [],
  })

  if (!data?.length) return null

  return (
    <div className="announcement-banner-stack">
      {data.map((a) => (
        <div className={`announcement-banner announcement-${a.category}`} key={a.id}>
          <Megaphone size={16} />
          <div>
            <strong>{a.title}</strong>
            <p>{a.body}</p>
          </div>
        </div>
      ))}
    </div>
  )
}

function LguDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const lgu = useQuery({
    queryKey: ['lgu-dashboard'],
    queryFn: async () => (await api.get('/lgu/dashboard')).data,
    retry: false,
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
  const notifications = (lgu.data?.notifications || []).filter((notification) => !visibleNotificationIds.includes(notification.id))
  const handleMarkRead = (id) => {
    setVisibleNotificationIds((current) => (current.includes(id) ? current : [...current, id]))
    markRead.mutate(id)
  }
  const markRead = useMutation({
    mutationFn: async (id) => (await api.patch(`/lgu/notifications/${id}/read`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] }),
  })
  const notificationLink = (notification) => (notification.type?.startsWith('earnings_pending_approval') ? '/lgu/dashboard?tab=earnings' : null)
  const reviews = useQuery({
    queryKey: ['lgu-reviews'],
    queryFn: async () => (await api.get('/lgu/reviews')).data,
    retry: false,
    placeholderData: { buyer_reviews: [], seller_ratings: [] },
  })
  const approve = useMutation({
    mutationFn: async (id) => (await api.patch(`/lgu/listings/${id}/approve`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] }),
  })
  const reject = useMutation({
    mutationFn: async (id) => (await api.patch(`/lgu/listings/${id}/reject`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] }),
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
  const verifySeller = useMutation({
    mutationFn: async (id) => (await api.patch(`/lgu/sellers/${id}/verify`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-sellers'] }),
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
  const rejectedEarnings = useQuery({
    queryKey: ['lgu-rejected-earnings'],
    queryFn: async () => (await api.get('/lgu/earnings/rejected')).data,
    retry: false,
    placeholderData: [],
  })
  const reopenEarnings = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`/lgu/payments/${paymentId}/reopen`)).data,
    onSuccess: () => {
      // The row moves from the rejected list back into the approval queue, and
      // the seller's projected earnings return to their Pending Balance, so
      // both lists and the dashboard counts are refetched.
      queryClient.invalidateQueries({ queryKey: ['lgu-rejected-earnings'] })
      queryClient.invalidateQueries({ queryKey: ['lgu-earnings'] })
      queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
    },
  })
  const approveEarnings = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`/lgu/payments/${paymentId}/approve`)).data,
    onSuccess: (data, paymentId) => {
      // Remove the row instantly rather than waiting on the invalidated
      // query's network refetch -- that gap is what let a second click land
      // on an already-approved (now stale) row and surface a confusing
      // "not awaiting approval" error right after the first click succeeded.
      queryClient.setQueryData(['lgu-earnings'], (old) => (old || []).filter((payment) => payment.id !== paymentId))
      queryClient.invalidateQueries({ queryKey: ['lgu-earnings'] })
      queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
    },
    onError: (error, paymentId) => {
      // A 422 here almost always means this payment was already approved
      // (e.g. a second click on a row before the list refreshed) -- refresh
      // the list so the stale, already-approved row disappears immediately
      // instead of leaving it clickable.
      if (error.response?.status === 422) {
        queryClient.setQueryData(['lgu-earnings'], (old) => (old || []).filter((payment) => payment.id !== paymentId))
        queryClient.invalidateQueries({ queryKey: ['lgu-earnings'] })
        queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
      }
    },
  })
  const clearHoldEarnings = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`/lgu/payments/${paymentId}/clear-hold`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-earnings'] }),
  })
  const rejectEarnings = useMutation({
    mutationFn: async ({ paymentId, reason }) => (await api.patch(`/lgu/payments/${paymentId}/reject`, { reason })).data,
    onSuccess: (data, { paymentId }) => {
      queryClient.setQueryData(['lgu-earnings'], (old) => (old || []).filter((payment) => payment.id !== paymentId))
      queryClient.invalidateQueries({ queryKey: ['lgu-earnings'] })
    },
  })
  const pendingEarningsCount = pendingEarnings.data?.length ?? 0
  const pendingEarningsAmount = (pendingEarnings.data || []).reduce((sum, payment) => sum + Number(payment.amount || 0), 0)

  const wallet = useQuery({
    queryKey: ['lgu-wallet'],
    queryFn: async () => (await api.get('/lgu/wallet')).data,
    retry: false,
    placeholderData: { available_balance: 0, pending_balance: 0, processing_amount: 0, total_revenue: 0, withdrawn_amount: 0, revenue_history: [], withdrawal_requests: [] },
  })
  const [lguWithdrawForm, setLguWithdrawForm] = useState({ method: 'gcash', account_name: '', account_number: '', amount: '' })
  const [lguWithdrawFormError, setLguWithdrawFormError] = useState('')
  const requestLguWithdrawal = useMutation({
    mutationFn: async () => (await api.post('/lgu/withdrawals', {
      method: lguWithdrawForm.method,
      account_name: lguWithdrawForm.account_name,
      account_number: lguWithdrawForm.account_number,
      amount: Number(lguWithdrawForm.amount),
    })).data,
    onSuccess: () => {
      setLguWithdrawForm({ method: 'gcash', account_name: '', account_number: '', amount: '' })
      queryClient.invalidateQueries({ queryKey: ['lgu-wallet'] })
    },
  })
  const submitLguWithdrawal = () => {
    if (withdrawalFormIsIncomplete(lguWithdrawForm)) {
      setLguWithdrawFormError(REQUIRED_FIELDS_MESSAGE)
      return
    }
    setLguWithdrawFormError('')
    requestLguWithdrawal.mutate()
  }

  return (
    <Dashboard
      title="LGU Admin Dashboard"
      subtitle="Municipality-scoped approvals, reports, and reviews."
    >
      {tab === 'overview' && (
        <>
          <AnnouncementBanner />
          <StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0], ['Listings', reports.data?.listings ?? 0], ['Pending Approvals', reports.data?.pending_approvals ?? 0]]} />
          <Section title="Municipality Revenue" actions={<Link className="ghost" to="/lgu/dashboard?tab=wallet">Go to LGU Wallet</Link>}>
            <p className="helper-text">Your municipality&apos;s share of settled orders. Request a withdrawal of your Available Balance any time from the LGU Wallet page.</p>
            <StatsRow items={[
              ["Today's Revenue", currency(lgu.data?.municipality_revenue?.today_revenue ?? 0)],
              ['Monthly Revenue', currency(lgu.data?.municipality_revenue?.monthly_revenue ?? 0)],
              ['Total Revenue', currency(lgu.data?.municipality_revenue?.total_revenue ?? 0)],
              ['Available Balance', currency(lgu.data?.municipality_revenue?.available_balance ?? 0), true],
              ['Completed Orders', lgu.data?.municipality_revenue?.total_completed_orders ?? 0],
              ['Avg Revenue / Order', currency(lgu.data?.municipality_revenue?.average_revenue_per_order ?? 0)],
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
          <Section title="Notifications"><NotificationStack notifications={notifications.slice(0, 3)} onMarkRead={handleMarkRead} getLink={notificationLink} /></Section>
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
          <p className="helper-text">All listings from sellers in your municipality, including already-approved ones. Open a listing to approve, reject, archive, or delete it.</p>
          {(listingManagement.data || []).length ? (
            <div className="item-list">
              {listingManagement.data.map((item) => (
                <div className="card action" key={item.id}>
                  <div>
                    <div className="card-row"><Link className="seller-name-link" to={`/lgu/listings/${item.id}`}><strong>{item.title}</strong></Link><Badge status={item.approval_status} /></div>
                    <p>{item.sellerProfile?.hatchery_name} · {item.species}</p>
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
      {tab === 'approvals' && (
        <Section title="Pending Approvals">
          {(lgu.data?.pending_approvals || []).length ? (
            <div className="item-list">
              {lgu.data.pending_approvals.map((item) => (
                <div className="card action" key={item.id}>
                  <div>
                    <div className="card-row"><Link className="seller-name-link" to={`/lgu/listings/${item.id}`}><strong>{item.title}</strong></Link><Badge tone="warning">Pending</Badge></div>
                    <p>{item.sellerProfile?.hatchery_name}</p>
                  </div>
                  <div className="row-actions">
                    <Link className="ghost" to={`/lgu/listings/${item.id}`}>Review</Link>
                    <button type="button" onClick={() => approve.mutate(item.id)}>Approve</button>
                    <button type="button" className="ghost danger" onClick={() => reject.mutate(item.id)}>Reject</button>
                  </div>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No listings awaiting approval." />}
        </Section>
      )}
      {tab === 'sellers' && (
        <Section title="Manage Sellers">
          {sellersDirectory.data?.length ? (
            <div className="item-list">
              {sellersDirectory.data.map((seller) => (
                <div className="card action" key={seller.id}>
                  <div>
                    <div className="card-row">
                      <strong>{seller.hatchery_name}</strong>
                      <Badge status={seller.status} />
                    </div>
                    <p>{seller.user?.email}</p>
                  </div>
                  <div className="row-actions">
                    {seller.user_id && <Link className="ghost" to={`/lgu/dashboard?tab=messages&with=${seller.user_id}`}><MessageCircle size={16} /> Message</Link>}
                    {!seller.verified && <button type="button" onClick={() => verifySeller.mutate(seller.id)}>Verify</button>}
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
      )}
      {tab === 'earnings' && (
        <Section title="Seller Earnings Awaiting Approval">
          <p className="helper-text">Only completed (delivered) orders from sellers in your municipality appear here. Approving moves the earnings from the seller&apos;s Pending Balance into their Available Balance.</p>
          {(pendingEarnings.data || []).length ? (
            <div className="item-list">
              {pendingEarnings.data.map((payment) => (
                <LguEarningsRow
                  key={payment.id}
                  payment={payment}
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
      )}
      {tab === 'earnings' && (rejectedEarnings.data || []).length > 0 && (
        <Section title="Rejected Transactions">
          <p className="helper-text">
            Orders you rejected. The buyer&apos;s payment is still held for these -- rejecting doesn&apos;t refund it or release it to the seller, and no revenue is distributed. Reopening one puts it back in the queue above so it can be approved after all, and restores the seller&apos;s projected earnings for it.
          </p>
          <div className="item-list">
            {rejectedEarnings.data.map((payment) => (
              <LguRejectedEarningsRow
                key={payment.id}
                payment={payment}
                onReopen={(id) => reopenEarnings.mutate(id)}
                reopeningId={reopenEarnings.isPending ? reopenEarnings.variables : null}
              />
            ))}
          </div>
          {reopenEarnings.error && <p className="error">{reopenEarnings.error.response?.data?.message || 'Could not reopen this transaction.'}</p>}
        </Section>
      )}
      {tab === 'wallet' && (
        <>
          <StatsRow items={[['Available Balance', currency(wallet.data?.available_balance ?? 0), true], ['Pending Balance', currency(wallet.data?.pending_balance ?? 0)], ['Processing Withdrawal', currency(wallet.data?.processing_amount ?? 0)], ['Total Revenue', currency(wallet.data?.total_revenue ?? 0)], ['Withdrawn Amount', currency(wallet.data?.withdrawn_amount ?? 0)]]} />
          <Section title="Request Withdrawal">
            <p className="helper-text">Withdraws from your municipality&apos;s shared LGU revenue balance. Every LGU admin for your municipality sees the same wallet and withdrawal history.</p>
            <div className="form grid-form">
              <select value={lguWithdrawForm.method} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, method: e.target.value })}>
                <option value="gcash">GCash</option>
                <option value="maya">Maya</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
              <input value={lguWithdrawForm.account_name} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, account_name: e.target.value })} placeholder="Account name" />
              <input value={lguWithdrawForm.account_number} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, account_number: e.target.value })} placeholder="Account number" />
              <input value={lguWithdrawForm.amount} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, amount: e.target.value })} placeholder="Amount to withdraw" type="number" min="0" step="0.01" />
            </div>
            <p className="helper-text">Available to withdraw: {currency(wallet.data?.available_balance ?? 0)}</p>
            <button type="button" onClick={submitLguWithdrawal} disabled={requestLguWithdrawal.isPending}>{requestLguWithdrawal.isPending ? 'Submitting...' : 'Submit Withdrawal Request'}</button>
            {lguWithdrawFormError && <p className="error">{lguWithdrawFormError}</p>}
            {requestLguWithdrawal.error && <p className="error">{apiErrorMessage(requestLguWithdrawal.error, 'Could not submit withdrawal request.')}</p>}
            {requestLguWithdrawal.isSuccess && <p className="helper-text">Withdrawal request submitted for {currency(requestLguWithdrawal.data?.amount)}. You&apos;ll be notified once the Super Admin pays it out.</p>}
          </Section>
          <Section title="Withdrawal Requests">
            {(wallet.data?.withdrawal_requests || []).length ? (
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
                {wallet.data.withdrawal_requests.map((request) => (
                  <div className="table-row" key={request.id}>
                    <span>{currency(request.amount)}</span>
                    <span>{withdrawalMethodLabel(request.method)}</span>
                    <span>{request.account_name} · {request.account_number}</span>
                    <span><Badge status={request.status} /></span>
                    <span>{request.requestedBy?.name || 'Unknown'}</span>
                    <span>{new Date(request.created_at).toLocaleDateString()}</span>
                    <span>
                      {request.status === 'rejected' && request.rejection_reason && `Reason: ${request.rejection_reason}`}
                      {request.status === 'paid' && request.paid_at && `Paid on ${new Date(request.paid_at).toLocaleDateString()}`}
                      {(request.status === 'pending' || request.status === 'approved') && '—'}
                    </span>
                  </div>
                ))}
              </div>
            ) : <EmptyState message="No withdrawal requests yet." />}
          </Section>
          <Section title="Revenue History">
            {(wallet.data?.revenue_history || []).length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Order</span>
                  <span>Seller</span>
                  <span>Gross Amount</span>
                  <span>LGU Share</span>
                  <span>Settled Date</span>
                </div>
                {wallet.data.revenue_history.map((settlement) => (
                  <div className="table-row" key={settlement.id}>
                    <span>{settlement.order?.order_number ? `#${settlement.order.order_number}` : 'N/A'}</span>
                    <span>{settlement.sellerProfile?.hatchery_name || 'Unknown seller'}</span>
                    <span>{currency(settlement.gross_amount)}</span>
                    <span>{currency(settlement.lgu_share)}</span>
                    <span>{settlement.settled_at ? new Date(settlement.settled_at).toLocaleDateString() : 'N/A'}</span>
                  </div>
                ))}
              </div>
            ) : <EmptyState message="No settled revenue yet." />}
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
        <Section title="Reports" actions={<PeriodFilter period={reportsPeriod} onChange={setReportsPeriod} />}>
          <p className="helper-text">Graphs reflect activity in your municipality for the selected period. The summary below remains all-time.</p>
          <div className="charts-grid">
            <CategoryBarChart title="Listings by Status" data={(reports.data?.listings_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.approval_status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.approval_status)} />
            <CategoryBarChart title="Listings by Species" data={reports.data?.listings_by_species} dataKey="total" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} />
            <CategoryBarChart title="Sellers by Verification Status" data={(reports.data?.sellers_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.status)} />
            <TimeSeriesChart title={`Orders Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.orders_over_time} dataKey="count" color="var(--color-primary)" />
          </div>
          <StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0], ['Listings', reports.data?.listings ?? 0], ['Pending Approvals', reports.data?.pending_approvals ?? 0]]} />

          <h3>Municipality Revenue (LGU Share)</h3>
          <p className="helper-text">Revenue values represent your municipality&apos;s LGU Share only, for the selected period.</p>
          <StatsRow items={[
            ['Total Revenue', currency(reports.data?.revenue_cards?.total_revenue ?? 0)],
            ['Available Balance', currency(reports.data?.revenue_cards?.available_balance ?? 0), true],
            ['Total Withdrawn', currency(reports.data?.revenue_cards?.total_withdrawn ?? 0)],
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
      {tab === 'notifications' && <Section title="Notifications"><NotificationStack notifications={notifications} onMarkRead={handleMarkRead} getLink={notificationLink} /></Section>}
      {tab === 'profile' && <AdminProfilePanel endpointBase="/lgu" />}
    </Dashboard>
  )
}

function LguListingReviewPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')
  const [showArchive, setShowArchive] = useState(false)
  const [archiveReason, setArchiveReason] = useState('')
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
    mutationFn: async () => (await api.patch(`/lgu/listings/${id}/reject`, reason.trim() ? { reason: reason.trim() } : {})).data,
    onSuccess: goToApprovals,
  })
  const archive = useMutation({
    mutationFn: async () => (await api.patch(`/lgu/listings/${id}/archive`, archiveReason.trim() ? { reason: archiveReason.trim() } : {})).data,
    onSuccess: goToListingManagement,
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
  const busy = approve.isPending || reject.isPending || archive.isPending || destroyListing.isPending
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
                <h3>{seller?.hatchery_name || listing.seller}</h3>
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
                    <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for rejection (optional)" />
                    <button type="button" className="danger" onClick={() => reject.mutate()} disabled={busy}>Confirm Reject</button>
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
              <p className="helper-text">Archiving hides the listing from the marketplace but keeps its records. Deleting permanently removes it (only possible if it has no orders). The seller is notified either way.</p>
              <div className="row-actions">
                <button type="button" className="ghost" onClick={() => setShowArchive(!showArchive)} disabled={busy}>Archive Listing</button>
                <button type="button" className="ghost danger" onClick={() => setShowDelete(!showDelete)} disabled={busy}>Delete Listing</button>
              </div>
              {showArchive && (
                <div className="form grid-form withdrawal-reject-form">
                  <input value={archiveReason} onChange={(e) => setArchiveReason(e.target.value)} placeholder="Reason for archiving (optional)" />
                  <button type="button" className="danger" onClick={() => archive.mutate()} disabled={busy}>Confirm Archive</button>
                </div>
              )}
              {showDelete && (
                <div className="form grid-form withdrawal-reject-form">
                  <input value={deleteReason} onChange={(e) => setDeleteReason(e.target.value)} placeholder="Reason for deletion (required)" required />
                  <button type="button" className="danger" onClick={confirmDelete} disabled={busy || !deleteReason.trim()}>Confirm Delete</button>
                </div>
              )}
              {(archive.error || destroyListing.error) && (
                <p className="error">{archive.error?.response?.data?.message || destroyListing.error?.response?.data?.message || 'Could not update listing.'}</p>
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

function SuperAdminListingReviewPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')
  const [showArchive, setShowArchive] = useState(false)
  const [archiveReason, setArchiveReason] = useState('')
  const [showDelete, setShowDelete] = useState(false)
  const [deleteReason, setDeleteReason] = useState('')
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({ species: '', title: '', quantity: '', price_per_piece: '', description: '' })

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
    mutationFn: async () => (await api.patch(`/super-admin/listings/${id}/reject`, reason.trim() ? { reason: reason.trim() } : {})).data,
    onSuccess: goToListingManagement,
  })
  const archive = useMutation({
    mutationFn: async () => (await api.patch(`/super-admin/listings/${id}/archive`, archiveReason.trim() ? { reason: archiveReason.trim() } : {})).data,
    onSuccess: goToListingManagement,
  })
  const destroyListing = useMutation({
    mutationFn: async () => (await api.delete(`/super-admin/listings/${id}`, { data: { reason: deleteReason.trim() } })).data,
    onSuccess: goToListingManagement,
  })
  const updateListing = useMutation({
    mutationFn: async () => (await api.patch(`/super-admin/listings/${id}`, {
      species: form.species,
      title: form.title,
      quantity: Number(form.quantity),
      price_per_piece: Number(form.price_per_piece),
      description: form.description,
    })).data,
    onSuccess: () => {
      setEditing(false)
      queryClient.invalidateQueries({ queryKey: ['super-admin-listing', id] })
    },
  })
  const confirmDelete = () => {
    if (!deleteReason.trim()) return
    if (!window.confirm(`Delete "${listing.title}"? This cannot be undone.`)) return
    destroyListing.mutate()
  }
  const startEdit = () => {
    setForm({
      species: listing.species || '',
      title: listing.title || '',
      quantity: String(listing.quantity ?? ''),
      price_per_piece: String(listing.price_per_piece ?? ''),
      description: listing.description || '',
    })
    setEditing(true)
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
  const busy = approve.isPending || reject.isPending || archive.isPending || destroyListing.isPending || updateListing.isPending

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
                <h3>{seller?.hatchery_name || listing.seller}</h3>
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

      <Section title="Edit Listing">
        <div className="card">
          {editing ? (
            <div className="form grid-form">
              <input value={form.species} onChange={(e) => setForm({ ...form, species: e.target.value })} placeholder="Species" />
              <input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Title" />
              <input type="number" min="0" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} placeholder="Quantity" />
              <input type="number" min="0.01" step="0.01" value={form.price_per_piece} onChange={(e) => setForm({ ...form, price_per_piece: e.target.value })} placeholder="Price per piece" />
              <input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Description" />
              <div className="row-actions">
                <button type="button" onClick={() => updateListing.mutate()} disabled={busy}>Save Changes</button>
                <button type="button" className="ghost" onClick={() => setEditing(false)} disabled={busy}>Cancel</button>
              </div>
              {updateListing.error && <p className="error">{updateListing.error.response?.data?.message || 'Could not update listing.'}</p>}
            </div>
          ) : (
            <button type="button" className="ghost" onClick={startEdit} disabled={busy}>Edit Listing Details</button>
          )}
        </div>
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
                <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for rejection (optional)" />
                <button type="button" className="danger" onClick={() => reject.mutate()} disabled={busy}>Confirm Reject</button>
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
          <p className="helper-text">Archiving hides the listing from the marketplace but keeps its records. Deleting permanently removes it (only possible if it has no orders). The seller is notified either way.</p>
          <div className="row-actions">
            <button type="button" className="ghost" onClick={() => setShowArchive(!showArchive)} disabled={busy}>Archive Listing</button>
            <button type="button" className="ghost danger" onClick={() => setShowDelete(!showDelete)} disabled={busy}>Delete Listing</button>
          </div>
          {showArchive && (
            <div className="form grid-form withdrawal-reject-form">
              <input value={archiveReason} onChange={(e) => setArchiveReason(e.target.value)} placeholder="Reason for archiving (optional)" />
              <button type="button" className="danger" onClick={() => archive.mutate()} disabled={busy}>Confirm Archive</button>
            </div>
          )}
          {showDelete && (
            <div className="form grid-form withdrawal-reject-form">
              <input value={deleteReason} onChange={(e) => setDeleteReason(e.target.value)} placeholder="Reason for deletion (required)" required />
              <button type="button" className="danger" onClick={confirmDelete} disabled={busy || !deleteReason.trim()}>Confirm Delete</button>
            </div>
          )}
          {(archive.error || destroyListing.error) && (
            <p className="error">{archive.error?.response?.data?.message || destroyListing.error?.response?.data?.message || 'Could not update listing.'}</p>
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
      <form className="order-lookup-form" onSubmit={submit}>
        <input placeholder="Enter Order Number (e.g. FG-AB12CD)" value={orderNumberInput} onChange={(e) => setOrderNumberInput(e.target.value)} />
        <button type="submit">Search</button>
      </form>
      {lookup.isFetching && <LoadingState label="Looking up order..." />}
      {lookup.isError && <p className="error">{lookup.error?.response?.data?.message || 'Order not found.'}</p>}
      {lookup.data && <OrderDetailPanel detail={lookup.data} />}
    </Section>
  )
}

const ANNOUNCEMENT_CATEGORIES = [
  ['general', 'General'],
  ['maintenance', 'System Maintenance'],
  ['update', 'Marketplace Update'],
  ['policy', 'Policy Change'],
  ['holiday', 'Holiday Announcement'],
]

const EMPTY_ANNOUNCEMENT_FORM = { title: '', body: '', category: 'general', starts_at: '', expires_at: '' }

/**
 * Super Admin Announcements CRUD -- create/edit/delete (see
 * AnnouncementController). Creating (or updating into) a past-or-immediate
 * starts_at fires the notification fan-out right away on the backend; a
 * future starts_at is picked up later by the scheduled
 * announcements:publish command.
 */
function SuperAdminAnnouncements() {
  const [form, setForm] = useState(EMPTY_ANNOUNCEMENT_FORM)
  const [editingId, setEditingId] = useState(null)

  const list = useQuery({
    queryKey: ['super-admin-announcements'],
    queryFn: async () => (await api.get('/super-admin/announcements')).data,
    retry: false,
    placeholderData: [],
  })

  const resetForm = () => {
    setForm(EMPTY_ANNOUNCEMENT_FORM)
    setEditingId(null)
  }

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        title: form.title,
        body: form.body,
        category: form.category,
        starts_at: form.starts_at || null,
        expires_at: form.expires_at || null,
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
    setEditingId(a.id)
    setForm({
      title: a.title,
      body: a.body,
      category: a.category,
      starts_at: a.starts_at ? a.starts_at.slice(0, 16) : '',
      expires_at: a.expires_at ? a.expires_at.slice(0, 16) : '',
    })
  }

  return (
    <>
      <Section title={editingId ? 'Edit Announcement' : 'Create Announcement'}>
        <div className="form grid-form">
          <input placeholder="Title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
            {ANNOUNCEMENT_CATEGORIES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
          <input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} title="Starts at (optional -- leave blank to publish immediately)" />
          <input type="datetime-local" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} title="Expires at (optional -- leave blank for no expiration)" />
          <textarea placeholder="Announcement body" value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
        </div>
        <p className="helper-text">Leave Starts At blank to notify Buyers, Sellers, and LGU Admins immediately. Leave Expires At blank for no expiration.</p>
        <button type="button" onClick={() => save.mutate()} disabled={save.isPending || !form.title || !form.body}>
          {save.isPending ? 'Saving...' : editingId ? 'Update Announcement' : 'Publish Announcement'}
        </button>
        {editingId && <button type="button" className="ghost" onClick={resetForm}>Cancel</button>}
        {save.error && <p className="error">{save.error.response?.data?.message || 'Could not save announcement.'}</p>}
      </Section>
      <Section title="All Announcements">
        {(list.data || []).length ? (
          <div className="item-list">
            {list.data.map((a) => (
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
        ) : <EmptyState message="No announcements yet." />}
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

function SuperAdminDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [lguForm, setLguForm] = useState({ name: '', email: '', password: '', municipality_id: '' })
  const [lguFormError, setLguFormError] = useState('')
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const dashboard = useQuery({
    queryKey: ['super-admin-dashboard'],
    queryFn: async () => (await api.get('/super-admin/dashboard')).data,
    retry: false,
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
  })
  const approveWithdrawal = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/withdrawals/${id}/approve`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-withdrawals'] }),
  })
  const rejectWithdrawal = useMutation({
    mutationFn: async ({ id, reason }) => (await api.patch(`/super-admin/withdrawals/${id}/reject`, { reason })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-withdrawals'] }),
  })
  const markWithdrawalPaid = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/withdrawals/${id}/paid`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-withdrawals'] }),
  })
  const lguWithdrawals = useQuery({
    queryKey: ['super-admin-lgu-withdrawals'],
    queryFn: async () => (await api.get('/super-admin/lgu-withdrawals')).data,
    retry: false,
    placeholderData: [],
  })
  const approveLguWithdrawal = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/lgu-withdrawals/${id}/approve`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-withdrawals'] }),
  })
  const rejectLguWithdrawal = useMutation({
    mutationFn: async ({ id, reason }) => (await api.patch(`/super-admin/lgu-withdrawals/${id}/reject`, { reason })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-withdrawals'] }),
  })
  const markLguWithdrawalPaid = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/lgu-withdrawals/${id}/paid`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-withdrawals'] }),
  })
  const createLguAdmin = useMutation({
    mutationFn: async (payload) => (await api.post('/super-admin/lgu-admins', payload)).data,
    onSuccess: () => {
      setLguForm({ name: '', email: '', password: '', municipality_id: '' })
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
    setLguFormError('')
    createLguAdmin.mutate({ ...lguForm, email: (lguForm.email || '').trim() })
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
  const [moderationFilters, setModerationFilters] = useState({ role: '', action: '' })
  const moderationLog = useQuery({
    queryKey: ['super-admin-moderation-log', moderationFilters],
    queryFn: async () => (await api.get('/super-admin/moderation-log', { params: moderationFilters })).data,
    retry: false,
    placeholderData: [],
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
          <AnnouncementBanner />
          {/* Executive at-a-glance -- today's pulse and GROSS marketplace
              revenue (today / month / all-time). These are the full buyer-paid
              value, NOT the platform's own income; the Super Admin's actual
              revenue (the 6% payout fee) is the "Platform Revenue" cards in the
              Marketplace Revenue section below. Labels say "Gross" so the top
              figures are never mistaken for the platform's cut. */}
          <StatsRow items={[
            ["Today's Orders", dashboard.data?.executive?.todays_orders ?? 0, true],
            ["Today's Gross Revenue", currency(dashboard.data?.executive?.todays_gross_revenue ?? 0), true],
            ['Monthly Gross Revenue', currency(dashboard.data?.executive?.monthly_gross_revenue ?? 0), true],
            ['Gross Marketplace Revenue (All-Time)', currency(dashboard.data?.platform_revenue?.gross_marketplace_revenue ?? 0)],
          ]} />
          <StatsRow items={[['Total LGUs', reports.data?.total_lgus ?? 0], ['Total Sellers', reports.data?.total_sellers ?? 0], ['Total Buyers', reports.data?.total_buyers ?? 0], ['Total Settled Orders', dashboard.data?.platform_revenue?.total_settled_orders ?? 0]]} />
          <Section title="Action Required" actions={<Link className="ghost" to="/admin/dashboard?tab=payouts">Manage Payouts</Link>}>
            <p className="helper-text">Approval and payout queues awaiting Super Admin or LGU action across the platform.</p>
            <StatsRow items={[
              ['Pending Seller Approvals', dashboard.data?.pending_seller_approvals ?? 0],
              ['Pending LGU Approvals', dashboard.data?.pending_lgu_approvals ?? 0],
              ['Pending Listing Approvals', dashboard.data?.pending_listing_approvals ?? 0],
              ['Pending Seller Withdrawals', dashboard.data?.pending_seller_withdrawals ?? 0],
              ['Pending LGU Withdrawals', dashboard.data?.pending_lgu_withdrawals ?? 0],
            ]} />
          </Section>
          <Section title="Marketplace Revenue" actions={<Link className="ghost" to="/admin/dashboard?tab=reports">View Reports</Link>}>
            <p className="helper-text">Platform Revenue is a 6% payout fee charged when a seller withdraws -- it is realized only once the Super Admin marks that withdrawal Paid, never taken from the order at settlement time. Gross Marketplace Revenue is the full value paid by buyers before revenue sharing, recognized at settlement.</p>
            <StatsRow items={[
              ["Today's Platform Revenue", currency(dashboard.data?.platform_revenue?.today_platform_revenue ?? 0)],
              ['Monthly Platform Revenue', currency(dashboard.data?.platform_revenue?.monthly_platform_revenue ?? 0)],
              ['Total Platform Revenue', currency(dashboard.data?.platform_revenue?.total_platform_revenue ?? 0)],
              ['Avg Realized Revenue / Settled Order', currency(dashboard.data?.platform_revenue?.average_platform_revenue_per_order ?? 0)],
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
          <Section title="Account Moderation" actions={<Link className="ghost" to="/admin/dashboard?tab=moderation">View Moderation Log</Link>}>
            <StatsRow items={[
              ['Active Buyers', dashboard.data?.active_buyers ?? 0],
              ['Suspended Buyers', dashboard.data?.suspended_buyers ?? 0],
              ['Active Sellers', dashboard.data?.active_sellers ?? 0],
              ['Suspended Sellers', dashboard.data?.suspended_sellers ?? 0],
              ['Active LGU Admins', dashboard.data?.active_lgu_admins ?? 0],
              ['Suspended LGU Admins', dashboard.data?.suspended_lgu_admins ?? 0],
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
          <p className="helper-text">All listings platform-wide, across every municipality. Open a listing to view, edit, approve, reject, archive, or delete it.</p>
          {(listingManagement.data || []).length ? (
            <div className="item-list">
              {listingManagement.data.map((item) => (
                <div className="card action" key={item.id}>
                  <div>
                    <div className="card-row"><Link className="seller-name-link" to={`/admin/listings/${item.id}`}><strong>{item.title}</strong></Link><Badge status={item.approval_status} /></div>
                    <p>{item.sellerProfile?.hatchery_name} · {item.species} · {item.municipality?.name}</p>
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
          <p className="helper-text">Suspending a buyer blocks placing orders, payments, messaging, reviews, and contacting sellers -- they can still log in. Existing completed orders are unaffected. Removing deletes the account permanently and is only possible for buyers with no order history; suspend anyone who has already traded.</p>
          {(usersQuery.data?.buyers || []).length ? (
            <div className="item-list">
              {usersQuery.data.buyers.map((user) => (
                <div className="card action" key={user.id}>
                  <div>
                    <div className="card-row"><strong>{user.name}</strong><Badge status={user.status === 'suspended' ? 'suspended' : 'active'} /></div>
                    <p>{user.email} · {user.phone || 'Not Available'}</p>
                    <p className="muted">{user.buyerProfile?.ratings_count > 0 ? <>Buyer rating: {renderStars(user.buyerProfile.rating)} {Number(user.buyerProfile.rating).toFixed(1)}/5 · {user.buyerProfile.ratings_count} rating{user.buyerProfile.ratings_count === 1 ? '' : 's'}</> : 'No buyer ratings yet'}</p>
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
      {tab === 'notifications' && <Section title="Notifications"><NotificationStack notifications={notifications} onMarkRead={handleMarkRead} /></Section>}
      {tab === 'moderation' && (
        <Section title="Moderation Log">
          <p className="helper-text">Complete audit trail of every account suspension and reinstatement across Buyers, Sellers, and LGU Admins.</p>
          <div className="form grid-form">
            <select value={moderationFilters.role} onChange={(e) => setModerationFilters({ ...moderationFilters, role: e.target.value })}>
              <option value="">All roles</option>
              <option value="buyer">Buyers</option>
              <option value="seller">Sellers</option>
              <option value="lgu_admin">LGU Admins</option>
            </select>
            <select value={moderationFilters.action} onChange={(e) => setModerationFilters({ ...moderationFilters, action: e.target.value })}>
              <option value="">All actions</option>
              <option value="suspended">Suspended</option>
              <option value="reinstated">Reinstated</option>
            </select>
          </div>
          {(moderationLog.data || []).length ? (
            <div className="item-list">
              {moderationLog.data.map((log) => (
                <div className="card" key={log.id}>
                  <div className="card-row"><strong>{log.user?.name || 'Unknown account'}</strong><Badge status={log.action === 'suspended' ? 'suspended' : 'active'} /></div>
                  <p>{roleLabel(log.role)} · {log.action === 'suspended' ? 'Suspended' : 'Reinstated'} by {log.moderator?.name || 'Unknown'}</p>
                  {log.reason && <p>Reason: {log.reason}</p>}
                  {log.notes && <p className="muted">Notes: {log.notes}</p>}
                  <p className="muted">{log.created_at ? new Date(log.created_at).toLocaleString() : ''}</p>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No moderation actions match these filters." />}
        </Section>
      )}
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
              <input value={lguForm.password} onChange={(e) => setLguForm({ ...lguForm, password: stripSpaces(e.target.value) })} onKeyDown={blockSpaceKey} type="password" placeholder="Temporary password" />
              <select value={lguForm.municipality_id} onChange={(e) => setLguForm({ ...lguForm, municipality_id: e.target.value })}>
                <option value="">Select municipality</option>
                {(municipalitiesQuery.data || []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <p className="helper-text">{PASSWORD_HELP}</p>
            <button type="button" onClick={submitLguAdmin}>Create LGU Admin</button>
            {lguFormError && <p className="error">{lguFormError}</p>}
            {createLguAdmin.error && <p className="error">{apiErrorMessage(createLguAdmin.error, 'Could not create LGU admin.')}</p>}
          </Section>
          <Section title="Registered LGU Admins">
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
        <Section title="All Sellers (Platform-Wide)">
          <p className="helper-text">Super Admin may suspend any seller regardless of municipality. Suspended sellers cannot create, edit, or publish listings, receive new orders, or request withdrawals. Existing completed orders are unaffected. Removing deletes the account and its listings permanently and is only possible for sellers with no order history; suspend anyone who has already traded.</p>
          {(sellersQuery.data || []).length ? (
            <div className="item-list">
              {sellersQuery.data.map((seller) => (
                <div className="card action" key={seller.id}>
                  <div>
                    <div className="card-row"><strong>{seller.hatchery_name}</strong><Badge status={seller.status} /></div>
                    <p>{seller.municipality?.name || 'Unknown'} · {seller.verified ? 'Verified' : 'Not verified'} · {seller.listings?.length ?? 0} listings · {Number(seller.rating || 0).toFixed(1)}/5</p>
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
      )}
      {tab === 'transactions' && (
        <>
          <SuperAdminOrderLookup />
          <Section title="All Transactions">
            <OrderTable rows={dashboard.data?.transactions || []} detailsEndpoint={(orderNumber) => `/super-admin/orders/${orderNumber}`} />
          </Section>
        </>
      )}
      {tab === 'payouts' && (
        <>
        <Section title="Seller Payouts">
          {(withdrawals.data || []).length ? (
            <div className="item-list">
              {withdrawals.data.map((request) => (
                <WithdrawalRow
                  key={request.id}
                  request={request}
                  onApprove={(id) => approveWithdrawal.mutate(id)}
                  onReject={(id, reason) => rejectWithdrawal.mutate({ id, reason })}
                  onMarkPaid={(id) => markWithdrawalPaid.mutate(id)}
                />
              ))}
            </div>
          ) : <EmptyState message="No withdrawal requests yet." />}
        </Section>
        <Section title="LGU Payouts">
          {(lguWithdrawals.data || []).length ? (
            <div className="item-list">
              {lguWithdrawals.data.map((request) => (
                <WithdrawalRow
                  key={request.id}
                  request={request}
                  type="lgu"
                  onApprove={(id) => approveLguWithdrawal.mutate(id)}
                  onReject={(id, reason) => rejectLguWithdrawal.mutate({ id, reason })}
                  onMarkPaid={(id) => markLguWithdrawalPaid.mutate(id)}
                />
              ))}
            </div>
          ) : <EmptyState message="No LGU withdrawal requests yet." />}
        </Section>
        </>
      )}
      {tab === 'reports' && (
        <Section title="Platform Reports" actions={<PeriodFilter period={reportsPeriod} onChange={setReportsPeriod} />}>
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
          <StatsRow items={[['LGU Admins', reports.data?.total_lgus ?? 0], ['Transactions', reports.data?.total_transactions ?? 0], ['Pending Payouts', reports.data?.pending_payouts ?? 0], ['Listings', reports.data?.total_listings ?? 0]]} />

          <h3>Marketplace Revenue</h3>
          <p className="helper-text">Platform Revenue is a 6% payout fee charged when a seller withdraws, realized once the Super Admin marks it Paid (plotted by payout date), for the selected period. Gross Marketplace Revenue is the full value paid by buyers before revenue sharing, recognized at settlement.</p>
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
              ['Active Buyers', reports.data.moderation_summary.active_buyers],
              ['Suspended Buyers', reports.data.moderation_summary.suspended_buyers],
              ['Active Sellers', reports.data.moderation_summary.active_sellers],
              ['Suspended Sellers', reports.data.moderation_summary.suspended_sellers],
              ['Active LGU Admins', reports.data.moderation_summary.active_lgu_admins],
              ['Suspended LGU Admins', reports.data.moderation_summary.suspended_lgu_admins],
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

const BUYER_SUSPENSION_REASONS = [
  'Fraudulent Orders',
  'Fake Payments',
  'Chargeback Abuse',
  'Harassment',
  'Spam',
  'Multiple Fake Accounts',
  'Marketplace Policy Violation',
  'Other',
]

/**
 * Reusable suspend/reinstate control -- reveal-on-click form, same UX as
 * WithdrawalRow's reject flow. Suspending uses a required dropdown when
 * `reasons` is given (Buyer moderation) or an optional free-text field
 * otherwise (Seller/LGU Admin moderation). Reinstating always requires a
 * free-text reason, regardless of role -- same accountability expectation
 * as suspending: every status change needs a stated reason on the record.
 */
function ModerationAction({ suspended, reasons, onSuspend, onReinstate }) {
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

  const reasonRequired = mode === 'reinstate' || Boolean(reasons)
  const canSubmit = !reasonRequired || Boolean(reason.trim())

  const submit = () => {
    if (!canSubmit) return
    if (mode === 'suspend') {
      onSuspend(reason.trim() || undefined, notes.trim() || undefined)
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
          placeholder={mode === 'reinstate' ? 'Reason for reinstating (required)' : 'Reason (optional)'}
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
 * Enumerated grounds for permanently removing an account -- mirrors
 * SuperAdminController::BUYER_REMOVAL_REASONS / SELLER_REMOVAL_REASONS, which
 * validate the same list server-side.
 */
const BUYER_REMOVAL_REASONS = [
  'Spam Account',
  'Fake or Duplicate Account',
  'Fraudulent Registration',
  'Marketplace Policy Violation',
  'Requested by Account Owner',
  'Other',
]

const SELLER_REMOVAL_REASONS = [
  'Spam Account',
  'Fake or Duplicate Account',
  'Fraudulent Registration',
  'Fake Hatchery Details',
  'Marketplace Policy Violation',
  'Requested by Account Owner',
  'Other',
]

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
function AccountRemovalAction({ accountName, reasons, onRemove, error, removing }) {
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

function LguAdminRow({ admin, municipalities, onUpdate, onDisable, onEnable }) {
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

function WithdrawalRow({ request, onApprove, onReject, onMarkPaid, type = 'seller' }) {
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')
  const seller = request.sellerProfile
  const isLgu = type === 'lgu'

  const submitReject = () => {
    if (!reason.trim()) return
    onReject(request.id, reason.trim())
    setShowReject(false)
    setReason('')
  }

  return (
    <div className="card action withdrawal-row">
      <div>
        <div className="card-row">
          {isLgu ? (
            <strong>{request.municipality?.name || 'Unknown municipality'}</strong>
          ) : (
            <>
              <Avatar src={seller?.profile_picture} alt={seller?.hatchery_name} className="listing-seller-avatar" />
              <strong>{seller?.hatchery_name || seller?.user?.name || 'Unknown seller'}</strong>
            </>
          )}
          <Badge status={request.status} />
        </div>
        <p>
          Request #{request.id} · {currency(request.amount)} requested via {withdrawalMethodLabel(request.method)}<br />
          {request.account_name} · {request.account_number}
        </p>
        {isLgu ? (
          <p className="muted">Requested by: {request.requestedBy?.name || 'Unknown'} · No platform fee applies to LGU withdrawals.</p>
        ) : (
          <p className="muted">Platform payout fee (6%): {currency(request.platform_fee)} · Seller receives: {currency(request.net_amount)}</p>
        )}
        <p className="muted">Requested {new Date(request.created_at).toLocaleDateString()}</p>
        {request.status === 'rejected' && request.rejection_reason && (
          <p className="error">Reason: {request.rejection_reason}</p>
        )}
        {request.status === 'paid' && request.paid_at && (
          <p className="helper-text">Paid on {new Date(request.paid_at).toLocaleDateString()}</p>
        )}
      </div>
      <div className="row-actions">
        {request.status === 'pending' && (
          <button type="button" onClick={() => onApprove(request.id)}>Approve</button>
        )}
        {request.status === 'approved' && (
          <button type="button" onClick={() => onMarkPaid(request.id)}>Mark as Paid</button>
        )}
        {(request.status === 'pending' || request.status === 'approved') && (
          <button type="button" className="ghost danger" onClick={() => setShowReject(!showReject)}>Reject</button>
        )}
      </div>
      {showReject && (
        <div className="form grid-form withdrawal-reject-form">
          <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for rejection" />
          <button type="button" className="danger" onClick={submitReject} disabled={!reason.trim()}>Confirm Reject</button>
        </div>
      )}
    </div>
  )
}

function Dashboard({ title, subtitle, actions, children }) {
  return <div className="dashboard"><div className="dashboard-head"><div><p className="eyebrow">{subtitle}</p><h1>{title}</h1></div>{actions}</div>{children}</div>
}

function StatsRow({ items }) {
  return <div className="stats-grid">{items.map(([label, value, highlight]) => <Stat key={label} label={label} value={value} highlight={highlight} />)}</div>
}

function Stat({ value, label, highlight = false }) {
  return <div className={highlight ? 'stat-card stat-card-highlight' : 'stat-card'}><strong>{value}</strong><span>{label}</span></div>
}

function TopPerformerCard({ eyebrow, icon: Icon, performer }) {
  return (
    <div className="card top-performer">
      <div className="top-performer-head">
        <span className="top-performer-icon"><Icon size={18} /></span>
        <p className="eyebrow">{eyebrow}</p>
      </div>
      {performer ? (
        <>
          <strong className="top-performer-name">{performer.name}</strong>
          <p className="muted">{currency(performer.amount)} · {performer.orders} settled {performer.orders === 1 ? 'order' : 'orders'}</p>
        </>
      ) : <p className="muted">Not enough data yet.</p>}
    </div>
  )
}

function DataTable({ rows }) {
  if (!rows?.length) return <EmptyState message="No records yet." />
  const keys = Object.keys(rows[0] || {}).slice(0, 6)
  return (
    <div className="table">
      {rows.map((row, index) => (
        <div className={`table-row ${index === 0 ? 'first' : ''}`} key={row.id || row.title || index}>
          {keys.map((key) => {
            const value = row[key]
            if (value && typeof value === 'object' && '__badge' in value) return <span key={key}><Badge status={value.__badge} /></span>
            return <span key={key}>{typeof value === 'object' && value !== null ? value.title || value.name || value.hatchery_name || JSON.stringify(value) : String(value)}</span>
          })}
        </div>
      ))}
    </div>
  )
}

function UserDirectoryList({ users, messageBasePath, emptyMessage = 'No users found.' }) {
  if (!users?.length) return <EmptyState message={emptyMessage} />
  return (
    <div className="item-list">
      {users.map((user) => (
        <div className="card action" key={user.id}>
          <div>
            <div className="card-row">
              <strong>{user.name}</strong>
              <Badge status={user.status || 'unknown'} />
            </div>
            <p>{user.email} · {user.phone || 'Not Available'}</p>
            {user.buyerProfile?.ratings_count > 0 && <p className="muted">Buyer rating: {renderStars(user.buyerProfile.rating)} {Number(user.buyerProfile.rating).toFixed(1)}/5 · {user.buyerProfile.ratings_count} rating{user.buyerProfile.ratings_count === 1 ? '' : 's'}</p>}
            <p className="muted">Joined {user.created_at ? new Date(user.created_at).toLocaleDateString() : 'Not Available'}</p>
          </div>
          <div className="row-actions">
            <Link className="ghost" to={`${messageBasePath}?tab=messages&with=${user.id}`}><MessageCircle size={16} /> Message</Link>
          </div>
        </div>
      ))}
    </div>
  )
}

/**
 * Order Details expander for one OrderTable row -- lazy-fetches the shared
 * Order Details payload (see App\Support\OrderTransactionPresenter) only
 * once expanded, and renders it with OrderDetailPanel/OrderTimelineView
 * exactly like every other role's lookup view.
 */
function OrderTableDetailRow({ orderNumber, detailsEndpoint }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['order-detail', detailsEndpoint, orderNumber],
    queryFn: async () => (await api.get(detailsEndpoint(orderNumber))).data,
    enabled: Boolean(orderNumber && detailsEndpoint),
  })

  return (
    <div className="order-table-detail-row">
      {isLoading && <LoadingState label="Loading order details..." />}
      {isError && <p className="error">Could not load order details.</p>}
      {data && <OrderDetailPanel detail={data} />}
    </div>
  )
}

/**
 * The shared order list, used by the Buyer's own orders and the Super Admin's
 * platform-wide transactions.
 *
 * showPaymentStatus is off for the Buyer: the payment column reports the
 * escrow lifecycle (paid_held -> released), which tracks when the SELLER's
 * money is verified and released, not whether the buyer paid. A buyer who has
 * paid would otherwise sit at "Paid Held" until their LGU settles the order,
 * which reads like something is still owed. Their Order Status column already
 * says where the order actually is.
 */
function OrderTable({ rows, onReview, detailsEndpoint, initialExpandedOrderNumber, showPaymentStatus = true }) {
  const [expandedOrderNumber, setExpandedOrderNumber] = useState(initialExpandedOrderNumber || null)

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
      quantity: row.quantity,
      status: row.status,
      payment_status: row.payment?.status || row.payment_status || 'pending',
      total_amount: row.total_amount || row.amount || 0,
      created_at: row.created_at || row.date || '',
      review: row.review || null,
    }
  })

  if (!normalized.length) return <EmptyState message="No orders yet." />

  const toggleExpanded = (orderNumber) => setExpandedOrderNumber((current) => (current === orderNumber ? null : orderNumber))

  return (
    <div className="table">
      <div className="table-row first">
        <span>Order Name</span>
        <span>Order #</span>
        <span>Seller</span>
        <span>Qty</span>
        <span>Status</span>
        {showPaymentStatus && <span>Payment</span>}
        {onReview && <span>Review</span>}
        {detailsEndpoint && <span>Details</span>}
      </div>
      {normalized.map((row) => (
        <Fragment key={row.id}>
          <div className="table-row">
            <span>{row.order_name}</span>
            <span>{row.order_number}</span>
            <span className="order-seller-cell">
              <Avatar src={row.seller_avatar} alt={row.seller_name} className="order-seller-avatar" />
              {row.seller_name}{row.seller_contact_name ? ` (${row.seller_contact_name})` : ''}
            </span>
            <span>{Number(row.quantity).toLocaleString()}</span>
            <span><Badge status={row.status}>{statusChartLabel(row.status)}</Badge></span>
            {showPaymentStatus && <span><Badge status={row.payment_status}>{statusChartLabel(row.payment_status)}</Badge></span>}
            {onReview && <ReviewCell row={row} onReview={onReview} />}
            {detailsEndpoint && (
              <span>
                <button type="button" className="ghost" onClick={() => toggleExpanded(row.order_number)}>
                  {expandedOrderNumber === row.order_number ? 'Hide Details' : 'View Details'}
                </button>
              </span>
            )}
          </div>
          {detailsEndpoint && expandedOrderNumber === row.order_number && (
            <OrderTableDetailRow orderNumber={row.order_number} detailsEndpoint={detailsEndpoint} />
          )}
        </Fragment>
      ))}
    </div>
  )
}

/**
 * Shared Order Number search-box state/query, used by every role's Order
 * Lookup (Seller's own-listing lookup, Super Admin's global lookup) so the
 * search form and fetch behavior only exist once.
 */
function useOrderNumberLookup(endpointBuilder, queryKeyPrefix) {
  const [orderNumberInput, setOrderNumberInput] = useState('')
  const [searchedOrderNumber, setSearchedOrderNumber] = useState('')

  const query = useQuery({
    queryKey: [queryKeyPrefix, searchedOrderNumber],
    queryFn: async () => (await api.get(endpointBuilder(searchedOrderNumber))).data,
    enabled: Boolean(searchedOrderNumber),
    retry: false,
  })

  const submit = (e) => {
    e.preventDefault()
    setSearchedOrderNumber(orderNumberInput.trim().toUpperCase())
  }

  return { orderNumberInput, setOrderNumberInput, submit, query, searchedOrderNumber }
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
function OrderDetailPanel({ detail }) {
  if (!detail) return null
  return (
    <div className="order-detail-panel">
      <div className="order-detail-grid">
        <div className="order-detail-field"><span className="order-detail-field-label">Order Number</span><span>{detail.order_number}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Listing</span><span>{detail.listing?.title || detail.listing?.species || 'N/A'}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Seller</span><span>{detail.seller?.hatchery_name || 'N/A'}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Buyer</span><span>{detail.buyer?.name || 'N/A'}</span></div>
        {detail.municipality && <div className="order-detail-field"><span className="order-detail-field-label">Municipality</span><span>{detail.municipality.name}</span></div>}
        <div className="order-detail-field"><span className="order-detail-field-label">Quantity</span><span>{Number(detail.quantity).toLocaleString()} pcs</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Total Amount</span><span>{currency(detail.total_amount)}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Payment Status</span><span><Badge status={detail.payment_status || 'pending'}>{statusChartLabel(detail.payment_status || 'pending')}</Badge></span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Order Status</span><span><Badge status={detail.order_status}>{statusChartLabel(detail.order_status)}</Badge></span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Delivery Status</span><span>{detail.delivery_status}</span></div>
        <div className="order-detail-field">
          <span className="order-detail-field-label">Review Status</span>
          <span>{detail.review ? `${'★'.repeat(detail.review.rating)}${'☆'.repeat(5 - detail.review.rating)} (${detail.review.rating}/5)` : 'Not yet reviewed'}</span>
        </div>
        {detail.seller_notes && (
          <div className="order-detail-field"><span className="order-detail-field-label">Seller Notes</span><span>{detail.seller_notes}</span></div>
        )}
      </div>
      {detail.revenue_distribution_preview && (
        <div className="order-detail-grid">
          <div className="order-detail-field">
            <span className="order-detail-field-label">Revenue Distribution{detail.revenue_distribution_preview.source === 'preview' ? ' (Preview)' : ''}</span>
            <span>
              Seller {currency(detail.revenue_distribution_preview.seller_share)} · LGU {currency(detail.revenue_distribution_preview.lgu_share)} · Platform {currency(detail.revenue_distribution_preview.platform_share)}
            </span>
          </div>
          <div className="order-detail-field">
            <span className="order-detail-field-label">LGU Verification Status</span>
            <span>
              <Badge status={detail.lgu_verification?.status}>{statusChartLabel(detail.lgu_verification?.status)}</Badge>
              {detail.lgu_verification?.review_reason ? ` — ${detail.lgu_verification.review_reason}` : ''}
            </span>
          </div>
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

function ReviewCell({ row, onReview }) {
  const [open, setOpen] = useState(false)
  const [rating, setRating] = useState(5)
  const [title, setTitle] = useState('')
  const [comment, setComment] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  if (row.status !== 'completed') return <span className="muted">Not yet eligible</span>
  if (row.review) return <span className="review-given">Reviewed: {'★'.repeat(row.review.rating)}{'☆'.repeat(5 - row.review.rating)} ({row.review.rating}/5)</span>
  if (!open) return <span><button type="button" className="ghost" onClick={() => setOpen(true)}>Rate Seller</button></span>

  const submit = async () => {
    setSubmitting(true)
    setError('')
    try {
      await onReview(row.orderId, { rating, title, comment })
      setOpen(false)
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not submit review.')
      setSubmitting(false)
    }
  }

  return (
    <span className="review-form">
      <select value={rating} onChange={(e) => setRating(Number(e.target.value))}>
        {[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{n} star{n > 1 ? 's' : ''}</option>)}
      </select>
      <input placeholder="Review title (optional)" value={title} onChange={(e) => setTitle(e.target.value)} />
      <input placeholder="Write your review (optional)" value={comment} onChange={(e) => setComment(e.target.value)} />
      <button type="button" onClick={submit} disabled={submitting}>{submitting ? 'Saving...' : 'Submit'}</button>
      {error && <p className="error">{error}</p>}
    </span>
  )
}

function NotificationStack({ notifications, onMarkRead, getLink }) {
  if (!notifications?.length) return <EmptyState message="No notifications yet." />
  return (
    <div className="notification-stack">
      {notifications.map((item) => {
        const link = getLink?.(item)
        const body = <div><strong>{item.title}</strong><p>{item.body}</p></div>
        return (
          <div className={`card notification ${item.read_at ? 'read' : 'unread'}`} key={item.id}>
            {link ? <Link className="notification-link" to={link}>{body}</Link> : body}
            <button type="button" onClick={() => onMarkRead(item.id)}>Mark Read</button>
          </div>
        )
      })}
    </div>
  )
}

function MarkAllReadButton({ unreadCount, onClick, loading }) {
  return (
    <button
      type="button"
      className="ghost mark-all-read-button"
      onClick={onClick}
      disabled={loading || !unreadCount}
    >
      {loading ? 'Marking...' : 'Mark All as Read'}
    </button>
  )
}

const PERIOD_OPTIONS = [
  ['daily', 'Daily'],
  ['weekly', 'Weekly'],
  ['monthly', 'Monthly'],
  ['yearly', 'Yearly'],
]

function periodLabel(period) {
  return PERIOD_OPTIONS.find(([value]) => value === period)?.[1] || period
}

function PeriodFilter({ period, onChange }) {
  return (
    <div className="tab-bar">
      {PERIOD_OPTIONS.map(([value, label]) => (
        <button key={value} type="button" className={period === value ? 'tab active' : 'tab'} onClick={() => onChange(value)}>{label}</button>
      ))}
    </div>
  )
}

const SPECIES_CHART_COLORS = {
  Bangus: 'var(--color-primary)',
  Tilapia: 'var(--color-teal)',
  Grouper: 'var(--chart-violet)',
  Catfish: 'var(--chart-gold)',
  'Sea Bass': 'var(--chart-magenta)',
  Carp: 'var(--chart-green)',
}

function speciesChartColor(species) {
  return SPECIES_CHART_COLORS[species] || 'var(--color-neutral-text)'
}

const STATUS_CHART_COLORS = {
  success: 'var(--color-success)',
  info: 'var(--color-info)',
  warning: 'var(--color-warning)',
  danger: 'var(--color-danger)',
  neutral: 'var(--color-neutral-text)',
}

function statusChartColor(status) {
  return STATUS_CHART_COLORS[badgeTone(status)]
}

function statusChartLabel(status) {
  const key = String(status || '').toLowerCase()
  if (STATUS_LABELS[key]) return STATUS_LABELS[key]
  return String(status || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function ChartCard({ title, empty, children }) {
  return (
    <div className="card chart-card">
      <h3>{title}</h3>
      {empty ? <EmptyState message="No data for this period yet." /> : <div className="chart-body">{children}</div>}
    </div>
  )
}

function TimeSeriesChart({ title, data, dataKey, color, valueFormatter }) {
  const empty = !data?.length || data.every((point) => Number(point[dataKey]) === 0)
  return (
    <ChartCard title={title} empty={empty}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
          <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
          <XAxis dataKey="label" tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={{ stroke: 'var(--chart-grid)' }} tickLine={false} />
          <YAxis tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={false} tickLine={false} width={48} />
          <Tooltip formatter={(value) => (valueFormatter ? valueFormatter(value) : value)} contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 'var(--radius-sm)' }} />
          <Line type="monotone" dataKey={dataKey} stroke={color} strokeWidth={2} dot={{ r: 3, fill: color }} activeDot={{ r: 5 }} />
        </LineChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}

function CategoryBarChart({ title, data, dataKey, nameKey, colorFor, valueFormatter }) {
  const rows = data ?? []
  const empty = !rows.length
  return (
    <ChartCard title={title} empty={empty}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
          <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
          <XAxis dataKey={nameKey} tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={{ stroke: 'var(--chart-grid)' }} tickLine={false} />
          <YAxis tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={false} tickLine={false} width={48} />
          <Tooltip formatter={(value) => (valueFormatter ? valueFormatter(value) : value)} contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 'var(--radius-sm)' }} />
          <Bar dataKey={dataKey} radius={[4, 4, 0, 0]}>
            {rows.map((entry) => <Cell key={entry[nameKey]} fill={colorFor(entry)} />)}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}

function MessagesPanel({ initialUserId }) {
  const session = getSession()
  const [activeUserId, setActiveUserId] = useState(initialUserId || null)
  const [draft, setDraft] = useState('')
  const openedInitialRef = useRef(false)

  const threads = useQuery({
    queryKey: ['message-threads'],
    queryFn: async () => (await api.get('/messages/threads')).data,
    retry: false,
    placeholderData: [],
  })

  const thread = useQuery({
    queryKey: ['message-thread', activeUserId],
    queryFn: async () => (await api.get(`/messages/thread/${activeUserId}`)).data,
    enabled: !!activeUserId,
    retry: false,
  })

  const markRead = useMutation({
    mutationFn: async (userId) => (await api.patch(`/messages/thread/${userId}/read`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })

  const sendMessage = useMutation({
    mutationFn: async () => (await api.post('/messages', { receiver_id: activeUserId, body: draft })).data,
    onSuccess: () => {
      setDraft('')
      queryClient.invalidateQueries({ queryKey: ['message-thread', activeUserId] })
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
    },
  })

  const editMessage = useMutation({
    mutationFn: async ({ id, body }) => (await api.patch(`/messages/${id}`, { body })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['message-thread', activeUserId] })
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
    },
  })

  const deleteMessage = useMutation({
    mutationFn: async (id) => (await api.delete(`/messages/${id}`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['message-thread', activeUserId] })
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
    },
  })

  useEffect(() => {
    if (initialUserId && !openedInitialRef.current) {
      openedInitialRef.current = true
      setActiveUserId(initialUserId)
      markRead.mutate(initialUserId)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialUserId])

  const openThread = (userId) => {
    setActiveUserId(userId)
    markRead.mutate(userId)
  }

  return (
    <div className="messages-layout">
      <div className="card thread-list">
        {!threads.data?.length && <p className="helper-text">No conversations yet.</p>}
        {(threads.data || []).map((item) => (
          <button
            type="button"
            key={item.user.id}
            className={`thread-item ${activeUserId === item.user.id ? 'active' : ''}`}
            onClick={() => openThread(item.user.id)}
          >
            <Avatar src={item.user.profile_picture} alt={item.user.name} className="thread-avatar" />
            <div>
              <strong>{item.user.name}</strong>
              <span>{item.last_message?.body}</span>
            </div>
            {item.unread_count > 0 && <span className="pill">{item.unread_count} new</span>}
          </button>
        ))}
      </div>
      <div className="card thread-view">
        {!activeUserId && <p>Select a conversation to view messages.</p>}
        {activeUserId && (
          <>
            <h4>
              <Avatar src={thread.data?.user?.profile_picture} alt={thread.data?.user?.name} className="thread-header-avatar" />
              {thread.data?.user?.name || 'Conversation'}
              {session?.role === 'seller' && thread.data?.user?.role === 'buyer' && (
                <Link className="seller-name-link thread-view-profile" to={`/seller/buyers/${thread.data.user.id}`}>View Profile</Link>
              )}
            </h4>
            <div className="message-log">
              {(thread.data?.messages || []).map((message) => (
                <MessageBubble
                  key={message.id}
                  message={message}
                  isMine={message.sender_id === session?.id}
                  onEdit={(body) => editMessage.mutateAsync({ id: message.id, body })}
                  onDelete={() => deleteMessage.mutateAsync(message.id)}
                />
              ))}
              {!thread.data?.messages?.length && <p className="helper-text">Say hello to start the conversation.</p>}
            </div>
            <div className="compose-bar">
              <input placeholder="Type a message..." value={draft} onChange={(e) => setDraft(e.target.value)} />
              <button type="button" onClick={() => sendMessage.mutate()} disabled={!draft.trim() || sendMessage.isPending}>Send</button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

const MESSAGE_EDIT_WINDOW_MS = 15 * 60 * 1000

function MessageBubble({ message, isMine, onEdit, onDelete }) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(message.body)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [renderedAt] = useState(() => Date.now())

  const isDeleted = !!message.deleted_at
  const withinEditWindow = renderedAt - new Date(message.created_at).getTime() <= MESSAGE_EDIT_WINDOW_MS
  const canModify = isMine && !isDeleted

  const saveEdit = async () => {
    setBusy(true)
    setError('')
    try {
      await onEdit(draft)
      setEditing(false)
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not edit message.')
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    setBusy(true)
    setError('')
    try {
      await onDelete()
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not delete message.')
      setBusy(false)
    }
  }

  if (editing) {
    return (
      <div className={`message-bubble ${isMine ? 'mine' : 'theirs'}`}>
        <input value={draft} onChange={(e) => setDraft(e.target.value)} disabled={busy} />
        <div className="row-actions">
          <button type="button" onClick={saveEdit} disabled={busy || !draft.trim()}>Save</button>
          <button type="button" className="ghost" disabled={busy} onClick={() => { setEditing(false); setDraft(message.body); setError('') }}>Cancel</button>
        </div>
        {error && <p className="error">{error}</p>}
      </div>
    )
  }

  return (
    <div className={`message-bubble ${isMine ? 'mine' : 'theirs'}`}>
      <p className={isDeleted ? 'deleted' : ''}>{message.body}</p>
      <div className="message-meta">
        {!isDeleted && message.edited_at && <span className="muted">(edited)</span>}
        {canModify && withinEditWindow && <button type="button" className="link-action" onClick={() => setEditing(true)}>Edit</button>}
        {canModify && <button type="button" className="link-action" disabled={busy} onClick={remove}>Delete</button>}
      </div>
      {error && <p className="error">{error}</p>}
    </div>
  )
}

function PaymentSuccessPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const session = getSession()
  const orderNumber = searchParams.get('order')
  const listingId = searchParams.get('listing_id')
  const acknowledgedKey = orderNumber ? `fishmarket_payment_success_${orderNumber}` : null
  const acknowledgedRef = useRef(false)
  const acknowledge = useMutation({
    mutationFn: async () => (await api.post(`/orders/${orderNumber}/payment-success`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['buyer-notifications'] })
    },
  })
  useEffect(() => {
    if (!session || !orderNumber || acknowledgedRef.current) return
    if (acknowledgedKey && sessionStorage.getItem(acknowledgedKey)) {
      acknowledgedRef.current = true
      return
    }
    acknowledgedRef.current = true
    if (acknowledgedKey) sessionStorage.setItem(acknowledgedKey, '1')
    acknowledge.mutate()
  }, [session, orderNumber, acknowledgedKey, acknowledge])
  useEffect(() => {
    if (acknowledge.isSuccess && orderNumber) {
      navigate(`/buyer/dashboard?tab=orders&order=${orderNumber}${listingId ? `&listing_id=${listingId}` : ''}`, { replace: true })
    }
  }, [acknowledge.isSuccess, orderNumber, listingId, navigate])
  if (!session) return <Navigate to="/login" replace />
  return (
    <main className="auth-page">
      <section className="result-card success-card">
        <p className="eyebrow">Payment Successful</p>
        <h1>Order received</h1>
        <p>{orderNumber ? `Payment for order #${orderNumber} was successful and is now held in escrow.` : 'Your payment returned from PayMongo and your session is still active.'}</p>
        <div className="success-actions">
          <Link className="button" to={`/buyer/dashboard?tab=orders${orderNumber ? `&order=${orderNumber}` : ''}`}>View Orders</Link>
          <Link className="ghost" to="/buyer/dashboard?tab=notifications">Open Notifications</Link>
        </div>
      </section>
    </main>
  )
}

function PaymentCancelledPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const session = getSession()
  const orderNumber = searchParams.get('order')
  const acknowledgedKey = orderNumber ? `fishmarket_payment_failed_${orderNumber}` : null
  const acknowledgedRef = useRef(false)
  const acknowledge = useMutation({
    mutationFn: async () => (await api.post(`/orders/${orderNumber}/payment-cancelled`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['buyer-notifications'] })
    },
  })
  useEffect(() => {
    if (!session || !orderNumber || acknowledgedRef.current) return
    if (acknowledgedKey && sessionStorage.getItem(acknowledgedKey)) {
      acknowledgedRef.current = true
      return
    }
    acknowledgedRef.current = true
    if (acknowledgedKey) sessionStorage.setItem(acknowledgedKey, '1')
    acknowledge.mutate()
  }, [session, orderNumber, acknowledgedKey, acknowledge])
  if (!session) return <Navigate to="/login" replace />
  return <main className="auth-page"><section className="result-card success-card"><p className="eyebrow">Payment Declined</p><h1>Card payment declined</h1><p>{orderNumber ? `Payment for order #${orderNumber} was declined or expired. The order is marked failed and your stock reservation has been restored.` : 'Your session is still active. You can continue browsing or try again.'}</p><div className="success-actions"><button className="button" type="button" onClick={() => navigate('/buyer/dashboard?tab=browse')}>Return to Merchant</button><Link className="ghost" to="/buyer/dashboard?tab=notifications">Open Notifications</Link></div></section></main>
}

function SellersPage() {
  const { data = [] } = useQuery({
    queryKey: ['sellers'],
    queryFn: async () => {
      const response = await api.get('/sellers')
      return response.data.map((seller) => ({
        id: seller.id,
        name: seller.hatchery_name,
        municipality: seller.municipality?.name || 'Unknown',
        rating: seller.rating,
        verified: seller.verified,
        listings: seller.listings_count ?? 0,
        profile_picture: seller.profile_picture,
      }))
    },
    retry: false,
    placeholderData: [],
  })
  return <main><Section title="Verified Sellers">{data.length ? <SellerGrid items={data} /> : <EmptyState message="No sellers registered yet." />}</Section></main>
}

function SellerGrid({ items = [] }) {
  return (
    <div className="seller-grid">
      {items.map((seller) => (
        <Link className="card seller" to={sellerProfilePath(seller.id)} key={seller.id || seller.name}>
          <Avatar src={seller.profile_picture} alt={seller.name} className="seller-grid-avatar" />
          {seller.verified && <ShieldCheck size={16} className="seller-grid-badge" />}
          <h3>{seller.name}</h3>
          <p>{seller.municipality}</p>
          <strong>{seller.rating}/5</strong>
          <span>{seller.listings} listings</span>
        </Link>
      ))}
    </div>
  )
}

/**
 * Seller Posts feed -- a seller's farm/hatchery updates, shown on the shared
 * Seller Profile page to every viewer (buyer/seller/LGU/Super Admin). Only the
 * profile owner (isOwner) sees the composer and per-post Edit/Delete controls;
 * everyone else is read-only. Entirely separate from listing media.
 */
function SellerPostsSection({ seller, posts, isOwner }) {
  return (
    <Section title="Farm Posts">
      {isOwner && <SellerPostComposer />}
      {(posts || []).length ? (
        <div className="seller-post-feed">
          {posts.map((post) => <SellerPostCard key={post.id} post={post} isOwner={isOwner} seller={seller} />)}
        </div>
      ) : (
        <EmptyState message={isOwner ? 'You have not posted any farm updates yet. Share your first update above.' : 'This seller has not posted any farm updates yet.'} />
      )}
    </Section>
  )
}

function SellerPostComposer() {
  const [body, setBody] = useState('')
  const [staged, setStaged] = useState([])

  const addStaged = (files) => setStaged((current) => [...current, ...files.map((file) => ({ file, previewUrl: URL.createObjectURL(file) }))])
  const removeStaged = (index) => setStaged((current) => {
    URL.revokeObjectURL(current[index].previewUrl)
    return current.filter((_, i) => i !== index)
  })
  const clearStaged = () => setStaged((current) => {
    current.forEach((item) => URL.revokeObjectURL(item.previewUrl))
    return []
  })

  const create = useMutation({
    mutationFn: async () => {
      const formData = new FormData()
      if (body.trim()) formData.append('body', body.trim())
      staged.forEach((item) => formData.append('media[]', item.file))
      return (await api.post('/seller/posts', formData)).data
    },
    onSuccess: () => {
      setBody('')
      clearStaged()
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })

  const canPost = (body.trim() || staged.length) && !create.isPending

  return (
    <div className="card seller-post-composer">
      <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Share a farm update -- a new harvest, freshly stocked fingerlings, feeding video, or announcement..." />
      <StagedImagePicker files={staged} onAdd={addStaged} onRemove={removeStaged} maxImages={10} />
      {create.error && <p className="error">{create.error.response?.data?.message || 'Could not publish your post.'}</p>}
      <button type="button" onClick={() => create.mutate()} disabled={!canPost}>{create.isPending ? 'Posting...' : 'Post Update'}</button>
    </div>
  )
}

function SellerPostCard({ post, isOwner, seller }) {
  const session = getSession()
  const [editing, setEditing] = useState(false)
  const [body, setBody] = useState(post.body || '')
  const [media, setMedia] = useState(post.media || [])
  const [showComments, setShowComments] = useState(false)
  const [commentDraft, setCommentDraft] = useState('')
  const edited = post.updated_at && post.created_at && post.updated_at !== post.created_at
  const comments = post.comments || []
  const canInteract = !!session

  const save = useMutation({
    mutationFn: async () => (await api.patch(`/seller/posts/${post.id}`, { body: body.trim() || null })).data,
    onSuccess: () => {
      setEditing(false)
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })
  const remove = useMutation({
    mutationFn: async () => (await api.delete(`/seller/posts/${post.id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-profile'] }),
  })
  const toggleLike = useMutation({
    mutationFn: async () => (await api.post(`/seller-posts/${post.id}/like`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-profile'] }),
  })
  const addComment = useMutation({
    mutationFn: async () => (await api.post(`/seller-posts/${post.id}/comments`, { body: commentDraft.trim() })).data,
    onSuccess: () => {
      setCommentDraft('')
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })
  const deleteComment = useMutation({
    mutationFn: async (commentId) => (await api.delete(`/seller-posts/comments/${commentId}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-profile'] }),
  })

  const cancelEdit = () => {
    setEditing(false)
    setBody(post.body || '')
    setMedia(post.media || [])
  }
  const canDeleteComment = (comment) => session && (comment.user_id === session.id || session.role === 'super_admin')
  const submitComment = () => {
    if (commentDraft.trim() && !addComment.isPending) addComment.mutate()
  }

  return (
    <article className="card seller-post">
      <div className="seller-post-head">
        <div className="seller-post-author">
          <Avatar src={seller?.profile_picture} alt={seller?.hatchery_name} className="seller-post-avatar" />
          <span className="seller-post-author-info">
            <span className="seller-post-author-name"><strong>{seller?.hatchery_name || 'Seller'}</strong><RoleBadge role={seller?.user?.role || 'seller'} /></span>
            <span className="muted">{new Date(post.created_at).toLocaleString()}{edited ? ' · edited' : ''}</span>
          </span>
        </div>
        {isOwner && !editing && (
          <div className="row-actions">
            <button type="button" className="ghost" onClick={() => setEditing(true)}>Edit</button>
            <button type="button" className="ghost danger" onClick={() => { if (window.confirm('Delete this post permanently?')) remove.mutate() }} disabled={remove.isPending}>Delete</button>
          </div>
        )}
      </div>
      {editing ? (
        <>
          <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Update your post..." />
          <SellerPostMediaManager postId={post.id} media={media} onChange={setMedia} />
          {save.error && <p className="error">{save.error.response?.data?.message || 'Could not save changes.'}</p>}
          <div className="row-actions">
            <button type="button" onClick={() => save.mutate()} disabled={save.isPending}>{save.isPending ? 'Saving...' : 'Save Changes'}</button>
            <button type="button" className="ghost" onClick={cancelEdit}>Cancel</button>
          </div>
        </>
      ) : (
        <>
          {post.body && <p className="seller-post-body">{post.body}</p>}
          <MediaGallery media={post.media} title={null} />
          {(post.likes_count > 0 || comments.length > 0) && (
            <div className="seller-post-tally muted">
              {post.likes_count > 0 && <span><Heart size={13} fill="currentColor" /> {post.likes_count}</span>}
              {comments.length > 0 && <span>{comments.length} comment{comments.length === 1 ? '' : 's'}</span>}
            </div>
          )}
          <div className="seller-post-engagement">
            <button type="button" className={`ghost seller-post-action ${post.liked_by_me ? 'liked' : ''}`} onClick={() => toggleLike.mutate()} disabled={!canInteract || toggleLike.isPending}>
              <Heart size={16} fill={post.liked_by_me ? 'currentColor' : 'none'} /> {post.liked_by_me ? 'Liked' : 'Like'}
            </button>
            <button type="button" className="ghost seller-post-action" onClick={() => setShowComments((current) => !current)}>
              <MessageCircle size={16} /> Comment
            </button>
          </div>
          {showComments && (
            <div className="seller-post-comments">
              {comments.map((comment) => (
                <div className="seller-post-comment" key={comment.id}>
                  <Avatar src={comment.user?.profile_picture} alt={comment.user?.name} className="seller-post-comment-avatar" />
                  <div className="seller-post-comment-body">
                    <div className="seller-post-comment-head">
                      <strong>{comment.user?.name || 'AbaiMarket user'}</strong>
                      <RoleBadge role={comment.user?.role} />
                      <span className="muted">{new Date(comment.created_at).toLocaleDateString()}</span>
                      {canDeleteComment(comment) && (
                        <button type="button" className="seller-post-comment-delete" onClick={() => deleteComment.mutate(comment.id)} disabled={deleteComment.isPending} aria-label="Delete comment"><Trash2 size={13} /></button>
                      )}
                    </div>
                    <p>{comment.body}</p>
                  </div>
                </div>
              ))}
              {canInteract ? (
                <div className="seller-post-comment-form">
                  <input
                    value={commentDraft}
                    onChange={(e) => setCommentDraft(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitComment() } }}
                    placeholder="Write a comment..."
                  />
                  <button type="button" onClick={submitComment} disabled={!commentDraft.trim() || addComment.isPending}>Post</button>
                </div>
              ) : (
                <p className="helper-text">Log in to like or comment on this post.</p>
              )}
              {addComment.error && <p className="error">Could not post your comment. Please try again.</p>}
            </div>
          )}
        </>
      )}
      {remove.error && <p className="error">{remove.error.response?.data?.message || 'Could not delete this post.'}</p>}
    </article>
  )
}

function SellerPostMediaManager({ postId, media, onChange }) {
  const inputRef = useRef(null)
  const items = media || []
  const maxMedia = 10

  const addMedia = useMutation({
    mutationFn: async (files) => {
      const formData = new FormData()
      files.forEach((file) => formData.append('media[]', file))
      return (await api.post(`/seller/posts/${postId}/media`, formData)).data
    },
    onSuccess: (postData) => {
      onChange(postData.media)
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })
  const deleteMedia = useMutation({
    mutationFn: async (mediaId) => (await api.delete(`/seller/posts/${postId}/media/${mediaId}`)).data,
    onSuccess: (postData) => {
      onChange(postData.media)
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })

  const remainingSlots = maxMedia - items.length
  const busy = addMedia.isPending || deleteMedia.isPending

  const handleFiles = (e) => {
    const files = Array.from(e.target.files || []).slice(0, remainingSlots)
    e.target.value = ''
    if (files.length) addMedia.mutate(files)
  }

  return (
    <div className="listing-image-manager">
      <div className="listing-image-grid">
        {items.map((item) => (
          <div className="listing-image-thumb" key={item.id}>
            {item.type === 'video' ? <video src={item.url} controls /> : <img src={item.url} alt="Post media" />}
            <div className="listing-image-thumb-actions">
              <button type="button" onClick={() => deleteMedia.mutate(item.id)} disabled={busy} title="Remove media">Remove</button>
            </div>
          </div>
        ))}
        {remainingSlots > 0 && (
          <button type="button" className="listing-image-add" onClick={() => inputRef.current?.click()} disabled={busy}>
            + Add media<span className="muted">{items.length}/{maxMedia}</span>
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={LISTING_MEDIA_ACCEPT} multiple hidden onChange={handleFiles} />
      {(addMedia.error || deleteMedia.error) && (
        <p className="error">{addMedia.error?.response?.data?.message || deleteMedia.error?.response?.data?.message || 'Could not update post media.'}</p>
      )}
    </div>
  )
}

function SellerProfilePage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const session = getSession()
  const { data } = useQuery({
    queryKey: ['seller-profile', id],
    queryFn: async () => (await api.get(`/sellers/${id}`)).data,
    retry: false,
  })

  if (!data) return <main className="auth-page"><LoadingState label="Loading seller profile..." /></main>

  const seller = data.seller
  const reviews = data.reviews || []
  const sellerListings = (data.listings || []).map((item) => ({
    ...item,
    sellerProfile: seller,
    seller: seller.hatchery_name,
    municipality: seller.municipality?.name,
    price: item.price_per_piece,
    status: item.approval_status === 'approved' ? 'Approved' : 'Pending',
    rating: seller.rating,
  }))
  const galleryMedia = (seller.gallery || []).map((url, index) => ({ id: `gallery-${index}`, type: 'photo', title: `Farm photo ${index + 1}`, url }))
  const isOwner = session?.role === 'seller' && seller.user_id === session.id
  const farmDetails = [
    ['Farming Methods', seller.farming_methods],
    ['Fish Raising Practices', seller.fish_raising_practices],
    ['Water Source', seller.water_source],
    ['Feeding Practices', seller.feeding_practices],
    ['Farm History', seller.farm_history],
    ['Certifications', seller.certifications],
  ].filter(([, value]) => value)

  return (
    <main className="seller-profile-page">
      <img className="seller-cover-photo" src={seller.cover_photo || DEFAULT_COVER_IMAGE} alt={`${seller.hatchery_name} cover`} />
      <section className="card seller-profile-header">
        <div className="seller-header-row">
          <img className="seller-avatar" src={seller.profile_picture || DEFAULT_AVATAR_IMAGE} alt={`${seller.hatchery_name} profile`} />
          <div>
            <div className="card-row">
              <h1>{seller.hatchery_name}</h1>
              {seller.verified && <Badge tone="success">Verified Seller</Badge>}
            </div>
            <div className="stats-inline">
              <Stat value={renderStars(seller.rating)} label={`${seller.rating}/5 · ${reviews.length} review${reviews.length === 1 ? '' : 's'}`} />
              <Stat value={sellerListings.length} label="Active listings" />
              <Stat value={data.completed_sales ?? 0} label="Completed sales" />
            </div>
          </div>
        </div>
        {seller.description && !(seller.verified && seller.description === DEFAULT_SELLER_DESCRIPTION) && <p className="listing-description">{seller.description}</p>}
        <div className="detail-meta">
          {seller.user?.name && seller.user.name !== seller.hatchery_name && <span><strong>Seller Name:</strong> {seller.user.name}</span>}
          <span><strong>Municipality:</strong> {seller.municipality?.name || 'Unknown'}</span>
          <span><strong>Address:</strong> {seller.address || 'Not provided'}</span>
          <span><strong>Contact:</strong> {seller.user?.phone || 'Not provided'}</span>
          <span><strong>Email:</strong> {seller.user?.email}</span>
          {seller.years_experience != null && <span><strong>Experience:</strong> {seller.years_experience} year{seller.years_experience === 1 ? '' : 's'}</span>}
        </div>
        {seller.user_id && (!session || ['buyer', 'lgu_admin', 'super_admin'].includes(session.role)) && (
          <button
            className="button"
            type="button"
            onClick={() => {
              const chatPath = `${roleRoutes[session?.role] || '/buyer/dashboard'}?tab=messages&with=${seller.user_id}`
              navigate(session ? chatPath : '/login', session ? undefined : { state: { from: chatPath } })
            }}
          >
            <MessageCircle size={16} /> Chat Seller
          </button>
        )}
      </section>
      {farmDetails.length > 0 && (
        <Section title="About This Hatchery">
          <div className="farm-details-grid">
            {farmDetails.map(([label, value]) => (
              <div className="card" key={label}>
                <h4>{label}</h4>
                <p className="listing-description">{value}</p>
              </div>
            ))}
          </div>
        </Section>
      )}
      <SellerPostsSection seller={seller} posts={data.posts} isOwner={isOwner} />
      <Section title="Available Stock">
        {sellerListings.length ? (
          <ListingGrid
            items={sellerListings}
            detailPath={
              session?.role === 'buyer' ? (item) => `/buyer/listings/${item.id}?source=browse`
                : session?.role === 'lgu_admin' ? (item) => `/lgu/listings/${item.id}`
                  : session?.role === 'super_admin' ? (item) => `/admin/listings/${item.id}`
                    : undefined
            }
          />
        ) : <EmptyState message="No listings available from this seller yet." />}
      </Section>
      <MediaGallery media={galleryMedia} title="Farm Gallery" />
      <Section title="Buyer Reviews">
        {reviews.length ? (
          <div className="review-list">
            {reviews.map((review) => (
              <div className="card review-item" key={review.id}>
                <div className="card-row">
                  <strong>{renderStars(review.rating)}</strong>
                  <span className="muted">{new Date(review.created_at).toLocaleDateString()}</span>
                </div>
                <p className="review-author"><Avatar src={review.buyer?.profile_picture} alt={review.buyer?.name} className="review-avatar" />{review.buyer?.name || 'AbaiMarket Buyer'}</p>
                {review.title && <p className="review-title">{review.title}</p>}
                <p>{review.comment || 'No comment left.'}</p>
              </div>
            ))}
          </div>
        ) : <EmptyState message="No reviews yet." />}
      </Section>
    </main>
  )
}

function StarRatingInput({ value, onChange }) {
  return (
    <div className="star-input" role="radiogroup" aria-label="Rating">
      {[1, 2, 3, 4, 5].map((n) => (
        <button
          type="button"
          key={n}
          className={`star-btn ${n <= value ? 'on' : ''}`}
          onClick={() => onChange(n)}
          aria-label={`${n} star${n > 1 ? 's' : ''}`}
          aria-pressed={n <= value}
        >★</button>
      ))}
    </div>
  )
}

/**
 * The seller's side of feedback: rate a buyer for one completed order (the
 * mirror of a buyer's Review -- see ReviewCell and SellerController::rateBuyer).
 *
 * Used from two places, hence the props: the Buyer Profile page's "Rate this
 * Buyer" list, and inline on a completed row in Order Management. invalidateKey
 * says which cached query the new rating invalidates, since each entry point
 * reads its orders from a different endpoint.
 */
function BuyerRateOrderForm({ order, invalidateKey = 'seller-buyer-profile', showHeader = true, onDone }) {
  const [rating, setRating] = useState(0)
  const [comment, setComment] = useState('')
  const submit = useMutation({
    mutationFn: async () => (await api.post(`/orders/${order.id}/rate-buyer`, { rating, comment: comment.trim() || null })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [invalidateKey] })
      onDone?.()
    },
  })

  return (
    <div className="card buyer-rate-form">
      {showHeader && (
        <div className="card-row"><strong>Order {order.order_number}</strong><span className="muted">{order.listing?.species || order.listing?.title}</span></div>
      )}
      <StarRatingInput value={rating} onChange={setRating} />
      <textarea value={comment} onChange={(e) => setComment(e.target.value)} placeholder="Optional note (payment reliability, communication, pickup, etc.)" />
      {submit.error && <p className="error">{submit.error.response?.data?.message || 'Could not submit rating.'}</p>}
      <div className="row-actions">
        <button type="button" onClick={() => submit.mutate()} disabled={!rating || submit.isPending}>{submit.isPending ? 'Submitting...' : 'Submit Rating'}</button>
        {onDone && <button type="button" className="ghost" onClick={onDone}>Cancel</button>}
      </div>
    </div>
  )
}

function BuyerProfileForSellerPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['seller-buyer-profile', id],
    queryFn: async () => (await api.get(`/seller/buyers/${id}`)).data,
    retry: false,
  })

  if (isLoading) return <main className="auth-page"><LoadingState label="Loading buyer profile..." /></main>
  if (isError || !data) {
    return (
      <main className="auth-page">
        <section className="result-card">
          <h1>Buyer profile unavailable</h1>
          <p>{error?.response?.data?.message || 'You can only view profiles of buyers who have ordered from you or messaged you.'}</p>
          <Link className="button" to="/seller/dashboard?tab=orders">Back to Orders</Link>
        </section>
      </main>
    )
  }

  const buyer = data.buyer
  const stats = data.stats
  const reviews = data.reviews || []
  const buyerRating = data.buyer_rating || { average: 0, count: 0 }
  const buyerRatings = data.buyer_ratings || []
  const sellerOrders = data.seller_orders || []
  const completedUnrated = sellerOrders.filter((order) => order.status === 'completed' && !order.buyerRating)

  return (
    <main className="seller-profile-page">
      <section className="card seller-profile-header">
        <div className="seller-header-row">
          <Avatar src={buyer.profile_picture} alt={buyer.name} className="seller-avatar" />
          <div>
            <div className="card-row"><h1>{buyer.name}</h1><RoleBadge role="buyer" /></div>
            <div className="stats-inline">
              <Stat value={renderStars(buyerRating.average)} label={`${Number(buyerRating.average).toFixed(1)}/5 · ${buyerRating.count} buyer rating${buyerRating.count === 1 ? '' : 's'}`} />
              <Stat value={stats.total_orders} label="Orders with you" />
              <Stat value={stats.completed_orders_all} label="Completed orders (all sellers)" />
            </div>
          </div>
        </div>
        <div className="detail-meta">
          <span><strong>Municipality:</strong> {buyer.municipality?.name || 'Not provided'}</span>
          <span><strong>Member Since:</strong> {new Date(buyer.created_at).toLocaleDateString()}</span>
          <span><strong>Total Spent (with you):</strong> {currency(stats.total_spent)}</span>
          <span><strong>Most Recent Purchase:</strong> {stats.most_recent_purchase ? new Date(stats.most_recent_purchase).toLocaleDateString() : 'None yet'}</span>
        </div>
        <button className="button" type="button" onClick={() => navigate(`/seller/dashboard?tab=messages&with=${buyer.id}`)}>
          <MessageCircle size={16} /> Message Buyer
        </button>
      </section>

      <Section title="Rate this Buyer">
        <p className="helper-text">Rate a buyer after a completed order so other sellers can tell they&apos;re reliable. One rating per completed order.</p>
        {completedUnrated.length ? (
          <div className="item-list">
            {completedUnrated.map((order) => <BuyerRateOrderForm key={order.id} order={order} />)}
          </div>
        ) : (
          <EmptyState message={stats.completed_orders ? 'You have rated all your completed orders with this buyer.' : 'You can rate this buyer once you have a completed order with them.'} />
        )}
      </Section>

      <Section title="Buyer Ratings from Sellers">
        <p className="helper-text">How sellers across the platform have rated this buyer.</p>
        {buyerRatings.length ? (
          <div className="review-list">
            {buyerRatings.map((entry) => (
              <div className="card review-item" key={entry.id}>
                <div className="card-row">
                  <p className="review-author"><Avatar src={entry.sellerProfile?.profile_picture} alt={entry.sellerProfile?.hatchery_name} className="review-avatar" />{entry.sellerProfile?.hatchery_name || 'Seller'} <RoleBadge role="seller" /></p>
                  <strong>{renderStars(entry.rating)}</strong>
                </div>
                {entry.comment && <p>{entry.comment}</p>}
                <div className="detail-meta">
                  {entry.order?.listing?.species && <span><strong>Species:</strong> {entry.order.listing.species}</span>}
                  {entry.order?.order_number && <span><strong>Order ID:</strong> #{entry.order.order_number}</span>}
                  <span className="muted">{new Date(entry.created_at).toLocaleDateString()}</span>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState message="No seller has rated this buyer yet." />}
      </Section>

      <Section title="Reviews from this Buyer">
        <p className="helper-text">Reviews this buyer left on your orders.</p>
        {reviews.length ? (
          <div className="review-list">
            {reviews.map((review) => (
              <div className="card review-item" key={review.id}>
                <div className="card-row">
                  <p className="review-author"><Avatar src={buyer.profile_picture} alt={buyer.name} className="review-avatar" />{buyer.name} <RoleBadge role="buyer" /></p>
                  <strong>{renderStars(review.rating)}</strong>
                </div>
                {review.title && <p className="review-title">{review.title}</p>}
                <p>{review.comment || 'No comment left.'}</p>
                <div className="detail-meta">
                  {review.order?.listing?.species && <span><strong>Species:</strong> {review.order.listing.species}</span>}
                  {review.order?.order_number && <span><strong>Order ID:</strong> #{review.order.order_number}</span>}
                  <span className="muted">{new Date(review.created_at).toLocaleDateString()}</span>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState message="This buyer hasn't left a review yet." />}
      </Section>
    </main>
  )
}

function AboutPage({ compact = false }) {
  return <Section title="About the Platform"><div className="about-card"><p>AbaiMarket is a web-based marketplace for local fingerling supply. It supports buyers, hatcheries, LGU admins, and platform admins with role-based dashboards, listing approval, PayMongo checkout, messaging, reviews, notifications, and Gemini AI farming assistance.</p>{!compact && <Link className="button" to="/register">Join AbaiMarket</Link>}</div></Section>
}

function Section({ title, actions, children }) {
  return (
    <section className="section">
      <div className="section-head"><h2>{title}</h2>{actions}</div>
      {children}
    </section>
  )
}

function Step({ n, t, d }) {
  return <div className="card step"><span>{n}</span><h3>{t}</h3><p>{d}</p></div>
}

const AI_GREETING_BY_ROLE = {
  buyer: 'Ask AbaiMarket AI about buying fingerlings, contacting sellers, orders, wallet, reviews, or fish farming basics like species, water quality, and feeding.',
  seller: 'Ask AbaiMarket AI about your listings, orders, wallet, seller earnings, withdrawals, reviews, or business recommendations like restocking and top-performing species.',
  lgu_admin: 'Ask AbaiMarket AI about pending approvals, seller verification, seller earnings, reports, or municipality statistics -- scoped to your own municipality.',
  super_admin: 'Ask AbaiMarket AI about platform-wide statistics, listings, payouts, reports, or municipality comparisons and trends.',
}

const AI_PLACEHOLDER_BY_ROLE = {
  buyer: 'Ask about buying, sellers, orders, or fish care...',
  seller: 'Ask about your listings, orders, wallet, or sales...',
  lgu_admin: 'Ask about approvals, sellers, earnings, or reports...',
  super_admin: 'Ask about platform stats, payouts, or municipalities...',
}

/**
 * Reply-language options for the AI Assistant, shared by every role. The
 * values must match AiAssistantController::LANGUAGES on the backend, which
 * validates them; '' means "Auto", the original behaviour of detecting the
 * language from the message itself (see App\Support\AiLanguageDetector).
 */
const AI_LANGUAGES = [
  ['', 'Auto-detect'],
  ['English', 'English'],
  ['Tagalog', 'Tagalog'],
  ['Bisaya', 'Bisaya'],
]

const AI_LANGUAGE_STORAGE_KEY = 'fishmarket_ai_language'

// Read once at module load rather than on every mount; the widget remounts on
// navigation and this is a plain string, not reactive state that others share.
let storedAiLanguage = ''
try {
  storedAiLanguage = localStorage.getItem(AI_LANGUAGE_STORAGE_KEY) || ''
} catch {
  storedAiLanguage = ''
}

function aiErrorMessage(err) {
  if (!err?.response) return 'Network error -- please check your connection and try again.'
  const status = err.response.status
  if (status === 422) return 'Please enter a question first.'
  if (status === 429) return 'Too many requests right now -- please wait a moment and try again.'
  if (status >= 500) return 'The AI assistant is temporarily unavailable. Please try again shortly.'
  return 'Something went wrong. Please try again.'
}

function FloatingAi() {
  const [open, setOpen] = useState(false)
  const [message, setMessage] = useState('')
  const [chat, setChat] = useState([])
  const [error, setError] = useState(null)
  const [language, setLanguage] = useState(storedAiLanguage)
  const chatLogRef = useRef(null)
  const role = getSession()?.role || 'buyer'
  const aiGreeting = { role: 'ai', text: AI_GREETING_BY_ROLE[role] || AI_GREETING_BY_ROLE.buyer }

  const chooseLanguage = (value) => {
    setLanguage(value)
    storedAiLanguage = value
    try {
      if (value) localStorage.setItem(AI_LANGUAGE_STORAGE_KEY, value)
      else localStorage.removeItem(AI_LANGUAGE_STORAGE_KEY)
    } catch {
      // Private mode / blocked storage -- the choice still applies for this
      // session, it just won't be remembered after a reload.
    }
  }

  const history = useQuery({
    queryKey: ['ai-assistant-history'],
    queryFn: async () => (await api.get('/ai-assistant/history')).data,
    retry: false,
    refetchOnWindowFocus: false,
  })

  // Prior conversation history (from the server) is rendered directly from
  // the query result rather than copied into local state, so there is no
  // effect racing the query's async resolution -- `chat` only ever holds
  // messages sent during this mount.
  const historyMessages = (history.data || []).flatMap((entry) => [
    { role: 'user', text: entry.message },
    { role: 'ai', text: entry.response },
  ])
  const displayChat = [aiGreeting, ...historyMessages, ...chat]

  const ask = useMutation({
    // language is omitted when set to Auto, so the backend keeps detecting it
    // from the message exactly as it always has.
    mutationFn: async (question) => (await api.post('/ai-assistant/ask', { question, language: language || null })).data.response,
  })

  useEffect(() => {
    if (chatLogRef.current) chatLogRef.current.scrollTop = chatLogRef.current.scrollHeight
  }, [displayChat.length, ask.isPending])

  const sendQuestion = (question) => {
    setError(null)
    ask.mutate(question, {
      onSuccess: (response) => setChat((current) => [...current, { role: 'ai', text: response }]),
      onError: (err) => setError({ message: aiErrorMessage(err), question }),
    })
  }

  const submit = () => {
    const question = message.trim()
    if (!question || ask.isPending) return
    setChat((current) => [...current, { role: 'user', text: question }])
    setMessage('')
    sendQuestion(question)
  }

  const retry = () => {
    if (!error?.question || ask.isPending) return
    sendQuestion(error.question)
  }

  return (
    <div className="ai-widget">
      <button className="ai-toggle" onClick={() => setOpen(!open)} type="button"><Bot size={20} /> AI</button>
      {open && (
        <div className="ai-panel">
          <div className="ai-panel-head">
            <h3>AbaiMarket AI Assistant</h3>
            <select
              className="ai-language-picker"
              value={language}
              onChange={(e) => chooseLanguage(e.target.value)}
              aria-label="Reply language"
              title="Reply language"
            >
              {AI_LANGUAGES.map(([value, label]) => <option key={value || 'auto'} value={value}>{label}</option>)}
            </select>
          </div>
          <div className="chat-log" ref={chatLogRef}>
            {displayChat.map((m, i) => <p className={m.role} key={`${m.role}-${i}`}>{m.text}</p>)}
            {ask.isPending && (
              <p className="ai ai-typing"><span className="typing-dots"><span /><span /><span /></span></p>
            )}
          </div>
          {error && (
            <div className="ai-error">
              <p className="error">{error.message}</p>
              <button type="button" className="ghost" onClick={retry} disabled={ask.isPending}>Retry</button>
            </div>
          )}
          <textarea
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault()
                submit()
              }
            }}
            placeholder={AI_PLACEHOLDER_BY_ROLE[role] || AI_PLACEHOLDER_BY_ROLE.buyer}
            disabled={ask.isPending}
          />
          <button onClick={submit} type="button" disabled={ask.isPending || !message.trim()}>
            {ask.isPending ? 'Thinking...' : 'Ask AbaiMarket AI'}
          </button>
        </div>
      )}
    </div>
  )
}

export default App
