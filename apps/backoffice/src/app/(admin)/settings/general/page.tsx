"use client";

import { Divider, Form, Input, Select } from "antd";
import { MediaPicker } from "@/components/media-picker";
import dayjs from "dayjs";
import { SettingsForm } from "../use-settings";

const ZONES = [
  "Asia/Kuala_Lumpur",
  "Asia/Singapore",
  "Asia/Jakarta",
  "Asia/Bangkok",
  "Asia/Manila",
  "Asia/Hong_Kong",
  "Asia/Tokyo",
  "Australia/Sydney",
  "Europe/London",
  "UTC",
];
const FORMATS = ["D MMM YYYY", "DD/MM/YYYY", "YYYY-MM-DD", "MMM D, YYYY"];

export default function GeneralSettings() {
  return (
    <SettingsForm group="general">
      {() => (
        <>
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item
              label="Site name"
              name="site_name"
              rules={[{ required: true }]}
              extra="Shown in the backoffice, emails and the authenticator app."
            >
              <Input maxLength={120} />
            </Form.Item>
            <Form.Item
              label="Contact email"
              name="contact_email"
              rules={[{ type: "email" }]}
              extra="Shown on the public maintenance page."
            >
              <Input />
            </Form.Item>
            <Form.Item label="Time zone" name="timezone">
              <Select
                showSearch
                options={ZONES.map((z) => ({
                  value: z,
                  label: z.replace("_", " "),
                }))}
              />
            </Form.Item>
            <Form.Item label="Date format" name="date_format">
              <Select
                options={FORMATS.map((f) => ({
                  value: f,
                  label: `${dayjs().format(f)}`,
                }))}
              />
            </Form.Item>
          </div>
          <Divider titlePlacement="start">Logo and favicon</Divider>
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item
              label="Logo"
              name="logo"
              extra="Shown in the backoffice menu and on the sign-in page. A wide PNG or SVG with a transparent background, about 360 × 80, works best. Empty = the site name."
            >
              <MediaPicker />
            </Form.Item>
            <Form.Item
              label="Favicon"
              name="favicon"
              extra="The small icon in the browser tab, for the backoffice and the public site. A square PNG or ICO, at least 64 × 64."
            >
              <MediaPicker />
            </Form.Item>
          </div>
        </>
      )}
    </SettingsForm>
  );
}
