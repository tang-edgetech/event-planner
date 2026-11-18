"use client";

import { App, Button, Checkbox, Divider, Form, Input, Select, Space, Tag, Tooltip, type FormInstance } from "antd";
import { ArrowDownOutlined, ArrowUpOutlined, CopyOutlined, DeleteOutlined, DownOutlined, DownloadOutlined, ImportOutlined, PlusOutlined, RightOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { api } from "@/lib/api";
import { useCan } from "@/lib/session";
import type { EventDetail, FormFieldRow } from "@/lib/types";
import { RichText } from "@/components/rich-text";
import { downloadJson, TemplateImport } from "@/components/template-import";
import { EventTabForm, useEventDetail } from "../../event-context";

type Builder = { types: { value: string; label: string }[]; presets: { value: string; label: string; count: number }[] };

const WITH_OPTIONS = ["select", "radio", "checkboxes"];
const NO_INPUT = ["heading", "html"];
const WIDTHS = [
  { value: "100", label: "Full width" },
  { value: "66", label: "2/3" },
  { value: "50", label: "Half" },
  { value: "33", label: "1/3" },
  { value: "25", label: "1/4" },
];

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Values = Record<string, any>;

const toRows = (v: Values) => ({
  ...v,
  form_fields: ((v.form_fields as FormFieldRow[]) ?? []).map((f) => ({ ...f, options: Array.isArray(f.options) ? f.options.join("\n") : (f.options ?? "") })),
});
const fromRows = (v: Values) => ({
  form_intro: v.form_intro,
  submit_label: v.submit_label,
  form_fields: ((v.form_fields as FormFieldRow[]) ?? []).map((f) => ({
    ...f,
    options: String(f.options ?? "")
      .split(/\r?\n/)
      .map((s) => s.trim())
      .filter(Boolean),
  })),
});

/** Registration form builder: add, reorder and configure the fields visitors fill in. */
export default function FormTab() {
  const { data: builder } = useQuery({ queryKey: ["event-builder"], queryFn: async () => (await api.get<Builder>("/events/builder")).data, staleTime: 300_000 });
  return (
    <EventTabForm transformIn={toRows} transformOut={fromRows}>
      {(form) => (
        <>
          <Form.Item label="Text above the form" name="form_intro">
            <RichText minHeight={80} />
          </Form.Item>
          <Divider titlePlacement="start">Fields</Divider>
          <FormTemplateTools form={form} />
          <FieldList form={form} builder={builder} />
          <Form.Item label="Submit button label" name="submit_label" className="!mt-6" style={{ maxWidth: 320 }}>
            <Input maxLength={60} placeholder="Submit" />
          </Form.Item>
        </>
      )}
    </EventTabForm>
  );
}

function FieldList({ form, builder }: { form: FormInstance; builder?: Builder }) {
  const can = useCan();
  const [open, setOpen] = useState<Record<number, boolean>>({});
  const typeLabel = (t: string) => builder?.types.find((x) => x.value === t)?.label ?? t;

  return (
    <Form.List name="form_fields">
      {(fields, { add, remove, move }) => (
        <div>
          {fields.map((field, index) => {
            const row = (form.getFieldValue(["form_fields", field.name]) ?? {}) as FormFieldRow;
            const expanded = open[field.key] ?? false;
            return (
              <div key={field.key} className="ep-builder-row">
                <div className="ep-builder-row__head">
                  <Button type="text" size="small" icon={expanded ? <DownOutlined /> : <RightOutlined />} aria-label={expanded ? "Collapse" : "Expand"} onClick={() => setOpen((o) => ({ ...o, [field.key]: !expanded }))} />
                  <span className="ep-muted w-6 text-xs font-bold">{index + 1}</span>
                  <button type="button" className="min-w-0 flex-1 cursor-pointer truncate border-0 bg-transparent p-0 text-left font-semibold" style={{ color: "inherit" }} onClick={() => setOpen((o) => ({ ...o, [field.key]: !expanded }))}>
                    {row.label || <span className="ep-muted font-normal">(no label)</span>}
                  </button>
                  <Tag>{typeLabel(row.type)}</Tag>
                  {row.required ? <Tag color="red">Required</Tag> : null}
                  {row.conditional ? <Tag color="blue">Conditional</Tag> : null}
                  {can("events.manage") ? (
                    <Space size={0}>
                      <Tooltip title="Move up">
                        <Button type="text" size="small" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, index - 1)} aria-label="Move up" />
                      </Tooltip>
                      <Tooltip title="Move down">
                        <Button type="text" size="small" icon={<ArrowDownOutlined />} disabled={index === fields.length - 1} onClick={() => move(index, index + 1)} aria-label="Move down" />
                      </Tooltip>
                      <Tooltip title="Duplicate">
                        <Button type="text" size="small" icon={<CopyOutlined />} onClick={() => add({ ...row, name: row.name ? `${row.name}_copy` : "", label: `${row.label ?? ""} (copy)` }, index + 1)} aria-label="Duplicate" />
                      </Tooltip>
                      <Tooltip title="Delete">
                        <Button type="text" size="small" danger icon={<DeleteOutlined />} onClick={() => remove(field.name)} aria-label="Delete field" />
                      </Tooltip>
                    </Space>
                  ) : null}
                </div>
                <div className="border-t px-3 pb-1 pt-3" style={{ borderColor: "var(--ep-border)", display: expanded ? "block" : "none" }}>
                  <FieldEditor name={field.name} form={form} builder={builder} />
                </div>
              </div>
            );
          })}
          {can("events.manage") ? (
            <Button
              type="dashed"
              block
              icon={<PlusOutlined />}
              onClick={() => {
                add({ type: "text", label: "", width: "50", required: false, options: "" });
                setOpen((o) => ({ ...o, [Date.now()]: true }));
              }}
            >
              Add field
            </Button>
          ) : null}
        </div>
      )}
    </Form.List>
  );
}

