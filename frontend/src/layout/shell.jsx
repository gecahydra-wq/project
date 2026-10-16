import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { ArrowRight, BarChart3, Bell, Bot, ChevronDown, ChevronRight, CircleUserRound, Coins, Ellipsis, Flag, History, Home, Info, LayoutDashboard, LifeBuoy, LogOut, MapPin, Megaphone, Menu, MessageCircle, RotateCcw, Scale, Search, Settings, ShieldAlert, ShieldCheck, ShoppingBag, ShoppingCart, Star, Store, Users as UsersIcon, Wallet, X } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { SiteAnnouncementBar } from '../components/announcements.jsx'
import { NotificationBell } from '../components/notifications.jsx'
import { Avatar, RoleBadge } from '../components/ui.jsx'
import { api, roleRoutes } from '../lib/api.js'
import { useDismiss, useUnreadMessages } from '../lib/hooks.jsx'
import { FloatingAi } from '../modules/ai/index.jsx'



export function AppShell({ user, children }) {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const tab = searchParams.get('tab') || 'overview'
  const homeRoute = roleRoutes[user.role] || '/'
  const menu = {
    buyer: [['Dashboard', '/buyer/dashboard?tab=overview', LayoutDashboard], ['Browse', '/buyer/dashboard?tab=browse', Search], ['Cart', '/buyer/dashboard?tab=cart', ShoppingBag], ['Orders', '/buyer/dashboard?tab=orders', ShoppingCart], ['Messages', '/buyer/dashboard?tab=messages', MessageCircle], ['Notifications', '/buyer/dashboard?tab=notifications', Bell], ['Analytics', '/buyer/dashboard?tab=analytics', BarChart3], ['AI Assistant', '/buyer/dashboard?tab=ai', Bot], ['Help & Support', '/buyer/dashboard?tab=support', LifeBuoy], ['Profile', '/buyer/dashboard?tab=settings', ShieldCheck]],
    seller: [['Dashboard', '/seller/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/seller/dashboard?tab=marketplace', Search], ['Listings', '/seller/dashboard?tab=listings', Store], ['Orders', '/seller/dashboard?tab=orders', ShoppingCart], ['Messages', '/seller/dashboard?tab=messages', MessageCircle], ['Wallet', '/seller/dashboard?tab=wallet', Wallet], ['Notifications', '/seller/dashboard?tab=notifications', Bell], ['Notices', '/seller/dashboard?tab=notices', ShieldAlert], ['Analytics', '/seller/dashboard?tab=analytics', BarChart3], ['Help & Support', '/seller/dashboard?tab=support', LifeBuoy], ['Profile', '/seller/dashboard?tab=profile', ShieldCheck]],
    lgu_admin: [['Dashboard', '/lgu/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/lgu/dashboard?tab=marketplace', Search], ['Listing Management', '/lgu/dashboard?tab=listings', Store], ['Sellers', '/lgu/dashboard?tab=sellers', ShieldCheck], ['User Reports', '/lgu/dashboard?tab=user-reports', Flag], ['Notices to Explain', '/lgu/dashboard?tab=notices', ShieldAlert], ['Disputes', '/lgu/dashboard?tab=disputes', Scale], ['Support Tickets', '/lgu/dashboard?tab=support', LifeBuoy], ['Orders', '/lgu/dashboard?tab=orders', ShoppingCart], ['Seller Earnings', '/lgu/dashboard?tab=earnings', Wallet], ['LGU Wallet', '/lgu/dashboard?tab=wallet', Wallet], ['Messages', '/lgu/dashboard?tab=messages', MessageCircle], ['Notifications', '/lgu/dashboard?tab=notifications', Bell], ['Analytics', '/lgu/dashboard?tab=reports', BarChart3], ['Activity Log', '/lgu/dashboard?tab=activity-log', History], ['Reviews & Ratings', '/lgu/dashboard?tab=reviews', Star], ['Users', '/lgu/dashboard?tab=users', UsersIcon], ['Profile', '/lgu/dashboard?tab=profile', CircleUserRound]],
    super_admin: [['Dashboard', '/admin/dashboard?tab=overview', LayoutDashboard], ['Marketplace', '/admin/dashboard?tab=marketplace', Search], ['Listing Management', '/admin/dashboard?tab=listings', Store], ['LGU Admins', '/admin/dashboard?tab=lgu-admins', ShieldCheck], ['Sellers', '/admin/dashboard?tab=sellers', Store], ['Users', '/admin/dashboard?tab=users', UsersIcon], ['User Reports', '/admin/dashboard?tab=user-reports', Flag], ['Notices to Explain', '/admin/dashboard?tab=notices', ShieldAlert], ['Disputes', '/admin/dashboard?tab=disputes', Scale], ['Support Tickets', '/admin/dashboard?tab=support', LifeBuoy], ['Seller Earnings', '/admin/dashboard?tab=earnings', Wallet], ['Platform Earnings', '/admin/dashboard?tab=platform-earnings', Coins], ['Reviews & Ratings', '/admin/dashboard?tab=reviews', Star], ['Orders', '/admin/dashboard?tab=transactions', ShoppingCart], ['Payout Management', '/admin/dashboard?tab=payouts', Wallet], ['Refunds', '/admin/dashboard?tab=refunds', RotateCcw], ['Municipalities', '/admin/dashboard?tab=municipalities', MapPin], ['Announcements', '/admin/dashboard?tab=announcements', Megaphone], ['Messages', '/admin/dashboard?tab=messages', MessageCircle], ['Notifications', '/admin/dashboard?tab=notifications', Bell], ['Activity Log', '/admin/dashboard?tab=activity-log', History], ['Analytics', '/admin/dashboard?tab=reports', BarChart3], ['Profile', '/admin/dashboard?tab=profile', CircleUserRound]],
  }[user.role]
  const unreadMessages = useUnreadMessages()

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

  // Same destinations as the old sidebar, regrouped for a top bar. A link is
  // the current page when its ?tab= value matches exactly -- the sidebar's
  // path.includes(tab) also lit up "User Reports" whenever Analytics
  // (tab=reports) was open.
  const isActive = (path) => new URLSearchParams(path.split('?')[1] || '').get('tab') === tab
  const nav = buildRoleNav(user.role, menu)
  const isBuyer = user.role === 'buyer'

  return (
    <div className={`min-h-screen ${isBuyer ? 'has-bottom-nav' : ''}`}>
      <TopBar
        user={user}
        homeRoute={homeRoute}
        nav={nav}
        isActive={isActive}
        unreadMessages={unreadMessages}
        onLogout={logout}
      />
      <main className="app-main mx-auto w-full max-w-[1500px]">
        <SiteAnnouncementBar />
        {children}
      </main>
      {isBuyer && <BuyerBottomNav nav={nav} isActive={isActive} />}
      <FloatingAi />
    </div>
  )
}

