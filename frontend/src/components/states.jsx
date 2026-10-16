

export function EmptyState({ title, message, icon: Icon }) {
  return (
    <div className="empty-state">
      {Icon && <span className="empty-state-icon"><Icon size={24} /></span>}
      {title && <strong>{title}</strong>}
      {message && <p>{message}</p>}
    </div>
  )
}

export function LoadingState({ label = 'Loading...' }) {
  return (
    <div className="loading-state">
      <span className="spinner" />
      <span>{label}</span>
    </div>
  )
}