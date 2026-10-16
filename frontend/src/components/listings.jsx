import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { ChevronLeft, ChevronRight, Image as ImageIcon, MessageCircle, PlayCircle, ShoppingBag, Video as VideoIcon, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ListingDetailsFields } from './forms.jsx'
import { renderStars } from './stars.jsx'
import { EmptyState } from './states.jsx'
import { Avatar, Badge, Modal, Stat } from './ui.jsx'
import { api, queryClient, roleRoutes } from '../lib/api.js'
import { LISTING_MEDIA_ACCEPT, SPECIES_OPTIONS } from '../lib/constants.js'
import { currency, formatStock, truncate } from '../lib/format.js'
import { useAccountSuspended } from '../lib/hooks.jsx'
import { bulkSize, galleryMediaFor, listingPayload, listingToForm, mapListing, matchesSpeciesFilter, minimumOrder, resolveListingImage } from '../lib/listing.js'
import { getSession, sellerProfilePath } from '../lib/session.js'



/**
 * Photo for one Shop by Species tile, resolved in priority order:
 *
 *   1. /species/<slug>.jpg -- a curated photograph dropped into
 *      frontend/public/species/ (bangus, tilapia, tuna, catfish, sea-bass,
 *      carp). Nothing else has to change when those files appear: the tile
 *      picks them up on the next load. Supply photos you hold the rights to.
 *   2. A real uploaded photo from an approved listing of that species -- so
 *      the row shows the actual stock on the marketplace today.
 *   3. The species artwork in SPECIES_PLACEHOLDERS, so a species with neither
 *      a curated photo nor a listing still renders.
 *
 * Each source is tried in turn via onError, which is the only way to know
 * whether a static file exists without shipping a manifest of them. The index
 * never advances past the last entry, so a failing final source cannot loop.
 */
export function SpeciesTileImage({ species, listings = [] }) {
  const [attempt, setAttempt] = useState(0)

  const sources = useMemo(() => {
    const slug = species.toLowerCase().replace(/\s+/g, '-')
    const fromListing = listings.find((listing) => (
      (listing.species || '').toLowerCase() === species.toLowerCase()
      && listing.media?.some((media) => media.type === 'photo' && media.url)
    ))

    return [
      `/species/${slug}.jpg`,
      fromListing ? resolveListingImage(fromListing) : null,
      resolveListingImage({ species }),
    ].filter(Boolean)
  }, [species, listings])

  const src = sources[Math.min(attempt, sources.length - 1)]
  // The fallback artwork is a line illustration that needs room around it; a
  // real photograph should fill the tile edge to edge.
  const isArtwork = src.endsWith('.svg')

  return (
    <span className="species-tile-media">
      <img
        src={src}
        alt={species}
        loading="lazy"
        className={isArtwork ? 'species-tile-art' : 'species-tile-photo'}
        onError={() => setAttempt((current) => Math.min(current + 1, sources.length - 1))}
      />
    </span>
  )
}

export function MarketplaceBrowser({ detailPath }) {
  const [filters, setFilters] = useState({ q: '', species: 'All', variety: 'All', municipality: 'All' })
  const { data = [] } = useQuery({
    queryKey: ['listings'],
    queryFn: async () => (await api.get('/listings')).data.map(mapListing),
    retry: false,
    placeholderData: [],
  })
  const filtered = data.filter((item) => {
    const haystack = `${item.title} ${item.species} ${item.variety || ''} ${item.seller} ${item.municipality}`.toLowerCase()
    return haystack.includes(filters.q.toLowerCase()) && matchesSpeciesFilter(item, filters) && (filters.municipality === 'All' || item.municipality === filters.municipality)
  })
  return (
    <div className="buyer-browse">
      <div className="filter-card inline">
        <label className="filter-label">Search<input placeholder="Search listings" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} /></label>
        <label className="filter-label">Species<select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value, variety: 'All' })}><option>All</option>{SPECIES_OPTIONS.map((s) => <option key={s}>{s}</option>)}</select></label>
        <VarietyFilter listings={data} filters={filters} setFilters={setFilters} />
        <MunicipalityFilter listings={data} filters={filters} setFilters={setFilters} />
      </div>
      {filtered.length ? (
        <ListingGrid items={filtered} detailPath={detailPath} />
      ) : (
        <EmptyState message="No listings available yet. Check back soon as verified sellers add their stock." />
      )}
    </div>
  )
}

