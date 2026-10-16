import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Heart, MessageCircle, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { StagedImagePicker } from '../../components/forms.jsx'
import { ListingGrid, MediaGallery } from '../../components/listings.jsx'
import { ReportUserAction } from '../../components/moderation.jsx'
import { SellerReviewsSection } from '../../components/reviews.jsx'
import { renderStars } from '../../components/stars.jsx'
import { EmptyState, LoadingState } from '../../components/states.jsx'
import { Avatar, Badge, PeriodFilteredRows, RoleBadge, Section, Stat } from '../../components/ui.jsx'
import { api, queryClient, roleRoutes } from '../../lib/api.js'
import { statusChartLabel } from '../../lib/chartColors.js'
import { DEFAULT_AVATAR_IMAGE, DEFAULT_COVER_IMAGE, DEFAULT_SELLER_DESCRIPTION, LISTING_MEDIA_ACCEPT } from '../../lib/constants.js'
import { currency } from '../../lib/format.js'
import { getSession } from '../../lib/session.js'



/**
 * Seller Posts feed -- a seller's farm/hatchery updates, shown on the shared
 * Seller Profile page to every viewer (buyer/seller/LGU/Super Admin). Only the
 * profile owner (isOwner) sees the composer and per-post Edit/Delete controls;
 * everyone else is read-only. Entirely separate from listing media.
 */
function SellerPostsSection({ seller, posts, isOwner }) {
  return (
    <Section title="Farm Posts">
      {isOwner && <SellerPostComposer />}
      {(posts || []).length ? (
        <div className="seller-post-feed">
          {posts.map((post) => <SellerPostCard key={post.id} post={post} isOwner={isOwner} seller={seller} />)}
        </div>
      ) : (
        <EmptyState message={isOwner ? 'You have not posted any farm updates yet. Share your first update above.' : 'This seller has not posted any farm updates yet.'} />
      )}
    </Section>
  )
}

