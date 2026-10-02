import axios from 'axios'
import { QueryClient, QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm, useWatch } from 'react-hook-form'
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
  Clock,
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
  Scale,
  Megaphone,
  MessageCircle,
  PlayCircle,
  Search,
  CalendarDays,
  Camera,
  Check,
  Eye,
  EyeOff,
  Flag,
  KeyRound,
  Mail,
  Sprout,
  UserRound,
  ShieldAlert,
  ShieldCheck,
  ShoppingBag,
  ShoppingCart,
  Star,
  Store,
  Trash2,
  UserPlus,
  Users as UsersIcon,
  Timer,
  Video as VideoIcon,
  Wallet,
  X,
  XCircle,
} from 'lucide-react'
import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react'
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
  // Grouper is no longer offered (replaced by Tuna in SPECIES_OPTIONS) but the
  // mapping stays so any listing already recorded against it keeps its
  // artwork. Tuna has no illustration yet and falls through to the default.
  [/grouper/i, '/placeholders/grouper.svg'],
  [/sea.?bass/i, '/placeholders/sea-bass.svg'],
]
// The species the marketplace filters by, in one place -- Browse, the in-app
// marketplace browser and the landing page's Shop by Species row all read this
// list, so they can never drift apart. Each one has artwork in
// SPECIES_PLACEHOLDERS above.
const SPECIES_OPTIONS = ['Bangus', 'Tilapia', 'Tuna', 'Catfish', 'Sea Bass', 'Carp']
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

/**
 * The listing's media MINUS the one already shown as the main image, so the
 * "Seller Care Photos & Videos" gallery never repeats the thumbnail the buyer
 * is already looking at. A listing with a single photo therefore has no
 * gallery at all -- MediaGallery renders nothing for an empty list.
 *
 * Excludes the exact item resolveListingImage() picked rather than simply
 * dropping the first: the thumbnail is the first *photo*, so for a listing
 * whose first upload is a video, slicing index 0 would hide the video and
 * still show the thumbnail again.
 */
function galleryMediaFor(item) {
  const thumbnail = item?.media?.find((media) => media.type === 'photo' && media.url)
  return (item?.media || []).filter((media) => media.id !== thumbnail?.id)
}

/**
 * Unit of Measurement. The backend is the source of truth (see
 * FingerlingListing::UNIT_TYPES) and sends unit_label/unit_label_plural on
 * every listing; these are the fallbacks for anything that predates the
 * feature or comes from a payload without them, and the option list the
 * seller's form renders.
 */
const UNIT_TYPES = [
  { value: 'piece', label: 'Per Piece', short: 'pc', plural: 'pcs' },
  { value: 'kilogram', label: 'Per Kilogram', short: 'kg', plural: 'kg' },
  { value: 'bulk', label: 'Per Bulk', short: 'bulk', plural: 'bulk' },
]

/*
 * There is no longer a unit picker on the listing form: every listing is
 * counted, priced and stocked in single fingerlings, and "bulk" is a choice
 * the BUYER makes at order time. UNIT_TYPES above is kept only so listings
 * created while units existed still render their old labels rather than
 * silently reading as something else.
 */

function unitMeta(unitType) {
  return UNIT_TYPES.find((unit) => unit.value === unitType) || UNIT_TYPES[0]
}

/** "pc" / "kg" / "bulk" -- the per-unit price suffix. */
function unitLabel(item) {
  return item?.unit_label || unitMeta(item?.unit_type).short
}

/** "pcs" / "kg" / "bulk" -- labels a quantity. */
function unitLabelPlural(item) {
  return item?.unit_label_plural || unitMeta(item?.unit_type).plural
}

/** A listing's minimum order, never below 1. */
function minimumOrder(item) {
  return Math.max(1, Number(item?.minimum_order) || 1)
}

/** e.g. "500 pcs", "12 kg". */
function formatQuantity(quantity, item) {
  return `${Number(quantity || 0).toLocaleString()} ${unitLabelPlural(item)}`
}

/**
 * Stock, in the words a buyer needs. Everything is counted in single fish, so
 * the number is the number -- but when the seller has stated a bulk size we
 * also say what that buys, because a buyer thinking in bulks should not have
 * to divide.
 */
function formatStock(item) {
  const fish = Number(item?.quantity || 0)
  const bulk = Number(item?.pieces_per_unit || 0)
  const base = `${fish.toLocaleString()} qty`
  if (bulk <= 0) return base
  const bulks = Number(item?.available_bulks ?? Math.floor(fish / bulk))
  return `${base} (${bulks.toLocaleString()} bulk${bulks === 1 ? '' : 's'})`
}

/** Fish in one bulk, or null when the seller has not stated it. */
function bulkSize(item) {
  const n = Number(item?.pieces_per_unit || 0)
  return n > 0 ? n : null
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

/**
 * Display shape for a seller row from GET /sellers. LandingPage and
 * SellersPage share the ['sellers'] query cache, so they MUST cache the same
 * shape: whichever mounts first decides what the other reads. Mapping inside
 * both queryFns (rather than after the query, per page) is what keeps them
 * agreeing -- note `municipality` collapses to a string here, and handing
 * SellerGrid the raw object instead crashes the render.
 */
function mapSeller(seller) {
  return {
    id: seller.id,
    name: seller.hatchery_name,
    municipality: seller.municipality?.name || 'Unknown',
    rating: seller.rating,
    verified: seller.verified,
    listings: seller.listings_count ?? 0,
    profile_picture: seller.profile_picture,
  }
}

function renderStars(rating) {
  const rounded = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)))
  return <span className="stars">{'★'.repeat(rounded)}{'☆'.repeat(5 - rounded)}</span>
}

