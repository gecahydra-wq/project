

/**
 * Turns an axios error into a message a user should actually see -- never a
 * raw framework/validation-exception string. Prefers the first field-specific
 * Laravel validation message (e.g. "This email address is already
 * registered.") over the top-level summary Laravel returns when several
 * fields fail, which reads like "The account name field is required. (and 2
 * more errors)" and tells the user nothing about the other two.
 *
 * Forms that can fail on several fields at once should still check for empty
 * required inputs before submitting (see REQUIRED_FIELDS_MESSAGE), so the
 * common "submitted a blank form" case never has to be described one field at
 * a time.
 */
export function apiErrorMessage(err, fallback = 'Something went wrong. Please try again.') {
  if (!err?.response) {
    return 'Cannot reach the AbaiMarket server right now. Please check your connection and try again.'
  }
  const data = err.response.data
  const firstFieldError = data?.errors && Object.values(data.errors)[0]
  if (Array.isArray(firstFieldError) && firstFieldError[0]) return firstFieldError[0]
  if (typeof data?.message === 'string' && data.message) return data.message
  return fallback
}

export function aiErrorMessage(err) {
  if (!err?.response) return 'Network error -- please check your connection and try again.'
  const status = err.response.status
  if (status === 422) return 'Please enter a question first.'
  // Reachable when a token expires mid-conversation; the guest case is handled
  // before a request is ever made.
  if (status === 401) return 'Please log in again to use the AI assistant.'
  if (status === 429) return 'Too many requests right now -- please wait a moment and try again.'
  if (status >= 500) return 'The AI assistant is temporarily unavailable. Please try again shortly.'
  return 'Something went wrong. Please try again.'
}