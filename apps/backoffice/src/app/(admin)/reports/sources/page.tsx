"use client";

import { Skeleton } from "antd";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { RankBars } from "@/components/bar-chart";
import { ReportFilters, useReportParams } from "../report-filters";

type Sources = {
  registrations_by_source: { source: string; count: number }[];
  registrations_by_campaign: { campaign: string; count: number }[];
  views_by_source: { source: string; count: number }[];
};

export default function ReportSources() {
  const p = useReportParams();
  const { data, isLoading } = useQuery({ queryKey: ["report-sources", p], queryFn: async () => (await api.get<Sources>("/reports/sources", { params: p })).data });
  return (
    <>
      <ReportFilters />
      <p className="ep-muted -mt-2 mb-3 text-sm">
        Sources come from campaign tags in links (for example <code>?utm_source=newsletter&amp;utm_campaign=launch</code>) or the website the visitor came from.
      </p>
      {isLoading || !data ? (
        <Skeleton active />
      ) : (
        <div className="grid gap-4 lg:grid-cols-3">
          <section className="ep-card">
            <h2 className="ep-card__title">Registrations by source</h2>
            <RankBars rows={data.registrations_by_source.map((r) => ({ label: r.source, value: r.count }))} />
          </section>
          <section className="ep-card">
            <h2 className="ep-card__title">Registrations by campaign</h2>
            <RankBars rows={data.registrations_by_campaign.map((r) => ({ label: r.campaign, value: r.count }))} />
          </section>
          <section className="ep-card">
            <h2 className="ep-card__title">Page views by source</h2>
            <RankBars rows={data.views_by_source.map((r) => ({ label: r.source, value: r.count }))} />
          </section>
        </div>
      )}
    </>
  );
}