function FieldEditor({ name, form, builder }: { name: number; form: FormInstance; builder?: Builder }) {
  return (
    <Form.Item noStyle shouldUpdate>
      {() => {
        const row = (form.getFieldValue(["form_fields", name]) ?? {}) as FormFieldRow;
        const t = row.type;
        const others = ((form.getFieldValue("form_fields") as FormFieldRow[]) ?? []).filter((_, i) => i !== name && !NO_INPUT.includes(_.type));
        return (
          <>
            <div className="grid gap-x-3 md:grid-cols-4">
              <Form.Item label="Type" name={[name, "type"]}>
                <Select options={builder?.types} />
              </Form.Item>
              <Form.Item label={t === "heading" ? "Heading text" : t === "consent" ? "Short label" : "Label"} name={[name, "label"]} className="md:col-span-2">
                <Input maxLength={500} />
              </Form.Item>
              {!NO_INPUT.includes(t) ? (
                <Form.Item label="Field key" name={[name, "name"]} tooltip="Used in exports and placeholders, e.g. full_name. Created from the label when empty." rules={[{ pattern: /^[a-z0-9_]*$/, message: "Lower-case letters, numbers, _" }]}>
                  <Input placeholder="auto" />
                </Form.Item>
              ) : null}
            </div>
            <div className="grid gap-x-3 md:grid-cols-4">
              {t !== "hidden" && t !== "heading" ? (
                <Form.Item label="Width" name={[name, "width"]}>
                  <Select options={WIDTHS} />
                </Form.Item>
              ) : null}
              {!NO_INPUT.includes(t) && t !== "hidden" ? (
                <Form.Item label=" " name={[name, "required"]} valuePropName="checked">
                  <Checkbox>Required</Checkbox>
                </Form.Item>
              ) : null}
              {["text", "textarea", "email", "phone", "nric", "number"].includes(t) ? (
                <Form.Item label="Placeholder" name={[name, "placeholder"]}>
                  <Input />
                </Form.Item>
              ) : null}
              {!NO_INPUT.includes(t) ? (
                <Form.Item label={t === "hidden" ? "Value" : "Default value"} name={[name, "default"]}>
                  <Input />
                </Form.Item>
              ) : null}
            </div>
            {t === "phone" ? (
              <Form.Item label="Phone format" name={[name, "phone_format"]} initialValue="my" style={{ maxWidth: 360 }}>
                <Select
                  options={[
                    { value: "my", label: "Malaysian number (saved as +60…)" },
                    { value: "intl", label: "International, with country code" },
                    { value: "any", label: "Any" },
                  ]}
                />
              </Form.Item>
            ) : null}
            {t === "nric" ? (
              <Form.Item label="ID format" name={[name, "nric_format"]} initialValue="my" style={{ maxWidth: 360 }}>
                <Select
                  options={[
                    { value: "my", label: "Malaysian NRIC only" },
                    { value: "my_or_passport", label: "NRIC or passport" },
                    { value: "any", label: "Any ID" },
                  ]}
                />
              </Form.Item>
            ) : null}
            {WITH_OPTIONS.includes(t) ? (
              <div className="grid gap-x-3 md:grid-cols-2">
                <Form.Item label="Options from" name={[name, "options_source"]} initialValue="custom">
                  <Select
                    options={[
                      { value: "custom", label: "Typed below" },
                      { value: "preset", label: "A saved list (Settings → Extra)" },
                    ]}
                  />
                </Form.Item>
                {row.options_source === "preset" ? (
                  <Form.Item label="Saved list" name={[name, "preset"]}>
                    <Select options={builder?.presets.map((p) => ({ value: p.value, label: `${p.label} (${p.count})` }))} notFoundContent="No saved lists yet" />
                  </Form.Item>
                ) : (
                  <Form.Item label="Options (one per line)" name={[name, "options"]}>
                    <Input.TextArea rows={4} />
                  </Form.Item>
                )}
              </div>
            ) : null}
            <div className="flex flex-wrap gap-x-6">
              {[...WITH_OPTIONS, "salutation"].includes(t) ? (
                <Form.Item name={[name, "allow_other"]} valuePropName="checked">
                  <Checkbox>Add “Others – please specify”</Checkbox>
                </Form.Item>
              ) : null}
              {["text", "email", "phone", "nric", "number"].includes(t) ? (
                <Form.Item name={[name, "unique"]} valuePropName="checked" tooltip="The same answer cannot register twice for this event (e.g. email or NRIC).">
                  <Checkbox>Must be unique per event</Checkbox>
                </Form.Item>
              ) : null}
            </div>
            {["heading", "html", "consent"].includes(t) ? (
              <Form.Item label={t === "consent" ? "Consent text" : "Content"} name={[name, "content"]}>
                <RichText minHeight={70} />
              </Form.Item>
            ) : null}
            {!NO_INPUT.includes(t) && t !== "hidden" ? (
              <Form.Item label="Help text" name={[name, "help"]}>
                <Input />
              </Form.Item>
            ) : null}
            <Form.Item name={[name, "conditional"]} valuePropName="checked" className="!mb-2">
              <Checkbox>Show only when another answer matches…</Checkbox>
            </Form.Item>
            {row.conditional ? (
              <div className="grid gap-x-3 md:grid-cols-3">
                <Form.Item label="When the field" name={[name, "cond_field"]}>
                  <Select options={others.map((o) => ({ value: o.name || "", label: o.label || o.name, disabled: !o.name }))} placeholder="Choose a field (it needs a key)" />
                </Form.Item>
                <Form.Item label="…" name={[name, "cond_operator"]} initialValue="is">
                  <Select
                    options={[
                      { value: "is", label: "is" },
                      { value: "is_not", label: "is not" },
                      { value: "contains", label: "contains" },
                      { value: "not_empty", label: "is answered" },
                      { value: "empty", label: "is empty" },
                    ]}
                  />
                </Form.Item>
                {!["empty", "not_empty"].includes(row.cond_operator ?? "is") ? (
                  <Form.Item label="Value" name={[name, "cond_value"]}>
                    <Input />
                  </Form.Item>
                ) : null}
              </div>
            ) : null}
          </>
        );
      }}
    </Form.Item>
  );
}

