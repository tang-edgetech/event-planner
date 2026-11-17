"use client";

import { useCan } from "@/lib/session";
import { ApplicantsTable } from "@/components/applicants-table";
import { NotFoundView, PageHead } from "@/components/ui";

export default function ApplicantsPage() {
  const can = useCan();
  if (!can("applicants.view")) return <NotFoundView />;
  return (
    <>
      <PageHead title="Applicants" sub="Everyone who registered, across all events." />
      <ApplicantsTable />
    </>
  );
}
