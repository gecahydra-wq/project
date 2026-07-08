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
  Fish,
  LayoutDashboard,
  LogOut,
  Menu,
  Search,
  ShieldCheck,
  ShoppingCart,
  Star,
  Store,
  Wallet,
} from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import './App.css'

const queryClient = new QueryClient()
const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

const api = axios.create({ baseURL: API_URL })
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('fishmarket_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

const roleRoutes = {
  buyer: '/buyer/dashboard',
  seller: '/seller/dashboard',
  lgu_admin: '/lgu/dashboard',
  super_admin: '/admin/dashboard',
}

const demoUsers = {
  'buyer@fishmarket.test': { id: 1, name: 'Maria B.', role: 'buyer', email: 'buyer@fishmarket.test', municipality: 'Mandaue' },
  'seller@fishmarket.test': { id: 4, name: "Juan's Hatchery", role: 'seller', email: 'seller@fishmarket.test', municipality: 'Mandaue' },
  'lgu@fishmarket.test': { id: 2, name: 'Admin Cruz', role: 'lgu_admin', email: 'lgu@fishmarket.test', municipality: 'Mandaue' },
  'super@fishmarket.test': { id: 3, name: 'Super Admin', role: 'super_admin', email: 'super@fishmarket.test', municipality: 'All LGUs' },
}

const listings = [
  { id: 1, species: 'Bangus', title: 'Bangus Fingerlings', seller: "Juan's Hatchery", municipality: 'Mandaue', price: 3.5, quantity: 5000, status: 'Approved', rating: 4.8, image: 'BO', description: 'Healthy 4-5 inch bangus fingerlings raised in monitored nursery tanks.' },
  { id: 2, species: 'Tilapia', title: 'Tilapia Fingerlings', seller: 'BlueLake Hatchery', municipality: 'Consolacion', price: 3, quantity: 10000, status: 'Approved', rating: 4.6, image: 'TI', description: 'Fast-growing tilapia stock suitable for beginner pond farmers.' },
  { id: 3, species: 'Grouper', title: 'Grouper Fingerlings', seller: 'IslaFin Aqua', municipality: 'Compostela', price: 5, quantity: 1200, status: 'Pending', rating: 4.7, image: 'GR', description: 'Limited grouper fingerlings for coastal aquaculture setups.' },
  { id: 4, species: 'Catfish', title: 'Catfish Fingerlings', seller: 'CebuFish Farm', municipality: 'Talisay', price: 3.8, quantity: 3000, status: 'Approved', rating: 4.5, image: 'CA', description: 'Hardy catfish fingerlings with flexible feeding requirements.' },
  { id: 5, species: 'Sea Bass', title: 'Sea Bass Fingerlings', seller: 'IslaFin Aqua', municipality: 'Lapu-Lapu', price: 5, quantity: 800, status: 'Approved', rating: 4.4, image: 'SB', description: 'Sea bass stock with care videos and water-quality records.' },
  { id: 6, species: 'Carp', title: 'Carp Fingerlings', seller: 'Carmen Hatchery', municipality: 'Carmen', price: 3.2, quantity: 6500, status: 'Approved', rating: 4.3, image: 'CP', description: 'Affordable carp fingerlings for freshwater pond operations.' },
]

const sellers = [
  { name: "Juan's Hatchery", municipality: 'Mandaue', rating: 4.8, verified: true, listings: 12 },
  { name: 'BlueLake Hatchery', municipality: 'Consolacion', rating: 4.6, verified: true, listings: 9 },
  { name: 'IslaFin Aqua', municipality: 'Lapu-Lapu', rating: 4.4, verified: false, listings: 7 },
]

const orders = [
  { id: 'FG-0041', species: 'Bangus', qty: 2000, seller: "Juan's Hatchery", amount: 7000, status: 'Paid - Held', date: 'Mar 8' },
  { id: 'FG-0038', species: 'Tilapia', qty: 5000, seller: 'BlueLake Hatchery', amount: 15000, status: 'Completed', date: 'Feb 25' },
  { id: 'FG-0036', species: 'Catfish', qty: 900, seller: 'CebuFish Farm', amount: 3420, status: 'In Transit', date: 'Feb 12' },
]

function currency(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

function getSession() {
  const stored = localStorage.getItem('fishmarket_user')
  return stored ? JSON.parse(stored) : null
}

function getHomeRoute() {
  const session = getSession()
  return session?.role ? roleRoutes[session.role] || '/' : '/'
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
          <Route path="/payment-success" element={<PaymentSuccessPage />} />
          <Route path="/payment-cancelled" element={<PaymentCancelledPage />} />
        </Route>
          <Route path="/buyer/dashboard" element={<Protected allowed={['buyer']}><BuyerDashboard /></Protected>} />
          <Route path="/buyer/listings/:id" element={<Protected allowed={['buyer']}><BuyerListingDetailPage /></Protected>} />
          <Route path="/seller/dashboard" element={<Protected allowed={['seller']}><SellerDashboard /></Protected>} />
          <Route path="/lgu/dashboard" element={<Protected allowed={['lgu_admin']}><LguDashboard /></Protected>} />
          <Route path="/admin/dashboard" element={<Protected allowed={['super_admin']}><SuperAdminDashboard /></Protected>} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

function PublicLayout() {
  const homeRoute = getHomeRoute()
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
        <div className="nav-actions">
          <Link className="ghost" to="/login">Login</Link>
          <Link className="button" to="/register">Register</Link>
        </div>
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
    buyer: [['Dashboard', '/buyer/dashboard?tab=overview', LayoutDashboard], ['Browse', '/buyer/dashboard?tab=browse', Search], ['Orders', '/buyer/dashboard?tab=orders', ShoppingCart], ['Saved', '/buyer/dashboard?tab=saved', Star], ['Notifications', '/buyer/dashboard?tab=notifications', Bell], ['AI Assistant', '/buyer/dashboard?tab=ai', Bot]],
    seller: [['Dashboard', '/seller/dashboard?tab=overview', LayoutDashboard], ['Listings', '/seller/dashboard?tab=listings', Store], ['Orders', '/seller/dashboard?tab=orders', ShoppingCart], ['Analytics', '/seller/dashboard?tab=analytics', BarChart3], ['Profile', '/seller/dashboard?tab=profile', ShieldCheck]],
    lgu_admin: [['Dashboard', '/lgu/dashboard?tab=overview', LayoutDashboard], ['Approvals', '/lgu/dashboard?tab=approvals', CheckCircle], ['Reports', '/lgu/dashboard?tab=reports', BarChart3], ['Reviews', '/lgu/dashboard?tab=reviews', Star]],
    super_admin: [['Dashboard', '/admin/dashboard?tab=overview', LayoutDashboard], ['LGU Admins', '/admin/dashboard?tab=lgu-admins', ShieldCheck], ['Transactions', '/admin/dashboard?tab=transactions', Wallet], ['Reports', '/admin/dashboard?tab=reports', BarChart3]],
  }[user.role]

  function logout() {
    localStorage.removeItem('fishmarket_user')
    localStorage.removeItem('fishmarket_token')
    navigate('/login')
  }

  return (
    <div className="shell">
      <aside className="sidebar">
        <Link className="brand" to={homeRoute}><span><Fish size={22} /></span>FishMarket</Link>
        <div className="profile-chip">
          <strong>{user.name}</strong>
          <span>{roleLabel(user.role)} - {user.municipality}</span>
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
          <Stat value="87" label="Active listings" />
          <Stat value="24" label="Verified sellers" />
          <Stat value="P1.2M" label="Escrow tracked" />
        </div>
      </section>
      <Section title="Featured Listings"><ListingGrid items={listings.slice(0, 3)} /></Section>
      <Section title="Featured Sellers"><SellerGrid /></Section>
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
    queryFn: async () => {
      const response = await api.get('/listings')
      return response.data.map(item => ({
        ...item,
        image: item.species?.slice(0, 2),
        seller: item.sellerProfile?.hatchery_name || item.seller_profile_id,
        municipality: item.municipality?.name || 'Unknown',
        price: item.price_per_piece,
        status: item.approval_status === 'approved' ? 'Approved' : item.approval_status === 'pending' ? 'Pending' : 'Rejected',
        rating: item.sellerProfile?.rating || 4.5,
        description: 'Quality fingerlings from verified hatchery.'
      }))
    },
    retry: false, 
    placeholderData: listings 
  })
  const filtered = data.filter((item) => {
    const haystack = `${item.title} ${item.species} ${item.seller} ${item.municipality}`.toLowerCase()
    return haystack.includes(filters.q.toLowerCase()) && (filters.species === 'All' || item.species === filters.species) && (filters.municipality === 'All' || item.municipality === filters.municipality)
  })
  return (
    <main className="page-grid">
      <aside className="filter-card">
        <h2>Advanced Filters</h2>
        <input placeholder="Search listings" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} />
        <select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value })}><option>All</option>{['Bangus', 'Tilapia', 'Grouper', 'Catfish', 'Sea Bass', 'Carp'].map((s) => <option key={s}>{s}</option>)}</select>
        <select value={filters.municipality} onChange={(e) => setFilters({ ...filters, municipality: e.target.value })}><option>All</option>{['Mandaue', 'Consolacion', 'Compostela', 'Talisay', 'Lapu-Lapu', 'Carmen'].map((s) => <option key={s}>{s}</option>)}</select>
      </aside>
      <ListingGrid items={filtered} />
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
      <div className="listing-image">{item.image || item.species?.slice(0, 2)}</div>
      <div className="card-row"><h3>{item.title}</h3><span className="pill">{item.status}</span></div>
      <p>{item.seller} - {item.municipality}</p>
      <div className="card-row"><strong>{currency(item.price)}/pc</strong><span>{Number(item.quantity).toLocaleString()} pcs</span></div>
      {mode === 'saved' || mode === 'buyer' ? (
        <button className="button full" type="button" onClick={() => onSelect?.(item)}>{mode === 'saved' ? 'Open Saved Listing' : 'View Details'}</button>
      ) : (
        <Link className="button full" to={linkTarget}>View Details</Link>
      )}
    </article>
  )
}

