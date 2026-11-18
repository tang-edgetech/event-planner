"use client";

import { useEffect, useState } from "react";

/** Full-screen loader while the page starts; rendered by the server, faded out once the page is running. */
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
      <span className="ep-preloader__spinner" />
    </div>
  );
}

/** Thin bar across the top after clicking a link to another page (event, microsite) until the browser leaves. */
export function TopProgress() {
  const [state, setState] = useState<"idle" | "loading">("idle");
  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = (e.target as Element | null)?.closest?.("a[href]") as HTMLAnchorElement | null;
      if (!a || a.target === "_blank" || a.hasAttribute("download")) return;
      const url = new URL(a.href, window.location.href);
      if (!/^https?:$/.test(url.protocol)) return; // tel:, mailto:, whatsapp
      if (url.pathname === window.location.pathname && url.search === window.location.search) return; // #anchors
      setState("loading");
    };
    // Coming back with the Back button shows the old page from memory: reset the bar.
    const onShow = () => setState("idle");
    document.addEventListener("click", onClick);
    window.addEventListener("pageshow", onShow);
    return () => {
      document.removeEventListener("click", onClick);
      window.removeEventListener("pageshow", onShow);
    };
  }, []);
  return <div className={`ep-topbar is-${state}`} aria-hidden="true" />;
}
