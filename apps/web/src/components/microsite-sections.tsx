import { media } from "@/lib/public-api";
import type { PublicEvent, PublicMicrosite, Section } from "@/lib/types";
import { RegistrationForm } from "./registration-form";
import { Carousel, Countdown, ModalButton } from "./microsite-client";

type Obj = Record<string, unknown>;
const str = (v: unknown) => (typeof v === "string" ? v : "");
const list = <T = Obj,>(v: unknown) => (Array.isArray(v) ? (v as T[]) : []);

/** Light or dark text for a background colour. */
function isDark(hex: string): boolean {
  const h = hex.replace("#", "");
  if (h.length < 6) return false;
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b < 140;
}

/** Section wrapper: background (colour, gradient, image, video), text colour, spacing, width, anchor. */
function Wrap({ s, children, site }: { s: Section; children: React.ReactNode; site: PublicMicrosite }) {
  const st = s.settings;
  const bg = str(st.bg_type) || "none";
  const style: React.CSSProperties & Record<string, string> = {};
  let dark = false;
  if (bg === "color" && str(st.bg_color)) {
    style.background = str(st.bg_color);
    dark = isDark(str(st.bg_color));
  } else if (bg === "gradient") {
    style.background = `linear-gradient(${Number(st.bg_angle ?? 135)}deg, ${str(st.bg_color) || "#7a0f3d"}, ${str(st.bg_color2) || "#2a1a20"})`;
    dark = isDark(str(st.bg_color) || "#7a0f3d");
  } else if (bg === "image" && str(st.bg_image)) {
    style.backgroundImage = `url("${media(str(st.bg_image))}")`;
    if (str(st.bg_image_mobile)) style["--ep-bg-mobile"] = `url("${media(str(st.bg_image_mobile))}")`;
    dark = true;
  } else if (bg === "video") {
    dark = true;
  }
  const scheme = str(st.text_scheme) || "auto";
  const light = scheme === "light" || (scheme === "auto" && dark);
  if (scheme === "custom" && str(st.text_color)) style.color = str(st.text_color);
  const fit = str(st.bg_fit) || "cover";

  return (
    <section
      id={str(st.anchor) || undefined}
      className={[
        "ep-section",
        `ep-section--${s.layout}`,
        `ep-pad-${str(st.padding) || "md"}`,
        light ? "ep-on-dark" : "",
        bg === "image" ? `ep-bg-image ep-bg-${fit}` : "",
        site.settings.animations === false ? "" : "ep-reveal",
      ].join(" ")}
      style={style}
    >
      {bg === "video" && str(st.bg_video) ? <video className="ep-bg-video" src={media(str(st.bg_video))} autoPlay muted loop playsInline aria-hidden="true" /> : null}
      {(bg === "image" || bg === "video") && str(st.overlay) ? <div className="ep-overlay" style={{ background: str(st.overlay), opacity: 0.6 }} aria-hidden="true" /> : null}
      <div className={`ep-container ep-container--${str(st.container) || "default"}`}>{children}</div>
    </section>
  );
}

function Heading({ d }: { d: Obj }) {
  if (!str(d.eyebrow) && !str(d.heading)) return null;
  return (
    <div className={`ep-heading ep-heading--${str(d.align) || "center"}`}>
      {str(d.eyebrow) ? <p className="ep-eyebrow">{str(d.eyebrow)}</p> : null}
      {str(d.heading) ? <h2>{str(d.heading)}</h2> : null}
    </div>
  );
}

function Html({ html, className = "ep-prose" }: { html: unknown; className?: string }) {
  return str(html) ? <div className={className} dangerouslySetInnerHTML={{ __html: str(html) }} /> : null;
}

function Buttons({ items, site }: { items: unknown; site: PublicMicrosite }) {
  const btns = list(items);
  if (!btns.length) return null;
  return (
    <div className="ep-buttons">
      {btns.map((b, i) => {
        const cls = `ep-btn ep-btn--${str(b.style) || "solid"}`;
        const label = str(b.label) || "Learn more";
        if (b.action === "modal") {
          return <ModalButton key={i} className={cls} label={label} title={str(b.modal_title)} links={list<{ label: string; url: string }>(b.modal_links)} />;
        }
        let href = str(b.url) || "#";
        if (b.action === "event") {
          const ev = site.events[String(b.event_id ?? site.event_id ?? "")];
          href = ev ? `/event/${ev.slug}` : "#";
        } else if (b.action === "anchor" && !href.startsWith("#")) {
          href = `#${href}`;
        }
        const external = b.action === "url" && b.new_tab;
        return (
          <a key={i} className={cls} href={href} {...(external ? { target: "_blank", rel: "noopener noreferrer" } : {})}>
            {label}
          </a>
        );
      })}
    </div>
  );
}

