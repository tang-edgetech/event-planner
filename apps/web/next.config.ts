import type { NextConfig } from "next";

// Public site: only exact event (/event/{slug}) and microsite (/e/{slug}) addresses show content.
// Nothing here may be listed or indexed by search engines.
const apiUrl = (process.env.API_URL ?? "http://127.0.0.1:8000").replace(/\/$/, "");
// The backoffice may show microsite previews in a frame; nobody else may frame this site.
const backoffice = process.env.BACKOFFICE_ORIGIN ?? "http://localhost:4004";

const nextConfig: NextConfig = {
  poweredByHeader: false,
  images: { unoptimized: true },
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  async rewrites() {
    return [{ source: "/media/:path*", destination: `${apiUrl}/storage/media/:path*` }];
  },
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Robots-Tag", value: "noindex, nofollow, noarchive" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "Content-Security-Policy", value: `frame-ancestors 'self' ${backoffice}` },
        ],
      },
    ];
  },
};

export default nextConfig;
