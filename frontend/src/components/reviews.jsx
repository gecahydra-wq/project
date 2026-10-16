import { useMutation } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { ShieldCheck, Star, Store, Trash2 } from 'lucide-react'
import { useEffect } from 'react'
import { renderStars } from './stars.jsx'
import { EmptyState } from './states.jsx'
import { Avatar, RoleBadge, Section, SellerLink } from './ui.jsx'
import { api, queryClient } from '../lib/api.js'
import { usePeriodFilter } from '../lib/hooks.jsx'
import { reviewListingPath } from '../lib/listing.js'
import { sellerProfilePath } from '../lib/session.js'



function LguReviewCard({ review, onRemove, scope }) {
  const buyer = review.buyer
  const seller = review.sellerProfile
  const listing = review.order?.listing
  const listingPath = reviewListingPath(scope, listing?.id)

  return (
    <div className="card review-card review-card-buyer">
      <div className="review-card-head">
        <span className="review-badge"><RoleBadge role="buyer" /> reviewed seller</span>
        <span className="muted">{new Date(review.created_at).toLocaleDateString()}</span>
      </div>
      <div className="review-rating-line">
        {renderStars(review.rating)}
        <strong className="review-score">{Number(review.rating).toFixed(1)}<span>/5</span></strong>
      </div>
      {review.title && <p className="review-title">{review.title}</p>}
      <blockquote className="review-quote">{review.comment || 'No comment left.'}</blockquote>
      <div className="lgu-review-parties">
        <div className="lgu-review-party">
          <span className="lgu-review-party-label">Buyer</span>
          <Avatar src={buyer?.profile_picture} alt={buyer?.name} className="review-avatar" />
          <span>{buyer?.name || 'Unknown buyer'}</span>
        </div>
        <div className="lgu-review-party">
          <span className="lgu-review-party-label">Seller</span>
          <Avatar src={seller?.profile_picture} alt={seller?.hatchery_name} className="review-avatar" />
          <span>
            <SellerLink seller={seller} name={seller?.hatchery_name} />
            {seller?.user?.name && seller.user.name !== seller?.hatchery_name ? ` (${seller.user.name})` : ''}
          </span>
        </div>
      </div>
      <div className="detail-meta">
        {listing?.species && <span><strong>Species:</strong> {listing.species}{listing.variety ? ` (${listing.variety})` : ''}</span>}
        {listing?.title && <span><strong>Listing:</strong> {listing.title}</span>}
        {review.order?.order_number && <span><strong>Order ID:</strong> #{review.order.order_number}</span>}
      </div>
      <div className="review-card-footer">
        {listingPath && <Link className="ghost" to={listingPath}><Store size={15} /> View Listing</Link>}
        {onRemove && <button type="button" className="ghost danger" onClick={onRemove}><Trash2 size={15} /> Remove</button>}
      </div>
    </div>
  )
}

/**
 * "Reviews & Ratings" view for LGU Admin and Super Admin. Feedback runs one
 * way: buyers review sellers. Sellers rating buyers was removed, so there is no
 * second direction to filter or merge. Backed by GET {scope}/reviews.
 */
export function ReviewsAndRatingsSection({ data, scope, scopeLabel = 'on the platform' }) {
  const apiBase = scope === 'lgu' ? '/lgu' : '/super-admin'
  const queryKey = scope === 'lgu' ? ['lgu-reviews'] : ['super-admin-reviews']

  const removeReview = useMutation({
    mutationFn: async (id) => (await api.delete(`${apiBase}/reviews/${id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })

  const allReviews = [...(data?.buyer_reviews || [])].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
  const { shown: reviews, controls: periodControls, periodEmpty } = usePeriodFilter(allReviews, { noun: 'reviews posted' })

  return (
    <Section title="Reviews & Ratings">
      <p className="helper-text">Buyers reviewing sellers. Remove any review that isn&apos;t fair to the seller; their rating is recalculated automatically.</p>
      {removeReview.error && <p className="error">{removeReview.error?.response?.data?.message || 'Could not remove that review.'}</p>}
      {periodControls}
      {reviews.length ? (
        <div className="review-list">
          {reviews.map((review) => (
            <LguReviewCard
              key={`review-${review.id}`}
              review={review}
              scope={scope}
              onRemove={() => { if (window.confirm('Remove this buyer review? The seller’s rating will be recalculated.')) removeReview.mutate(review.id) }}
            />
          ))}
        </div>
      ) : periodEmpty || <EmptyState message={`No buyer reviews yet ${scopeLabel}.`} />}
    </Section>
  )
}

/**
 * The seller's own Notices to Explain -- raised when their average rating falls
 * below 3 after a review of 3 stars or fewer, or when a buyer's report is found valid. The seller
 * sends ONE explanation here and their LGU decides. A rejected explanation
 * suspends them; the next step is their LGU or Help & Support
 * (FinalRejectionHelp), like a rejected registration. If staff reinstate them
 * later, the card says so (reinstated_at, from the moderation log).
 */
/**
 * The reviews of 3 stars or fewer behind a low-rating notice (low_reviews from
 * SellerReputation::lowReviewsFor), newest first. Each opens the seller's
 * profile scrolled to that review, so the seller sees exactly what to explain
 * and the LGU what to judge.
 */
export function LowReviewsList({ notice, sellerProfileId }) {
  const reviews = notice.low_reviews || []
  if (notice.type !== 'low_rating' || !reviews.length) return null
  return (
    <div className="low-reviews">
      <strong>Reviews behind this notice (3 stars or fewer)</strong>
      <ul>
        {reviews.map((review) => (
          <li key={review.id}>
            <Link to={`${sellerProfilePath(sellerProfileId)}?review=${review.id}`}>
              <span className="low-reviews-stars">{'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}</span>
              {' '}{review.rating}/5
              {review.order_number ? ` · Order #${review.order_number}` : ''}
              {review.created_at ? ` · ${new Date(review.created_at).toLocaleDateString()}` : ''}
              {' — '}{(review.comment || review.title || 'No comment left.').slice(0, 120)}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

const REVIEW_STARS = [5, 4, 3, 2, 1]

/**
 * Buyer reviews at the foot of a seller profile. The old version listed bare
 * star rows, which told a buyer nothing about whether 5 stars came from one
 * order or forty -- so this leads with the shape of the score (average, total,
 * and how the ratings are spread) before the individual write-ups.
 *
 * The distribution is one series of counts, so it reads as a single-hue bar set
 * with the count printed on every row: the number beside each bar is the whole
 * data table, and nothing is carried by colour alone. The average is computed
 * from the reviews actually on screen, falling back to the stored seller rating
 * only when there are none to average.
 *
 * Every review is tied to one completed order of the buyer's own (see
 * ReviewController::store), which is what makes the "Verified purchase" mark a
 * fact rather than decoration.
 */
export function SellerReviewsSection({ reviews = [], fallbackAverage }) {
  // Opened from a Notice to Explain (LowReviewsList): land on that review.
  const [searchParams] = useSearchParams()
  const focusReviewId = searchParams.get('review')
  useEffect(() => {
    if (!focusReviewId || !reviews.length) return
    document.getElementById(`review-${focusReviewId}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }, [focusReviewId, reviews.length])
  const { shown: shownReviews, controls: periodControls, periodEmpty } = usePeriodFilter(reviews, { noun: 'reviews posted' })
  const total = reviews.length
  const average = total
    ? reviews.reduce((sum, review) => sum + Number(review.rating || 0), 0) / total
    : Number(fallbackAverage || 0)
  const written = reviews.filter((review) => (review.comment || '').trim()).length
  const distribution = REVIEW_STARS.map((star) => ({
    star,
    count: reviews.filter((review) => Math.round(Number(review.rating || 0)) === star).length,
  }))

  return (
    <Section title="Buyer Reviews" id="buyer-reviews">
      {total ? (
        <>
          <div className="card review-summary">
            <div className="review-summary-score">
              <strong className="review-summary-average">{average.toFixed(1)}<span>/5</span></strong>
              {renderStars(average)}
              <p className="muted">
                {total} verified review{total === 1 ? '' : 's'}
                {written ? ` · ${written} with a written comment` : ''}
              </p>
            </div>
            <ul className="review-distribution">
              {distribution.map(({ star, count }) => (
                <li key={star} title={`${count} of ${total} review${total === 1 ? '' : 's'} rated ${star} star${star === 1 ? '' : 's'}`}>
                  <span className="review-distribution-label">{star}<Star size={12} /></span>
                  <span className="review-distribution-track">
                    <span
                      className="review-distribution-bar"
                      style={{ width: `${total ? (count / total) * 100 : 0}%` }}
                    />
                  </span>
                  <span className="review-distribution-count">{count}</span>
                </li>
              ))}
            </ul>
          </div>
          {periodControls}
          {shownReviews.length ? (
          <div className="review-list">
            {shownReviews.map((review) => (
              <article className={`card review-item${String(review.id) === focusReviewId ? ' is-focused' : ''}`} id={`review-${review.id}`} key={review.id}>
                <div className="review-card-head">
                  <p className="review-author">
                    <Avatar src={review.buyer?.profile_picture} alt={review.buyer?.name} className="review-avatar" />
                    {review.buyer?.name || 'AbaiMarket Buyer'}
                  </p>
                  <span className="muted">{review.created_at ? new Date(review.created_at).toLocaleString() : ''}</span>
                </div>
                <div className="review-rating-line">
                  {renderStars(review.rating)}
                  <strong className="review-score">{Number(review.rating || 0).toFixed(1)}<span>/5</span></strong>
                  {review.order_id && <span className="review-badge"><ShieldCheck size={13} /> Verified purchase</span>}
                </div>
                {review.title && <p className="review-title">{review.title}</p>}
                <blockquote className="review-quote">{review.comment || 'No comment left.'}</blockquote>
              </article>
            ))}
          </div>
          ) : periodEmpty}
        </>
      ) : (
        <EmptyState
          icon={Star}
          title="No reviews yet"
          message="Buyers can leave a review once their order with this hatchery is completed."
        />
      )}
    </Section>
  )
}