/** Variety dropdown, built from the varieties actually listed for the chosen species. Hidden when there are none. */
export function VarietyFilter({ listings, filters, setFilters }) {
  const varieties = [...new Set(
    listings
      .filter((item) => item.variety && matchesSpeciesFilter(item, { species: filters.species }))
      .map((item) => item.variety.trim()),
  )].sort()
  if (!varieties.length) return null
  return (
    <label className="filter-label">Variety<select value={filters.variety} onChange={(e) => setFilters({ ...filters, variety: e.target.value })}><option>All</option>{varieties.map((v) => <option key={v}>{v}</option>)}</select></label>
  )
}

/**
 * Municipality options must come from the database, not a hardcoded Cebu list,
 * so a newly-created municipality appears in the listings filter immediately
 * after the /municipalities query refreshes. If that public endpoint is briefly
 * unavailable, fall back to the municipalities present in the loaded listings
 * so filtering still works instead of showing an empty dropdown.
 */
export function MunicipalityFilter({ listings, filters, setFilters }) {
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
  })
  const apiOptions = useMemo(() => (
    (municipalitiesQuery.data || [])
      .map((municipality) => municipality.name?.trim())
      .filter(Boolean)
      .sort((a, b) => a.localeCompare(b))
  ), [municipalitiesQuery.data])
  const listingOptions = useMemo(() => (
    [...new Set(
      listings
        .map((item) => item.municipality?.trim())
        .filter(Boolean),
    )].sort((a, b) => a.localeCompare(b))
  ), [listings])
  const options = apiOptions.length ? apiOptions : listingOptions

  useEffect(() => {
    if (filters.municipality === 'All' || !options.length || options.includes(filters.municipality)) return
    setFilters((current) => current.municipality === filters.municipality
      ? { ...current, municipality: 'All' }
      : current)
  }, [filters.municipality, options, setFilters])

  return (
    <label className="filter-label">
      Municipality
      <select value={filters.municipality} onChange={(e) => setFilters({ ...filters, municipality: e.target.value })}>
        <option>All</option>
        {options.map((municipality) => <option key={municipality}>{municipality}</option>)}
      </select>
    </label>
  )
}

export function ListingGrid({ items, mode = 'public', onSelect, detailPath }) {
  return <div className="listing-grid">{items.map((item) => <ListingCard key={item.id} item={item} mode={mode} onSelect={onSelect} detailPath={detailPath} />)}</div>
}