function ListingDetailPanel({ item, isBuyer = false, checkout, qty, setQty, onPay }) {
  const safeQty = Math.min(Math.max(Number(qty) || 1, 1), Number(item.quantity) || 1)
  return (
    <article className="card listing-detail-panel">
      <div className="card-row">
        <h3>{item.title}</h3>
        <span className="pill">{item.status}</span>
      </div>
      <p>{item.description}</p>
      <div className="stats-inline">
        <Stat value={currency(item.price)} label="Per piece" />
        <Stat value={item.quantity.toLocaleString()} label="Available" />
        <Stat value={`${item.rating}/5`} label="Seller rating" />
      </div>
      <div className="detail-meta">
        <span><strong>Seller:</strong> {item.seller}</span>
        <span><strong>Municipality:</strong> {item.municipality}</span>
      </div>
      {isBuyer && (
        <>
          <label>Quantity<input type="number" min="1" max={item.quantity} value={qty} onChange={(e) => setQty(e.target.value)} /></label>
          <div className="checkout-bar">
            <strong>Total: {currency(safeQty * item.price)}</strong>
            <button onClick={onPay} type="button">Pay with PayMongo</button>
          </div>
          {checkout?.error && <p className="error">{checkout.error.message}</p>}
        </>
      )}
      {!isBuyer && <p className="helper-text">Payment is reserved for buyer accounts only.</p>}
    </article>
  )
}

