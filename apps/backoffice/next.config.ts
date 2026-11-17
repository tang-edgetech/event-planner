import type { NextConfig } from "next";

// The backoffice lives only under a secret address (BACKOFFICE_BASE_PATH in .env.local, different per server).
// Everything outside it, including the server root, answers 404. Nothing here may be indexed or linked.
const basePath = process.env.BACKOFFICE_BASE_PATH ?? "";
if (!/^\/[a-z0-9]{12,64}$/.test(basePath)) {
  throw new Error(
    "BACKOFFICE_BASE_PATH must be set in .env.local to a secret like /k7m2p9x4q8r3t6w5yz (lower-case letters and digits, 12-64 long).",
  );
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
  // The browser only ever talks to this server: {secret}/api/* and {secret}/media/* are passed on to Laravel,
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
          // no-referrer: the secret address is never sent to other sites when a link is clicked.
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
