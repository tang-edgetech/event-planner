import { redirect } from "next/navigation";

export default async function UserIndex({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  redirect(`/users/${id}/account`);
}
