import { Camera, Check, Eye, EyeOff, TriangleAlert } from 'lucide-react'
import { useRef } from 'react'
import { LISTING_MEDIA_ACCEPT, MIN_WITHDRAWAL, OTHER_BANK, PH_BANKS, SPECIES_OPTIONS } from '../lib/constants.js'
import { currency } from '../lib/format.js'
import { unitMeta } from '../lib/listing.js'
import { PASSWORD_RULES } from '../lib/validation.js'



/**
 * Bank picker + the reminder to double-check the account, shown only for a
 * bank transfer. Shared by the seller and LGU withdrawal forms.
 */
export function BankTransferFields({ form, setForm }) {
  if (form.method !== 'bank_transfer') return null
  return (
    <>
      <select value={form.bank_choice} onChange={(e) => setForm({ ...form, bank_choice: e.target.value })} aria-label="Bank">
        <option value="">Choose your bank</option>
        {PH_BANKS.map((bank) => <option key={bank} value={bank}>{bank}</option>)}
        <option value={OTHER_BANK}>Other bank (type the name)</option>
      </select>
      {form.bank_choice === OTHER_BANK && (
        <input value={form.bank_other} onChange={(e) => setForm({ ...form, bank_other: e.target.value })} placeholder="Bank name" maxLength={100} />
      )}
      <p role="note" className="full-span m-0 flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        <TriangleAlert size={17} className="mt-0.5 shrink-0 text-amber-600" />
        <span>
          Please make sure the <strong>bank</strong>, <strong>account number</strong> and <strong>account name</strong> are correct and match your bank records exactly.
          A transfer sent to a wrong account may not be recoverable, and AbaiMarket cannot reverse a bank transfer once it has been sent.
        </span>
      </p>
    </>
  )
}

/** Shown live under the amount while it is above zero but below the minimum. */
export function BelowMinimumWarning({ amount }) {
  if (!(amount > 0 && amount < MIN_WITHDRAWAL)) return null
  return (
    <p role="alert" className="mt-2 mb-0 flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
      <TriangleAlert size={17} className="mt-0.5 shrink-0 text-amber-600" />
      <span>
        You&apos;re withdrawing below the minimum. Withdrawals must be at least <strong>{currency(MIN_WITHDRAWAL)}</strong>; you entered {currency(amount)}.
      </span>
    </p>
  )
}

/**
 * The listing detail fields, shared by Create Listing and the edit popup so
 * the two can never drift apart. The Unit of Measurement dropdown drives the
 * wording of the price, stock, and minimum-order fields -- a seller pricing
 * per kilogram sees "Price per kg", not "Price per piece".
 */
export function ListingDetailsFields({ form, setForm }) {
  const unit = unitMeta(form.unit_type)
  const set = (patch) => setForm({ ...form, ...patch })

  return (
    <div className="form grid-form">
      <label className="filter-label">
        Species
        {/* Picked from the marketplace list so it always matches the Browse
            filter. An older listing with a species outside the list keeps it. */}
        <select value={form.species} onChange={(e) => set({ species: e.target.value })}>
          <option value="" disabled>Choose a species</option>
          {SPECIES_OPTIONS.map((s) => <option key={s}>{s}</option>)}
          {form.species && !SPECIES_OPTIONS.includes(form.species) && <option value={form.species}>{form.species}</option>}
        </select>
      </label>
      <label className="filter-label">
        Variety (optional)
        <input value={form.variety || ''} maxLength={60} onChange={(e) => set({ variety: e.target.value })} placeholder="e.g. GIFT, Red, Nile" />
        <span className="helper-text">The strain, if it matters to buyers. Buyers can filter by it under the species.</span>
      </label>
      <label className="filter-label">
        Price per quantity
        <input type="number" min="0.01" step="0.01" value={form.price} onChange={(e) => set({ price: e.target.value })} placeholder="Price for one fingerling" />
        <span className="helper-text">The price of a single fingerling. A buyer ordering by bulk pays this times the bulk size.</span>
      </label>
      <label className="filter-label">
        Stock available (quantity)
        <input type="number" min="0" value={form.quantity} onChange={(e) => set({ quantity: e.target.value })} placeholder="Total fingerlings you have" />
        <span className="helper-text">The total number of fingerlings you have, not a number of bulks.</span>
      </label>
      <label className="filter-label">
        Minimum order (quantity)
        <input type="number" min="1" value={form.minimum_order} onChange={(e) => set({ minimum_order: e.target.value })} placeholder="1" />
        <span className="helper-text">Buyers cannot order less than this. Leave at 1 for no minimum.</span>
      </label>
      <label className="filter-label">
        1 bulk = how many quantity?
        <input
          type="number"
          min="1"
          value={form.pieces_per_unit}
          onChange={(e) => set({ pieces_per_unit: e.target.value })}
          placeholder="e.g. 10"
        />
        <span className="helper-text">
          Required. Buyers can order by quantity or by bulk, and this is what one bulk means on your listing
          {Number(form.pieces_per_unit) > 0 ? ` — 1 bulk = ${Number(form.pieces_per_unit).toLocaleString()} quantity.` : '.'}
        </span>
      </label>
      <label className="filter-label">
        What one {unit.short} contains (optional)
        <input
          value={form.unit_description}
          onChange={(e) => set({ unit_description: e.target.value })}
          placeholder={form.unit_type === 'bulk' ? 'e.g. 1 bulk = 1 sack of 1,000 pcs' : form.unit_type === 'kilogram' ? 'e.g. roughly 80-100 pcs per kg' : 'e.g. average size 2-3 cm'}
        />
      </label>
      <label className="filter-label listing-description-field">
        Description
        <textarea value={form.description} onChange={(e) => set({ description: e.target.value })} placeholder="Describe the fingerlings: health, feeding, size consistency, etc." />
      </label>
    </div>
  )
}

