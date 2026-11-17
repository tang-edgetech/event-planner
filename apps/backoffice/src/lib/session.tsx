"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { createContext, useContext } from "react";
import { api } from "./api";
import type { Me, Permission } from "./types";

export const ME_KEY = ["me"] as const;

export const fetchMe = async () => (await api.get<Me>("/auth/me")).data;

/** The signed-in session (user, permissions, system look-and-feel settings). */
export function useMeQuery() {
  return useQuery({ queryKey: ME_KEY, queryFn: fetchMe, staleTime: 60_000 });
}

export const MeContext = createContext<Me | null>(null);

export function useMe(): Me {
  const me = useContext(MeContext);
  if (!me) throw new Error("useMe() outside the signed-in shell");
  return me;
}

export function useCan() {
  const me = useContext(MeContext);
  return (permission: Permission) => !!me?.permissions.includes(permission);
}

export function useRefreshMe() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: ME_KEY });
}
