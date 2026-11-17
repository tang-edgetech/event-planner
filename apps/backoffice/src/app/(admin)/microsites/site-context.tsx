"use client";

import { createContext, useContext } from "react";
import type { MicrositeDetail } from "@/lib/types";

export const SiteContext = createContext<MicrositeDetail | null>(null);
export const useSite = () => {
  const s = useContext(SiteContext);
  if (!s) throw new Error("useSite outside microsite layout");
  return s;
};
