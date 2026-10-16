import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Bot, Camera, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { api } from '../../lib/api.js'
import { aiErrorMessage } from '../../lib/errors.js'
import { getSession } from '../../lib/session.js'



const AI_GREETING_BY_ROLE = {
  buyer: 'Ask AbaiMarket AI about buying fingerlings, contacting sellers, orders, wallet, reviews, or fish farming basics like species, water quality, and feeding.',
  seller: 'Ask AbaiMarket AI about your listings, orders, wallet, seller earnings, withdrawals, reviews, business recommendations like restocking, or fish farming -- water quality, feeding, disease, and harvesting.',
  lgu_admin: 'Ask AbaiMarket AI about pending approvals, seller verification, seller earnings, reports, or municipality statistics -- scoped to your own municipality.',
  super_admin: 'Ask AbaiMarket AI about platform-wide statistics, listings, payouts, reports, or municipality comparisons and trends.',
}

const AI_PLACEHOLDER_BY_ROLE = {
  buyer: 'Ask about buying, sellers, orders, or fish care...',
  seller: 'Ask about your listings, orders, wallet, sales, or fish care...',
  lgu_admin: 'Ask about approvals, sellers, earnings, or reports...',
  super_admin: 'Ask about platform stats, payouts, or municipalities...',
}

/**
 * Reply-language options for the AI Assistant, shared by every role. The
 * values must match AiAssistantController::LANGUAGES on the backend, which
 * validates them; '' means "Auto", the original behaviour of detecting the
 * language from the message itself (see App\Support\AiLanguageDetector).
 */
const AI_LANGUAGES = [
  ['', 'Auto-detect'],
  ['English', 'English'],
  ['Tagalog', 'Tagalog'],
  ['Bisaya', 'Bisaya'],
]

/**
 * Starter questions offered on an empty conversation, per role. A blank chat
 * box gives no clue what the assistant can actually answer, so users guessed --
 * and a guess that misses gets a refusal, which reads as "the AI is broken".
 * These are phrased as a real user would type them, and each role's list mixes
 * a live-data question with a how-does-this-work one; buyers and sellers also
 * get a fish-farming question, since that capability is the least discoverable.
 */
const AI_SUGGESTIONS_BY_ROLE = {
  buyer: ['How do I buy fingerlings?', 'Where is my order?', 'What species is good for a beginner?', 'Why are my fingerlings dying?'],
  seller: ['What is my available balance?', 'How do I withdraw my earnings?', 'How often should I feed my fingerlings?', 'My tilapia have white spots -- what do I do?'],
  lgu_admin: ['How many sellers are registered here?', 'What is awaiting my earnings approval?', 'How do I verify a seller?'],
  super_admin: ['How many sellers are on the platform?', 'What withdrawals are awaiting payout?', 'Which municipality has the most listings?'],
}

const AI_LANGUAGE_STORAGE_KEY = 'fishmarket_ai_language'

// Read once at module load rather than on every mount; the widget remounts on
// navigation and this is a plain string, not reactive state that others share.
let storedAiLanguage = ''
try {
  storedAiLanguage = localStorage.getItem(AI_LANGUAGE_STORAGE_KEY) || ''
} catch {
  storedAiLanguage = ''
}

