"use client";

import { Form, Input, Select, Switch } from "antd";
import { findTheme, THEMES, type EpTheme } from "@/theme/themes";
import { SettingsForm } from "../use-settings";

/** Settings → Appearance: theme (dropdown with a live preview beside it) and menu position. */
export default function AppearanceSettings() {
  return (
    <SettingsForm group="appearance">
      {(form) => (
        <>
          <Form.Item noStyle shouldUpdate>
            {() => {
              const theme = findTheme(form.getFieldValue("theme"));
              const pos = (form.getFieldValue("menu_position") as string) ?? "sidebar";
              return (
                <div className="mb-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_440px]">
                  <div>
                    <Form.Item label="Theme" name="theme" extra="Built-in themes are locked, so the default can never be broken.">
                      <Select options={THEMES.map((t) => ({ value: t.key, label: t.label }))} />
                    </Form.Item>
                    <div className="mb-4 flex flex-wrap gap-1.5" aria-label="Theme colours">
                      {[theme.primary, theme.accent, theme.ink, theme.ground, theme.border].map((c) => (
                        <span key={c} title={c} className="size-7 rounded-md" style={{ background: c, boxShadow: "inset 0 0 0 1px rgba(0,0,0,.12)" }} />
                      ))}
                    </div>
                    <Form.Item name="menu_position" hidden>
                      <Input />
                    </Form.Item>
                    <Form.Item label="Menu position">
                      <div className="grid max-w-md grid-cols-2 gap-3">
                        {(["sidebar", "top"] as const).map((p) => (
                          <button
                            key={p}
                            type="button"
                            onClick={() => form.setFieldValue("menu_position", p)}
                            aria-pressed={pos === p}
                            className="flex cursor-pointer items-center gap-3 rounded-lg border bg-transparent p-3 text-left"
                            style={{ borderColor: pos === p ? "var(--ep-primary)" : "var(--ep-border)", borderWidth: pos === p ? 2 : 1, color: "var(--ep-ink)" }}
                          >
                            <span className="flex h-11 w-16 overflow-hidden rounded border" style={{ flexDirection: p === "top" ? "column" : "row", borderColor: "var(--ep-border)" }}>
                              <span style={{ background: "var(--ep-primary)", ...(p === "top" ? { height: 10 } : { width: 16 }) }} />
                              <span className="flex-1" style={{ background: "var(--ep-ground)" }} />
                            </span>
                            <span className="text-sm font-semibold">
                              {p === "sidebar" ? "Sidebar" : "Top bar"}
                              <span className="ep-muted block text-xs font-normal">{p === "sidebar" ? "Default" : "Option 2"}</span>
                            </span>
                          </button>
                        ))}
                      </div>
                    </Form.Item>
                    <Form.Item label="Let users choose their own theme" name="allow_user_theme" valuePropName="checked" extra="Shown in each user's My account. Their choice only changes their own screen.">
                      <Switch />
                    </Form.Item>
                  </div>
                  <ThemePreview theme={theme} menu={pos === "top" ? "top" : "sidebar"} />
                </div>
              );
            }}
          </Form.Item>
        </>
      )}
    </SettingsForm>
  );
}

function ThemePreview({ theme: t, menu }: { theme: EpTheme; menu: "sidebar" | "top" }) {
  const nav = ["Overview", "Events", "Applicants", "Settings"];
  return (
    <div>
      <div className="ep-muted mb-1.5 text-xs font-semibold uppercase tracking-wide">Preview</div>
      <div className="flex h-[260px] overflow-hidden rounded-lg border" style={{ background: t.ground, borderColor: t.border, flexDirection: menu === "top" ? "column" : "row", fontFamily: t.font, color: t.ink }}>
        <div className="flex shrink-0 gap-1 p-2" style={{ background: t.navBg, flexDirection: menu === "top" ? "row" : "column", width: menu === "top" ? "100%" : 104, alignItems: menu === "top" ? "center" : "stretch", borderRight: menu === "top" ? 0 : `1px solid ${t.border}`, borderBottom: menu === "top" ? `1px solid ${t.border}` : 0 }}>
          <span className="flex items-center gap-1.5 px-1 pb-1 text-[10px] font-bold" style={{ color: t.navInk }}>
            <span className="size-3 rounded-sm" style={{ background: t.primary, boxShadow: `inset 0 0 0 2px ${t.accent}` }} />
            Event Plus
          </span>
          {nav.map((n, i) => (
            <span key={n} className="rounded px-1.5 py-1 text-[9px]" style={{ background: i === 1 ? t.navActiveBg : "transparent", color: i === 1 ? t.navActiveInk : t.navInk, fontWeight: i === 1 ? 700 : 400 }}>
              {n}
            </span>
          ))}
        </div>
        <div className="flex min-w-0 flex-1 flex-col gap-2 p-3">
          <div className="flex items-center justify-between">
            <span className="text-sm font-bold">Events</span>
            <span className="px-2 py-1 text-[9px] font-bold" style={{ background: t.primary, color: t.dark ? "#042F2E" : "#fff", borderRadius: t.radius / 2 }}>
              New event
            </span>
          </div>
          <div className="grid grid-cols-3 gap-1.5">
            {[
              ["Open", "6"],
              ["Applicants", "412"],
              ["Seats left", "138"],
            ].map(([l, v]) => (
              <div key={l} className="px-2 py-1.5" style={{ background: t.card, border: `1px solid ${t.border}`, borderTop: `2px solid ${t.primary}`, borderRadius: t.radius / 2 }}>
                <div className="text-[8px]" style={{ color: t.muted }}>
                  {l}
                </div>
                <div className="text-sm font-bold">{v}</div>
              </div>
            ))}
          </div>
          <div className="flex flex-1 flex-col px-2 py-1 text-[9px]" style={{ background: t.card, border: `1px solid ${t.border}`, borderRadius: t.radius / 2 }}>
            {["Cardio Update Symposium", "Oncology Masterclass", "Respiratory Roundtable"].map((e, i) => (
              <div key={e} className="flex items-center justify-between py-1.5" style={{ borderBottom: i < 2 ? `1px solid ${t.border}` : 0 }}>
                <span>{e}</span>
                <span className="h-3 w-5 rounded-full" style={{ background: i < 2 ? t.primary : t.border }} />
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
