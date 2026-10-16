import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { useEffect, useRef } from 'react'
import { api } from '../../lib/api.js'
import { getSession } from '../../lib/session.js'



export function PaymentSuccessPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const session = getSession()
  const orderNumber = searchParams.get('order')
  const returnToken = searchParams.get('t')
  const acknowledgedKey = orderNumber ? `fishmarket_payment_success_${orderNumber}` : null
  const acknowledgedRef = useRef(false)
  const acknowledge = useMutation({
    // 410 means the single-use return token was already spent, which is an
    // expected answer here (a bookmarked or re-opened receipt), not a failure
    // to report as one -- so unwrap it into the same shape as a 200.
    mutationFn: async () => {
      try {
        const data = (await api.post(`/orders/${orderNumber}/payment-success`, { t: returnToken })).data
        // Land on the plain orders list straight from the response. The
        // dashboard turns an ?order= param into an auto-opened order popup;
        // the buyer just paid and should arrive at their orders, not be
        // pulled into a dialog they did not ask for. Navigating here, from
        // the promise itself, also survives the dev-only StrictMode remount
        // that can leave a hook's rendered mutation state stale.
        if (data?.status === 'success') navigate('/buyer/dashboard?tab=orders', { replace: true })
        return data
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
          <Link className="button" to="/buyer/dashboard?tab=orders">View Orders</Link>
          <Link className="ghost" to="/buyer/dashboard?tab=notifications">Open Notifications</Link>
        </div>
      </section>
    </main>
  )
}

export function PaymentCancelledPage() {
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