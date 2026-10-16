

export const DISMISSED_ANNOUNCEMENTS_KEY = 'abaimarket_dismissed_announcements'

export function readDismissedAnnouncements() {
  try {
    const stored = JSON.parse(localStorage.getItem(DISMISSED_ANNOUNCEMENTS_KEY) || '[]')
    return Array.isArray(stored) ? stored : []
  } catch {
    return []
  }
}

export const ANNOUNCEMENT_CATEGORIES = [
  ['general', 'General'],
  ['maintenance', 'System Maintenance'],
  ['update', 'Marketplace Update'],
  ['policy', 'Policy Change'],
  ['holiday', 'Holiday Announcement'],
]

export const EMPTY_ANNOUNCEMENT_FORM = { title: '', body: '', category: 'general', starts_at: '', expires_at: '' }