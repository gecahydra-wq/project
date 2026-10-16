

/** Who wrote a staff entry, as the ticket's owner sees it. */
export function supportAuthorLabel(message) {
  return message.author_role === 'lgu_admin' ? `${message.author?.name || 'Your LGU'} (LGU)` : 'AbaiMarket Support'
}