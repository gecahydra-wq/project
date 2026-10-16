import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle, ChevronLeft, Clock, LifeBuoy, Mail, MessageCircle, Search, Send, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { ScreenshotInput } from '../../components/forms.jsx'
import { EmptyState, LoadingState } from '../../components/states.jsx'
import { Badge, Modal, RoleBadge, Section } from '../../components/ui.jsx'
import { api, queryClient, roleRoutes } from '../../lib/api.js'
import { apiErrorMessage } from '../../lib/errors.js'
import { formatMessageTimestamp } from '../../lib/format.js'
import { usePeriodFilter } from '../../lib/hooks.jsx'
import { getSession } from '../../lib/session.js'
import { supportAuthorLabel } from '../../lib/support.js'



/**
 * Help Center articles, shown on the public /help page and inside the Buyer
 * and Seller "Help & Support" tab. Written from how the app actually behaves
 * -- if a rule changes (a fee, a timeout, who approves what), update the
 * answer here too.
 */
const HELP_TOPICS = [
  {
    id: 'getting-started',
    title: 'Getting Started',
    audience: 'all',
    items: [
      ['What is AbaiMarket?', 'AbaiMarket is a marketplace for fish fingerlings from hatcheries in Cebu. Every hatchery is verified by its own LGU, and your payment is held safely until your order is done.'],
      ['How do I create an account?', 'Click Register, choose Buyer or Seller, and sign up with your email or Google account. Open the verification link we email you before logging in. Sellers also need their LGU to approve their hatchery before they can post listings.'],
      ['I did not get the verification email.', 'Check your spam or promotions folder first. If it is not there, try logging in. AbaiMarket will offer a Resend Verification Email button for accounts that are not verified yet.'],
      ['I forgot my password.', 'Click Forgot password? on the login page and enter your email. We will send you a link to set a new password. This works for Google accounts too.'],
      ['How do I contact support?', 'Log in, open Help & Support from the sidebar, and choose Contact Support. Fill in the form to send a support ticket or feedback. Your LGU or the AbaiMarket support team replies on your ticket, and you can see your past tickets and read the replies under My Tickets. You also get an email when your ticket is received, replied to or closed. Support may message you there to clarify your issue, so check it from time to time. Once they have, you can reply there. It is not real-time support, so allow some time for a reply.'],
    ],
  },
  {
    id: 'buying',
    title: 'Buying & Payments',
    audience: 'buyer',
    items: [
      ['How do I buy fingerlings?', 'Open a listing, choose how many fish (or how many bulks) you want, and place your order. The minimum order is shown on the listing. You then pay through PayMongo with GCash, Maya or a card.'],
      ['Is my payment safe?', 'Yes. Your payment is held by AbaiMarket, not sent straight to the seller. The seller is only paid after you confirm you received your fingerlings and their LGU approves the earnings.'],
      ['How long do I have to pay?', 'Unpaid orders are cancelled automatically after about 30 minutes, and the stock goes back to the listing. Until then, open the order with View Order in My Orders and use the Pay Now button.'],
      ['I paid, but my order still says unpaid.', 'It can take a moment for the payment to be confirmed, so refresh My Orders after a minute or two. If it still says unpaid, send a support ticket under "Payment or refund". Include the order number and a screenshot of your receipt.'],
      ['What does the Cart do?', 'The Cart is a list of listings you want to buy later. It does not hold stock for you, and prices and stock always show their current values. You check out one item at a time.'],
    ],
  },
  {
    id: 'orders',
    title: 'Orders, Delivery & Refunds',
    audience: 'buyer',
    items: [
      ['What do the order statuses mean?', 'Placed: waiting for payment or for the seller. Confirmed: the seller accepted it. Out for Delivery: the fingerlings are on the way. Completed: you confirmed you received them. Cancelled: the order was stopped.'],
      ['How do I confirm my delivery?', 'When your fingerlings arrive, open My Orders, click View Order on the order and click Confirm Received. Only do this after they have actually arrived, because it starts the seller\'s payment.'],
      ['Can I cancel my order?', 'Yes. From My Orders, click View Order on your order and use Cancel Order -- any time before it is Out for Delivery. An unpaid order cancels right away. A paid order needs a reason, and your payment is then refunded (refunds are processed by the Super Admin). You can still cancel while the seller is preparing your order (status Confirmed); once it is Out for Delivery the Cancel Order button is greyed out, because the fish may already be on their way -- message the seller instead. Unpaid orders are also cancelled automatically if you do not pay in time, and if the seller\'s account is suspended, your unfinished order is cancelled and refunded automatically.'],
      ['How do refunds work?', 'If a paid order is cancelled or expires (including when the seller is suspended), its payment shows Refund Pending. The AbaiMarket support team sends the refund, and the payment then shows Refunded. If it takes too long, send a support ticket under "Payment or refund".'],
      ['How do I rate a seller?', 'After an order is completed, use Rate Seller in the Rating column of My Orders -- a rating window also opens once you confirm receipt and close the order popup. If you close it, you can rate any time later, and your stars then show in that column. Your review appears on the seller\'s profile.'],
      ['A seller treated me unfairly. What do I do?', 'Use Report User on the seller\'s profile. Reports go to the seller\'s LGU and the Super Admin for review. Use a support ticket for problems with AbaiMarket itself, and a report for problems with a person.'],
    ],
  },
  {
    id: 'selling',
    title: 'Selling & Listings',
    audience: 'seller',
    items: [
      ['How do I start selling?', 'Register as a Seller and fill in your hatchery profile. Your municipality\'s LGU reviews and approves your registration. Once approved, you can create listings from the Listings tab.'],
      ['Do my listings need approval?', 'No. A listing goes live as soon as you post it. Your LGU can still review it and remove it if it breaks the rules.'],
      ['How do prices, bulks and minimum orders work?', 'You set a price per fish and say how many fish make up one bulk. Buyers can order by the fish or by the bulk. You can also set a minimum order so you never receive orders that are too small.'],
      ['Why can\'t I post a listing?', 'Your registration may still be waiting for LGU approval, your account may be suspended, or your listings may be paused because of an open Notice to Explain. Check your Notifications and Notices tab. If none of these apply, send a support ticket.'],
      ['How do I handle an order?', 'In the Orders tab, open the order with View Order, then move a paid order to Confirmed and then Out for Delivery. The buyer confirms when it arrives. You can cancel an order, with a reason, at any time before it is completed -- even while it is Out for Delivery, if the delivery runs into trouble; cancelling refunds the buyer and returns the reserved stock to your listing. Unpaid orders cannot be moved forward. If your account is suspended, all your unfinished orders are cancelled and the buyers refunded.'],
    ],
  },
  {
    id: 'wallet',
    title: 'Earnings & Withdrawals',
    audience: 'seller',
    items: [
      ['When do I get paid?', 'After the buyer confirms they received the order, your LGU reviews the earnings. Once approved, 94% of the order total goes to your Available Balance. The other 6% is shared: 2% to your LGU and 4% to AbaiMarket.'],
      ['How do I withdraw my money?', 'Open your Wallet and click Request Withdrawal. Choose GCash, Maya or a bank account. You receive the full amount you request. The Super Admin approves and sends the payout. Once it arrives, click Money Received to complete the withdrawal. If you do not confirm within the confirmation window, the system marks it paid automatically.'],
      ['My earnings review or withdrawal was rejected.', 'Open the rejected item and click Dispute This Rejection to explain your side. The person who rejected it reviews your dispute. If they accept it, the item is reopened for another review. A rejected withdrawal keeps its amount on hold for 7 days so you can dispute it once. If you do not dispute it within 7 days, or your dispute is rejected, the amount returns to your Available Balance on its own. If you still think a final rejection is wrong, message the Super Admin or send a support ticket under Wallet or withdrawal.'],
      ['What is a Notice to Explain?', 'If a review of 3 stars or fewer brings your average rating below 3, or your LGU finds a buyer\'s report against you valid, AbaiMarket sends you a Notice to Explain. The notice lists the low reviews behind it, and you can open each one. You are not suspended and your listings stay up while you send one explanation from the Notices tab. If it is rejected, your seller account is suspended and you can no longer explain in the app; to have it reviewed again, message your LGU or send a support ticket. After a rejected explanation, your next notice (from a rating or a report) suspends you right away with nothing to explain -- message your LGU or send a support ticket. An accepted explanation does not count against you. A 4 or 5 star review never causes a notice.'],
    ],
  },
]

