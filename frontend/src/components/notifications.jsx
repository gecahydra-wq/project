import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { Bell, Check, ListFilter, MessageCircle } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { withGoldStars } from './stars.jsx'
import { EmptyState } from './states.jsx'
import { PeriodFilteredRows, Section } from './ui.jsx'
import { api, queryClient } from '../lib/api.js'
import { asList, timeAgo } from '../lib/format.js'
import { useDismiss, useUnreadMessages } from '../lib/hooks.jsx'
import { notificationHistoryType, notificationLinkFor } from '../lib/notifications.js'



/**
 * Each role's existing unread-notification source and read endpoints. The
 * query keys match the dashboards' own, so the bell and the Notifications page
 * share one cache: marking something read in either updates both. The LGU has
 * no standalone list endpoint -- its notifications come with /lgu/dashboard.
 */
const NOTIFICATION_SOURCES = {
  buyer: { key: ['buyer-notifications'], url: '/buyer/notifications', prefix: '/buyer', alsoRefresh: ['buyer-dashboard'] },
  seller: { key: ['seller-notifications'], url: '/seller/notifications', prefix: '/seller', alsoRefresh: ['seller-dashboard'] },
  lgu_admin: { key: ['lgu-dashboard'], url: '/lgu/dashboard', select: (data) => data?.notifications || [], prefix: '/lgu', alsoRefresh: [] },
  super_admin: { key: ['super-admin-notifications'], url: '/super-admin/notifications', prefix: '/super-admin', alsoRefresh: [] },
}