type FormTemplate = { form_intro?: string; submit_label?: string; form_fields: FormFieldRow[] };

/** Reads a registration form template file: our own export, or just a list of fields. */
function parseFormTemplate(json: unknown): FormTemplate {
  const o = (Array.isArray(json) ? { form_fields: json } : json) as Record<string, unknown> | null;
  if (!o || typeof o !== "object") throw new Error("This file is not a registration form template.");
  if (o.kind && o.kind !== "event-form") {
    throw new Error(o.kind === "microsite" ? "This is a microsite template. Import it in a microsite's Page builder instead." : "This file is not a registration form template.");
  }
  if (!Array.isArray(o.form_fields)) throw new Error("No form fields were found in this file.");
  const fields = (o.form_fields as unknown[]).filter((f): f is FormFieldRow => !!f && typeof f === "object" && typeof (f as FormFieldRow).type === "string");
  if (!fields.length) throw new Error("The file has no usable form fields.");
  return {
    form_fields: fields,
    form_intro: typeof o.form_intro === "string" ? o.form_intro : undefined,
    submit_label: typeof o.submit_label === "string" ? o.submit_label : undefined,
  };
}

/** Export this form as a template file, or import one (file or another event). */
function FormTemplateTools({ form }: { form: FormInstance }) {
  const event = useEventDetail();
  const can = useCan();
  const { message } = App.useApp();
  const [open, setOpen] = useState(false);
  const { data: events } = useQuery({
    queryKey: ["event-options"],
    queryFn: async () => (await api.get<{ data: { value: number; label: string }[] }>("/events/options")).data.data,
    enabled: open,
    staleTime: 60_000,
  });

  const exportIt = () => {
    const v = fromRows(form.getFieldsValue(true));
    downloadJson(`${event.slug}-registration-form.json`, { kind: "event-form", version: 1, exported_at: new Date().toISOString(), source: event.title, ...v });
    message.success("Template downloaded. Import it into any event's Registration form.");
  };

  const apply = (t: FormTemplate, mode: "replace" | "append") => {
    const rows = toRows({ form_fields: t.form_fields }).form_fields as FormFieldRow[];
    const current = ((form.getFieldValue("form_fields") as FormFieldRow[]) ?? []).filter(Boolean);
    if (mode === "replace") {
      form.setFieldsValue({ form_fields: rows, ...(t.form_intro !== undefined ? { form_intro: t.form_intro } : {}), ...(t.submit_label !== undefined ? { submit_label: t.submit_label } : {}) });
    } else {
      // Keep field names unique; conditions inside the template follow the renamed fields.
      const used = new Set(current.map((f) => f.name).filter(Boolean));
      const renamed: Record<string, string> = {};
      rows.forEach((r) => {
        if (!r.name) return;
        let name = r.name;
        for (let i = 2; used.has(name); i++) name = `${r.name}_${i}`;
        if (name !== r.name) renamed[r.name] = name;
        r.name = name;
        used.add(name);
      });
      rows.forEach((r) => {
        if (r.cond_field && renamed[r.cond_field]) r.cond_field = renamed[r.cond_field];
      });
      form.setFieldsValue({ form_fields: [...current, ...rows] });
    }
    message.success(`Imported ${rows.length} field(s). Check them, then press Save changes.`);
  };

  return (
    <div className="mb-3 flex flex-wrap justify-end gap-2">
      {can("events.manage") ? (
        <Button icon={<ImportOutlined />} onClick={() => setOpen(true)}>
          Import template
        </Button>
      ) : null}
      <Button icon={<DownloadOutlined />} onClick={exportIt}>
        Export template
      </Button>
      <TemplateImport<FormTemplate>
        open={open}
        onClose={() => setOpen(false)}
        what="registration form"
        itemNoun="event"
        sources={(events ?? []).filter((e) => e.value !== event.id)}
        loadFrom={async (id) => {
          const e = (await api.get<{ data: EventDetail }>(`/events/${id}`)).data.data;
          const r = e as unknown as Record<string, unknown>;
          return { form_fields: e.form_fields, form_intro: typeof r.form_intro === "string" ? r.form_intro : undefined, submit_label: typeof r.submit_label === "string" ? r.submit_label : undefined };
        }}
        parse={parseFormTemplate}
        describe={(t) => `${t.form_fields.length} field(s)${t.form_intro ? " and the text above the form" : ""}`}
        onApply={apply}
      />
    </div>
  );
}