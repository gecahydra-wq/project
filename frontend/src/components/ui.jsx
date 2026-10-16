import { Link } from 'react-router-dom'
import { MessageCircle, X } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { EmptyState } from './states.jsx'
import { DEFAULT_AVATAR_IMAGE } from '../lib/constants.js'
import { currency } from '../lib/format.js'
import { useFocusSection, usePeriodFilter } from '../lib/hooks.jsx'
import { sellerProfilePath } from '../lib/session.js'
import { PERIOD_OPTIONS, badgeTone } from '../lib/status.js'



export function Badge({ status, tone, children }) {
  const resolvedTone = tone || badgeTone(status)
  return <span className={`badge badge-${resolvedTone}`}>{children ?? status}</span>
}

// Short, colour-coded role labels -- each role gets its own distinct badge
// colour (see .badge-role-* in App.css) for author headers and comments.
const ROLE_BADGE = {
  buyer: { label: 'Buyer', className: 'badge-role-buyer' },
  seller: { label: 'Seller', className: 'badge-role-seller' },
  lgu_admin: { label: 'LGU', className: 'badge-role-lgu' },
  super_admin: { label: 'Admin', className: 'badge-role-admin' },
}

export function RoleBadge({ role }) {
  const info = ROLE_BADGE[role]
  if (!info) return null
  return <span className={`badge ${info.className}`}>{info.label}</span>
}

/**
 * A seller's name that opens their profile. Every role, everywhere a seller is
 * named, lands on the same page (sellerProfilePath picks the route for the
 * signed-in role). Without a profile id it falls back to plain text. Clicks
 * don't bubble, so it is safe inside clickable cards and table rows.
 */
export function SellerLink({ seller = null, id = seller?.id, name = seller?.hatchery_name || seller?.user?.name, fallback = 'Unknown seller' }) {
  const label = name || fallback
  if (!id) return label
  return <Link className="seller-name-link" to={sellerProfilePath(id)} onClick={(e) => e.stopPropagation()}>{label}</Link>
}

/**
 * Shared dialog, used where an edit should happen in place instead of
 * navigating away to a separate page. Dismisses on Escape or a backdrop
 * click; the panel swallows clicks so working inside the form never closes it.
 */
/* Modals can stack (an order popup opens the cancel confirm or the review
   form on top of it), so body.modal-open is tracked with a count -- otherwise
   the first modal to unmount would unlock body scroll while another is still
   on screen. */
let openModalCount = 0

export function Modal({ title, subtitle, onClose, children, footer, className = '' }) {
  const overlayRef = useRef(null)

  useEffect(() => {
    const handleKey = (e) => {
      if (e.key !== 'Escape') return
      /* Only the topmost modal reacts. Overlays are siblings in the DOM, so
         the last one is the one the user sees; lower modals stay put. */
      const overlays = document.querySelectorAll('.modal-overlay')
      if (overlays[overlays.length - 1] !== overlayRef.current) return
      onClose()
    }
    window.addEventListener('keydown', handleKey)
    openModalCount += 1
    document.body.classList.add('modal-open')
    return () => {
      window.removeEventListener('keydown', handleKey)
      openModalCount -= 1
      if (openModalCount === 0) document.body.classList.remove('modal-open')
    }
  }, [onClose])

  return (
    <div className="modal-overlay" ref={overlayRef} onClick={onClose}>
      <div className={`modal-panel ${className}`.trim()} role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()}>
        <header className="modal-header">
          <div>
            <h3>{title}</h3>
            {subtitle && <p className="helper-text">{subtitle}</p>}
          </div>
          <button type="button" className="modal-close" onClick={onClose} aria-label="Close"><X size={20} /></button>
        </header>
        <div className="modal-body">{children}</div>
        {footer && <footer className="modal-footer">{footer}</footer>}
      </div>
    </div>
  )
}

export function Avatar({ src, alt, className = '' }) {
  return <img className={`avatar ${className}`} src={src || DEFAULT_AVATAR_IMAGE} alt={alt} />
}

export function Dashboard({ title, subtitle, actions, children }) {
  useFocusSection()
  return <div className="dashboard"><div className="dashboard-head"><div><p className="eyebrow">{subtitle}</p><h1>{title}</h1></div>{actions}</div>{children}</div>
}

/**
 * items are [label, value, highlight?, to?]. A fourth entry turns that tile
 * into a link to the tab it summarises -- the number on the overview is the
 * obvious thing to click, and before this it was the one dead end on the page.
 * Tiles without a destination render exactly as they always have.
 */
export function StatsRow({ items }) {
  return <div className="stats-grid">{items.map(([label, value, highlight, to]) => <Stat key={label} label={label} value={value} highlight={highlight} to={to} />)}</div>
}