function ListingCard({ item, mode = 'public', onSelect, detailPath }) {
  const linkTarget = typeof detailPath === 'function' ? detailPath(item) : detailPath || `/listing/${item.id}`
  return (
    <article className="card listing">
      <div className="listing-media">
        <img className="listing-image" src={resolveListingImage(item)} alt={item.title || item.species || 'Fingerlings listing'} />
      </div>
      <h3>{item.title}</h3>
      <p className="listing-seller-row">
        <Avatar src={item.sellerProfile?.profile_picture} alt={item.seller} className="listing-seller-avatar" />
        {item.sellerProfile?.id ? <Link className="seller-name-link" to={sellerProfilePath(item.sellerProfile.id)} onClick={(e) => e.stopPropagation()}>{item.seller}</Link> : item.seller}{item.sellerContactName && item.sellerContactName !== item.seller ? ` (${item.sellerContactName})` : ''} · {item.municipality}
      </p>
      <p className="listing-rating"><span title={`${item.rating}/5`}>{renderStars(item.rating)}</span> <span className="muted">{Number(item.rating || 0).toFixed(1)}/5</span></p>
      {item.description && (
        <p className="listing-description-preview">
          {truncate(item.description)}
          {item.description.length > 110 && <Link className="read-more" to={linkTarget}>Read more</Link>}
        </p>
      )}
      <div className="listing-price-row">
        <span className="listing-price">{currency(item.price)}<small>/qty</small></span>
        {Number(item.quantity) <= 0 ? (
          <Badge tone="danger">Out of Stock</Badge>
        ) : (
          <span className="listing-stock">{formatStock(item)}</span>
        )}
      </div>
      {/* What a unit actually holds. Without this a "bulk" price is unreadable:
          the buyer cannot tell whether one bulk is 10 fish or 1,000. */}
      {item.unit_contents_label && (
        <p className="listing-minimum">{item.unit_contents_label}</p>
      )}
      {minimumOrder(item) > 1 && (
        <p className="listing-minimum">Minimum order: {minimumOrder(item).toLocaleString()} qty</p>
      )}
      {mode === 'buyer' ? (
        <button className="button full" type="button" onClick={() => onSelect?.(item)}>View Details</button>
      ) : (
        <Link className="button full" to={linkTarget}>View Details</Link>
      )}
    </article>
  )
}