function SellerPostComposer() {
  const [body, setBody] = useState('')
  const [staged, setStaged] = useState([])

  const addStaged = (files) => setStaged((current) => [...current, ...files.map((file) => ({ file, previewUrl: URL.createObjectURL(file) }))])
  const removeStaged = (index) => setStaged((current) => {
    URL.revokeObjectURL(current[index].previewUrl)
    return current.filter((_, i) => i !== index)
  })
  const clearStaged = () => setStaged((current) => {
    current.forEach((item) => URL.revokeObjectURL(item.previewUrl))
    return []
  })

  const create = useMutation({
    mutationFn: async () => {
      const formData = new FormData()
      if (body.trim()) formData.append('body', body.trim())
      staged.forEach((item) => formData.append('media[]', item.file))
      return (await api.post('/seller/posts', formData)).data
    },
    onSuccess: () => {
      setBody('')
      clearStaged()
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })

  const canPost = (body.trim() || staged.length) && !create.isPending

  return (
    <div className="card seller-post-composer">
      <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Share a farm update -- a new harvest, freshly stocked fingerlings, feeding video, or announcement..." />
      <StagedImagePicker files={staged} onAdd={addStaged} onRemove={removeStaged} maxImages={10} />
      {create.error && <p className="error">{create.error.response?.data?.message || 'Could not publish your post.'}</p>}
      <button type="button" onClick={() => create.mutate()} disabled={!canPost}>{create.isPending ? 'Posting...' : 'Post Update'}</button>
    </div>
  )
}

function SellerPostCard({ post, isOwner, seller }) {
  const session = getSession()
  const [editing, setEditing] = useState(false)
  const [body, setBody] = useState(post.body || '')
  const [media, setMedia] = useState(post.media || [])
  const [showComments, setShowComments] = useState(false)
  const [commentDraft, setCommentDraft] = useState('')
  const edited = post.updated_at && post.created_at && post.updated_at !== post.created_at
  const comments = post.comments || []
  const canInteract = !!session

  const save = useMutation({
    mutationFn: async () => (await api.patch(`/seller/posts/${post.id}`, { body: body.trim() || null })).data,
    onSuccess: () => {
      setEditing(false)
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })
  const remove = useMutation({
    mutationFn: async () => (await api.delete(`/seller/posts/${post.id}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-profile'] }),
  })
  const toggleLike = useMutation({
    mutationFn: async () => (await api.post(`/seller-posts/${post.id}/like`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-profile'] }),
  })
  const addComment = useMutation({
    mutationFn: async () => (await api.post(`/seller-posts/${post.id}/comments`, { body: commentDraft.trim() })).data,
    onSuccess: () => {
      setCommentDraft('')
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })
  const deleteComment = useMutation({
    mutationFn: async (commentId) => (await api.delete(`/seller-posts/comments/${commentId}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seller-profile'] }),
  })

  const cancelEdit = () => {
    setEditing(false)
    setBody(post.body || '')
    setMedia(post.media || [])
  }
  const canDeleteComment = (comment) => session && (comment.user_id === session.id || session.role === 'super_admin')
  const submitComment = () => {
    if (commentDraft.trim() && !addComment.isPending) addComment.mutate()
  }

  return (
    <article className="card seller-post">
      <div className="seller-post-head">
        <div className="seller-post-author">
          <Avatar src={seller?.profile_picture} alt={seller?.hatchery_name} className="seller-post-avatar" />
          <span className="seller-post-author-info">
            <span className="seller-post-author-name"><strong>{seller?.hatchery_name || 'Seller'}</strong><RoleBadge role={seller?.user?.role || 'seller'} /></span>
            <span className="muted">{new Date(post.created_at).toLocaleString()}{edited ? ' · edited' : ''}</span>
          </span>
        </div>
        {isOwner && !editing && (
          <div className="row-actions">
            <button type="button" className="ghost" onClick={() => setEditing(true)}>Edit</button>
            <button type="button" className="ghost danger" onClick={() => { if (window.confirm('Delete this post permanently?')) remove.mutate() }} disabled={remove.isPending}>Delete</button>
          </div>
        )}
      </div>
      {editing ? (
        <>
          <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Update your post..." />
          <SellerPostMediaManager postId={post.id} media={media} onChange={setMedia} />
          {save.error && <p className="error">{save.error.response?.data?.message || 'Could not save changes.'}</p>}
          <div className="row-actions">
            <button type="button" onClick={() => save.mutate()} disabled={save.isPending}>{save.isPending ? 'Saving...' : 'Save Changes'}</button>
            <button type="button" className="ghost" onClick={cancelEdit}>Cancel</button>
          </div>
        </>
      ) : (
        <>
          {post.body && <p className="seller-post-body">{post.body}</p>}
          <MediaGallery media={post.media} title={null} />
          {(post.likes_count > 0 || comments.length > 0) && (
            <div className="seller-post-tally muted">
              {post.likes_count > 0 && <span><Heart size={13} fill="currentColor" /> {post.likes_count}</span>}
              {comments.length > 0 && <span>{comments.length} comment{comments.length === 1 ? '' : 's'}</span>}
            </div>
          )}
          <div className="seller-post-engagement">
            <button type="button" className={`ghost seller-post-action ${post.liked_by_me ? 'liked' : ''}`} onClick={() => toggleLike.mutate()} disabled={!canInteract || toggleLike.isPending}>
              <Heart size={16} fill={post.liked_by_me ? 'currentColor' : 'none'} /> {post.liked_by_me ? 'Liked' : 'Like'}
            </button>
            <button type="button" className="ghost seller-post-action" onClick={() => setShowComments((current) => !current)}>
              <MessageCircle size={16} /> Comment
            </button>
          </div>
          {showComments && (
            <div className="seller-post-comments">
              {comments.map((comment) => (
                <div className="seller-post-comment" key={comment.id}>
                  <Avatar src={comment.user?.profile_picture} alt={comment.user?.name} className="seller-post-comment-avatar" />
                  <div className="seller-post-comment-body">
                    <div className="seller-post-comment-head">
                      <strong>{comment.user?.name || 'AbaiMarket user'}</strong>
                      <RoleBadge role={comment.user?.role} />
                      <span className="muted">{new Date(comment.created_at).toLocaleDateString()}</span>
                      {canDeleteComment(comment) && (
                        <button type="button" className="seller-post-comment-delete" onClick={() => deleteComment.mutate(comment.id)} disabled={deleteComment.isPending} aria-label="Delete comment"><Trash2 size={13} /></button>
                      )}
                    </div>
                    <p>{comment.body}</p>
                  </div>
                </div>
              ))}
              {canInteract ? (
                <div className="seller-post-comment-form">
                  <input
                    value={commentDraft}
                    onChange={(e) => setCommentDraft(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitComment() } }}
                    placeholder="Write a comment..."
                  />
                  <button type="button" onClick={submitComment} disabled={!commentDraft.trim() || addComment.isPending}>Post</button>
                </div>
              ) : (
                <p className="helper-text">Log in to like or comment on this post.</p>
              )}
              {addComment.error && <p className="error">Could not post your comment. Please try again.</p>}
            </div>
          )}
        </>
      )}
      {remove.error && <p className="error">{remove.error.response?.data?.message || 'Could not delete this post.'}</p>}
    </article>
  )
}

function SellerPostMediaManager({ postId, media, onChange }) {
  const inputRef = useRef(null)
  const items = media || []
  const maxMedia = 10

  const addMedia = useMutation({
    mutationFn: async (files) => {
      const formData = new FormData()
      files.forEach((file) => formData.append('media[]', file))
      return (await api.post(`/seller/posts/${postId}/media`, formData)).data
    },
    onSuccess: (postData) => {
      onChange(postData.media)
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })
  const deleteMedia = useMutation({
    mutationFn: async (mediaId) => (await api.delete(`/seller/posts/${postId}/media/${mediaId}`)).data,
    onSuccess: (postData) => {
      onChange(postData.media)
      queryClient.invalidateQueries({ queryKey: ['seller-profile'] })
    },
  })

  const remainingSlots = maxMedia - items.length
  const busy = addMedia.isPending || deleteMedia.isPending

  const handleFiles = (e) => {
    const files = Array.from(e.target.files || []).slice(0, remainingSlots)
    e.target.value = ''
    if (files.length) addMedia.mutate(files)
  }

  return (
    <div className="listing-image-manager">
      <div className="listing-image-grid">
        {items.map((item) => (
          <div className="listing-image-thumb" key={item.id}>
            {item.type === 'video' ? <video src={item.url} controls /> : <img src={item.url} alt="Post media" />}
            <div className="listing-image-thumb-actions">
              <button type="button" onClick={() => deleteMedia.mutate(item.id)} disabled={busy} title="Remove media">Remove</button>
            </div>
          </div>
        ))}
        {remainingSlots > 0 && (
          <button type="button" className="listing-image-add" onClick={() => inputRef.current?.click()} disabled={busy}>
            + Add media<span className="muted">{items.length}/{maxMedia}</span>
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={LISTING_MEDIA_ACCEPT} multiple hidden onChange={handleFiles} />
      {(addMedia.error || deleteMedia.error) && (
        <p className="error">{addMedia.error?.response?.data?.message || deleteMedia.error?.response?.data?.message || 'Could not update post media.'}</p>
      )}
    </div>
  )
}

export function SellerProfilePage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const session = getSession()
  const { data } = useQuery({
    queryKey: ['seller-profile', id],
    queryFn: async () => (await api.get(`/sellers/${id}`)).data,
    retry: false,
  })

  if (!data) return <main className="auth-page"><LoadingState label="Loading seller profile..." /></main>

  const seller = data.seller
  const reviews = data.reviews || []
  const sellerListings = (data.listings || []).map((item) => ({
    ...item,
    sellerProfile: seller,
    seller: seller.hatchery_name,
    municipality: seller.municipality?.name,
    price: item.price_per_piece,
    status: item.approval_status === 'approved' ? 'Approved' : 'Pending',
    rating: seller.rating,
  }))
  const galleryMedia = (seller.gallery || []).map((url, index) => ({ id: `gallery-${index}`, type: 'photo', title: `Farm photo ${index + 1}`, url }))
  const isOwner = session?.role === 'seller' && seller.user_id === session.id
  const farmDetails = [
    ['Farming Methods', seller.farming_methods],
    ['Fish Raising Practices', seller.fish_raising_practices],
    ['Water Source', seller.water_source],
    ['Feeding Practices', seller.feeding_practices],
    ['Farm History', seller.farm_history],
    ['Certifications', seller.certifications],
  ].filter(([, value]) => value)

  return (
    <main className="seller-profile-page">
      <img className="seller-cover-photo" src={seller.cover_photo || DEFAULT_COVER_IMAGE} alt={`${seller.hatchery_name} cover`} />
      <section className="card seller-profile-header">
        <div className="seller-header-row">
          <img className="seller-avatar" src={seller.profile_picture || DEFAULT_AVATAR_IMAGE} alt={`${seller.hatchery_name} profile`} />
          <div>
            <div className="card-row">
              <h1>{seller.hatchery_name}</h1>
              {seller.verified && <Badge tone="success">Verified Seller</Badge>}
            </div>
            <div className="stats-inline">
              {/* Jumps to the Buyer Reviews section further down this page. */}
              <Stat
                value={renderStars(seller.rating)}
                label={`${seller.rating}/5 · ${reviews.length} review${reviews.length === 1 ? '' : 's'}`}
                to="#buyer-reviews"
                onClick={(event) => {
                  event.preventDefault()
                  document.getElementById('buyer-reviews')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
                }}
              />
              <Stat value={sellerListings.length} label="Active listings" />
              <Stat value={data.completed_sales ?? 0} label="Completed sales" />
            </div>
          </div>
        </div>
        {seller.description && !(seller.verified && seller.description === DEFAULT_SELLER_DESCRIPTION) && <p className="listing-description">{seller.description}</p>}
        <div className="detail-meta">
          {seller.user?.name && seller.user.name !== seller.hatchery_name && <span><strong>Seller Name:</strong> {seller.user.name}</span>}
          <span><strong>Municipality:</strong> {seller.municipality?.name || 'Unknown'}</span>
          <span><strong>Address:</strong> {seller.address || 'Not provided'}</span>
          <span><strong>Contact:</strong> {seller.user?.phone || 'Not provided'}</span>
          <span><strong>Email:</strong> {seller.user?.email}</span>
          {seller.years_experience != null && <span><strong>Experience:</strong> {seller.years_experience} year{seller.years_experience === 1 ? '' : 's'}</span>}
        </div>
        {seller.user_id && (!session || ['buyer', 'lgu_admin', 'super_admin'].includes(session.role)) && (
          <button
            className="button"
            type="button"
            onClick={() => {
              const chatPath = `${roleRoutes[session?.role] || '/buyer/dashboard'}?tab=messages&with=${seller.user_id}`
              navigate(session ? chatPath : '/login', session ? undefined : { state: { from: chatPath } })
            }}
          >
            <MessageCircle size={16} /> Chat Seller
          </button>
        )}
        {/* Reporting is buyer -> seller only; admins moderate from their own
            dashboards and a seller cannot report another seller. */}
        {seller.user_id && session?.role === 'buyer' && (
          <ReportUserAction userId={seller.user_id} userName={seller.hatchery_name} label="Report Seller" />
        )}
      </section>
      {farmDetails.length > 0 && (
        <Section title="About This Hatchery">
          <div className="farm-details-grid">
            {farmDetails.map(([label, value]) => (
              <div className="card" key={label}>
                <h4>{label}</h4>
                <p className="listing-description">{value}</p>
              </div>
            ))}
          </div>
        </Section>
      )}
      <SellerPostsSection seller={seller} posts={data.posts} isOwner={isOwner} />
      <Section title="Available Stock">
        {sellerListings.length ? (
          <ListingGrid
            items={sellerListings}
            detailPath={
              session?.role === 'buyer' ? (item) => `/buyer/listings/${item.id}?source=browse`
                : session?.role === 'seller' ? (item) => `/seller/listings/${item.id}?source=marketplace`
                  : session?.role === 'lgu_admin' ? (item) => `/lgu/listings/${item.id}`
                    : session?.role === 'super_admin' ? (item) => `/admin/listings/${item.id}`
                      : undefined
            }
          />
        ) : <EmptyState message="No listings available from this seller yet." />}
      </Section>
      <MediaGallery media={galleryMedia} title="Farm Gallery" />
      <SellerReviewsSection reviews={reviews} fallbackAverage={seller.rating} />
    </main>
  )
}



export function BuyerProfileForSellerPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['seller-buyer-profile', id],
    queryFn: async () => (await api.get(`/seller/buyers/${id}`)).data,
    retry: false,
  })

  if (isLoading) return <main className="auth-page"><LoadingState label="Loading buyer profile..." /></main>
  if (isError || !data) {
    return (
      <main className="auth-page">
        <section className="result-card">
          <h1>Buyer profile unavailable</h1>
          <p>{error?.response?.data?.message || 'You can only view profiles of buyers who have ordered from you or messaged you.'}</p>
          <Link className="button" to="/seller/dashboard?tab=orders">Back to Orders</Link>
        </section>
      </main>
    )
  }

  const buyer = data.buyer
  const stats = data.stats
  const reviews = data.reviews || []
  const sellerOrders = data.seller_orders || []

  return (
    <main className="seller-profile-page">
      <section className="card seller-profile-header">
        <div className="seller-header-row">
          <Avatar src={buyer.profile_picture} alt={buyer.name} className="seller-avatar" />
          <div>
            <div className="card-row"><h1>{buyer.name}</h1><RoleBadge role="buyer" /></div>
            <div className="stats-inline">
              <Stat value={stats.completed_orders} label="Completed with you" />
              <Stat value={stats.total_orders} label="Orders with you" />
              <Stat value={stats.completed_orders_all} label="Completed orders (all sellers)" />
            </div>
          </div>
        </div>
        <div className="detail-meta">
          <span><strong>Municipality:</strong> {buyer.municipality?.name || 'Not provided'}</span>
          <span><strong>Member Since:</strong> {new Date(buyer.created_at).toLocaleDateString()}</span>
          <span><strong>Total Spent (with you):</strong> {currency(stats.total_spent)}</span>
          <span><strong>Most Recent Purchase:</strong> {stats.most_recent_purchase ? new Date(stats.most_recent_purchase).toLocaleDateString() : 'None yet'}</span>
        </div>
        <div className="row-actions">
          <button className="button" type="button" onClick={() => navigate(`/seller/dashboard?tab=messages&with=${buyer.id}`)}>
            <MessageCircle size={16} /> Message Buyer
          </button>
          <ReportUserAction userId={buyer.id} userName={buyer.name} label="Report Buyer" />
        </div>
      </section>


      <Section title="Order History with You">
        <p className="helper-text">
          Whether a buyer completes what they start is the useful signal about them -- more so than a score, which a seller
          could leave out of irritation at a cancelled order.
        </p>
        <PeriodFilteredRows rows={sellerOrders}>
          {(shown) => (shown.length ? (
          <div className="item-list">
            {shown.map((order) => (
              <div className="card action" key={order.id}>
                <div>
                  <div className="card-row">
                    <strong>{order.order_number}</strong>
                    <Badge status={order.status}>{statusChartLabel(order.status)}</Badge>
                  </div>
                  <p className="muted">
                    {order.listing?.species || order.listing?.title || 'Listing'} · {currency(order.total_amount)}
                    {order.created_at ? ` · ${new Date(order.created_at).toLocaleDateString()}` : ''}
                  </p>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState message="No orders with this buyer yet." />)}
        </PeriodFilteredRows>
      </Section>

      <Section title="Reviews from this Buyer">
        <p className="helper-text">Reviews this buyer left on your orders.</p>
        <PeriodFilteredRows rows={reviews} noun="reviews posted">
          {(shown) => (shown.length ? (
          <div className="review-list">
            {shown.map((review) => (
              <div className="card review-item" key={review.id}>
                <div className="card-row">
                  <p className="review-author"><Avatar src={buyer.profile_picture} alt={buyer.name} className="review-avatar" />{buyer.name} <RoleBadge role="buyer" /></p>
                  <strong>{renderStars(review.rating)}</strong>
                </div>
                {review.title && <p className="review-title">{review.title}</p>}
                <p>{review.comment || 'No comment left.'}</p>
                <div className="detail-meta">
                  {review.order?.listing?.species && <span><strong>Species:</strong> {review.order.listing.species}</span>}
                  {review.order?.order_number && <span><strong>Order ID:</strong> #{review.order.order_number}</span>}
                  <span className="muted">{new Date(review.created_at).toLocaleDateString()}</span>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState message="This buyer hasn't left a review yet." />)}
        </PeriodFilteredRows>
      </Section>
    </main>
  )
}