export function Stat({ value, label, highlight = false, to, onClick }) {
  const className = `stat-card${highlight ? ' stat-card-highlight' : ''}${to ? ' stat-card-link' : ''}`
  const body = <><strong>{value}</strong><span>{label}</span></>

  return to
    ? <Link className={className} to={to} onClick={onClick}>{body}</Link>
    : <div className={className}>{body}</div>
}

/**
 * One titled group of queue counts on a dashboard, styled like the LGU's
 * "Seller Earnings Approval" card, so a reviewer can tell at a glance which
 * number belongs to sellers, reports or payouts. `items` are
 * [label, count, link]; `action` is an optional [label, link] button for a
 * group whose numbers all lead to one page.
 */
export function ActionGroup({ title, items, action = null }) {
  const waiting = items.reduce((sum, [, count]) => sum + Number(count || 0), 0)
  return (
    <div className="card action-group">
      <div className="card-row">
        <h3>{title}</h3>
        <div className="action-group-meta">
          {waiting > 0 && <Badge tone="warning">{waiting} waiting</Badge>}
          {action && <Link className="ghost" to={action[1]}>{action[0]}</Link>}
        </div>
      </div>
      <div className="stats-inline">
        {items.map(([label, count, to]) => (
          <Stat key={label} value={count} label={label} to={to} highlight={Number(count) > 0} />
        ))}
      </div>
    </div>
  )
}

export function TopPerformerCard({ eyebrow, icon: Icon, performer }) {
  return (
    <div className="card top-performer">
      <div className="top-performer-head">
        <span className="top-performer-icon"><Icon size={18} /></span>
        <p className="eyebrow">{eyebrow}</p>
      </div>
      {performer ? (
        <>
          <strong className="top-performer-name">{performer.name}</strong>
          <p className="muted">{currency(performer.amount)} · {performer.orders} settled {performer.orders === 1 ? 'order' : 'orders'}</p>
        </>
      ) : <p className="muted">Not enough data yet.</p>}
    </div>
  )
}

export function DataTable({ rows }) {
  if (!rows?.length) return <EmptyState message="No records yet." />
  const keys = Object.keys(rows[0] || {}).slice(0, 6)
  return (
    <div className="table">
      {rows.map((row, index) => (
        <div className={`table-row ${index === 0 ? 'first' : ''}`} key={row.id || row.title || index}>
          {keys.map((key) => {
            const value = row[key]
            if (value && typeof value === 'object' && '__badge' in value) return <span key={key}><Badge status={value.__badge} /></span>
            return <span key={key}>{typeof value === 'object' && value !== null ? value.title || value.name || value.hatchery_name || JSON.stringify(value) : String(value)}</span>
          })}
        </div>
      ))}
    </div>
  )
}

export function UserDirectoryList({ users, messageBasePath, emptyMessage = 'No users found.' }) {
  if (!users?.length) return <EmptyState message={emptyMessage} />
  return (
    <div className="item-list">
      {users.map((user) => (
        <div className="card action" key={user.id}>
          <div>
            <div className="card-row">
              <strong><SellerLink id={user.sellerProfile?.id} name={user.name} /></strong>
              <Badge status={user.status || 'unknown'} />
            </div>
            <p>{user.email} · {user.phone || 'Not Available'}</p>
            <p className="muted">Joined {user.created_at ? new Date(user.created_at).toLocaleDateString() : 'Not Available'}</p>
          </div>
          <div className="row-actions">
            <Link className="ghost" to={`${messageBasePath}?tab=messages&with=${user.id}`}><MessageCircle size={16} /> Message</Link>
          </div>
        </div>
      ))}
    </div>
  )
}

export function PeriodFilteredRows({ rows, children, noun = 'orders placed', dateKey }) {
  const { shown, controls, periodEmpty } = usePeriodFilter(rows, { noun, dateKey })
  return (
    <>
      {controls}
      {periodEmpty || children(shown)}
    </>
  )
}

export function PeriodFilter({ period, onChange }) {
  return (
    <div className="tab-bar">
      {PERIOD_OPTIONS.map(([value, label]) => (
        <button key={value} type="button" className={period === value ? 'tab active' : 'tab'} onClick={() => onChange(value)}>{label}</button>
      ))}
    </div>
  )
}

export function Section({ title, actions, children, id }) {
  return (
    <section className="section" id={id}>
      <div className="section-head"><h2>{title}</h2>{actions}</div>
      {children}
    </section>
  )
}

export function Step({ n, t, d }) {
  return <div className="card step"><span>{n}</span><h3>{t}</h3><p>{d}</p></div>
}