"use client";

import { useEffect } from "react";
import { mediaUrl } from "@/lib/api";

/** Logo from Settings → General, or the coloured square and the site name when no logo is set. */
export function BrandMark({ logo, name }: { logo?: string | null; name: string }) {
  if (logo) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={mediaUrl(logo)} alt={name} className="ep-brand-logo" />;
  }
  return (
    <>
      <span className="ep-logo" aria-hidden="true" />
      <span className="truncate">{name}</span>
    </>
  );
}

/** Uses the favicon from Settings → General for the browser tab. */
export function useFavicon(favicon?: string | null) {
  useEffect(() => {
    if (!favicon) return;
    const href = mediaUrl(favicon);
    let link = document.querySelector<HTMLLinkElement>("link[data-ep-favicon]");
    if (!link) {
      document.querySelectorAll("link[rel~='icon']").forEach((l) => l.remove());
      link = document.createElement("link");
      link.rel = "icon";
      link.dataset.epFavicon = "1";
      document.head.appendChild(link);
    }
    link.href = href;
  }, [favicon]);
}
