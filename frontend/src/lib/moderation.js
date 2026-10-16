

export const BUYER_SUSPENSION_REASONS = [
  'Fraudulent Orders',
  'Fake Payments',
  'Chargeback Abuse',
  'Harassment',
  'Spam',
  'Multiple Fake Accounts',
  'Marketplace Policy Violation',
  'Other',
]

/**
 * Enumerated grounds for permanently removing an account -- mirrors
 * SuperAdminController::BUYER_REMOVAL_REASONS / SELLER_REMOVAL_REASONS, which
 * validate the same list server-side.
 */
export const BUYER_REMOVAL_REASONS = [
  'Spam Account',
  'Fake or Duplicate Account',
  'Fraudulent Registration',
  'Marketplace Policy Violation',
  'Requested by Account Owner',
  'Other',
]

export const SELLER_REMOVAL_REASONS = [
  'Spam Account',
  'Fake or Duplicate Account',
  'Fraudulent Registration',
  'Fake Hatchery Details',
  'Marketplace Policy Violation',
  'Requested by Account Owner',
  'Other',
]