function currency(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

/**
 * When an order was placed, formatted the same way for every role -- Buyer,
 * Seller, LGU Admin, and Super Admin all read the same string for the same
 * order. `withTime` is off for tight table cells that render the time
 * separately.
 */
function formatOrderDate(value, { withTime = true } = {}) {
  if (!value) return '--'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '--'
  const day = date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
  return withTime ? `${day}, ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : day
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
}

/** Spaces and dashes people type into account numbers ("0995 475 7102"). */
function normalizeAccountNumber(value) {
  return String(value || '').replace(/[\s-]/g, '')
}

/**
 * The first problem with a filled-in withdrawal form, or null. Mirrors
 * App\Support\PayoutAccount on the server -- same patterns, same messages:
 * GCash / Maya need an 11-digit mobile number starting with 09, a bank
 * transfer needs a 10-16 digit account number, and the amount must be a
 * positive number.
 */
function withdrawalFormIssue(form) {
  const accountNumber = normalizeAccountNumber(form.account_number)
  if (form.method === 'bank_transfer') {
    if (!/^\d{10,16}$/.test(accountNumber)) return 'Enter a valid bank account number (10 to 16 digits, numbers only).'
  } else if (!/^09\d{9}$/.test(accountNumber)) {
    return 'Enter a valid 11-digit mobile number starting with 09 (e.g. 09954757102).'
  }
  const amount = Number(form.amount)
  if (!Number.isFinite(amount) || amount <= 0) return 'Please enter a valid amount.'
  return null
}

function accountNumberPlaceholder(method) {
  return method === 'bank_transfer' ? 'Bank account number' : 'Mobile number (09XXXXXXXXX)'
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
  refund_pending: 'warning',
  // Green, not grey: a completed refund is a resolved good outcome for the
  // buyer, the same class of event as 'released' or 'completed'. Grey read as
  // "nothing happened here", which is the opposite of what it means.
  refunded: 'success',
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
  refund_pending: 'Refund Pending',
}

/**
 * What the BUYER is told about payment, as [label, tone].
 *
 * The stored payment status tracks the ESCROW lifecycle: money captured
 * ('paid_held'), then released to the seller once their LGU approves the
 * earnings ('released'). That is the seller's concern. A buyer who has paid
 * should read "Paid" and keep reading "Paid" -- showing them "Paid Held" until
 * their LGU settles the order reads like they still owe something. The seller
 * and Super Admin keep the raw escrow status (see OrderTable's paymentView).
 */
const BUYER_PAYMENT_VIEW = {
  pending: ['Unpaid', 'warning'],
  checkout_created: ['Unpaid', 'warning'],
  paid_held: ['Paid', 'success'],
  released: ['Paid', 'success'],
  refund_pending: ['Refund Pending', 'warning'],
  refunded: ['Refunded', 'success'],
  failed: ['Not Paid', 'danger'],
  cancelled: ['Not Paid', 'danger'],
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
            <Route path="/" element={<RedirectIfSignedIn><LandingPage /></RedirectIfSignedIn>} />
            <Route path="/browse" element={<BrowsePage />} />
            <Route path="/sellers" element={<SellersPage />} />
            <Route path="/about" element={<AboutPage />} />
            <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
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
          <Route path="/seller/listings/:id" element={<Protected allowed={['seller']}><SellerListingDetailPage /></Protected>} />
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
      <SiteAnnouncementBar />
      <header className="site-header">
        <Link className="brand" to={homeRoute}><span><Fish size={22} /></span>AbaiMarket</Link>
        <nav>
          <Link to="/">Home</Link>
          <Link to="/browse">Browse</Link>
          <Link to="/sellers">Sellers</Link>
          <Link to="/about">About</Link>
        </nav>
        {session ? (
          // Browse/Sellers/About stay reachable while signed in, so without
          // this the header offers a signed-in visitor no way back into the
          // app -- Login/Register are hidden and nothing replaces them.
          <div className="nav-actions">
            <Link className="button" to={homeRoute}>Go to Dashboard</Link>
          </div>
        ) : (
          <div className="nav-actions">
            <Link className="ghost" to="/login">Login</Link>
            <Link className="button" to="/register">Register</Link>
          </div>
        )}
      </header>
      {/* Scope wrapper: the storefront-scale type and section rhythm below
          apply to the public marketing pages only. The four role dashboards
          are dense tables and approval queues where that spacing would mean
          far more scrolling for the same work, so they keep the base styles. */}
      <div className="public-shell">
        <Outlet />
      </div>
      <FloatingAi />
    </>
  )
}

/**
 * Keeps a signed-in user off the public marketing landing page. The header
 * hides Login/Register once a session exists, so an already-signed-in visitor
 * who opens "/" in a new tab (or just never logged out) would otherwise land
 * on a page with no way forward. Sent to their own dashboard instead.
 *
 * Deliberately NOT applied to /login or /register: reaching those while
 * signed in is how you switch accounts, and a stale session is cleared by the
 * 401 interceptor the moment any request fails.
 */
function RedirectIfSignedIn({ children }) {
  const session = getSession()
  if (session) return <Navigate to={getHomeRoute()} replace />
  return children
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
    seller: [['Dashboard', '/seller/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/seller/dashboard?tab=marketplace', Search], ['Listings', '/seller/dashboard?tab=listings', Store], ['Orders', '/seller/dashboard?tab=orders', ShoppingCart], ['Messages', '/seller/dashboard?tab=messages', MessageCircle], ['Wallet', '/seller/dashboard?tab=wallet', Wallet], ['Notifications', '/seller/dashboard?tab=notifications', Bell], ['Notices', '/seller/dashboard?tab=notices', ShieldAlert], ['Analytics', '/seller/dashboard?tab=analytics', BarChart3], ['Profile', '/seller/dashboard?tab=profile', ShieldCheck]],
    lgu_admin: [['Dashboard', '/lgu/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/lgu/dashboard?tab=marketplace', Search], ['Listing Management', '/lgu/dashboard?tab=listings', Store], ['Sellers', '/lgu/dashboard?tab=sellers', ShieldCheck], ['User Reports', '/lgu/dashboard?tab=user-reports', Flag], ['Notices to Explain', '/lgu/dashboard?tab=notices', ShieldAlert], ['Disputes', '/lgu/dashboard?tab=disputes', Scale], ['Orders', '/lgu/dashboard?tab=orders', ShoppingCart], ['Seller Earnings', '/lgu/dashboard?tab=earnings', Wallet], ['LGU Wallet', '/lgu/dashboard?tab=wallet', Wallet], ['Messages', '/lgu/dashboard?tab=messages', MessageCircle], ['Notifications', '/lgu/dashboard?tab=notifications', Bell], ['Analytics', '/lgu/dashboard?tab=reports', BarChart3], ['Activity Log', '/lgu/dashboard?tab=activity-log', History], ['Reviews & Ratings', '/lgu/dashboard?tab=reviews', Star], ['Users', '/lgu/dashboard?tab=users', UsersIcon], ['Profile', '/lgu/dashboard?tab=profile', CircleUserRound]],
    super_admin: [['Dashboard', '/admin/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/admin/dashboard?tab=marketplace', Search], ['Listing Management', '/admin/dashboard?tab=listings', Store], ['LGU Admins', '/admin/dashboard?tab=lgu-admins', ShieldCheck], ['Sellers', '/admin/dashboard?tab=sellers', Store], ['Users', '/admin/dashboard?tab=users', UsersIcon], ['User Reports', '/admin/dashboard?tab=user-reports', Flag], ['Notices to Explain', '/admin/dashboard?tab=notices', ShieldAlert], ['Disputes', '/admin/dashboard?tab=disputes', Scale], ['Seller Earnings', '/admin/dashboard?tab=earnings', Wallet], ['Reviews & Ratings', '/admin/dashboard?tab=reviews', Star], ['Orders', '/admin/dashboard?tab=transactions', ShoppingCart], ['Payout Management', '/admin/dashboard?tab=payouts', Wallet], ['Municipalities', '/admin/dashboard?tab=municipalities', MapPin], ['Announcements', '/admin/dashboard?tab=announcements', Megaphone], ['Messages', '/admin/dashboard?tab=messages', MessageCircle], ['Notifications', '/admin/dashboard?tab=notifications', Bell], ['Moderation Log', '/admin/dashboard?tab=moderation', ShieldAlert], ['Activity Log', '/admin/dashboard?tab=activity-log', History], ['Analytics', '/admin/dashboard?tab=reports', BarChart3], ['Profile', '/admin/dashboard?tab=profile', CircleUserRound]],
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
      <main className="app-main">
        <SiteAnnouncementBar />
        {children}
      </main>
      <FloatingAi />
    </div>
  )
}

function roleLabel(role) {
  return ({ buyer: 'Buyer', seller: 'Seller', lgu_admin: 'LGU Admin', super_admin: 'Super Admin' })[role]
}

/**
 * Photo for one Shop by Species tile, resolved in priority order:
 *
 *   1. /species/<slug>.jpg -- a curated photograph dropped into
 *      frontend/public/species/ (bangus, tilapia, tuna, catfish, sea-bass,
 *      carp). Nothing else has to change when those files appear: the tile
 *      picks them up on the next load. Supply photos you hold the rights to.
 *   2. A real uploaded photo from an approved listing of that species -- so
 *      the row shows the actual stock on the marketplace today.
 *   3. The species artwork in SPECIES_PLACEHOLDERS, so a species with neither
 *      a curated photo nor a listing still renders.
 *
 * Each source is tried in turn via onError, which is the only way to know
 * whether a static file exists without shipping a manifest of them. The index
 * never advances past the last entry, so a failing final source cannot loop.
 */
function SpeciesTileImage({ species, listings = [] }) {
  const [attempt, setAttempt] = useState(0)

  const sources = useMemo(() => {
    const slug = species.toLowerCase().replace(/\s+/g, '-')
    const fromListing = listings.find((listing) => (
      (listing.species || '').toLowerCase() === species.toLowerCase()
      && listing.media?.some((media) => media.type === 'photo' && media.url)
    ))

    return [
      `/species/${slug}.jpg`,
      fromListing ? resolveListingImage(fromListing) : null,
      resolveListingImage({ species }),
    ].filter(Boolean)
  }, [species, listings])

  const src = sources[Math.min(attempt, sources.length - 1)]
  // The fallback artwork is a line illustration that needs room around it; a
  // real photograph should fill the tile edge to edge.
  const isArtwork = src.endsWith('.svg')

  return (
    <span className="species-tile-media">
      <img
        src={src}
        alt={species}
        loading="lazy"
        className={isArtwork ? 'species-tile-art' : 'species-tile-photo'}
        onError={() => setAttempt((current) => Math.min(current + 1, sources.length - 1))}
      />
    </span>
  )
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
    queryFn: async () => (await api.get('/sellers')).data.map(mapSeller),
    retry: false,
    placeholderData: [],
  })
  // Coverage, not the seeded dropdown list: only municipalities with an active
  // LGU Admin come back, so a visitor can tell whether their own area is served
  // before they bother registering.
  const partnersQuery = useQuery({
    queryKey: ['partner-municipalities'],
    queryFn: async () => (await api.get('/partner-municipalities')).data,
    retry: false,
    placeholderData: [],
  })
  const partners = partnersQuery.data || []
  const featured = listingsQuery.data || []
  const featuredSellers = sellersQuery.data || []
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
      {/* Coverage map in list form. A visitor's first question is "is my town
          on here?", and until now the only way to find out was to start
          registering and open the municipality dropdown -- which lists every
          seeded municipality, partnered or not. */}
      <Section title="Partnered LGUs">
        <p className="helper-text">
          These municipalities have an LGU partner on AbaiMarket. Their LGU verifies local hatcheries, approves seller earnings, and handles reports for the area.
        </p>
        {partners.length ? (
          <div className="lgu-grid">
            {partners.map((partner) => (
              <div className="lgu-card" key={partner.id}>
                <span className="lgu-card-head">
                  <MapPin size={16} />
                  <strong>{partner.name}</strong>
                </span>
                <span className="muted">{partner.province}</span>
                <span className="lgu-card-count">
                  {partner.verified_sellers_count
                    ? `${partner.verified_sellers_count} verified ${partner.verified_sellers_count === 1 ? 'seller' : 'sellers'}`
                    : 'No verified sellers yet'}
                </span>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState message="No LGU partners yet. Municipalities appear here once their LGU joins AbaiMarket." />
        )}
      </Section>
      <Section title="How It Works"><div className="steps"><Step n="1" t="Register" d="Buyers and sellers create verified marketplace accounts." /><Step n="2" t="Order & Pay" d="Buyers place orders and pay through PayMongo Checkout." /><Step n="3" t="LGU Oversight" d="LGU admins verify sellers and approve local listings." /><Step n="4" t="Release" d="Super Admin releases held seller funds after completion." /></div></Section>
      {/* Browse-by-species row: the photo leads, the label sits under it, and
          each tile lands on Browse already filtered to that species. */}
      <Section title="Shop by Species">
        <div className="species-row">
          {SPECIES_OPTIONS.map((species) => (
            <Link className="species-tile" key={species} to={`/browse?species=${encodeURIComponent(species)}`}>
              <SpeciesTileImage species={species} listings={featured} />
              <strong>{species}</strong>
            </Link>
          ))}
        </div>
      </Section>
      <AboutPage compact />
      <footer>AbaiMarket - LGU, Sellers, and Fish Farmers working together for local aquaculture.</footer>
    </main>
  )
}

function BrowsePage() {
  const [searchParams] = useSearchParams()
  // Honour ?species= so the landing page's Shop by Species tiles arrive
  // pre-filtered instead of dropping the visitor into the full catalogue.
  const requestedSpecies = searchParams.get('species')
  const [filters, setFilters] = useState({
    q: '',
    species: SPECIES_OPTIONS.includes(requestedSpecies) ? requestedSpecies : 'All',
    municipality: 'All',
  })
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
        <label className="filter-label">Species<select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value })}><option>All</option>{SPECIES_OPTIONS.map((s) => <option key={s}>{s}</option>)}</select></label>
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
        <label className="filter-label">Species<select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value })}><option>All</option>{SPECIES_OPTIONS.map((s) => <option key={s}>{s}</option>)}</select></label>
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
        <span className="listing-price">{currency(item.price)}<small>/qty</small></span>
        {Number(item.quantity) <= 0 ? (
          <Badge tone="danger">Out of Stock</Badge>
        ) : (
          <span className="listing-stock">{formatStock(item)}</span>
        )}
      </div>
      {/* What a unit actually holds. Without this a "bulk" price is unreadable:
          the buyer cannot tell whether one bulk is 10 fish or 1,000. */}
      {item.unit_contents_label && (
        <p className="listing-minimum">{item.unit_contents_label}</p>
      )}
      {minimumOrder(item) > 1 && (
        <p className="listing-minimum">Minimum order: {minimumOrder(item).toLocaleString()} qty</p>
      )}
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
  // Unit of Measurement + Minimum Order. The seller's minimum is the floor for
  // a valid order and available stock is the ceiling; when stock has fallen
  // below the minimum the listing simply can't be ordered right now. The
  // backend enforces the same rule (FingerlingListing::quantityIssue).
  const minimum = minimumOrder(item)
  const available = Number(item.quantity) || 0
  // A listing has no unit of its own; the buyer decides here whether to count
  // in single fingerlings or in bulks. `qty` ALWAYS holds a plain quantity --
  // the bulk input just multiplies on the way in -- so everything downstream
  // (the total, Add to Cart, and the checkout call in the parent) is unchanged
  // and there is only ever one number being ordered.
  const perBulk = bulkSize(item) || 0
  const [orderMode, setOrderMode] = useState('quantity')
  const buyingInBulk = orderMode === 'bulk' && perBulk > 0
  const bulksEntered = perBulk > 0 ? Math.round((Number(qty) || 0) / perBulk) : 0
  const belowMinimumStock = !outOfStock && available < minimum
  const enteredQty = Number(qty) || 0
  const safeQty = Math.min(Math.max(enteredQty, minimum), available || minimum)
  const quantityError = outOfStock || belowMinimumStock ? null
    : enteredQty < minimum ? `Minimum order for this listing is ${minimum.toLocaleString()} quantity.`
      : enteredQty > available ? `Only ${available.toLocaleString()} quantity available.`
        : null
  const canOrder = !outOfStock && !belowMinimumStock && !quantityError
  // Start the buyer at the seller's minimum rather than at 1, so the form
  // opens on a valid order. Keyed to the listing so it seeds once and never
  // fights the buyer while they type a different amount.
  const seededForListing = useRef(null)
  useEffect(() => {
    if (!isBuyer || !setQty || seededForListing.current === item.id) return
    seededForListing.current = item.id
    setQty(String(minimum))
  }, [isBuyer, setQty, item.id, minimum])
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
      </div>
      {item.description ? (
        <p className="listing-description">{item.description}</p>
      ) : (
        <p className="helper-text">No description provided by the seller.</p>
      )}
      <div className="stats-inline">
        <Stat value={currency(item.price)} label="Per quantity" highlight />
        <Stat value={formatStock(item)} label="Available" />
        <Stat value={`${minimum.toLocaleString()} qty`} label="Minimum order" />
      </div>
      <div className="detail-meta">
        <span className="listing-seller-row"><strong>Hatchery/Farm:</strong> <Avatar src={item.sellerProfile?.profile_picture} alt={item.seller} className="listing-seller-avatar" /> {item.sellerProfile?.id ? <Link to={sellerProfilePath(item.sellerProfile.id)}>{item.seller}</Link> : item.seller}</span>
        {/* Sits with the hatchery it belongs to rather than in the stats row:
            it describes the SELLER, not this listing's price or stock. */}
        <span><strong>Seller rating:</strong> {renderStars(item.rating)} <span className="muted">{Number(item.rating || 0).toFixed(1)}/5</span></span>
        {item.sellerContactName && item.sellerContactName !== item.seller && <span><strong>Seller:</strong> {item.sellerContactName}</span>}
        {item.unit_description && <span><strong>What one bulk contains:</strong> {item.unit_description}</span>}
        <span><strong>Municipality:</strong> {item.municipality}</span>
      </div>
      <MediaGallery media={galleryMediaFor(item)} />
      {isBuyer && (
        <>
          {outOfStock ? (
            <p className="helper-text">This item is currently unavailable.</p>
          ) : belowMinimumStock ? (
            <p className="helper-text">
              Only {available.toLocaleString()} quantity left, which is below this seller&apos;s minimum order of {minimum.toLocaleString()}.
              This listing can&apos;t be ordered until they restock.
            </p>
          ) : (
            <>
              {/* The listing has no unit of its own -- the BUYER decides here
                  whether to think in single fingerlings or in bulks. Either
                  way the order is sent as a plain quantity. */}
              {perBulk > 0 && (
                <label>
                  Order by
                  <select
                    value={orderMode}
                    onChange={(e) => {
                      const mode = e.target.value
                      setOrderMode(mode)
                      const wanted = Math.max(minimum, Number(qty) || minimum)
                      // Switching to bulk snaps up to a whole bulk that still
                      // clears the seller's minimum.
                      setQty(String(mode === 'bulk'
                        ? Math.max(1, Math.ceil(wanted / perBulk)) * perBulk
                        : wanted))
                    }}
                  >
                    <option value="quantity">Quantity</option>
                    <option value="bulk">Bulk</option>
                  </select>
                </label>
              )}
              <label>
                {buyingInBulk ? 'Number of bulks' : 'Quantity'}
                <input
                  type="number"
                  min={buyingInBulk ? 1 : minimum}
                  max={buyingInBulk ? Math.floor(available / perBulk) : available}
                  value={buyingInBulk ? bulksEntered : qty}
                  onChange={(e) => setQty(buyingInBulk
                    ? String((Number(e.target.value) || 0) * perBulk)
                    : e.target.value)}
                />
                <span className="helper-text">
                  {buyingInBulk
                    ? `${bulksEntered.toLocaleString()} bulk${bulksEntered === 1 ? '' : 's'} = ${(bulksEntered * perBulk).toLocaleString()} quantity · ${currency(item.price)} each`
                    : `Minimum ${minimum.toLocaleString()} · ${available.toLocaleString()} available · ${currency(item.price)} each`}
                </span>
              </label>
              {/* Takes the whole remaining stock in one click. Always switches to
                  plain quantity: the stock need not be a whole number of bulks. */}
              <button
                type="button"
                className="ghost buy-all-button"
                disabled={!buyingInBulk && enteredQty === available}
                onClick={() => {
                  setOrderMode('quantity')
                  setQty(String(available))
                }}
              >
                Buy all stock ({available.toLocaleString()})
              </button>
            </>
          )}
          {quantityError && <p className="error">{quantityError}</p>}
          <div className="checkout-bar">
            <strong className="price">Total: {currency(canOrder ? safeQty * item.price : 0)}</strong>
            {addToCart && (
              <button className="ghost" type="button" disabled={!canOrder || addToCart.isPending} onClick={() => addToCart.mutate(safeQty)}>
                <ShoppingBag size={16} /> {addToCart.isPending ? 'Adding...' : 'Add to Cart'}
              </button>
            )}
            <button onClick={onPay} type="button" disabled={!canOrder}>{outOfStock ? 'Out of Stock' : 'Pay with PayMongo'}</button>
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

/**
 * Shared dialog, used where an edit should happen in place instead of
 * navigating away to a separate page. Dismisses on Escape or a backdrop
 * click; the panel swallows clicks so working inside the form never closes it.
 */
function Modal({ title, subtitle, onClose, children, footer }) {
  useEffect(() => {
    const handleKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', handleKey)
    document.body.classList.add('modal-open')
    return () => {
      window.removeEventListener('keydown', handleKey)
      document.body.classList.remove('modal-open')
    }
  }, [onClose])

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-panel" role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()}>
        <header className="modal-header">
          <div>
            <h3>{title}</h3>
            {subtitle && <p className="helper-text">{subtitle}</p>}
          </div>
          <button type="button" className="modal-close" onClick={onClose} aria-label="Close"><X size={20} /></button>
        </header>
        <div className="modal-body">{children}</div>
        {footer && <footer className="modal-footer">{footer}</footer>}
      </div>
    </div>
  )
}

/**
 * "Are these details correct?" step before a withdrawal request is sent, for
 * both the seller and the LGU wallet. Money sent to a mistyped account cannot
 * be pulled back, so the requester sees exactly what will be submitted first.
 * `fee` is the seller's platform payout fee; LGU payouts have none (null).
 */
function WithdrawalConfirmModal({ form, fee = null, pending = false, onConfirm, onClose }) {
  const amount = Number(form.amount) || 0
  const rows = [
    ['Payout method', withdrawalMethodLabel(form.method)],
    ['Account name', form.account_name.trim()],
    [form.method === 'bank_transfer' ? 'Bank account number' : 'Mobile number', normalizeAccountNumber(form.account_number)],
    ['Amount requested', currency(amount)],
  ]
  if (fee !== null) {
    rows.push(['Platform payout fee (6%)', currency(fee)])
    rows.push(['You will receive', currency(Math.round((amount - fee) * 100) / 100)])
  }
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

/** A blank listing form, shared by the create form and the edit popup. */
const EMPTY_LISTING_FORM = {
  species: '',
  quantity: '',
  price: '',
  description: '',
  minimum_order: '1',
  pieces_per_unit: '',
  unit_description: '',
}

/** Turn an existing listing into the form shape above. */
function listingToForm(listing) {
  return {
    species: listing.species || '',
    quantity: String(listing.quantity ?? ''),
    price: String(listing.price_per_piece ?? ''),
    description: listing.description || '',
    minimum_order: String(listing.minimum_order ?? 1),
    pieces_per_unit: listing.pieces_per_unit ? String(listing.pieces_per_unit) : '',
    unit_description: listing.unit_description || '',
  }
}

/** The request body both the create and the update call send. */
function listingPayload(form) {
  return {
    species: form.species,
    title: `${form.species} Fingerlings`,
    description: form.description,
    quantity: Number(form.quantity),
    price_per_piece: Number(form.price),
    minimum_order: Math.max(1, Number(form.minimum_order) || 1),
    pieces_per_unit: Number(form.pieces_per_unit) || null,
    unit_description: form.unit_description?.trim() || null,
  }
}

/**
 * The listing detail fields, shared by Create Listing and the edit popup so
 * the two can never drift apart. The Unit of Measurement dropdown drives the
 * wording of the price, stock, and minimum-order fields -- a seller pricing
 * per kilogram sees "Price per kg", not "Price per piece".
 */
function ListingDetailsFields({ form, setForm }) {
  const unit = unitMeta(form.unit_type)
  const set = (patch) => setForm({ ...form, ...patch })

  return (
    <div className="form grid-form">
      <label className="filter-label">
        Species
        <input value={form.species} onChange={(e) => set({ species: e.target.value })} placeholder="e.g. Bangus" />
      </label>
      <label className="filter-label">
        Price per quantity
        <input type="number" min="0.01" step="0.01" value={form.price} onChange={(e) => set({ price: e.target.value })} placeholder="Price for one fingerling" />
        <span className="helper-text">The price of a single fingerling. A buyer ordering by bulk pays this times the bulk size.</span>
      </label>
      <label className="filter-label">
        Stock available (quantity)
        <input type="number" min="0" value={form.quantity} onChange={(e) => set({ quantity: e.target.value })} placeholder="Total fingerlings you have" />
        <span className="helper-text">The total number of fingerlings you have, not a number of bulks.</span>
      </label>
      <label className="filter-label">
        Minimum order (quantity)
        <input type="number" min="1" value={form.minimum_order} onChange={(e) => set({ minimum_order: e.target.value })} placeholder="1" />
        <span className="helper-text">Buyers cannot order less than this. Leave at 1 for no minimum.</span>
      </label>
      <label className="filter-label">
        1 bulk = how many quantity?
        <input
          type="number"
          min="1"
          value={form.pieces_per_unit}
          onChange={(e) => set({ pieces_per_unit: e.target.value })}
          placeholder="e.g. 10"
        />
        <span className="helper-text">
          Required. Buyers can order by quantity or by bulk, and this is what one bulk means on your listing
          {Number(form.pieces_per_unit) > 0 ? ` — 1 bulk = ${Number(form.pieces_per_unit).toLocaleString()} quantity.` : '.'}
        </span>
      </label>
      <label className="filter-label">
        What one {unit.short} contains (optional)
        <input
          value={form.unit_description}
          onChange={(e) => set({ unit_description: e.target.value })}
          placeholder={form.unit_type === 'bulk' ? 'e.g. 1 bulk = 1 sack of 1,000 pcs' : form.unit_type === 'kilogram' ? 'e.g. roughly 80-100 pcs per kg' : 'e.g. average size 2-3 cm'}
        />
      </label>
      <label className="filter-label listing-description-field">
        Description
        <textarea value={form.description} onChange={(e) => set({ description: e.target.value })} placeholder="Describe the fingerlings: health, feeding, size consistency, etc." />
      </label>
    </div>
  )
}

/**
 * Edit an existing listing without leaving the Listings tab. Carries the exact
 * same capabilities the old inline edit view had -- the detail fields plus the
 * photo/video manager -- so nothing was lost in the move to a popup.
 */
function ListingEditModal({ listing, onClose }) {
  const [form, setForm] = useState(() => listingToForm(listing))

  const updateListing = useMutation({
    mutationFn: async () => (await api.patch(`/listings/${listing.id}`, listingPayload(form))).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
      onClose()
    },
  })

  return (
    <Modal
      title="Edit Listing"
      subtitle={listing.title}
      onClose={onClose}
      footer={
        <>
          <button type="button" onClick={() => updateListing.mutate()} disabled={updateListing.isPending}>
            {updateListing.isPending ? 'Saving...' : 'Update Listing'}
          </button>
          <button type="button" className="ghost" onClick={onClose} disabled={updateListing.isPending}>Cancel</button>
        </>
      }
    >
      {listing.approval_status !== 'approved' && (
        <div className="card-row modal-status-row">
          <Badge status={listing.approval_status} />
          <span className="muted">Edits are re-checked by your LGU before they appear in the marketplace.</span>
        </div>
      )}
      {listing.approval_status === 'rejected' && listing.rejection_reason && (
        <p className="error">Reason for rejection: {listing.rejection_reason}</p>
      )}
      <ListingDetailsFields form={form} setForm={setForm} />
      {updateListing.error && <p className="error">{updateListing.error.response?.data?.message || 'Could not save listing.'}</p>}
      <div className="modal-section">
        <h4>Listing Photos &amp; Videos</h4>
        <p className="helper-text">Upload up to 5 photos or videos (JPG, PNG, WEBP up to 25MB; MP4, MOV, WEBM up to 25MB). The first item is used as the primary image in the marketplace.</p>
        <ListingImageManager
          listingId={listing.id}
          media={listing.media || []}
          onChange={() => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })}
        />
      </div>
    </Modal>
  )
}

/**
 * Registration approval banner for sellers whose registration has not been
 * approved yet (see backend App\Support\SellerApproval). Until it is, listing
 * creation is refused server-side, so the dashboard says so up front rather
 * than letting them fill in a form that will 403.
 */
function SellerApprovalNotice({ seller }) {
  const status = seller?.approval_status
  if (!status || status === 'approved') return null

  const copy = {
    pending: {
      tone: 'warning',
      title: 'Registration pending approval',
      body: 'Your hatchery registration is waiting to be reviewed by your LGU Admin. You can start creating listings as soon as it is approved.',
    },
    rejected: {
      tone: 'danger',
      title: 'Registration rejected',
      body: seller.registration_rejection_reason
        ? `Reason: ${seller.registration_rejection_reason} Update your hatchery profile and contact your LGU to have it reviewed again.`
        : 'Update your hatchery profile and contact your LGU to have it reviewed again.',
    },
  }[status]

  if (!copy) return null

  return (
    <div className={`card approval-notice approval-notice-${copy.tone}`}>
      <div className="card-row">
        <strong>{copy.title}</strong>
        <Badge tone={copy.tone === 'danger' ? 'danger' : 'warning'}>{seller.approval_status_label || 'Pending'}</Badge>
      </div>
      <p className="helper-text">{copy.body}</p>
    </div>
  )
}

function Avatar({ src, alt, className = '' }) {
  return <img className={`avatar ${className}`} src={src || DEFAULT_AVATAR_IMAGE} alt={alt} />
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
      const safeQty = Math.min(Math.max(Number(qty) || 0, minimumOrder(item)), Number(item.quantity) || minimumOrder(item))
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
      const safeQty = Math.min(Math.max(Number(qty) || 0, minimumOrder(item)), Number(item.quantity) || minimumOrder(item))
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

/**
 * Read-only listing detail for logged-in sellers. Sellers browsing the
 * marketplace (or another hatchery's profile) used to land on the public
 * /listing/:id page, which renders inside PublicLayout and therefore looks
 * like a logged-out session. This keeps them inside the seller AppShell.
 * Purchasing stays buyer-only, so no checkout/cart actions are wired here.
 */
function SellerListingDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const sourceTab = searchParams.get('source') || 'marketplace'
  const { data: item, isLoading, isError } = useQuery({
    queryKey: ['seller-listing', id],
    queryFn: async () => mapListing((await api.get(`/listings/${id}`)).data),
    retry: false,
  })

  if (isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (isError || !item) return <main className="auth-page"><section className="result-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to={`/seller/dashboard?tab=${sourceTab}`}>Back to Marketplace</Link></section></main>

  return (
    <main className="detail-page">
      <img className="detail-art" src={resolveListingImage(item)} alt={item.title || item.species} />
      <div className="detail-stack">
        <ListingDetailPanel item={item} />
        <Link className="ghost" to={`/seller/dashboard?tab=${sourceTab}`}>{sourceTab === 'listings' ? 'Back to My Listings' : 'Back to Marketplace'}</Link>
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
  const [showPassword, setShowPassword] = useState(false)
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
          type={showPassword ? 'text' : 'password'}
          placeholder="Password"
          onKeyDown={blockSpaceKey}
          onChange={(e) => { e.target.value = stripSpaces(e.target.value); passwordField.onChange(e) }}
        />
        <PasswordVisibilityToggle shown={showPassword} onToggle={() => setShowPassword(!showPassword)} noun="password" />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <p className="helper-text"><Link to="/forgot-password">Forgot password?</Link></p>
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

// Step 1 of "forgot password": request the emailed link. Works for every role,
// including accounts created with Google (the reset gives them a password).
// The backend answers identically for unknown emails, so this page always
// shows the same confirmation.
function ForgotPasswordPage() {
  const { register, handleSubmit, formState: { errors } } = useForm({ defaultValues: { email: '' } })
  const [sentTo, setSentTo] = useState(null)
  const sendLink = useMutation({
    mutationFn: async (email) => {
      try {
        return (await api.post('/auth/forgot-password', { email })).data
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'Could not send the reset link. Please try again.'), { cause: err })
      }
    },
    onSuccess: (_data, email) => setSentTo(email),
  })

  if (sentTo) {
    return (
      <AuthCard title="Check Your Email" subtitle="Password reset requested.">
        <p className="helper-text">
          If <strong>{sentTo}</strong> has an AbaiMarket account, we sent it a link to reset your password. The link expires in 60 minutes -- check your spam folder too.
        </p>
        <div className="success-actions">
          <button type="button" className="ghost" onClick={() => sendLink.mutate(sentTo)} disabled={sendLink.isPending}>
            {sendLink.isPending ? 'Sending...' : 'Send Again'}
          </button>
          <Link className="button" to="/login">Back to Login</Link>
        </div>
        {sendLink.isError && <p className="error">{sendLink.error.message}</p>}
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Forgot Password" subtitle="Enter your account email and we'll send you a reset link.">
      <form onSubmit={handleSubmit((v) => sendLink.mutate((v.email || '').trim()))} className="form">
        <input {...register('email', { validate: (value) => validateEmail(value) || true })} placeholder="Email" />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <p className="helper-text">Signed up with Google? You can use this to set a password as well.</p>
        <button type="submit" disabled={sendLink.isPending}>{sendLink.isPending ? 'Sending...' : 'Send Reset Link'}</button>
        {sendLink.error && <p className="error">{sendLink.error.message}</p>}
      </form>
      <p className="helper-text"><Link to="/login">Back to Login</Link></p>
    </AuthCard>
  )
}

// Step 2: the page the emailed link opens (?token=...&email=...).
function ResetPasswordPage() {
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token')
  const email = searchParams.get('email')
  const { register, handleSubmit, getValues, control, formState: { errors } } = useForm({ defaultValues: { password: '', password_confirmation: '' } })
  // Live values drive the requirement checklist as the user types.
  const [password, confirmation] = useWatch({ control, name: ['password', 'password_confirmation'] })
  const [showPasswords, setShowPasswords] = useState(false)
  const passwordField = register('password', { validate: (value) => validatePassword(value) || true })
  const confirmField = register('password_confirmation', {
    validate: (value) => value === getValues('password') || 'The passwords do not match.',
  })
  const reset = useMutation({
    mutationFn: async (values) => {
      try {
        return (await api.post('/auth/reset-password', { token, email, ...values })).data
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'Could not reset your password. Please try again.'), { cause: err })
      }
    },
  })

  if (!token || !email) {
    return (
      <AuthCard title="Invalid Link" subtitle="This password reset link is incomplete.">
        <p className="helper-text">Open the link exactly as it appears in the email, or request a new one.</p>
        <div className="success-actions">
          <Link className="button" to="/forgot-password">Request a New Link</Link>
        </div>
      </AuthCard>
    )
  }

  if (reset.isSuccess) {
    return (
      <AuthCard title="Password Reset" subtitle="You're all set.">
        <p className="helper-text">{reset.data?.message || 'Your password has been reset.'}</p>
        <div className="success-actions">
          <Link className="button" to="/login">Go to Login</Link>
        </div>
      </AuthCard>
    )
  }

  const passwordInputProps = (field) => ({
    ...field,
    type: showPasswords ? 'text' : 'password',
    autoComplete: 'new-password',
    onKeyDown: blockSpaceKey,
    onChange: (e) => { e.target.value = stripSpaces(e.target.value); field.onChange(e) },
  })

  return (
    <AuthCard title="Reset Password" subtitle={`Choose a new password for ${email}.`}>
      <form onSubmit={handleSubmit((v) => reset.mutate(v))} className="form">
        <ProfileField label="New password">
          <input {...passwordInputProps(passwordField)} />
        </ProfileField>
        {errors.password && <p className="error">{errors.password.message}</p>}
        <ProfileField label="Confirm new password">
          <input {...passwordInputProps(confirmField)} />
        </ProfileField>
        {errors.password_confirmation && <p className="error">{errors.password_confirmation.message}</p>}
        <PasswordChecklist password={password} confirmation={confirmation} />
        <PasswordVisibilityToggle shown={showPasswords} onToggle={() => setShowPasswords(!showPasswords)} />
        <button type="submit" disabled={reset.isPending}>{reset.isPending ? 'Saving...' : 'Reset Password'}</button>
        {reset.error && (
          <p className="error">
            {reset.error.message} <Link to="/forgot-password">Request a new link</Link>
          </p>
        )}
      </form>
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
  const [searchParams] = useSearchParams()
  // Set when someone used the Google button on the login page with an email
  // we have no account for: the backend can't guess Buyer vs Seller, so it
  // sends them here to choose before the account is created.
  const googleRoleRequired = searchParams.get('google_role_required')
  const googleEmail = searchParams.get('email')
  const { register, handleSubmit, watch, getValues, setError, clearErrors, formState: { errors } } = useForm({ defaultValues: { role: 'buyer', municipality_id: '' } })
  const role = watch('role')
  const isSeller = role === 'seller'
  const passwordField = register('password', {
    validate: (value) => validatePassword(value) || true,
  })
  const [showPassword, setShowPassword] = useState(false)
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
    placeholderData: [],
  })
  // An unreachable API, a 404 and a genuinely empty table all used to render
  // the same thing here -- a select with no options -- which reads as "there
  // are no municipalities" and hides the real cause. Seller registration is
  // impossible without this list, so say which of the three it is.
  const municipalities = municipalitiesQuery.data || []
  const municipalityIssue = municipalitiesQuery.isError
    ? "Couldn't load the municipality list. The API may be unreachable -- check that the backend is running and that VITE_API_URL points at it."
    : (!municipalitiesQuery.isFetching && !municipalities.length
      ? 'No municipalities are set up yet. An administrator needs to add them before sellers can register.'
      : null)
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

  const beginGoogleRegistration = () => {
    const values = getValues()
    const params = new URLSearchParams({ registration: '1', role: values.role || 'buyer' })

    if (values.role === 'seller') {
      if (!values.municipality_id) {
        setError('municipality_id', { type: 'required', message: "Please select your hatchery's municipality." })
        return
      }
      params.set('municipality_id', values.municipality_id)
    } else {
      clearErrors('municipality_id')
    }

    window.location.assign(`${API_URL}/auth/google/redirect?${params.toString()}`)
  }

  if (registeredEmail) {
    return (
      <AuthCard title="Check Your Email" subtitle="You're almost there.">
        <VerificationNotice email={registeredEmail} lead="Your AbaiMarket account has been created." />
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Register" subtitle="Registration is available only for buyers and sellers.">
      {googleRoleRequired && (
        <p className="helper-text">
          <strong>Almost there — one more step.</strong>{' '}
          {googleEmail ? `There's no AbaiMarket account for ${googleEmail} yet. ` : "You don't have an AbaiMarket account yet. "}
          Pick <strong>Buyer / Fish Farmer</strong> or <strong>Seller / Hatchery</strong> below, then press
          {' '}<strong>Continue with Google</strong> again to finish creating your account.
        </p>
      )}
      <form onSubmit={handleSubmit((v) => registerUser.mutate(v))} className="form">
        <input {...register('name')} placeholder="Full name / Hatchery name" />
        <input {...register('email', { validate: (value) => validateEmail(value) || true })} placeholder="Email" />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <input
          {...passwordField}
          type={showPassword ? 'text' : 'password'}
          placeholder="Password"
          onKeyDown={blockSpaceKey}
          onChange={(e) => { e.target.value = stripSpaces(e.target.value); passwordField.onChange(e) }}
        />
        <p className="helper-text">{PASSWORD_HELP}</p>
        {errors.password && <p className="error">{errors.password.message}</p>}
        <PasswordVisibilityToggle shown={showPassword} onToggle={() => setShowPassword(!showPassword)} noun="password" />
        <select {...register('role')}><option value="buyer">Buyer / Fish Farmer</option><option value="seller">Seller / Hatchery</option></select>
        {isSeller && (
          <>
            <select {...register('municipality_id', { required: isSeller })} defaultValue="" disabled={!municipalities.length}>
              <option value="" disabled>
                {municipalitiesQuery.isFetching && !municipalities.length ? 'Loading municipalities...' : 'Select municipality'}
              </option>
              {municipalities.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
            {municipalityIssue && <p className="error">{municipalityIssue}</p>}
            {errors.municipality_id && <p className="error">Please select your hatchery's municipality.</p>}
          </>
        )}
        <button type="submit" disabled={registerUser.isPending}>{registerUser.isPending ? 'Creating account...' : 'Create Account'}</button>
        {registerUser.error && <p className="error">{registerUser.error.message}</p>}
      </form>
      <div className="auth-divider"><span>or</span></div>
      <button type="button" className="ghost full google-button" onClick={beginGoogleRegistration}>
        <GoogleIcon /> Continue with Google as {isSeller ? 'Seller / Hatchery' : 'Buyer / Fish Farmer'}
      </button>
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
            {listing?.sellerProfile?.hatchery_name || 'Unknown seller'}
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

  // Abandoning a PayMongo checkout no longer fails the order (see
  // OrderController::markPaymentCancelled), so the buyer needs a way back in.
  // /checkout mints a fresh PayMongo session for any order still 'placed'.
  const resumePayment = useMutation({
    mutationFn: async (orderId) => (await api.post(`/orders/${orderId}/checkout`)).data,
    onSuccess: (data) => window.location.assign(data.checkout_url),
    onError: () => queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] }),
  })
  // The buyer confirms the fingerlings arrived. This is what releases the
  // payment into the seller's LGU earnings queue, so it is the buyer's call
  // alone -- the seller has no equivalent action.
  const confirmReceived = useMutation({
    mutationFn: async (orderId) => (await api.patch(`/orders/${orderId}/confirm-received`)).data,
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] }),
  })
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
      {tab === 'overview' && (
        <>
          <StatsRow items={[
            ['Active Orders', data?.active_orders ?? 0, false, '/buyer/dashboard?tab=orders'],
            ['Completed Orders', data?.completed_orders ?? 0, false, '/buyer/dashboard?tab=orders'],
            ['Unread Messages', data?.unread_messages ?? 0, false, '/buyer/dashboard?tab=messages'],
          ]}
          />
          {/* Mirrors the Seller Dashboard's Recent Orders: the five most recent,
              expandable, with the full list a click away. The Orders tab below
              is still where every order lives. */}
          <Section title="Recent Orders" actions={<Link className="ghost" to="/buyer/dashboard?tab=orders">View All Orders</Link>}>
            <OrderTable
              rows={(orders || []).slice(0, 5)}
              onReview={handleReview}
              onConfirmReceived={(orderId) => confirmReceived.mutate(orderId)}
              confirmPendingOrderId={confirmReceived.isPending ? confirmReceived.variables : null}
              detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
              paymentView="buyer"
              showOrderDate
            />
          </Section>
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
            onConfirmReceived={(orderId) => confirmReceived.mutate(orderId)}
            confirmPendingOrderId={confirmReceived.isPending ? confirmReceived.variables : null}
            onPay={(orderId) => resumePayment.mutate(orderId)}
            payPendingOrderId={resumePayment.isPending ? resumePayment.variables : null}
            detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
            initialExpandedOrderNumber={searchParams.get('order')}
            paymentView="buyer"
            showOrderDate
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

/* ---------------------------------------------------------------------------
   Profile page building blocks, shared by all four roles' profile tabs:
   a header card (avatar you click to change, optional cover banner), titled
   section cards, labelled fields, and a sticky save bar that only appears
   when something actually changed.
   ------------------------------------------------------------------------- */

function formatMonthYear(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
}

function ProfileHeader({
  name, email, badges, meta = [], actions,
  picture, pictureUploading, onPictureUpload, onPictureRemove, pictureError,
  cover, coverUploading, onCoverUpload, onCoverRemove, coverError,
}) {
  const pictureInput = useRef(null)
  const coverInput = useRef(null)
  const [picturePreview, setPicturePreview] = useState(null)
  const [coverPreview, setCoverPreview] = useState(null)
  const hasCover = Boolean(onCoverUpload)

  // Shows the chosen file immediately while it uploads.
  const pickFile = (setPreview, upload) => (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setPreview(URL.createObjectURL(file))
    upload(file)
  }

  return (
    <section className={`card profile-hero${hasCover ? ' profile-hero-with-cover' : ''}`}>
      {hasCover && (
        <div className="profile-hero-cover">
          <img src={(coverUploading && coverPreview) || cover || DEFAULT_COVER_IMAGE} alt="Farm cover photo" />
          <div className="profile-hero-cover-actions">
            <button type="button" className="profile-pill-button" onClick={() => coverInput.current?.click()} disabled={coverUploading}>
              <Camera size={15} aria-hidden="true" />
              {coverUploading ? 'Uploading...' : cover ? 'Change cover' : 'Add cover photo'}
            </button>
            {cover && onCoverRemove && (
              <button type="button" className="profile-pill-button" onClick={onCoverRemove} disabled={coverUploading}>Remove</button>
            )}
          </div>
          <input ref={coverInput} type="file" accept={IMAGE_UPLOAD_ACCEPT} hidden onChange={pickFile(setCoverPreview, onCoverUpload)} />
        </div>
      )}
      <div className="profile-hero-body">
        <div className="profile-hero-avatar">
          <img src={(pictureUploading && picturePreview) || picture || DEFAULT_AVATAR_IMAGE} alt="Your profile picture" />
          {onPictureUpload && (
            <>
              <button type="button" className="profile-avatar-button" onClick={() => pictureInput.current?.click()} disabled={pictureUploading} aria-label="Change profile picture">
                <Camera size={16} aria-hidden="true" />
              </button>
              <input ref={pictureInput} type="file" accept={IMAGE_UPLOAD_ACCEPT} hidden onChange={pickFile(setPicturePreview, onPictureUpload)} />
            </>
          )}
        </div>
        <div className="profile-hero-info">
          <div className="profile-hero-name">
            <h2>{name}</h2>
            {badges}
          </div>
          <p className="profile-hero-meta">
            {email && <span><Mail size={14} aria-hidden="true" />{email}</span>}
            {meta.filter(Boolean).map(([Icon, text]) => <span key={text}><Icon size={14} aria-hidden="true" />{text}</span>)}
          </p>
          {onPictureUpload && (
            <div className="profile-hero-photo-links">
              <button type="button" className="profile-link-button" onClick={() => pictureInput.current?.click()} disabled={pictureUploading}>
                {pictureUploading ? 'Uploading...' : picture ? 'Change photo' : 'Add a profile photo'}
              </button>
              {picture && onPictureRemove && (
                <button type="button" className="profile-link-button profile-link-muted" onClick={onPictureRemove} disabled={pictureUploading}>Remove photo</button>
              )}
            </div>
          )}
          {(pictureError || coverError) && <p className="error">{pictureError || coverError}</p>}
        </div>
        {actions && <div className="profile-hero-actions">{actions}</div>}
      </div>
    </section>
  )
}

function ProfileCard({ icon: Icon, title, description, children }) {
  return (
    <section className="card profile-card">
      <header className="profile-card-head">
        {Icon && <span className="profile-card-icon"><Icon size={18} aria-hidden="true" /></span>}
        <div>
          <h3>{title}</h3>
          {description && <p className="helper-text">{description}</p>}
        </div>
      </header>
      {children}
    </section>
  )
}

function ProfileField({ label, hint, wide = false, children }) {
  return (
    <label className={`profile-field${wide ? ' profile-field-wide' : ''}`}>
      <span className="profile-field-label">{label}</span>
      {children}
      {hint && <span className="profile-field-hint">{hint}</span>}
    </label>
  )
}

function ProfileSaveBar({ dirty, saving, success, error, onSave, onDiscard }) {
  if (!dirty && !saving && !error) {
    return success
      ? <div className="profile-save-bar profile-save-bar-saved" role="status"><Check size={16} aria-hidden="true" />Your profile is saved.</div>
      : null
  }
  return (
    <div className="profile-save-bar" role="region" aria-label="Unsaved changes">
      <p>{error ? <span className="profile-save-error">{error}</span> : saving ? 'Saving your changes...' : 'You have unsaved changes.'}</p>
      <div className="profile-save-bar-actions">
        <button type="button" className="ghost" onClick={onDiscard} disabled={saving}>Discard</button>
        <button type="button" onClick={onSave} disabled={saving}>{saving ? 'Saving...' : 'Save changes'}</button>
      </div>
    </div>
  )
}

function BuyerSettingsForm({ user, buyerProfile, onSave, saving, success, error }) {
  const initialValues = {
    name: user.name || '',
    email: user.email || '',
    phone: user.phone || '',
    address: buyerProfile?.address || '',
    bio: buyerProfile?.bio || '',
  }
  // `saved` is the last known server state; the save bar compares against it.
  const [saved, setSaved] = useState(initialValues)
  const [form, setForm] = useState(initialValues)
  const dirty = JSON.stringify(form) !== JSON.stringify(saved)
  const setField = (key) => (e) => setForm({ ...form, [key]: e.target.value })

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
    <div className="profile-page">
      <ProfileHeader
        name={saved.name || 'Your profile'}
        email={saved.email}
        badges={<RoleBadge role="buyer" />}
        meta={[
          user.municipality?.name && [MapPin, user.municipality.name],
          user.created_at && [CalendarDays, `Member since ${formatMonthYear(user.created_at)}`],
        ]}
        picture={user.profile_picture}
        pictureUploading={uploadPicture.isPending || removePicture.isPending}
        onPictureUpload={(file) => uploadPicture.mutate(file)}
        onPictureRemove={() => removePicture.mutate()}
        pictureError={uploadPicture.error?.response?.data?.message || removePicture.error?.response?.data?.message}
      />

      <ProfileCard icon={UserRound} title="Personal information" description="Your name and contact details, shared with sellers you order from.">
        <div className="profile-fields">
          <ProfileField label="Full name">
            <input value={form.name} onChange={setField('name')} autoComplete="name" />
          </ProfileField>
          <ProfileField label="Email address" hint="Used to log in and to receive receipts and updates.">
            <input type="email" value={form.email} onChange={setField('email')} autoComplete="email" />
          </ProfileField>
          <ProfileField label="Phone number" hint="Optional. Helps sellers coordinate delivery.">
            <input type="tel" value={form.phone} onChange={setField('phone')} autoComplete="tel" placeholder="e.g. 0917 123 4567" />
          </ProfileField>
          <ProfileField label="Municipality" hint="Set when you registered.">
            <input value={user.municipality?.name || 'Not set'} disabled />
          </ProfileField>
        </div>
        <PasswordResetNote />
      </ProfileCard>

      <ProfileCard icon={MapPin} title="Farm & delivery" description="Where your fingerlings go, and a little about your farm.">
        <div className="profile-fields">
          <ProfileField label="Address" wide>
            <input value={form.address} onChange={setField('address')} autoComplete="street-address" placeholder="Barangay, municipality, province" />
          </ProfileField>
          <ProfileField label="About you" hint="Optional. What you farm, your pond size, or anything sellers should know." wide>
            <textarea value={form.bio} onChange={setField('bio')} rows={4} />
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
 * Buyers, Sellers and LGU Admins change their password through the public
 * Forgot password flow (emailed link -> /reset-password), not from their
 * profile; only the Super Admin keeps an in-profile Change Password form.
 */
function PasswordResetNote() {
  return (
    <p className="helper-text profile-password-note">
      <KeyRound size={14} aria-hidden="true" />
      Need a new password? Log out and choose <strong>Forgot password?</strong> on the login page, and we&apos;ll email you a reset link.
    </p>
  )
}

/** Live password requirement checklist, shared by Reset Password and the Super Admin form. */
function PasswordChecklist({ password = '', confirmation = '' }) {
  return (
    <ul className="password-checklist" aria-label="Password requirements">
      {PASSWORD_RULES.map(([label, test]) => (
        <li key={label} className={test(password || '') ? 'met' : ''}><Check size={14} aria-hidden="true" />{label}</li>
      ))}
      <li className={confirmation && password === confirmation ? 'met' : ''}><Check size={14} aria-hidden="true" />Passwords match</li>
    </ul>
  )
}

// `noun` exists only so a form with one password field can say "Show
// password" rather than the plural the multi-field forms need.
function PasswordVisibilityToggle({ shown, onToggle, noun = 'passwords' }) {
  return (
    <button type="button" className="profile-link-button password-visibility-toggle" onClick={onToggle}>
      {shown ? <EyeOff size={15} aria-hidden="true" /> : <Eye size={15} aria-hidden="true" />}
      {shown ? `Hide ${noun}` : `Show ${noun}`}
    </button>
  )
}

const PASSWORD_RULES = [
  ['8 to 64 characters', (v) => v.length >= 8 && v.length <= 64],
  ['An uppercase letter', (v) => /[A-Z]/.test(v)],
  ['A lowercase letter', (v) => /[a-z]/.test(v)],
  ['A number', (v) => /[0-9]/.test(v)],
  ['A special character', (v) => /[^A-Za-z0-9]/.test(v)],
]

function ChangePasswordForm() {
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [localError, setLocalError] = useState('')
  const [showPasswords, setShowPasswords] = useState(false)
  const inputType = showPasswords ? 'text' : 'password'

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
    <div className="profile-password">
      <div className="profile-fields">
        <ProfileField label="Current password" hint="Forgot it? Log out and use Forgot password on the login page." wide>
          <input type={inputType} value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} autoComplete="current-password" />
        </ProfileField>
        <ProfileField label="New password">
          <input type={inputType} value={newPassword} onChange={(e) => setNewPassword(stripSpaces(e.target.value))} onKeyDown={blockSpaceKey} autoComplete="new-password" />
        </ProfileField>
        <ProfileField label="Confirm new password">
          <input type={inputType} value={confirmPassword} onChange={(e) => setConfirmPassword(stripSpaces(e.target.value))} onKeyDown={blockSpaceKey} autoComplete="new-password" />
        </ProfileField>
      </div>
      <PasswordChecklist password={newPassword} confirmation={confirmPassword} />
      <div className="profile-actions">
        <PasswordVisibilityToggle shown={showPasswords} onToggle={() => setShowPasswords(!showPasswords)} />
        <button type="button" onClick={submit} disabled={changePassword.isPending || !currentPassword || !newPassword}>
          {changePassword.isPending ? 'Updating...' : 'Update password'}
        </button>
      </div>
      {localError && <p className="error">{localError}</p>}
      {changePassword.isSuccess && <p className="profile-saved" role="status"><Check size={15} aria-hidden="true" />Password updated.</p>}
      {changePassword.error && <p className="error">{changePassword.error.response?.data?.message || 'Could not update password.'}</p>}
    </div>
  )
}

/**
 * Profile tab for LGU Admins and the Super Admin. They have no public profile
 * and no endpoint to edit their own name or email, so this is the profile
 * header (photo via the per-role /profile/picture endpoints; endpointBase is
 * '/lgu' or '/super-admin'), a read-only account summary, and Change Password.
 */
function AdminProfilePanel({ endpointBase }) {
  const session = getSession()
  // The stored session only carries municipality_id, so resolve the name from
  // the public municipality list (the same cached query registration uses).
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    enabled: session?.role === 'lgu_admin',
    retry: false,
    placeholderData: [],
  })
  const municipalityName = (municipalitiesQuery.data || []).find((m) => m.id === session?.municipality_id)?.name
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

  const isLgu = session?.role === 'lgu_admin'
  const municipality = municipalityName
    || (typeof session?.municipality === 'string' ? session.municipality : session?.municipality?.name)

  return (
    <div className="profile-page">
      <ProfileHeader
        name={session?.name || 'Your profile'}
        email={session?.email}
        badges={<RoleBadge role={session?.role} />}
        meta={[
          isLgu && municipality && [MapPin, municipality],
          !isLgu && [ShieldCheck, 'Platform-wide access'],
        ]}
        picture={picture}
        pictureUploading={uploadPicture.isPending || removePicture.isPending}
        onPictureUpload={(file) => uploadPicture.mutate(file)}
        onPictureRemove={() => removePicture.mutate()}
        pictureError={uploadPicture.error?.response?.data?.message || removePicture.error?.response?.data?.message}
      />

      <ProfileCard
        icon={UserRound}
        title="Account details"
        description={isLgu
          ? 'Your name and email are managed by the Super Admin. Ask them if something needs to change.'
          : 'This administrator account is managed at the platform level.'}
      >
        <dl className="profile-details">
          <div><dt>Full name</dt><dd>{session?.name || '-'}</dd></div>
          <div><dt>Email address</dt><dd>{session?.email || '-'}</dd></div>
          <div><dt>Role</dt><dd>{roleLabel(session?.role) || '-'}</dd></div>
          <div><dt>{isLgu ? 'Municipality' : 'Scope'}</dt><dd>{isLgu ? (municipality || '-') : 'All municipalities'}</dd></div>
        </dl>
        {isLgu && <PasswordResetNote />}
      </ProfileCard>

      {session?.role === 'super_admin' && (
        <ProfileCard icon={KeyRound} title="Password & security" description="Change the password you use to log in.">
          <ChangePasswordForm />
        </ProfileCard>
      )}
    </div>
  )
}

