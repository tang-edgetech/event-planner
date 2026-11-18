import type { Metadata } from "next";
import { getPublic, media, visitSource } from "@/lib/public-api";
import type { PublicMicrosite } from "@/lib/types";
import { MicrositeSections } from "@/components/microsite-sections";
import { Reveal, SiteNav } from "@/components/microsite-client";
import { hexColor } from "@/lib/colors";

type Props = { params: Promise<{ slug: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

const previewOf = (sp: Record<string, string | string[] | undefined>) => (typeof sp.preview === "string" && /^[A-Za-z0-9]{20,64}$/.test(sp.preview) ? sp.preview : undefined);

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { slug } = await params;
  const preview = previewOf(await searchParams);
  const site = await getPublic<PublicMicrosite>("microsites", slug, preview ? { preview } : {});
  return { title: site.preview ? `Preview · ${site.title}` : site.title, robots: { index: false, follow: false } };
}

/** Microsite: /e/{slug}. "?preview=…" shows unsaved changes from the backoffice for 30 minutes. */
export default async function MicrositePage({ params, searchParams }: Props) {
  const { slug } = await params;
  const sp = await searchParams;
  const preview = previewOf(sp);
  const site = await getPublic<PublicMicrosite>("microsites", slug, preview ? { preview } : { track: "1" }, preview ? undefined : await visitSource(sp));
  const s = site.settings;
  const accent = typeof s.accent_color === "string" && s.accent_color ? s.accent_color : undefined;
  const style = {
    ...(accent ? { "--ep-accent": accent } : {}),
    ...(hexColor(s.button_bg) ? { "--ep-btn-bg": hexColor(s.button_bg) } : {}),
    ...(hexColor(s.button_text) ? { "--ep-btn-fg": hexColor(s.button_text) } : {}),
    ...(typeof s.page_bg === "string" && s.page_bg ? { background: s.page_bg } : {}),
  } as React.CSSProperties;
  const navItems = site.sections
    .filter((x) => typeof x.settings.nav_label === "string" && x.settings.nav_label && typeof x.settings.anchor === "string" && x.settings.anchor)
    .map((x) => ({ label: String(x.settings.nav_label), href: `#${String(x.settings.anchor)}` }));
  const cta = typeof s.nav_cta_label === "string" && s.nav_cta_label && typeof s.nav_cta_url === "string" ? { label: s.nav_cta_label, href: s.nav_cta_url } : undefined;

  return (
    <div className="ep-microsite" style={style} id="top">
      {site.preview ? <div className="ep-preview-bar">Preview of unsaved changes · visitors do not see this yet</div> : null}
      {s.show_nav !== false && (navItems.length || cta || s.nav_logo) ? <SiteNav logo={typeof s.nav_logo === "string" ? media(s.nav_logo) : undefined} title={site.title} items={navItems} cta={cta} /> : null}
      <main>
        <MicrositeSections site={site} />
      </main>
      <footer className="ep-site-footer">{typeof s.footer_text === "string" ? s.footer_text : ""}</footer>
      {typeof s.custom_css === "string" && s.custom_css ? <style>{s.custom_css}</style> : null}
      {s.animations === false ? null : <Reveal />}
    </div>
  );
}