export function ListingDetailPanel({ item, isBuyer = false, checkout, qty, setQty, onPay, addToCart }) {
  const navigate = useNavigate()
  const session = getSession()
  // A suspended buyer can look but not buy; the server refuses the order too.
  const suspended = useAccountSuspended()
  const outOfStock = Number(item.quantity) <= 0
  // Unit of Measurement + Minimum Order. The seller's minimum is the floor for
  // a valid order and available stock is the ceiling; when stock has fallen
  // below the minimum the listing simply can't be ordered right now. The
  // backend enforces the same rule (FingerlingListing::quantityIssue).
  const minimum = minimumOrder(item)
  const available = Number(item.quantity) || 0
  // A listing has no unit of its own; the buyer decides here whether to count
  // in single fingerlings or in bulks. `qty` ALWAYS holds a plain quantity --
  // the bulk input just multiplies on the way in -- so everything downstream
  // (the total, Add to Cart, and the checkout call in the parent) is unchanged
  // and there is only ever one number being ordered.
  const perBulk = bulkSize(item) || 0
  const [orderMode, setOrderMode] = useState('quantity')
  const buyingInBulk = orderMode === 'bulk' && perBulk > 0
  const bulksEntered = perBulk > 0 ? Math.round((Number(qty) || 0) / perBulk) : 0
  const belowMinimumStock = !outOfStock && available < minimum
  const enteredQty = Number(qty) || 0
  const safeQty = Math.min(Math.max(enteredQty, minimum), available || minimum)
  const quantityError = outOfStock || belowMinimumStock ? null
    : enteredQty < minimum ? `Minimum order for this listing is ${minimum.toLocaleString()} quantity.`
      : enteredQty > available ? `Only ${available.toLocaleString()} quantity available.`
        : null
  const canOrder = !outOfStock && !belowMinimumStock && !quantityError
  // Start the buyer at the seller's minimum rather than at 1, so the form
  // opens on a valid order. Keyed to the listing so it seeds once and never
  // fights the buyer while they type a different amount.
  const seededForListing = useRef(null)
  useEffect(() => {
    if (!isBuyer || !setQty || seededForListing.current === item.id) return
    seededForListing.current = item.id
    setQty(String(minimum))
  }, [isBuyer, setQty, item.id, minimum])
  const sellerUserId = item.sellerProfile?.user_id
  const canChat = sellerUserId && (!session || ['buyer', 'lgu_admin', 'super_admin'].includes(session.role))
  const chatSeller = () => {
    const chatPath = `${roleRoutes[session?.role] || '/buyer/dashboard'}?tab=messages&with=${sellerUserId}`
    if (!session) {
      navigate('/login', { state: { from: chatPath } })
    } else {
      navigate(chatPath)
    }
  }
  return (
    <article className="card listing-detail-panel">
      <div className="card-row">
        <h3>{item.title}</h3>
        {outOfStock && <Badge tone="danger">Out of Stock</Badge>}
      </div>
      {item.description ? (
        <p className="listing-description">{item.description}</p>
      ) : (
        <p className="helper-text">No description provided by the seller.</p>
      )}
      <div className="stats-inline">
        <Stat value={currency(item.price)} label="Per quantity" highlight />
        <Stat value={formatStock(item)} label="Available" />
        <Stat value={`${minimum.toLocaleString()} qty`} label="Minimum order" />
      </div>
      <div className="detail-meta">
        <span className="listing-seller-row"><strong>Hatchery/Farm:</strong> <Avatar src={item.sellerProfile?.profile_picture} alt={item.seller} className="listing-seller-avatar" /> {item.sellerProfile?.id ? <Link to={sellerProfilePath(item.sellerProfile.id)}>{item.seller}</Link> : item.seller}</span>
        {/* Sits with the hatchery it belongs to rather than in the stats row:
            it describes the SELLER, not this listing's price or stock. */}
        <span><strong>Seller rating:</strong> {renderStars(item.rating)} <span className="muted">{Number(item.rating || 0).toFixed(1)}/5</span></span>
        {item.sellerContactName && item.sellerContactName !== item.seller && <span><strong>Seller:</strong> {item.sellerContactName}</span>}
        {item.unit_description && <span><strong>What one bulk contains:</strong> {item.unit_description}</span>}
        <span><strong>Municipality:</strong> {item.municipality}</span>
      </div>
      <MediaGallery media={galleryMediaFor(item)} />
      {isBuyer && (
        <>
          {outOfStock ? (
            <p className="helper-text">This item is currently unavailable.</p>
          ) : belowMinimumStock ? (
            <p className="helper-text">
              Only {available.toLocaleString()} quantity left, which is below this seller&apos;s minimum order of {minimum.toLocaleString()}.
              This listing can&apos;t be ordered until they restock.
            </p>
          ) : (
            <>
              {/* The listing has no unit of its own -- the BUYER decides here
                  whether to think in single fingerlings or in bulks. Either
                  way the order is sent as a plain quantity. */}
              {perBulk > 0 && (
                <label>
                  Order by
                  <select
                    value={orderMode}
                    onChange={(e) => {
                      const mode = e.target.value
                      setOrderMode(mode)
                      const wanted = Math.max(minimum, Number(qty) || minimum)
                      // Switching to bulk snaps up to a whole bulk that still
                      // clears the seller's minimum.
                      setQty(String(mode === 'bulk'
                        ? Math.max(1, Math.ceil(wanted / perBulk)) * perBulk
                        : wanted))
                    }}
                  >
                    <option value="quantity">Quantity</option>
                    <option value="bulk">Bulk</option>
                  </select>
                </label>
              )}
              <label>
                {buyingInBulk ? 'Number of bulks' : 'Quantity'}
                <input
                  type="number"
                  min={buyingInBulk ? 1 : minimum}
                  max={buyingInBulk ? Math.floor(available / perBulk) : available}
                  value={buyingInBulk ? bulksEntered : qty}
                  onChange={(e) => setQty(buyingInBulk
                    ? String((Number(e.target.value) || 0) * perBulk)
                    : e.target.value)}
                />
                <span className="helper-text">
                  {buyingInBulk
                    ? `${bulksEntered.toLocaleString()} bulk${bulksEntered === 1 ? '' : 's'} = ${(bulksEntered * perBulk).toLocaleString()} quantity · ${currency(item.price)} each`
                    : `Minimum ${minimum.toLocaleString()} · ${available.toLocaleString()} available · ${currency(item.price)} each`}
                </span>
              </label>
              <div className="buy-actions">
                {/* Takes the whole remaining stock in one click. Always switches to
                    plain quantity: the stock need not be a whole number of bulks. */}
                <button
                  type="button"
                  className="ghost buy-all-button"
                  disabled={!buyingInBulk && enteredQty === available}
                  onClick={() => {
                    setOrderMode('quantity')
                    setQty(String(available))
                  }}
                >
                  Buy all stock ({available.toLocaleString()})
                </button>
                {addToCart && (
                  <button className="ghost" type="button" disabled={!canOrder || addToCart.isPending} onClick={() => addToCart.mutate(safeQty)}>
                    <ShoppingBag size={16} /> {addToCart.isPending ? 'Adding...' : 'Add to Cart'}
                  </button>
                )}
              </div>
            </>
          )}
          {quantityError && <p className="error">{quantityError}</p>}
          <div className="checkout-bar">
            <strong className="price">Total: {currency(canOrder ? safeQty * item.price : 0)}</strong>
            <button onClick={onPay} type="button" disabled={!canOrder || suspended}>{outOfStock ? 'Out of Stock' : 'Pay with PayMongo'}</button>
          </div>
          {suspended && (
            <p className="error">
              Your account is suspended, so you cannot place orders or pay right now. If you think this is a mistake,{' '}
              <Link to="/buyer/dashboard?tab=support">send a support ticket</Link>.
            </p>
          )}
          {addToCart?.isSuccess && (
            <p className="helper-text">
              Saved to your cart. <Link to="/buyer/dashboard?tab=cart">View cart</Link>
            </p>
          )}
          {addToCart?.error && <p className="error">{addToCart.error.response?.data?.message || 'Could not add this listing to your cart.'}</p>}
          {checkout?.error && <p className="error">{checkout.error.message}</p>}
        </>
      )}
      {!isBuyer && <p className="helper-text">Payment is reserved for buyer accounts only.</p>}
      {canChat && (
        <button className="ghost" type="button" onClick={chatSeller}>
          <MessageCircle size={16} /> Chat Seller
        </button>
      )}
    </article>
  )
}

