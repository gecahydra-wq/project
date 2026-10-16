import { roleRoutes } from './api.js'



// A corrupt or truncated value here must not be fatal: getSession() is called
// by the route guards, so a thrown SyntaxError would blank every protected
// page with no way back. Treat unreadable storage as "signed out".
export function getSession() {
  const stored = localStorage.getItem('fishmarket_user')
  if (!stored) return null
  try {
    return JSON.parse(stored)
  } catch {
    return null
  }
}

export function updateSessionUser(partial) {
  const current = getSession()
  if (!current) return
  localStorage.setItem('fishmarket_user', JSON.stringify({ ...current, ...partial }))
}

export function getHomeRoute() {
  const session = getSession()
  return session?.role ? roleRoutes[session.role] || '/' : '/'
}

export function sellerProfilePath(id) {
  const session = getSession()
  if (session?.role === 'buyer') return `/buyer/sellers/${id}`
  if (session?.role === 'seller') return `/seller/sellers/${id}`
  if (session?.role === 'lgu_admin') return `/lgu/sellers/${id}`
  if (session?.role === 'super_admin') return `/admin/sellers/${id}`
  return `/sellers/${id}`
}