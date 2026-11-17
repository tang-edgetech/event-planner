import { redirect } from "next/navigation";

export default async function EventIndex({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  redirect(`/events/${id}/details`);
}
