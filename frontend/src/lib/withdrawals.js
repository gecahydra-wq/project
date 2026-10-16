import { OTHER_BANK } from './constants.js'



export function withdrawalMethodLabel(method, bankName = null) {
  const label = ({ gcash: 'GCash', maya: 'Maya', bank_transfer: 'Bank Transfer' })[method] || method
  return method === 'bank_transfer' && bankName ? `${label} (${bankName})` : label
}

/** The bank actually sent to the server: the picked one, or the typed name for "Other". */
export function selectedBankName(form) {
  if (form.method !== 'bank_transfer') return null
  return (form.bank_choice === OTHER_BANK ? form.bank_other : form.bank_choice)?.trim() || null
}