import { NextResponse, type NextRequest } from "next/server";
import { API_URL } from "@/lib/public-api";

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/**
 * Receives the registration form (same origin, background request) and passes it to the API
 * with the shared key and the visitor's IP, browser and campaign source.
 */
export async function POST(request: NextRequest, { params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  if (!SLUG.test(slug)) {
    return NextResponse.json({ ok: false, message: "Not found." }, { status: 404 });
  }
  let body: Record<string, unknown>;
  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return NextResponse.json({ ok: false, message: "Invalid request." }, { status: 400 });
  }
  const { _visitor, _source, ...fields } = body;
  const ip = (request.headers.get("x-forwarded-for") ?? "").split(",")[0].trim() || request.headers.get("x-real-ip") || "";

  try {
    const res = await fetch(`${API_URL}/api/public/events/${slug}/register`, {
      method: "POST",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Internal-Key": process.env.INTERNAL_API_KEY ?? "",
        "X-Client-Ip": ip,
        "X-Client-Agent": request.headers.get("user-agent") ?? "",
        "X-Client-Visitor": typeof _visitor === "string" ? _visitor.slice(0, 64) : "",
        "X-Client-Source": JSON.stringify(_source && typeof _source === "object" ? _source : {}),
      },
      body: JSON.stringify(fields),
    });
    if (res.status === 404) {
      return NextResponse.json({ ok: false, message: "This event is not available." }, { status: 404 });
    }
    if (res.status === 429) {
      return NextResponse.json({ ok: false, message: "Too many attempts. Please wait a minute and try again." }, { status: 429 });
    }
    const json = await res.json();
    return NextResponse.json(json, { status: res.status });
  } catch {
    return NextResponse.json({ ok: false, message: "The registration service is not reachable. Please try again." }, { status: 502 });
  }
}
