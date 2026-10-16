import { ANNOUNCEMENT_MAX_YEAR, ANNOUNCEMENT_START_GRACE_MS } from '../components/announcements.jsx'
import { MIN_WITHDRAWAL } from './constants.js'
import { selectedBankName } from './withdrawals.js'



/**
 * True when a withdrawal form is missing anything the server requires, so the
 * form can say "fill in all required fields" once instead of submitting a
 * blank form and getting back a one-field-at-a-time validation summary.
 *
 * This is a UX shortcut, never the authority -- the same rules are enforced
 * server-side (see SellerController::requestWithdrawal and
 * LguController::requestWithdrawal), which also own the checks the client
 * can't make, like the available-balance ceiling.
 */
export function withdrawalFormIsIncomplete(form) {
  return !form.method
    || !form.account_name.trim()
    || !form.account_number.trim()
    || !String(form.amount).trim()
    || (form.method === 'bank_transfer' && !selectedBankName(form))
}

/** Spaces and dashes people type into account numbers ("0995 475 7102"). */
export function normalizeAccountNumber(value) {
  return String(value || '').replace(/[\s-]/g, '')
}

export function withdrawalFormIssue(form) {
  const accountNumber = normalizeAccountNumber(form.account_number)
  if (form.method === 'bank_transfer') {
    if (!selectedBankName(form)) return 'Choose your bank.'
    if (!/^\d{10,16}$/.test(accountNumber)) return 'Enter a valid bank account number (10 to 16 digits, numbers only).'
  } else if (!/^09\d{9}$/.test(accountNumber)) {
    return 'Enter a valid 11-digit mobile number starting with 09 (e.g. 09954757102).'
  }
  const amount = Number(form.amount)
  if (!Number.isFinite(amount) || amount <= 0) return 'Please enter a valid amount.'
  if (amount < MIN_WITHDRAWAL) return 'The minimum withdrawal is ₱100.00.'
  return null
}

export function accountNumberPlaceholder(method) {
  return method === 'bank_transfer' ? 'Bank account number' : 'Mobile number (09XXXXXXXXX)'
}

// Passwords may not contain whitespace. blockSpaceKey stops the space key from
// typing anything; stripSpaces removes any whitespace that slips in via paste
// or autofill. Applied to every password field -- including login -- so the
// behaviour is identical everywhere. This is safe: registration has always
// forbidden whitespace in passwords, so no stored password contains any and
// stripping it on login can never lock a real account out.
export function blockSpaceKey(e) {
  if (e.key === ' ') e.preventDefault()
}

export function stripSpaces(value) {
  return (value || '').replace(/\s/g, '')
}

// Shared password policy -- mirrors App\Rules\StrongPassword on the backend so
// the same rules and messages are enforced client-side before submitting.
// Returns a user-facing error message, or '' when the password is valid.
export const PASSWORD_HELP = 'Use 8-64 characters with an uppercase letter, a lowercase letter, a number, and a special character. No spaces.'

export function validatePassword(value) {
  const v = value || ''
  if (/\s/.test(v)) return 'Password cannot contain spaces.'
  if (v.length < 8) return 'Password must be at least 8 characters.'
  if (v.length > 64) return 'Password must be at most 64 characters.'
  if (!/[A-Z]/.test(v)) return 'Password must contain an uppercase letter.'
  if (!/[a-z]/.test(v)) return 'Password must contain a lowercase letter.'
  if (!/[0-9]/.test(v)) return 'Password must contain a number.'
  if (!/[^A-Za-z0-9]/.test(v)) return 'Password must contain a special character.'
  return ''
}

// Shared email policy -- mirrors App\Support\AuthValidation on the backend.
// Leading/trailing spaces are trimmed first (the backend trims them too), then
// any internal space is rejected, then the basic address format is checked.
// Returns a user-facing error message, or '' when the email is valid.
export function validateEmail(value) {
  const v = (value || '').trim()
  if (/\s/.test(v)) return 'Email address must not contain spaces.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Please enter a valid email address.'
  return ''
}

export const PASSWORD_RULES = [
  ['8 to 64 characters', (v) => v.length >= 8 && v.length <= 64],
  ['An uppercase letter', (v) => /[A-Z]/.test(v)],
  ['A lowercase letter', (v) => /[a-z]/.test(v)],
  ['A number', (v) => /[0-9]/.test(v)],
  ['A special character', (v) => /[^A-Za-z0-9]/.test(v)],
]

/**
 * Mirrors AnnouncementController::guardSchedule so the Super Admin sees the
 * problem before submitting. Only a date that changed from `original` (the
 * values loaded for editing) is held to the "not in the past" rules.
 */
export function announcementScheduleIssue(form, original) {
  const now = Date.now()
  const start = form.starts_at ? new Date(form.starts_at).getTime() : null
  const end = form.expires_at ? new Date(form.expires_at).getTime() : null
  const yearOf = (value) => new Date(value).getFullYear()

  if ((start && yearOf(form.starts_at) > ANNOUNCEMENT_MAX_YEAR) || (end && yearOf(form.expires_at) > ANNOUNCEMENT_MAX_YEAR)) {
    return `The year can't be after ${ANNOUNCEMENT_MAX_YEAR}.`
  }
  if (start && form.starts_at !== original.starts_at && start < now - ANNOUNCEMENT_START_GRACE_MS) {
    return "The start date can't be in the past. Leave it blank to publish right away."
  }
  if (end && form.expires_at !== original.expires_at && end <= now) {
    return 'The end date must be in the future.'
  }
  if (start && end && end <= start) {
    return 'The end date must be after the start date.'
  }
  return ''
}