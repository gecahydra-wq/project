

export function renderStars(rating) {
  const rounded = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)))
  return <span className="stars">{'★'.repeat(rounded)}{'☆'.repeat(5 - rounded)}</span>
}

// Review notifications embed stars as plain text in the title/body (built
// server-side), which renders them in the surrounding text colour. Wrap star
// runs in the gold .stars span -- as React elements, not innerHTML, because
// notification bodies carry user-written review comments.
export function withGoldStars(text) {
  if (!text || !/[★☆]/.test(text)) return text
  return String(text)
    .split(/([★☆]+)/)
    .map((part, index) => (/^[★☆]+$/.test(part) ? <span className="stars" key={index}>{part}</span> : part))
}