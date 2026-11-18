"use client";

import { Button, Card, ColorPicker, DatePicker, Input, InputNumber, Radio, Select, Switch } from "antd";
import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PlusOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import { api } from "@/lib/api";
import { MediaListPicker, MediaPicker } from "@/components/media-picker";
import { RichText } from "@/components/rich-text";
import type { FieldDef } from "./sections";

type Obj = Record<string, unknown>;

/** Renders a list of field definitions as a controlled editor for one object (section data, settings, repeater item). */
export function SchemaFields({ fields, value, onChange, disabled }: { fields: FieldDef[]; value: Obj; onChange: (v: Obj) => void; disabled?: boolean }) {
  const set = (key: string, v: unknown) => onChange({ ...value, [key]: v });
  return (
    <div className="grid gap-x-3 gap-y-3 md:grid-cols-2">
      {fields
        .filter((f) => !f.showIf || f.showIf(value))
        .map((f) => {
          const wide = ["richtext", "repeater", "textarea", "images", "code"].includes(f.type) || f.type === "radio";
          return (
            <div key={f.key} className={wide ? "md:col-span-2" : undefined}>
              <label className="mb-1 block text-sm font-medium">{f.label}</label>
              <FieldInput f={f} value={value[f.key]} onChange={(v) => set(f.key, v)} disabled={disabled} />
              {f.help ? <div className="ep-muted mt-1 text-xs">{f.help}</div> : null}
            </div>
          );
        })}
    </div>
  );
}

function FieldInput({ f, value, onChange, disabled }: { f: FieldDef; value: unknown; onChange: (v: unknown) => void; disabled?: boolean }) {
  switch (f.type) {
    case "text":
      return <Input value={(value as string) ?? ""} onChange={(e) => onChange(e.target.value)} disabled={disabled} aria-label={f.label} />;
    case "textarea":
      return <Input.TextArea rows={3} value={(value as string) ?? ""} onChange={(e) => onChange(e.target.value)} disabled={disabled} aria-label={f.label} />;
    case "code":
      return <Input.TextArea rows={8} className="font-mono !text-xs" value={(value as string) ?? ""} onChange={(e) => onChange(e.target.value)} disabled={disabled} aria-label={f.label} />;
    case "richtext":
      return <RichText value={(value as string) ?? ""} onChange={onChange} minHeight={90} />;
    case "image":
      return <MediaPicker value={(value as string) ?? null} onChange={onChange} />;
    case "video":
      return <MediaPicker value={(value as string) ?? null} onChange={onChange} accept="video/mp4" />;
    case "images":
      return <MediaListPicker value={(value as string[]) ?? []} onChange={onChange} />;
    case "switch":
      return <Switch checked={!!value} onChange={onChange} disabled={disabled} aria-label={f.label} />;
    case "number":
      return <InputNumber value={(value as number) ?? undefined} onChange={onChange} disabled={disabled} min={0} max={360} aria-label={f.label} />;
    case "color":
      return <ColorPicker value={(value as string) || undefined} onChange={(c) => onChange(c.toHexString())} allowClear onClear={() => onChange(null)} showText format="hex" disabled={disabled} />;
    case "datetime":
      return <DatePicker showTime={{ format: "h:mm A" }} format="D MMM YYYY, h:mm A" value={value ? dayjs(value as string) : null} onChange={(d) => onChange(d ? d.toISOString() : null)} disabled={disabled} className="!w-full" />;
    case "event":
      return <EventSelect value={(value as number) ?? null} onChange={onChange} disabled={disabled} />;
    case "select":
      return <Select value={(value as string) ?? f.fallback} onChange={onChange} options={f.options} disabled={disabled} className="!w-full" aria-label={f.label} />;
    case "radio":
      return <Radio.Group optionType="button" size="small" value={(value as string) ?? f.fallback} onChange={(e) => onChange(e.target.value)} options={f.options} disabled={disabled} />;
    case "repeater":
      return <Repeater f={f} value={(value as Obj[]) ?? []} onChange={onChange} disabled={disabled} />;
    default:
      return null;
  }
}

function Repeater({ f, value, onChange, disabled }: { f: Extract<FieldDef, { type: "repeater" }>; value: Obj[]; onChange: (v: Obj[]) => void; disabled?: boolean }) {
  const move = (i: number, d: number) => {
    const next = [...value];
    [next[i], next[i + d]] = [next[i + d], next[i]];
    onChange(next);
  };
  return (
    <div className="flex flex-col gap-2">
      {value.map((item, i) => (
        <Card
          key={i}
          size="small"
          title={`${f.itemLabel} ${i + 1}${item.name || item.title || item.label ? `: ${String(item.name || item.title || item.label)}` : ""}`}
          extra={
            disabled ? null : (
              <span>
                <Button size="small" type="text" icon={<ArrowUpOutlined />} disabled={i === 0} onClick={() => move(i, -1)} aria-label="Move up" />
                <Button size="small" type="text" icon={<ArrowDownOutlined />} disabled={i === value.length - 1} onClick={() => move(i, 1)} aria-label="Move down" />
                <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => onChange(value.filter((_, j) => j !== i))} aria-label={`Remove ${f.itemLabel}`} />
              </span>
            )
          }
        >
          <SchemaFields fields={f.fields} value={item} onChange={(v) => onChange(value.map((x, j) => (j === i ? v : x)))} disabled={disabled} />
        </Card>
      ))}
      {disabled ? null : (
        <Button type="dashed" icon={<PlusOutlined />} onClick={() => onChange([...value, {}])}>
          Add {f.itemLabel.toLowerCase()}
        </Button>
      )}
    </div>
  );
}

export function EventSelect({ value, onChange, disabled }: { value: number | null; onChange: (v: number | null) => void; disabled?: boolean }) {
  const { data } = useQuery({ queryKey: ["event-options"], queryFn: async () => (await api.get<{ data: { value: number; label: string; status: string }[] }>("/events/options")).data.data, staleTime: 60_000 });
  return (
    <Select
      allowClear
      showSearch={{ optionFilterProp: "label" }}
      className="!w-full"
      value={value ?? undefined}
      onChange={(v) => onChange(v ?? null)}
      disabled={disabled}
      placeholder="Choose an event"
      options={data?.map((e) => ({ value: e.value, label: e.status === "published" ? e.label : `${e.label} (draft)` }))}
    />
  );
}
