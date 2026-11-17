import axios, { AxiosError } from "axios";

export const BASE_PATH = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

/** Prefix a path inside the backoffice (for plain <a href>, window.location, media). */
export const withBase = (path: string) => `${BASE_PATH}${path.startsWith("/") ? path : `/${path}`}`;

/** Media stored as "media/2026/11/x.jpg" → served by this server at {secret}/media/… */
export const mediaUrl = (path?: string | null) => (path ? (/^https?:\/\//.test(path) ? path : withBase(`/${path.replace(/^\/+/, "")}`)) : "");

/**
 * All requests go to this same server ({secret}/api/…), which passes them to Laravel.
 * The XSRF-TOKEN cookie is sent back automatically as the X-XSRF-TOKEN header.
 */
export const api = axios.create({
  baseURL: withBase("/api"),
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
});

export type ApiErrorBody = { message?: string; reason?: string; errors?: Record<string, string[]> };

/** Session problems are broadcast so the shell can react (logout pop-up, forced password change…). */
api.interceptors.response.use(
  (r) => r,
  (error: AxiosError<ApiErrorBody>) => {
    const status = error.response?.status;
    const url = error.config?.url ?? "";
    const isAuthCall = /auth\/(login|two-factor|config)|setup/.test(url);
    if (typeof window !== "undefined" && !isAuthCall) {
      if (status === 401) {
        window.dispatchEvent(new CustomEvent("ep:signed-out", { detail: error.response?.data?.reason ?? "unauthenticated" }));
      } else if (status === 423) {
        window.dispatchEvent(new CustomEvent("ep:account-gate", { detail: error.response?.data?.reason }));
      } else if (status === 419) {
        window.dispatchEvent(new CustomEvent("ep:signed-out", { detail: "expired" }));
      }
    }
    return Promise.reject(error);
  },
);

/** First readable message from an API error. */
export function errorMessage(error: unknown, fallback = "Something went wrong. Please try again."): string {
  const e = error as AxiosError<ApiErrorBody>;
  const data = e?.response?.data;
  if (data?.errors) {
    const first = Object.values(data.errors)[0];
    if (first?.length) return first[0];
  }
  return data?.message && data.message !== "Not found." ? data.message : fallback;
}

/** Laravel validation errors → Ant Design Form fields (supports "data.email" style keys). */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function formErrors(error: unknown): any[] {
  const e = error as AxiosError<ApiErrorBody>;
  const errors = e?.response?.data?.errors ?? {};
  return Object.entries(errors).map(([key, messages]) => ({
    name: key.split(".").map((k) => (/^\d+$/.test(k) ? Number(k) : k)),
    errors: messages,
  }));
}

/** Download a CSV/JSON export from a POST endpoint. */
export async function download(url: string, body: Record<string, unknown>, fallbackName: string) {
  const res = await api.post(url, body, { responseType: "blob" });
  const disposition = String(res.headers["content-disposition"] ?? "");
  const name = /filename="?([^"]+)"?/.exec(disposition)?.[1] ?? fallbackName;
  const href = URL.createObjectURL(res.data as Blob);
  const a = document.createElement("a");
  a.href = href;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}
