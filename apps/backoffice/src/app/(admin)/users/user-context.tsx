"use client";

import { useQuery } from "@tanstack/react-query";
import { createContext, useContext } from "react";
import { api } from "@/lib/api";
import type { UserRow } from "@/lib/types";

export const UserContext = createContext<UserRow | null>(null);
export const useUserRow = () => {
  const u = useContext(UserContext);
  if (!u) throw new Error("useUserRow outside user layout");
  return u;
};

export function useUserQuery(id: string) {
  return useQuery({ queryKey: ["user", id], queryFn: async () => (await api.get<{ data: UserRow }>(`/users/${id}`)).data.data });
}
