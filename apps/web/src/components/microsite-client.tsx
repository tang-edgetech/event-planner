"use client";

import { useEffect, useState } from "react";

/** Live countdown to a date/time. */
export function Countdown({ to }: { to: string }) {
  const target = new Date(to).getTime();
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setNow(Date.now());
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, []);
  if (now === null || Number.isNaN(target)) return null;
  const s = Math.max(0, Math.floor((target - now) / 1000));
  const parts: [number, string][] = [
    [Math.floor(s / 86400), "Days"],
    [Math.floor((s % 86400) / 3600), "Hours"],
    [Math.floor((s % 3600) / 60), "Minutes"],
    [s % 60, "Seconds"],
  ];
  return (
    <div className="ep-countdown" role="timer" aria-label="Time remaining">
      {parts.map(([n, l]) => (
        <div key={l} className="ep-countdown__item">
          <strong>{String(n).padStart(2, "0")}</strong>
          <span>{l}</span>
        </div>
      ))}
    </div>
  );
}

/** Button that opens a pop-up list of links. */
export function ModalButton({ label, title, links, className, style }: { label: string; title?: string; links: { label: string; url: string }[]; className: string; style?: React.CSSProperties }) {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    if (!open) return;
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    window.addEventListener("keydown", esc);
    return () => window.removeEventListener("keydown", esc);
  }, [open]);
  return (
    <>
      <button type="button" className={className} style={style} onClick={() => setOpen(true)}>
        {label}
      </button>
      {open ? (
        <div className="ep-modal" role="dialog" aria-modal="true" aria-label={title || label} onClick={() => setOpen(false)}>
          <div className="ep-modal__box" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-start justify-between gap-4">
              <h3 className="m-0 text-lg font-bold">{title || label}</h3>
              <button type="button" className="ep-modal__close" aria-label="Close" onClick={() => setOpen(false)}>
                ×
              </button>
            </div>
            <ul className="mt-4 flex list-none flex-col gap-2 p-0">
              {links.map((l, i) => (
                <li key={i}>
                  <a className="ep-btn ep-btn--outline w-full" href={l.url} target="_blank" rel="noopener noreferrer">
                    {l.label}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}
    </>
  );
}

/** Horizontal sliding gallery with previous/next buttons. */
export function Carousel({ images }: { images: string[] }) {
  const [i, setI] = useState(0);
  if (!images.length) return null;
  return (
    <div className="ep-carousel">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={images[i]} alt={`Photo ${i + 1} of ${images.length}`} />
      {images.length > 1 ? (
        <>
          <button type="button" className="ep-carousel__btn ep-carousel__btn--prev" aria-label="Previous photo" onClick={() => setI((i - 1 + images.length) % images.length)}>
            ‹
          </button>
          <button type="button" className="ep-carousel__btn ep-carousel__btn--next" aria-label="Next photo" onClick={() => setI((i + 1) % images.length)}>
            ›
          </button>
          <div className="ep-carousel__dots">
            {images.map((_, j) => (
              <button key={j} type="button" aria-label={`Show photo ${j + 1}`} className={j === i ? "is-active" : ""} onClick={() => setI(j)} />
            ))}
          </div>
        </>
      ) : null}
    </div>
  );
}

/** Top menu of the microsite, collapsing into a toggle on phones. */
export function SiteNav({ logo, title, items, cta }: { logo?: string; title: string; items: { label: string; href: string }[]; cta?: { label: string; href: string } }) {
  const [open, setOpen] = useState(false);
  return (
    <header className="ep-sitenav">
      <div className="ep-sitenav__inner">
        <a href="#top" className="ep-sitenav__brand">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          {logo ? <img src={logo} alt={title} /> : <span>{title}</span>}
        </a>
        <button type="button" className="ep-sitenav__toggle" aria-expanded={open} aria-label="Menu" onClick={() => setOpen(!open)}>
          ☰
        </button>
        <nav className={`ep-sitenav__links${open ? " is-open" : ""}`} aria-label="Page sections">
          {items.map((it) => (
            <a key={it.href} href={it.href} onClick={() => setOpen(false)}>
              {it.label}
            </a>
          ))}
          {cta ? (
            <a className="ep-btn ep-btn--solid ep-btn--sm" href={cta.href} onClick={() => setOpen(false)}>
              {cta.label}
            </a>
          ) : null}
        </nav>
      </div>
    </header>
  );
}

/** Fades sections in as they scroll into view (Microsite → General → Scroll animations). */
export function Reveal() {
  useEffect(() => {
    const els = document.querySelectorAll<HTMLElement>(".ep-reveal");
    if (!("IntersectionObserver" in window) || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      els.forEach((el) => el.classList.add("is-visible"));
      return;
    }
    const io = new IntersectionObserver(
      (entries) =>
        entries.forEach((e) => {
          if (e.isIntersecting) {
            e.target.classList.add("is-visible");
            io.unobserve(e.target);
          }
        }),
      { threshold: 0.08 },
    );
    els.forEach((el) => io.observe(el));
    // Safety net: never leave a section invisible (e.g. in a background tab or a scaled preview frame).
    const fallback = window.setTimeout(() => els.forEach((el) => el.classList.add("is-visible")), 2500);
    return () => {
      io.disconnect();
      window.clearTimeout(fallback);
    };
  }, []);
  return null;
}
