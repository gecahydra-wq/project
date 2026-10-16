import { Navigate, Outlet } from 'react-router-dom'
import { SiteAnnouncementBar } from '../components/announcements.jsx'
import { AppShell, PublicHeader } from './shell.jsx'
import { roleRoutes } from '../lib/api.js'
import { getHomeRoute, getSession } from '../lib/session.js'
import { FloatingAi } from '../modules/ai/index.jsx'



export function PublicLayout() {
  const homeRoute = getHomeRoute()
  const session = getSession()
  return (
    <>
      <SiteAnnouncementBar />
      <PublicHeader homeRoute={homeRoute} signedIn={Boolean(session)} />
      {/* Scope wrapper: the storefront-scale type and section rhythm below
          apply to the public marketing pages only. The four role dashboards
          are dense tables and approval queues where that spacing would mean
          far more scrolling for the same work, so they keep the base styles. */}
      <div className="public-shell">
        <Outlet />
      </div>
      {/* One footer for every public page, not just the landing page. */}
      <footer className="site-footer">AbaiMarket - LGU, Sellers, and Fish Farmers working together for local aquaculture.</footer>
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
export function RedirectIfSignedIn({ children }) {
  const session = getSession()
  if (session) return <Navigate to={getHomeRoute()} replace />
  return children
}

export function Protected({ allowed, children }) {
  const session = getSession()
  if (!session) return <Navigate to="/login" replace />
  if (!allowed.includes(session.role)) return <Navigate to={roleRoutes[session.role] || '/login'} replace />
  return <AppShell user={session}>{children}</AppShell>
}