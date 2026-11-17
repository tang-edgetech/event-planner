"use client";

import { Button, Card, Divider, Form, Input, InputNumber, Radio, Switch } from "antd";
import { DeleteOutlined, PlusOutlined } from "@ant-design/icons";
import { SettingsForm } from "../use-settings";

type OptionList = { name: string; slug?: string; options: string[] | string };

const listsIn = (v: Record<string, unknown>) => ({
  ...v,
  option_lists: ((v.option_lists as OptionList[]) ?? []).map((l) => ({ ...l, options: Array.isArray(l.options) ? l.options.join("\n") : l.options })),
});
const listsOut = (v: Record<string, unknown>) => ({
  ...v,
  option_lists: ((v.option_lists as OptionList[]) ?? []).map((l) => ({ ...l, options: String(l.options ?? "").split(/\r?\n/).map((s) => s.trim()).filter(Boolean) })),
});

export default function ExtraSettings() {
  return (
    <SettingsForm group="extra" transformIn={listsIn} transformOut={listsOut}>
      {(form) => (
        <>
          <Divider titlePlacement="start">Public site homepage</Divider>
          <Form.Item label="The homepage shows" name="home_mode" extra="Events and microsites are only reachable at their exact address.">
            <Radio.Group
              options={[
                { value: "maintenance", label: "“Under maintenance” message" },
                { value: "notfound", label: "Page not found (404)" },
              ]}
            />
          </Form.Item>
          <Form.Item noStyle shouldUpdate={(a, b) => a.home_mode !== b.home_mode}>
            {() =>
              form.getFieldValue("home_mode") === "maintenance" ? (
                <div className="grid gap-x-4 md:grid-cols-2">
                  <Form.Item label="Maintenance title" name="maintenance_title">
                    <Input maxLength={190} />
                  </Form.Item>
                  <Form.Item label="Maintenance message" name="maintenance_message">
                    <Input.TextArea rows={2} maxLength={2000} />
                  </Form.Item>
                </div>
              ) : null
            }
          </Form.Item>

          <Divider titlePlacement="start">Registration features</Divider>
          <div className="grid gap-x-4 md:grid-cols-3">
            <Form.Item label="Capacity alert email" name="capacity_alert" valuePropName="checked" extra="Emails the event's recipients once when an event nearly fills up.">
              <Switch />
            </Form.Item>
            <Form.Item label="Alert at (% full)" name="capacity_alert_pct">
              <InputNumber min={10} max={100} />
            </Form.Item>
            <Form.Item label="Hide personal data from Viewers" name="mask_viewers" valuePropName="checked" extra="Email, phone and ID are partly hidden for people who cannot edit applicants.">
              <Switch />
            </Form.Item>
          </div>
          <Form.Item label="When a seat frees up (events with a waiting list)" name="waitlist_mode">
            <Radio.Group
              options={[
                { value: "manual", label: "Do nothing (staff decide)" },
                { value: "notify", label: "Email the event's recipients" },
                { value: "auto", label: "Move the next person up and email them" },
              ]}
            />
          </Form.Item>

          <Divider titlePlacement="start">Option lists</Divider>
          <p className="ep-muted -mt-2 mb-3 text-sm">Reusable answer lists for registration forms (for example specialties or hospitals). In the form builder choose “Options from: saved list”.</p>
          <Form.List name="option_lists">
            {(fields, { add, remove }) => (
              <div className="flex flex-col gap-3">
                {fields.map((f) => (
                  <Card key={f.key} size="small" extra={<Button type="text" danger icon={<DeleteOutlined />} aria-label="Remove list" onClick={() => remove(f.name)} />} title={form.getFieldValue(["option_lists", f.name, "name"]) || "New list"}>
                    <div className="grid gap-x-4 md:grid-cols-2">
                      <Form.Item label="Name" name={[f.name, "name"]} rules={[{ required: true, message: "Name the list." }]}>
                        <Input maxLength={80} />
                      </Form.Item>
                      <Form.Item label="Key" name={[f.name, "slug"]} extra="Leave empty to create it from the name.">
                        <Input maxLength={80} />
                      </Form.Item>
                    </div>
                    <Form.Item label="Options (one per line)" name={[f.name, "options"]}>
                      <Input.TextArea rows={4} />
                    </Form.Item>
                  </Card>
                ))}
                <Button className="self-start" icon={<PlusOutlined />} onClick={() => add({ name: "", slug: "", options: "" })}>
                  Add option list
                </Button>
              </div>
            )}
          </Form.List>
          <div className="h-6" />
        </>
      )}
    </SettingsForm>
  );
}
