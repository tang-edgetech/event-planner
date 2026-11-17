"use client";

import { DatePicker, Select } from "antd";
import { useQuery } from "@tanstack/react-query";
import dayjs, { type Dayjs } from "dayjs";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { api } from "@/lib/api";

/** Date range (default last 30 days) and event filter, kept in the address so tabs and reloads keep them. */
export function useReportParams() {
  const sp = useSearchParams();
  return {
    from: sp.get("from") ?? dayjs().subtract(29, "day").format("YYYY-MM-DD"),
    to: sp.get("to") ?? dayjs().format("YYYY-MM-DD"),
    event_id: sp.get("event_id") ? Number(sp.get("event_id")) : undefined,
  };
}

export function ReportFilters({ withEvent = true }: { withEvent?: boolean }) {
  const router = useRouter();
  const pathname = usePathname();
  const sp = useSearchParams();
  const p = useReportParams();
  const { data: events } = useQuery({ queryKey: ["event-options"], queryFn: async () => (await api.get<{ data: { value: number; label: string }[] }>("/events/options")).data.data, staleTime: 60_000 });

  const push = (patch: Record<string, string | undefined>) => {
    const next = new URLSearchParams(sp.toString());
    Object.entries(patch).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
    router.replace(`${pathname}?${next.toString()}`);
  };

  return (
    <div className="ep-filters">
      <DatePicker.RangePicker
        allowClear={false}
        value={[dayjs(p.from), dayjs(p.to)]}
        presets={[
          { label: "Last 7 days", value: [dayjs().subtract(6, "day"), dayjs()] },
          { label: "Last 30 days", value: [dayjs().subtract(29, "day"), dayjs()] },
          { label: "This month", value: [dayjs().startOf("month"), dayjs()] },
          { label: "Last 90 days", value: [dayjs().subtract(89, "day"), dayjs()] },
        ]}
        onChange={(r) => {
          const range = r as [Dayjs, Dayjs] | null;
          if (range) push({ from: range[0].format("YYYY-MM-DD"), to: range[1].format("YYYY-MM-DD") });
        }}
      />
      {withEvent ? <Select allowClear showSearch={{ optionFilterProp: "label" }} placeholder="All events" style={{ width: 260 }} value={p.event_id} options={events} onChange={(v) => push({ event_id: v ? String(v) : undefined })} /> : null}
    </div>
  );
}
