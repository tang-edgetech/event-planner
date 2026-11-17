import type { Metadata } from "next";
import { getPublic, media, visitSource } from "@/lib/public-api";
import type { PublicEvent } from "@/lib/types";
import { RegistrationForm } from "@/components/registration-form";

type Props = { params: Promise<{ slug: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const event = await getPublic<PublicEvent>("events", slug);
  return { title: event.title, robots: { index: false, follow: false } };
}

/** Event registration page: /event/{slug}. Unknown or unpublished events show the 404 page. */
export default async function EventPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const event = await getPublic<PublicEvent>("events", slug, { track: "1" }, await visitSource(await searchParams));
  const split = event.page_layout !== "single";
  const style = { ...(event.accent_color ? { "--ep-accent": event.accent_color } : {}), ...(event.page_bg ? { background: event.page_bg } : {}) } as React.CSSProperties;
  const reg = event.registration;
  const status = reg.state === "open" && reg.remaining !== null && reg.remaining > 0 && reg.remaining <= 20 ? `${reg.remaining} seat${reg.remaining === 1 ? "" : "s"} left` : reg.label;

  return (
    <main className="ep-event" style={style}>
      {event.banner_desktop || event.banner_mobile ? (
        <section className="ep-event-banner">
          <picture>
            {event.banner_mobile ? <source media="(max-width: 767px)" srcSet={media(event.banner_mobile)} /> : null}
            <img src={media(event.banner_desktop || event.banner_mobile)} alt={event.title} fetchPriority="high" />
          </picture>
        </section>
      ) : null}
      <section className={`ep-event-body ${split ? "ep-event-body--split" : "ep-event-body--single"}`}>
        {split ? (
          <aside className="ep-event-aside">
            <span className={`ep-status ep-status--${reg.state}`}>{status}</span>
            {event.show_title ? <h1 className="ep-event-title">{event.title}</h1> : null}
            <Facts event={event} />
            {event.summary ? <div className="ep-prose mt-5" dangerouslySetInnerHTML={{ __html: event.summary }} /> : null}
          </aside>
        ) : null}
        <div className="ep-event-card">
          {!split ? (
            <>
              {event.show_title ? <h1 className="ep-event-title">{event.title}</h1> : null}
              <Facts event={event} compact />
            </>
          ) : event.form ? (
            <>
              <h2 className="ep-event-card__heading">{reg.state === "waitlist" ? "Join the waiting list" : "Registration form"}</h2>
              <p className="ep-muted mb-5 text-sm">
                Fields marked <span className="ep-req">*</span> are required.
              </p>
            </>
          ) : null}
          <RegistrationForm event={event} />
        </div>
      </section>
    </main>
  );
}

function Facts({ event, compact }: { event: PublicEvent; compact?: boolean }) {
  if (!event.date_text && !event.time_text && !event.venue) return null;
  return (
    <dl className={`ep-facts${compact ? " ep-facts--compact" : ""}`}>
      {event.date_text ? (
        <div>
          <dt>Date</dt>
          <dd>{event.date_text}</dd>
        </div>
      ) : null}
      {event.time_text ? (
        <div>
          <dt>Time</dt>
          <dd>{event.time_text}</dd>
        </div>
      ) : null}
      {event.venue ? (
        <div>
          <dt>Venue</dt>
          <dd>
            {event.venue}
            {event.venue_map_url ? (
              <>
                {" · "}
                <a href={event.venue_map_url} target="_blank" rel="noopener noreferrer">
                  View map
                </a>
              </>
            ) : null}
          </dd>
        </div>
      ) : null}
    </dl>
  );
}
