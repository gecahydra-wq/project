import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  // Dev server only -- has no effect on `npm run build` or the Railway deploy.
  // Vite otherwise binds just the IPv6 loopback (::1) here, so the backend's
  // FRONTEND_URL redirect to the IPv4 literal 127.0.0.1:5173 (the Google
  // sign-in callback, password reset links) was refused. Pinning the host
  // makes both 127.0.0.1 and localhost reach the SPA, and strictPort stops
  // Vite silently moving to 5174 and breaking those same redirects.
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
  },
})