export function MediaGallery({ media, title = 'Seller Care Photos & Videos' }) {
  const [enlargedIndex, setEnlargedIndex] = useState(null)
  const viewable = (media || []).filter((item) => item.url)

  const showPrev = useCallback(() => setEnlargedIndex((i) => (i - 1 + viewable.length) % viewable.length), [viewable.length])
  const showNext = useCallback(() => setEnlargedIndex((i) => (i + 1) % viewable.length), [viewable.length])

  useEffect(() => {
    if (enlargedIndex == null) return undefined
    const handleKey = (e) => {
      if (e.key === 'Escape') setEnlargedIndex(null)
      if (e.key === 'ArrowLeft') showPrev()
      if (e.key === 'ArrowRight') showNext()
    }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [enlargedIndex, showPrev, showNext])

  if (!media?.length) return null
  const enlarged = enlargedIndex != null ? viewable[enlargedIndex] : null

  return (
    <div className="media-gallery">
      {title && <h4>{title}</h4>}
      <div className="media-grid">
        {media.map((item) => (
          <div
            className={`media-tile ${item.url ? 'clickable' : ''}`}
            key={item.id}
            onClick={() => item.url && setEnlargedIndex(viewable.indexOf(item))}
          >
            {item.url ? (
              item.type === 'video' ? (
                <div className="media-video-preview">
                  <video src={item.url} muted playsInline preload="metadata" />
                  <span className="media-play-badge"><PlayCircle size={40} /></span>
                </div>
              ) : (
                <img src={item.url} alt="Farm photo" />
              )
            ) : (
              <span className="media-placeholder">{item.type === 'video' ? <VideoIcon size={22} /> : <ImageIcon size={22} />}</span>
            )}
          </div>
        ))}
      </div>
      {enlarged && (
        <div className="lightbox-overlay" onClick={() => setEnlargedIndex(null)}>
          <button type="button" className="lightbox-close" onClick={() => setEnlargedIndex(null)} aria-label="Close preview"><X size={22} /></button>
          {viewable.length > 1 && (
            <>
              <button type="button" className="lightbox-nav lightbox-prev" onClick={(e) => { e.stopPropagation(); showPrev() }} aria-label="Previous media"><ChevronLeft size={28} /></button>
              <button type="button" className="lightbox-nav lightbox-next" onClick={(e) => { e.stopPropagation(); showNext() }} aria-label="Next media"><ChevronRight size={28} /></button>
            </>
          )}
          {enlarged.type === 'video' ? (
            <video className="lightbox-video" src={enlarged.url} controls autoPlay onClick={(e) => e.stopPropagation()} />
          ) : (
            <img className="lightbox-image" src={enlarged.url} alt="Farm photo" onClick={(e) => e.stopPropagation()} />
          )}
        </div>
      )}
    </div>
  )
}

/**
 * Edit an existing listing without leaving the Listings tab. Carries the exact
 * same capabilities the old inline edit view had -- the detail fields plus the
 * photo/video manager -- so nothing was lost in the move to a popup.
 */
export function ListingEditModal({ listing, onClose }) {
  const [form, setForm] = useState(() => listingToForm(listing))

  const updateListing = useMutation({
    mutationFn: async () => (await api.patch(`/listings/${listing.id}`, listingPayload(form))).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
      onClose()
    },
  })

  return (
    <Modal
      title="Edit Listing"
      subtitle={listing.title}
      onClose={onClose}
      footer={
        <>
          <button type="button" onClick={() => updateListing.mutate()} disabled={updateListing.isPending}>
            {updateListing.isPending ? 'Saving...' : 'Update Listing'}
          </button>
          <button type="button" className="ghost" onClick={onClose} disabled={updateListing.isPending}>Cancel</button>
        </>
      }
    >
      {listing.approval_status !== 'approved' && (
        <div className="card-row modal-status-row">
          <Badge status={listing.approval_status} />
          <span className="muted">This listing was taken off the marketplace after review. Contact your LGU if you have questions.</span>
        </div>
      )}
      {listing.approval_status === 'rejected' && listing.rejection_reason && (
        <p className="error">Reason for rejection: {listing.rejection_reason}</p>
      )}
      <ListingDetailsFields form={form} setForm={setForm} />
      {updateListing.error && <p className="error">{updateListing.error.response?.data?.message || 'Could not save listing.'}</p>}
      <div className="modal-section">
        <h4>Listing Photos &amp; Videos</h4>
        <p className="helper-text">Upload up to 5 photos or videos (JPG, PNG, WEBP up to 25MB; MP4, MOV, WEBM up to 25MB). The first item is used as the primary image in the marketplace.</p>
        <ListingImageManager
          listingId={listing.id}
          media={listing.media || []}
          onChange={() => queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })}
        />
      </div>
    </Modal>
  )
}