function Banner({ d, site }: { d: Obj; site: PublicMicrosite }) {
  const ev = site.event_id ? site.events[String(site.event_id)] : undefined;
  const date = str(d.date) || ev?.date_text || "";
  const time = str(d.time) || ev?.time_text || "";
  const venue = str(d.venue) || ev?.venue || "";
  const style = str(d.style) || "center";
  const text = (
    <div className={`ep-banner__text ep-align-${str(d.text_align) || "center"}`}>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      {str(d.logo) ? <img className="ep-banner__logo" src={media(str(d.logo))} alt="" /> : null}
      {str(d.eyebrow) ? <p className="ep-eyebrow">{str(d.eyebrow)}</p> : null}
      {str(d.title) ? <h1 className="ep-banner__title">{str(d.title)}</h1> : null}
      <Html html={d.description} className="ep-prose ep-banner__desc" />
      {d.show_meta && (date || time || venue) ? (
        <ul className="ep-banner__meta">
          {date ? <li>{date}</li> : null}
          {time ? <li>{time}</li> : null}
          {venue ? <li>{venue}</li> : null}
        </ul>
      ) : null}
      {d.countdown && str(d.countdown_to) ? <Countdown to={str(d.countdown_to)} /> : null}
      <Buttons items={d.buttons} site={site} />
    </div>
  );
  return (
    <div className={`ep-banner ep-banner--${style} ep-banner--h-${str(d.height) || "md"}`}>
      {style === "split" ? (
        <div className="ep-split">
          {text}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          {str(d.side_image) ? <img className="ep-banner__visual" src={media(str(d.side_image))} alt="" /> : null}
        </div>
      ) : style === "card" ? (
        <div className="ep-banner__card">{text}</div>
      ) : (
        text
      )}
    </div>
  );
}

function videoEmbed(url: string): string | null {
  const yt = /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{6,})/.exec(url);
  if (yt) return `https://www.youtube-nocookie.com/embed/${yt[1]}`;
  const vm = /vimeo\.com\/(?:video\/)?(\d+)/.exec(url);
  if (vm) return `https://player.vimeo.com/video/${vm[1]}`;
  return null;
}

function contactHref(type: string, value: string): string {
  if (type === "whatsapp") return `https://wa.me/${value.replace(/\D/g, "")}`;
  if (type === "phone") return `tel:${value.replace(/[^\d+]/g, "")}`;
  if (type === "email") return `mailto:${value}`;
  return value;
}

