import { connection } from "next/server";
import { notFound } from "next/navigation";
import { getSite } from "@/lib/public-api";

/**
 * The homepage never lists anything: a maintenance notice, or a 404 (Settings → Extra → homepage).
 * Events and microsites are reached only by their exact address.
 */
export default async function Home() {
  await connection();
  const site = await getSite();
  const mode = site?.home_mode ?? process.env.HOME_MODE ?? "maintenance";
  if (mode === "notfound") {
    notFound();
  }
  return (
    <main className="ep-notice">
      <h1 className="ep-notice__title">{site?.maintenance_title || "This site is under maintenance"}</h1>
      <p className="ep-notice__text">{site?.maintenance_message || "Please check back later."}</p>
      {site?.contact_email ? (
        <p className="ep-notice__text">
          <a href={`mailto:${site.contact_email}`}>{site.contact_email}</a>
        </p>
      ) : null}
    </main>
  );
}
