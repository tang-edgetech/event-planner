"use client";

import { ApplicantsTable } from "@/components/applicants-table";
import { useEventDetail } from "../../event-context";

export default function EventApplicantsTab() {
  const event = useEventDetail();
  return <ApplicantsTable eventId={event.id} />;
}
