import { roleRoutes } from './api.js'



export function getSession() {
  const stored = localStorage.getItem('fishmarket_user')
  return stored ? JSON.parse(stored) : null
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