function ListingDetailPage() {
  const { id } = useParams()
  const session = getSession()
  const isBuyer = session?.role === 'buyer'
  const [qty, setQty] = useState(1)
  
  const { data: item = listings[0] } = useQuery({
    queryKey: ['listing', id],
    queryFn: async () => {
      try {
        const response = await api.get(`/listings/${id}`)
        return {
          ...response.data,
          image: response.data.species?.slice(0, 2),
          seller: response.data.sellerProfile?.hatchery_name,
          municipality: response.data.municipality?.name,
          price: response.data.price_per_piece,
          status: response.data.approval_status === 'approved' ? 'Approved' : 'Pending',
          rating: response.data.sellerProfile?.rating || 4.5,
          description: 'Quality fingerlings from verified hatchery.'
        }
      } catch {
        return listings.find((listing) => String(listing.id) === id) || listings[0]
      }
    },
    retry: false
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
  return (
    <main className="detail-page">
      <div className="detail-art">{item.species}</div>
      <ListingDetailPanel item={item} isBuyer={isBuyer} checkout={checkout} qty={qty} setQty={setQty} onPay={() => checkout.mutate()} />
    </main>
  )
}

function BuyerListingDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const sourceTab = searchParams.get('source') || 'browse'
  const [qty, setQty] = useState(1)
  const { data: item = listings[0] } = useQuery({
    queryKey: ['buyer-listing', id],
    queryFn: async () => {
      try {
        const response = await api.get(`/listings/${id}`)
        return {
          ...response.data,
          image: response.data.species?.slice(0, 2),
          seller: response.data.sellerProfile?.hatchery_name,
          municipality: response.data.municipality?.name,
          price: response.data.price_per_piece,
          status: response.data.approval_status === 'approved' ? 'Approved' : 'Pending',
          rating: response.data.sellerProfile?.rating || 4.5,
          description: 'Quality fingerlings from verified hatchery.'
        }
      } catch {
        return listings.find((listing) => String(listing.id) === id) || listings[0]
      }
    },
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
  return (
    <main className="detail-page">
      <div className="detail-art">{item.species}</div>
      <div className="detail-stack">
        <ListingDetailPanel item={item} isBuyer checkout={buyListing} qty={qty} setQty={setQty} onPay={() => buyListing.mutate()} />
        <Link className="ghost" to={`/buyer/dashboard?tab=${sourceTab}`}>Back to {sourceTab === 'saved' ? 'Saved' : 'Browse'}</Link>
      </div>
    </main>
  )
}

function LoginPage() {
  const { register, handleSubmit } = useForm({ defaultValues: { email: 'buyer@fishmarket.test', password: 'password' } })
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
      } catch {
        const user = demoUsers[values.email]
        if (!user) throw new Error('Invalid demo credentials.')
        return { user, token: `demo-${user.role}` }
      }
    },
    onSuccess: ({ user, token }) => {
      localStorage.setItem('fishmarket_user', JSON.stringify(user))
      localStorage.setItem('fishmarket_token', token)
      window.location.replace(location.state?.from || roleRoutes[user.role] || '/')
    },
  })
  return <AuthCard title="Login" subtitle="One account gateway for all FishMarket roles."><form onSubmit={handleSubmit((v) => login.mutate(v))} className="form"><input {...register('email')} placeholder="Email" /><input {...register('password')} type="password" placeholder="Password" /><button type="submit">Login</button>{login.error && <p className="error">{login.error.message}</p>}<small>Try buyer, seller, lgu, or super demo emails from the README. Password: password.</small></form></AuthCard>
}

