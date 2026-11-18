"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { App, Avatar, Button, Drawer, Dropdown, Modal, Spin, Typography } from "antd";
import {
  AppstoreOutlined,
  AuditOutlined,
  BarChartOutlined,
  CalendarOutlined,
  DashboardOutlined,
  ExportOutlined,
  GlobalOutlined,
  LogoutOutlined,
  MenuOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  TeamOutlined,
  UserOutlined,
} from "@ant-design/icons";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { setDateFormat } from "@/lib/format";
import { MeContext, useMeQuery } from "@/lib/session";
import type { Me, Permission } from "@/lib/types";
import { useEpTheme } from "./providers";
import { IdleGuard } from "./idle-guard";
import { NotFoundView } from "./ui";
import { PageFade } from "./page-transition";
import { BrandMark, useFavicon } from "./brand";
import { PasswordForm, TwoFactorManager } from "./account-forms";

type NavItem = { href: string; label: string; icon: React.ReactNode; perm?: Permission; developer?: boolean; group: "Manage" | "System" };

const NAV: NavItem[] = [
  { href: "/overview", label: "Overview", icon: <DashboardOutlined />, perm: "dashboard.view", group: "Manage" },
  { href: "/events", label: "Events", icon: <CalendarOutlined />, perm: "events.view", group: "Manage" },
  { href: "/microsites", label: "Microsites", icon: <AppstoreOutlined />, perm: "microsites.view", group: "Manage" },
  { href: "/applicants", label: "Applicants", icon: <TeamOutlined />, perm: "applicants.view", group: "Manage" },
  { href: "/reports/overview", label: "Reports", icon: <BarChartOutlined />, perm: "reports.view", group: "Manage" },
  { href: "/users", label: "Users", icon: <UserOutlined />, perm: "users.view", group: "System" },
  { href: "/roles", label: "Roles & permissions", icon: <SafetyCertificateOutlined />, developer: true, group: "System" },
  { href: "/audit-logs", label: "Audit logs", icon: <AuditOutlined />, perm: "audit.view", group: "System" },
  { href: "/settings/general", label: "Settings", icon: <SettingOutlined />, perm: "settings.manage", group: "System" },
];

/** Signed-in layout: menu (sidebar or top bar), header, idle sign-out, forced password / 2FA steps. */
export function AdminShell({ children }: { children: React.ReactNode }) {
  const { data: me, isLoading, isError } = useMeQuery();
  const { setThemeKey } = useEpTheme();
  const router = useRouter();
  const [ended, setEnded] = useState<string | null>(null);
  const [gate, setGate] = useState<string | null>(null);

  useEffect(() => {
    if (!me) return;
    setThemeKey(me.settings.allow_user_theme && me.user.theme ? me.user.theme : me.settings.theme);
    setDateFormat(me.settings.date_format);
  }, [me, setThemeKey]);

  useEffect(() => {
    const out = (e: Event) => setEnded((prev) => prev ?? (e as CustomEvent<string>).detail ?? "unauthenticated");
    const lock = (e: Event) => setGate((e as CustomEvent<string>).detail ?? null);
    window.addEventListener("ep:signed-out", out);
    window.addEventListener("ep:account-gate", lock);
    return () => {
      window.removeEventListener("ep:signed-out", out);
      window.removeEventListener("ep:account-gate", lock);
    };
  }, []);

  if (isLoading) {
    return (
      <div className="ep-notice">
        <Spin size="large" />
      </div>
    );
  }
  if (isError || !me) {
    return <NotFoundView />;
  }

  const mustChange = me.user.must_change_password || gate === "password_change";
  const needs2fa = (me.settings.require_2fa && !me.user.has_two_factor) || gate === "two_factor_setup";

  return (
    <MeContext.Provider value={me}>
      {mustChange || needs2fa ? (
        <AccountGate me={me} step={mustChange ? "password" : "two_factor"} onDone={() => setGate(null)} />
      ) : (
        <Shell me={me}>{children}</Shell>
      )}
      <IdleGuard idleMinutes={me.settings.idle_minutes} onSignedOut={() => router.replace("/login")} />
      <Modal
        open={!!ended && ended !== "idle_local"}
        title="Your session has ended"
        closable={false}
        mask={{ closable: false }}
        footer={
          <Button type="primary" onClick={() => router.replace("/login")}>
            Sign in again
          </Button>
        }
      >
        <Typography.Paragraph>
          {ended === "idle"
            ? "For your security you were signed out after a period of inactivity."
            : ended === "inactive"
              ? "Your account has been switched off. Please contact an administrator."
              : "You were signed out (an administrator may have ended your session, or your password changed). Please sign in again."}
        </Typography.Paragraph>
      </Modal>
    </MeContext.Provider>
  );
}