function Body({ s, site }: { s: Section; site: PublicMicrosite }) {
  const d = s.data;
  switch (s.layout) {
    case "banner":
      return <Banner d={d} site={site} />;
    case "text":
      return (
        <>
          <Heading d={d} />
          <Html html={d.content} className={`ep-prose ${d.columns === "2" ? "ep-cols-2" : "ep-measure"}`} />
        </>
      );
    case "image_text":
      return (
        <div className={`ep-split ep-split--${str(d.image_position) || "left"} ep-split--${str(d.image_ratio) || "50"}`}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          {str(d.image) ? <img className="ep-split__img" src={media(str(d.image))} alt="" /> : <div />}
          <div>
            <Heading d={{ ...d, align: "left" }} />
            <Html html={d.content} />
            <Buttons items={d.buttons} site={site} />
          </div>
        </div>
      );
    case "image": {
      const img = (
        <picture>
          {str(d.image_mobile) ? <source media="(max-width: 767px)" srcSet={media(str(d.image_mobile))} /> : null}
          <img className="ep-full-img" src={media(str(d.image))} alt={str(d.caption)} />
        </picture>
      );
      return (
        <figure className="m-0">
          {str(d.link) ? <a href={str(d.link)}>{img}</a> : img}
          {str(d.caption) ? <figcaption className="ep-caption">{str(d.caption)}</figcaption> : null}
        </figure>
      );
    }
    case "gallery": {
      const imgs = list<string>(d.images).map((x) => media(x));
      return (
        <>
          <Heading d={d} />
          <Html html={d.intro} className="ep-prose ep-measure mb-6" />
          {d.style === "carousel" ? (
            <Carousel images={imgs} />
          ) : (
            <div className={`ep-gallery ep-gallery--${str(d.style) || "grid"}`} style={{ "--cols": Number(d.columns ?? 3) } as React.CSSProperties}>
              {imgs.map((src, i) => (
                // eslint-disable-next-line @next/next/no-img-element
                <img key={i} src={src} alt={`Photo ${i + 1}`} loading="lazy" />
              ))}
            </div>
          )}
        </>
      );
    }
    case "speakers":
      return (
        <>
          <Heading d={d} />
          <Html html={d.intro} className="ep-prose ep-measure mb-6" />
          <div className={`ep-people ep-people--${str(d.style) || "cards"}`}>
            {list(d.items).map((p, i) => (
              <article key={i} className="ep-person">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                {str(p.photo) ? <img src={media(str(p.photo))} alt={str(p.name)} loading="lazy" /> : <div className="ep-person__ph" aria-hidden="true" />}
                <div>
                  <h3>{str(p.name)}</h3>
                  {str(p.role) ? <p className="ep-person__role">{str(p.role)}</p> : null}
                  <Html html={p.bio} className="ep-prose text-sm" />
                </div>
              </article>
            ))}
          </div>
        </>
      );
    case "agenda":
      return (
        <>
          <Heading d={d} />
          <Html html={d.intro} className="ep-prose ep-measure mb-6" />
          <ol className="ep-agenda">
            {list(d.items).map((it, i) => (
              <li key={i}>
                <span className="ep-agenda__time">{str(it.time)}</span>
                <div>
                  <strong>{str(it.title)}</strong>
                  {str(it.description) ? <p>{str(it.description)}</p> : null}
                </div>
              </li>
            ))}
          </ol>
        </>
      );
    case "cards":
      return (
        <>
          <Heading d={d} />
          <Html html={d.intro} className="ep-prose ep-measure mb-6" />
          <div className="ep-cards" style={{ "--cols": Number(d.columns ?? 3) } as React.CSSProperties}>
            {list(d.items).map((c, i) => (
              <article key={i} className="ep-card-item">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                {str(c.image) ? <img src={media(str(c.image))} alt="" loading="lazy" /> : null}
                <div className="ep-card-item__body">
                  {str(c.title) ? <h3>{str(c.title)}</h3> : null}
                  <Html html={c.content} className="ep-prose text-sm" />
                  {str(c.link_url) ? (
                    <a className="ep-link" href={str(c.link_url)}>
                      {str(c.link_label) || "Learn more"} →
                    </a>
                  ) : null}
                </div>
              </article>
            ))}
          </div>
        </>
      );
    case "logos":
      return (
        <>
          <Heading d={d} />
          <div className="ep-logos">
            {list<string>(d.images).map((src, i) => (
              // eslint-disable-next-line @next/next/no-img-element
              <img key={i} src={media(src)} alt={`Logo ${i + 1}`} loading="lazy" />
            ))}
          </div>
        </>
      );
    case "video": {
      const src = videoEmbed(str(d.embed));
      return (
        <>
          <Heading d={d} />
          <Html html={d.intro} className="ep-prose ep-measure mb-6" />
          {src ? (
            <div className="ep-video">
              <iframe src={src} title={str(d.heading) || "Video"} allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture" allowFullScreen loading="lazy" />
            </div>
          ) : null}
        </>
      );
    }
    case "contact":
      return (
        <>
          <Heading d={d} />
          <Html html={d.intro} className="ep-prose ep-measure mb-6" />
          <div className="ep-contacts">
            {list(d.items).map((c, i) => (
              <a key={i} className="ep-contact" href={contactHref(str(c.type), str(c.value))} target={c.type === "link" || c.type === "whatsapp" ? "_blank" : undefined} rel="noopener noreferrer">
                <span className="ep-contact__type">{{ whatsapp: "WhatsApp", phone: "Call", email: "Email", link: "Visit" }[str(c.type)] ?? "Contact"}</span>
                <strong>{str(c.name) || str(c.value)}</strong>
                {str(c.name) ? <span className="ep-muted text-sm">{str(c.value)}</span> : null}
              </a>
            ))}
          </div>
        </>
      );
    case "form": {
      const ev: PublicEvent | undefined = site.events[String(d.event_id ?? site.event_id ?? "")];
      return (
        <>
          <Heading d={d} />
          <Html html={d.intro} className="ep-prose ep-measure mb-6" />
          {ev ? (
            <div className="ep-form-card">
              <RegistrationForm event={ev} id={`form-${s.id.slice(0, 8)}`} />
            </div>
          ) : (
            <p className="ep-muted text-center">Registration is not available.</p>
          )}
        </>
      );
    }
    case "html":
      return <div dangerouslySetInnerHTML={{ __html: str(d.code) }} />;
    default:
      return null;
  }
}

export function MicrositeSections({ site }: { site: PublicMicrosite }) {
  return (
    <>
      {site.sections.map((s) => (
        <Wrap key={s.id} s={s} site={site}>
          <Body s={s} site={site} />
        </Wrap>
      ))}
    </>
  );
}
