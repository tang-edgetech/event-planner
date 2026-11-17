import { NextResponse, type NextRequest } from "next/server";

/**
 * Without a session cookie, every backoffice page except sign-in and setup answers a plain 404,
 * the same as an unknown address. (The pages also check the session with the API.)
 */
export function proxy(request: NextRequest) {
  if (request.cookies.has(process.env.SESSION_COOKIE ?? "ep_bo_session")) {
    return NextResponse.next();
  }
  const url = request.nextUrl.clone();
  url.pathname = "/__not-found";
  return NextResponse.rewrite(url, { status: 404 });
}

export const config = {
  // Everything except: the sign-in and setup pages, the entry address, API/media pass-through and Next.js assets.
  matcher: ["/((?!login|setup|api/|media/|_next/|favicon\\.ico|__not-found).+)"],
};
