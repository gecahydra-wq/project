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
  BarChart3,
  Bell,
  Bot,
  CheckCircle,
  ChevronLeft,
  ChevronRight,
  Fish,
  Image as ImageIcon,
  LayoutDashboard,
  LogOut,
  Menu,
  MessageCircle,
  PlayCircle,
  Search,
  ShieldCheck,
  ShoppingCart,
  Star,
  Store,
  Video as VideoIcon,
  Wallet,
  X,
} from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
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
  const rounded = Math.round(Number(rating) || 0)
  return '★'.repeat(Math.max(0, Math.min(5, rounded))) + '☆'.repeat(5 - Math.max(0, Math.min(5, rounded)))
}

function currency(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

function withdrawalMethodLabel(method) {
  return ({ gcash: 'GCash', maya: 'Maya', bank_transfer: 'Bank Transfer' })[method] || method
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
  rejected: 'danger',
  cancelled: 'danger',
  failed: 'danger',
  suspended: 'danger',
  disabled: 'danger',
}

function badgeTone(status) {
  return BADGE_TONES[String(status || '').toLowerCase().replace(/\s+/g, '_')] || 'neutral'
}

function Badge({ status, tone, children }) {
  const resolvedTone = tone || badgeTone(status)
  return <span className={`badge badge-${resolvedTone}`}>{children ?? status}</span>
}

function EmptyState({ title, message, icon: Icon }) {
  return (
    <div className="empty-state">
      {Icon && <Icon size={28} className="empty-state-icon" />}
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
          <Route path="/admin/dashboard" element={<Protected allowed={['super_admin']}><SuperAdminDashboard /></Protected>} />
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
        <Link className="brand" to={homeRoute}><span><Fish size={22} /></span>FishMarket</Link>
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
    buyer: [['Dashboard', '/buyer/dashboard?tab=overview', LayoutDashboard], ['Browse', '/buyer/dashboard?tab=browse', Search], ['Orders', '/buyer/dashboard?tab=orders', ShoppingCart], ['Messages', '/buyer/dashboard?tab=messages', MessageCircle], ['Notifications', '/buyer/dashboard?tab=notifications', Bell], ['AI Assistant', '/buyer/dashboard?tab=ai', Bot], ['Profile', '/buyer/dashboard?tab=settings', ShieldCheck]],
    seller: [['Dashboard', '/seller/dashboard?tab=overview', LayoutDashboard], ['Listings', '/seller/dashboard?tab=listings', Store], ['Orders', '/seller/dashboard?tab=orders', ShoppingCart], ['Messages', '/seller/dashboard?tab=messages', MessageCircle], ['Wallet', '/seller/dashboard?tab=wallet', Wallet], ['Notifications', '/seller/dashboard?tab=notifications', Bell], ['Analytics', '/seller/dashboard?tab=analytics', BarChart3], ['Profile', '/seller/dashboard?tab=profile', ShieldCheck]],
    lgu_admin: [['Dashboard', '/lgu/dashboard?tab=overview', LayoutDashboard], ['Approvals', '/lgu/dashboard?tab=approvals', CheckCircle], ['Reports', '/lgu/dashboard?tab=reports', BarChart3], ['Reviews', '/lgu/dashboard?tab=reviews', Star]],
    super_admin: [['Dashboard', '/admin/dashboard?tab=overview', LayoutDashboard], ['LGU Admins', '/admin/dashboard?tab=lgu-admins', ShieldCheck], ['Transactions', '/admin/dashboard?tab=transactions', Wallet], ['Reports', '/admin/dashboard?tab=reports', BarChart3]],
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
        <Link className="brand" to={homeRoute}><span><Fish size={22} /></span>FishMarket</Link>
        <div className="profile-chip">
          <Avatar src={user.profile_picture} alt={user.name} className="profile-chip-avatar" />
          <div className="profile-chip-info">
            <strong className="profile-chip-name">{user.name}</strong>
            <span className="profile-chip-meta">
              <Badge tone="neutral">{roleLabel(user.role)}</Badge>
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
        <div>
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
      <footer>FishMarket - LGU, Sellers, and Fish Farmers working together for local aquaculture.</footer>
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

function ListingDetailPanel({ item, isBuyer = false, checkout, qty, setQty, onPay }) {
  const navigate = useNavigate()
  const session = getSession()
  const outOfStock = Number(item.quantity) <= 0
  const safeQty = Math.min(Math.max(Number(qty) || 1, 1), Number(item.quantity) || 1)
  const sellerUserId = item.sellerProfile?.user_id
  const canChat = sellerUserId && (!session || session.role === 'buyer')
  const chatSeller = () => {
    const chatPath = `/buyer/dashboard?tab=messages&with=${sellerUserId}`
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
            <button onClick={onPay} type="button" disabled={outOfStock}>{outOfStock ? 'Out of Stock' : 'Pay with PayMongo'}</button>
          </div>
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
      <h4>{title}</h4>
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
  if (isError || !item) return <main className="auth-page"><section className="auth-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to="/browse">Back to Browse</Link></section></main>

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

  if (isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (isError || !item) return <main className="auth-page"><section className="auth-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to={`/buyer/dashboard?tab=${sourceTab}`}>Back to Browse</Link></section></main>

  return (
    <main className="detail-page">
      <img className="detail-art" src={resolveListingImage(item)} alt={item.title || item.species} />
      <div className="detail-stack">
        <ListingDetailPanel item={item} isBuyer checkout={buyListing} qty={qty} setQty={setQty} onPay={() => buyListing.mutate()} />
        <Link className="ghost" to={`/buyer/dashboard?tab=${sourceTab}`}>Back to Browse</Link>
      </div>
    </main>
  )
}

function LoginPage() {
  const { register, handleSubmit } = useForm({ defaultValues: { email: '', password: '' } })
  const navigate = useNavigate()
  const location = useLocation()
  const session = getSession()
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
          throw new Error(err.response.data?.message || 'Invalid credentials.', { cause: err })
        }
        const user = demoUsers[values.email]
        if (!user) throw new Error('Invalid demo credentials.', { cause: err })
        return { user, token: `demo-${user.role}` }
      }
    },
    onSuccess: ({ user, token }) => {
      localStorage.setItem('fishmarket_user', JSON.stringify(user))
      localStorage.setItem('fishmarket_token', token)
      window.location.replace(location.state?.from || roleRoutes[user.role] || '/')
    },
  })
  return <AuthCard title="Login" subtitle="One account gateway for all FishMarket roles."><form onSubmit={handleSubmit((v) => login.mutate(v))} className="form"><input {...register('email')} placeholder="Email" /><input {...register('password')} type="password" placeholder="Password" /><button type="submit">Login</button>{login.error && <p className="error">{login.error.message}</p>}<small>Buyers and sellers register for an account. LGU and Super Admin accounts are pre-created by the platform.</small></form></AuthCard>
}

