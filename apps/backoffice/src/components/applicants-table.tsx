"use client";

import Link from "next/link";
import { App, Button, DatePicker, Descriptions, Drawer, Dropdown, Input, Select, Skeleton, Table, Tooltip } from "antd";
import { DeleteOutlined, DownloadOutlined, EyeOutlined, SwapOutlined } from "@ant-design/icons";
import { keepPreviousData, useQuery, useQueryClient } from "@tanstack/react-query";
import type { Dayjs } from "dayjs";
import { useState } from "react";
import { api, download, errorMessage } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { useCan } from "@/lib/session";
import type { ApplicantRow, Paged, RegistrationState } from "@/lib/types";
import { ApplicantStatusTag, BulkBar, Kpi, RegistrationTag, sortParams } from "./ui";

type Resp = Paged<ApplicantRow> & {
  statuses: { value: string; label: string }[];
  event: null | { id: number; title: string; registration: RegistrationState; counts: Record<string, number>; columns: { name: string; label: string }[] };
};
type Filters = { q?: string; event_id?: number; status?: string[]; from?: string; to?: string; sort?: string; order?: string; page: number; per_page: number };

/** Applicants with search, filters, date range, sorting, bulk status/delete and CSV/JSON export (selected or all matching). */
export function ApplicantsTable({ eventId }: { eventId?: number }) {
  const can = useCan();
  const qc = useQueryClient();
  const { message, modal } = App.useApp();
  const [f, setF] = useState<Filters>({ page: 1, per_page: 25, event_id: eventId });
  const [selected, setSelected] = useState<number[]>([]);
  const [viewing, setViewing] = useState<number | null>(null);
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
  const refresh = () => {
    setSelected([]);
    qc.invalidateQueries({ queryKey: ["applicants"] });
    qc.invalidateQueries({ queryKey: ["event"] });
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
          refresh();
        } catch (e) {
          message.error(errorMessage(e));
        }
      },
    });

  const bulkDelete = () =>
    modal.confirm({
      title: `Delete ${selected.length} applicant(s)?`,
      content: "Their registrations and answers are removed and the seats they held become free. This is recorded in the audit log.",
      okText: "Delete",
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          const res = await api.post("/applicants/bulk-delete", { ids: selected });
          message.success(`${res.data.changed} applicant(s) deleted`);
          refresh();
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
      <BulkBar count={selected.length} onClear={() => setSelected([])} hint="Tick rows to change their status, delete or export just those.">
        {can("applicants.manage") ? (
          <>
            <Dropdown disabled={!selected.length} trigger={["click"]} menu={{ items: (data?.statuses ?? []).map((s) => ({ key: s.value, label: s.label, onClick: () => bulk(s.value, s.label) })) }}>
              <Button icon={<SwapOutlined />}>Change status</Button>
            </Dropdown>
            <Button danger icon={<DeleteOutlined />} disabled={!selected.length} onClick={bulkDelete}>
              Delete
            </Button>
          </>
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
      </BulkBar>
      <Table<ApplicantRow>
        rowKey="id"
        loading={isFetching}
        dataSource={data?.data}
        tableLayout="fixed"
        scroll={{ x: 960 }}
        rowSelection={can("applicants.manage") || can("applicants.export") ? { selectedRowKeys: selected, onChange: (k) => setSelected(k as number[]), preserveSelectedRowKeys: true } : undefined}
        onChange={(_, __, sorter, extra) => {
          if (extra.action === "sort") set(sortParams(sorter));
        }}
        pagination={{ current: f.page, pageSize: f.per_page, total: data?.meta.total, showSizeChanger: true, pageSizeOptions: [25, 50, 100, 200], showTotal: (t) => `${t} applicant(s)`, onChange: (page, per_page) => set({ page, per_page }) }}
        columns={[
          {
            title: "Name",
            key: "name",
            sorter: true,
            width: 240,
            render: (_, a) => (
              <div className="min-w-0">
                <Link href={`/applicants/${a.id}`} className="block truncate font-semibold">
                  {a.name || "—"}
                </Link>
                {!eventId ? <div className="ep-muted truncate text-xs">{a.event_title}</div> : null}
              </div>
            ),
          },
          { title: "Email", key: "email", dataIndex: "email", sorter: true, ellipsis: { showTitle: true }, width: 240 },
          { title: "Status", key: "status", sorter: true, width: 130, render: (_, a) => <ApplicantStatusTag status={a.status} label={a.status_label} /> },
          { title: "Source", dataIndex: "source", width: 130, ellipsis: { showTitle: false }, render: (s: string) => <Tooltip title={s}>{s}</Tooltip> },
          { title: "Registered", key: "created_at", sorter: true, defaultSortOrder: "descend", width: 170, render: (_, a) => fmtDateTime(a.created_at) },
          {
            title: "",
            key: "view",
            width: 90,
            fixed: "right",
            render: (_, a) => (
              <Button size="small" icon={<EyeOutlined />} onClick={() => setViewing(a.id)} aria-label={`View ${a.name || "applicant"}`}>
                View
              </Button>
            ),
          },
        ]}
      />
      <ApplicantDrawer id={viewing} onClose={() => setViewing(null)} />
    </>
  );
}

type Detail = {
  id: number;
  event_title: string;
  status: string;
  status_label: string;
  name: string;
  email: string | null;
  phone: string | null;
  created_at: string;
  fields: { name: string; label: string }[];
  data: Record<string, unknown>;
  source: Record<string, string> | null;
  notes: string | null;
};

const show = (v: unknown) => (Array.isArray(v) ? v.join(", ") : v === null || v === undefined || v === "" ? "—" : String(v));

/** Every answer of one applicant, without leaving the list. Editing happens on the full record. */
function ApplicantDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data: a, isLoading } = useQuery({
    queryKey: ["applicant", String(id)],
    queryFn: async () => (await api.get<{ data: Detail }>(`/applicants/${id}`)).data.data,
    enabled: id !== null,
  });
  const source = a?.source ? Object.entries(a.source).filter(([, v]) => v) : [];
  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      size="large"
      title={a?.name || "Applicant"}
      extra={
        id !== null ? (
          <Link href={`/applicants/${id}`}>
            <Button type="primary">Open full record</Button>
          </Link>
        ) : null
      }
    >
      {isLoading || !a ? (
        <Skeleton active />
      ) : (
        <>
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Event">{a.event_title}</Descriptions.Item>
            <Descriptions.Item label="Status">
              <ApplicantStatusTag status={a.status} label={a.status_label} />
            </Descriptions.Item>
            <Descriptions.Item label="Registered">{fmtDateTime(a.created_at)}</Descriptions.Item>
          </Descriptions>
          <h3 className="mb-2 mt-5 text-sm font-semibold">Answers</h3>
          <Descriptions size="small" column={1} bordered>
            {a.fields.map((fd) => (
              <Descriptions.Item key={fd.name} label={fd.label}>
                <span className="break-words">{show(a.data?.[fd.name])}</span>
              </Descriptions.Item>
            ))}
          </Descriptions>
          {source.length ? (
            <>
              <h3 className="mb-2 mt-5 text-sm font-semibold">Where they came from</h3>
              <Descriptions size="small" column={1} bordered>
                {source.map(([k, v]) => (
                  <Descriptions.Item key={k} label={k.replace(/_/g, " ")}>
                    {v}
                  </Descriptions.Item>
                ))}
              </Descriptions>
            </>
          ) : null}
          {a.notes ? (
            <>
              <h3 className="mb-2 mt-5 text-sm font-semibold">Notes</h3>
              <p className="whitespace-pre-wrap text-sm">{a.notes}</p>
            </>
          ) : null}
        </>
      )}
    </Drawer>
  );
}
