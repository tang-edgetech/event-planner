"use client";

import Link from "next/link";
import { Progress, Table } from "antd";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { fmtDate } from "@/lib/format";
import { PublishTag } from "@/components/ui";
import { ReportFilters, useReportParams } from "../report-filters";

type Row = { id: number; title: string; status: string; starts_at: string | null; capacity: number | null; registrations: number; seated: number; waitlist: number; attended: number; cancelled: number; fill_rate: number | null; attendance_rate: number | null; views: number };

export default function ReportEvents() {
  const p = useReportParams();
  const { data, isFetching } = useQuery({ queryKey: ["report-events", p.from, p.to], queryFn: async () => (await api.get<{ data: Row[] }>("/reports/events", { params: { from: p.from, to: p.to } })).data });
  return (
    <>
      <ReportFilters withEvent={false} />
      <p className="ep-muted -mt-2 mb-3 text-sm">“New in period” counts registrations within the dates; seats, waiting list and attendance are current totals.</p>
      <Table<Row>
        rowKey="id"
        loading={isFetching}
        dataSource={data?.data}
        scroll={{ x: 1000 }}
        pagination={{ pageSize: 25 }}
        columns={[
          { title: "Event", render: (_, r) => <Link href={`/events/${r.id}/details`}>{r.title}</Link> },
          { title: "Date", width: 120, render: (_, r) => fmtDate(r.starts_at) },
          { title: "Status", width: 100, render: (_, r) => <PublishTag status={r.status} /> },
          { title: "Page views", dataIndex: "views", width: 100, sorter: (a, b) => a.views - b.views },
          { title: "New in period", dataIndex: "registrations", width: 120, sorter: (a, b) => a.registrations - b.registrations },
          { title: "Seats", width: 160, render: (_, r) => (r.capacity ? <Progress percent={r.fill_rate ?? 0} size="small" format={() => `${r.seated}/${r.capacity}`} /> : r.seated) },
          { title: "Waiting", dataIndex: "waitlist", width: 90 },
          { title: "Attended", width: 120, render: (_, r) => `${r.attended}${r.attendance_rate !== null ? ` (${r.attendance_rate}%)` : ""}` },
          { title: "Cancelled", dataIndex: "cancelled", width: 100 },
        ]}
      />
    </>
  );
}
