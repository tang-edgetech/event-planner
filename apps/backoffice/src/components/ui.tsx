"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Tag } from "antd";
import type { RegistrationState } from "@/lib/types";

/** Page title row: title, optional subtitle, actions on the right. */
export function PageHead({ title, sub, actions }: { title: React.ReactNode; sub?: React.ReactNode; actions?: React.ReactNode }) {
  return (
    <div className="ep-page-head">
      <div className="min-w-0">
        <h1 className="ep-page-head__title">{title}</h1>
        {sub ? <div className="ep-page-head__sub">{sub}</div> : null}
      </div>
      {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
    </div>
  );
}

/** Tabs where every tab is its own route (Back/Forward and refresh keep the tab). */
export function RouteTabs({ items }: { items: { href: string; label: string; hidden?: boolean }[] }) {
  const pathname = usePathname();
  return (
    <nav className="ep-tabs" aria-label="Sections">
      {items
        .filter((i) => !i.hidden)
        .map((i) => {
          const active = pathname === i.href || pathname.startsWith(`${i.href}/`);
          return (
            <Link key={i.href} href={i.href} className={`ep-tabs__item${active ? " is-active" : ""}`} aria-current={active ? "page" : undefined}>
              {i.label}
            </Link>
          );
        })}
    </nav>
  );
}

const REG_COLORS: Record<string, string> = { open: "green", waitlist: "gold", full: "red", closed: "default", not_open: "blue" };
export function RegistrationTag({ reg }: { reg: RegistrationState }) {
  return <Tag color={REG_COLORS[reg.state]}>{reg.state === "open" && reg.remaining !== null && reg.remaining <= 20 ? `${reg.remaining} seats left` : reg.label}</Tag>;
}

const APPLICANT_COLORS: Record<string, string> = { registered: "blue", confirmed: "green", waitlist: "gold", attended: "purple", cancelled: "default" };
export function ApplicantStatusTag({ status, label }: { status: string; label?: string }) {
  return <Tag color={APPLICANT_COLORS[status] ?? "default"}>{label ?? status}</Tag>;
}

export function PublishTag({ status }: { status: string }) {
  return status === "published" ? <Tag color="green">Published</Tag> : <Tag>Draft</Tag>;
}

export function SeatBar({ count, max }: { count: number; max: number | null }) {
  if (!max) return <span>{count}</span>;
  const pct = Math.min(100, Math.round((count * 100) / max));
  return (
    <span>
      {count} / {max}
      <span className="ep-bar" aria-hidden="true">
        <span style={{ width: `${pct}%` }} />
      </span>
    </span>
  );
}

/** Same wording as the public site's 404, so nothing here hints that a backoffice exists. */
export function NotFoundView() {
  return (
    <main className="ep-notice">
      <h1 className="ep-notice__title">Page not found</h1>
      <p className="ep-notice__text">The page you are looking for does not exist or is no longer available.</p>
    </main>
  );
}

export function Kpi({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="ep-kpi">
      <div className="ep-kpi__label">{label}</div>
      <div className="ep-kpi__value">{value}</div>
    </div>
  );
}

/** Table sorter → ?sort=&order= for the API (no order = the list's usual order). */
export function sortParams(sorter: unknown): { sort?: string; order?: "asc" | "desc" } {
  const s = (Array.isArray(sorter) ? sorter[0] : sorter) as { order?: "ascend" | "descend" | null; columnKey?: React.Key } | undefined;
  return s?.order ? { sort: String(s.columnKey), order: s.order === "ascend" ? "asc" : "desc" } : { sort: undefined, order: undefined };
}

/** Bar above a list: how many rows are ticked, a way to clear them, and the actions that work on them. */
export function BulkBar({ count, onClear, hint, children }: { count: number; onClear: () => void; hint: string; children?: React.ReactNode }) {
  return (
    <div className={`ep-bulkbar${count ? " is-active" : ""}`} role="region" aria-label="Selected rows">
      {count ? (
        <span className="text-sm">
          <strong>{count}</strong> selected ·{" "}
          <button type="button" className="ep-linkbtn" onClick={onClear}>
            Clear
          </button>
        </span>
      ) : (
        <span className="ep-muted text-sm">{hint}</span>
      )}
      <div className="flex flex-wrap gap-2">{children}</div>
    </div>
  );
}
