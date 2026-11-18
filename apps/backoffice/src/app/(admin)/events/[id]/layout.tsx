"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { Button, Skeleton, Space } from "antd";
import { ArrowLeftOutlined, ExportOutlined } from "@ant-design/icons";
import { useMe } from "@/lib/session";
import { NotFoundView, PageHead, PublishTag, RegistrationTag, RouteTabs } from "@/components/ui";
import { DraftBanner, PublishButton } from "@/components/publish-control";
import { EventContext, useEventQuery } from "../event-context";

/** /events/{id}/details | form | capacity | emails | applicants */
export default function EventLayout({ children }: { children: React.ReactNode }) {
  const { id } = useParams<{ id: string }>();
  const me = useMe();
  const { data: event, isLoading, isError } = useEventQuery(id);

  if (isLoading) return <Skeleton active />;
  if (isError || !event) return <NotFoundView />;
  const pub = { kind: "event" as const, id: event.id, title: event.title, status: event.status, address: `/event/${event.slug}` };

  return (
    <EventContext.Provider value={event}>
      <PageHead
        title={event.title}
        sub={
          <Space wrap size={6}>
            <PublishTag status={event.status} />
            <RegistrationTag reg={event.registration} />
            <span>
              {event.registration.count}
              {event.registration.max ? ` / ${event.registration.max}` : ""} seats taken · {event.counts.total} applicant(s)
            </span>
          </Space>
        }
        actions={
          <>
            <Link href="/events">
              <Button icon={<ArrowLeftOutlined />}>All events</Button>
            </Link>
            <a href={`${me.settings.web_url}/event/${event.slug}`} target="_blank" rel="noreferrer">
              <Button icon={<ExportOutlined />} disabled={event.status !== "published"}>
                Open public page
              </Button>
            </a>
            <PublishButton {...pub} />
          </>
        }
      />
      <DraftBanner {...pub} />
      <RouteTabs
        items={[
          { href: `/events/${id}/details`, label: "Details" },
          { href: `/events/${id}/form`, label: "Registration form" },
          { href: `/events/${id}/capacity`, label: "Capacity & status" },
          { href: `/events/${id}/emails`, label: "After submit & emails" },
          { href: `/events/${id}/applicants`, label: `Applicants (${event.counts.total})` },
        ]}
      />
      {children}
    </EventContext.Provider>
  );
}