function RegisterPage() {
  const { register, handleSubmit } = useForm({ defaultValues: { role: 'buyer' } })
  const registerUser = useMutation({
    mutationFn: async (values) => (await api.post('/auth/register', values)).data,
    onSuccess: ({ user, token }) => {
      localStorage.setItem('fishmarket_user', JSON.stringify(user))
      localStorage.setItem('fishmarket_token', token)
      window.location.replace(roleRoutes[user.role] || '/')
    },
  })
  return <AuthCard title="Register" subtitle="Registration is available only for buyers and sellers."><form onSubmit={handleSubmit((v) => registerUser.mutate(v))} className="form"><input {...register('name')} placeholder="Full name / Hatchery name" /><input {...register('email')} placeholder="Email" /><input {...register('password')} type="password" placeholder="Password" /><select {...register('role')}><option value="buyer">Buyer / Fish Farmer</option><option value="seller">Seller / Hatchery</option></select><button type="submit">Create Account</button>{registerUser.error && <p className="error">Backend registration requires the API server. Run start-backend.cmd.</p>}</form></AuthCard>
}

function AuthCard({ title, subtitle, children }) {
  return <main className="auth-page"><section className="auth-card"><p className="eyebrow">FishMarket Access</p><h1>{title}</h1><p>{subtitle}</p>{children}</section></main>
}

function BuyerDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [filters, setFilters] = useState({ q: '', species: 'All', municipality: 'All' })
  const [visibleNotificationIds, setVisibleNotificationIds] = useState([])
  const { data } = useQuery({
    queryKey: ['buyer-dashboard'],
    queryFn: async () => (await api.get('/buyer/dashboard')).data,
    retry: false,
    placeholderData: {
      active_orders: 2,
      completed_orders: 8,
      saved_listings: 15,
      unread_messages: 0,
      notifications: [],
      recent_orders: [],
      recent_reviews: [],
    },
  })

  const orders = data?.recent_orders || []
  const notifications = (data?.notifications || []).filter((notification) => !visibleNotificationIds.includes(notification.id))
  const savedItems = listings.slice(0, 3)
  const handleMarkRead = (id) => {
    setVisibleNotificationIds((current) => (current.includes(id) ? current : [...current, id]))
    markRead.mutate(id)
  }
  const markRead = useMutation({
    mutationFn: async (id) => (await api.patch(`/buyer/notifications/${id}/read`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] }),
  })

  const tabs = [
    ['overview', 'Dashboard'],
    ['browse', 'Browse'],
    ['orders', 'Orders'],
    ['saved', 'Saved'],
    ['notifications', 'Notifications'],
    ['ai', 'AI Assistant'],
  ]

  const browseItems = listings.filter((item) => {
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
          <StatsRow items={[['Active Orders', data?.active_orders ?? 0], ['Completed Orders', data?.completed_orders ?? 0], ['Saved Listings', data?.saved_listings ?? 0], ['Unread Messages', data?.unread_messages ?? 0]]} />
          <Section title="Recent Orders"><OrderTable rows={orders} /></Section>
          <Section title="Notifications"><NotificationStack notifications={notifications.slice(0, 3)} onMarkRead={handleMarkRead} /></Section>
        </>
      )}
      {tab === 'browse' && (
        <Section title="Browse Listings">
          <div className="buyer-browse">
            <div className="filter-card inline">
              <input placeholder="Search listings" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} />
              <select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value })}><option>All</option>{['Bangus', 'Tilapia', 'Grouper', 'Catfish', 'Sea Bass', 'Carp'].map((s) => <option key={s}>{s}</option>)}</select>
              <select value={filters.municipality} onChange={(e) => setFilters({ ...filters, municipality: e.target.value })}><option>All</option>{['Mandaue', 'Consolacion', 'Compostela', 'Talisay', 'Lapu-Lapu', 'Carmen'].map((s) => <option key={s}>{s}</option>)}</select>
            </div>
            <ListingGrid items={browseItems} detailPath={(item) => `/buyer/listings/${item.id}?source=browse`} />
          </div>
        </Section>
      )}
      {tab === 'orders' && <Section title="My Orders"><OrderTable rows={orders} /></Section>}
      {tab === 'saved' && (
        <Section title="Saved Listings">
          <div className="saved-layout">
            <div className="saved-summary card">
              <h3>Saved inside your buyer account</h3>
              <p>Saved listings stay in this dashboard so you can review them without leaving your buyer session.</p>
              <div className="stats-inline">
                <Stat value={savedItems.length} label="Saved items" />
                <Stat value={orders.length} label="Orders" />
                <Stat value={notifications.length} label="Notifications" />
              </div>
            </div>
            <ListingGrid
              items={savedItems}
              detailPath={(item) => `/buyer/listings/${item.id}?source=saved`}
            />
          </div>
        </Section>
      )}
      {tab === 'notifications' && <Section title="Notifications"><NotificationStack notifications={notifications} onMarkRead={handleMarkRead} /></Section>}
      {tab === 'ai' && <Section title="AI Assistant"><p>Use the floating Gemini assistant at the bottom-right. It stays available on every page and keeps your buyer session intact.</p></Section>}
    </Dashboard>
  )
}

function SellerDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const [form, setForm] = useState({ species: '', quantity: '', price: '', description: '', municipality: '' })
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
      listings: listings.filter((l) => l.seller === "Juan's Hatchery"),
      orders,
    },
  })
  const analytics = useQuery({
    queryKey: ['seller-analytics'],
    queryFn: async () => (await api.get('/seller/analytics')).data,
    retry: false,
    placeholderData: { sales_by_month: [], top_species: [] },
  })
  const createListing = useMutation({
    mutationFn: async () => (await api.post('/listings', {
      seller_profile_id: dashboard.data?.seller?.id || 1,
      municipality_id: dashboard.data?.seller?.municipality_id || 1,
      species: form.species,
      title: `${form.species} Fingerlings`,
      quantity: Number(form.quantity),
      price_per_piece: Number(form.price),
      scientific_name: '',
      average_size: '',
      availability_status: 'in_stock',
    })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] }),
  })
  const tabs = [['overview', 'Dashboard'], ['listings', 'Listings'], ['orders', 'Orders'], ['analytics', 'Analytics'], ['profile', 'Profile']]

  return (
    <Dashboard
      title="Seller Dashboard"
      subtitle="Manage listings, orders, and analytics."
      actions={<TabBar active={tab} tabs={tabs} setSearchParams={setSearchParams} />}
    >
      {tab === 'overview' && <StatsRow items={[['Active Listings', dashboard.data?.active_listings ?? 0], ['Pending Orders', dashboard.data?.pending_orders ?? 0], ['Total Sales', currency(dashboard.data?.total_sales ?? 0)], ['Ratings', `${dashboard.data?.ratings ?? 0}/5`]]} />}
      {tab === 'listings' && (
        <>
          <Section title="Create Listing">
            <div className="form grid-form">
              <input value={form.species} onChange={(e) => setForm({ ...form, species: e.target.value })} placeholder="Species" />
              <input value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} placeholder="Quantity" />
              <input value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} placeholder="Price" />
              <input value={form.municipality} onChange={(e) => setForm({ ...form, municipality: e.target.value })} placeholder="Municipality" />
              <textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Description" />
            </div>
            <button onClick={() => createListing.mutate()} type="button">Save Listing</button>
          </Section>
          <Section title="My Listings"><DataTable rows={dashboard.data?.listings || []} /></Section>
        </>
      )}
      {tab === 'orders' && <Section title="Order Management"><DataTable rows={dashboard.data?.orders || []} /></Section>}
      {tab === 'analytics' && <Section title="Analytics"><StatsRow items={[['Completed Sales', currency(analytics.data?.total_completed_sales ?? 0)], ['Order Statuses', analytics.data?.order_status_breakdown?.length ?? 0], ['Top Species', analytics.data?.top_species?.[0]?.species || 'None'], ['Rating', `${dashboard.data?.ratings ?? 0}/5`]]} /></Section>}
      {tab === 'profile' && <Section title="Seller Profile"><div className="card"><h3>{dashboard.data?.seller?.hatchery_name}</h3><p>Verified seller profile with LGU oversight and inventory management.</p></div></Section>}
    </Dashboard>
  )
}

function LguDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const lgu = useQuery({
    queryKey: ['lgu-dashboard'],
    queryFn: async () => (await api.get('/lgu/dashboard')).data,
    retry: false,
    placeholderData: { registered_sellers: 24, active_listings: 87, pending_approvals: [] },
  })
  const reports = useQuery({
    queryKey: ['lgu-reports'],
    queryFn: async () => (await api.get('/lgu/reports')).data,
    retry: false,
    placeholderData: { registered_sellers: 24, buyers: 156, listings: 87, pending_approvals: 5 },
  })
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

  const tabs = [['overview', 'Dashboard'], ['approvals', 'Approvals'], ['reports', 'Reports'], ['reviews', 'Reviews']]

  return (
    <Dashboard
      title="LGU Admin Dashboard"
      subtitle="Municipality-scoped approvals, reports, and reviews."
      actions={<TabBar active={tab} tabs={tabs} setSearchParams={setSearchParams} />}
    >
      {tab === 'overview' && <StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0], ['Buyers', reports.data?.buyers ?? 0], ['Listings', reports.data?.listings ?? 0], ['Pending Approvals', reports.data?.pending_approvals ?? 0]]} />}
      {tab === 'approvals' && (
        <Section title="Pending Approvals">
          <div className="action-grid">
            {(lgu.data?.pending_approvals || []).map((item) => (
              <div className="card action" key={item.id}>
                <div>
                  <strong>{item.title}</strong>
                  <p>{item.sellerProfile?.hatchery_name}</p>
                </div>
                <div className="row-actions">
                  <button type="button" onClick={() => approve.mutate(item.id)}>Approve</button>
                  <button type="button" className="ghost" onClick={() => reject.mutate(item.id)}>Reject</button>
                </div>
              </div>
            ))}
          </div>
        </Section>
      )}
      {tab === 'reports' && <Section title="Reports"><StatsRow items={[['Registered Sellers', reports.data?.registered_sellers ?? 0], ['Buyers', reports.data?.buyers ?? 0], ['Listings', reports.data?.listings ?? 0], ['Pending Approvals', reports.data?.pending_approvals ?? 0]]} /></Section>}
      {tab === 'reviews' && <Section title="Reviews"><DataTable rows={reviews.data || []} /></Section>}
    </Dashboard>
  )
}