export function FloatingAi() {
  const [open, setOpen] = useState(false)
  const [message, setMessage] = useState('')
  const [chat, setChat] = useState([])
  const [error, setError] = useState(null)
  const [language, setLanguage] = useState(storedAiLanguage)
  // Optional photo for the next question (muddy pond water, a sick fish...).
  const [photo, setPhotoState] = useState(null)
  const [photoPreview, setPhotoPreview] = useState(null)
  // The preview URL is made and released together with the photo itself.
  const setPhoto = (file) => {
    setPhotoPreview((previous) => {
      if (previous) URL.revokeObjectURL(previous)
      return file ? URL.createObjectURL(file) : null
    })
    setPhotoState(file)
  }
  const photoInputRef = useRef(null)
  const chatLogRef = useRef(null)
  // This widget also renders on the PUBLIC layout, where there is no session.
  // Both AI endpoints sit behind auth:sanctum, so a guest's question came back
  // 401 and surfaced as a generic "Something went wrong" -- the feature looked
  // broken rather than gated. Guests now see what it does and how to get it.
  const session = getSession()
  const isGuest = !session
  const role = session?.role || 'buyer'
  const aiGreeting = { role: 'ai', text: AI_GREETING_BY_ROLE[role] || AI_GREETING_BY_ROLE.buyer }

  const chooseLanguage = (value) => {
    setLanguage(value)
    storedAiLanguage = value
    try {
      if (value) localStorage.setItem(AI_LANGUAGE_STORAGE_KEY, value)
      else localStorage.removeItem(AI_LANGUAGE_STORAGE_KEY)
    } catch {
      // Private mode / blocked storage -- the choice still applies for this
      // session, it just won't be remembered after a reload.
    }
  }

  const history = useQuery({
    queryKey: ['ai-assistant-history'],
    queryFn: async () => (await api.get('/ai-assistant/history')).data,
    retry: false,
    refetchOnWindowFocus: false,
    // A guest has no history and no token -- asking for it is a guaranteed 401.
    enabled: !isGuest,
  })

  // Prior conversation history (from the server) is rendered directly from
  // the query result rather than copied into local state, so there is no
  // effect racing the query's async resolution -- `chat` only ever holds
  // messages sent during this mount.
  const historyMessages = (history.data || []).flatMap((entry) => [
    { role: 'user', text: entry.message, photo: entry.photo_url },
    { role: 'ai', text: entry.response },
  ])
  const displayChat = [aiGreeting, ...historyMessages, ...chat]

  const ask = useMutation({
    // language is omitted when set to Auto, so the backend keeps detecting it
    // from the message exactly as it always has.
    mutationFn: async ({ question, photo: file }) => {
      if (!file) return (await api.post('/ai-assistant/ask', { question, language: language || null })).data.response
      const body = new FormData()
      if (question) body.append('question', question)
      if (language) body.append('language', language)
      body.append('photo', file)
      return (await api.post('/ai-assistant/ask', body)).data.response
    },
  })

  const choosePhoto = (file) => {
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      setError({ message: 'Please choose a JPG, PNG or WEBP photo.' })
      return
    }
    if (file.size > 5 * 1024 * 1024) {
      setError({ message: 'That photo is over 5 MB. Please choose a smaller one.' })
      return
    }
    setError(null)
    setPhoto(file)
  }

  useEffect(() => {
    if (chatLogRef.current) chatLogRef.current.scrollTop = chatLogRef.current.scrollHeight
  }, [displayChat.length, ask.isPending])

  const sendQuestion = (question, file = null) => {
    setError(null)
    ask.mutate({ question, photo: file }, {
      onSuccess: (response) => setChat((current) => [...current, { role: 'ai', text: response }]),
      onError: (err) => setError({ message: aiErrorMessage(err), question, photo: file }),
    })
  }

  const submit = () => {
    const question = message.trim()
    if ((!question && !photo) || ask.isPending) return
    // Its own object URL, so clearing the picker does not blank the chat bubble.
    setChat((current) => [...current, { role: 'user', text: question, photo: photo ? URL.createObjectURL(photo) : null }])
    setMessage('')
    sendQuestion(question, photo)
    setPhoto(null)
  }

  const retry = () => {
    if ((!error?.question && !error?.photo) || ask.isPending) return
    sendQuestion(error.question, error.photo)
  }

  return (
    <div className="ai-widget">
      <button className="ai-toggle" onClick={() => setOpen(!open)} type="button"><Bot size={20} /> AI</button>
      {open && (
        <div className="ai-panel">
          <div className="ai-panel-head">
            <h3>AbaiMarket AI Assistant</h3>
            <select
              className="ai-language-picker"
              value={language}
              onChange={(e) => chooseLanguage(e.target.value)}
              aria-label="Reply language"
              title="Reply language"
            >
              {AI_LANGUAGES.map(([value, label]) => <option key={value || 'auto'} value={value}>{label}</option>)}
            </select>
          </div>
          <div className="chat-log" ref={chatLogRef}>
            {displayChat.map((m, i) => (
              <p className={m.role} key={`${m.role}-${i}`}>
                {m.photo && <img className="chat-photo" src={m.photo} alt="Photo sent to the AI assistant" />}
                {m.text}
              </p>
            ))}
            {ask.isPending && (
              <p className="ai ai-typing"><span className="typing-dots"><span /><span /><span /></span></p>
            )}
          </div>
          {isGuest && (
            <div className="ai-guest">
              <p>Log in to ask the assistant about listings, orders, your wallet, or fish farming.</p>
              <Link className="button full" to="/login">Log in</Link>
            </div>
          )}
          {/* Only on a genuinely empty conversation -- once there is anything
              to read, the suggestions stop being help and start being clutter. */}
          {!isGuest && !historyMessages.length && !chat.length && !ask.isPending && (
            <div className="ai-suggestions">
              {(AI_SUGGESTIONS_BY_ROLE[role] || AI_SUGGESTIONS_BY_ROLE.buyer).map((suggestion) => (
                <button
                  type="button"
                  className="ai-suggestion"
                  key={suggestion}
                  onClick={() => {
                    setChat((current) => [...current, { role: 'user', text: suggestion }])
                    sendQuestion(suggestion)
                  }}
                >
                  {suggestion}
                </button>
              ))}
            </div>
          )}
          {error && (
            <div className="ai-error">
              <p className="error">{error.message}</p>
              {(error.question || error.photo) && <button type="button" className="ghost" onClick={retry} disabled={ask.isPending}>Retry</button>}
            </div>
          )}
          {!isGuest && (
            <>
              <textarea
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault()
                    submit()
                  }
                }}
                placeholder={photo ? 'Ask about this photo, e.g. "Will my fingerlings survive in this water?"' : AI_PLACEHOLDER_BY_ROLE[role] || AI_PLACEHOLDER_BY_ROLE.buyer}
                disabled={ask.isPending}
              />
              {photoPreview && (
                <div className="ai-photo-preview">
                  <img src={photoPreview} alt="Photo to send" />
                  <span className="muted">{photo?.name}</span>
                  <button type="button" className="link-action" onClick={() => setPhoto(null)} disabled={ask.isPending} aria-label="Remove photo"><X size={16} /></button>
                </div>
              )}
              <div className="ai-send-row">
                <input
                  ref={photoInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  hidden
                  onChange={(e) => { choosePhoto(e.target.files?.[0] || null); e.target.value = '' }}
                />
                <button
                  type="button"
                  className="ghost ai-photo-button"
                  onClick={() => photoInputRef.current?.click()}
                  disabled={ask.isPending}
                  title="Add a photo (e.g. your pond water or fish)"
                  aria-label="Add a photo"
                >
                  <Camera size={18} />
                </button>
                <button className="ai-send-button" onClick={submit} type="button" disabled={ask.isPending || (!message.trim() && !photo)}>
                  {ask.isPending ? 'Thinking...' : 'Ask AbaiMarket AI'}
                </button>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  )
}