/**
 * Registration approval banner for sellers whose registration has not been
 * approved yet (see backend App\Support\SellerApproval). Until it is, listing
 * creation is refused server-side, so the dashboard says so up front rather
 * than letting them fill in a form that will 403.
 */
export function SellerApprovalNotice({ seller, lguContact }) {
  const status = seller?.approval_status
  if (!status || status === 'approved') return null

  // A rejection is not final: spell out how to get a second review, with a
  // direct link to message the seller's own LGU and one to Help & Support.
  if (status === 'rejected') {
    const reason = (seller.registration_rejection_reason || '').trim()
    const town = lguContact?.municipality || seller.municipality?.name
    return (
      <div className="card approval-notice approval-notice-danger">
        <div className="card-row">
          <strong>Registration rejected</strong>
          <Badge tone="danger">{seller.approval_status_label || 'Rejected'}</Badge>
        </div>
        {reason && <p className="helper-text"><strong>Reason:</strong> {/[.!?]$/.test(reason) ? reason : `${reason}.`}</p>}
        <p className="helper-text">This is not final. To have your registration reviewed again:</p>
        <ol className="approval-steps helper-text">
          <li>
            Tell your LGU it is ready for review:{' '}
            {lguContact
              ? <Link to={`/seller/dashboard?tab=messages&with=${lguContact.id}`}>message {town ? `${town} LGU` : 'your LGU'} ({lguContact.name})</Link>
              : <span>contact {town ? `${town} LGU` : 'your LGU'}</span>}
            {' '}or <Link to="/seller/dashboard?tab=support">send a support ticket</Link> under &quot;Seller registration or verification&quot;.
          </li>
          <li>Once they approve it you will get a notification and can start creating listings.</li>
        </ol>
      </div>
    )
  }

  const copy = {
    pending: {
      tone: 'warning',
      title: 'Registration pending approval',
      body: 'Your hatchery registration is waiting to be reviewed by your LGU Admin. You can start creating listings as soon as it is approved.',
    },
  }[status]

  if (!copy) return null

  return (
    <div className={`card approval-notice approval-notice-${copy.tone}`}>
      <div className="card-row">
        <strong>{copy.title}</strong>
        <Badge tone={copy.tone === 'danger' ? 'danger' : 'warning'}>{seller.approval_status_label || 'Pending'}</Badge>
      </div>
      <p className="helper-text">{copy.body}</p>
    </div>
  )
}

