import type { Metadata } from "next";
import { DM_Sans, IBM_Plex_Sans, Inter, Manrope, Plus_Jakarta_Sans } from "next/font/google";
import { AntdRegistry } from "@ant-design/nextjs-registry";
import { Providers } from "@/components/providers";
import { Preloader, TopProgress } from "@/components/page-transition";
import "./globals.css";

// Fonts of the built-in themes. Each is downloaded by the browser only when the active theme uses it.
const dmSans = DM_Sans({ subsets: ["latin"], variable: "--font-dm-sans" });
const plex = IBM_Plex_Sans({ subsets: ["latin"], weight: ["400", "500", "600", "700"], variable: "--font-plex", preload: false });
const inter = Inter({ subsets: ["latin"], variable: "--font-inter", preload: false });
const manrope = Manrope({ subsets: ["latin"], variable: "--font-manrope", preload: false });
const jakarta = Plus_Jakarta_Sans({ subsets: ["latin"], variable: "--font-jakarta", preload: false });

export const metadata: Metadata = {
  title: "Event Plus Backoffice",
  robots: { index: false, follow: false, nocache: true, googleBot: { index: false, follow: false } },
  referrer: "no-referrer",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  const fonts = [dmSans, plex, inter, manrope, jakarta].map((f) => f.variable).join(" ");
  return (
    <html lang="en" className={`${fonts} h-full antialiased`}>
      <body className="min-h-full">
        <AntdRegistry>
          <Preloader />
          <TopProgress />
          <Providers>{children}</Providers>
        </AntdRegistry>
      </body>
    </html>
  );
}
