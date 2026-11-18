"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { Button, Skeleton } from "antd";
import { ArrowLeftOutlined, ExportOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { useMe } from "@/lib/session";
import type { MicrositeDetail } from "@/lib/types";
import { NotFoundView, PageHead, PublishTag, RouteTabs } from "@/components/ui";
import { DraftBanner, PublishButton } from "@/components/publish-control";
import { SiteContext } from "../site-context";

/** /microsites/{id}/builder | settings */
export default function MicrositeLayout({ children }: { children: React.ReactNode }) {
  const { id } = useParams<{ id: string }>();
  const me = useMe();
  const { data: site, isLoading, isError } = useQuery({ queryKey: ["microsite", id], queryFn: async () => (await api.get<{ data: MicrositeDetail }>(`/microsites/${id}`)).data.data });

  if (isLoading) return <Skeleton active />;
  if (isError || !site) return <NotFoundView />;
  const pub = { kind: "microsite" as const, id: site.id, title: site.title, status: site.status, address: `/e/${site.slug}` };

  return (
    <SiteContext.Provider value={site}>
      <PageHead
        title={site.title}
        sub={
          <span>
            <PublishTag status={site.status} /> /e/{site.slug}
          </span>
        }
        actions={
          <>
            <Link href="/microsites">
              <Button icon={<ArrowLeftOutlined />}>All microsites</Button>
            </Link>
            <a href={`${me.settings.web_url}/e/${site.slug}`} target="_blank" rel="noreferrer">
              <Button icon={<ExportOutlined />} disabled={site.status !== "published"}>
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
          { href: `/microsites/${id}/builder`, label: "Page builder" },
          { href: `/microsites/${id}/settings`, label: "General" },
        ]}
      />
      {children}
    </SiteContext.Provider>
  );
}