const HELP_AUDIENCE_TABS = [
  ['all', 'All Topics'],
  ['buyer', 'For Buyers'],
  ['seller', 'For Sellers'],
]

/**
 * Searchable FAQ. `audience` limits it to one role's topics plus the general
 * ones; `includeGeneral={false}` drops the general Getting Started topic, which
 * only matters before someone has an account.
 */
function HelpArticles({ audience = 'all', showAudienceTabs = false, includeGeneral = true }) {
  const [query, setQuery] = useState('')
  const [activeAudience, setActiveAudience] = useState(audience)
  const needle = query.trim().toLowerCase()

  const topics = HELP_TOPICS
    .filter((topic) => includeGeneral || topic.audience !== 'all')
    .filter((topic) => activeAudience === 'all' || topic.audience === 'all' || topic.audience === activeAudience)
    .map((topic) => ({
      ...topic,
      items: needle
        ? topic.items.filter(([q, a]) => q.toLowerCase().includes(needle) || a.toLowerCase().includes(needle))
        : topic.items,
    }))
    .filter((topic) => topic.items.length)

  return (
    <div className="help-articles">
      {showAudienceTabs && (
        <div className="tab-bar">
          {HELP_AUDIENCE_TABS.map(([value, label]) => (
            <button key={value} type="button" className={activeAudience === value ? 'tab active' : 'tab'} onClick={() => setActiveAudience(value)}>{label}</button>
          ))}
        </div>
      )}
      <label className="help-search">
        <Search size={18} />
        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search help articles, e.g. refund, withdraw, password" aria-label="Search help articles" />
      </label>
      {topics.length ? topics.map((topic) => (
        <div className="help-topic" key={topic.id}>
          <h3>{topic.title}</h3>
          {topic.items.map(([q, a]) => (
            <details className="help-item" key={q} open={Boolean(needle)}>
              <summary>{q}</summary>
              <p>{a}</p>
            </details>
          ))}
        </div>
      )) : <EmptyState message="No articles match your search. Try another word, or contact support." />}
    </div>
  )
}

