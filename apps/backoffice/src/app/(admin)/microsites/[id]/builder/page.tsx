"use client";

import { App, Button, Collapse, Dropdown, Empty, Segmented, Space, Tag, Tooltip, Typography } from "antd";
import {
  ArrowDownOutlined,
  ArrowUpOutlined,
  CopyOutlined,
  DeleteOutlined,
  DesktopOutlined,
  DownloadOutlined,
  EyeInvisibleOutlined,
  EyeOutlined,
  ImportOutlined,
  MobileOutlined,
  PlusOutlined,
  ReloadOutlined,
  SaveOutlined,
  TabletOutlined,
} from "@ant-design/icons";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import { useCan, useMe } from "@/lib/session";
import type { Paged, Section } from "@/lib/types";
import { downloadJson, TemplateImport } from "@/components/template-import";
import { APPEARANCE_FIELDS, SECTION_TYPES } from "../../sections";
import { SchemaFields } from "../../schema-fields";
import { useSite } from "../../site-context";

const DEVICES = { mobile: 390, tablet: 820, desktop: 1366 } as const;
type Device = keyof typeof DEVICES;

const uid = () => (typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : String(Date.now() + Math.random()));

/** Page builder: add, arrange and edit sections, then "Preview changes" on phone, tablet or desktop before saving. */
/** Remounts with fresh state whenever the saved microsite changes. */
export default function BuilderTab() {
  const site = useSite();
  return <Builder key={site.updated_at} />;
}