function RegisterPage() {
  const { register, handleSubmit } = useForm({ defaultValues: { role: 'buyer' } })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
    placeholderData: [],
  })
  const registerUser = useMutation({
    mutationFn: async (values) => (await api.post('/auth/register', values)).data,
    onSuccess: ({ user, token }) => {
      localStorage.setItem('fishmarket_user', JSON.stringify(user))
      localStorage.setItem('fishmarket_token', token)
      window.location.replace(roleRoutes[user.role] || '/')
    },
  })
  return <AuthCard title="Register" subtitle="Registration is available only for buyers and sellers."><form onSubmit={handleSubmit((v) => registerUser.mutate(v))} className="form"><input {...register('name')} placeholder="Full name / Hatchery name" /><input {...register('email')} placeholder="Email" /><input {...register('password')} type="password" placeholder="Password" /><select {...register('role')}><option value="buyer">Buyer / Fish Farmer</option><option value="seller">Seller / Hatchery</option></select><select {...register('municipality_id', { required: true })} defaultValue=""><option value="" disabled>Select municipality</option>{(municipalitiesQuery.data || []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select><button type="submit">Create Account</button>{registerUser.error && <p className="error">Backend registration requires the API server. Run start-backend.cmd.</p>}</form></AuthCard>
}

function AuthCard({ title, subtitle, children }) {
  return <main className="auth-page"><section className="auth-card"><p className="eyebrow">FishMarket Access</p><h1>{title}</h1><p>{subtitle}</p>{children}</section></main>
}

function BuyerDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [filters, setFilters] = useState({ q: '', species: 'All', municipality: 'All' })
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

  const tabs = [
    ['overview', 'Dashboard'],
    ['browse', 'Browse'],
    ['orders', 'Orders'],
    ['messages', 'Messages'],
    ['notifications', 'Notifications'],
    ['ai', 'AI Assistant'],
    ['settings', 'Settings'],
  ]

  const listingsQuery = useQuery({
    queryKey: ['listings'],
    queryFn: async () => (await api.get('/listings')).data.map(mapListing),
    retry: false,
    placeholderData: [],
  })
  const browseItems = (listingsQuery.data || []).filter((item) => {
    const haystack = `${item.title} ${item.species} ${item.seller} ${item.municipality}`.toLowerCase()
    return haystack.includes(filters.q.toLowerCase()) && (filters.species === 'All' || item.species === filters.species) && (filters.municipality === 'All' || item.municipality === filters.municipality)
  })

  return (
    <Dashboard
      title="Buyer Dashboard"
      subtitle="Browse, order, pay, review, and track notifications."
      actions={<TabBar active={tab} tabs={tabs} setSearchParams={setSearchParams} />}
    >
      {tab === 'overview' && (
        <>
          <StatsRow items={[['Active Orders', data?.active_orders ?? 0], ['Completed Orders', data?.completed_orders ?? 0], ['Unread Messages', data?.unread_messages ?? 0]]} />
          <Section title="Recent Orders"><OrderTable rows={orders} onReview={handleReview} /></Section>
          <Section title="Notifications"><NotificationStack notifications={notifications.slice(0, 3)} onMarkRead={handleMarkRead} /></Section>
        </>
      )}
      {tab === 'browse' && (
        <Section title="Browse Listings">
          <div className="buyer-browse">
            <div className="filter-card inline">
              <label className="filter-label">Search<input placeholder="Search listings" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} /></label>
              <label className="filter-label">Species<select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value })}><option>All</option>{['Bangus', 'Tilapia', 'Grouper', 'Catfish', 'Sea Bass', 'Carp'].map((s) => <option key={s}>{s}</option>)}</select></label>
              <label className="filter-label">Municipality<select value={filters.municipality} onChange={(e) => setFilters({ ...filters, municipality: e.target.value })}><option>All</option>{['Mandaue', 'Consolacion', 'Compostela', 'Talisay', 'Lapu-Lapu', 'Carmen'].map((s) => <option key={s}>{s}</option>)}</select></label>
            </div>
            {browseItems.length ? (
              <ListingGrid items={browseItems} detailPath={(item) => `/buyer/listings/${item.id}?source=browse`} />
            ) : (
              <EmptyState message="No listings available yet. Check back soon as verified sellers add their stock." />
            )}
          </div>
        </Section>
      )}
      {tab === 'orders' && <Section title="My Orders"><OrderTable rows={orders} onReview={handleReview} /></Section>}
      {tab === 'messages' && <Section title="Messages"><MessagesPanel initialUserId={searchParams.get('with') ? Number(searchParams.get('with')) : null} /></Section>}
      {tab === 'notifications' && <Section title="Notifications"><NotificationStack notifications={notifications} onMarkRead={handleMarkRead} /></Section>}
      {tab === 'ai' && (
        <Section title="AI Assistant">
          <p>Use the floating Gemini assistant at the bottom-right. It stays available on every page and keeps your buyer session intact.</p>
          <div className="action-grid">
            <div className="card ai-roadmap">
              <strong>Species & Quantity Advisor</strong>
              <p>Recommends fingerling species and stocking quantity based on your pond space, budget, and water source.</p>
              <span className="pill muted-pill">Coming Soon</span>
            </div>
            <div className="card ai-roadmap">
              <strong>Disease Diagnosis</strong>
              <p>Upload symptoms or photos for AI-assisted disease detection and treatment guidance.</p>
              <span className="pill muted-pill">Coming Soon</span>
            </div>
            <div className="card ai-roadmap">
              <strong>Growth Tracker</strong>
              <p>Log feeding schedules and growth milestones with AI-generated tips and alerts.</p>
              <span className="pill muted-pill">Coming Soon</span>
            </div>
            <div className="card ai-roadmap">
              <strong>Market & ROI Insights</strong>
              <p>See current fingerling market prices, best buying times, and ROI estimates.</p>
              <span className="pill muted-pill">Coming Soon</span>
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
    if (newPassword.length < 8) {
      setLocalError('New password must be at least 8 characters.')
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
      <input type="password" placeholder="New password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} />
      <input type="password" placeholder="Confirm new password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} />
      <button type="button" onClick={submit} disabled={changePassword.isPending || !currentPassword || !newPassword}>{changePassword.isPending ? 'Saving...' : 'Change Password'}</button>
      {localError && <p className="error">{localError}</p>}
      {changePassword.isSuccess && <p className="helper-text">Password updated.</p>}
      {changePassword.error && <p className="error">{changePassword.error.response?.data?.message || 'Could not update password.'}</p>}
    </div>
  )
}

function SellerDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
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
  const analytics = useQuery({
    queryKey: ['seller-analytics'],
    queryFn: async () => (await api.get('/seller/analytics')).data,
    retry: false,
    placeholderData: { sales_by_month: [], top_species: [] },
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
      gallery: form.gallery.split('\n').map((url) => url.trim()).filter(Boolean),
    })).data,
    onSuccess: (result) => {
      updateSessionUser({ name: result.user.name, email: result.user.email, phone: result.user.phone })
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })
  const tabs = [['overview', 'Dashboard'], ['listings', 'Listings'], ['orders', 'Orders'], ['messages', 'Messages'], ['wallet', 'Wallet'], ['notifications', 'Notifications'], ['analytics', 'Analytics'], ['profile', 'Profile']]

  return (
    <Dashboard
      title="Seller Dashboard"
      subtitle="Manage listings, orders, and analytics."
      actions={<TabBar active={tab} tabs={tabs} setSearchParams={setSearchParams} />}
    >
      {tab === 'overview' && <StatsRow items={[['Active Listings', dashboard.data?.active_listings ?? 0], ['Pending Orders', dashboard.data?.pending_orders ?? 0], ['Total Sales', currency(dashboard.data?.total_sales ?? 0)], ['Unread Messages', dashboard.data?.unread_messages ?? 0]]} />}
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
        <Section title="Order Management">
          <SellerOrderTable
            rows={dashboard.data?.orders || []}
            onUpdateStatus={(orderId, status) => updateOrderStatus.mutateAsync({ orderId, status })}
          />
        </Section>
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
            <button type="button" onClick={() => requestWithdrawal.mutate()} disabled={requestWithdrawal.isPending}>{requestWithdrawal.isPending ? 'Submitting...' : 'Submit Withdrawal Request'}</button>
            {requestWithdrawal.error && <p className="error">{requestWithdrawal.error.response?.data?.message || 'Could not submit withdrawal request.'}</p>}
            {requestWithdrawal.isSuccess && <p className="helper-text">Withdrawal request submitted.</p>}
          </Section>
          <Section title="Withdrawal Requests">
            {(wallet.data?.withdrawal_requests || []).length ? (
              <div className="table">
                <div className="table-row first">
                  <span>Amount</span>
                  <span>Method</span>
                  <span>Account</span>
                  <span>Status</span>
                  <span>Requested</span>
                  <span>Notes</span>
                </div>
                {wallet.data.withdrawal_requests.map((request) => (
                  <div className="table-row" key={request.id}>
                    <span>{currency(request.amount)}</span>
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
      {tab === 'notifications' && <Section title="Notifications"><NotificationStack notifications={notifications} onMarkRead={handleMarkRead} /></Section>}
      {tab === 'analytics' && <Section title="Analytics"><StatsRow items={[['Completed Sales', currency(analytics.data?.total_completed_sales ?? 0)], ['Order Statuses', analytics.data?.order_status_breakdown?.length ?? 0], ['Top Species', analytics.data?.top_species?.[0]?.species || 'None'], ['Rating', `${dashboard.data?.ratings ?? 0}/5`]]} /></Section>}
      {tab === 'profile' && (
        <>
          <Section title="Seller Profile">
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
    gallery: (seller.gallery || []).join('\n'),
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
        <textarea value={form.gallery} onChange={(e) => setForm({ ...form, gallery: e.target.value })} placeholder="Additional farm photo URLs (one per line, optional)" />
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
  confirmed: [['in_transit', 'Mark In Transit'], ['cancelled', 'Cancel Order']],
  in_transit: [['completed', 'Mark Completed'], ['cancelled', 'Cancel Order']],
}

function SellerOrderTable({ rows, onUpdateStatus }) {
  if (!rows?.length) return <EmptyState message="No orders yet." />
  return <div className="item-list">{rows.map((order) => <SellerOrderRow key={order.id} order={order} onUpdateStatus={onUpdateStatus} />)}</div>
}

function SellerOrderRow({ order, onUpdateStatus }) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const transitions = ORDER_STATUS_TRANSITIONS[order.status] || []

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
    <div className="card action">
      <div>
        <strong>{order.order_number}</strong>
        <p>
          {order.listing?.title || order.listing?.species || 'Listing'} ·{' '}
          {order.buyer?.id ? <Link to={`/seller/buyers/${order.buyer.id}`}>{order.buyer.name}</Link> : (order.buyer?.name || 'Buyer')} ·{' '}
          {Number(order.quantity).toLocaleString()} pcs · {currency(order.total_amount)}
        </p>
        <Badge status={order.status} />
        {error && <p className="error">{error}</p>}
      </div>
      <div className="row-actions">
        {transitions.length === 0 ? (
          <span className="muted">No further action</span>
        ) : (
          transitions.map(([status, label]) => (
            <button key={status} type="button" className={status === 'cancelled' ? 'ghost danger' : ''} disabled={saving} onClick={() => applyStatus(status)}>
              {label}
            </button>
          ))
        )}
      </div>
    </div>
  )
}

function LguReviewCard({ review }) {
  const buyer = review.buyer
  const seller = review.sellerProfile
  const listing = review.order?.listing

  return (
    <div className="card review-item">
      <div className="card-row">
        <strong>{renderStars(review.rating)}</strong>
        <span className="muted">{Number(review.rating)}/5 · Submitted {new Date(review.created_at).toLocaleDateString()}</span>
      </div>
      {review.title && <p className="review-title">{review.title}</p>}
      <p>{review.comment || 'No comment left.'}</p>
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
        {review.order?.created_at && <span><strong>Transaction Date:</strong> {new Date(review.order.created_at).toLocaleDateString()}</span>}
      </div>
    </div>
  )
}

function LguDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const lgu = useQuery({
    queryKey: ['lgu-dashboard'],
    queryFn: async () => (await api.get('/lgu/dashboard')).data,
    retry: false,
    placeholderData: { registered_sellers: 24, active_listings: 87, pending_approvals: [], notifications: [] },
  })
  const reports = useQuery({
    queryKey: ['lgu-reports'],
    queryFn: async () => (await api.get('/lgu/reports')).data,
    retry: false,
    placeholderData: { registered_sellers: 24, listings: 87, pending_approvals: 5 },
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
    placeholderData: [],
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
    mutationFn: async (id) => (await api.patch(`/lgu/sellers/${id}/suspend`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-sellers'] }),
  })
  const reinstateSeller = useMutation({
    mutationFn: async (id) => (await api.patch(`/lgu/sellers/${id}/reinstate`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lgu-sellers'] }),
  })
  const pendingEarnings = useQuery({
    queryKey: ['lgu-earnings'],
    queryFn: async () => (await api.get('/lgu/earnings')).data,
    retry: false,
    placeholderData: [],
  })
  const approveEarnings = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`/lgu/payments/${paymentId}/approve`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lgu-earnings'] })
      queryClient.invalidateQueries({ queryKey: ['lgu-dashboard'] })
    },
  })
  const pendingEarningsCount = pendingEarnings.data?.length ?? 0
  const pendingEarningsAmount = (pendingEarnings.data || []).reduce((sum, payment) => sum + Number(payment.amount || 0), 0)

  const tabs = [['overview', 'Dashboard'], ['approvals', 'Approvals'], ['sellers', 'Sellers'], ['earnings', 'Seller Earnings'], ['users', 'Users'], ['reports', 'Reports'], ['reviews', 'Reviews'], ['notifications', 'Notifications']]

  return (
    <Dashboard
      title="LGU Admin Dashboard"
      subtitle="Municipality-scoped approvals, reports, and reviews."
      actions={<TabBar active={tab} tabs={tabs} setSearchParams={setSearchParams} />}
    >
      {tab === 'overview' && (
        <>
          <StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0], ['Listings', reports.data?.listings ?? 0], ['Pending Approvals', reports.data?.pending_approvals ?? 0]]} />
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
                    {!seller.verified && <button type="button" onClick={() => verifySeller.mutate(seller.id)}>Verify</button>}
                    {seller.status !== 'suspended' ? (
                      <button type="button" className="ghost danger" onClick={() => suspendSeller.mutate(seller.id)}>Suspend</button>
                    ) : (
                      <button type="button" onClick={() => reinstateSeller.mutate(seller.id)}>Reinstate</button>
                    )}
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
                <div className="card action" key={payment.id}>
                  <div>
                    <div className="card-row">
                      <Avatar src={payment.order?.sellerProfile?.profile_picture} alt={payment.order?.sellerProfile?.hatchery_name} className="listing-seller-avatar" />
                      <strong>{payment.order?.sellerProfile?.hatchery_name || payment.order?.sellerProfile?.user?.name || 'Unknown seller'}</strong>
                    </div>
                    <p>
                      Order #{payment.order?.order_number} · {payment.order?.listing?.title || payment.order?.listing?.species || 'Listing'} · Buyer: {payment.order?.buyer?.name || 'Unknown buyer'}
                    </p>
                    <p className="muted">{currency(payment.amount)} awaiting approval</p>
                  </div>
                  <div className="row-actions">
                    <button type="button" onClick={() => approveEarnings.mutate(payment.id)} disabled={approveEarnings.isPending}>Approve Earnings</button>
                  </div>
                </div>
              ))}
            </div>
          ) : <EmptyState message="No completed orders awaiting earnings approval." />}
          {approveEarnings.error && <p className="error">{approveEarnings.error.response?.data?.message || 'Could not approve earnings.'}</p>}
        </Section>
      )}
      {tab === 'users' && (
        <>
          <Section title="Buyers in Your Municipality">
            <DataTable rows={(usersDirectory.data?.buyers || []).map((user) => ({
              name: user.name,
              email: user.email,
              phone: user.phone || 'Not Available',
              status: { __badge: user.status || 'unknown' },
              joined: user.created_at ? new Date(user.created_at).toLocaleDateString() : 'Not Available',
            }))} />
          </Section>
          <Section title="Sellers in Your Municipality">
            <DataTable rows={(usersDirectory.data?.sellers || []).map((user) => ({
              name: user.name,
              email: user.email,
              phone: user.phone || 'Not Available',
              status: { __badge: user.status || 'unknown' },
              joined: user.created_at ? new Date(user.created_at).toLocaleDateString() : 'Not Available',
            }))} />
          </Section>
        </>
      )}
      {tab === 'reports' && <Section title="Reports"><StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0], ['Listings', reports.data?.listings ?? 0], ['Pending Approvals', reports.data?.pending_approvals ?? 0]]} /></Section>}
      {tab === 'reviews' && (
        <Section title="Reviews">
          {(reviews.data || []).length ? (
            <div className="review-list">
              {reviews.data.map((review) => <LguReviewCard key={review.id} review={review} />)}
            </div>
          ) : <EmptyState message="No reviews yet in your municipality." />}
        </Section>
      )}
      {tab === 'notifications' && <Section title="Notifications"><NotificationStack notifications={notifications} onMarkRead={handleMarkRead} getLink={notificationLink} /></Section>}
    </Dashboard>
  )
}

function LguListingReviewPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')

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

  const approve = useMutation({
    mutationFn: async () => (await api.patch(`/lgu/listings/${id}/approve`)).data,
    onSuccess: goToApprovals,
  })
  const reject = useMutation({
    mutationFn: async () => (await api.patch(`/lgu/listings/${id}/reject`, reason.trim() ? { reason: reason.trim() } : {})).data,
    onSuccess: goToApprovals,
  })

  if (listingQuery.isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (listingQuery.isError || !listing) {
    return (
      <main className="auth-page">
        <section className="auth-card">
          <h1>Listing not found</h1>
          <p>This listing may have been removed, or is outside your municipality.</p>
          <Link className="button" to="/lgu/dashboard?tab=approvals">Back to Approvals</Link>
        </section>
      </main>
    )
  }

  const seller = sellerQuery.data?.seller
  const careMedia = (sellerQuery.data?.listings || [])
    .filter((otherListing) => otherListing.id !== listing.id)
    .flatMap((otherListing) => otherListing.media || [])
  const busy = approve.isPending || reject.isPending

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

      <MediaGallery media={careMedia} />

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

      <Link className="ghost" to="/lgu/dashboard?tab=approvals">Back to Approvals</Link>
    </main>
  )
}

function SuperAdminDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [lguForm, setLguForm] = useState({ name: '', email: '', password: '', municipality_id: '' })
  const dashboard = useQuery({
    queryKey: ['super-admin-dashboard'],
    queryFn: async () => (await api.get('/super-admin/dashboard')).data,
    retry: false,
    placeholderData: { lgu_admins: 8, total_sellers: 142, transactions: [] },
  })
  const reports = useQuery({
    queryKey: ['super-admin-reports'],
    queryFn: async () => (await api.get('/super-admin/reports')).data,
    retry: false,
    placeholderData: { total_lgus: 8, total_sellers: 142, total_buyers: 1240, total_listings: 87, total_transactions: 18, pending_payouts: 18, transactions: [], lgu_admins: [] },
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
  const createLguAdmin = useMutation({
    mutationFn: async () => (await api.post('/super-admin/lgu-admins', lguForm)).data,
    onSuccess: () => {
      setLguForm({ name: '', email: '', password: '', municipality_id: '' })
      queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] })
    },
  })
  const updateLguAdmin = useMutation({
    mutationFn: async ({ id, payload }) => (await api.patch(`/super-admin/lgu-admins/${id}`, payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] }),
  })
  const disableLguAdmin = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/lgu-admins/${id}/disable`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] }),
  })
  const enableLguAdmin = useMutation({
    mutationFn: async (id) => (await api.patch(`/super-admin/lgu-admins/${id}/enable`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-lgu-admins'] }),
  })

  const tabs = [['overview', 'Dashboard'], ['lgu-admins', 'LGU Admins'], ['sellers', 'Sellers'], ['transactions', 'Transactions'], ['payouts', 'Seller Payouts'], ['reports', 'Reports']]

  return (
    <Dashboard
      title="Super Admin Dashboard"
      subtitle="Platform-wide control, transaction review, and payout release."
      actions={<TabBar active={tab} tabs={tabs} setSearchParams={setSearchParams} />}
    >
      {tab === 'overview' && <StatsRow items={[['Total LGUs', reports.data?.total_lgus ?? 0], ['Total Sellers', reports.data?.total_sellers ?? 0], ['Total Buyers', reports.data?.total_buyers ?? 0], ['Pending Payouts', reports.data?.pending_payouts ?? 0]]} />}
      {tab === 'lgu-admins' && (
        <>
          <Section title="Add LGU Admin">
            <div className="form grid-form">
              <input value={lguForm.name} onChange={(e) => setLguForm({ ...lguForm, name: e.target.value })} placeholder="Full name" />
              <input value={lguForm.email} onChange={(e) => setLguForm({ ...lguForm, email: e.target.value })} placeholder="Email" />
              <input value={lguForm.password} onChange={(e) => setLguForm({ ...lguForm, password: e.target.value })} type="password" placeholder="Temporary password" />
              <select value={lguForm.municipality_id} onChange={(e) => setLguForm({ ...lguForm, municipality_id: e.target.value })}>
                <option value="">Select municipality</option>
                {(municipalitiesQuery.data || []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
            <button type="button" onClick={() => createLguAdmin.mutate()}>Create LGU Admin</button>
            {createLguAdmin.error && <p className="error">{createLguAdmin.error.response?.data?.message || 'Could not create LGU admin.'}</p>}
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
                    onDisable={(id) => disableLguAdmin.mutate(id)}
                    onEnable={(id) => enableLguAdmin.mutate(id)}
                  />
                ))}
              </div>
            ) : <EmptyState message="No LGU admins registered yet." />}
          </Section>
        </>
      )}
      {tab === 'sellers' && (
        <Section title="All Sellers (Platform-Wide)">
          <DataTable rows={(sellersQuery.data || []).map((seller) => ({
            hatchery_name: seller.hatchery_name,
            municipality: seller.municipality?.name || 'Unknown',
            status: { __badge: seller.status },
            verified: seller.verified ? 'Yes' : 'No',
            listings: seller.listings?.length ?? 0,
            rating: seller.rating,
          }))} />
        </Section>
      )}
      {tab === 'transactions' && <Section title="All Transactions"><OrderTable rows={dashboard.data?.transactions || []} /></Section>}
      {tab === 'payouts' && (
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
      )}
      {tab === 'reports' && <Section title="Platform Reports"><StatsRow items={[['LGU Admins', reports.data?.total_lgus ?? 0], ['Transactions', reports.data?.total_transactions ?? 0], ['Pending Payouts', reports.data?.pending_payouts ?? 0], ['Listings', reports.data?.total_listings ?? 0]]} /></Section>}
    </Dashboard>
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
        {editing ? (
          <button type="button" onClick={save}>Save</button>
        ) : (
          <button type="button" className="ghost" onClick={() => setEditing(true)}>Edit</button>
        )}
        {admin.status !== 'disabled' ? (
          <button type="button" className="ghost danger" onClick={() => onDisable(admin.id)}>Disable</button>
        ) : (
          <button type="button" onClick={() => onEnable(admin.id)}>Enable</button>
        )}
      </div>
    </div>
  )
}

function WithdrawalRow({ request, onApprove, onReject, onMarkPaid }) {
  const [showReject, setShowReject] = useState(false)
  const [reason, setReason] = useState('')
  const seller = request.sellerProfile

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
          <Avatar src={seller?.profile_picture} alt={seller?.hatchery_name} className="listing-seller-avatar" />
          <strong>{seller?.hatchery_name || seller?.user?.name || 'Unknown seller'}</strong>
          <Badge status={request.status} />
        </div>
        <p>
          Request #{request.id} · {currency(request.amount)} via {withdrawalMethodLabel(request.method)}<br />
          {request.account_name} · {request.account_number}
        </p>
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
  return <div className="dashboard"><div className="dashboard-head"><div><p className="eyebrow">{subtitle}</p><h1>{title}</h1></div>{actions || <button><Menu size={18} /> Actions</button>}</div>{children}</div>
}

function TabBar({ active, tabs, setSearchParams }) {
  return <div className="tab-bar">{tabs.map(([value, label]) => <button key={value} type="button" className={active === value ? 'tab active' : 'tab'} onClick={() => setSearchParams(value === 'overview' ? {} : { tab: value })}>{label}</button>)}</div>
}

function StatsRow({ items }) {
  return <div className="stats-grid">{items.map(([label, value, highlight]) => <Stat key={label} label={label} value={value} highlight={highlight} />)}</div>
}

function Stat({ value, label, highlight = false }) {
  return <div className={highlight ? 'stat-card stat-card-highlight' : 'stat-card'}><strong>{value}</strong><span>{label}</span></div>
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

function OrderTable({ rows, onReview }) {
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

  return (
    <div className="table">
      <div className="table-row first">
        <span>Order Name</span>
        <span>Order #</span>
        <span>Seller</span>
        <span>Qty</span>
        <span>Status</span>
        <span>Payment</span>
        {onReview && <span>Review</span>}
      </div>
      {normalized.map((row) => (
        <div className="table-row" key={row.id}>
          <span>{row.order_name}</span>
          <span>{row.order_number}</span>
          <span className="order-seller-cell">
            <Avatar src={row.seller_avatar} alt={row.seller_name} className="order-seller-avatar" />
            {row.seller_name}{row.seller_contact_name ? ` (${row.seller_contact_name})` : ''}
          </span>
          <span>{Number(row.quantity).toLocaleString()}</span>
          <span><Badge status={row.status} /></span>
          <span><Badge status={row.payment_status} /></span>
          {onReview && <ReviewCell row={row} onReview={onReview} />}
        </div>
      ))}
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
      <section className="auth-card success-card">
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
  return <main className="auth-page"><section className="auth-card"><p className="eyebrow">Payment Declined</p><h1>Card payment declined</h1><p>{orderNumber ? `Payment for order #${orderNumber} was declined or expired. The order is marked failed and your stock reservation has been restored.` : 'Your session is still active. You can continue browsing or try again.'}</p><div className="success-actions"><button className="button" type="button" onClick={() => navigate('/buyer/dashboard?tab=browse')}>Return to Merchant</button><Link className="ghost" to="/buyer/dashboard?tab=notifications">Open Notifications</Link></div></section></main>
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
  const allMedia = (data.listings || []).flatMap((item) => item.media || [])
  const galleryMedia = (seller.gallery || []).map((url, index) => ({ id: `gallery-${index}`, type: 'photo', title: `Farm photo ${index + 1}`, url }))
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
        {seller.description && <p className="listing-description">{seller.description}</p>}
        <div className="detail-meta">
          {seller.user?.name && seller.user.name !== seller.hatchery_name && <span><strong>Seller Name:</strong> {seller.user.name}</span>}
          <span><strong>Municipality:</strong> {seller.municipality?.name || 'Unknown'}</span>
          <span><strong>Address:</strong> {seller.address || 'Not provided'}</span>
          <span><strong>Contact:</strong> {seller.user?.phone || 'Not provided'}</span>
          <span><strong>Email:</strong> {seller.user?.email}</span>
          {seller.years_experience != null && <span><strong>Experience:</strong> {seller.years_experience} year{seller.years_experience === 1 ? '' : 's'}</span>}
        </div>
        {seller.user_id && (!session || session.role === 'buyer') && (
          <button
            className="button"
            type="button"
            onClick={() => {
              const chatPath = `/buyer/dashboard?tab=messages&with=${seller.user_id}`
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
      <Section title="Available Stock">
        {sellerListings.length ? (
          <ListingGrid
            items={sellerListings}
            detailPath={session?.role === 'buyer' ? (item) => `/buyer/listings/${item.id}?source=browse` : undefined}
          />
        ) : <EmptyState message="No listings available from this seller yet." />}
      </Section>
      <MediaGallery media={allMedia} />
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
                <p className="review-author"><Avatar src={review.buyer?.profile_picture} alt={review.buyer?.name} className="review-avatar" />{review.buyer?.name || 'FishMarket Buyer'}</p>
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
        <section className="auth-card">
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

  return (
    <main className="seller-profile-page">
      <section className="card seller-profile-header">
        <div className="seller-header-row">
          <Avatar src={buyer.profile_picture} alt={buyer.name} className="seller-avatar" />
          <div>
            <div className="card-row"><h1>{buyer.name}</h1></div>
            <div className="stats-inline">
              <Stat value={stats.total_orders} label="Orders with you" />
              <Stat value={stats.completed_orders} label="Completed" />
              <Stat value={stats.pending_orders} label="Pending" />
            </div>
          </div>
        </div>
        <div className="detail-meta">
          <span><strong>Municipality:</strong> {buyer.municipality?.name || 'Not provided'}</span>
          <span><strong>Member Since:</strong> {new Date(buyer.created_at).toLocaleDateString()}</span>
          <span><strong>Total Spent:</strong> {currency(stats.total_spent)}</span>
          <span><strong>Most Recent Purchase:</strong> {stats.most_recent_purchase ? new Date(stats.most_recent_purchase).toLocaleDateString() : 'None yet'}</span>
        </div>
        <button className="button" type="button" onClick={() => navigate(`/seller/dashboard?tab=messages&with=${buyer.id}`)}>
          <MessageCircle size={16} /> Message Buyer
        </button>
      </section>
      <Section title="Reviews from this Buyer">
        {reviews.length ? (
          <div className="review-list">
            {reviews.map((review) => (
              <div className="card review-item" key={review.id}>
                <div className="card-row">
                  <strong>{renderStars(review.rating)}</strong>
                  <span className="muted">{new Date(review.created_at).toLocaleDateString()}</span>
                </div>
                {review.title && <p className="review-title">{review.title}</p>}
                <p>{review.comment || 'No comment left.'}</p>
              </div>
            ))}
          </div>
        ) : <EmptyState message="This buyer hasn't left a review yet." />}
      </Section>
    </main>
  )
}

function AboutPage({ compact = false }) {
  return <Section title="About the Platform"><div className="about-card"><p>FishMarket is a web-based marketplace for local fingerling supply. It supports buyers, hatcheries, LGU admins, and platform admins with role-based dashboards, listing approval, PayMongo checkout, messaging, reviews, notifications, and Gemini AI farming assistance.</p>{!compact && <Link className="button" to="/register">Join FishMarket</Link>}</div></Section>
}

function Section({ title, children }) {
  return <section className="section"><h2>{title}</h2>{children}</section>
}

function Step({ n, t, d }) {
  return <div className="card step"><span>{n}</span><h3>{t}</h3><p>{d}</p></div>
}

function FloatingAi() {
  const [open, setOpen] = useState(false)
  const [language, setLanguage] = useState('Bisaya')
  const [message, setMessage] = useState('Unsay maayo nga species para beginner?')
  const [chat, setChat] = useState([{ role: 'ai', text: 'Ask FishMarket AI about species, feeding, disease signs, ROI, or market timing.' }])
  const ask = useMutation({
    mutationFn: async () => {
      try {
        return (await api.post('/ai-assistant/ask', { language, question: message })).data.response
      } catch {
        return language === 'Tagalog' ? 'Gabay: Tilapia is a good beginner option. Monitor water quality and avoid overcrowding.' : 'Tambag: Tilapia maayo para beginner. Bantayi ang tubig, pagkaon, ug stocking density.'
      }
    },
    onSuccess: (response) => {
      setChat([...chat, { role: 'user', text: message }, { role: 'ai', text: response }])
      setMessage('')
    },
  })
  return <div className="ai-widget"><button className="ai-toggle" onClick={() => setOpen(!open)} type="button"><Bot size={20} /> AI</button>{open && <div className="ai-panel"><h3>Gemini Fish Farming Assistant</h3><select value={language} onChange={(e) => setLanguage(e.target.value)}><option value="English">English</option><option value="Tagalog">Tagalog</option><option value="Bisaya">Bisaya</option></select><div className="chat-log">{chat.map((m, i) => <p className={m.role} key={`${m.role}-${i}`}>{m.text}</p>)}</div><textarea value={message} onChange={(e) => setMessage(e.target.value)} /><button onClick={() => ask.mutate()} type="button">Ask Gemini</button></div>}</div>
}

export default App
