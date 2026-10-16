import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { ChevronRight, Search } from 'lucide-react'
import { useState } from 'react'
import { EmptyState, LoadingState } from './states.jsx'
import { Badge, Section } from './ui.jsx'
import { ACTIVITY_ACTION_META, activityActionMeta, activityLogLink } from '../lib/activity.js'
import { api } from '../lib/api.js'
import { formatOrderDate, localDateInput, roleLabel } from '../lib/format.js'
import { orderPeriodStart } from '../lib/orders.js'
import { ORDER_PERIODS } from '../lib/status.js'



// The category tabs, in this order, for the LGU and the Super Admin alike.
// Matches App\Support\ActivityLog::CATEGORIES / CATEGORY_ORDER on the backend.
const ACTIVITY_CATEGORIES = [
  ['orders', 'Orders'],
  ['payments', 'Payments & Payouts'],
  ['listings_sellers', 'Listings & Sellers'],
  ['reports', 'Reports & Notices'],
  ['disputes', 'Disputes'],
  ['moderation', 'Moderation'],
  ['support', 'Help & Support'],
  ['reviews', 'Reviews & Ratings'],
  ['accounts', 'Accounts'],
]

export function ActivityLogEntryCard({ scope, entry }) {
  const meta = activityActionMeta(entry.action)
  const Icon = meta.icon
  const link = activityLogLink(scope, entry)

  const content = (
    <>
      <span className={`activity-log-icon tone-${meta.tone}`}><Icon size={16} /></span>
      <div className="activity-log-body">
        <div className="activity-log-title-row">
          <strong>{meta.label}</strong>
          {entry.reference_number && <Badge tone="neutral">{entry.reference_number}</Badge>}
        </div>
        <p className="muted">
          {entry.administrator ? `${entry.administrator} (${roleLabel(entry.role)})` : roleLabel(entry.role)}
          {entry.target_user ? ` → ${entry.target_user}` : ''}
          {entry.municipality ? ` · ${entry.municipality}` : ''}
        </p>
        {entry.description && <p className="muted">{entry.description}</p>}
        {/* Every audit entry is dated -- for order-linked actions this is when
            the action happened; the order's own date is on the record the
            entry links through to. */}
        <p className="muted">{entry.timestamp ? formatOrderDate(entry.timestamp) : ''}</p>
      </div>
      {link && <ChevronRight size={18} className="activity-log-chevron" />}
    </>
  )

  return link
    ? <Link className="card activity-log-item" to={link}>{content}</Link>
    : <div className="card activity-log-item">{content}</div>
}

/**
 * Global Activity Log / Audit Trail -- reused by both LGU Admin (municipality-
 * scoped server-side) and Super Admin (platform-wide, with an extra
 * municipality filter). Backed by GET {scope}/activity-log and
 * {scope}/activity-log/actions (see App\Support\ActivityLog on the backend).
 * Every entry is clickable through to the relevant existing page for that
 * action (see activityLogLink above) so an admin never has to go hunting for
 * the underlying record.
 */
const ACTIVITY_LOG_DEFAULT_FILTERS = { category: '', action: '', date_from: '', date_to: '', municipality_id: '' }

/**
 * The one audit trail for the LGU (own municipality) and the Super Admin
 * (everything): every important event on the platform, filtered by category
 * tabs, search, action type, the same All / Today / This Week / This Month /
 * This Year chips as every other history list (or a custom date range), and
 * municipality for the Super Admin. ?category= opens a tab directly (e.g.
 * Moderation, which replaced the separate Moderation Log page).
 */