/**
 * How each role's menu is arranged in the top bar. Labels refer to entries in
 * AppShell's existing `menu`, so every destination stays exactly as it was:
 * this only decides WHERE a link appears. Messages and Notifications become
 * icons on the right, Profile moves into the avatar menu.
 */
const ROLE_NAV_LAYOUT = {
  buyer: {
    breakpoint: 'lg',
    main: ['Dashboard', 'Browse', 'Cart', 'Orders', { label: 'More', items: ['Analytics', 'AI Assistant', 'Help & Support'] }],
    bottom: [['Dashboard', 'Home'], ['Browse', 'Browse'], ['Cart', 'Cart'], ['Orders', 'Orders'], ['Profile', 'Profile']],
  },
  seller: {
    breakpoint: 'lg',
    main: ['Dashboard', 'Marketplace', 'Listings', 'Orders', 'Wallet', { label: 'More', items: ['Analytics', 'Notices', 'Help & Support'] }],
  },
  lgu_admin: {
    breakpoint: 'xl',
    main: [
      'Dashboard',
      'Marketplace',
      { label: 'Sellers', items: ['Sellers', 'Listing Management', 'User Reports', 'Notices to Explain', 'Disputes', 'Reviews & Ratings'] },
      { label: 'Money', items: ['Orders', 'Seller Earnings', 'LGU Wallet'] },
      ['Support Tickets', 'Support'],
      { label: 'Insights', items: ['Analytics', 'Activity Log', 'Users'] },
    ],
  },
  super_admin: {
    breakpoint: 'xl',
    main: [
      'Dashboard',
      'Marketplace',
      { label: 'People', items: ['LGU Admins', 'Sellers', 'Users'] },
      { label: 'Moderation', items: ['Listing Management', 'User Reports', 'Notices to Explain', 'Disputes', 'Reviews & Ratings'] },
      { label: 'Money', items: ['Orders', 'Seller Earnings', 'Payout Management', 'Refunds'] },
      ['Support Tickets', 'Support'],
      { label: 'System', items: ['Municipalities', 'Announcements', 'Analytics', 'Activity Log'] },
    ],
  },
}

