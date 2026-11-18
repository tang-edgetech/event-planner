"use client";

import { usePathname, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useRef, useState } from "react";

/** Other code (e.g. after router.push) can start the bar with: window.dispatchEvent(new Event("ep:nav-start")). */
export const startProgress = () => window.dispatchEvent(new Event("ep:nav-start"));

/** Full-screen loader on first visit; rendered by the server, removed once the app is running. */
export function Preloader() {
  const [done, setDone] = useState(false);
  const [gone, setGone] = useState(false);
  useEffect(() => {
    const t1 = window.setTimeout(() => setDone(true), 50);
    const t2 = window.setTimeout(() => setGone(true), 500);
    return () => {
      window.clearTimeout(t1);
      window.clearTimeout(t2);
    };
  }, []);
  if (gone) return null;
  return (
    <div className={`ep-preloader${done ? " is-done" : ""}`} aria-hidden="true">
      <span className="ep-preloader__mark" />
      <span className="ep-preloader__spinner" />
    </div>
  );
}

/** Thin bar across the top while moving between pages. */
export function TopProgress() {
  return (
    <Suspense fallback={null}>
      <Bar />
    </Suspense>
  );
}

function Bar() {
  const pathname = usePathname();
  const search = useSearchParams();
  const [state, setState] = useState<"idle" | "loading" | "done">("idle");
  const timers = useRef<number[]>([]);
  const clear = () => {
    timers.current.forEach((t) => window.clearTimeout(t));
    timers.current = [];
  };

  // Start on clicks on links to another page of this app.
  useEffect(() => {
    const start = () => {
      clear();
      setState("loading");
      timers.current.push(window.setTimeout(() => setState("done"), 10000)); // never stay stuck
    };
    const onClick = (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = (e.target as Element | null)?.closest?.("a[href]") as HTMLAnchorElement | null;
      if (!a || a.target === "_blank" || a.hasAttribute("download")) return;
      const url = new URL(a.href, window.location.href);
      if (url.origin !== window.location.origin) return;
      if (url.pathname === window.location.pathname && url.search === window.location.search) return;
      start();
    };
    document.addEventListener("click", onClick, true);
    window.addEventListener("ep:nav-start", start);
    return () => {
      document.removeEventListener("click", onClick, true);
      window.removeEventListener("ep:nav-start", start);
      clear();
    };
  }, []);

  // Finish when the new page is on screen.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setState((s) => (s === "loading" ? "done" : s));
  }, [pathname, search]);

  useEffect(() => {
    if (state !== "done") return;
    const t = window.setTimeout(() => setState("idle"), 400);
    return () => window.clearTimeout(t);
  }, [state]);

  return <div className={`ep-topbar is-${state}`} role="progressbar" aria-hidden={state === "idle"} aria-label="Loading page" />;
}

/** Fades the page content in after every page change, without re-mounting it. */
export function PageFade({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el || !el.animate || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    el.animate([{ opacity: 0, transform: "translateY(6px)" }, { opacity: 1, transform: "none" }], { duration: 260, easing: "ease-out" });
  }, [pathname]);
  return <div ref={ref}>{children}</div>;
}
