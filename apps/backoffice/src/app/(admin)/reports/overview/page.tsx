"use client";

import { Skeleton } from "antd";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { BarChart, RankBars } from "@/components/bar-chart";
import { Kpi } from "@/components/ui";
import { ReportFilters, useReportParams } from "../report-filters";

type Overview = {
  totals: { registrations: number; views: number; visitors: number; conversion: number | null };
  registrations_daily: { date: string; count: number }[];
  views_daily: { date: string; count: number }[];
  by_status: { status: string; label: string; count: number }[];
  by_device: Record<string, number>;
};

export default function ReportOverview() {
  const p = useReportParams();
  const { data, isLoading } = useQuery({ queryKey: ["report-overview", p], queryFn: async () => (await api.get<Overview>("/reports/overview", { params: p })).data });
  return (
    <>
      <ReportFilters />
      {isLoading || !data ? (
        <Skeleton active />
      ) : (
        <>
          <div className="ep-kpis !grid-cols-2 xl:!grid-cols-4">
            <Kpi label="Registrations" value={data.totals.registrations} />
            <Kpi label="Page views" value={data.totals.views} />
            <Kpi label="Unique visitors (per day)" value={data.totals.visitors} />
            <Kpi label="Visitors who registered" value={data.totals.conversion === null ? "—" : `${data.totals.conversion}%`} />
          </div>
          <div className="ep-grid-2">
            <div className="flex min-w-0 flex-col gap-4">
              <section className="ep-card">
                <h2 className="ep-card__title">Registrations per day</h2>
                <BarChart data={data.registrations_daily} />
              </section>
              <section className="ep-card">
                <h2 className="ep-card__title">Page views per day</h2>
                <BarChart data={data.views_daily} label="views" />
              </section>
            </div>
            <div className="flex min-w-0 flex-col gap-4">
              <section className="ep-card">
                <h2 className="ep-card__title">Applicant status</h2>
                <RankBars rows={data.by_status.map((s) => ({ label: s.label, value: s.count }))} />
              </section>
              <section className="ep-card">
                <h2 className="ep-card__title">Devices</h2>
                <RankBars rows={Object.entries(data.by_device).map(([k, v]) => ({ label: k[0].toUpperCase() + k.slice(1), value: Number(v) }))} />
              </section>
            </div>
          </div>
        </>
      )}
    </>
  );
}
