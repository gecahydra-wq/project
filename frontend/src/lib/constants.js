

export const SPECIES_PLACEHOLDERS = [
  [/bangus|milkfish/i, '/placeholders/bangus.svg'],
  [/tilapia/i, '/placeholders/tilapia.svg'],
  [/catfish/i, '/placeholders/catfish.svg'],
  [/carp/i, '/placeholders/carp.svg'],
  // Grouper is no longer offered (replaced by Tuna in SPECIES_OPTIONS) but the
  // mapping stays so any listing already recorded against it keeps its
  // artwork. Tuna has no illustration yet and falls through to the default.
  [/grouper/i, '/placeholders/grouper.svg'],
  [/sea.?bass/i, '/placeholders/sea-bass.svg'],
]
// The species the marketplace filters by, in one place -- Browse, the in-app
// marketplace browser and the landing page's Shop by Species row all read this
// list, so they can never drift apart. Each one has artwork in
// SPECIES_PLACEHOLDERS above.
export const SPECIES_OPTIONS = ['Bangus', 'Tilapia', 'Tuna', 'Catfish', 'Sea Bass', 'Carp']
export const DEFAULT_PLACEHOLDER_IMAGE = '/placeholders/default.svg'
export const DEFAULT_AVATAR_IMAGE = '/placeholders/avatar.svg'
export const DEFAULT_COVER_IMAGE = '/placeholders/cover.svg'
// System placeholder bio assigned at seller registration (see AuthController).
// Once the seller is verified it's stale/misleading, so it's hidden then --
// only a real, seller-written description shows on a verified profile.
export const DEFAULT_SELLER_DESCRIPTION = 'New hatchery profile pending LGU verification.'
export const IMAGE_UPLOAD_ACCEPT = 'image/jpeg,image/png,image/webp'
export const LISTING_MEDIA_ACCEPT = 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm'

/**
 * Unit of Measurement. The backend is the source of truth (see
 * FingerlingListing::UNIT_TYPES) and sends unit_label/unit_label_plural on
 * every listing; these are the fallbacks for anything that predates the
 * feature or comes from a payload without them, and the option list the
 * seller's form renders.
 */
export const UNIT_TYPES = [
  { value: 'piece', label: 'Per Piece', short: 'pc', plural: 'pcs' },
  { value: 'kilogram', label: 'Per Kilogram', short: 'kg', plural: 'kg' },
  { value: 'bulk', label: 'Per Bulk', short: 'bulk', plural: 'bulk' },
]

/** Mirrors App\Support\PayoutAccount::BANKS. "Other" lets the user type a bank that is not listed. */
export const PH_BANKS = [
  'BDO Unibank', 'Bank of the Philippine Islands (BPI)', 'Metrobank', 'Land Bank of the Philippines',
  'Philippine National Bank (PNB)', 'China Bank', 'Security Bank', 'UnionBank of the Philippines',
  'RCBC', 'EastWest Bank', 'Development Bank of the Philippines (DBP)', 'PSBank',
  'Asia United Bank (AUB)', 'Bank of Commerce', 'Philippine Veterans Bank', 'Maybank Philippines',
  'CIMB Bank Philippines', 'PBCom', 'Sterling Bank of Asia', 'GoTyme Bank', 'Tonik Digital Bank',
  'UNO Digital Bank', 'Maya Bank', 'BDO Network Bank', 'Card Bank',
]
export const OTHER_BANK = '__other__'

export const REQUIRED_FIELDS_MESSAGE = 'Please fill in all required fields.'

/**
 * The first problem with a filled-in withdrawal form, or null. Mirrors
 * App\Support\PayoutAccount on the server -- same patterns, same messages:
 * GCash / Maya need an 11-digit mobile number starting with 09, a bank
 * transfer needs a 10-16 digit account number, and the amount must be a
 * positive number.
 */
// Mirrors PayoutAccount::MIN_AMOUNT on the backend (sellers and LGUs alike).
export const MIN_WITHDRAWAL = 100

/** A blank listing form, shared by the create form and the edit popup. */
export const EMPTY_LISTING_FORM = {
  species: '',
  variety: '',
  quantity: '',
  price: '',
  description: '',
  minimum_order: '1',
  pieces_per_unit: '',
  unit_description: '',
}