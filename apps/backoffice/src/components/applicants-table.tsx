"use client";

import Link from "next/link";
import { App, Button, DatePicker, Dropdown, Input, Select, Space, Table, Tooltip } from "antd";
import { DownloadOutlined, SwapOutlined } from "@ant-design/icons";
import { keepPreviousData, useQuery, useQueryClient } from "@tanstack/react-query";
import type { Dayjs } from "dayjs";
import { useMemo, useState } from "react";
import { api, download, errorMessage } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { useCan } from "@/lib/session";
import type { ApplicantRow, Paged, RegistrationState } from "@/lib/types";
import { ApplicantStatusTag, Kpi, RegistrationTag } from "./ui";

type Resp = Paged<ApplicantRow> & {
  statuses: { value: string; label: string }[];
  event: null | { id: number; title: string; registration: RegistrationState; counts: Record<string, number>; columns: { name: string; label: string }[] };
};
type Filters = { q?: string; event_id?: number; status?: string[]; from?: string; to?: string; sort?: string; order?: string; page: number; per_page: number };

/** Applicants with search, filters, date range, bulk status change and CSV/JSON export (selected or all matching). */
export function ApplicantsTable({ eventId }: { eventId?: number }) {
  const can = useCan();
  const qc = useQueryClient();
  const { message, modal } = App.useApp();
  const [f, setF] = useState<Filters>({ page: 1, per_page: 25, event_id: eventId });
  const [selected, setSelected] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);
  const { data, isFetching } = useQuery({ queryKey: ["applicants", f], queryFn: async () => (await api.get<Resp>("/applicants", { params: f })).data, placeholderData: keepPreviousData });
  const { data: events } = useQuery({
    queryKey: ["event-options"],
    queryFn: async () => (await api.get<{ data: { value: number; label: string }[] }>("/events/options")).data.data,
    enabled: !eventId,
    staleTime: 60_000,
  });

  const set = (patch: Partial<Filters>) => {
    setF((p) => ({ ...p, ...patch, page: patch.page ?? 1 }));
    if (!("page" in patch)) setSelected([]);
  };

  const bulk = (status: string, label: string) =>
    modal.confirm({
      title: `Set ${selected.length} applicant(s) to ${label}?`,
      content: "Emails are not sent for bulk changes, except seat offers when the waiting list moves up automatically.",
      okText: `Set to ${label}`,
      onOk: async () => {
        try {
          const res = await api.post("/applicants/bulk-status", { ids: selected, status });
          message.success(`${res.data.changed} applicant(s) updated`);
          setSelected([]);
          qc.invalidateQueries({ queryKey: ["applicants"] });
          qc.invalidateQueries({ queryKey: ["event"] });
        } catch (e) {
          message.error(errorMessage(e));
        }
      },
    });

  const exportAs = async (format: "csv" | "json", onlySelected: boolean) => {
    setBusy(true);
    try {
      const { page: _a, per_page: _b, ...filters } = f;
      void _a;
      void _b;
      await download("/applicants/export", { ...filters, format, ids: onlySelected ? selected : undefined }, `applicants.${format}`);
    } catch (e) {
      message.error(errorMessage(e, "Export failed."));
    } finally {
      setBusy(false);
    }
  };
  const exportMenu = (format: "csv" | "json") => ({
    items: [
      { key: "s", label: `Selected (${selected.length})`, disabled: !selected.length, onClick: () => exportAs(format, true) },
      { key: "a", label: `All matching the filters (${data?.meta.total ?? 0})`, onClick: () => exportAs(format, false) },
    ],
  });

  const answerColumns = useMemo(
    () =>
      (data?.event?.columns ?? [])
        .filter((c) => !["full_name", "name", "email", "phone", "salutation"].includes(c.name))
        .slice(0, 4)
        .map((c) => ({
          title: c.label,
          key: c.name,
          width: 160,
          ellipsis: true,
          render: (_: unknown, a: ApplicantRow) => {
            const v = a.answers?.[c.name];
            return Array.isArray(v) ? v.join(", ") : String(v ?? "");
          },
        })),
    [data?.event?.columns],
  );

  const ev = data?.event;
  return (
    <>
      {ev ? (
        <div className="ep-kpis !grid-cols-2 md:!grid-cols-3 xl:!grid-cols-6">
          <Kpi label="Seats taken" value={`${ev.registration.count}${ev.registration.max ? ` / ${ev.registration.max}` : ""}`} />
          {data?.statuses.map((s) => (
            <Kpi key={s.value} label={s.label} value={ev.counts[s.value] ?? 0} />
          ))}
        </div>
      ) : null}
      <div className="ep-filters">
        <Input.Search allowClear placeholder="Search name, email, phone or any answer" onSearch={(q) => set({ q })} style={{ width: 300 }} />
        {!eventId ? <Select allowClear showSearch={{ optionFilterProp: "label" }} placeholder="All events" style={{ width: 240 }} options={events} onChange={(event_id) => set({ event_id })} /> : null}
        <Select mode="multiple" allowClear maxTagCount="responsive" placeholder="All statuses" style={{ width: 220 }} options={data?.statuses} onChange={(status) => set({ status })} />
        <DatePicker.RangePicker
          placeholder={["Registered from", "to"]}
          onChange={(r) => {
            const range = r as [Dayjs | null, Dayjs | null] | null;
            set({ from: range?.[0]?.format("YYYY-MM-DD"), to: range?.[1]?.format("YYYY-MM-DD") });
          }}
        />
        <div className="flex-1" />
        {ev ? <RegistrationTag reg={ev.registration} /> : null}
      </div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        {selected.length ? (
          <span className="text-sm">
            <strong>{selected.length}</strong> selected
          </span>
        ) : (
          <span className="ep-muted text-sm">Tick rows to change their status or export just those.</span>
        )}
        <Space wrap>
          {can("applicants.manage") ? (
            <Dropdown disabled={!selected.length} trigger={["click"]} menu={{ items: (data?.statuses ?? []).map((s) => ({ key: s.value, label: s.label, onClick: () => bulk(s.value, s.label) })) }}>
              <Button icon={<SwapOutlined />}>Change status</Button>
            </Dropdown>
          ) : null}
          {can("applicants.export") ? (
            <>
              <Dropdown menu={exportMenu("csv")} trigger={["click"]}>
                <Button icon={<DownloadOutlined />} loading={busy}>
                  Export CSV
                </Button>
              </Dropdown>
              <Dropdown menu={exportMenu("json")} trigger={["click"]}>
                <Button icon={<DownloadOutlined />} loading={busy}>
                  Export JSON
                </Button>
              </Dropdown>
            </>
          ) : null}
        </Space>
      </div>
      <Table<ApplicantRow>
        rowKey="id"
        loading={isFetching}
        dataSource={data?.data}
        scroll={{ x: 900 + answerColumns.length * 160 }}
        rowSelection={can("applicants.manage") || can("applicants.export") ? { selectedRowKeys: selected, onChange: (k) => setSelected(k as number[]), preserveSelectedRowKeys: true } : undefined}
        onChange={(_, __, sorter) => {
          const s = Array.isArray(sorter) ? sorter[0] : sorter;
          set({ sort: s?.order ? String(s.columnKey) : undefined, order: s?.order === "ascend" ? "asc" : "desc" });
        }}
        pagination={{ current: f.page, pageSize: f.per_page, total: data?.meta.total, showSizeChanger: true, pageSizeOptions: [25, 50, 100, 200], onChange: (page, per_page) => set({ page, per_page }) }}
        columns={[
          {
            title: "Name",
            key: "name",
            sorter: true,
            render: (_, a) => (
              <div className="min-w-[180px]">
                <Link href={`/applicants/${a.id}`} className="font-semibold">
                  {a.name || "—"}
                </Link>
                {!eventId ? <div className="ep-muted text-xs">{a.event_title}</div> : null}
              </div>
            ),
          },
          { title: "Email", key: "email", dataIndex: "email", sorter: true, ellipsis: true, width: 220 },
          { title: "Phone", dataIndex: "phone", width: 150 },
          ...answerColumns,
          { title: "Status", key: "status", sorter: true, width: 130, render: (_, a) => <ApplicantStatusTag status={a.status} label={a.status_label} /> },
          { title: "Source", dataIndex: "source", width: 140, ellipsis: true, render: (s: string) => <Tooltip title={s}>{s}</Tooltip> },
          { title: "Registered", key: "created_at", sorter: true, defaultSortOrder: "descend", width: 180, render: (_, a) => fmtDateTime(a.created_at) },
        ]}
      />
    </>
  );
}
