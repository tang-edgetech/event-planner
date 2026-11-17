"use client";

import { App, Button, DatePicker, Descriptions, Dropdown, Input, Select, Table, Tag, Typography } from "antd";
import { CheckCircleFilled, CloseCircleFilled, DownloadOutlined } from "@ant-design/icons";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import type { Dayjs } from "dayjs";
import { useState } from "react";
import { api, download, errorMessage } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { useCan } from "@/lib/session";
import type { AuditRow, Paged } from "@/lib/types";
import { NotFoundView, PageHead } from "@/components/ui";

type Filters = { q?: string; status?: string; category?: string[]; user_id?: number; from?: string; to?: string; page: number; per_page: number };
type Response = Paged<AuditRow> & { filters: { categories: { value: string; label: string }[]; users: { value: number; label: string }[] } };

/**
 * Audit logs: every activity in the system, successes and errors.
 * Expand a row for the plain-language explanation; select rows to export just those.
 */
export default function AuditLogsPage() {
  const can = useCan();
  const { message } = App.useApp();
  const [f, setF] = useState<Filters>({ page: 1, per_page: 25 });
  const [selected, setSelected] = useState<number[]>([]);
  const [exporting, setExporting] = useState(false);
  const { data, isFetching } = useQuery({
    queryKey: ["audit", f],
    queryFn: async () => (await api.get<Response>("/audit-logs", { params: f })).data,
    placeholderData: keepPreviousData,
    enabled: can("audit.view"),
  });

  if (!can("audit.view")) return <NotFoundView />;

  const set = (patch: Partial<Filters>) => {
    setF((p) => ({ ...p, ...patch, page: patch.page ?? 1 }));
    if (!("page" in patch)) setSelected([]);
  };

  const exportAs = async (format: "csv" | "json", onlySelected: boolean) => {
    setExporting(true);
    try {
      const { page: _p, per_page: _pp, ...filters } = f;
      void _p;
      void _pp;
      await download("/audit-logs/export", { ...filters, format, ids: onlySelected ? selected : undefined }, `audit-logs.${format}`);
    } catch (e) {
      message.error(errorMessage(e, "Export failed."));
    } finally {
      setExporting(false);
    }
  };

  const exportMenu = (format: "csv" | "json") => ({
    items: [
      { key: "sel", label: `Selected rows (${selected.length})`, disabled: !selected.length, onClick: () => exportAs(format, true) },
      { key: "all", label: `All matching the filters (${data?.meta.total ?? 0})`, onClick: () => exportAs(format, false) },
    ],
  });

  return (
    <>
      <PageHead
        title="Audit logs"
        sub="Everything that happens in the system: sign-ins, changes, emails, registrations and errors."
        actions={
          can("audit.export") ? (
            <>
              <Dropdown menu={exportMenu("csv")} trigger={["click"]}>
                <Button icon={<DownloadOutlined />} loading={exporting}>
                  Export CSV
                </Button>
              </Dropdown>
              <Dropdown menu={exportMenu("json")} trigger={["click"]}>
                <Button icon={<DownloadOutlined />} loading={exporting}>
                  Export JSON
                </Button>
              </Dropdown>
            </>
          ) : null
        }
      />
      <div className="ep-filters">
        <Input.Search allowClear placeholder="Search what happened, who, item or IP" onSearch={(q) => set({ q })} style={{ width: 300 }} />
        <Select
          allowClear
          placeholder="Success and errors"
          style={{ width: 170 }}
          options={[
            { value: "success", label: "Success only" },
            { value: "error", label: "Errors only" },
          ]}
          onChange={(status) => set({ status })}
        />
        <Select mode="multiple" allowClear maxTagCount="responsive" placeholder="All areas" style={{ width: 240 }} options={data?.filters.categories} onChange={(category) => set({ category })} />
        <Select allowClear showSearch={{ optionFilterProp: "label" }} placeholder="Anyone" style={{ width: 180 }} options={data?.filters.users} onChange={(user_id) => set({ user_id })} />
        <DatePicker.RangePicker
          onChange={(r) => {
            const range = r as [Dayjs | null, Dayjs | null] | null;
            set({ from: range?.[0]?.format("YYYY-MM-DD"), to: range?.[1]?.format("YYYY-MM-DD") });
          }}
        />
      </div>
      {selected.length ? (
        <div className="mb-2 text-sm">
          <strong>{selected.length}</strong> selected ·{" "}
          <Button type="link" size="small" className="!px-0" onClick={() => setSelected([])}>
            Clear selection
          </Button>
        </div>
      ) : null}
      <Table<AuditRow>
        rowKey="id"
        size="middle"
        loading={isFetching}
        dataSource={data?.data}
        scroll={{ x: 900 }}
        rowSelection={can("audit.export") ? { selectedRowKeys: selected, onChange: (keys) => setSelected(keys as number[]), preserveSelectedRowKeys: true } : undefined}
        pagination={{ current: f.page, pageSize: f.per_page, total: data?.meta.total, showSizeChanger: true, pageSizeOptions: [25, 50, 100, 200], onChange: (page, per_page) => set({ page, per_page }) }}
        expandable={{
          expandRowByClick: true,
          expandedRowRender: (r) => (
            <div className="max-w-4xl py-1">
              <Typography.Paragraph className="ep-desc !mb-3">{r.description || r.summary}</Typography.Paragraph>
              <Descriptions size="small" column={{ xs: 1, md: 2 }} bordered>
                <Descriptions.Item label="When">{fmtDateTime(r.created_at)}</Descriptions.Item>
                <Descriptions.Item label="Result">{r.status === "success" ? "Succeeded" : "Failed"}</Descriptions.Item>
                <Descriptions.Item label="Area">{r.category_label}</Descriptions.Item>
                <Descriptions.Item label="Action code">{r.action}</Descriptions.Item>
                <Descriptions.Item label="Done by">{r.user_name}</Descriptions.Item>
                <Descriptions.Item label="Item">{r.subject_label ?? "—"}</Descriptions.Item>
                <Descriptions.Item label="IP address">{r.ip_address ?? "—"}</Descriptions.Item>
                <Descriptions.Item label="Browser">{r.device ?? "—"}</Descriptions.Item>
              </Descriptions>
              {r.properties ? (
                <details className="mt-3">
                  <summary className="ep-muted cursor-pointer text-xs">Technical details</summary>
                  <pre className="mt-2 max-h-64 overflow-auto rounded bg-black/5 p-3 text-xs">{JSON.stringify(r.properties, null, 2)}</pre>
                </details>
              ) : null}
            </div>
          ),
        }}
        columns={[
          {
            title: "",
            width: 36,
            render: (_, r) => (r.status === "success" ? <CheckCircleFilled style={{ color: "#1E6B35" }} aria-label="Success" /> : <CloseCircleFilled style={{ color: "#B42335" }} aria-label="Error" />),
          },
          { title: "When", width: 180, render: (_, r) => <span className="whitespace-nowrap">{fmtDateTime(r.created_at)}</span> },
          { title: "What happened", render: (_, r) => <span className={r.status === "error" ? "font-medium" : undefined}>{r.summary}</span> },
          { title: "Area", width: 160, render: (_, r) => <Tag>{r.category_label}</Tag> },
          { title: "By", width: 160, dataIndex: "user_name", ellipsis: true },
        ]}
      />
    </>
  );
}