/** An icon for each top-bar dropdown, so groups read like the links beside them. */
const NAV_GROUP_ICONS = { More: Ellipsis, Sellers: Store, Money: Wallet, Insights: BarChart3, People: UsersIcon, Moderation: ShieldAlert, System: Settings }

/** Resolves ROLE_NAV_LAYOUT labels against the role's menu entries ([label, path, Icon]). */
function buildRoleNav(role, menu) {
  const layout = ROLE_NAV_LAYOUT[role] || { breakpoint: 'lg', main: menu.map(([label]) => label) }
  const byLabel = Object.fromEntries(menu.map((entry) => [entry[0], entry]))
  // A string is a plain link; [label, shortLabel] is a link shown under a
  // shorter name in the top bar; an object is a dropdown group.
  const toLink = (ref) => {
    const [label, short] = Array.isArray(ref) ? ref : [ref, ref]
    const entry = byLabel[label]
    return entry ? { label, short, path: entry[1], Icon: entry[2] } : null
  }
  const main = layout.main
    .map((ref) => (ref && !Array.isArray(ref) && typeof ref === 'object'
      ? { label: ref.label, Icon: NAV_GROUP_ICONS[ref.label] || Ellipsis, items: ref.items.map(toLink).filter(Boolean) }
      : toLink(ref)))
    .filter(Boolean)
  return {
    breakpoint: layout.breakpoint,
    main,
    messages: toLink('Messages'),
    notifications: toLink('Notifications'),
    profile: toLink('Profile'),
    bottom: (layout.bottom || []).map(toLink).filter(Boolean),
  }
}

function BrandMark({ to, dark = false }) {
  return (
    <Link data-tw to={to} className={`group flex shrink-0 items-center gap-2.5 text-lg font-bold tracking-tight ${dark ? 'text-white' : 'text-abai-navy'}`}>
      <img
        src="/logo-mark.png"
        alt=""
        width="40"
        height="40"
        className={`size-10 rounded-xl shadow-lg transition duration-300 group-hover:-rotate-6 group-hover:scale-105 ${dark ? 'shadow-black/30 ring-1 ring-white/25' : 'shadow-abai-navy/20'}`}
      />
      AbaiMarket
    </Link>
  )
}

