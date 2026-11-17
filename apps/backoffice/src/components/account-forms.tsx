"use client";

import { Alert, App, Button, Empty, Form, Input, List, Modal, Popconfirm, Space, Typography } from "antd";
import { MobileOutlined, PlusOutlined } from "@ant-design/icons";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";

type PasswordValues = { current_password?: string; password: string; password_confirmation: string; must_change_password?: boolean };

const PASSWORD_HELP = "At least 8 characters with upper- and lower-case letters, a number and a symbol (the exact rule is set in Settings → Security).";

/**
 * Change password. For your own account: current, new and repeat new.
 * For another user (admin): new and repeat, no current password.
 */
export function PasswordForm({ self, userId, onDone }: { self: boolean; userId?: number; onDone?: () => void }) {
  const [form] = Form.useForm<PasswordValues>();
  const { message } = App.useApp();
  const [saving, setSaving] = useState(false);

  const submit = async (values: PasswordValues) => {
    setSaving(true);
    try {
      if (self) await api.put("/account/password", values);
      else await api.put(`/users/${userId}/password`, values);
      message.success(self ? "Password changed. Other devices were signed out." : "Password set. The user was signed out everywhere.");
      form.resetFields();
      onDone?.();
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Form form={form} layout="vertical" onFinish={submit} requiredMark={false} style={{ maxWidth: 440 }}>
      {self ? (
        <Form.Item label="Current password" name="current_password" rules={[{ required: true, message: "Enter your current password." }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
      ) : null}
      <Form.Item label="New password" name="password" extra={PASSWORD_HELP} rules={[{ required: true, message: "Enter a new password." }]}>
        <Input.Password autoComplete="new-password" />
      </Form.Item>
      <Form.Item
        label="Repeat new password"
        name="password_confirmation"
        dependencies={["password"]}
        rules={[
          { required: true, message: "Repeat the new password." },
          ({ getFieldValue }) => ({
            validator: (_, v) => (!v || getFieldValue("password") === v ? Promise.resolve() : Promise.reject(new Error("The two passwords do not match."))),
          }),
        ]}
      >
        <Input.Password autoComplete="new-password" />
      </Form.Item>
      <Button type="primary" htmlType="submit" loading={saving}>
        {self ? "Change password" : "Set password"}
      </Button>
    </Form>
  );
}

type Device = { id: number; name: string; confirmed_at: string | null; last_used_at: string | null };

/** Authenticator devices (max 2). Own account: add / remove. Another user: view / remove (admin). */
export function TwoFactorManager({ self, userId, canManage, onChanged }: { self: boolean; userId: number; canManage?: boolean; onChanged?: () => void }) {
  const { message } = App.useApp();
  const qc = useQueryClient();
  const key = ["two-factor", userId];
  const { data, isLoading } = useQuery({
    queryKey: key,
    queryFn: async () => (await api.get<{ devices: Device[]; max?: number }>(self ? "/account/two-factor" : `/users/${userId}/two-factor`)).data,
  });
  const [adding, setAdding] = useState(false);
  const devices = data?.devices ?? [];
  const max = data?.max ?? 2;

  const remove = async (d: Device) => {
    try {
      await api.delete(self ? `/account/two-factor/${d.id}` : `/users/${userId}/two-factor/${d.id}`);
      message.success("Device removed");
      await qc.invalidateQueries({ queryKey: key });
      onChanged?.();
    } catch (e) {
      message.error(errorMessage(e));
    }
  };

  return (
    <div style={{ maxWidth: 560 }}>
      <Typography.Paragraph className="ep-muted">
        Two-factor authentication (2FA) asks for a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…) after the password. Up to {max} devices can be linked, for example a phone and a backup phone.
      </Typography.Paragraph>
      <List
        loading={isLoading}
        bordered
        dataSource={devices}
        locale={{ emptyText: <Empty description="No devices linked: 2FA is off for this account" /> }}
        renderItem={(d) => (
          <List.Item
            actions={
              self || canManage
                ? [
                    <Popconfirm key="r" title={`Remove "${d.name}"?`} description="Codes from this device will stop working." okText="Remove" okButtonProps={{ danger: true }} onConfirm={() => remove(d)}>
                      <Button danger size="small">
                        Remove
                      </Button>
                    </Popconfirm>,
                  ]
                : []
            }
          >
            <List.Item.Meta avatar={<MobileOutlined style={{ fontSize: 20 }} />} title={d.name} description={`Linked ${fmtDateTime(d.confirmed_at)} · last used ${fmtDateTime(d.last_used_at)}`} />
          </List.Item>
        )}
      />
      {self ? (
        <Button className="mt-3" type="primary" icon={<PlusOutlined />} disabled={devices.length >= max} onClick={() => setAdding(true)}>
          {devices.length >= max ? `Maximum of ${max} devices reached` : "Add a device"}
        </Button>
      ) : null}
      {adding ? (
        <AddDeviceModal
          onClose={() => setAdding(false)}
          onDone={async () => {
            setAdding(false);
            await qc.invalidateQueries({ queryKey: key });
            onChanged?.();
          }}
        />
      ) : null}
    </div>
  );
}

function AddDeviceModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { message } = App.useApp();
  const [step, setStep] = useState<{ device_id: number; secret: string; qr_svg: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [nameForm] = Form.useForm<{ name: string }>();
  const [codeForm] = Form.useForm<{ code: string }>();

  const start = async ({ name }: { name: string }) => {
    setBusy(true);
    try {
      setStep((await api.post("/account/two-factor", { name })).data);
    } catch (e) {
      nameForm.setFields(formErrors(e));
    } finally {
      setBusy(false);
    }
  };
  const confirm = async ({ code }: { code: string }) => {
    if (!step) return;
    setBusy(true);
    try {
      await api.post(`/account/two-factor/${step.device_id}/confirm`, { code });
      message.success("Device linked. Next time you sign in, you will be asked for a code.");
      onDone();
    } catch (e) {
      codeForm.setFields(formErrors(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open title="Add an authenticator device" onCancel={onClose} footer={null} destroyOnHidden>
      {!step ? (
        <Form form={nameForm} layout="vertical" onFinish={start} requiredMark={false}>
          <Form.Item label="Device name" name="name" extra="For example: My iPhone" rules={[{ required: true, message: "Give the device a name." }]}>
            <Input maxLength={60} autoFocus />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={busy}>
            Continue
          </Button>
        </Form>
      ) : (
        <Space orientation="vertical" size="middle" className="w-full">
          <Alert type="info" showIcon title="1. Open your authenticator app and scan this QR code." />
          <div className="mx-auto w-[200px] rounded bg-white p-2" dangerouslySetInnerHTML={{ __html: step.qr_svg }} />
          <Typography.Text type="secondary">
            Can&apos;t scan? Enter this key in the app: <Typography.Text code copyable>{step.secret}</Typography.Text>
          </Typography.Text>
          <Form form={codeForm} layout="vertical" onFinish={confirm} requiredMark={false}>
            <Form.Item label="2. Enter the 6-digit code shown in the app" name="code" rules={[{ required: true, message: "Enter the code." }]}>
              <Input.OTP length={6} />
            </Form.Item>
            <Button type="primary" htmlType="submit" loading={busy}>
              Link device
            </Button>
          </Form>
        </Space>
      )}
    </Modal>
  );
}