function SellerDashboard() {
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [form, setForm] = useState(EMPTY_LISTING_FORM)
  const [editingListingId, setEditingListingId] = useState(null)
  const [stagedImages, setStagedImages] = useState([])
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const [withdrawForm, setWithdrawForm] = useState({ method: 'gcash', account_name: '', account_number: '', amount: '' })
  const dashboard = useQuery({
    queryKey: ['seller-dashboard'],
    queryFn: async () => (await api.get('/seller/dashboard')).data,
    retry: false,
    placeholderData: {
      seller: { id: 1, hatchery_name: "Juan's Hatchery", approval_status: 'approved' },
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
  const [confirmingWithdrawal, setConfirmingWithdrawal] = useState(false)
  const requestWithdrawal = useMutation({
    mutationFn: async () => (await api.post('/seller/withdrawals', {
      method: withdrawForm.method,
      account_name: withdrawForm.account_name,
      account_number: normalizeAccountNumber(withdrawForm.account_number),
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
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
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
      {tab === 'overview' && (
        <>
          <SellerApprovalNotice seller={dashboard.data?.seller} />
          {(dashboard.data?.open_notices || []).length > 0 && (
            <div className="card approval-notice approval-notice-danger">
              <div className="card-row">
                <strong>You have a Notice to Explain</strong>
                <Badge tone="danger">Action needed</Badge>
              </div>
              <p className="helper-text">
                Your average buyer rating has fallen to 3 stars or below and your LGU has asked you to explain. Your account has not been
                suspended. <Link to="/seller/dashboard?tab=notices">Open Notices</Link> to respond.
              </p>
            </div>
          )}
          <StatsRow items={[
            ['Active Listings', dashboard.data?.active_listings ?? 0, false, '/seller/dashboard?tab=listings'],
            ['Pending Orders', dashboard.data?.pending_orders ?? 0, false, '/seller/dashboard?tab=orders'],
            ['Total Sales', currency(dashboard.data?.total_sales ?? 0), false, '/seller/dashboard?tab=analytics'],
            ['Unread Messages', dashboard.data?.unread_messages ?? 0, false, '/seller/dashboard?tab=messages'],
          ]}
          />
          {/* The seller's counterpart to the Buyer Dashboard's Recent Orders:
              the same table, told from the other side of the transaction. */}
          <Section title="Recent Orders" actions={<Link className="ghost" to="/seller/dashboard?tab=orders">View All Orders</Link>}>
            <OrderTable
              rows={(dashboard.data?.orders || []).slice(0, 5)}
              counterparty="buyer"
              detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`}
              showOrderDate
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
          <SellerApprovalNotice seller={dashboard.data?.seller} />
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
            <SellerOrderTable
              rows={dashboard.data?.orders || []}
              onUpdateStatus={(orderId, status, cancellationReason) => updateOrderStatus.mutateAsync({ orderId, status, cancellationReason })}
            />
          </Section>
        </>
      )}
      {tab === 'notices' && <SellerNoticesSection />}
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
              <input value={withdrawForm.account_number} onChange={(e) => setWithdrawForm({ ...withdrawForm, account_number: e.target.value })} placeholder={accountNumberPlaceholder(withdrawForm.method)} inputMode="numeric" />
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
            {confirmingWithdrawal && (
              <WithdrawalConfirmModal form={withdrawForm} fee={withdrawFeePreview} onConfirm={confirmWithdrawal} onClose={() => setConfirmingWithdrawal(false)} />
            )}
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
                  <Fragment key={request.id}>
                  <div className="table-row">
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
                  {request.status === 'rejected' && (
                    <div className="table-row-appeal">
                      <div className="table-row-appeal-head">
                        <p className="error">This withdrawal was rejected. If you think it should be reconsidered, explain your side.</p>
                        <DisputeAction
                          endpoint={`/withdrawals/${request.id}/dispute`}
                          invalidateKeys={['seller-wallet']}
                          label="Dispute This Rejection"
                        />
                      </div>
                    </div>
                  )}
                  </Fragment>
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

/* 'placed' is the UNPAID state -- an order only becomes 'paid' once money is
   in escrow (OrderController::markOrderPaid). Confirming from here used to be
   offered and would strand the order: it leaves 'placed', so the buyer can no
   longer check out and orders:expire-unpaid no longer sees it, and the order
   runs to 'completed' with nothing captured. Cancelling is the only move a
   seller has until the buyer pays; the API enforces the same rule. */
const ORDER_STATUS_TRANSITIONS = {
  placed: [['cancelled', 'Cancel Order']],
  paid: [['confirmed', 'Confirm Order'], ['cancelled', 'Cancel Order']],
  confirmed: [['in_transit', 'Mark Out for Delivery'], ['cancelled', 'Cancel Order']],
  /* No 'Mark Completed' here on purpose. Completing an order is what releases
     its payment into the LGU earnings queue, so a seller who could mark their
     own delivery complete could start their own payout without the buyer ever
     confirming the fingerlings arrived. Only the buyer confirms receipt (see
     OrderController::confirmReceived), with an LGU/Super Admin backstop for a
     buyer who goes silent. The API rejects 'completed' from this endpoint
     outright, so offering it here would only produce a validation error. */
  /* Nothing here either. Once the order is out for delivery the fingerlings
     have left the farm, so cancelling would hand the seller back stock that is
     no longer in their pond and -- on a paid order -- refund a buyer who is
     about to receive the fish. From here the order ends with the buyer
     confirming receipt (or the LGU/Super Admin backstop). The API refuses a
     cancellation from in_transit for the same reason. */
  in_transit: [],
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

// One row of the Super Admin refund queue (see App\Support\OrderCancellation).
function RefundRow({ refund, onMarkRefunded }) {
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
        <p>{refund.buyer?.name || 'Buyer'} · {refund.hatchery_name || 'Seller'} · {currency(refund.amount)}</p>
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

function SellerOrderTable({ rows, onUpdateStatus }) {
  if (!rows?.length) return <EmptyState message="No orders yet." />
  return <div className="item-list">{rows.map((order) => <SellerOrderRow key={order.id} order={order} onUpdateStatus={onUpdateStatus} />)}</div>
}

function SellerOrderRow({ order, onUpdateStatus }) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [showDetails, setShowDetails] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [cancelReason, setCancelReason] = useState('')
  const transitions = ORDER_STATUS_TRANSITIONS[order.status] || []

  const applyStatus = async (status, cancellationReason = null) => {
    // Cancelling needs a reason, which a confirm() dialog cannot collect, so
    // it opens the inline form below instead of a browser prompt. The API
    // requires the reason too, so this is not merely a UI courtesy.
    if (status === 'cancelled' && !cancellationReason) {
      setCancelling(true)
      return
    }
    setSaving(true)
    setError('')
    try {
      await onUpdateStatus(order.id, status, cancellationReason)
      setCancelling(false)
      setCancelReason('')
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not update order.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className={`card action${showDetails || cancelling ? ' action-stacked' : ''}`}>
      <div>
        <strong>{order.order_number}</strong>
        <p>
          {order.listing?.title || order.listing?.species || 'Listing'} ·{' '}
          {order.buyer?.id ? <Link to={`/seller/buyers/${order.buyer.id}`}>{order.buyer.name}</Link> : (order.buyer?.name || 'Buyer')} ·{' '}
          {formatQuantity(order.quantity, order.listing)} · {currency(order.total_amount)}
        </p>
        <p className="muted order-date-line"><CalendarDays size={14} /> Ordered {formatOrderDate(order.created_at)}</p>
        <span className="seller-order-badges">
          <Badge status={order.status}>{statusChartLabel(order.status)}</Badge>
          {/* The escrow lifecycle -- paid_held until this seller's LGU approves
              the earnings, then released. This is the seller's money, so this
              is where the raw status belongs; the buyer sees a plain
              Paid/Unpaid instead (see BUYER_PAYMENT_VIEW). */}
          {order.payment?.status && (
            <Badge status={order.payment.status}>{statusChartLabel(order.payment.status)}</Badge>
          )}
        </span>
        {/* A rejected review leaves the payment in 'paid_held' too, so without
            excluding it the card claimed to be waiting on an approval that had
            already been refused -- directly contradicting the notice below. */}
        {order.payment?.status === 'paid_held' && order.status === 'completed' && order.lgu_review_status !== 'rejected' && (
          <p className="muted">The buyer confirmed delivery. Waiting for your LGU to approve the earnings before the payment is released.</p>
        )}
        {order.status === 'placed' && (
          <p className="muted">Waiting for the buyer's payment. You can confirm this order once the payment is held in escrow.</p>
        )}
        {order.lgu_review_status === 'rejected' && (
          <div className="dispute-notice">
            <p className="error">
              Your LGU declined to approve the earnings for this order.
              {order.lgu_review_reason ? ` Reason: ${order.lgu_review_reason}` : ''}
            </p>
            {/* An open appeal replaces the button: re-offering it would invite a
                duplicate the API refuses, and say nothing about the one already
                filed. A rejected appeal allows another, since the seller may
                have new information -- that is the API's rule too. */}
            {order.latestDispute?.status === 'open' ? (
              <span className="dispute-submitted">
                <Clock size={15} /> Dispute submitted -- waiting for your LGU's response.
              </span>
            ) : (
              <>
                {order.latestDispute?.status === 'rejected' && (
                  <p className="helper-text dispute-outcome">
                    Your previous dispute was reviewed and declined.
                    {order.latestDispute.resolution_note ? ` Note: ${order.latestDispute.resolution_note}` : ''}
                  </p>
                )}
                <DisputeAction
                  endpoint={`/orders/${order.id}/dispute-earnings`}
                  invalidateKeys={['seller-dashboard']}
                  label={order.latestDispute?.status === 'rejected' ? 'Dispute Again' : 'Explain My Side'}
                />
              </>
            )}
          </div>
        )}
        {error && <p className="error">{error}</p>}
      </div>
      <div className="row-actions">
        {transitions.map(([status, label]) => (
          <button key={status} type="button" className={status === 'cancelled' ? 'ghost danger' : ''} disabled={saving} onClick={() => applyStatus(status)}>
            {label}
          </button>
        ))}
        {/* GET /orders/{order_number} is scoped to the caller in
            OrderController::show, so a seller reads their own listings' orders
            through the very same endpoint and panel the buyer uses. */}
        <button type="button" className="ghost" onClick={() => setShowDetails((open) => !open)}>
          {showDetails ? 'Hide Details' : 'View Details'}
        </button>
      </div>
      {cancelling && (
        <div className="card cancel-form">
          <p className="helper-text">
            {order.payment?.status === 'paid_held'
              ? "Cancelling a paid order returns the stock to your listing and refunds the buyer. Tell them why -- they will see this reason."
              : 'Cancelling returns the stock to your listing. Tell the buyer why -- they will see this reason.'}
          </p>
          <textarea
            value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)}
            placeholder="e.g. Our pond had a fish kill overnight and we cannot fulfil this order."
          />
          <div className="row-actions">
            <button
              type="button"
              className="danger"
              disabled={!cancelReason.trim() || saving}
              onClick={() => applyStatus('cancelled', cancelReason.trim())}
            >
              {saving ? 'Cancelling...' : 'Confirm Cancellation'}
            </button>
            <button type="button" className="ghost" onClick={() => { setCancelling(false); setCancelReason('') }}>
              Keep Order
            </button>
          </div>
        </div>
      )}
      {showDetails && (
        <OrderTableDetailRow orderNumber={order.order_number} detailsEndpoint={(orderNumber) => `/orders/${orderNumber}`} />
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
          <strong>{payment.order?.sellerProfile?.hatchery_name || payment.order?.sellerProfile?.user?.name || 'Unknown seller'}</strong>
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
function LguRejectedEarningsRow({ payment, base = '/lgu', dashboardPath = '/lgu/dashboard' }) {
  const [expanded, setExpanded] = useState(false)
  const order = payment.order
  const orderNumber = order?.order_number

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
          <strong>{order?.sellerProfile?.hatchery_name || order?.sellerProfile?.user?.name || 'Unknown seller'}</strong>
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
function SellerEarningsPanel({ scope = 'lgu' }) {
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
            back in the queue above.
          </p>
          <div className="item-list">
            {rejectedEarnings.data.map((payment) => (
              <LguRejectedEarningsRow key={payment.id} payment={payment} base={base} dashboardPath={dashboardPath} />
            ))}
          </div>
        </Section>
      )}
    </>
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
 * "Reviews & Ratings" view for LGU Admin and Super Admin. Feedback runs one
 * way: buyers review sellers. Sellers rating buyers was removed, so there is no
 * second direction to filter or merge. Backed by GET {scope}/reviews.
 */
function ReviewsAndRatingsSection({ data, scope, scopeLabel = 'on the platform' }) {
  const apiBase = scope === 'lgu' ? '/lgu' : '/super-admin'
  const queryKey = scope === 'lgu' ? ['lgu-reviews'] : ['super-admin-reviews']

  const removeReview = useMutation({
    mutationFn: async (id) => (await api.delete(`${apiBase}/reviews/${id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })

  const reviews = [...(data?.buyer_reviews || [])].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))

  return (
    <Section title="Reviews & Ratings">
      <p className="helper-text">Buyers reviewing sellers. Remove any review that isn&apos;t fair to the seller; their rating is recalculated automatically.</p>
      {removeReview.error && <p className="error">{removeReview.error?.response?.data?.message || 'Could not remove that review.'}</p>}
      {reviews.length ? (
        <div className="review-list">
          {reviews.map((review) => (
            <LguReviewCard
              key={`review-${review.id}`}
              review={review}
              scope={scope}
              onRemove={() => { if (window.confirm('Remove this buyer review? The seller’s rating will be recalculated.')) removeReview.mutate(review.id) }}
            />
          ))}
        </div>
      ) : <EmptyState message={`No buyer reviews yet ${scopeLabel}.`} />}
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
  seller_registration_approved: { label: 'Seller Registration Approved', icon: ShieldCheck, tone: 'success', category: 'listings_sellers' },
  seller_registration_rejected: { label: 'Seller Registration Rejected', icon: XCircle, tone: 'danger', category: 'listings_sellers' },
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
  user_report_filed: { label: 'User Report Filed', icon: Flag, tone: 'warning', category: 'reports' },
  user_report_reviewed: { label: 'User Report Under Review', icon: Flag, tone: 'info', category: 'reports' },
  user_report_resolved: { label: 'User Report Resolved', icon: CheckCircle, tone: 'success', category: 'reports' },
  user_report_dismissed: { label: 'User Report Dismissed', icon: XCircle, tone: 'neutral', category: 'reports' },
  seller_notice_issued: { label: 'Notice to Explain Issued', icon: ShieldAlert, tone: 'danger', category: 'reports' },
  seller_notice_updated: { label: 'Notice to Explain Updated', icon: ShieldCheck, tone: 'info', category: 'reports' },
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
        {/* Every audit entry is dated -- for order-linked actions this is when
            the action happened; the order's own date is on the record the
            entry links through to. */}
        <p className="muted">{entry.timestamp ? formatOrderDate(entry.timestamp) : ''}</p>
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

const DISMISSED_ANNOUNCEMENTS_KEY = 'abaimarket_dismissed_announcements'

function readDismissedAnnouncements() {
  try {
    const stored = JSON.parse(localStorage.getItem(DISMISSED_ANNOUNCEMENTS_KEY) || '[]')
    return Array.isArray(stored) ? stored : []
  } catch {
    return []
  }
}

/**
 * Site-wide announcement bar: the Super Admin's active announcements across
 * the top of every page -- the public storefront (guests included) and all
 * four dashboards, on every tab. Backed by the public GET /announcements/active
 * (see App\Models\Announcement::scopeActive), so it only ever shows
 * announcements inside their display window. The in-app notification each
 * user receives is unchanged; this is the always-visible counterpart.
 *
 * A viewer can dismiss an announcement; that is remembered in this browser
 * only, keyed by id + updated_at, so editing an announcement shows it again.
 */
function SiteAnnouncementBar() {
  const [dismissed, setDismissed] = useState(readDismissedAnnouncements)
  const { data } = useQuery({
    queryKey: ['announcements-active'],
    queryFn: async () => (await api.get('/announcements/active')).data,
    retry: false,
    placeholderData: [],
    refetchInterval: 5 * 60 * 1000,
  })

  const visible = (data || []).filter((a) => !dismissed.includes(`${a.id}:${a.updated_at}`))
  if (!visible.length) return null

  const dismiss = (announcement) => {
    const next = [...dismissed, `${announcement.id}:${announcement.updated_at}`].slice(-50)
    setDismissed(next)
    try {
      localStorage.setItem(DISMISSED_ANNOUNCEMENTS_KEY, JSON.stringify(next))
    } catch {
      // Storage unavailable (private mode): dismissal just lasts this page view.
    }
  }

  return (
    <div className="site-announcements" role="region" aria-label="Announcements">
      {visible.map((a) => (
        <div className={`site-announcement announcement-${a.category}`} key={a.id}>
          <Megaphone size={16} aria-hidden="true" />
          <p><strong>{a.title}</strong> {a.body}</p>
          <button type="button" className="site-announcement-dismiss" onClick={() => dismiss(a)} aria-label={`Dismiss announcement: ${a.title}`}>
            ×
          </button>
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
  const lguOrders = useQuery({
    queryKey: ['lgu-orders'],
    queryFn: async () => (await api.get('/lgu/orders')).data,
    enabled: tab === 'orders',
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
  const markAllRead = useMutation({
    mutationFn: async () => (await api.patch('/lgu/notifications/read-all')).data,
    onSuccess: () => {
      setVisibleNotificationIds((current) => [...current, ...notifications.map((n) => n.id)])
      queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
    },
  })
  const notificationLink = (notification) => (notification.type?.startsWith('earnings_pending_approval') ? '/lgu/dashboard?tab=earnings' : null)
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
  })
  const [lguWithdrawForm, setLguWithdrawForm] = useState({ method: 'gcash', account_name: '', account_number: '', amount: '' })
  const [lguWithdrawFormError, setLguWithdrawFormError] = useState('')
  const [confirmingLguWithdrawal, setConfirmingLguWithdrawal] = useState(false)
  const requestLguWithdrawal = useMutation({
    mutationFn: async () => (await api.post('/lgu/withdrawals', {
      method: lguWithdrawForm.method,
      account_name: lguWithdrawForm.account_name,
      account_number: normalizeAccountNumber(lguWithdrawForm.account_number),
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
          <StatsRow items={[
            ['Registered Sellers', reports.data?.registered_sellers ?? 0],
            ['Listings', reports.data?.listings ?? 0],
            ['Open User Reports', lgu.data?.open_user_reports ?? 0],
            ['Open Notices to Explain', lgu.data?.open_seller_notices ?? 0],
          ]} />
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
          <p className="helper-text">All listings from sellers in your municipality, including already-approved ones. Open a listing to review it and approve, reject, or delete it.</p>
          {(listingManagement.data || []).length ? (
            <div className="item-list">
              {listingManagement.data.map((item) => (
                <div className="card action" key={item.id}>
                  <div>
                    <div className="card-row"><Link className="seller-name-link" to={`/lgu/listings/${item.id}`}><strong>{item.title}</strong></Link>{item.approval_status !== 'approved' && <Badge status={item.approval_status} />}</div>
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
                      <strong>{seller.hatchery_name}</strong>
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
      {tab === 'orders' && (
        <Section title="Orders">
          <p className="helper-text">Every order placed with a seller in your municipality. If a buyer never confirms a delivery that is Out for Delivery, you can mark it as received on their behalf once you have confirmed it arrived. It then moves to Seller Earnings for approval.</p>
          <AdminOrderTable rows={lguOrders.data || []} base="/lgu" invalidateKeys={['lgu-orders', 'lgu-earnings', 'lgu-dashboard']} />
        </Section>
      )}
      {tab === 'earnings' && <SellerEarningsPanel />}
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
              <input value={lguWithdrawForm.account_number} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, account_number: e.target.value })} placeholder={accountNumberPlaceholder(lguWithdrawForm.method)} inputMode="numeric" />
              <input value={lguWithdrawForm.amount} onChange={(e) => setLguWithdrawForm({ ...lguWithdrawForm, amount: e.target.value })} placeholder="Amount to withdraw" type="number" min="0" step="0.01" />
            </div>
            <p className="helper-text">Available to withdraw: {currency(wallet.data?.available_balance ?? 0)}</p>
            <button type="button" onClick={submitLguWithdrawal} disabled={requestLguWithdrawal.isPending}>{requestLguWithdrawal.isPending ? 'Submitting...' : 'Submit Withdrawal Request'}</button>
            {lguWithdrawFormError && <p className="error">{lguWithdrawFormError}</p>}
            {confirmingLguWithdrawal && (
              <WithdrawalConfirmModal form={lguWithdrawForm} onConfirm={confirmLguWithdrawal} onClose={() => setConfirmingLguWithdrawal(false)} />
            )}
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
                  <Fragment key={request.id}>
                  <div className="table-row">
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
                  {request.status === 'rejected' && (
                    <div className="table-row-appeal">
                      <div className="table-row-appeal-head">
                        <p className="error">This withdrawal was rejected by the Super Admin. If you think it should be reconsidered, explain your side.</p>
                        <DisputeAction
                          endpoint={`/lgu/lgu-withdrawals/${request.id}/dispute`}
                          invalidateKeys={['lgu-wallet']}
                          label="Dispute This Rejection"
                        />
                      </div>
                    </div>
                  )}
                  </Fragment>
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
        <Section title="Analytics" actions={<PeriodFilter period={reportsPeriod} onChange={setReportsPeriod} />}>
          <p className="helper-text">Graphs reflect activity in your municipality for the selected period. The summary below remains all-time.</p>
          <div className="charts-grid">
            <CategoryBarChart title="Listings by Status" data={(reports.data?.listings_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.approval_status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.approval_status)} />
            <CategoryBarChart title="Listings by Species" data={reports.data?.listings_by_species} dataKey="total" nameKey="species" colorFor={(entry) => speciesChartColor(entry.species)} />
            <CategoryBarChart title="Sellers by Verification Status" data={(reports.data?.sellers_by_status || []).map((row) => ({ ...row, label: statusChartLabel(row.status) }))} dataKey="total" nameKey="label" colorFor={(entry) => statusChartColor(entry.status)} />
            <TimeSeriesChart title={`Orders Over Time (${periodLabel(reportsPeriod)})`} data={reports.data?.orders_over_time} dataKey="count" color="var(--color-primary)" />
          </div>
          <StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0], ['Listings', reports.data?.listings ?? 0]]} />

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
      {tab === 'notifications' && (
        <Section
          title="Notifications"
          actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllRead.isPending} onClick={() => markAllRead.mutate()} />}
        >
          <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} getLink={notificationLink} />
        </Section>
      )}
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

function SuperAdminListingReviewPage() {
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
    mutationFn: async () => (await api.patch(`/super-admin/listings/${id}/reject`, reason.trim() ? { reason: reason.trim() } : {})).data,
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

// datetime-local inputs work in the viewer's local time with no timezone.
// The API receives ISO strings with an offset, and stored UTC values are
// converted back to local time before they are put in the inputs.
function toLocalDateTimeInput(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function localDateTimeInputToIso(value) {
  return value ? new Date(value).toISOString() : null
}

const ANNOUNCEMENT_START_GRACE_MS = 5 * 60 * 1000
const ANNOUNCEMENT_MAX_YEAR = 2100
const ANNOUNCEMENT_MAX_INPUT = `${ANNOUNCEMENT_MAX_YEAR}-12-31T23:59`

/**
 * Mirrors AnnouncementController::guardSchedule so the Super Admin sees the
 * problem before submitting. Only a date that changed from `original` (the
 * values loaded for editing) is held to the "not in the past" rules.
 */
function announcementScheduleIssue(form, original) {
  const now = Date.now()
  const start = form.starts_at ? new Date(form.starts_at).getTime() : null
  const end = form.expires_at ? new Date(form.expires_at).getTime() : null
  const yearOf = (value) => new Date(value).getFullYear()

  if ((start && yearOf(form.starts_at) > ANNOUNCEMENT_MAX_YEAR) || (end && yearOf(form.expires_at) > ANNOUNCEMENT_MAX_YEAR)) {
    return `The year can't be after ${ANNOUNCEMENT_MAX_YEAR}.`
  }
  if (start && form.starts_at !== original.starts_at && start < now - ANNOUNCEMENT_START_GRACE_MS) {
    return "The start date can't be in the past. Leave it blank to publish right away."
  }
  if (end && form.expires_at !== original.expires_at && end <= now) {
    return 'The end date must be in the future.'
  }
  if (start && end && end <= start) {
    return 'The end date must be after the start date.'
  }
  return ''
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
  const [lguForm, setLguForm] = useState({ name: '', email: '', password: '', password_confirmation: '', municipality_id: '' })
  const [showLguPasswords, setShowLguPasswords] = useState(false)
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
              ['Open User Reports', dashboard.data?.open_user_reports ?? 0],
              ['Pending Seller Withdrawals', dashboard.data?.pending_seller_withdrawals ?? 0],
              ['Pending LGU Withdrawals', dashboard.data?.pending_lgu_withdrawals ?? 0],
            ]} />
          </Section>
          <Section title="Marketplace Revenue" actions={<Link className="ghost" to="/admin/dashboard?tab=reports">View Analytics</Link>}>
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
          <p className="helper-text">All listings platform-wide, across every municipality. Open a listing to review it and approve, reject, or delete it.</p>
          {(listingManagement.data || []).length ? (
            <div className="item-list">
              {listingManagement.data.map((item) => (
                <div className="card action" key={item.id}>
                  <div>
                    <div className="card-row"><Link className="seller-name-link" to={`/admin/listings/${item.id}`}><strong>{item.title}</strong></Link>{item.approval_status !== 'approved' && <Badge status={item.approval_status} />}</div>
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
        <Section
          title="Notifications"
          actions={<MarkAllReadButton unreadCount={notifications.length} loading={markAllNotificationsRead.isPending} onClick={() => markAllNotificationsRead.mutate()} />}
        >
          <NotificationStack notifications={notifications} onMarkRead={handleMarkRead} />
        </Section>
      )}
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
        <>
          <SellerRegistrationQueue
            endpointBase="/super-admin"
            queryKey="super-admin-seller-registrations"
            stageLabel="Seller registrations awaiting review across every municipality. These are normally handled by each municipality's own LGU Admin -- approve one here when that LGU Admin is unavailable. Approving verifies the seller and lets them start creating listings."
            approveLabel="Approve Registration"
            emptyMessage="No seller registrations awaiting review."
            extraInvalidateKeys={['super-admin-sellers', 'super-admin-dashboard']}
          />
          <Section title="All Sellers (Platform-Wide)">
          <p className="helper-text">Super Admin may suspend any seller regardless of municipality. Suspended sellers cannot create, edit, or publish listings, receive new orders, or request withdrawals. Existing completed orders are unaffected. Removing deletes the account and its listings permanently and is only possible for sellers with no order history; suspend anyone who has already traded.</p>
          {(sellersQuery.data || []).length ? (
            <div className="item-list">
              {sellersQuery.data.map((seller) => (
                <div className="card action" key={seller.id}>
                  <div>
                    <div className="card-row">
                      <strong>{seller.hatchery_name}</strong>
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
        <Section title="Refunds">
          <p className="helper-text">Paid orders that were cancelled or expired. Refund the buyer in the PayMongo dashboard, then mark it refunded here.</p>
          {(refunds.data || []).length ? (
            <div className="item-list">
              {refunds.data.map((refund) => (
                <RefundRow key={refund.id} refund={refund} onMarkRefunded={(id, reference) => markRefunded.mutateAsync({ id, reference })} />
              ))}
            </div>
          ) : <EmptyState message="No refunds needed." />}
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
}

function ReportStatusBadge({ status }) {
  const meta = REPORT_STATUS_META[status] || { label: status, tone: 'neutral' }
  return <Badge tone={meta.tone}>{meta.label}</Badge>
}

/**
 * "Report this user" button + popup. One component for both directions -- a
 * Buyer reporting a Seller and a Seller reporting a Buyer post to the same
 * endpoint, which derives the direction from the caller's role (see backend
 * UserReportController).
 */
function ReportUserAction({ userId, userName, label = 'Report User' }) {
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
      reason: form.reason || reasons.data?.[0],
      description: form.description,
    })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-reports'] })
      setForm({ reason: '', description: '' })
      setOpen(false)
    },
  })

  const reason = form.reason || reasons.data?.[0] || ''
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
            they will review it and decide what action to take. Filing a report does not suspend anyone by itself.
          </p>
          <label className="filter-label">
            Reason
            <select value={reason} onChange={(e) => setForm({ ...form, reason: e.target.value })}>
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
function UserReportsPanel({ endpointBase, queryKey, scopeLabel }) {
  const [statusFilter, setStatusFilter] = useState('open')
  const [actingId, setActingId] = useState(null)
  const [decision, setDecision] = useState({ status: 'under_review', notes: '' })

  const reports = useQuery({
    queryKey: [queryKey],
    queryFn: async () => (await api.get(`${endpointBase}/user-reports`)).data,
    retry: false,
    placeholderData: [],
  })

  const updateReport = useMutation({
    mutationFn: async ({ id, status, notes }) => (await api.patch(`${endpointBase}/user-reports/${id}`, {
      status,
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
      {rows.length ? (
        <div className="item-list">
          {rows.map((report) => (
            <div className="card report-card" key={report.id}>
              <div className="card-row">
                <strong>
                  {report.reporter?.name || 'Unknown'} <span className="muted">({report.reporter_role})</span>
                  {' → '}
                  {report.reportedUser?.name || 'Unknown'} <span className="muted">({report.reported_role})</span>
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
                    <option value="resolved">Resolve</option>
                    <option value="dismissed">Dismiss</option>
                  </select>
                  <textarea
                    value={decision.notes}
                    onChange={(e) => setDecision({ ...decision, notes: e.target.value })}
                    placeholder="Notes on your decision (optional, shared with the reporter)"
                    rows={2}
                  />
                  <div className="row-actions">
                    <button type="button" disabled={updateReport.isPending} onClick={() => updateReport.mutate({ id: report.id, status: decision.status, notes: decision.notes.trim() })}>
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
      ) : <EmptyState message="No reports match this filter." />}
      {updateReport.error && <p className="error">{updateReport.error.response?.data?.message || 'Could not update this report.'}</p>}
    </Section>
  )
}

/**
 * Notices to Explain -- the LGU's dedicated dashboard for sellers
 * automatically flagged for a low average rating (backend
 * App\Support\SellerReputation). Raising a notice never suspends anyone; this
 * is where the LGU reads the seller's explanation and decides what to do,
 * including suspending them from the Sellers tab if that is warranted.
 */
function SellerNoticesPanel({ scope = 'lgu' }) {
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
  // a rejection is an offense, and the third one suspends the seller.
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
        Sellers {scope === 'super_admin' ? 'across every municipality' : 'in your municipality'} whose average buyer rating has fallen to 3 stars or below are flagged here automatically. Their listings come
        A seller&apos;s <strong>first</strong> notice is a warning: their listings stay up while they explain. From their{' '}
        <strong>second</strong> notice onward the listings come off the marketplace until you accept the explanation. Read it and decide:{' '}
        <strong>accept</strong> puts their listings back with no offense recorded, <strong>reject</strong> records an offense. Nothing here
        suspends anyone automatically -- a rating can fall because a buyer was trolling. Suspension is your call, from the Sellers tab, after
        one notice or never.
      </p>
      {(notices.data || []).length ? (
        <div className="item-list">
          {notices.data.map((notice) => (
            <div className="card report-card" key={notice.id}>
              <div className="card-row">
                <strong>{notice.sellerProfile?.hatchery_name || 'Seller'}</strong>
                <Badge tone="danger">{Number(notice.average_rating || 0).toFixed(2)}/5</Badge>
                <ReportStatusBadge status={notice.status} />
              </div>
              <p className="report-description">{notice.details}</p>
              <p className="muted">
                Issued {new Date(notice.created_at).toLocaleString()} · {notice.ratings_count} review{notice.ratings_count === 1 ? '' : 's'} at the time
              </p>
              <p className="muted">
                Offenses on record: {notice.seller_offense_count ?? 0}
                {notice.sellerProfile?.listings_frozen_at ? ' · Listings are frozen' : ' · Listings are live'}
                {notice.sellerProfile?.status === 'suspended' ? ' · Account suspended' : ''}
              </p>
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
              {['accepted', 'rejected'].includes(notice.status) ? (
                <p className="helper-text">
                  {notice.status === 'accepted' ? 'Explanation accepted.' : 'Explanation rejected -- an offense was recorded.'}
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
                      onClick={() => rejectNotice.mutate({ id: notice.id, reason: decision.notes.trim() })}
                    >
                      Reject Explanation
                    </button>
                    <button type="button" className="ghost" onClick={() => setActingId(null)}>Cancel</button>
                  </div>
                  <p className="helper-text">
                    Rejecting records an offense and leaves the listings as they are. To suspend this seller, use the Sellers tab.
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
      ) : <EmptyState message={`No sellers ${scope === 'super_admin' ? '' : 'in your municipality '}are currently flagged for a low rating.`} />}
      {(updateNotice.error || acceptNotice.error || rejectNotice.error) && (
        <p className="error">
          {(updateNotice.error || acceptNotice.error || rejectNotice.error).response?.data?.message || 'Could not update this notice.'}
        </p>
      )}
    </Section>
  )
}

/**
 * The seller's own Notices to Explain. Raised automatically when their average
 * rating falls to 3 stars or below -- the seller answers here, and their LGU
 * reads the answer and decides. Responding never closes a notice.
 */
function SellerNoticesSection() {
  const [drafts, setDrafts] = useState({})

  const notices = useQuery({
    queryKey: ['seller-notices'],
    queryFn: async () => (await api.get('/seller/notices')).data,
    retry: false,
    placeholderData: [],
  })

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
        If your average buyer rating falls to 3 stars or below, your LGU is notified automatically and asks you to explain. Your{' '}
        <strong>first</strong> notice is a warning -- your listings stay on the marketplace while you explain. From your second notice onward your
        listings come off the marketplace until your LGU accepts your explanation. Either way this is <strong>not</strong> a suspension: you can
        still sign in, reply to buyers and complete orders already placed. Nothing suspends your account automatically.
      </p>
      {(notices.data || []).length ? (
        <div className="item-list">
          {notices.data.map((notice) => {
            const open = ['open', 'under_review'].includes(notice.status)
            const draft = drafts[notice.id] ?? ''
            return (
              <div className="card report-card" key={notice.id}>
                <div className="card-row">
                  <strong>Low Rating Notice</strong>
                  <Badge tone="danger">{Number(notice.average_rating || 0).toFixed(2)}/5</Badge>
                  <ReportStatusBadge status={notice.status} />
                </div>
                <p className="report-description">{notice.details}</p>
                <p className="muted">Issued {new Date(notice.created_at).toLocaleString()}</p>
                {notice.seller_response && (
                  <div className="notice-response">
                    <strong>Your explanation</strong>
                    <p>{notice.seller_response}</p>
                  </div>
                )}
                {notice.lgu_notes && <p className="helper-text"><strong>LGU notes:</strong> {notice.lgu_notes}</p>}
                {notice.status === 'accepted' && (
                  <p className="helper-text">Your LGU accepted this explanation. Your listings are back on the marketplace and no offense was recorded.</p>
                )}
                {notice.status === 'rejected' && (
                  <p className="error">Your LGU rejected this explanation, so an offense was recorded against your account.</p>
                )}
                {open ? (
                  <div className="form grid-form">
                    <textarea
                      value={draft}
                      onChange={(e) => setDrafts((current) => ({ ...current, [notice.id]: e.target.value }))}
                      placeholder={notice.seller_response ? 'Add to your explanation (at least 10 characters)' : 'Explain what happened and what you are doing about it (at least 10 characters)'}
                      rows={4}
                    />
                    <button
                      type="button"
                      disabled={draft.trim().length < 10 || respond.isPending}
                      onClick={() => respond.mutate({ id: notice.id, response: draft.trim() })}
                    >
                      {respond.isPending ? 'Sending...' : 'Send Explanation'}
                    </button>
                  </div>
                ) : (
                  <p className="helper-text">This notice has been closed by your LGU.</p>
                )}
              </div>
            )
          })}
        </div>
      ) : <EmptyState message="You have no notices. Keep your ratings above 3 stars and none will be raised." />}
      {respond.error && <p className="error">{respond.error.response?.data?.message || 'Could not send your explanation.'}</p>}
    </Section>
  )
}

function SellerRegistrationQueue({ endpointBase, queryKey, stageLabel, approveLabel, emptyMessage, extraInvalidateKeys = [] }) {
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

  return (
    <Section title="Seller Registration Approvals">
      <p className="helper-text">{stageLabel}</p>
      {registrations.data?.length ? (
        <div className="item-list">
          {registrations.data.map((seller) => (
            <div className="card action" key={seller.id}>
              <div>
                <div className="card-row">
                  <strong>{seller.hatchery_name}</strong>
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
      ) : <EmptyState message={emptyMessage} />}
      {(approve.error || reject.error) && (
        <p className="error">{approve.error?.response?.data?.message || reject.error?.response?.data?.message || 'Could not update this registration.'}</p>
      )}
    </Section>
  )
}

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

/**
 * items are [label, value, highlight?, to?]. A fourth entry turns that tile
 * into a link to the tab it summarises -- the number on the overview is the
 * obvious thing to click, and before this it was the one dead end on the page.
 * Tiles without a destination render exactly as they always have.
 */
function StatsRow({ items }) {
  return <div className="stats-grid">{items.map(([label, value, highlight, to]) => <Stat key={label} label={label} value={value} highlight={highlight} to={to} />)}</div>
}

function Stat({ value, label, highlight = false, to }) {
  const className = `stat-card${highlight ? ' stat-card-highlight' : ''}${to ? ' stat-card-link' : ''}`
  const body = <><strong>{value}</strong><span>{label}</span></>

  return to
    ? <Link className={className} to={to}>{body}</Link>
    : <div className={className}>{body}</div>
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
function OrderTableDetailRow({ orderNumber, detailsEndpoint, paymentView = 'escrow' }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['order-detail', detailsEndpoint, orderNumber],
    queryFn: async () => (await api.get(detailsEndpoint(orderNumber))).data,
    enabled: Boolean(orderNumber && detailsEndpoint),
  })

  return (
    <div className="order-table-detail-row">
      {isLoading && <LoadingState label="Loading order details..." />}
      {isError && <p className="error">Could not load order details.</p>}
      {data && <OrderDetailPanel detail={data} paymentView={paymentView} />}
    </div>
  )
}

/**
 * The shared order list, used by the Buyer's own orders and the Super Admin's
 * platform-wide transactions.
 *
 * paymentView picks WHOSE view of the payment the column shows. 'escrow' (the
 * default, used by the Super Admin) is the raw lifecycle: paid_held ->
 * released, i.e. when the SELLER's money is verified and released. 'buyer'
 * collapses that to what the buyer actually needs -- Paid / Unpaid / Refunded
 * -- because a buyer who has paid should not sit at "Paid Held" until their
 * LGU settles the order, which reads like something is still owed. See
 * BUYER_PAYMENT_VIEW.
 *
 * counterparty names the OTHER side of the transaction: 'seller' for a buyer
 * or admin reading the table, 'buyer' for the seller's own Recent Orders,
 * where the useful name is who bought it rather than who sold it.
 *
 * showOrderDate adds an Order Date column to the row itself. It is on for the
 * Buyer Dashboard's Recent Orders, where the date should be readable at a
 * glance without expanding anything; the expandable View Details panel and
 * every other caller are unchanged.
 *
 * onPay adds the Payment column: a buyer whose checkout was abandoned or
 * declined keeps a reserved, payable order until its window closes, and this
 * is the way back into PayMongo. Buyer Dashboard only -- the Super Admin's
 * transaction list passes no handler and renders no column.
 *
 * onMarkReceived adds the Action column for the LGU / Super Admin backstop:
 * a paid order stuck Out for Delivery because the buyer never confirmed it.
 * See AdminOrderTable.
 */
function OrderTable({ rows, onReview, onConfirmReceived, confirmPendingOrderId, onMarkReceived, markReceivedPendingOrderId, onPay, payPendingOrderId, detailsEndpoint, initialExpandedOrderNumber, showPaymentStatus = true, paymentView = 'escrow', counterparty = 'seller', showOrderDate = false }) {
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
      buyer_name: row.buyer?.name || 'Unknown buyer',
      buyer_avatar: row.buyer?.profile_picture || null,
      quantity: row.quantity,
      quantity_label: formatQuantity(row.quantity, row.listing),
      status: row.status,
      payment_status: row.payment?.status || row.payment_status || 'pending',
      total_amount: row.total_amount || row.amount || 0,
      created_at: row.created_at || row.date || '',
      review: row.review || null,
      // Appended by the Order model; null unless the order is awaiting payment.
      payment_expires_at: row.payment_expires_at || null,
    }
  })

  if (!normalized.length) return <EmptyState message="No orders yet." />

  const toggleExpanded = (orderNumber) => setExpandedOrderNumber((current) => (current === orderNumber ? null : orderNumber))

  return (
    <div className="table">
      <div className="table-row first">
        <span>Order Name</span>
        <span>Order #</span>
        <span>{counterparty === 'buyer' ? 'Buyer' : 'Seller'}</span>
        <span>Qty</span>
        {showOrderDate && <span>Order Date</span>}
        <span>Status</span>
        {(showPaymentStatus || onPay) && <span>Payment</span>}
        {onReview && <span>Review</span>}{/* also holds Confirm Received -- see ReviewCell */}
        {onMarkReceived && <span>Action</span>}
        {detailsEndpoint && <span>Details</span>}
      </div>
      {normalized.map((row) => (
        <Fragment key={row.id}>
          <div className="table-row">
            <span>{row.order_name}</span>
            <span>{row.order_number}</span>
            {counterparty === 'buyer' ? (
              <span className="order-seller-cell">
                <Avatar src={row.buyer_avatar} alt={row.buyer_name} className="order-seller-avatar" />
                {row.buyer_name}
              </span>
            ) : (
              <span className="order-seller-cell">
                <Avatar src={row.seller_avatar} alt={row.seller_name} className="order-seller-avatar" />
                {row.seller_name}{row.seller_contact_name ? ` (${row.seller_contact_name})` : ''}
              </span>
            )}
            <span>{row.quantity_label}</span>
            {showOrderDate && (
              <span className="order-date-cell">
                {formatOrderDate(row.created_at, { withTime: false })}
                {row.created_at && <small>{new Date(row.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small>}
              </span>
            )}
            <span><Badge status={row.status}>{statusChartLabel(row.status)}</Badge></span>
            {(showPaymentStatus || onPay) && (
              <PaymentCell
                row={row}
                view={paymentView}
                onPay={onPay}
                pending={payPendingOrderId === row.orderId}
              />
            )}
            {onReview && (
              <ReviewCell
                row={row}
                onReview={onReview}
                onConfirmReceived={onConfirmReceived}
                confirmPendingOrderId={confirmPendingOrderId}
              />
            )}
            {onMarkReceived && (
              row.status === 'in_transit' && ['paid_held', 'released'].includes(row.payment_status) ? (
                <span>
                  <button
                    type="button"
                    className="table-row-action"
                    onClick={() => onMarkReceived(row)}
                    disabled={markReceivedPendingOrderId === row.orderId}
                    title="The buyer has not confirmed this delivery. Marking it received sends it to Seller Earnings for approval."
                  >
                    {markReceivedPendingOrderId === row.orderId ? 'Marking...' : 'Mark as Received'}
                  </button>
                </span>
              ) : <span className="muted">—</span>
            )}
            {detailsEndpoint && (
              <span>
                <button type="button" className="ghost" onClick={() => toggleExpanded(row.order_number)}>
                  {expandedOrderNumber === row.order_number ? 'Hide Details' : 'View Details'}
                </button>
              </span>
            )}
          </div>
          {detailsEndpoint && expandedOrderNumber === row.order_number && (
            <OrderTableDetailRow orderNumber={row.order_number} detailsEndpoint={detailsEndpoint} paymentView={paymentView} />
          )}
        </Fragment>
      ))}
    </div>
  )
}

/**
 * The LGU / Super Admin Orders list, with the backstop for a silent buyer:
 * "Mark as Received" completes a paid order that is Out for Delivery on the
 * buyer's behalf (LguController / SuperAdminController::markOrderDelivered),
 * which puts it in the Seller Earnings queue. It does not release any money
 * itself -- earnings approval is still a separate step.
 */
function AdminOrderTable({ rows, base, invalidateKeys }) {
  const queryClient = useQueryClient()
  const markReceived = useMutation({
    mutationFn: async (orderId) => (await api.patch(`${base}/orders/${orderId}/mark-delivered`)).data,
    onSuccess: () => invalidateKeys.forEach((key) => queryClient.invalidateQueries({ queryKey: [key] })),
  })
  const handleMarkReceived = (row) => {
    if (!window.confirm(`Mark order ${row.order_number} as received on the buyer's behalf? Only do this once you have confirmed the fingerlings were delivered. The order will move to Seller Earnings for approval.`)) return
    markReceived.mutate(row.orderId)
  }

  return (
    <>
      {markReceived.error && <p className="error">{markReceived.error.response?.data?.message || 'Could not mark this order as received.'}</p>}
      <OrderTable
        rows={rows}
        detailsEndpoint={(orderNumber) => `${base}/orders/${orderNumber}`}
        onMarkReceived={handleMarkReceived}
        markReceivedPendingOrderId={markReceived.isPending ? markReceived.variables : null}
        showOrderDate
      />
    </>
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
/**
 * paymentView matches OrderTable's: 'escrow' (default -- seller, LGU, Super
 * Admin) shows the raw lifecycle, 'buyer' collapses it so a buyer reads "Paid"
 * here exactly as they do in the column above. Display only, so it applies to
 * every past order without touching a single stored row.
 */
function OrderDetailPanel({ detail, paymentView = 'escrow' }) {
  if (!detail) return null
  return (
    <div className="order-detail-panel">
      <div className="order-detail-grid">
        <div className="order-detail-field"><span className="order-detail-field-label">Order Number</span><span>{detail.order_number}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Order Date</span><span>{formatOrderDate(detail.created_at)}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Listing</span><span>{detail.listing?.title || detail.listing?.species || 'N/A'}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Seller</span><span>{detail.seller?.hatchery_name || 'N/A'}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Buyer</span><span>{detail.buyer?.name || 'N/A'}</span></div>
        {detail.municipality && <div className="order-detail-field"><span className="order-detail-field-label">Municipality</span><span>{detail.municipality.name}</span></div>}
        <div className="order-detail-field"><span className="order-detail-field-label">Quantity</span><span>{formatQuantity(detail.quantity, detail.listing)}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Total Amount</span><span className="price">{currency(detail.total_amount)}</span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Payment Status</span><span><PaymentStatusBadge status={detail.payment_status || 'pending'} view={paymentView} /></span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Order Status</span><span><Badge status={detail.order_status}>{statusChartLabel(detail.order_status)}</Badge></span></div>
        <div className="order-detail-field"><span className="order-detail-field-label">Delivery Status</span><span>{detail.delivery_status}</span></div>
        <div className="order-detail-field">
          <span className="order-detail-field-label">Review Status</span>
          <span>{detail.review ? `${'★'.repeat(detail.review.rating)}${'☆'.repeat(5 - detail.review.rating)} (${detail.review.rating}/5)` : 'Not yet reviewed'}</span>
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

/** One payment status, told from either the buyer's or the escrow's point of view. */
function PaymentStatusBadge({ status, view }) {
  if (view !== 'buyer') return <Badge status={status}>{statusChartLabel(status)}</Badge>
  const [label, tone] = BUYER_PAYMENT_VIEW[status] || ['Unpaid', 'warning']
  return <Badge tone={tone}>{label}</Badge>
}

/**
 * The payment state, and -- only while the order can still be paid -- the way
 * to act on it.
 *
 * Pay Now used to be a column of its own. It was empty for every order that
 * was not mid-checkout, so for most buyers it was a column of dashes taking
 * width on a table that already scrolls sideways. Putting the action in the
 * cell it acts on means it is present exactly when it is useful and costs
 * nothing when it is not.
 */
function PaymentCell({ row, view, onPay, pending }) {
  const expiresAtMs = row.payment_expires_at ? Date.parse(row.payment_expires_at) : null
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!expiresAtMs) return undefined
    const timer = setInterval(() => setNow(Date.now()), 30000)
    return () => clearInterval(timer)
  }, [expiresAtMs])

  const minutesLeft = expiresAtMs === null ? null : Math.floor((expiresAtMs - now) / 60000)
  // The scheduler runs every five minutes, so an order can sit here briefly
  // after its window has closed. Don't offer a payment the API would refuse.
  const payable = Boolean(onPay) && row.status === 'placed' && minutesLeft !== null && minutesLeft >= 0

  if (!payable) return <span><PaymentStatusBadge status={row.payment_status} view={view} /></span>

  // No "Unpaid" badge here: a Pay Now button with a countdown beside it already
  // says the order is unpaid, and saying it twice in one cell just adds height.
  // The badge is still the whole story in the branch above, where there is no
  // button to carry the meaning -- including an order that is still unpaid but
  // whose window has closed.
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

/**
 * The buyer's end of an order: confirm it arrived, then rate the seller.
 *
 * Confirming receipt lives here rather than in its own column because the two
 * actions are strictly sequential -- an order cannot be rated until it is
 * confirmed -- so one cell is never asking for both at once, and the orders
 * table (which already scrolls sideways) gains no extra width.
 *
 * Only the buyer sees this: completing an order is what releases its payment
 * into the LGU earnings queue, so the seller must not be able to declare their
 * own delivery complete. See OrderController::confirmReceived.
 */
/**
 * Appeal a rejection.
 *
 * A rejected earnings review or withdrawal used to be the end of the
 * conversation -- the party was told the reason and had no way to answer it.
 * `endpoint` differs per subject (order, seller withdrawal, LGU withdrawal);
 * the backend decides who may file, so this only collects the explanation.
 */
function DisputeAction({ endpoint, invalidateKeys = [], label = 'Dispute This' }) {
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
function DisputesPanel({ scope = 'lgu' }) {
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
  const resolvedRows = rows.filter((row) => row.status !== 'open')

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
                      <strong>{row.filedBy?.name || 'Unknown'}</strong>
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
                      <strong>{row.filedBy?.name || 'Unknown'}</strong>
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
        ) : <EmptyState message="No resolved disputes yet." />}
      </Section>
    </>
  )
}


function ReviewCell({ row, onReview, onConfirmReceived, confirmPendingOrderId }) {
  const [open, setOpen] = useState(false)
  const [rating, setRating] = useState(5)
  const [title, setTitle] = useState('')
  const [comment, setComment] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  if (row.status !== 'completed') {
    // Paid and on its way, so the buyer is the one who says it arrived.
    // The payment must actually have reached escrow, not merely have a
    // checkout session open -- the API refuses to complete an unpaid order,
    // so offering the button would only produce an error. 'paid_held' and
    // 'released' are the only two captured states (see MockPayment).
    const awaitingReceipt = onConfirmReceived
      && ['confirmed', 'in_transit'].includes(row.status)
      && ['paid_held', 'released'].includes(row.payment_status)
    if (!awaitingReceipt) return <span className="muted">Not yet eligible</span>

    return (
      <span>
        <button
          type="button"
          className="table-row-action"
          onClick={() => onConfirmReceived(row.orderId)}
          disabled={confirmPendingOrderId === row.orderId}
          title="Confirm you received the fingerlings. This releases the payment to your seller's LGU for approval."
        >
          {confirmPendingOrderId === row.orderId ? 'Confirming...' : 'Confirm Received'}
        </button>
      </span>
    )
  }
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
  Tuna: 'var(--chart-violet)',
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
                <span>{row.seller || 'Unknown'}</span>
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

function MessagesPanel({ initialUserId }) {
  const session = getSession()
  const [activeUserId, setActiveUserId] = useState(initialUserId || null)
  const [draft, setDraft] = useState('')
  const openedInitialRef = useRef(false)

  // There is no websocket: a reply would otherwise sit unseen until the panel
  // remounted or the window regained focus. Polling is scoped to this component,
  // which is only mounted while the Messages tab is open, and React Query pauses
  // intervals on a backgrounded tab (refetchIntervalInBackground defaults to
  // false), so a minimised browser stops asking. The open conversation refreshes
  // faster than the thread list because that is where someone is actually
  // waiting for a reply.
  const threads = useQuery({
    queryKey: ['message-threads'],
    queryFn: async () => (await api.get('/messages/threads')).data,
    retry: false,
    placeholderData: [],
    refetchInterval: 15000,
  })

  const thread = useQuery({
    queryKey: ['message-thread', activeUserId],
    queryFn: async () => (await api.get(`/messages/thread/${activeUserId}`)).data,
    enabled: !!activeUserId,
    retry: false,
    refetchInterval: 5000,
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

  // markRead used to fire only when a thread was opened. Now that replies can
  // arrive into a thread that is already on screen, one landing here would stay
  // counted as unread -- so mark it read as it arrives. Reading the count from a
  // ref keeps this to one PATCH per batch of new messages rather than one per
  // render, and markRead only invalidates 'message-threads', so it cannot
  // retrigger the thread query that fed it.
  const seenCountRef = useRef(0)
  useEffect(() => {
    const count = thread.data?.messages?.length ?? 0
    if (!activeUserId) { seenCountRef.current = 0; return }
    if (count > seenCountRef.current) {
      if (seenCountRef.current > 0) markRead.mutate(activeUserId)
      seenCountRef.current = count
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [thread.data, activeUserId])

  // Where "View Profile" goes for the person in the open conversation, or null
  // when this pairing has no profile page to open.
  const counterpart = thread.data?.user
  const threadProfilePath = (() => {
    if (!counterpart) return null
    if (session?.role === 'seller' && counterpart.role === 'buyer') return `/seller/buyers/${counterpart.id}`
    if (session?.role === 'buyer' && counterpart.role === 'seller' && counterpart.seller_profile_id) {
      return sellerProfilePath(counterpart.seller_profile_id)
    }
    return null
  })()

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
              {item.last_message?.created_at && (
                <span className="thread-timestamp">{formatMessageTimestamp(item.last_message.created_at)}</span>
              )}
            </div>
            {item.unread_count > 0 && <span className="pill">{item.unread_count} new</span>}
          </button>
        ))}
      </div>
      <div className="card thread-view">
        {!activeUserId && <p>Select a conversation to view messages.</p>}
        {activeUserId && (
          <>
            {/* The name is wrapped rather than left as a bare text node so it
                can be truncated: without a box of its own a long name grows the
                header and shunts the avatar and View Profile around. */}
            <h4 className="thread-header">
              <Avatar src={thread.data?.user?.profile_picture} alt={thread.data?.user?.name} className="thread-header-avatar" />
              <span className="thread-header-name">{thread.data?.user?.name || 'Conversation'}</span>
              {/* View Profile works both ways: a seller opens the buyer they're
                  talking to, and a buyer opens the hatchery. The seller's page
                  is addressed by seller_profiles.id, which the thread payload
                  carries for seller counterparts (MessageController). */}
              {threadProfilePath && (
                <Link className="seller-name-link thread-view-profile" to={threadProfilePath}>View Profile</Link>
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

/**
 * Date + time for one message, shown on every bubble for both sender and
 * receiver. Today's messages read "Today, 2:34 PM" and this year's drop the
 * year, so a long thread stays scannable while still always carrying a date.
 */
function formatMessageTimestamp(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  const today = new Date()
  const isSameDay = (a, b) => a.toDateString() === b.toDateString()
  const yesterday = new Date(today)
  yesterday.setDate(today.getDate() - 1)

  if (isSameDay(date, today)) return `Today, ${time}`
  if (isSameDay(date, yesterday)) return `Yesterday, ${time}`

  const day = date.toLocaleDateString(undefined, {
    year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    month: 'short',
    day: 'numeric',
  })
  return `${day}, ${time}`
}

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
        {/* Date and time on every message, for whoever is reading it -- this
            bubble renders identically for the sender and the receiver, so both
            sides see the same stamp. Read straight from messages.created_at,
            which every message has always had, so existing conversations show
            their real history rather than a blank. */}
        <span className="message-timestamp" title={new Date(message.created_at).toString()}>
          {formatMessageTimestamp(message.created_at)}
        </span>
        {!isDeleted && message.edited_at && (
          <span className="muted" title={new Date(message.edited_at).toString()}>· edited {formatMessageTimestamp(message.edited_at)}</span>
        )}
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
  const returnToken = searchParams.get('t')
  const acknowledgedKey = orderNumber ? `fishmarket_payment_success_${orderNumber}` : null
  const acknowledgedRef = useRef(false)
  const acknowledge = useMutation({
    // 410 means the single-use return token was already spent, which is an
    // expected answer here (a bookmarked or re-opened receipt), not a failure
    // to report as one -- so unwrap it into the same shape as a 200.
    mutationFn: async () => {
      try {
        return (await api.post(`/orders/${orderNumber}/payment-success`, { t: returnToken })).data
      } catch (err) {
        if (err?.response?.status === 410) return err.response.data
        throw err
      }
    },
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
    if (acknowledge.data?.status === 'success' && orderNumber) {
      navigate(`/buyer/dashboard?tab=orders&order=${orderNumber}${listingId ? `&listing_id=${listingId}` : ''}`, { replace: true })
    }
  }, [acknowledge.data?.status, orderNumber, listingId, navigate])
  if (!session) return <Navigate to="/login" replace />
  const status = acknowledge.data?.status
  const processing = status === 'processing'
  // A spent single-use token: the buyer re-opened an old receipt. Never render
  // this as a fresh "Payment Successful", or the same link keeps looking like a
  // new transaction every time it is visited.
  const spent = status === 'already_confirmed'
  return (
    <main className="auth-page">
      <section className={`result-card ${spent ? '' : 'success-card'}`}>
        <p className="eyebrow">{spent ? 'Link Already Used' : processing ? 'Confirming Payment' : 'Payment Successful'}</p>
        <h1>{spent ? 'This payment link has expired' : processing ? 'Almost there' : 'Order received'}</h1>
        <p>{spent || processing ? acknowledge.data.message : orderNumber ? `Payment for order #${orderNumber} was successful and is now held in escrow.` : 'Your payment returned from PayMongo and your session is still active.'}</p>
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
  const returnToken = searchParams.get('t')
  const acknowledgedKey = orderNumber ? `fishmarket_payment_failed_${orderNumber}` : null
  const acknowledgedRef = useRef(false)
  const acknowledge = useMutation({
    // 410 = the return token was already spent and the order has since been
    // paid, so there is no "declined" state left to record.
    mutationFn: async () => {
      try {
        return (await api.post(`/orders/${orderNumber}/payment-cancelled`, { t: returnToken })).data
      } catch (err) {
        if (err?.response?.status === 410) return err.response.data
        throw err
      }
    },
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
  // The order is NOT failed here -- markPaymentCancelled leaves it reserved and
  // payable until its window closes, and My Orders carries the Pay Now button.
  // Same spent-token handling as PaymentSuccessPage: an old cancel link must
  // not keep announcing a failure for an order that has since been paid.
  const spent = acknowledge.data?.status === 'already_confirmed'
  return <main className="auth-page"><section className="result-card success-card"><p className="eyebrow">{spent ? 'Link Already Used' : 'Payment Not Completed'}</p><h1>{spent ? 'This payment link has expired' : 'Payment not completed'}</h1><p>{spent ? acknowledge.data.message : orderNumber ? `Payment for order #${orderNumber} was not completed. The order is still reserved for you — you can pay for it from My Orders until the payment window closes. No funds were captured.` : 'Your session is still active. You can continue browsing or try again.'}</p><div className="success-actions"><Link className="button" to="/buyer/dashboard?tab=orders">Go to My Orders</Link><button className="ghost" type="button" onClick={() => navigate('/buyer/dashboard?tab=browse')}>Return to Merchant</button></div></section></main>
}

function SellersPage() {
  const { data = [] } = useQuery({
    queryKey: ['sellers'],
    queryFn: async () => (await api.get('/sellers')).data.map(mapSeller),
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
        {/* Reporting is buyer -> seller only; admins moderate from their own
            dashboards and a seller cannot report another seller. */}
        {seller.user_id && session?.role === 'buyer' && (
          <ReportUserAction userId={seller.user_id} userName={seller.hatchery_name} label="Report Seller" />
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
                : session?.role === 'seller' ? (item) => `/seller/listings/${item.id}?source=marketplace`
                  : session?.role === 'lgu_admin' ? (item) => `/lgu/listings/${item.id}`
                    : session?.role === 'super_admin' ? (item) => `/admin/listings/${item.id}`
                      : undefined
            }
          />
        ) : <EmptyState message="No listings available from this seller yet." />}
      </Section>
      <MediaGallery media={galleryMedia} title="Farm Gallery" />
      <SellerReviewsSection reviews={reviews} fallbackAverage={seller.rating} />
    </main>
  )
}

const REVIEW_STARS = [5, 4, 3, 2, 1]

/**
 * Buyer reviews at the foot of a seller profile. The old version listed bare
 * star rows, which told a buyer nothing about whether 5 stars came from one
 * order or forty -- so this leads with the shape of the score (average, total,
 * and how the ratings are spread) before the individual write-ups.
 *
 * The distribution is one series of counts, so it reads as a single-hue bar set
 * with the count printed on every row: the number beside each bar is the whole
 * data table, and nothing is carried by colour alone. The average is computed
 * from the reviews actually on screen, falling back to the stored seller rating
 * only when there are none to average.
 *
 * Every review is tied to one completed order of the buyer's own (see
 * ReviewController::store), which is what makes the "Verified purchase" mark a
 * fact rather than decoration.
 */
function SellerReviewsSection({ reviews = [], fallbackAverage }) {
  const total = reviews.length
  const average = total
    ? reviews.reduce((sum, review) => sum + Number(review.rating || 0), 0) / total
    : Number(fallbackAverage || 0)
  const written = reviews.filter((review) => (review.comment || '').trim()).length
  const distribution = REVIEW_STARS.map((star) => ({
    star,
    count: reviews.filter((review) => Math.round(Number(review.rating || 0)) === star).length,
  }))

  return (
    <Section title="Buyer Reviews">
      {total ? (
        <>
          <div className="card review-summary">
            <div className="review-summary-score">
              <strong className="review-summary-average">{average.toFixed(1)}<span>/5</span></strong>
              {renderStars(average)}
              <p className="muted">
                {total} verified review{total === 1 ? '' : 's'}
                {written ? ` · ${written} with a written comment` : ''}
              </p>
            </div>
            <ul className="review-distribution">
              {distribution.map(({ star, count }) => (
                <li key={star} title={`${count} of ${total} review${total === 1 ? '' : 's'} rated ${star} star${star === 1 ? '' : 's'}`}>
                  <span className="review-distribution-label">{star}<Star size={12} /></span>
                  <span className="review-distribution-track">
                    <span
                      className="review-distribution-bar"
                      style={{ width: `${total ? (count / total) * 100 : 0}%` }}
                    />
                  </span>
                  <span className="review-distribution-count">{count}</span>
                </li>
              ))}
            </ul>
          </div>
          <div className="review-list">
            {reviews.map((review) => (
              <article className="card review-item" key={review.id}>
                <div className="review-card-head">
                  <p className="review-author">
                    <Avatar src={review.buyer?.profile_picture} alt={review.buyer?.name} className="review-avatar" />
                    {review.buyer?.name || 'AbaiMarket Buyer'}
                  </p>
                  <span className="muted">{review.created_at ? new Date(review.created_at).toLocaleString() : ''}</span>
                </div>
                <div className="review-rating-line">
                  {renderStars(review.rating)}
                  <strong className="review-score">{Number(review.rating || 0).toFixed(1)}<span>/5</span></strong>
                  {review.order_id && <span className="review-badge"><ShieldCheck size={13} /> Verified purchase</span>}
                </div>
                {review.title && <p className="review-title">{review.title}</p>}
                <blockquote className="review-quote">{review.comment || 'No comment left.'}</blockquote>
              </article>
            ))}
          </div>
        </>
      ) : (
        <EmptyState
          icon={Star}
          title="No reviews yet"
          message="Buyers can leave a review once their order with this hatchery is completed."
        />
      )}
    </Section>
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
  const sellerOrders = data.seller_orders || []

  return (
    <main className="seller-profile-page">
      <section className="card seller-profile-header">
        <div className="seller-header-row">
          <Avatar src={buyer.profile_picture} alt={buyer.name} className="seller-avatar" />
          <div>
            <div className="card-row"><h1>{buyer.name}</h1><RoleBadge role="buyer" /></div>
            <div className="stats-inline">
              <Stat value={stats.completed_orders} label="Completed with you" />
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
        <div className="row-actions">
          <button className="button" type="button" onClick={() => navigate(`/seller/dashboard?tab=messages&with=${buyer.id}`)}>
            <MessageCircle size={16} /> Message Buyer
          </button>
          <ReportUserAction userId={buyer.id} userName={buyer.name} label="Report Buyer" />
        </div>
      </section>


      <Section title="Order History with You">
        <p className="helper-text">
          Whether a buyer completes what they start is the useful signal about them -- more so than a score, which a seller
          could leave out of irritation at a cancelled order.
        </p>
        {sellerOrders.length ? (
          <div className="item-list">
            {sellerOrders.map((order) => (
              <div className="card action" key={order.id}>
                <div>
                  <div className="card-row">
                    <strong>{order.order_number}</strong>
                    <Badge status={order.status}>{statusChartLabel(order.status)}</Badge>
                  </div>
                  <p className="muted">
                    {order.listing?.species || order.listing?.title || 'Listing'} · {currency(order.total_amount)}
                    {order.created_at ? ` · ${new Date(order.created_at).toLocaleDateString()}` : ''}
                  </p>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState message="No orders with this buyer yet." />}
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
  seller: 'Ask AbaiMarket AI about your listings, orders, wallet, seller earnings, withdrawals, reviews, business recommendations like restocking, or fish farming -- water quality, feeding, disease, and harvesting.',
  lgu_admin: 'Ask AbaiMarket AI about pending approvals, seller verification, seller earnings, reports, or municipality statistics -- scoped to your own municipality.',
  super_admin: 'Ask AbaiMarket AI about platform-wide statistics, listings, payouts, reports, or municipality comparisons and trends.',
}

const AI_PLACEHOLDER_BY_ROLE = {
  buyer: 'Ask about buying, sellers, orders, or fish care...',
  seller: 'Ask about your listings, orders, wallet, sales, or fish care...',
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

/**
 * Starter questions offered on an empty conversation, per role. A blank chat
 * box gives no clue what the assistant can actually answer, so users guessed --
 * and a guess that misses gets a refusal, which reads as "the AI is broken".
 * These are phrased as a real user would type them, and each role's list mixes
 * a live-data question with a how-does-this-work one; buyers and sellers also
 * get a fish-farming question, since that capability is the least discoverable.
 */
const AI_SUGGESTIONS_BY_ROLE = {
  buyer: ['How do I buy fingerlings?', 'Where is my order?', 'What species is good for a beginner?', 'Why are my fingerlings dying?'],
  seller: ['What is my available balance?', 'How do I withdraw my earnings?', 'How often should I feed my fingerlings?', 'My tilapia have white spots -- what do I do?'],
  lgu_admin: ['How many sellers are registered here?', 'What is awaiting my earnings approval?', 'How do I verify a seller?'],
  super_admin: ['How many sellers are on the platform?', 'What withdrawals are awaiting payout?', 'Which municipality has the most listings?'],
}

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
  // Reachable when a token expires mid-conversation; the guest case is handled
  // before a request is ever made.
  if (status === 401) return 'Please log in again to use the AI assistant.'
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
  // This widget also renders on the PUBLIC layout, where there is no session.
  // Both AI endpoints sit behind auth:sanctum, so a guest's question came back
  // 401 and surfaced as a generic "Something went wrong" -- the feature looked
  // broken rather than gated. Guests now see what it does and how to get it.
  const session = getSession()
  const isGuest = !session
  const role = session?.role || 'buyer'
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
    // A guest has no history and no token -- asking for it is a guaranteed 401.
    enabled: !isGuest,
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
          {isGuest && (
            <div className="ai-guest">
              <p>Log in to ask the assistant about listings, orders, your wallet, or fish farming.</p>
              <Link className="button full" to="/login">Log in</Link>
            </div>
          )}
          {/* Only on a genuinely empty conversation -- once there is anything
              to read, the suggestions stop being help and start being clutter. */}
          {!isGuest && !historyMessages.length && !chat.length && !ask.isPending && (
            <div className="ai-suggestions">
              {(AI_SUGGESTIONS_BY_ROLE[role] || AI_SUGGESTIONS_BY_ROLE.buyer).map((suggestion) => (
                <button
                  type="button"
                  className="ai-suggestion"
                  key={suggestion}
                  onClick={() => {
                    setChat((current) => [...current, { role: 'user', text: suggestion }])
                    sendQuestion(suggestion)
                  }}
                >
                  {suggestion}
                </button>
              ))}
            </div>
          )}
          {error && (
            <div className="ai-error">
              <p className="error">{error.message}</p>
              <button type="button" className="ghost" onClick={retry} disabled={ask.isPending}>Retry</button>
            </div>
          )}
          {!isGuest && (
            <>
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
            </>
          )}
        </div>
      )}
    </div>
  )
}

export default App