export function ActivityLogPanel({ scope }) {
  const [searchParams] = useSearchParams()
  const initialCategory = ACTIVITY_CATEGORIES.some(([value]) => value === searchParams.get('category')) ? searchParams.get('category') : ''
  const [filters, setFilters] = useState({ ...ACTIVITY_LOG_DEFAULT_FILTERS, category: initialCategory })
  const [period, setPeriod] = useState('all')
  const [searchDraft, setSearchDraft] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [page, setPage] = useState(1)
  const base = scope === 'lgu' ? '/lgu' : '/super-admin'

  const municipalities = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
    placeholderData: [],
    enabled: scope === 'super-admin',
  })

  const log = useQuery({
    queryKey: ['activity-log', scope, filters, appliedSearch, page],
    queryFn: async () => (await api.get(`${base}/activity-log`, { params: { ...filters, search: appliedSearch, page, per_page: 20 } })).data,
    retry: false,
  })

  const updateFilter = (key, value) => {
    setPage(1)
    // Typing a date by hand replaces the quick chip.
    if (key === 'date_from' || key === 'date_to') setPeriod('custom')
    setFilters((current) => (key === 'category' ? { ...current, category: value, action: '' } : { ...current, [key]: value }))
  }

  const choosePeriod = (value) => {
    setPage(1)
    setPeriod(value)
    setFilters((current) => ({ ...current, date_from: localDateInput(orderPeriodStart(value)), date_to: '' }))
  }

  const submitSearch = (e) => {
    e.preventDefault()
    setPage(1)
    setAppliedSearch(searchDraft.trim())
  }

  const clearFilters = () => {
    setPeriod('all')
    setFilters(ACTIVITY_LOG_DEFAULT_FILTERS)
    setSearchDraft('')
    setAppliedSearch('')
    setPage(1)
  }

  const total = log.data?.total ?? 0
  const perPage = log.data?.per_page ?? 20
  const totalPages = Math.max(1, Math.ceil(total / perPage))
  const hasActiveFilters = Boolean(filters.category || filters.action || filters.date_from || filters.date_to || filters.municipality_id || appliedSearch)

  // Only the actions belonging to the selected category, so picking a
  // category first ("Payments") narrows the action list to just those --
  // otherwise every action across all categories is offered, grouped.
  const actionOptions = filters.category
    ? ACTIVITY_ACTION_META && Object.entries(ACTIVITY_ACTION_META).filter(([, meta]) => meta.category === filters.category)
    : Object.entries(ACTIVITY_ACTION_META)

  return (
    <Section title="Activity Log">
      <p className="helper-text">
        Everything important that happens on AbaiMarket{scope === 'lgu' ? ' in your municipality' : ''}: orders, payments and payouts, listings,
        reports and notices, disputes, suspensions, support tickets and reviews, newest first. Pick a tab or filter, and click an entry to jump to
        where it&apos;s managed.
      </p>

      <div className="tab-bar activity-log-category-bar">
        <button type="button" className={filters.category === '' ? 'tab active' : 'tab'} onClick={() => updateFilter('category', '')}>All</button>
        {ACTIVITY_CATEGORIES.map(([value, label]) => (
          <button key={value} type="button" className={filters.category === value ? 'tab active' : 'tab'} onClick={() => updateFilter('category', value)}>
            {label}
          </button>
        ))}
      </div>

      <div className="tab-bar" role="group" aria-label="Show activity from">
        {ORDER_PERIODS.map(([value, label]) => (
          <button key={value} type="button" className={period === value ? 'tab active' : 'tab'} onClick={() => choosePeriod(value)}>{label}</button>
        ))}
      </div>

      <form className="form grid-form activity-log-filters" onSubmit={submitSearch}>
        <input placeholder="Search by name, description, or reference #" value={searchDraft} onChange={(e) => setSearchDraft(e.target.value)} />
        <button type="submit"><Search size={16} /> Search</button>
        <select value={filters.action} onChange={(e) => updateFilter('action', e.target.value)}>
          <option value="">{filters.category ? `All ${ACTIVITY_CATEGORIES.find(([v]) => v === filters.category)?.[1]} actions` : 'All action types'}</option>
          {actionOptions.map(([action, meta]) => <option key={action} value={action}>{meta.label}</option>)}
        </select>
        <input type="date" value={filters.date_from} onChange={(e) => updateFilter('date_from', e.target.value)} title="From date" />
        <input type="date" value={filters.date_to} onChange={(e) => updateFilter('date_to', e.target.value)} title="To date" />
        {scope === 'super-admin' && (
          <select value={filters.municipality_id} onChange={(e) => updateFilter('municipality_id', e.target.value)}>
            <option value="">All municipalities</option>
            {(municipalities.data || []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </select>
        )}
        {hasActiveFilters && <button type="button" className="ghost" onClick={clearFilters}>Clear Filters</button>}
      </form>

      {log.isLoading && <LoadingState label="Loading activity log..." />}
      {log.isError && (
        <p className="error">
          Could not load the activity log ({log.error?.response?.data?.message || log.error?.message || 'unknown error'}).{' '}
          <button type="button" className="ghost" onClick={() => log.refetch()}>Retry</button>
        </p>
      )}
      {!log.isLoading && !log.isError && (
        (log.data?.data || []).length ? (
          <div className="item-list">
            {log.data.data.map((entry) => <ActivityLogEntryCard key={entry.id} scope={scope} entry={entry} />)}
          </div>
        ) : <EmptyState message="No activity matches these filters." />
      )}
      {total > perPage && (
        <div className="row-actions">
          <button type="button" className="ghost" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
          <span className="muted">Page {page} of {totalPages}</span>
          <button type="button" className="ghost" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>Next</button>
        </div>
      )}
    </Section>
  )
}

/**
 * Export Reports -- report-type select + PDF/Excel download buttons, reused
 * by both LGU and Super Admin Reports sections. Downloads via axios blob
 * (auth header already attached by the shared api instance) rather than a
 * plain <a href>, since the export endpoints require a Bearer token.
 */
export function ReportExportControls({ typeOptions, exportEndpoint, period }) {
  const [type, setType] = useState(typeOptions[0]?.value || '')
  const [downloading, setDownloading] = useState(null)
  const [error, setError] = useState('')

  const download = async (format) => {
    setDownloading(format)
    setError('')
    try {
      const response = await api.get(exportEndpoint, { params: { type, format, period }, responseType: 'blob' })
      const disposition = response.headers['content-disposition'] || ''
      const match = disposition.match(/filename="?([^"]+)"?/)
      const filename = match?.[1] || `report.${format === 'pdf' ? 'pdf' : 'xlsx'}`
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } catch {
      setError('Could not export this report.')
    } finally {
      setDownloading(null)
    }
  }

  return (
    <div className="order-lookup-form">
      <select value={type} onChange={(e) => setType(e.target.value)}>
        {typeOptions.map((opt) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
      </select>
      <button type="button" className="ghost" onClick={() => download('pdf')} disabled={downloading !== null}>
        {downloading === 'pdf' ? 'Exporting...' : 'Export PDF'}
      </button>
      <button type="button" className="ghost" onClick={() => download('xlsx')} disabled={downloading !== null}>
        {downloading === 'xlsx' ? 'Exporting...' : 'Export Excel'}
      </button>
      {error && <p className="error">{error}</p>}
    </div>
  )
}