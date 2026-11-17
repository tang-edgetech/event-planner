"use client";

import { App, Button, ColorPicker, Divider, Form, Input, Radio, Switch } from "antd";
import type { Color } from "antd/es/color-picker";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { useCan } from "@/lib/session";
import { MediaPicker } from "@/components/media-picker";
import { EventSelect } from "../../schema-fields";
import { useSite } from "../../site-context";

const hex = (c: Color) => c?.toHexString?.() ?? null;

/** Microsite → General: address, status, linked event, colours, top menu, footer, custom CSS. */
export default function MicrositeSettingsTab() {
  const site = useSite();
  const can = useCan();
  const qc = useQueryClient();
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const initial = { title: site.title, slug: site.slug, status: site.status, event_id: site.event_id, settings: site.settings };

  useEffect(() => {
    form.setFieldsValue({ title: site.title, slug: site.slug, status: site.status, event_id: site.event_id, settings: site.settings });
  }, [site, form]);

  const save = async (v: typeof initial) => {
    setSaving(true);
    try {
      const res = await api.put(`/microsites/${site.id}`, v);
      qc.setQueryData(["microsite", String(site.id)], res.data.data);
      qc.invalidateQueries({ queryKey: ["microsites"] });
      message.success("Saved");
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Form form={form} layout="vertical" requiredMark={false} initialValues={initial} onFinish={save} disabled={!can("microsites.manage")}>
      <div className="ep-card">
        <div className="grid gap-x-4 md:grid-cols-3">
          <Form.Item label="Title" name="title" rules={[{ required: true }]} className="md:col-span-2">
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item label="Status" name="status">
            <Radio.Group
              optionType="button"
              options={[
                { value: "draft", label: "Draft" },
                { value: "published", label: "Published" },
              ]}
            />
          </Form.Item>
          <Form.Item label="Web address" name="slug" className="md:col-span-2" rules={[{ pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/, message: "Lower-case letters, numbers and dashes." }]}>
            <Input addonBefore="/e/" />
          </Form.Item>
          <Form.Item label="Linked event" name="event_id" extra="Supplies the banner's date and venue and the default registration form.">
            <EventSelect value={null} onChange={(v) => form.setFieldValue("event_id", v)} />
          </Form.Item>
        </div>
        <Divider titlePlacement="start">Look</Divider>
        <div className="grid gap-x-4 md:grid-cols-3">
          <Form.Item label="Accent colour" name={["settings", "accent_color"]} getValueFromEvent={hex}>
            <ColorPicker allowClear showText format="hex" />
          </Form.Item>
          <Form.Item label="Page background" name={["settings", "page_bg"]} getValueFromEvent={hex}>
            <ColorPicker allowClear showText format="hex" />
          </Form.Item>
          <Form.Item label="Scroll animations" name={["settings", "animations"]} valuePropName="checked">
            <Switch />
          </Form.Item>
        </div>
        <Divider titlePlacement="start">Top menu and footer</Divider>
        <div className="grid gap-x-4 md:grid-cols-3">
          <Form.Item label="Show the top menu" name={["settings", "show_nav"]} valuePropName="checked" extra="Lists sections that have a menu label.">
            <Switch />
          </Form.Item>
          <Form.Item label="Menu logo" name={["settings", "nav_logo"]}>
            <MediaPicker />
          </Form.Item>
          <div />
          <Form.Item label="Menu button label" name={["settings", "nav_cta_label"]}>
            <Input placeholder="Register" />
          </Form.Item>
          <Form.Item label="Menu button link" name={["settings", "nav_cta_url"]} extra="#register, or a full https:// address">
            <Input />
          </Form.Item>
        </div>
        <Form.Item label="Footer text" name={["settings", "footer_text"]}>
          <Input maxLength={500} />
        </Form.Item>
        <Form.Item label="Custom CSS (advanced)" name={["settings", "custom_css"]}>
          <Input.TextArea rows={5} className="font-mono !text-xs" />
        </Form.Item>
      </div>
      {can("microsites.manage") ? (
        <div className="ep-sticky-actions">
          <Button type="primary" htmlType="submit" loading={saving}>
            Save changes
          </Button>
        </div>
      ) : null}
    </Form>
  );
}
