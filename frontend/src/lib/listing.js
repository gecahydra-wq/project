import { DEFAULT_PLACEHOLDER_IMAGE, SPECIES_PLACEHOLDERS, UNIT_TYPES } from './constants.js'



export function resolveListingImage(item) {
  const uploaded = item?.media?.find((media) => media.type === 'photo' && media.url)
  if (uploaded) return uploaded.url
  const species = item?.species || ''
  const match = SPECIES_PLACEHOLDERS.find(([pattern]) => pattern.test(species))
  return match ? match[1] : DEFAULT_PLACEHOLDER_IMAGE
}

/**
 * The listing's media MINUS the one already shown as the main image, so the
 * "Seller Care Photos & Videos" gallery never repeats the thumbnail the buyer
 * is already looking at. A listing with a single photo therefore has no
 * gallery at all -- MediaGallery renders nothing for an empty list.
 *
 * Excludes the exact item resolveListingImage() picked rather than simply
 * dropping the first: the thumbnail is the first *photo*, so for a listing
 * whose first upload is a video, slicing index 0 would hide the video and
 * still show the thumbnail again.
 */
export function galleryMediaFor(item) {
  const thumbnail = item?.media?.find((media) => media.type === 'photo' && media.url)
  return (item?.media || []).filter((media) => media.id !== thumbnail?.id)
}

/*
 * There is no longer a unit picker on the listing form: every listing is
 * counted, priced and stocked in single fingerlings, and "bulk" is a choice
 * the BUYER makes at order time. UNIT_TYPES above is kept only so listings
 * created while units existed still render their old labels rather than
 * silently reading as something else.
 */

export function unitMeta(unitType) {
  return UNIT_TYPES.find((unit) => unit.value === unitType) || UNIT_TYPES[0]
}

/** "pc" / "kg" / "bulk" -- the per-unit price suffix. */
export function unitLabel(item) {
  return item?.unit_label || unitMeta(item?.unit_type).short
}

/** "pcs" / "kg" / "bulk" -- labels a quantity. */
export function unitLabelPlural(item) {
  return item?.unit_label_plural || unitMeta(item?.unit_type).plural
}

/** A listing's minimum order, never below 1. */
export function minimumOrder(item) {
  return Math.max(1, Number(item?.minimum_order) || 1)
}

/** Fish in one bulk, or null when the seller has not stated it. */
export function bulkSize(item) {
  const n = Number(item?.pieces_per_unit || 0)
  return n > 0 ? n : null
}

export function mapListing(item) {
  return {
    ...item,
    seller: item.sellerProfile?.hatchery_name || item.seller_profile_id,
    sellerContactName: item.sellerProfile?.user?.name || '',
    municipality: item.municipality?.name || 'Unknown',
    price: item.price_per_piece,
    status: item.approval_status === 'approved' ? 'Approved' : item.approval_status === 'pending' ? 'Pending' : 'Rejected',
    rating: item.sellerProfile?.rating ?? 0,
    description: item.description || '',
  }
}

/**
 * Display shape for a seller row from GET /sellers. LandingPage and
 * SellersPage share the ['sellers'] query cache, so they MUST cache the same
 * shape: whichever mounts first decides what the other reads. Mapping inside
 * both queryFns (rather than after the query, per page) is what keeps them
 * agreeing -- note `municipality` collapses to a string here, and handing
 * SellerGrid the raw object instead crashes the render.
 */
export function mapSeller(seller) {
  return {
    id: seller.id,
    name: seller.hatchery_name,
    municipality: seller.municipality?.name || 'Unknown',
    rating: seller.rating,
    verified: seller.verified,
    listings: seller.listings_count ?? 0,
    profile_picture: seller.profile_picture,
  }
}

/**
 * Species match ignores capitalisation (older listings were typed by hand,
 * e.g. "tilapia"), and a variety narrows within the species -- a GIFT Tilapia
 * is still a Tilapia.
 */
export function matchesSpeciesFilter(item, filters) {
  const same = (a, b) => (a || '').trim().toLowerCase() === (b || '').trim().toLowerCase()
  if (filters.species !== 'All' && !same(item.species, filters.species)) return false
  return !filters.variety || filters.variety === 'All' || same(item.variety, filters.variety)
}

/** Turn an existing listing into the form shape above. */
export function listingToForm(listing) {
  return {
    species: listing.species || '',
    variety: listing.variety || '',
    quantity: String(listing.quantity ?? ''),
    price: String(listing.price_per_piece ?? ''),
    description: listing.description || '',
    minimum_order: String(listing.minimum_order ?? 1),
    pieces_per_unit: listing.pieces_per_unit ? String(listing.pieces_per_unit) : '',
    unit_description: listing.unit_description || '',
  }
}

/** The request body both the create and the update call send. */
export function listingPayload(form) {
  return {
    species: form.species,
    variety: form.variety?.trim() || null,
    title: `${form.variety?.trim() ? `${form.variety.trim()} ` : ''}${form.species} Fingerlings`,
    description: form.description,
    quantity: Number(form.quantity),
    price_per_piece: Number(form.price),
    minimum_order: Math.max(1, Number(form.minimum_order) || 1),
    pieces_per_unit: Number(form.pieces_per_unit) || null,
    unit_description: form.unit_description?.trim() || null,
  }
}

export function reviewListingPath(scope, listingId) {
  if (!listingId) return null
  return scope === 'lgu' ? `/lgu/listings/${listingId}` : `/admin/listings/${listingId}`
}