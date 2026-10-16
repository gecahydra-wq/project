import { STATUS_LABELS, badgeTone } from './status.js'



const SPECIES_CHART_COLORS = {
  Bangus: 'var(--color-primary)',
  Tilapia: 'var(--color-teal)',
  Tuna: 'var(--chart-violet)',
  Catfish: 'var(--chart-gold)',
  'Sea Bass': 'var(--chart-magenta)',
  Carp: 'var(--chart-green)',
}

export function speciesChartColor(species) {
  return SPECIES_CHART_COLORS[species] || 'var(--color-neutral-text)'
}

const STATUS_CHART_COLORS = {
  success: 'var(--color-success)',
  info: 'var(--color-info)',
  warning: 'var(--color-warning)',
  danger: 'var(--color-danger)',
  neutral: 'var(--color-neutral-text)',
}

export function statusChartColor(status) {
  return STATUS_CHART_COLORS[badgeTone(status)]
}

export function statusChartLabel(status) {
  const key = String(status || '').toLowerCase()
  if (STATUS_LABELS[key]) return STATUS_LABELS[key]
  return String(status || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}