function Shell({ me, children }: { me: Me; children: React.ReactNode }) {
  const pathname = usePathname();
  const [drawer, setDrawer] = useState(false);
  const top = me.settings.menu_position === "top";
  const items = useMemo(() => NAV.filter((n) => (!n.perm || me.permissions.includes(n.perm)) && (!n.developer || me.is_developer)), [me.permissions, me.is_developer]);
  useFavicon(me.settings.favicon);

  const isActive = (href: string) => {
    const root = "/" + href.split("/")[1];
    return pathname === href || pathname.startsWith(`${root}/`) || pathname === root;
  };

  const links = (grouped: boolean, onNavigate?: () => void) => {
    let lastGroup = "";
    return items.map((n) => {
      const heading = grouped && n.group !== lastGroup ? <div className="ep-nav__group">{n.group}</div> : null;
      lastGroup = n.group;
      return (
        <div key={n.href} className="contents">
          {heading}
          <Link href={n.href} className={`ep-nav__item${isActive(n.href) ? " is-active" : ""}`} onClick={onNavigate} aria-current={isActive(n.href) ? "page" : undefined}>
            {n.icon}
            <span>{n.label}</span>
          </Link>
        </div>
      );
    });
  };

  const brand = (
    <Link href="/overview" className="ep-brand no-underline">
      <BrandMark logo={me.settings.logo} name={me.settings.site_name} />
    </Link>
  );

  return (
    <div className={`ep-shell${top ? " ep-shell--top" : ""}`}>
      {top ? (
        <nav className="ep-topnav hidden lg:flex" aria-label="Main menu">
          {brand}
          {links(false)}
        </nav>
      ) : (
        <aside className="ep-sider" aria-label="Main menu">
          {brand}
          <nav className="ep-nav">{links(true)}</nav>
        </aside>
      )}
      <div className="ep-main">
        <header className="ep-header">
          <div className="flex min-w-0 items-center gap-2">
            <Button className="lg:!hidden" type="text" icon={<MenuOutlined />} aria-label="Open menu" onClick={() => setDrawer(true)} />
            <span className="truncate font-semibold lg:hidden">{me.settings.site_name}</span>
          </div>
          <UserMenu me={me} />
        </header>
        <main className="ep-content">
          <PageFade>{children}</PageFade>
        </main>
      </div>
      <Drawer open={drawer} onClose={() => setDrawer(false)} placement="left" size={280} title={me.settings.site_name} styles={{ body: { padding: 0, background: "var(--ep-nav-bg)" } }}>
        <nav className="ep-nav pt-3">{links(true, () => setDrawer(false))}</nav>
      </Drawer>
    </div>
  );
}

function UserMenu({ me }: { me: Me }) {
  const router = useRouter();
  const qc = useQueryClient();
  const { message } = App.useApp();

  const signOut = async () => {
    try {
      await api.post("/auth/logout");
    } catch {}
    qc.clear();
    message.success("Signed out");
    router.replace("/login");
  };

  return (
    <Dropdown
      trigger={["click"]}
      menu={{
        items: [
          { key: "who", label: <span className="ep-muted text-xs">{me.user.role_label}</span>, disabled: true },
          { key: "account", icon: <UserOutlined />, label: <Link href={`/users/${me.user.id}/account`}>My account</Link> },
          { key: "site", icon: <GlobalOutlined />, label: <a href={me.settings.web_url} target="_blank" rel="noreferrer">Public site <ExportOutlined /></a> },
          { type: "divider" },
          { key: "out", icon: <LogoutOutlined />, label: "Sign out", onClick: signOut },
        ],
      }}
    >
      <Button type="text" className="!flex items-center gap-2" aria-label="Account menu">
        <Avatar size="small" style={{ background: "var(--ep-primary)" }}>
          {me.user.name.slice(0, 1).toUpperCase()}
        </Avatar>
        <span className="hidden max-w-[160px] truncate sm:inline">{me.user.name}</span>
      </Button>
    </Dropdown>
  );
}

/** Full-screen step that must be finished before using the backoffice. */
function AccountGate({ me, step, onDone }: { me: Me; step: "password" | "two_factor"; onDone: () => void }) {
  const qc = useQueryClient();
  const router = useRouter();
  const done = async () => {
    await qc.invalidateQueries({ queryKey: ["me"] });
    onDone();
  };
  return (
    <main className="ep-auth">
      <div className="ep-auth__card !max-w-xl">
        <div className="ep-auth__brand">
          <BrandMark logo={me.settings.logo} name={me.settings.site_name} />
        </div>
        {step === "password" ? (
          <>
            <Typography.Title level={4}>Choose a new password</Typography.Title>
            <Typography.Paragraph className="ep-muted">Before continuing, please replace the temporary password with your own.</Typography.Paragraph>
            <PasswordForm self onDone={done} />
          </>
        ) : (
          <>
            <Typography.Title level={4}>Set up two-factor authentication</Typography.Title>
            <Typography.Paragraph className="ep-muted">Your organisation requires a code from an authenticator app when signing in. Link at least one device to continue.</Typography.Paragraph>
            <TwoFactorManager self userId={me.user.id} onChanged={done} />
          </>
        )}
        <Button
          type="link"
          className="mt-4 !px-0"
          onClick={async () => {
            await api.post("/auth/logout").catch(() => {});
            qc.clear();
            router.replace("/login");
          }}
        >
          Sign out
        </Button>
      </div>
    </main>
  );
}
