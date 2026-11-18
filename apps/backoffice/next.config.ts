import type { NextConfig } from "next";

// The backoffice runs at the server root (/login, /overview, …). BACKOFFICE_BASE_PATH can move it under a prefix
// later; leave it empty for now. Pages other than sign-in and setup answer 404 to anyone signed out (src/proxy.ts).
const basePath = (process.env.BACKOFFICE_BASE_PATH ?? "").replace(/\/$/, "");
if (basePath && !/^\/[a-z0-9-]{1,64}$/.test(basePath)) {
  throw new Error("BACKOFFICE_BASE_PATH must be empty or a single path segment like /admin.");
}
const apiUrl = (process.env.API_URL ?? "http://127.0.0.1:8000").replace(/\/$/, "");

const nextConfig: NextConfig = {
  basePath,
  poweredByHeader: false,
  env: { NEXT_PUBLIC_BASE_PATH: basePath },
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  // The browser only ever talks to this server: /api/* and /media/* are passed on to Laravel,
  // so the API address is never exposed and cookies stay on this origin.
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${apiUrl}/api/admin/:path*` },
      { source: "/media/:path*", destination: `${apiUrl}/storage/media/:path*` },
    ];
  },
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Robots-Tag", value: "noindex, nofollow, noarchive, nosnippet" },
          // no-referrer: backoffice addresses are never sent to other sites when a link is clicked.
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Cache-Control", value: "private, no-store" },
        ],
      },
    ];
  },
};

export default nextConfig;
