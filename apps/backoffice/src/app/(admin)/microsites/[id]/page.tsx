import { redirect } from "next/navigation";

export default async function MicrositeIndex({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  redirect(`/microsites/${id}/builder`);
}
