import { theme as antdTheme, type ThemeConfig } from "antd";

/** Prefix for every Ant Design class (ep-ant-btn, ep-ant-table…), so they never collide with Tailwind or our ep- classes. */
export const ANT_PREFIX = "ep-ant";

/** A built-in colour scheme. Built-in schemes are locked; Settings → Appearance picks one. */
export type EpTheme = {
  key: string;
  label: string;
  dark?: boolean;
  primary: string;
  accent: string;
  ink: string;
  muted: string;
  ground: string;
  card: string;
  border: string;
  navBg: string;
  navInk: string;
  navActiveBg: string;
  navActiveInk: string;
  radius: number;
  font: string;
};

export const THEMES: EpTheme[] = [
  {
    key: "burgundy",
    label: "Burgundy Editorial (default)",
    primary: "#7A0F3D",
    accent: "#C8963E",
    ink: "#2A1A20",
    muted: "#75636A",
    ground: "#F7F3EF",
    card: "#FFFFFF",
    border: "#E9DFD8",
    navBg: "#7A0F3D",
    navInk: "#F3D9E3",
    navActiveBg: "#FFFFFF",
    navActiveInk: "#7A0F3D",
    radius: 10,
    font: "var(--font-dm-sans), system-ui, sans-serif",
  },
  {
    key: "clinical",
    label: "Clinical Trust",
    primary: "#0E5E6F",
    accent: "#12A38A",
    ink: "#0F2A33",
    muted: "#557079",
    ground: "#F2F6F7",
    card: "#FFFFFF",
    border: "#D9E3E6",
    navBg: "#0F2A33",
    navInk: "#B7CBD1",
    navActiveBg: "#17414D",
    navActiveInk: "#FFFFFF",
    radius: 6,
    font: "var(--font-plex), system-ui, sans-serif",
  },
  {
    key: "saas",
    label: "Clean SaaS",
    primary: "#4F46E5",
    accent: "#0EA5E9",
    ink: "#111827",
    muted: "#6B7280",
    ground: "#F6F7F9",
    card: "#FFFFFF",
    border: "#E5E7EB",
    navBg: "#FFFFFF",
    navInk: "#374151",
    navActiveBg: "#EEF2FF",
    navActiveInk: "#4338CA",
    radius: 8,
    font: "var(--font-inter), system-ui, sans-serif",
  },
  {
    key: "bento",
    label: "Soft Bento",
    primary: "#5B3FD9",
    accent: "#FF8A5B",
    ink: "#1F1B2E",
    muted: "#6B6478",
    ground: "#F4F1EC",
    card: "#FFFFFF",
    border: "#ECE7DF",
    navBg: "#FFFFFF",
    navInk: "#57506A",
    navActiveBg: "#5B3FD9",
    navActiveInk: "#FFFFFF",
    radius: 14,
    font: "var(--font-jakarta), system-ui, sans-serif",
  },
  {
    key: "midnight",
    label: "Midnight Ops (dark)",
    dark: true,
    primary: "#2DD4BF",
    accent: "#A3E635",
    ink: "#E6EAF2",
    muted: "#94A3B8",
    ground: "#070B16",
    card: "#121A2E",
    border: "#24304A",
    navBg: "#0B1020",
    navInk: "#A3AEC2",
    navActiveBg: "#12302F",
    navActiveInk: "#5EEAD4",
    radius: 10,
    font: "var(--font-manrope), system-ui, sans-serif",
  },
];

export const findTheme = (key?: string | null) => THEMES.find((t) => t.key === key) ?? THEMES[0];

/** Ant Design theme for a scheme. */
export function antTheme(t: EpTheme): ThemeConfig {
  return {
    algorithm: t.dark ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
    token: {
      colorPrimary: t.primary,
      colorLink: t.primary,
      colorText: t.ink,
      colorTextSecondary: t.muted,
      colorBorder: t.border,
      colorBorderSecondary: t.border,
      colorBgLayout: t.ground,
      colorBgContainer: t.card,
      borderRadius: t.radius,
      fontFamily: t.font,
      ...(t.dark ? {} : { colorSuccess: "#1E6B35", colorWarning: "#B26A00", colorError: "#B42335" }),
    },
    components: {
      Layout: { siderBg: t.navBg, headerBg: t.card, bodyBg: t.ground },
      Button: { primaryColor: t.dark ? "#042F2E" : "#FFFFFF" },
    },
  };
}

/** CSS variables for our own ep- classes (shell, menus, cards). */
export function themeVars(t: EpTheme): Record<string, string> {
  return {
    "--ep-primary": t.primary,
    "--ep-accent": t.accent,
    "--ep-ink": t.ink,
    "--ep-muted": t.muted,
    "--ep-ground": t.ground,
    "--ep-card": t.card,
    "--ep-border": t.border,
    "--ep-nav-bg": t.navBg,
    "--ep-nav-ink": t.navInk,
    "--ep-nav-active-bg": t.navActiveBg,
    "--ep-nav-active-ink": t.navActiveInk,
    "--ep-radius": `${t.radius}px`,
    "--ep-font": t.font,
  };
}