/** Shown wherever a ticket is sent or read: tickets are answered by email, not live. */
const SUPPORT_DISCLAIMER = "This is not real-time support. Your LGU or the AbaiMarket support team reviews tickets as soon as they can, and their replies appear on your ticket under My Tickets. We'll send you updates by email when your ticket is received, replied to or closed. They may message you to clarify your issue, so check your tickets from time to time. Once they have messaged you, you can reply here."

const SUPPORT_STATUS_META = {
  // Labelled from the reader's side. "resolved" is shown as Closed.
  user: {
    open: { label: 'Waiting on Support', tone: 'warning' },
    answered: { label: 'Support Replied', tone: 'info' },
    resolved: { label: 'Closed', tone: 'success' },
  },
  staff: {
    open: { label: 'Open', tone: 'warning' },
    answered: { label: 'Answered', tone: 'info' },
    resolved: { label: 'Closed', tone: 'success' },
  },
}

const SUPPORT_CATEGORY_LABELS = {
  order_delivery: 'Order or delivery',
  listing: 'Listing',
  seller_registration: 'Seller registration or verification',
  earnings: 'Seller earnings review',
  payment_refund: 'Payment or refund',
  wallet_withdrawal: 'Wallet or withdrawal',
  account: 'Account or login',
  technical: 'Technical problem or bug',
  feedback: 'Feedback or suggestion',
  other: 'Something else',
}

function SupportStatusBadge({ status, side = 'user' }) {
  const meta = SUPPORT_STATUS_META[side][status] || { label: status, tone: 'neutral' }
  return <Badge tone={meta.tone}>{meta.label}</Badge>
}

