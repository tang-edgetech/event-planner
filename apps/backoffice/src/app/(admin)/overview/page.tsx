"use client";

import Link from "next/link";
import { Button, Empty, List, Skeleton } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { fmtDate, fromNow } from "@/lib/format";
import { useCan, useMe } from "@/lib/session";
import type { RegistrationState } from "@/lib/types";
import { BarChart } from "@/components/bar-chart";
import { ApplicantStatusTag, Kpi, PageHead, RegistrationTag } from "@/components/ui";

type Dashboard = {
  kpis: Record<string, number>;
  upcoming: { id: number; title: string; starts_at: string | null; date_text: string | null; venue: string | null; registration: RegistrationState }[];
  latest: { id: number; name: string | null; event_title: string | null; status: string; status_label: string; created_at: string }[];
  trend: { date: string; count: number }[];
};

export default function OverviewPage() {
  const me = useMe();
  const can = useCan();
  const { data, isLoading } = useQuery({ queryKey: ["dashboard"], queryFn: async () => (await api.get<Dashboard>("/dashboard")).data });

  return (
    <>
      <PageHead
        title={`Welcome, ${me.user.name.split(" ")[0]}`}
        sub="What is happening across your events today."
        actions={
          can("events.manage") ? (
            <Link href="/events/new">
              <Button type="primary" icon={<PlusOutlined />}>
                New event
              </Button>
            </Link>
          ) : null
        }
      />
      {isLoading || !data ? (
        <Skeleton active />
      ) : (
        <>
          <div className="ep-kpis">
            <Kpi label="Open for registration" value={data.kpis.open_events} />
            <Kpi label="Published events" value={data.kpis.published_events} />
            <Kpi label="Applicants this month" value={data.kpis.applicants_month} />
            <Kpi label="All applicants" value={data.kpis.applicants_total} />
            <Kpi label="On waiting lists" value={data.kpis.waitlist} />
            <Kpi label="Page views this month" value={data.kpis.views_month} />
          </div>
          <div className="ep-grid-2">
            <div className="flex min-w-0 flex-col gap-4">
              <section className="ep-card">
                <h2 className="ep-card__title">Registrations, last 14 days</h2>
                <BarChart data={data.trend} />
              </section>
              <section className="ep-card">
                <h2 className="ep-card__title">Upcoming events</h2>
                {data.upcoming.length ? (
                  <List
                    dataSource={data.upcoming}
                    renderItem={(e) => (
                      <List.Item extra={<RegistrationTag reg={e.registration} />}>
                        <List.Item.Meta
                          title={<Link href={`/events/${e.id}/details`}>{e.title}</Link>}
                          description={`${e.date_text || fmtDate(e.starts_at)}${e.venue ? ` · ${e.venue}` : ""} · ${e.registration.count}${e.registration.max ? ` / ${e.registration.max}` : ""} seats taken`}
                        />
                      </List.Item>
                    )}
                  />
                ) : (
                  <Empty description="No upcoming published events" />
                )}
              </section>
            </div>
            <section className="ep-card min-w-0">
              <h2 className="ep-card__title">Latest registrations</h2>
              {data.latest.length ? (
                <List
                  dataSource={data.latest}
                  renderItem={(a) => (
                    <List.Item extra={<ApplicantStatusTag status={a.status} label={a.status_label} />}>
                      <List.Item.Meta
                        title={can("applicants.view") ? <Link href={`/applicants/${a.id}`}>{a.name || "—"}</Link> : a.name}
                        description={`${a.event_title ?? ""} · ${fromNow(a.created_at)}`}
                      />
                    </List.Item>
                  )}
                />
              ) : (
                <Empty description="No registrations yet" />
              )}
            </section>
          </div>
        </>
      )}
    </>
  );
}
