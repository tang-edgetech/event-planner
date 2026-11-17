"use client";

import { useCan } from "@/lib/session";
import { NotFoundView, PageHead, RouteTabs } from "@/components/ui";

/** /settings/general | security | email | appearance | logs | extra — each tab is its own address. */
export default function SettingsLayout({ children }: { children: React.ReactNode }) {
  const can = useCan();
  if (!can("settings.manage")) return <NotFoundView />;
  return (
    <>
      <PageHead title="Settings" sub="System-wide options. Every change is recorded in the audit log." />
      <RouteTabs
        items={[
          { href: "/settings/general", label: "General" },
          { href: "/settings/security", label: "Security" },
          { href: "/settings/email", label: "Email / SMTP" },
          { href: "/settings/appearance", label: "Appearance" },
          { href: "/settings/logs", label: "Logs" },
          { href: "/settings/extra", label: "Extra" },
        ]}
      />
      {children}
    </>
  );
}
