import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { CalendarDays } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { EmptyState } from '../components/states.jsx'
import { api } from './api.js'
import { periodDateValue } from './format.js'
import { orderPeriodStart } from './orders.js'
import { getSession } from './session.js'
import { ORDER_PERIODS } from './status.js'



/** Closes a popover on an outside click or Escape. */
export function useDismiss(open, setOpen) {
  const ref = useRef(null)
  useEffect(() => {
    if (!open) return undefined
    const onPointer = (event) => {
      if (ref.current && !ref.current.contains(event.target)) setOpen(false)
    }
    const onKey = (event) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open, setOpen])
  return ref
}

/** Unread messages for the signed-in user (any role), polled for the sidebar badge and dashboard notice. */
export function useUnreadMessages() {
  const unread = useQuery({
    queryKey: ['messages-unread'],
    queryFn: async () => (await api.get('/messages/unread-count')).data,
    retry: false,
    refetchInterval: 30000,
  })
  return Number(unread.data?.count) || 0
}

/**
 * Whether the signed-in buyer or seller is suspended, read fresh from
 * /auth/me (the stored session can be days old). A suspended account can
 * still sign in -- to read why and ask for help -- but cannot buy or sell.
 */
export function useAccountSuspended() {
  const session = getSession()
  const relevant = ['buyer', 'seller'].includes(session?.role)
  const me = useQuery({
    queryKey: ['auth-me'],
    queryFn: async () => (await api.get('/auth/me')).data,
    enabled: relevant,
    retry: false,
    staleTime: 30000,
  })
  return relevant && Boolean(me.data?.account_suspended)
}

/**
 * A dashboard link can add &focus=<section id> to land on one section of a
 * tab (e.g. LGU Payouts below Seller Payouts). The section may render only
 * after its data loads, so this looks for it briefly instead of just once.
 */
export function useFocusSection() {
  const [searchParams] = useSearchParams()
  const focus = searchParams.get('focus')
  const tab = searchParams.get('tab')

  useEffect(() => {
    if (!focus) return undefined
    let tries = 0
    const timer = setInterval(() => {
      const target = document.getElementById(focus)
      tries += 1
      if (target || tries > 30) {
        clearInterval(timer)
        target?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }
    }, 100)
    return () => clearInterval(timer)
  }, [focus, tab])
}

/**
 * Narrows a history list (orders, withdrawals, payments, reviews, reports,
 * tickets...) to rows dated today / this week / this month / this year, by
 * each row's `dateKey` (created_at unless the list is about a later date,
 * such as settled_at; or a function returning the row's date). Used on every list that has a history, for every
 * role; the lists are already fully loaded, so this filters in the browser.
 * Returns the filtered rows, the filter to render above them, and
 * `periodEmpty`: when a period leaves nothing, an empty state saying so
 * ("No tickets received today."), to show in place of the list's own empty
 * message, which would wrongly suggest the whole list is empty.
 * `compact` renders a dropdown instead of buttons, for narrow columns.
 */
export function usePeriodFilter(rows, { noun, dateKey = 'created_at', compact = false }) {
  const [period, setPeriod] = useState('all')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const start = period === 'custom'
    ? (dateFrom ? new Date(`${dateFrom}T00:00:00`) : null)
    : orderPeriodStart(period)
  const endExclusive = period === 'custom' && dateTo
    ? new Date(`${dateTo}T00:00:00`)
    : null
  if (endExclusive) endExclusive.setDate(endExclusive.getDate() + 1)
  const all = rows || []
  const dateOf = typeof dateKey === 'function' ? dateKey : (row) => row[dateKey]
  const shown = period === 'custom'
    ? all.filter((row) => {
      const value = dateOf(row)
      if (!value) return false
      const date = periodDateValue(value)
      return (!start || date >= start) && (!endExclusive || date < endExclusive)
    })
    : (start ? all.filter((row) => dateOf(row) && new Date(dateOf(row)) >= start) : all)
  const when = period === 'custom' ? 'in this date range' : ORDER_PERIODS.find(([value]) => value === period)[1].toLowerCase()
  const filtered = period !== 'all' && (period !== 'custom' || Boolean(dateFrom || dateTo)) && all.length > 0
  const controls = (
    <div className="period-filter-controls">
      {compact ? (
        <label className="period-select">
          <CalendarDays size={16} />
          <select value={period} onChange={(e) => setPeriod(e.target.value)} aria-label={`Show ${noun}`}>
            {ORDER_PERIODS.map(([value, label]) => <option key={value} value={value}>{value === 'all' ? 'Any date' : label}</option>)}
          </select>
        </label>
      ) : (
        <div className="tab-bar" role="group" aria-label={`Show ${noun}`}>
          {ORDER_PERIODS.map(([value, label]) => (
            <button key={value} type="button" className={period === value ? 'tab active' : 'tab'} onClick={() => setPeriod(value)}>{label}</button>
          ))}
        </div>
      )}
      {period === 'custom' && (
        <div className="period-custom-range">
          <label>
            <span>From</span>
            <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} aria-label={`From date for ${noun}`} />
          </label>
          <label>
            <span>To</span>
            <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} aria-label={`To date for ${noun}`} />
          </label>
        </div>
      )}
    </div>
  )
  const periodEmpty = filtered && !shown.length ? <EmptyState message={`No ${noun} ${when}.`} /> : null
  return { shown, controls, periodEmpty }
}

/**
 * Shared Order Number search-box state/query, used by every role's Order
 * Lookup (Seller's own-listing lookup, Super Admin's global lookup) so the
 * search form and fetch behavior only exist once.
 */
export function useOrderNumberLookup(endpointBuilder, queryKeyPrefix) {
  const [orderNumberInput, setOrderNumberInput] = useState('')
  const [searchedOrderNumber, setSearchedOrderNumber] = useState('')

  const query = useQuery({
    queryKey: [queryKeyPrefix, searchedOrderNumber],
    queryFn: async () => (await api.get(endpointBuilder(searchedOrderNumber))).data,
    enabled: Boolean(searchedOrderNumber),
    retry: false,
  })

  const submit = (e) => {
    e.preventDefault()
    setSearchedOrderNumber(orderNumberInput.trim().toUpperCase())
  }

  return { orderNumberInput, setOrderNumberInput, submit, query, searchedOrderNumber }
}