/** A ticket's screenshot as a thumbnail that opens the in-app viewer (with a way back). */
function SupportScreenshot({ url }) {
  const [open, setOpen] = useState(false)
  useEffect(() => {
    if (!open) return undefined
    const handleKey = (e) => { if (e.key === 'Escape') setOpen(false) }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [open])

  if (!url) return null
  return (
    <>
      <button type="button" className="support-screenshot" onClick={() => setOpen(true)} aria-label="View screenshot">
        <img src={url} alt="Attached screenshot" />
      </button>
      {open && (
        <div className="lightbox-overlay" onClick={() => setOpen(false)}>
          <button type="button" className="lightbox-close" onClick={() => setOpen(false)} aria-label="Close preview"><X size={22} /></button>
          <img className="lightbox-image" src={url} alt="Attached screenshot" onClick={(e) => e.stopPropagation()} />
        </div>
      )}
    </>
  )
}

/** "Still need help?" call to action. Sending a ticket needs a Buyer or Seller account. */
function SupportContactCard({ title, onContact }) {
  const session = getSession()
  const canSend = ['buyer', 'seller'].includes(session?.role)
  const isStaff = ['lgu_admin', 'super_admin'].includes(session?.role)

  return (
    <div className="card help-contact-card">
      <div>
        <strong>{title}</strong>
        <p>
          {canSend
            ? "Send a support ticket for help or to give feedback. We'll send you updates by email."
            : isStaff
              ? 'Tickets from buyers and sellers are in the Support Tickets tab of your dashboard.'
              : 'Log in to your AbaiMarket account to send a support ticket or feedback.'}
        </p>
      </div>
      {canSend && (onContact
        ? <button type="button" onClick={onContact}><LifeBuoy size={16} /> Contact Support</button>
        : <Link className="button" to={`${roleRoutes[session.role]}?tab=support&view=contact`}><LifeBuoy size={16} /> Contact Support</Link>)}
      {isStaff && <Link className="button" to={`${roleRoutes[session.role]}?tab=support`}>Open Support Tickets</Link>}
      {!session && <Link className="button" to="/login">Log In to Contact Support</Link>}
    </div>
  )
}

/** Public Help Center. Anyone can read it; sending a ticket needs an account. */
export function HelpCenterPage() {
  const session = getSession()

  return (
    <main className="help-page">
      <Section title="Help Center">
        <p className="helper-text">Answers to common questions about buying, selling, payments and your account.</p>
        <HelpArticles showAudienceTabs audience={session?.role === 'seller' ? 'seller' : session?.role === 'buyer' ? 'buyer' : 'all'} />
        <SupportContactCard title="Still need help?" />
      </Section>
    </main>
  )
}

/**
 * Buyer and Seller "Help & Support" tab: help articles, their past tickets,
 * and the contact form. ?view= picks the sub-tab and ?ticket= opens one
 * ticket, which is what the support emails and notifications link to.
 */
export function SupportPanel({ role }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const ticketId = searchParams.get('ticket')
  const view = ticketId ? 'tickets' : (searchParams.get('view') || 'help')

  const setView = (next) => setSearchParams({ tab: 'support', view: next })
  const openTicket = (id) => setSearchParams({ tab: 'support', view: 'tickets', ticket: String(id) })

  const tickets = useQuery({
    queryKey: ['my-support-tickets'],
    queryFn: async () => (await api.get('/support/tickets')).data,
    retry: false,
    placeholderData: [],
  })
  const replied = (tickets.data || []).filter((ticket) => ticket.status === 'answered').length
  const { shown: shownTickets, controls: ticketPeriodControls, periodEmpty: ticketPeriodEmpty } = usePeriodFilter(tickets.data, { noun: 'tickets sent' })

  return (
    <div className={view === 'contact' ? 'support-column support-column-wide' : 'support-column'}>
      <Section title="Help & Support">
        <div className="tab-bar">
          <button type="button" className={view === 'help' ? 'tab active' : 'tab'} onClick={() => setView('help')}>Help Articles</button>
          <button type="button" className={view === 'tickets' ? 'tab active' : 'tab'} onClick={() => setView('tickets')}>
            My Tickets{replied ? ` (${replied} replied)` : ''}
          </button>
          <button type="button" className={view === 'contact' ? 'tab active' : 'tab'} onClick={() => setView('contact')}>Contact Support</button>
        </div>

        {view === 'help' && (
          <>
            <HelpArticles audience={role} includeGeneral={false} />
            <SupportContactCard title="Didn&apos;t find your answer?" onContact={() => setView('contact')} />
          </>
        )}

        {view === 'contact' && <ContactSupportForm role={role} onViewTicket={openTicket} />}

        {view === 'tickets' && (ticketId ? (
          <UserSupportTicket ticketId={ticketId} onBack={() => setView('tickets')} onNew={() => setView('contact')} />
        ) : (tickets.data || []).length ? (
          <>
          <div className="support-ticket-period">{ticketPeriodControls}</div>
          {shownTickets.length ? (
          <div className="item-list">
            {shownTickets.map((ticket) => (
              <button type="button" className="card support-ticket-row" key={ticket.id} onClick={() => openTicket(ticket.id)}>
                <div className="card-row">
                  <strong>{ticket.subject}</strong>
                  <SupportStatusBadge status={ticket.status} />
                </div>
                <p className="muted">
                  {ticket.ticket_number} · {SUPPORT_CATEGORY_LABELS[ticket.category] || ticket.category}
                  {ticket.order?.order_number ? ` · Order ${ticket.order.order_number}` : ''}
                  {' · '}Updated {formatMessageTimestamp(ticket.last_activity_at || ticket.created_at)}
                </p>
              </button>
            ))}
          </div>
          ) : ticketPeriodEmpty}
          </>
        ) : (
          <EmptyState
            title="No tickets yet"
            message="Tickets you send from Contact Support, and the replies to them, show up here."
            icon={LifeBuoy}
          />
        ))}
      </Section>
    </div>
  )
}

const CONTACT_INFO = [
  [Mail, 'Email Updates', "We'll send you updates when your ticket is received, when support replies, and when it is closed."],
  [Clock, 'Not Real-Time', 'Tickets are reviewed as soon as possible. This is not a live chat.'],
  [LifeBuoy, 'Track Your Tickets', 'Support may message you to clarify. Check your tickets from time to time and reply there.'],
  [MessageCircle, 'Feedback Welcome', 'Pick "Feedback or suggestion" as the topic to share your ideas.'],
]

/**
 * The contact form: who you are and how to reach you, on the right; how
 * support works, on the left. Sending it opens a ticket for the user's LGU
 * and the Super Admin, and emails the user a confirmation.
 */
function ContactSupportForm({ role, onViewTicket }) {
  const session = getSession()
  const [first, ...rest] = (session?.name || '').trim().split(/\s+/)
  const emptyForm = {
    first_name: first || '',
    last_name: rest.join(' '),
    contact_email: session?.email || '',
    category: '',
    subject: '',
    order_number: '',
    body: '',
  }
  const [form, setForm] = useState(emptyForm)
  const [attachment, setAttachment] = useState(null)
  const [sent, setSent] = useState(null)
  const [missing, setMissing] = useState([])
  const setField = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  const categories = useQuery({
    queryKey: ['support-categories'],
    queryFn: async () => (await api.get('/support/categories')).data.categories,
    retry: false,
    placeholderData: [],
  })

  const submit = useMutation({
    mutationFn: async () => {
      const formData = new FormData()
      Object.entries(form).forEach(([key, value]) => { if (value.trim()) formData.append(key, value.trim()) })
      if (attachment) formData.append('attachment', attachment)
      return (await api.post('/support/tickets', formData)).data
    },
    onSuccess: (ticket) => {
      queryClient.invalidateQueries({ queryKey: ['my-support-tickets'] })
      setSent(ticket)
      setForm(emptyForm)
      setAttachment(null)
    },
  })

  // What still needs fixing, said out loud when Send Ticket is clicked --
  // a silently disabled button left users guessing which field was wrong.
  const problems = () => [
    !form.first_name.trim() && 'Enter your first name.',
    !form.contact_email.trim() && 'Enter your email.',
    !form.category && 'Choose a topic.',
    form.subject.trim().length < 5 && 'Write a subject of at least 5 characters.',
    form.body.trim().length < 10 && 'Write a message of at least 10 characters.',
  ].filter(Boolean)

  return (
    <div className="contact-layout">
      <div className="card contact-panel">
        <h3 className="contact-panel-head">How Support Works</h3>
        <div className="contact-info-grid">
          {CONTACT_INFO.map(([Icon, title, text]) => (
            <div className="contact-info-item" key={title}>
              <Icon size={28} />
              <h4>{title}</h4>
              <p>{text}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="card contact-panel">
        <h3 className="contact-panel-head">Contact Support</h3>
        {sent ? (
          <div className="contact-sent">
            <CheckCircle size={40} />
            <h4>Ticket {sent.ticket_number} sent</h4>
            <p>We emailed a confirmation to {sent.contact_email}. {SUPPORT_DISCLAIMER}</p>
            <div className="contact-actions">
              <button type="button" onClick={() => onViewTicket(sent.id)}>View My Ticket</button>
              <button type="button" className="ghost" onClick={() => setSent(null)}>Send Another</button>
            </div>
          </div>
        ) : (
          <form className="form contact-form" onSubmit={(e) => { e.preventDefault(); const found = problems(); setMissing(found); if (!found.length) submit.mutate() }}>
            <p className="contact-disclaimer"><Clock size={16} /> {SUPPORT_DISCLAIMER}</p>
            <div className="contact-form-grid">
              <label className="filter-label">
                <span>First Name <span className="required">*</span></span>
                <input value={form.first_name} maxLength={100} onChange={setField('first_name')} autoComplete="given-name" />
              </label>
              <label className="filter-label">
                Last Name
                <input value={form.last_name} maxLength={100} onChange={setField('last_name')} autoComplete="family-name" />
              </label>
              <label className="filter-label contact-form-full">
                <span>Email <span className="required">*</span></span>
                <input type="email" value={form.contact_email} maxLength={255} onChange={setField('contact_email')} autoComplete="email" />
              </label>
              <label className="filter-label">
                <span>Topic <span className="required">*</span></span>
                <select value={form.category} onChange={setField('category')}>
                  <option value="">Choose a topic</option>
                  {(categories.data || []).map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}
                </select>
              </label>
              <label className="filter-label">
                Order Number (optional)
                <input value={form.order_number} maxLength={50} onChange={setField('order_number')} placeholder="e.g. FG-ABC123" />
              </label>
              <label className="filter-label contact-form-full">
                <span>Subject <span className="required">*</span></span>
                <input value={form.subject} maxLength={150} onChange={setField('subject')} placeholder="A short summary, e.g. Charged but order says unpaid" />
              </label>
              <label className="filter-label contact-form-full">
                <span>Message <span className="required">*</span></span>
                <textarea
                  value={form.body}
                  maxLength={5000}
                  rows={6}
                  onChange={setField('body')}
                  placeholder="What happened, when, and what you expected (at least 10 characters)."
                />
              </label>
            </div>
            <ScreenshotInput file={attachment} onChange={setAttachment} disabled={submit.isPending} />
            <p className="helper-text">
              {role === 'buyer'
                ? 'Having trouble with a seller\'s behaviour? Use Report User on their profile instead.'
                : 'Appealing a rejected earnings review or withdrawal? Use Dispute This Rejection on that item instead.'}
            </p>
            {missing.length > 0 && <p className="error">{missing.join(' ')}</p>}
            {submit.error && <p className="error">{apiErrorMessage(submit.error, 'Could not send your ticket.')}</p>}
            <div className="contact-actions">
              <button type="submit" disabled={submit.isPending}>{submit.isPending ? 'Sending...' : <><Send size={16} /> Send Ticket</>}</button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}

/**
 * One of the user's own tickets: what they sent, the staff replies, and a
 * reply box to clarify things until it is closed. Not real-time support.
 */
function UserSupportTicket({ ticketId, onBack, onNew }) {
  const [draft, setDraft] = useState('')
  const queryKey = ['support-ticket', String(ticketId)]
  const ticket = useQuery({
    queryKey,
    queryFn: async () => (await api.get(`/support/tickets/${ticketId}`)).data,
    retry: false,
  })
  const reply = useMutation({
    mutationFn: async () => (await api.post(`/support/tickets/${ticketId}/replies`, { body: draft.trim() })).data,
    onSuccess: (data) => {
      setDraft('')
      queryClient.setQueryData(queryKey, data)
      queryClient.invalidateQueries({ queryKey: ['my-support-tickets'] })
    },
  })

  if (ticket.isLoading) return <LoadingState label="Loading ticket..." />

  const back = <button type="button" className="ghost" onClick={onBack}><ChevronLeft size={16} /> Back to My Tickets</button>
  if (ticket.error || !ticket.data) {
    return <div className="thread-view">{back}<p className="error">{apiErrorMessage(ticket.error, 'Could not load this ticket.')}</p></div>
  }

  const data = ticket.data
  const [original, ...responses] = data.messages || []
  const closed = data.status === 'resolved'
  const isOwn = (message) => message.user_id === data.user_id
  // Support has to message first; after that the user can reply freely.
  const canReply = !closed && responses.some((message) => !isOwn(message))

  return (
    <div className="thread-view">
      <div className="row-actions">{back}</div>
      <div className="card ticket-detail">
        <div className="card-row">
          <h3>{data.subject}</h3>
          <SupportStatusBadge status={data.status} />
        </div>
        <p className="muted">
          {data.ticket_number} · {SUPPORT_CATEGORY_LABELS[data.category] || data.category}
          {data.order?.order_number ? ` · Order ${data.order.order_number}` : ''}
          {' · '}Sent {formatMessageTimestamp(data.created_at)}
        </p>
        {original && (
          <div className="ticket-message">
            <strong>Your message</strong>
            <p>{original.body}</p>
            <SupportScreenshot url={original.attachment_url} />
          </div>
        )}
        {responses.length ? responses.map((message) => (
          <div className={isOwn(message) ? 'ticket-response mine' : 'ticket-response theirs'} key={message.id}>
            <div className="ticket-response-head">
              <strong>{isOwn(message) ? 'You' : supportAuthorLabel(message)}</strong>
              <span className="muted">{formatMessageTimestamp(message.created_at)}</span>
            </div>
            <p>{message.body}</p>
          </div>
        )) : (
          <p className="helper-text">No reply yet. You will get an email at {data.contact_email || 'your email'} when support messages you.</p>
        )}
        <p className="contact-disclaimer"><Clock size={16} /> {SUPPORT_DISCLAIMER}</p>
        {closed ? (
          <div className="row-actions">
            <span className="muted">This ticket is closed. Send a new ticket and mention {data.ticket_number} if you still need help.</span>
            <button type="button" onClick={onNew}>Send a New Ticket</button>
          </div>
) : !canReply ? (
          <p className="helper-text">You can reply once support messages you on this ticket.</p>
        ) : (
          <form className="ticket-reply" onSubmit={(e) => { e.preventDefault(); if (draft.trim()) reply.mutate() }}>
            <textarea
              value={draft}
              rows={3}
              maxLength={5000}
              onChange={(e) => setDraft(e.target.value)}
              placeholder="Reply to support's message..."
            />
            {reply.error && <p className="error">{apiErrorMessage(reply.error, 'Could not send your reply.')}</p>}
            <div className="ticket-reply-actions">
              <button type="submit" disabled={!draft.trim() || reply.isPending}><Send size={16} /> {reply.isPending ? 'Sending...' : 'Send Reply'}</button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}

const STAFF_TICKET_FILTERS = [
  ['open', 'Open'],
  ['answered', 'Answered'],
  ['resolved', 'Closed'],
  ['all', 'All Tickets'],
]

/**
 * LGU Admin and Super Admin support inbox: the ticket list on the left and
 * the selected ticket on the right. The LGU sees its municipality's tickets;
 * the Super Admin sees every ticket. Whoever picks one up answers it.
 */
export function SupportTicketsAdminPanel({ scope }) {
  const isSuper = scope === 'super-admin'
  const base = isSuper ? '/super-admin' : '/lgu'
  const queryKey = `${scope}-support-tickets`
  const [searchParams, setSearchParams] = useSearchParams()
  const ticketId = searchParams.get('ticket')
  const [statusFilter, setStatusFilter] = useState('open')
  const [search, setSearch] = useState('')

  const tickets = useQuery({
    queryKey: [queryKey],
    queryFn: async () => (await api.get(`${base}/support-tickets`)).data,
    retry: false,
    placeholderData: [],
    refetchInterval: 30000,
  })

  const openTicket = (id) => setSearchParams({ tab: 'support', ticket: String(id) })
  const refresh = () => queryClient.invalidateQueries({ queryKey: [queryKey] })

  const all = tickets.data || []
  const countFor = (value) => (value === 'all' ? all.length : all.filter((ticket) => ticket.status === value).length)
  const needle = search.trim().toLowerCase()
  const rows = all.filter((ticket) => {
    if (statusFilter !== 'all' && ticket.status !== statusFilter) return false
    if (!needle) return true
    return [ticket.ticket_number, ticket.subject, ticket.user?.name, ticket.first_name, ticket.last_name, ticket.contact_email, ticket.order?.order_number, ticket.municipality?.name]
      .some((value) => String(value || '').toLowerCase().includes(needle))
  })
  const { shown: periodRows, controls: periodControls, periodEmpty } = usePeriodFilter(rows, { noun: 'tickets received', compact: true })

  return (
    <Section title="Support Tickets">
      <p className="helper-text">
        {isSuper
          ? 'Tickets and feedback from buyers and sellers on the whole platform. Each one is also visible to the LGU of its municipality (buyer tickets not about an order, to every LGU), so whichever of you picks it up answers it, whatever the topic.'
          : 'Tickets and feedback from sellers in your municipality and buyers ordering from them, plus buyer tickets not about an order, which every LGU can see. The Super Admin sees the same tickets, so whichever of you picks one up answers it, whatever the topic.'}
        {' '}This is not real-time support: responses are emailed to the user.
      </p>
      <div className="ticket-inbox">
        <aside className="card ticket-inbox-list">
          <nav className="ticket-inbox-filters">
            {STAFF_TICKET_FILTERS.map(([value, label]) => (
              <button
                type="button"
                key={value}
                className={statusFilter === value ? 'ticket-inbox-filter active' : 'ticket-inbox-filter'}
                onClick={() => setStatusFilter(value)}
              >
                {label}
                <span className="ticket-inbox-count">{countFor(value)}</span>
              </button>
            ))}
          </nav>
          <label className="help-search">
            <Search size={16} />
            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ticket, name, email or order" />
          </label>
          {periodControls}
          {periodRows.length ? (
            <div className="ticket-inbox-rows">
              {periodRows.map((ticket) => {
                const sender = [ticket.first_name, ticket.last_name].filter(Boolean).join(' ') || ticket.user?.name || 'Unknown'
                const where = isSuper ? ticket.municipality?.name || 'All LGUs' : !ticket.municipality_id ? 'All LGUs' : ''
                const who = [sender, where].filter(Boolean).join(' · ')
                const topic = SUPPORT_CATEGORY_LABELS[ticket.category] || ticket.category
                // Every line is one line, cut with an ellipsis -- the full
                // text is in the tooltip and in the ticket itself -- so every
                // row is the same shape and the badges line up.
                return (
                  <button
                    type="button"
                    key={ticket.id}
                    className={String(ticket.id) === ticketId ? 'ticket-inbox-row active' : 'ticket-inbox-row'}
                    onClick={() => openTicket(ticket.id)}
                  >
                    <span className="ticket-inbox-row-top">
                      <strong title={ticket.subject}>{ticket.subject}</strong>
                      <SupportStatusBadge status={ticket.status} side="staff" />
                    </span>
                    <span className="ticket-inbox-row-line" title={who}>
                      <span className="ticket-inbox-number">{ticket.ticket_number}</span> · {who}
                    </span>
                    <span className="ticket-inbox-row-foot">
                      <span className="ticket-inbox-topic" title={topic}>{topic}</span>
                      <span>{formatMessageTimestamp(ticket.last_activity_at || ticket.created_at)}</span>
                    </span>
                  </button>
                )
              })}
            </div>
          ) : periodEmpty || <EmptyState message="No tickets match this filter." icon={LifeBuoy} />}
        </aside>

        <div className="ticket-inbox-detail">
          {ticketId
            ? <StaffSupportTicket key={ticketId} ticketId={ticketId} base={base} onChanged={refresh} />
            : <EmptyState title="Select a ticket" message="Choose a ticket on the left to read it and respond." icon={LifeBuoy} />}
        </div>
      </div>
    </Section>
  )
}

/**
 * One ticket for the LGU / Super Admin: the sender's contact details and
 * message, the responses and internal notes so far, and the response box.
 * Send Response emails the user; Internal Note is staff-only.
 */
function StaffSupportTicket({ ticketId, base, onChanged }) {
  const [draft, setDraft] = useState('')
  const [closing, setClosing] = useState(false)
  const [note, setNote] = useState('')
  const queryKey = ['support-ticket', String(ticketId)]

  const ticket = useQuery({
    queryKey,
    queryFn: async () => (await api.get(`/support/tickets/${ticketId}`)).data,
    retry: false,
  })

  const refresh = (data) => {
    if (data?.id) queryClient.setQueryData(queryKey, data)
    onChanged?.()
  }

  const respond = useMutation({
    mutationFn: async (internal) => (await api.post(`${base}/support-tickets/${ticketId}/responses`, { body: draft.trim(), internal })).data,
    onSuccess: (data) => { setDraft(''); refresh(data) },
  })
  const resolve = useMutation({
    mutationFn: async () => (await api.patch(`${base}/support-tickets/${ticketId}/resolve`, { note: note.trim() || undefined })).data,
    onSuccess: (data) => { setClosing(false); setNote(''); refresh(data) },
  })

  if (ticket.isLoading) return <LoadingState label="Loading ticket..." />
  if (ticket.error || !ticket.data) return <p className="error">{apiErrorMessage(ticket.error, 'Could not load this ticket.')}</p>

  const data = ticket.data
  const [original, ...responses] = data.messages || []
  const contactName = [data.first_name, data.last_name].filter(Boolean).join(' ') || data.user?.name
  const replyTo = data.contact_email || data.user?.email
  const closed = data.status === 'resolved'

  return (
    <div className="card ticket-detail">
      <div className="card-row">
        <SupportStatusBadge status={data.status} side="staff" />
        <span className="muted">{formatMessageTimestamp(data.created_at)}</span>
      </div>
      <h3>Ticket {data.ticket_number}: {data.subject}</h3>
      <dl className="ticket-facts">
        <div><dt>Contact</dt><dd>{contactName} {data.user && <RoleBadge role={data.user.role} />}{data.user?.name && data.user.name !== contactName ? ` (account: ${data.user.name})` : ''}</dd></div>
        <div><dt>Email</dt><dd>{replyTo}{data.user?.email && data.user.email !== replyTo ? ` (account: ${data.user.email})` : ''}</dd></div>
        <div><dt>Topic</dt><dd>{SUPPORT_CATEGORY_LABELS[data.category] || data.category}</dd></div>
        {data.order?.order_number && <div><dt>Order</dt><dd>{data.order.order_number}</dd></div>}
        <div><dt>Municipality</dt><dd>{data.municipality?.name || 'None (buyer ticket, open to every LGU)'}</dd></div>
      </dl>
      {original && (
        <div className="ticket-message">
          <strong>Describe the issue</strong>
          <p>{original.body}</p>
          <SupportScreenshot url={original.attachment_url} />
        </div>
      )}

      {responses.map((message) => (
        <div className={message.user_id === data.user_id ? 'ticket-response theirs' : message.is_internal ? 'ticket-response mine internal' : 'ticket-response mine'} key={message.id}>
          <div className="ticket-response-head">
            <strong>{message.author?.name || 'Staff'}</strong>
            <RoleBadge role={message.author_role} />
            {message.is_internal && <Badge tone="warning">Internal Note</Badge>}
            <span className="muted">{formatMessageTimestamp(message.created_at)}</span>
          </div>
          <p>{message.body}</p>
        </div>
      ))}

      {closed ? (
        <p className="helper-text ticket-reply">
          This ticket is closed{data.resolver?.name ? ` by ${data.resolver.name}` : ''}{data.resolved_at ? ` on ${formatMessageTimestamp(data.resolved_at)}` : ''}. It can no longer be replied to.
        </p>
      ) : (
      <div className="ticket-reply">
        <textarea
          value={draft}
          rows={4}
          maxLength={5000}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="Write a response..."
        />
        <p className="helper-text">Send Response emails your reply to {replyTo} and shows it under the user&apos;s My Tickets. Internal Note is only visible to LGU and Super Admin staff.</p>
        {respond.error && <p className="error">{apiErrorMessage(respond.error, 'Could not save your response.')}</p>}
        <div className="ticket-reply-actions">
          <button type="button" disabled={!draft.trim() || respond.isPending} onClick={() => respond.mutate(false)}><Send size={16} /> Send Response</button>
          <button type="button" className="ghost" disabled={!draft.trim() || respond.isPending} onClick={() => respond.mutate(true)}>Internal Note</button>
          <button type="button" className="ghost ticket-close-button" onClick={() => { setClosing(true); setNote('') }}><CheckCircle size={16} /> Close Ticket</button>
        </div>
      </div>
      )}

      {closing && (
        <Modal
          title="Close Ticket"
          subtitle={`${data.ticket_number} · ${data.subject}`}
          onClose={() => setClosing(false)}
          footer={
            <>
              <button type="button" disabled={resolve.isPending} onClick={() => resolve.mutate()}>Close Ticket</button>
              <button type="button" className="ghost" onClick={() => setClosing(false)}>Cancel</button>
            </>
          }
        >
          <p className="helper-text">The user is notified and emailed that the ticket is closed. A closing note is optional; it is included in the email and shown on their ticket.</p>
          <textarea value={note} rows={3} maxLength={5000} onChange={(e) => setNote(e.target.value)} placeholder="Closing note to the user (optional)" />
          {resolve.error && <p className="error">{apiErrorMessage(resolve.error)}</p>}
        </Modal>
      )}
    </div>
  )
}