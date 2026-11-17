"use client";

import { Alert, App, Button, Divider, Form, Input, InputNumber, Modal, Radio, Select } from "antd";
import { SendOutlined } from "@ant-design/icons";
import { useState } from "react";
import { api, errorMessage } from "@/lib/api";
import { useMe } from "@/lib/session";
import { SettingsForm } from "../use-settings";

export default function EmailSettings() {
  const me = useMe();
  const { message } = App.useApp();
  const [testOpen, setTestOpen] = useState(false);
  const [to, setTo] = useState(me.user.email);
  const [sending, setSending] = useState(false);

  const sendTest = async () => {
    setSending(true);
    try {
      const res = await api.post<{ mailer: string }>("/settings/email/test", { to });
      message.success(res.data.mailer === "smtp" ? `Test email sent to ${to}` : "No SMTP server set: the test email was written to the server log only.");
      setTestOpen(false);
    } catch (e) {
      message.error(errorMessage(e, "The test email could not be sent."));
    } finally {
      setSending(false);
    }
  };

  return (
    <>
      <SettingsForm
        group="email"
        extraActions={() => (
          <Button icon={<SendOutlined />} onClick={() => setTestOpen(true)}>
            Send test email
          </Button>
        )}
      >
        {(form, data) => (
          <>
            <Alert className="!mb-4" type="info" showIcon title="Save first, then send a test email. Every email sent (or failed) appears in the audit log." />
            <Form.Item label="Send emails through" name="mailer">
              <Radio.Group
                options={[
                  { value: "smtp", label: "SMTP server" },
                  { value: "log", label: "Do not send (write to the server log, for testing)" },
                ]}
              />
            </Form.Item>
            <Form.Item noStyle shouldUpdate={(a, b) => a.mailer !== b.mailer}>
              {() =>
                form.getFieldValue("mailer") === "smtp" ? (
                  <div className="grid gap-x-4 md:grid-cols-3">
                    <Form.Item label="Host" name="host" rules={[{ required: true, message: "Enter the SMTP host." }]} className="md:col-span-2">
                      <Input placeholder="smtp.example.com" />
                    </Form.Item>
                    <Form.Item label="Port" name="port">
                      <InputNumber min={1} max={65535} className="!w-full" />
                    </Form.Item>
                    <Form.Item label="Encryption" name="encryption">
                      <Select
                        options={[
                          { value: "tls", label: "TLS (port 587)" },
                          { value: "ssl", label: "SSL (port 465)" },
                          { value: "none", label: "None" },
                        ]}
                      />
                    </Form.Item>
                    <Form.Item label="Username" name="username">
                      <Input autoComplete="off" />
                    </Form.Item>
                    <Form.Item label="Password" name="password" extra={data.password_set ? "A password is saved. Leave empty to keep it." : undefined}>
                      <Input.Password autoComplete="new-password" placeholder={data.password_set ? "••••••••" : ""} />
                    </Form.Item>
                  </div>
                ) : null
              }
            </Form.Item>
            <Divider titlePlacement="start">Sender and recipients</Divider>
            <div className="grid gap-x-4 md:grid-cols-2">
              <Form.Item label="Sender email" name="from_address" rules={[{ type: "email" }]}>
                <Input placeholder="events@example.com" />
              </Form.Item>
              <Form.Item label="Sender name" name="from_name">
                <Input />
              </Form.Item>
            </div>
            <Form.Item label="Default notification recipients" name="admin_recipients" extra="Used when an event has no recipients of its own. Separate several emails with commas.">
              <Input.TextArea rows={2} />
            </Form.Item>
          </>
        )}
      </SettingsForm>
      <Modal open={testOpen} title="Send a test email" onCancel={() => setTestOpen(false)} onOk={sendTest} okText="Send" confirmLoading={sending}>
        <Input value={to} onChange={(e) => setTo(e.target.value)} type="email" aria-label="Send to" />
      </Modal>
    </>
  );
}
