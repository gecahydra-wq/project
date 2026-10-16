import { useQuery } from '@tanstack/react-query'
import { Megaphone } from 'lucide-react'
import { useState } from 'react'
import { DISMISSED_ANNOUNCEMENTS_KEY, readDismissedAnnouncements } from '../lib/announcements.js'
import { api } from '../lib/api.js'



/**
 * Site-wide announcement bar: the Super Admin's active announcements across
 * the top of every page -- the public storefront (guests included) and all
 * four dashboards, on every tab. Backed by the public GET /announcements/active
 * (see App\Models\Announcement::scopeActive), so it only ever shows
 * announcements inside their display window. The in-app notification each
 * user receives is unchanged; this is the always-visible counterpart.
 *
 * A viewer can dismiss an announcement; that is remembered in this browser
 * only, keyed by id + updated_at, so editing an announcement shows it again.
 */
export function SiteAnnouncementBar() {
  const [dismissed, setDismissed] = useState(readDismissedAnnouncements)
  const { data } = useQuery({
    queryKey: ['announcements-active'],
    queryFn: async () => (await api.get('/announcements/active')).data,
    retry: false,
    placeholderData: [],
    refetchInterval: 5 * 60 * 1000,
  })

  const visible = (data || []).filter((a) => !dismissed.includes(`${a.id}:${a.updated_at}`))
  if (!visible.length) return null

  const dismiss = (announcement) => {
    const next = [...dismissed, `${announcement.id}:${announcement.updated_at}`].slice(-50)
    setDismissed(next)
    try {
      localStorage.setItem(DISMISSED_ANNOUNCEMENTS_KEY, JSON.stringify(next))
    } catch {
      // Storage unavailable (private mode): dismissal just lasts this page view.
    }
  }

  return (
    <div className="site-announcements" role="region" aria-label="Announcements">
      {visible.map((a) => (
        <div className={`site-announcement announcement-${a.category}`} key={a.id}>
          <Megaphone size={16} aria-hidden="true" />
          <p><strong>{a.title}</strong> {a.body}</p>
          <button type="button" className="site-announcement-dismiss" onClick={() => dismiss(a)} aria-label={`Dismiss announcement: ${a.title}`}>
            ×
          </button>
        </div>
      ))}
    </div>
  )
}

export const ANNOUNCEMENT_START_GRACE_MS = 5 * 60 * 1000
export const ANNOUNCEMENT_MAX_YEAR = 2100
export const ANNOUNCEMENT_MAX_INPUT = `${ANNOUNCEMENT_MAX_YEAR}-12-31T23:59`