// One look for every navigation bar (dashboards and the public header): links
// sit in a translucent capsule, and the current page is a white pill.
const navCapsule = 'flex items-center gap-1 rounded-full bg-white/[0.06] p-1 ring-1 ring-white/10'
const navPill = 'group/link flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium whitespace-nowrap transition duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-abai-seafoam'
const navPillIdle = 'text-white/75 hover:bg-white/10 hover:text-white'
const navPillActive = 'bg-white text-abai-navy shadow-md'
const navPillIcon = (active) => `shrink-0 transition duration-200 ${active ? 'text-abai-teal' : 'text-abai-seafoam/70 group-hover/link:-translate-y-px group-hover/link:text-abai-seafoam'}`
// Phone menu rows, shared by both drawers.
const drawerRow = (active) => `group/item flex items-center gap-3 rounded-2xl px-3 py-2.5 text-base font-medium transition duration-300 ${active ? 'bg-white text-abai-navy shadow-md' : 'text-white/85 hover:bg-white/10 hover:text-white'}`
const drawerTile = (active) => `grid size-10 shrink-0 place-items-center rounded-xl transition ${active ? 'bg-abai-teal text-white' : 'bg-white/10 text-abai-seafoam group-hover/item:bg-white/15'}`
const drawerChevron = (active) => `ml-auto shrink-0 transition ${active ? 'text-abai-teal' : 'text-white/40 group-hover/item:translate-x-0.5 group-hover/item:text-white/70'}`
const outlineCta = 'inline-flex items-center justify-center gap-2 rounded-full px-5 py-2.5 text-sm font-semibold text-white ring-1 ring-white/40 transition duration-200 hover:bg-white/10 hover:ring-white'

function NavDropdown({ group, isActive, iconClass = '' }) {
  const [open, setOpen] = useState(false)
  const ref = useDismiss(open, setOpen)
  const active = group.items.some((item) => isActive(item.path))
  return (
    <div ref={ref} className="relative">
      <button
        data-tw
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className={`${navPill} cursor-pointer ${active ? navPillActive : open ? 'bg-white/10 text-white' : navPillIdle}`}
      >
        <group.Icon size={16} className={`${navPillIcon(active)} ${iconClass}`} />
        {group.label}
        <ChevronDown size={15} className={`transition-transform duration-200 ${open ? 'rotate-180' : ''} ${active ? 'text-abai-teal' : ''}`} />
      </button>
      <div
        className={`absolute left-0 top-full z-50 mt-3 w-72 origin-top-left rounded-2xl bg-white p-2 shadow-2xl shadow-abai-navy/25 ring-1 ring-black/5 transition duration-200 ${open ? 'translate-y-0 scale-100 opacity-100' : 'pointer-events-none -translate-y-1 scale-95 opacity-0'}`}
      >
        {group.items.map(({ label, path, Icon }) => {
          const current = isActive(path)
          return (
            <Link
              data-tw
              key={label}
              to={path}
              onClick={() => setOpen(false)}
              tabIndex={open ? 0 : -1}
              className={`group/item flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition ${current ? 'bg-abai-teal-soft font-semibold text-abai-teal-text' : 'text-slate-700 hover:bg-slate-50 hover:text-abai-navy'}`}
            >
              <span className={`grid size-9 place-items-center rounded-lg transition ${current ? 'bg-abai-teal text-white' : 'bg-slate-100 text-slate-500 group-hover/item:bg-abai-teal group-hover/item:text-white'}`}>
                <Icon size={17} />
              </span>
              {label}
              <ChevronRight size={15} className="ml-auto -translate-x-1 opacity-0 transition group-hover/item:translate-x-0 group-hover/item:opacity-60" />
            </Link>
          )
        })}
      </div>
    </div>
  )
}

function IconLink({ link, isActive, count }) {
  if (!link) return null
  const { label, path, Icon } = link
  return (
    <Link
      data-tw
      to={path}
      title={label}
      aria-label={count ? `${label} (${count} unread)` : label}
      className={`relative grid size-10 place-items-center rounded-full transition duration-200 hover:scale-105 ${isActive(path) ? 'bg-white/20 text-white' : 'text-white/80 hover:bg-white/10 hover:text-white'}`}
    >
      <Icon size={20} />
      {count > 0 && (
        <span className="absolute -right-0.5 -top-0.5 grid min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[0.68rem] font-bold leading-5 text-white ring-2 ring-abai-navy">
          {count > 99 ? '99+' : count}
        </span>
      )}
    </Link>
  )
}