function SuperAdminDashboard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const dashboard = useQuery({
    queryKey: ['super-admin-dashboard'],
    queryFn: async () => (await api.get('/super-admin/dashboard')).data,
    retry: false,
    placeholderData: { lgu_admins: 8, total_sellers: 142, held_in_escrow: 1200000, pending_payouts: [], transactions: [] },
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
  const release = useMutation({
    mutationFn: async (paymentId) => (await api.patch(`/super-admin/payments/${paymentId}/release`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['super-admin-dashboard'] }),
  })

  const tabs = [['overview', 'Dashboard'], ['lgu-admins', 'LGU Admins'], ['transactions', 'Transactions'], ['reports', 'Reports']]

  return (
    <Dashboard
      title="Super Admin Dashboard"
      subtitle="Platform-wide control, transaction review, and payout release."
      actions={<TabBar active={tab} tabs={tabs} setSearchParams={setSearchParams} />}
    >
      {tab === 'overview' && <StatsRow items={[['Total LGUs', reports.data?.total_lgus ?? 0], ['Total Sellers', reports.data?.total_sellers ?? 0], ['Total Buyers', reports.data?.total_buyers ?? 0], ['Pending Payouts', reports.data?.pending_payouts ?? 0]]} />}
      {tab === 'lgu-admins' && <Section title="LGU Admins"><DataTable rows={lguAdmins.data || []} /></Section>}
      {tab === 'transactions' && (
        <Section title="Transactions">
          <div className="table">
            {(dashboard.data?.pending_payouts || []).map((payment) => (
              <div className="table-row first" key={payment.id}>
                <span>{payment.order?.order_number}</span>
                <span>{currency(payment.amount)}</span>
                <span>{payment.status}</span>
                <button type="button" onClick={() => release.mutate(payment.id)}>Release</button>
              </div>
            ))}
          </div>
        </Section>
      )}
      {tab === 'reports' && <Section title="Platform Reports"><StatsRow items={[['LGU Admins', reports.data?.total_lgus ?? 0], ['Transactions', reports.data?.total_transactions ?? 0], ['Pending Payouts', reports.data?.pending_payouts ?? 0], ['Listings', reports.data?.total_listings ?? 0]]} /></Section>}
    </Dashboard>
  )
}

function Dashboard({ title, subtitle, actions, children }) {
  return <div className="dashboard"><div className="dashboard-head"><div><p className="eyebrow">{subtitle}</p><h1>{title}</h1></div>{actions || <button><Menu size={18} /> Actions</button>}</div>{children}</div>
}

function TabBar({ active, tabs, setSearchParams }) {
  return <div className="tab-bar">{tabs.map(([value, label]) => <button key={value} type="button" className={active === value ? 'tab active' : 'tab'} onClick={() => setSearchParams(value === 'overview' ? {} : { tab: value })}>{label}</button>)}</div>
}

function StatsRow({ items }) {
  return <div className="stats-grid">{items.map(([label, value]) => <Stat key={label} label={label} value={value} />)}</div>
}

function Stat({ value, label }) {
  return <div className="stat-card"><strong>{value}</strong><span>{label}</span></div>
}

function DataTable({ rows }) {
  if (!rows?.length) return <div className="card"><p>No records yet.</p></div>
  const keys = Object.keys(rows[0] || {}).slice(0, 6)
  return <div className="table">{rows.map((row, index) => <div className={`table-row ${index === 0 ? 'first' : ''}`} key={row.id || row.title || index}>{keys.map((key) => <span key={key}>{typeof row[key] === 'object' && row[key] !== null ? row[key].title || row[key].name || row[key].hatchery_name || JSON.stringify(row[key]) : String(row[key])}</span>)}</div>)}</div>
}

function OrderTable({ rows }) {
  const normalized = (rows || []).map((row) => ({
    id: row.order_number || row.id,
    order_name: row.listing?.title || row.listing?.species || row.species || 'Order',
    order_number: row.order_number || row.id,
    seller_name: row.listing?.sellerProfile?.hatchery_name || row.sellerProfile?.hatchery_name || row.seller || 'Unknown seller',
    quantity: row.quantity,
    status: row.status,
    payment_status: row.payment?.status || row.payment_status || 'pending',
    total_amount: row.total_amount || row.amount || 0,
    created_at: row.created_at || row.date || '',
  }))

  if (!normalized.length) return <div className="card"><p>No orders yet.</p></div>

  return (
    <div className="table">
      <div className="table-row first">
        <span>Order Name</span>
        <span>Order #</span>
        <span>Seller</span>
        <span>Qty</span>
        <span>Status</span>
        <span>Payment</span>
      </div>
      {normalized.map((row) => (
        <div className="table-row" key={row.id}>
          <span>{row.order_name}</span>
          <span>{row.order_number}</span>
          <span>{row.seller_name}</span>
          <span>{Number(row.quantity).toLocaleString()}</span>
          <span>{row.status}</span>
          <span>{row.payment_status}</span>
        </div>
      ))}
    </div>
  )
}

function NotificationStack({ notifications, onMarkRead }) {
  if (!notifications?.length) return <div className="card"><p>No notifications yet.</p></div>
  return <div className="notification-stack">{notifications.map((item) => <div className={`card notification ${item.read_at ? 'read' : 'unread'}`} key={item.id}><div><strong>{item.title}</strong><p>{item.body}</p></div><button type="button" onClick={() => onMarkRead(item.id)}>Mark Read</button></div>)}</div>
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
  return <main><Section title="Verified Sellers"><SellerGrid /></Section></main>
}

function SellerGrid() {
  return <div className="seller-grid">{sellers.map((seller) => <article className="card seller" key={seller.name}><ShieldCheck /><h3>{seller.name}</h3><p>{seller.municipality}</p><strong>{seller.rating}/5</strong><span>{seller.listings} listings</span></article>)}</div>
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
