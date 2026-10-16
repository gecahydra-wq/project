import axios from 'axios'
import { QueryClient } from '@tanstack/react-query'



/* A backgrounded window keeps polling. Without this React Query pauses every
   refetchInterval the moment the window loses focus, so while you act in one
   browser the other one silently stops asking and only catches up when you
   click it or reload. */
export const queryClient = new QueryClient({
  defaultOptions: { queries: { refetchIntervalInBackground: true } },
})
export const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

export const api = axios.create({ baseURL: API_URL })
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

export const roleRoutes = {
  buyer: '/buyer/dashboard',
  seller: '/seller/dashboard',
  lgu_admin: '/lgu/dashboard',
  super_admin: '/admin/dashboard',
}

export const demoUsers = {
  'lgu@gmail.com': { id: 1, name: 'LGU Admin', role: 'lgu_admin', email: 'lgu@gmail.com', municipality: 'Mandaue' },
  'superadmin@gmail.com': { id: 2, name: 'Super Admin', role: 'super_admin', email: 'superadmin@gmail.com', municipality: 'All LGUs' },
}