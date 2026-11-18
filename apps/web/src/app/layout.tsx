import type { Metadata, Viewport } from "next";
import { DM_Sans, Sora } from "next/font/google";
import { Preloader, TopProgress } from "@/components/page-loader";
import { getSite, media } from "@/lib/public-api";
import "./globals.css";

const body = DM_Sans({ subsets: ["latin"], variable: "--font-body" });
const display = Sora({ subsets: ["latin"], weight: ["600", "700"], variable: "--font-display" });

/** Favicon from the backoffice (Settings → General); pages set their own titles. */
export async function generateMetadata(): Promise<Metadata> {
  const site = await getSite();
  return {
    title: site?.site_name || "Event Plus",
    robots: { index: false, follow: false, nocache: true },
    ...(site?.favicon ? { icons: { icon: media(site.favicon) } } : {}),
  };
}

export const viewport: Viewport = { width: "device-width", initialScale: 1 };

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${body.variable} ${display.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col">
        <Preloader />
        <TopProgress />
        <div className="ep-page-fade flex flex-1 flex-col">{children}</div>
      </body>
    </html>
  );
}
