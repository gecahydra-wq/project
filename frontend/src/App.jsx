import { QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { Protected, PublicLayout, RedirectIfSignedIn } from './layout/guards.jsx'
import { queryClient } from './lib/api.js'
import { SuperAdminDashboard, SuperAdminListingReviewPage } from './modules/admin/index.jsx'
import { ForgotPasswordPage, GoogleCallbackPage, LoginPage, RegisterPage, ResetPasswordPage } from './modules/auth/index.jsx'
import { BuyerDashboard } from './modules/buyer/index.jsx'
import { LguDashboard, LguListingReviewPage } from './modules/lgu/index.jsx'
import { AboutPage, BrowsePage, LandingPage, SellersPage } from './modules/marketplace/index.jsx'
import { BuyerListingDetailPage, ListingDetailPage, SellerListingDetailPage } from './modules/marketplace/ListingDetailPages.jsx'
import { BuyerProfileForSellerPage, SellerProfilePage } from './modules/marketplace/SellerProfilePages.jsx'
import { PaymentCancelledPage, PaymentSuccessPage } from './modules/payments/index.jsx'
import { SellerDashboard } from './modules/seller/index.jsx'
import { HelpCenterPage } from './modules/support/index.jsx'
import './App.css'



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
            <Route path="/help" element={<HelpCenterPage />} />
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

export default App