function Builder() {
  const site = useSite();
  const me = useMe();
  const can = useCan();
  const qc = useQueryClient();
  const { message, modal } = App.useApp();
  const editable = can("microsites.manage");
  const [sections, setSections] = useState<Section[]>(site.sections ?? []);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [open, setOpen] = useState<string[]>([]);
  const [device, setDevice] = useState<Device>("desktop");
  const [previewSrc, setPreviewSrc] = useState<string | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const boxRef = useRef<HTMLDivElement>(null);
  const [boxWidth, setBoxWidth] = useState(600);
  const [importing, setImporting] = useState(false);
  const { data: siteOptions } = useQuery({
    queryKey: ["microsite-options"],
    queryFn: async () => (await api.get<Paged<{ id: number; title: string }>>("/microsites", { params: { per_page: 100 } })).data.data.map((m) => ({ value: m.id, label: m.title })),
    enabled: importing,
  });

  const exportTemplate = () => {
    downloadJson(`${site.slug}-page.json`, { kind: "microsite", version: 1, exported_at: new Date().toISOString(), source: site.title, sections });
    message.success("Template downloaded. Import it into any microsite's Page builder.");
  };
  const applyTemplate = (incoming: Section[], mode: "replace" | "append") => {
    const fresh = incoming.map((s) => ({ ...structuredClone(s), id: uid() }));
    update(mode === "replace" ? fresh : [...sections, ...fresh]);
    setOpen([]);
    message.success(`Imported ${fresh.length} section(s). Preview them, then press Save.`);
  };

  useEffect(() => {
    const el = boxRef.current;
    if (!el) return;
    const ro = new ResizeObserver(() => setBoxWidth(el.clientWidth));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => {
      if (dirty) e.preventDefault();
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  const update = (next: Section[]) => {
    setSections(next);
    setDirty(true);
  };
  const patch = (id: string, p: Partial<Section>) => update(sections.map((s) => (s.id === id ? { ...s, ...p } : s)));
  const move = (i: number, d: number) => {
    const next = [...sections];
    [next[i], next[i + d]] = [next[i + d], next[i]];
    update(next);
  };
  const add = (layout: string, at = sections.length) => {
    const def = SECTION_TYPES[layout];
    const s: Section = { id: uid(), layout, data: structuredClone(def.defaults.data), settings: { container: "default", text_scheme: "auto", ...structuredClone(def.defaults.settings) } };
    const next = [...sections];
    next.splice(at, 0, s);
    update(next);
    setOpen([s.id]);
  };

  const save = async () => {
    setSaving(true);
    try {
      const res = await api.put(`/microsites/${site.id}`, { sections });
      qc.setQueryData(["microsite", String(site.id)], res.data.data);
      setDirty(false);
      message.success(site.status === "published" ? "Saved. The changes are live." : "Saved");
      if (previewSrc) preview();
    } catch (e) {
      message.error(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  const preview = async () => {
    setPreviewing(true);
    try {
      const res = await api.post<{ path: string }>(`/microsites/${site.id}/preview`, { sections });
      setPreviewSrc(`${me.settings.web_url}${res.data.path}&t=${Date.now()}`);
    } catch (e) {
      message.error(errorMessage(e, "Preview failed."));
    } finally {
      setPreviewing(false);
    }
  };

  const addMenu = (at?: number) => ({
    items: Object.entries(SECTION_TYPES).map(([key, d]) => ({
      key,
      label: (
        <div className="py-0.5">
          <div className="font-medium">{d.label}</div>
          <div className="ep-muted text-xs">{d.description}</div>
        </div>
      ),
      onClick: () => add(key, at),
    })),
    style: { maxHeight: 420, overflowY: "auto" as const },
  });

  const width = DEVICES[device];
  const scale = Math.min(1, boxWidth / width);

  return (
    <div className="ep-builder">
      <TemplateImport<Section[]>
        open={importing}
        onClose={() => setImporting(false)}
        what="page"
        itemNoun="microsite"
        sources={(siteOptions ?? []).filter((o) => o.value !== site.id)}
        loadFrom={async (id) => (await api.get<{ data: { sections: Section[] } }>(`/microsites/${id}`)).data.data.sections ?? []}
        parse={parsePageTemplate}
        describe={(t) => `${t.length} section(s)`}
        onApply={applyTemplate}
      />
      <div className="min-w-0">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          {editable ? (
            <Dropdown menu={addMenu()} trigger={["click"]}>
              <Button icon={<PlusOutlined />}>Add section</Button>
            </Dropdown>
          ) : null}
          {editable ? (
            <Button icon={<ImportOutlined />} onClick={() => setImporting(true)}>
              Import template
            </Button>
          ) : null}
          <Tooltip title="Download these sections as a template file">
            <Button icon={<DownloadOutlined />} onClick={exportTemplate} aria-label="Export template">
              Export
            </Button>
          </Tooltip>
          <div className="flex-1" />
          {dirty ? <Tag color="gold">Unsaved changes</Tag> : null}
          <Button icon={<EyeOutlined />} onClick={preview} loading={previewing}>
            Preview changes
          </Button>
          {editable ? (
            <Button type="primary" icon={<SaveOutlined />} onClick={save} loading={saving} disabled={!dirty}>
              Save
            </Button>
          ) : null}
        </div>
        {sections.length ? (
          <Collapse
            activeKey={open}
            onChange={(k) => setOpen(k as string[])}
            items={sections.map((s, i) => {
              const def = SECTION_TYPES[s.layout];
              const title = (s.data.heading || s.data.title || "") as string;
              return {
                key: s.id,
                label: (
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="ep-muted text-xs font-bold">{i + 1}</span>
                    <strong>{def?.label ?? s.layout}</strong>
                    {title ? <span className="ep-muted truncate">{title}</span> : null}
                    {s.settings.hidden ? <Tag icon={<EyeInvisibleOutlined />}>Hidden</Tag> : null}
                    {s.settings.nav_label ? <Tag>{String(s.settings.nav_label)}</Tag> : null}
                  </span>
                ),
                extra: editable ? (
                  <Space size={0} onClick={(e) => e.stopPropagation()}>
                    <Tooltip title="Move up">
                      <Button size="small" type="text" icon={<ArrowUpOutlined />} disabled={i === 0} onClick={() => move(i, -1)} aria-label="Move up" />
                    </Tooltip>
                    <Tooltip title="Move down">
                      <Button size="small" type="text" icon={<ArrowDownOutlined />} disabled={i === sections.length - 1} onClick={() => move(i, 1)} aria-label="Move down" />
                    </Tooltip>
                    <Tooltip title="Duplicate">
                      <Button
                        size="small"
                        type="text"
                        icon={<CopyOutlined />}
                        onClick={() => {
                          const copy = { ...structuredClone(s), id: uid() };
                          const next = [...sections];
                          next.splice(i + 1, 0, copy);
                          update(next);
                        }}
                        aria-label="Duplicate section"
                      />
                    </Tooltip>
                    <Tooltip title="Delete">
                      <Button
                        size="small"
                        type="text"
                        danger
                        icon={<DeleteOutlined />}
                        aria-label="Delete section"
                        onClick={() =>
                          modal.confirm({
                            title: `Delete the "${def?.label}" section?`,
                            okText: "Delete",
                            okButtonProps: { danger: true },
                            onOk: () => update(sections.filter((x) => x.id !== s.id)),
                          })
                        }
                      />
                    </Tooltip>
                  </Space>
                ) : null,
                children: def ? (
                  <div className="flex flex-col gap-5">
                    <SchemaFields fields={def.fields} value={s.data} onChange={(data) => patch(s.id, { data })} disabled={!editable} />
                    <details>
                      <summary className="cursor-pointer text-sm font-semibold">Section appearance</summary>
                      <div className="mt-3">
                        <SchemaFields fields={APPEARANCE_FIELDS} value={s.settings} onChange={(settings) => patch(s.id, { settings })} disabled={!editable} />
                      </div>
                    </details>
                    {editable ? (
                      <Dropdown menu={addMenu(i + 1)} trigger={["click"]}>
                        <Button type="dashed" size="small" icon={<PlusOutlined />} className="self-start">
                          Add a section below
                        </Button>
                      </Dropdown>
                    ) : null}
                  </div>
                ) : (
                  <Typography.Text type="danger">Unknown section type</Typography.Text>
                ),
              };
            })}
          />
        ) : (
          <div className="ep-card">
            <Empty description="No sections yet. Add the first one." />
          </div>
        )}
      </div>

      <div className="min-w-0 xl:sticky xl:top-20 xl:self-start">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Segmented<Device>
            value={device}
            onChange={setDevice}
            options={[
              { value: "mobile", icon: <MobileOutlined />, label: "Mobile" },
              { value: "tablet", icon: <TabletOutlined />, label: "Tablet" },
              { value: "desktop", icon: <DesktopOutlined />, label: "Desktop" },
            ]}
          />
          <span className="ep-muted text-xs">
            {width}px{scale < 1 ? ` · shown at ${Math.round(scale * 100)}%` : ""}
          </span>
          <div className="flex-1" />
          {previewSrc ? <Button size="small" icon={<ReloadOutlined />} onClick={preview} aria-label="Refresh preview" /> : null}
        </div>
        <div ref={boxRef} className="ep-card !p-2" style={{ height: 760 * Math.max(scale, 0.55) + 16, overflow: "hidden" }}>
          {previewSrc ? (
            <iframe
              key={previewSrc}
              title="Microsite preview"
              src={previewSrc}
              className="ep-preview-frame"
              style={{ width, height: 760 * Math.max(scale, 0.55) / scale, transform: `scale(${scale})`, transformOrigin: "top left" }}
            />
          ) : (
            <div className="flex h-full flex-col items-center justify-center gap-3 text-center">
              <EyeOutlined style={{ fontSize: 28 }} className="ep-muted" />
              <p className="ep-muted m-0 max-w-xs text-sm">Click “Preview changes” to see this page, including unsaved edits, as visitors will. The preview link works for 30 minutes.</p>
              <Button icon={<EyeOutlined />} onClick={preview} loading={previewing}>
                Preview changes
              </Button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/** Reads a page template file: our own export, or just a list of sections. Unknown section types are left out. */
function parsePageTemplate(json: unknown): Section[] {
  const o = (Array.isArray(json) ? { sections: json } : json) as Record<string, unknown> | null;
  if (!o || typeof o !== "object") throw new Error("This file is not a page template.");
  if (o.kind && o.kind !== "microsite") {
    throw new Error(o.kind === "event-form" ? "This is a registration form template. Import it in an event's Registration form instead." : "This file is not a page template.");
  }
  if (!Array.isArray(o.sections)) throw new Error("No sections were found in this file.");
  const sections = (o.sections as Record<string, unknown>[])
    .filter((s) => s && typeof s === "object" && typeof s.layout === "string" && s.layout in SECTION_TYPES)
    .map((s) => ({
      id: "",
      layout: s.layout as string,
      data: s.data && typeof s.data === "object" ? (s.data as Record<string, unknown>) : {},
      settings: s.settings && typeof s.settings === "object" ? (s.settings as Record<string, unknown>) : {},
    }));
  if (!sections.length) throw new Error("The file has no sections this page builder knows.");
  return sections;
}