export function StagedImagePicker({ files, onAdd, onRemove, maxImages = 5 }) {
  const inputRef = useRef(null)
  const remainingSlots = maxImages - files.length

  const handleFiles = (e) => {
    const selected = Array.from(e.target.files || []).slice(0, remainingSlots)
    e.target.value = ''
    if (selected.length) onAdd(selected)
  }

  return (
    <div className="listing-image-manager">
      <div className="listing-image-grid">
        {files.map((staged, index) => (
          <div className="listing-image-thumb" key={staged.previewUrl}>
            {staged.file.type.startsWith('video/') ? <video src={staged.previewUrl} controls /> : <img src={staged.previewUrl} alt="Selected photo" />}
            {index === 0 && <span className="pill listing-image-primary">Primary</span>}
            <div className="listing-image-thumb-actions">
              <button type="button" onClick={() => onRemove(index)}>Remove</button>
            </div>
          </div>
        ))}
        {remainingSlots > 0 && (
          <button type="button" className="listing-image-add" onClick={() => inputRef.current?.click()}>
            + Add media<span className="muted">{files.length}/{maxImages}</span>
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={LISTING_MEDIA_ACCEPT} multiple hidden onChange={handleFiles} />
    </div>
  )
}

/** Live password requirement checklist, shared by Reset Password and the Super Admin form. */
export function PasswordChecklist({ password = '', confirmation = '' }) {
  return (
    <ul className="password-checklist" aria-label="Password requirements">
      {PASSWORD_RULES.map(([label, test]) => (
        <li key={label} className={test(password || '') ? 'met' : ''}><Check size={14} aria-hidden="true" />{label}</li>
      ))}
      <li className={confirmation && password === confirmation ? 'met' : ''}><Check size={14} aria-hidden="true" />Passwords match</li>
    </ul>
  )
}

// `noun` exists only so a form with one password field can say "Show
// password" rather than the plural the multi-field forms need.
export function PasswordVisibilityToggle({ shown, onToggle, noun = 'passwords' }) {
  return (
    <button type="button" className="profile-link-button password-visibility-toggle" onClick={onToggle}>
      {shown ? <EyeOff size={15} aria-hidden="true" /> : <Eye size={15} aria-hidden="true" />}
      {shown ? `Hide ${noun}` : `Show ${noun}`}
    </button>
  )
}

/** Optional screenshot picker for the contact form. */
export function ScreenshotInput({ files = [], onChange, disabled, maxFiles = 5 }) {
  const inputRef = useRef(null)
  const addFiles = (selected) => {
    const merged = [...files]
    for (const file of selected) {
      if (merged.length >= maxFiles) break
      if (!merged.some((existing) => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified)) merged.push(file)
    }
    onChange(merged)
  }
  return (
    <div className="support-attachment">
      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
        hidden
        onChange={(e) => { addFiles(Array.from(e.target.files || [])); e.target.value = '' }}
      />
      <button type="button" className="ghost" disabled={disabled || files.length >= maxFiles} onClick={() => inputRef.current?.click()}>
        <Camera size={16} /> {files.length ? 'Add Images' : 'Attach Images (optional)'}
      </button>
      {files.map((file, index) => (
        <span className="muted" key={`${file.name}-${file.lastModified}-${index}`}>
          {file.name}
          <button type="button" className="link-action" disabled={disabled} onClick={() => onChange(files.filter((_, fileIndex) => fileIndex !== index))}>Remove</button>
        </span>
      ))}
      <span className="muted">{files.length}/{maxFiles} images, up to 10 MB each</span>
    </div>
  )
}
