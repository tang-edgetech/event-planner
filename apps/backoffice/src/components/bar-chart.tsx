"use client";

import dayjs from "dayjs";

/** Simple daily bar chart (no chart library), with a value tooltip on each bar. */
export function BarChart({ data, height = 160, label = "registrations" }: { data: { date: string; count: number }[]; height?: number; label?: string }) {
  const max = Math.max(1, ...data.map((d) => d.count));
  const barW = 18;
  const gap = 6;
  const width = Math.max(320, data.length * (barW + gap));
  const chartH = height - 24;
  const every = Math.ceil(data.length / 10);
  return (
    <div className="ep-chart">
      <svg width={width} height={height} role="img" aria-label={`Daily ${label}`}>
        {data.map((d, i) => {
          const h = Math.round((d.count / max) * (chartH - 14));
          const x = i * (barW + gap);
          return (
            <g key={d.date}>
              <rect className="bar" x={x} y={chartH - h} width={barW} height={Math.max(h, d.count ? 2 : 0)} rx={3}>
                <title>{`${dayjs(d.date).format("D MMM")}: ${d.count} ${label}`}</title>
              </rect>
              {d.count > 0 && h > 14 ? (
                <text x={x + barW / 2} y={chartH - h - 3} textAnchor="middle">
                  {d.count}
                </text>
              ) : null}
              {i % every === 0 ? (
                <text x={x + barW / 2} y={height - 6} textAnchor="middle">
                  {dayjs(d.date).format("D/M")}
                </text>
              ) : null}
            </g>
          );
        })}
      </svg>
    </div>
  );
}

/** Horizontal bars for a ranked list (sources, statuses). */
export function RankBars({ rows }: { rows: { label: string; value: number }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  if (!rows.length) return <p className="ep-muted text-sm">No data in this period.</p>;
  return (
    <ul className="m-0 flex list-none flex-col gap-2 p-0">
      {rows.map((r) => (
        <li key={r.label}>
          <div className="flex justify-between gap-2 text-sm">
            <span className="truncate">{r.label}</span>
            <strong>{r.value}</strong>
          </div>
          <span className="ep-bar !w-full" aria-hidden="true">
            <span style={{ width: `${(r.value * 100) / max}%` }} />
          </span>
        </li>
      ))}
    </ul>
  );
}