/** The top bar's bell: a pop-up of unread notifications instead of a page change. */
export function NotificationBell({ role, link }) {
  const source = NOTIFICATION_SOURCES[role]
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const ref = useDismiss(open, setOpen)
  const query = useQuery({
    queryKey: source?.key || ['no-notifications'],
    queryFn: async () => (await api.get(source.url)).data,
    select: source?.select || asList,
    enabled: Boolean(source),
    retry: false,
    refetchInterval: 60000,
  })
  const items = query.data || []
  // A suspension or reinstatement arrives as a notification, but the suspended
  // banner, orders and listings come from other queries that are not polled.
  // When a new one shows up, refresh everything so the page matches it.
  const accountChangeId = items
    .filter((item) => ['account_suspended', 'account_reinstated'].includes(item.type))
    .reduce((latest, item) => Math.max(latest, item.id), 0)
  const seenAccountChange = useRef(null)
  useEffect(() => {
    if (seenAccountChange.current !== null && accountChangeId > seenAccountChange.current) {
      queryClient.invalidateQueries()
    }
    if (query.isSuccess) seenAccountChange.current = accountChangeId
  }, [accountChangeId, query.isSuccess])
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: source.key })
    source.alsoRefresh.forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }))
  }
  const markRead = useMutation({
    mutationFn: async (id) => (await api.patch(`${source.prefix}/notifications/${id}/read`)).data,
    onSuccess: refresh,
  })
  const markAllRead = useMutation({
    mutationFn: async () => (await api.patch(`${source.prefix}/notifications/read-all`)).data,
    onSuccess: refresh,
  })
  if (!source || !link) return null

  const getLink = notificationLinkFor(role)
  // Opening a notification counts as reading it; one with somewhere to go
  // (a ticket, messages, the earnings queue) also takes you there.
  const openItem = (item) => {
    const target = getLink(item)
    markRead.mutate(item.id)
    if (target) {
      setOpen(false)
      navigate(target)
    }
  }
  const count = items.length

  return (
    <div ref={ref} className="relative">
      <button
        data-tw
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={count ? `Notifications (${count} unread)` : 'Notifications'}
        title="Notifications"
        onClick={() => {
          if (!open) query.refetch()
          setOpen((value) => !value)
        }}
        className={`relative grid size-10 cursor-pointer place-items-center rounded-full transition duration-200 hover:scale-105 ${open ? 'bg-white/20 text-white' : 'text-white/80 hover:bg-white/10 hover:text-white'}`}
      >
        <Bell size={20} className={count ? 'bell-ring' : ''} />
        {count > 0 && (
          <span className="absolute -right-0.5 -top-0.5 grid min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[0.68rem] font-bold leading-5 text-white ring-2 ring-abai-navy">
            {count > 99 ? '99+' : count}
          </span>
        )}
      </button>

      <div
        role="dialog"
        aria-label="Notifications"
        className={`fixed inset-x-2 top-[4.25rem] z-50 flex max-h-[min(34rem,calc(100vh-6rem))] flex-col overflow-hidden rounded-2xl bg-white text-slate-800 shadow-2xl shadow-abai-navy/25 ring-1 ring-black/5 transition duration-200 sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-3 sm:w-96 sm:origin-top-right ${open ? 'translate-y-0 scale-100 opacity-100' : 'pointer-events-none -translate-y-1 scale-95 opacity-0'}`}
      >
        <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div className="flex items-center gap-2">
            <p className="m-0 font-semibold text-abai-navy">Notifications</p>
            {count > 0 && <span className="rounded-full bg-abai-teal-soft px-2 py-0.5 text-xs font-semibold text-abai-teal-text">{count} new</span>}
          </div>
          <button
            data-tw
            type="button"
            tabIndex={open ? 0 : -1}
            disabled={!count || markAllRead.isPending}
            onClick={() => markAllRead.mutate()}
            className="cursor-pointer rounded-full px-2.5 py-1 text-xs font-semibold text-abai-teal-text transition hover:bg-abai-teal-soft disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent"
          >
            {markAllRead.isPending ? 'Marking...' : 'Mark all as read'}
          </button>
        </div>

        <div className="flex-1 overflow-y-auto">
          {query.isLoading ? (
            <p className="m-0 px-4 py-10 text-center text-sm text-slate-500">Loading...</p>
          ) : count === 0 ? (
            <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
              <span className="grid size-12 place-items-center rounded-full bg-abai-teal-soft text-abai-teal-text"><Bell size={22} /></span>
              <p className="m-0 font-medium text-abai-navy">You&apos;re all caught up</p>
              <p className="m-0 text-sm text-slate-500">New notifications will show up here.</p>
            </div>
          ) : (
            <ul className="m-0 list-none divide-y divide-slate-100 p-0">
              {items.map((item) => (
                <li key={item.id} className="group/n relative flex items-start gap-3 px-4 py-3 transition hover:bg-slate-50">
                  <span className="mt-1.5 size-2 shrink-0 rounded-full bg-abai-teal" aria-hidden="true" />
                  <button
                    data-tw
                    type="button"
                    tabIndex={open ? 0 : -1}
                    onClick={() => openItem(item)}
                    className="min-w-0 flex-1 cursor-pointer text-left"
                  >
                    <span className="block text-sm font-semibold text-abai-navy">{withGoldStars(item.title)}</span>
                    <span className="mt-0.5 line-clamp-2 block text-sm text-slate-600">{item.body}</span>
                    <span className="mt-1 block text-xs text-slate-400">{timeAgo(item.created_at)}</span>
                  </button>
                  <button
                    data-tw
                    type="button"
                    tabIndex={open ? 0 : -1}
                    onClick={() => markRead.mutate(item.id)}
                    title="Mark as read"
                    aria-label={`Mark "${item.title}" as read`}
                    className="grid size-8 shrink-0 cursor-pointer place-items-center rounded-full text-slate-400 transition hover:bg-abai-teal-soft hover:text-abai-teal-text sm:opacity-0 sm:group-hover/n:opacity-100 sm:focus-visible:opacity-100"
                  >
                    <Check size={16} />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <Link
          data-tw
          to={link.path}
          tabIndex={open ? 0 : -1}
          onClick={() => setOpen(false)}
          className="block border-t border-slate-100 px-4 py-3 text-center text-sm font-semibold text-abai-teal-text transition hover:bg-abai-teal-soft"
        >
          View all notifications
        </Link>
      </div>
    </div>
  )
}

/** "You have N unread messages" on a dashboard overview, linking to Messages. */
export function UnreadMessagesNotice({ dashboardPath }) {
  const count = useUnreadMessages()
  if (!count) return null
  return (
    <Link className="card unread-messages-notice" to={`${dashboardPath}?tab=messages`}>
      <MessageCircle size={20} />
      <span><strong>You have {count} unread message{count === 1 ? '' : 's'}.</strong> Open Messages to read and reply.</span>
    </Link>
  )
}

export function NotificationStack({ notifications, onMarkRead = null, getLink, emptyMessage = 'No notifications yet.' }) {
  if (!notifications?.length) return <EmptyState message={emptyMessage} />
  return (
    <div className="notification-stack">
      {notifications.map((item) => {
        const link = getLink?.(item)
        const body = (
          <div>
            <strong>{withGoldStars(item.title)}</strong>
            <p>{item.body}</p>
            <small className="notification-time">
              {new Date(item.created_at).toLocaleString()}
              {item.read_at && ` · Read ${new Date(item.read_at).toLocaleDateString()}`}
            </small>
          </div>
        )
        return (
          <div className={`card notification ${item.read_at ? 'read' : 'unread'}`} key={item.id}>
            {link ? <Link className="notification-link" to={link}>{body}</Link> : body}
            {!item.read_at && onMarkRead && <button type="button" onClick={() => onMarkRead(item.id)}>Mark Read</button>}
          </div>
        )
      })}
    </div>
  )
}

const NOTIFICATION_HISTORY_TYPES = [
  ['all', 'All types'],
  ['message', 'Messages'],
  ['report', 'Reports'],
  ['order', 'Orders'],
  ['review', 'Reviews'],
  ['listing', 'Listings'],
  ['notice', 'Notices'],
  ['account', 'Account'],
  ['payout', 'Payouts'],
  ['support', 'Support'],
  ['announcement', 'Announcements'],
  ['dispute', 'Disputes'],
  ['other', 'Other'],
]

/**
 * Read notifications (GET /notifications/history). For buyers and sellers,
 * the type dropdown narrows the list before the existing date filter applies.
 */
export function NotificationHistory({ getLink, unreadCount, showTypeFilter = false }) {
  const [typeFilter, setTypeFilter] = useState('all')
  const history = useQuery({
    queryKey: ['notification-history', unreadCount],
    queryFn: async () => (await api.get('/notifications/history')).data,
    placeholderData: (previous) => previous,
  })
  const rows = history.data || []
  const filteredRows = typeFilter === 'all'
    ? rows
    : rows.filter((notification) => notificationHistoryType(notification) === typeFilter)
  return (
    <Section title="Notification History">
      <p className="helper-text">Notifications you have already read, newest first (the latest 200).</p>
      {showTypeFilter && (
        <label className="period-select notification-history-type-filter">
          <ListFilter size={16} />
          <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)} aria-label="Filter notification history by type">
            {NOTIFICATION_HISTORY_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
      )}
      <PeriodFilteredRows rows={filteredRows} noun="notifications received" dateKey="created_at">
        {(shown) => <NotificationStack notifications={shown} getLink={getLink} emptyMessage="No read notifications yet." />}
      </PeriodFilteredRows>
    </Section>
  )
}

export function MarkAllReadButton({ unreadCount, onClick, loading }) {
  return (
    <button
      type="button"
      className="ghost mark-all-read-button"
      onClick={onClick}
      disabled={loading || !unreadCount}
    >
      {loading ? 'Marking...' : 'Mark All as Read'}
    </button>
  )
}