function ListingImageManager({ listingId, media, onChange }) {
  const inputRef = useRef(null)
  const items = media || []
  const maxImages = 5

  const uploadMedia = useMutation({
    mutationFn: async (files) => {
      const formData = new FormData()
      files.forEach((file) => formData.append('photos[]', file))
      return (await api.post(`/listings/${listingId}/media`, formData)).data
    },
    onSuccess: (listing) => onChange(listing.media),
  })
  const deleteMedia = useMutation({
    mutationFn: async (mediaId) => (await api.delete(`/listings/${listingId}/media/${mediaId}`)).data,
    onSuccess: (listing) => onChange(listing.media),
  })
  const reorderMedia = useMutation({
    mutationFn: async (order) => (await api.patch(`/listings/${listingId}/media/reorder`, { order })).data,
    onSuccess: (listing) => onChange(listing.media),
  })

  const remainingSlots = maxImages - items.length

  const handleFiles = (e) => {
    const files = Array.from(e.target.files || []).slice(0, remainingSlots)
    e.target.value = ''
    if (files.length) uploadMedia.mutate(files)
  }

  const move = (index, direction) => {
    const target = index + direction
    if (target < 0 || target >= items.length) return
    const reordered = [...items]
    ;[reordered[index], reordered[target]] = [reordered[target], reordered[index]]
    reorderMedia.mutate(reordered.map((item) => item.id))
  }

  const busy = uploadMedia.isPending || deleteMedia.isPending || reorderMedia.isPending

  return (
    <div className="listing-image-manager">
      <div className="listing-image-grid">
        {items.map((item, index) => (
          <div className="listing-image-thumb" key={item.id}>
            {item.type === 'video' ? <video src={item.url} controls /> : <img src={item.url} alt="Farm photo" />}
            {index === 0 && <span className="pill listing-image-primary">Primary</span>}
            <div className="listing-image-thumb-actions">
              <button type="button" onClick={() => move(index, -1)} disabled={busy || index === 0} title="Move earlier">‹</button>
              <button type="button" onClick={() => deleteMedia.mutate(item.id)} disabled={busy} title="Remove media">Remove</button>
              <button type="button" onClick={() => move(index, 1)} disabled={busy || index === items.length - 1} title="Move later">›</button>
            </div>
          </div>
        ))}
        {remainingSlots > 0 && (
          <button type="button" className="listing-image-add" onClick={() => inputRef.current?.click()} disabled={busy}>
            + Add media<span className="muted">{items.length}/{maxImages}</span>
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={LISTING_MEDIA_ACCEPT} multiple hidden onChange={handleFiles} />
      {(uploadMedia.error || deleteMedia.error || reorderMedia.error) && (
        <p className="error">
          {uploadMedia.error?.response?.data?.message || deleteMedia.error?.response?.data?.message || reorderMedia.error?.response?.data?.message || 'Could not update listing images.'}
        </p>
      )}
    </div>
  )
}