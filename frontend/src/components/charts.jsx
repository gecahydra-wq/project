import { Bar, BarChart, CartesianGrid, Cell, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { EmptyState } from './states.jsx'



function ChartCard({ title, empty, children }) {
  return (
    <div className="card chart-card">
      <h3>{title}</h3>
      {empty ? <EmptyState message="No data for this period yet." /> : <div className="chart-body">{children}</div>}
    </div>
  )
}

export function TimeSeriesChart({ title, data, dataKey, color, valueFormatter }) {
  const empty = !data?.length || data.every((point) => Number(point[dataKey]) === 0)
  return (
    <ChartCard title={title} empty={empty}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
          <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
          <XAxis dataKey="label" tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={{ stroke: 'var(--chart-grid)' }} tickLine={false} />
          <YAxis tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={false} tickLine={false} width={48} />
          <Tooltip formatter={(value) => (valueFormatter ? valueFormatter(value) : value)} contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 'var(--radius-sm)' }} />
          <Line type="monotone" dataKey={dataKey} stroke={color} strokeWidth={2} dot={{ r: 3, fill: color }} activeDot={{ r: 5 }} />
        </LineChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}

export function CategoryBarChart({ title, data, dataKey, nameKey, colorFor, valueFormatter }) {
  const rows = data ?? []
  const empty = !rows.length
  return (
    <ChartCard title={title} empty={empty}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
          <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
          <XAxis dataKey={nameKey} tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={{ stroke: 'var(--chart-grid)' }} tickLine={false} />
          <YAxis tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} axisLine={false} tickLine={false} width={48} />
          <Tooltip formatter={(value) => (valueFormatter ? valueFormatter(value) : value)} contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 'var(--radius-sm)' }} />
          <Bar dataKey={dataKey} radius={[4, 4, 0, 0]}>
            {rows.map((entry) => <Cell key={entry[nameKey]} fill={colorFor(entry)} />)}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}