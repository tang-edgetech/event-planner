import "server-only";
import { headers } from "next/headers";
import { notFound } from "next/navigation";

/** Slugs are lower-case letters, digits and dashes. Anything else is a 404 without asking the API. */
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

export const API_URL = (process.env.API_URL ?? "http://127.0.0.1:8000").replace(/\/$/, "");

/** Headers every call to the API carries: the shared key, plus the visitor's IP and browser (for statistics and rate limits). */
export async function internalHeaders(extra: Record<string, string> = {}): Promise<Record<string, string>> {
  const h = await headers();
  const ip = (h.get("x-forwarded-for") ?? "").split(",")[0].trim() || h.get("x-real-ip") || "";
  return {
    Accept: "application/json",
    "X-Internal-Key": process.env.INTERNAL_API_KEY ?? "",
    "X-Client-Ip": ip,
    "X-Client-Agent": h.get("user-agent") ?? "",
    ...extra,
  };
}

/**
 * Fetch one published item from the Laravel public API, or show the 404 page.
 * Runs on the server only, so the API address and key never reach the browser.
 */
export async function getPublic<T>(kind: "events" | "microsites", slug: string, query: Record<string, string> = {}, source?: Record<string, string>): Promise<T> {
  if (!SLUG.test(slug) || slug.length > 120) {
    notFound();
  }
  const qs = new URLSearchParams(query).toString();
  let res: Response;
  try {
    res = await fetch(`${API_URL}/api/public/${kind}/${slug}${qs ? `?${qs}` : ""}`, {
      cache: "no-store",
      headers: await internalHeaders(source ? { "X-Client-Source": JSON.stringify(source) } : {}),
    });
  } catch {
    throw new Error("The event service is not reachable.");
  }
  if (res.status === 404) {
    notFound();
  }
  if (!res.ok) {
    throw new Error(`Event service error ${res.status}`);
  }
  return (await res.json()) as T;
}

export type SiteInfo = { site_name: string; home_mode: "maintenance" | "notfound"; maintenance_title: string; maintenance_message: string; contact_email: string };

export async function getSite(): Promise<SiteInfo | null> {
  try {
    const res = await fetch(`${API_URL}/api/public/site`, { cache: "no-store", headers: await internalHeaders() });
    return res.ok ? ((await res.json()) as SiteInfo) : null;
  } catch {
    return null;
  }
}

/** UTM tags and referrer of this visit, for statistics. */
export async function visitSource(searchParams: Record<string, string | string[] | undefined>): Promise<Record<string, string>> {
  const h = await headers();
  const out: Record<string, string> = {};
  for (const k of ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term"]) {
    const v = searchParams[k];
    if (typeof v === "string" && v) out[k] = v.slice(0, 100);
  }
  const ref = h.get("referer");
  if (ref) out.referrer = ref.slice(0, 300);
  return out;
}

/** "media/2026/11/x.jpg" → "/media/2026/11/x.jpg" (served by this site, passed on to the API). */
export const media = (path?: string | null) => (path ? (/^https?:\/\//.test(path) ? path : `/${path.replace(/^\/+/, "")}`) : "");
