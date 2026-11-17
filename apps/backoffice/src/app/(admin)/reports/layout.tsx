"use client";

import { Suspense } from "react";
import { useCan } from "@/lib/session";
import { NotFoundView, PageHead, RouteTabs } from "@/components/ui";

/** /reports/overview | events | sources */
export default function ReportsLayout({ children }: { children: React.ReactNode }) {
  const can = useCan();
  if (!can("reports.view")) return <NotFoundView />;
  return (
    <>
      <PageHead title="Reports" sub="Registrations, page views and where visitors come from." />
      <RouteTabs
        items={[
          { href: "/reports/overview", label: "Overview" },
          { href: "/reports/events", label: "By event" },
          { href: "/reports/sources", label: "Sources" },
        ]}
      />
      <Suspense>{children}</Suspense>
    </>
  );
}
