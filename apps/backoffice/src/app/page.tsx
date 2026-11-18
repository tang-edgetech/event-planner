"use client";

import { Spin } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { api } from "@/lib/api";

/** The backoffice root: first run → setup, signed in → Overview, otherwise → sign in. */
export default function Home() {
  const router = useRouter();
  const { data } = useQuery({
    queryKey: ["entry"],
    queryFn: async () => {
      const config = (await api.get<{ setup_needed: boolean }>("/auth/config")).data;
      if (config.setup_needed) return "/setup";
      try {
        await api.get("/auth/me");
        return "/overview";
      } catch {
        return "/login";
      }
    },
  });
  useEffect(() => {
    if (data) router.replace(data);
  }, [data, router]);
  return (
    <div className="ep-notice">
      <Spin size="large" />
    </div>
  );
}
