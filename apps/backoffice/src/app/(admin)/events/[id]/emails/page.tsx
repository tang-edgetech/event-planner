"use client";

import { Alert, Divider, Form, Input, Radio, Switch } from "antd";
import { RichText } from "@/components/rich-text";
import { EventTabForm, useEventDetail } from "../../event-context";

export default function EmailsTab() {
  const event = useEventDetail();
  const keys = (event.form_fields ?? []).filter((f) => !["heading", "html"].includes(f.type)).map((f) => `{${f.name || f.type}}`);
  return (
    <EventTabForm>
      {(form) => (
        <>
          <Alert
            className="!mb-4"
            type="info"
            showIcon
            title="Placeholders you can use in messages and emails"
            description={
              <span className="text-xs">
                {"{event_title} {event_date} {event_time} {event_venue} {event_url} {site_name} {registration_details}"} {keys.join(" ")}
              </span>
            }
          />
          <Divider titlePlacement="start">After a successful registration</Divider>
          <Form.Item label="Then" name="confirmation_type">
            <Radio.Group
              options={[
                { value: "message", label: "Show a thank-you message" },
                { value: "redirect", label: "Go to another web page" },
              ]}
            />
          </Form.Item>
          <Form.Item noStyle shouldUpdate={(a, b) => a.confirmation_type !== b.confirmation_type}>
            {() =>
              form.getFieldValue("confirmation_type") === "redirect" ? (
                <Form.Item label="Web page address" name="redirect_url" rules={[{ required: true, type: "url", message: "Enter a full address starting with https://" }]}>
                  <Input placeholder="https://" />
                </Form.Item>
              ) : (
                <Form.Item label="Thank-you message" name="confirmation_message">
                  <RichText placeholder="Thank you! Your registration for {event_title} has been received." />
                </Form.Item>
              )
            }
          </Form.Item>
          <Divider titlePlacement="start">Email to staff</Divider>
          <div className="grid gap-x-4 md:grid-cols-[200px_1fr]">
            <Form.Item label="Notify on each registration" name="notify_admin" valuePropName="checked">
              <Switch />
            </Form.Item>
            <Form.Item label="Recipients" name="admin_emails" extra="Separate several emails with commas. Empty: the default recipients in Settings → Email.">
              <Input.TextArea rows={2} />
            </Form.Item>
          </div>
          <Divider titlePlacement="start">Confirmation email to the applicant</Divider>
          <Form.Item label="Send a confirmation email" name="notify_applicant" valuePropName="checked" extra="Needs an Email field in the registration form.">
            <Switch />
          </Form.Item>
          <Form.Item noStyle shouldUpdate={(a, b) => a.notify_applicant !== b.notify_applicant}>
            {() =>
              form.getFieldValue("notify_applicant") ? (
                <>
                  <Form.Item label="Subject" name="email_subject">
                    <Input placeholder="Registration received: {event_title}" />
                  </Form.Item>
                  <Form.Item label="Message" name="email_body" extra="Leave empty for the standard confirmation email.">
                    <RichText minHeight={160} />
                  </Form.Item>
                </>
              ) : null
            }
          </Form.Item>
        </>
      )}
    </EventTabForm>
  );
}