function ProfileMenu({ user, profile, onLogout }) {
  const [open, setOpen] = useState(false)
  const ref = useDismiss(open, setOpen)
  return (
    <div ref={ref} className="relative">
      <button
        data-tw
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        aria-label="Account menu"
        onClick={() => setOpen((value) => !value)}
        className={`flex cursor-pointer items-center gap-2 rounded-full p-1 pr-2 transition hover:bg-white/10 ${open ? 'bg-white/10' : ''}`}
      >
        <span className="rounded-full ring-2 ring-abai-seafoam/70 transition">
          <Avatar src={user.profile_picture} alt={user.name} />
        </span>
        <ChevronDown size={15} className={`text-white/80 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
      </button>
      <div
        className={`absolute right-0 top-full z-50 mt-3 w-72 origin-top-right overflow-hidden rounded-2xl bg-white shadow-2xl shadow-abai-navy/25 ring-1 ring-black/5 transition duration-200 ${open ? 'translate-y-0 scale-100 opacity-100' : 'pointer-events-none -translate-y-1 scale-95 opacity-0'}`}
      >
        <div className="flex items-center gap-3 bg-linear-to-br from-abai-navy to-abai-teal p-4 text-white">
          <Avatar src={user.profile_picture} alt={user.name} className="nav-avatar-lg ring-2 ring-white/40" />
          <div className="min-w-0">
            <p className="m-0 truncate font-semibold">{user.name}</p>
            <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-white/80">
              <RoleBadge role={user.role} />
              {user.municipality && <span className="truncate">{user.municipality}</span>}
            </div>
          </div>
        </div>
        <div className="p-2">
          {profile && (
            <Link
              data-tw
              to={profile.path}
              onClick={() => setOpen(false)}
              tabIndex={open ? 0 : -1}
              className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-slate-700 transition hover:bg-slate-50 hover:text-abai-navy"
            >
              <profile.Icon size={17} className="text-slate-500" />My Profile
            </Link>
          )}
          <button
            data-tw
            type="button"
            onClick={onLogout}
            tabIndex={open ? 0 : -1}
            className="flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-red-50"
          >
            <LogOut size={17} />Logout
          </button>
        </div>
      </div>
    </div>
  )
}

/** Slide-in menu for phones and tablets: every link, grouped the same way as the top bar. */
function MobileDrawer({ open, onClose, children, header, dark = true }) {
  useEffect(() => {
    if (!open) return undefined
    const onKey = (event) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previous
    }
  }, [open, onClose])
  return (
    <div className={`fixed inset-0 z-[60] ${open ? '' : 'pointer-events-none'}`} aria-hidden={!open}>
      <div
        onClick={onClose}
        className={`absolute inset-0 bg-abai-navy-deep/50 backdrop-blur-sm transition-opacity duration-300 ${open ? 'opacity-100' : 'opacity-0'}`}
      />
      <aside
        className={`absolute inset-y-0 right-0 flex w-[min(22rem,88vw)] flex-col shadow-2xl transition-transform duration-300 ease-out ${dark ? 'nav-photo nav-photo-tall text-white' : 'bg-white text-slate-800'} ${open ? 'translate-x-0' : 'translate-x-full'}`}
      >
        <div className={`flex items-center justify-between gap-3 border-b p-4 ${dark ? 'border-white/10' : 'border-slate-200'}`}>
          {header}
          <button
            data-tw
            type="button"
            onClick={onClose}
            aria-label="Close menu"
            className={`grid size-10 shrink-0 cursor-pointer place-items-center rounded-full transition ${dark ? 'text-white/80 hover:bg-white/10' : 'text-slate-500 hover:bg-slate-100'}`}
          >
            <X size={20} />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto p-3">{children}</div>
      </aside>
    </div>
  )
}

function DrawerLink({ link, isActive, onNavigate, count, style, motion = '' }) {
  const { label, path, Icon } = link
  const current = isActive(path)
  return (
    <Link data-tw to={path} onClick={onNavigate} style={style} className={`${drawerRow(current)} ${motion}`}>
      <span className={drawerTile(current)}><Icon size={19} /></span>
      {label}
      {count > 0
        ? <span className="ml-auto rounded-full bg-red-500 px-2 py-0.5 text-xs font-bold text-white">{count}</span>
        : <ChevronRight size={18} className={drawerChevron(current)} />}
    </Link>
  )
}

function TopBar({ user, homeRoute, nav, isActive, unreadMessages, onLogout }) {
  const [drawerOpen, setDrawerOpen] = useState(false)
  const closeDrawer = useCallback(() => setDrawerOpen(false), [])
  const [scrolled, setScrolled] = useState(false)
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])
  // Phone-menu rows slide in one after another, like the public menu.
  let row = 0
  const stagger = () => ({ transitionDelay: drawerOpen ? `${120 + (row++) * 35}ms` : '0ms' })
  const slideIn = drawerOpen ? 'translate-x-0 opacity-100' : 'translate-x-6 opacity-0'
  // Tailwind only ships classes it can see written out in full, so the
  // breakpoint picks between complete class strings.
  const desktop = nav.breakpoint === 'xl' ? 'hidden xl:flex' : 'hidden lg:flex'
  const mobileOnly = nav.breakpoint === 'xl' ? 'xl:hidden' : 'lg:hidden'
  // Icons inside the capsule only once there is room for them; the LGU and
  // Super Admin bars carry more links, so theirs appear later.
  const pillIcon = nav.breakpoint === 'xl' ? 'hidden 2xl:block' : 'hidden xl:block'

  // The drawer renders beside the header, not inside it: backdrop-blur makes
  // the header the containing block for position:fixed children, which would
  // trap the drawer inside the 64px bar.
  return (
    <>
    <header className={`nav-photo sticky top-0 z-40 text-white transition-shadow duration-300 ${scrolled ? 'shadow-lg shadow-abai-navy/25' : ''}`}>
      <div className={`mx-auto flex max-w-[1500px] items-center gap-4 px-4 transition-all duration-300 sm:px-6 ${scrolled ? 'h-16' : 'h-20'}`}>
        <BrandMark to={homeRoute} dark />
        <nav className={`${desktop} min-w-0 flex-1 justify-center`} aria-label="Main">
          <div className={navCapsule}>
            {nav.main.map((item) => {
              if (item.items) return <NavDropdown key={item.label} group={item} isActive={isActive} iconClass={pillIcon} />
              const active = isActive(item.path)
              return (
                <Link
                  data-tw
                  key={item.label}
                  to={item.path}
                  aria-current={active ? 'page' : undefined}
                  className={`${navPill} ${active ? navPillActive : navPillIdle}`}
                >
                  <item.Icon size={16} className={`${navPillIcon(active)} ${pillIcon}`} />
                  {item.short}
                </Link>
              )
            })}
          </div>
        </nav>
        <div className="ml-auto flex items-center gap-1">
          <IconLink link={nav.messages} isActive={isActive} count={unreadMessages} />
          <NotificationBell role={user.role} link={nav.notifications} />
          <div className={desktop}>
            <ProfileMenu user={user} profile={nav.profile} onLogout={onLogout} />
          </div>
          <button
            data-tw
            type="button"
            onClick={() => setDrawerOpen(true)}
            aria-label="Open menu"
            className={`${mobileOnly} grid size-10 cursor-pointer place-items-center rounded-full text-white ring-1 ring-white/20 transition hover:bg-white/10`}
          >
            <Menu size={22} />
          </button>
        </div>
      </div>
    </header>

      <MobileDrawer
        open={drawerOpen}
        onClose={closeDrawer}
        header={(
          <div className="flex min-w-0 items-center gap-3">
            <Avatar src={user.profile_picture} alt={user.name} className="nav-avatar-lg ring-2 ring-abai-seafoam/60" />
            <div className="min-w-0">
              <p className="m-0 truncate font-semibold">{user.name}</p>
              <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-white/70">
                <RoleBadge role={user.role} />
                {user.municipality && <span className="truncate">{user.municipality}</span>}
              </div>
            </div>
          </div>
        )}
      >
        <div className="grid gap-1.5">
          {nav.main.map((item) => (item.items ? (
            <div key={item.label} className="mt-3 grid gap-1.5">
              <p className={`m-0 flex items-center gap-2 px-3 pb-0.5 text-[0.7rem] font-semibold uppercase tracking-wider text-abai-seafoam/70 transition duration-300 ${slideIn}`} style={stagger()}>
                <item.Icon size={13} />{item.label}
              </p>
              {item.items.map((link) => <DrawerLink key={link.label} link={link} isActive={isActive} onNavigate={closeDrawer} style={stagger()} motion={slideIn} />)}
            </div>
          ) : <DrawerLink key={item.label} link={item} isActive={isActive} onNavigate={closeDrawer} style={stagger()} motion={slideIn} />))}
          <div className="mt-3 grid gap-1.5 border-t border-white/10 pt-3">
            {nav.messages && <DrawerLink link={nav.messages} isActive={isActive} onNavigate={closeDrawer} count={unreadMessages} style={stagger()} motion={slideIn} />}
            {nav.notifications && <DrawerLink link={nav.notifications} isActive={isActive} onNavigate={closeDrawer} style={stagger()} motion={slideIn} />}
            {nav.profile && <DrawerLink link={nav.profile} isActive={isActive} onNavigate={closeDrawer} style={stagger()} motion={slideIn} />}
          </div>
          <button
            data-tw
            type="button"
            onClick={onLogout}
            style={stagger()}
            className={`${outlineCta} mt-4 w-full cursor-pointer py-3 text-base ${slideIn}`}
          >
            <LogOut size={18} />Logout
          </button>
        </div>
      </MobileDrawer>
    </>
  )
}

/** App-style tab bar for buyers on phones and tablets. */
function BuyerBottomNav({ nav, isActive }) {
  if (!nav.bottom.length) return null
  return (
    <>
      {/* Keeps the last bit of the page clear of the fixed bar. */}
      <div className="h-20 lg:hidden" aria-hidden="true" />
      <nav
        aria-label="Quick links"
        className="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200/80 bg-white/90 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_30px_rgba(11,46,79,0.08)] backdrop-blur-xl lg:hidden"
      >
        <div className="mx-auto grid max-w-lg grid-cols-5">
          {nav.bottom.map(({ label, short, path, Icon }) => {
            const current = isActive(path)
            return (
              <Link
                data-tw
                key={label}
                to={path}
                className={`group relative flex flex-col items-center gap-1 py-2.5 text-[0.7rem] font-medium transition ${current ? 'text-abai-teal-text' : 'text-slate-500 hover:text-abai-navy'}`}
              >
                <span className={`absolute inset-x-6 top-0 h-0.5 rounded-full bg-abai-teal transition-transform duration-300 ${current ? 'scale-x-100' : 'scale-x-0'}`} />
                <span className={`grid h-8 w-12 place-items-center rounded-full transition duration-300 ${current ? 'bg-abai-teal-soft' : 'group-hover:bg-slate-100'}`}>
                  {label === 'Dashboard' ? <Home size={20} /> : <Icon size={20} />}
                </span>
                {short}
              </Link>
            )
          })}
        </div>
      </nav>
    </>
  )
}

const PUBLIC_NAV = [['Home', '/', Home], ['Browse', '/browse', Search], ['Sellers', '/sellers', Store], ['About', '/about', Info], ['Help', '/help', LifeBuoy]]

export function PublicHeader({ homeRoute, signedIn }) {
  const location = useLocation()
  const [scrolled, setScrolled] = useState(false)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const closeDrawer = useCallback(() => setDrawerOpen(false), [])

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  const current = (path) => (path === '/' ? location.pathname === '/' : location.pathname.startsWith(path))
  const primaryCta = 'group/cta inline-flex items-center justify-center gap-2 rounded-full bg-abai-teal px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-black/20 ring-1 ring-white/15 transition duration-200 hover:-translate-y-0.5 hover:bg-abai-teal-hover hover:shadow-xl'
  const secondaryCta = outlineCta
  const arrow = <ArrowRight size={16} className="transition-transform duration-200 group-hover/cta:translate-x-1" />
  // Menu links slide in one after another while the drawer opens.
  const stagger = (index) => ({ transitionDelay: drawerOpen ? `${120 + index * 45}ms` : '0ms' })
  const slideIn = drawerOpen ? 'translate-x-0 opacity-100' : 'translate-x-6 opacity-0'

  return (
    <>
    <header
      className={`nav-photo sticky top-0 z-40 text-white transition-shadow duration-300 ${scrolled ? 'shadow-lg shadow-abai-navy/25' : ''}`}
    >
      <div className={`mx-auto flex max-w-[1500px] items-center gap-6 px-4 transition-all duration-300 sm:px-6 ${scrolled ? 'h-16' : 'h-20'}`}>
        <BrandMark to={homeRoute} dark />
        <nav className="hidden flex-1 justify-center lg:flex" aria-label="Main">
          <div className={navCapsule}>
            {PUBLIC_NAV.map(([label, path, Icon]) => {
              const active = current(path)
              return (
                <Link
                  data-tw
                  key={path}
                  to={path}
                  aria-current={active ? 'page' : undefined}
                  className={`${navPill} px-4 ${active ? navPillActive : navPillIdle}`}
                >
                  <Icon size={16} className={navPillIcon(active)} />
                  {label}
                </Link>
              )
            })}
          </div>
        </nav>
        <div className="ml-auto hidden items-center gap-2 lg:flex">
          {signedIn ? (
            // Browse/Sellers/About stay reachable while signed in, so without
            // this the header offers a signed-in visitor no way back into the
            // app -- Login/Register are hidden and nothing replaces them.
            <Link data-tw className={primaryCta} to={homeRoute}>Go to Dashboard{arrow}</Link>
          ) : (
            <>
              <Link data-tw className={secondaryCta} to="/login">Login</Link>
              <Link data-tw className={primaryCta} to="/register">Register{arrow}</Link>
            </>
          )}
        </div>
        <button
          data-tw
          type="button"
          onClick={() => setDrawerOpen(true)}
          aria-label="Open menu"
          className="ml-auto grid size-10 cursor-pointer place-items-center rounded-full text-white ring-1 ring-white/20 transition hover:bg-white/10 lg:hidden"
        >
          <Menu size={22} />
        </button>
      </div>
    </header>

      <MobileDrawer open={drawerOpen} onClose={closeDrawer} header={<BrandMark to={homeRoute} dark />}>
        <div className="flex min-h-full flex-col">
          <p className={`m-0 mb-4 px-2 text-sm leading-relaxed text-white/70 transition duration-300 ${slideIn}`} style={stagger(0)}>
            Fresh fingerlings from LGU-verified hatcheries, paid safely through escrow.
          </p>
          <div className="grid gap-1.5">
            {PUBLIC_NAV.map(([label, path, Icon], index) => {
              const active = current(path)
              return (
                <Link
                  data-tw
                  key={path}
                  to={path}
                  onClick={closeDrawer}
                  style={stagger(index + 1)}
                  className={`${drawerRow(active)} ${slideIn}`}
                >
                  <span className={drawerTile(active)}><Icon size={19} /></span>
                  {label}
                  <ChevronRight size={18} className={drawerChevron(active)} />
                </Link>
              )
            })}
          </div>
          <div className={`mt-auto grid gap-2 border-t border-white/10 pt-5 transition duration-300 ${slideIn}`} style={stagger(PUBLIC_NAV.length + 1)}>
            {signedIn ? (
              <Link data-tw className={`${primaryCta} py-3 text-base`} to={homeRoute} onClick={closeDrawer}>Go to Dashboard{arrow}</Link>
            ) : (
              <>
                <Link data-tw className={`${primaryCta} py-3 text-base`} to="/register" onClick={closeDrawer}>Register{arrow}</Link>
                <Link data-tw className={`${secondaryCta} py-3 text-base`} to="/login" onClick={closeDrawer}>Login</Link>
              </>
            )}
          </div>
        </div>
      </MobileDrawer>
    </>
  )
}