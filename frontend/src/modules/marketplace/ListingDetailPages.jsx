import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useState } from 'react'
import { ListingDetailPanel } from '../../components/listings.jsx'
import { LoadingState } from '../../components/states.jsx'
import { api, queryClient } from '../../lib/api.js'
import { mapListing, minimumOrder, resolveListingImage } from '../../lib/listing.js'
import { getSession } from '../../lib/session.js'



export function ListingDetailPage() {
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
      const safeQty = Math.min(Math.max(Number(qty) || 0, minimumOrder(item)), Number(item.quantity) || minimumOrder(item))
      const order = await api.post('/orders', { fingerling_listing_id: item.id, quantity: safeQty })
      return (await api.post(`/orders/${order.data.id}/checkout`)).data
    },
    onSuccess: (data) => window.location.assign(data.checkout_url),
  })

  if (isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (isError || !item) return <main className="auth-page"><section className="result-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to="/browse">Back to Browse</Link></section></main>

  return (
    <main className="detail-page">
      <img className="detail-art" src={resolveListingImage(item)} alt={item.title || item.species} />
      <ListingDetailPanel item={item} isBuyer={isBuyer} checkout={checkout} qty={qty} setQty={setQty} onPay={() => checkout.mutate()} />
    </main>
  )
}

export function BuyerListingDetailPage() {
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
      const safeQty = Math.min(Math.max(Number(qty) || 0, minimumOrder(item)), Number(item.quantity) || minimumOrder(item))
      const order = await api.post('/orders', { fingerling_listing_id: item.id, quantity: safeQty })
      return (await api.post(`/orders/${order.data.id}/checkout`)).data
    },
    onSuccess: (data) => window.location.assign(data.checkout_url),
  })
  const addToCart = useMutation({
    mutationFn: async (quantity) => (await api.post('/cart', { fingerling_listing_id: item.id, quantity })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['buyer-cart'] }),
  })

  if (isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (isError || !item) return <main className="auth-page"><section className="result-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to={`/buyer/dashboard?tab=${sourceTab}`}>Back to Browse</Link></section></main>

  return (
    <main className="detail-page">
      <img className="detail-art" src={resolveListingImage(item)} alt={item.title || item.species} />
      <div className="detail-stack">
        <ListingDetailPanel item={item} isBuyer checkout={buyListing} qty={qty} setQty={setQty} onPay={() => buyListing.mutate()} addToCart={addToCart} />
        <Link className="ghost" to={`/buyer/dashboard?tab=${sourceTab}`}>{sourceTab === 'cart' ? 'Back to Cart' : 'Back to Browse'}</Link>
      </div>
    </main>
  )
}

/**
 * Read-only listing detail for logged-in sellers. Sellers browsing the
 * marketplace (or another hatchery's profile) used to land on the public
 * /listing/:id page, which renders inside PublicLayout and therefore looks
 * like a logged-out session. This keeps them inside the seller AppShell.
 * Purchasing stays buyer-only, so no checkout/cart actions are wired here.
 */
export function SellerListingDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const sourceTab = searchParams.get('source') || 'marketplace'
  const { data: item, isLoading, isError } = useQuery({
    queryKey: ['seller-listing', id],
    queryFn: async () => mapListing((await api.get(`/listings/${id}`)).data),
    retry: false,
  })

  if (isLoading) return <main className="detail-page"><LoadingState label="Loading listing..." /></main>
  if (isError || !item) return <main className="auth-page"><section className="result-card"><h1>Listing not found</h1><p>This listing may have been removed or is no longer available.</p><Link className="button" to={`/seller/dashboard?tab=${sourceTab}`}>Back to Marketplace</Link></section></main>

  return (
    <main className="detail-page">
      <img className="detail-art" src={resolveListingImage(item)} alt={item.title || item.species} />
      <div className="detail-stack">
        <ListingDetailPanel item={item} />
        <Link className="ghost" to={`/seller/dashboard?tab=${sourceTab}`}>{sourceTab === 'listings' ? 'Back to My Listings' : 'Back to Marketplace'}</Link>
      </div>
    </main>
  )
}