"use client";

import { ColorPicker, DatePicker, Divider, Form, Input, Radio, Switch } from "antd";
import type { Color } from "antd/es/color-picker";
import { MediaPicker } from "@/components/media-picker";
import { RichText } from "@/components/rich-text";
import { EventTabForm } from "../../event-context";

const hex = (c: Color) => c?.toHexString?.() ?? null;
const DT = { showTime: { format: "h:mm A" }, format: "D MMM YYYY, h:mm A", className: "!w-full" } as const;

export default function DetailsTab() {
  return (
    <EventTabForm>
      {() => (
        <>
          <div className="grid gap-x-4 md:grid-cols-3">
            <Form.Item label="Event title" name="title" rules={[{ required: true, message: "Give the event a title." }]} className="md:col-span-2">
              <Input maxLength={255} />
            </Form.Item>
            <Form.Item label="Status" name="status" extra="Only published events can be opened by visitors.">
              <Radio.Group
                optionType="button"
                options={[
                  { value: "draft", label: "Draft" },
                  { value: "published", label: "Published" },
                ]}
              />
            </Form.Item>
          </div>
          <Form.Item label="Web address" name="slug" rules={[{ pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/, message: "Use lower-case letters, numbers and dashes only." }]} extra="Changing it breaks links already shared.">
            <Input addonBefore="/event/" />
          </Form.Item>
          <Divider titlePlacement="start">When and where</Divider>
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item label="Starts" name="starts_at">
              <DatePicker {...DT} />
            </Form.Item>
            <Form.Item label="Ends" name="ends_at">
              <DatePicker {...DT} />
            </Form.Item>
            <Form.Item label="Date (display text, optional)" name="date_text" extra="Overrides the date shown to visitors, e.g. “Saturday, 12 December 2026”.">
              <Input maxLength={255} />
            </Form.Item>
            <Form.Item label="Time (display text, optional)" name="time_text" extra="e.g. “9:00 AM – 5:00 PM”">
              <Input maxLength={255} />
            </Form.Item>
            <Form.Item label="Venue" name="venue">
              <Input maxLength={255} />
            </Form.Item>
            <Form.Item label="Venue map link" name="venue_map_url" rules={[{ type: "url", message: "Enter a full link starting with https://" }]}>
              <Input placeholder="https://maps.google.com/…" />
            </Form.Item>
          </div>
          <Form.Item label="About this event" name="summary">
            <RichText placeholder="Shown beside the registration form" />
          </Form.Item>
          <Divider titlePlacement="start">Page design</Divider>
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item label="Banner (desktop)" name="banner_desktop" extra="Wide image, about 1920 × 600.">
              <MediaPicker />
            </Form.Item>
            <Form.Item label="Banner (mobile, optional)" name="banner_mobile" extra="Taller image for phones, about 800 × 800.">
              <MediaPicker />
            </Form.Item>
            <Form.Item label="Page layout" name="page_layout">
              <Radio.Group
                options={[
                  { value: "split", label: "Details beside the form" },
                  { value: "single", label: "Form only (narrow)" },
                ]}
              />
            </Form.Item>
            <Form.Item label="Show title above the form" name="show_title" valuePropName="checked">
              <Switch />
            </Form.Item>
            <Form.Item label="Accent colour" name="accent_color" getValueFromEvent={hex} extra="Buttons and highlights. Leave empty for the default.">
              <ColorPicker allowClear showText format="hex" />
            </Form.Item>
            <Form.Item label="Page background" name="page_bg" getValueFromEvent={hex}>
              <ColorPicker allowClear showText format="hex" />
            </Form.Item>
          </div>
        </>
      )}
    </EventTabForm>
  );
}
