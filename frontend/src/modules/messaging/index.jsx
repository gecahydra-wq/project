import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useEffect, useRef, useState } from 'react'
import { Avatar, Modal } from '../../components/ui.jsx'
import { api, queryClient, roleRoutes } from '../../lib/api.js'
import { formatMessageTimestamp } from '../../lib/format.js'
import { getSession, sellerProfilePath } from '../../lib/session.js'



export function MessagesPanel({ initialUserId }) {
  const session = getSession()
  const [activeUserId, setActiveUserId] = useState(initialUserId || null)
  const [draft, setDraft] = useState('')
  const openedInitialRef = useRef(false)

  // There is no websocket: a reply would otherwise sit unseen until the panel
  // remounted or the window regained focus. Polling is scoped to this component,
  // which is only mounted while the Messages tab is open, and React Query pauses
  // intervals on a backgrounded tab (refetchIntervalInBackground defaults to
  // false), so a minimised browser stops asking. The open conversation refreshes
  // faster than the thread list because that is where someone is actually
  // waiting for a reply.
  const threads = useQuery({
    queryKey: ['message-threads'],
    queryFn: async () => (await api.get('/messages/threads')).data,
    retry: false,
    placeholderData: [],
    refetchInterval: 15000,
  })

  const thread = useQuery({
    queryKey: ['message-thread', activeUserId],
    queryFn: async () => (await api.get(`/messages/thread/${activeUserId}`)).data,
    enabled: !!activeUserId,
    retry: false,
    refetchInterval: 5000,
  })

  const markRead = useMutation({
    mutationFn: async (userId) => (await api.patch(`/messages/thread/${userId}/read`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
      queryClient.invalidateQueries({ queryKey: ['messages-unread'] })
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['seller-dashboard'] })
    },
  })

  const sendMessage = useMutation({
    mutationFn: async () => (await api.post('/messages', { receiver_id: activeUserId, body: draft })).data,
    onSuccess: () => {
      setDraft('')
      queryClient.invalidateQueries({ queryKey: ['message-thread', activeUserId] })
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
    },
  })

  const editMessage = useMutation({
    mutationFn: async ({ id, body }) => (await api.patch(`/messages/${id}`, { body })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['message-thread', activeUserId] })
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
    },
  })

  const deleteMessage = useMutation({
    mutationFn: async (id) => (await api.delete(`/messages/${id}`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['message-thread', activeUserId] })
      queryClient.invalidateQueries({ queryKey: ['message-threads'] })
    },
  })

  useEffect(() => {
    if (initialUserId && !openedInitialRef.current) {
      openedInitialRef.current = true
      setActiveUserId(initialUserId)
      markRead.mutate(initialUserId)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialUserId])

  const openThread = (userId) => {
    setActiveUserId(userId)
    markRead.mutate(userId)
  }

  // markRead used to fire only when a thread was opened. Now that replies can
  // arrive into a thread that is already on screen, one landing here would stay
  // counted as unread -- so mark it read as it arrives. Reading the count from a
  // ref keeps this to one PATCH per batch of new messages rather than one per
  // render, and markRead only invalidates 'message-threads', so it cannot
  // retrigger the thread query that fed it.
  const seenCountRef = useRef(0)
  useEffect(() => {
    const count = thread.data?.messages?.length ?? 0
    if (!activeUserId) { seenCountRef.current = 0; return }
    if (count > seenCountRef.current) {
      if (seenCountRef.current > 0) markRead.mutate(activeUserId)
      seenCountRef.current = count
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [thread.data, activeUserId])

  // Where "View Profile" goes for the person in the open conversation, or null
  // when this pairing has no profile page to open.
  const counterpart = thread.data?.user
  const threadProfilePath = (() => {
    if (!counterpart) return null
    if (session?.role === 'seller' && counterpart.role === 'buyer') return `/seller/buyers/${counterpart.id}`
    if (session?.role === 'buyer' && counterpart.role === 'seller' && counterpart.seller_profile_id) {
      return sellerProfilePath(counterpart.seller_profile_id)
    }
    return null
  })()

  return (
    <div className="messages-layout">
      <div className="card thread-list">
        {!threads.data?.length && <p className="helper-text">No conversations yet.</p>}
        {(threads.data || []).map((item) => (
          <button
            type="button"
            key={item.user.id}
            className={`thread-item ${activeUserId === item.user.id ? 'active' : ''}`}
            onClick={() => openThread(item.user.id)}
          >
            <Avatar src={item.user.profile_picture} alt={item.user.name} className="thread-avatar" />
            <div>
              <strong>{item.user.name}</strong>
              <span>{item.last_message?.body}</span>
              {item.last_message?.created_at && (
                <span className="thread-timestamp">{formatMessageTimestamp(item.last_message.created_at)}</span>
              )}
            </div>
            {item.unread_count > 0 && <span className="pill">{item.unread_count} new</span>}
          </button>
        ))}
      </div>
      <div className="card thread-view">
        {!activeUserId && <p>Select a conversation to view messages.</p>}
        {activeUserId && (
          <>
            {/* The name is wrapped rather than left as a bare text node so it
                can be truncated: without a box of its own a long name grows the
                header and shunts the avatar and View Profile around. */}
            <h4 className="thread-header">
              <Avatar src={thread.data?.user?.profile_picture} alt={thread.data?.user?.name} className="thread-header-avatar" />
              <span className="thread-header-name">{thread.data?.user?.name || 'Conversation'}</span>
              {/* View Profile works both ways: a seller opens the buyer they're
                  talking to, and a buyer opens the hatchery. The seller's page
                  is addressed by seller_profiles.id, which the thread payload
                  carries for seller counterparts (MessageController). */}
              {threadProfilePath && (
                <Link className="seller-name-link thread-view-profile" to={threadProfilePath}>View Profile</Link>
              )}
            </h4>
            <div className="message-log">
              {(thread.data?.messages || []).map((message) => (
                <MessageBubble
                  key={message.id}
                  message={message}
                  isMine={message.sender_id === session?.id}
                  automated={thread.data?.user?.role === 'system'}
                  onEdit={(body) => editMessage.mutateAsync({ id: message.id, body })}
                  onDelete={() => deleteMessage.mutateAsync(message.id)}
                />
              ))}
              {!thread.data?.messages?.length && <p className="helper-text">Say hello to start the conversation.</p>}
            </div>
            {thread.data?.user?.role === 'system' ? (
              // A system thread is one-way: AbaiMarket only ever sends here.
              // There is nothing to type, so the bar offers the two places a
              // human can actually answer instead of a reply box.
              <div className="compose-bar">
                {thread.data.support_contact && (
                  <button type="button" className="button" onClick={() => openThread(thread.data.support_contact.id)}>Message the Super Admin</button>
                )}
                <Link className="ghost" to={`${roleRoutes[session?.role] || '/buyer/dashboard'}?tab=support`}>Contact Support</Link>
              </div>
            ) : (
              <div className="compose-bar">
                <input
                  placeholder="Type a message..."
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter' && draft.trim() && !sendMessage.isPending) { e.preventDefault(); sendMessage.mutate() } }}
                />
                <button type="button" onClick={() => sendMessage.mutate()} disabled={!draft.trim() || sendMessage.isPending}>Send</button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}

const MESSAGE_EDIT_WINDOW_MS = 15 * 60 * 1000

function MessageBubble({ message, isMine, onEdit, onDelete, automated = false }) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(message.body)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [attachmentUrl, setAttachmentUrl] = useState('')
  const [showReceipt, setShowReceipt] = useState(false)
  const [renderedAt] = useState(() => Date.now())
  useEffect(() => {
    if (!message.has_attachment) return undefined
    let objectUrl = ''
    let cancelled = false
    api.get(`/messages/${message.id}/attachment`, { responseType: 'blob' }).then(({ data }) => {
      if (cancelled) return
      objectUrl = URL.createObjectURL(data)
      setAttachmentUrl(objectUrl)
    }).catch(() => setAttachmentUrl(''))
    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [message.id, message.has_attachment])

  const isDeleted = !!message.deleted_at
  const withinEditWindow = renderedAt - new Date(message.created_at).getTime() <= MESSAGE_EDIT_WINDOW_MS
  const canModify = isMine && !isDeleted
  const sellerProfileLink = message.message_type === 'order_auto_completed'
    ? message.body.match(/\[([^\]]+)\]\((\/sellers\/\d+)\)/)
    : null

  const saveEdit = async () => {
    setBusy(true)
    setError('')
    try {
      await onEdit(draft)
      setEditing(false)
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not edit message.')
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    setBusy(true)
    setError('')
    try {
      await onDelete()
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not delete message.')
      setBusy(false)
    }
  }

  if (editing) {
    return (
      <div className={`message-bubble ${isMine ? 'mine' : 'theirs'}`}>
        <input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey && draft.trim() && !busy) { e.preventDefault(); saveEdit() } }}
          disabled={busy}
        />
        <div className="row-actions">
          <button type="button" onClick={saveEdit} disabled={busy || !draft.trim()}>Save</button>
          <button type="button" className="ghost" disabled={busy} onClick={() => { setEditing(false); setDraft(message.body); setError('') }}>Cancel</button>
        </div>
        {error && <p className="error">{error}</p>}
      </div>
    )
  }

  return (
    <div className={`message-bubble ${isMine ? 'mine' : 'theirs'}`}>
      <p className={isDeleted ? 'deleted' : ''}>
        {sellerProfileLink ? <>
          {message.body.slice(0, sellerProfileLink.index)}
          <Link className="seller-name-link" to={sellerProfileLink[2]}>{sellerProfileLink[1]}</Link>
          {message.body.slice(sellerProfileLink.index + sellerProfileLink[0].length)}
        </> : message.body}
      </p>
      {message.has_attachment && attachmentUrl && (
        <button type="button" className="message-attachment-trigger" onClick={() => setShowReceipt(true)} aria-label="View payout receipt">
          <img className="message-attachment" src={attachmentUrl} alt="Payout receipt attachment" />
        </button>
      )}
      {showReceipt && attachmentUrl && (
        <Modal
          title="Payout receipt"
          subtitle="Receipt image attached to this message"
          className="receipt-viewer-modal"
          onClose={() => setShowReceipt(false)}
        >
          <img className="message-attachment-full" src={attachmentUrl} alt="Full-size payout receipt" />
        </Modal>
      )}
      {['payout_receipt', 'payout_auto_completed', 'order_auto_completed'].includes(message.message_type) && !isMine && (
        <div className="row-actions">
          <Link className="link-action" to={getSession()?.role === 'buyer' ? '/buyer/dashboard?tab=support' : getSession()?.role === 'lgu_admin' ? '/lgu/dashboard?tab=support' : '/seller/dashboard?tab=support'}>Contact Support</Link>
        </div>
      )}
      <div className="message-meta">
        {/* One-way messages from AbaiMarket carry a tag of their own: the
            reader should know at a glance that no person wrote (or can
            answer) this, whatever the message type. */}
        {automated && <span className="automated-tag">Automated message</span>}
        {/* Date and time on every message, for whoever is reading it -- this
            bubble renders identically for the sender and the receiver, so both
            sides see the same stamp. Read straight from messages.created_at,
            which every message has always had, so existing conversations show
            their real history rather than a blank. */}
        <span className="message-timestamp" title={new Date(message.created_at).toString()}>
          {formatMessageTimestamp(message.created_at)}
        </span>
        {!isDeleted && message.edited_at && (
          <span className="muted" title={new Date(message.edited_at).toString()}>· edited {formatMessageTimestamp(message.edited_at)}</span>
        )}
        {canModify && withinEditWindow && <button type="button" className="link-action" onClick={() => setEditing(true)}>Edit</button>}
        {canModify && <button type="button" className="link-action" disabled={busy} onClick={remove}>Delete</button>}
      </div>
      {error && <p className="error">{error}</p>}
    </div>
  )
}