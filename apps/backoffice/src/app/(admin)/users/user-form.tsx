"use client";

import { Alert, Button, Checkbox, Form, Input, Select, Switch } from "antd";
import { useMe } from "@/lib/session";
import { THEMES } from "@/theme/themes";

export type UserValues = {
  name: string;
  username: string;
  email: string;
  role?: string;
  status?: string;
  active?: boolean;
  theme?: string | null;
  password?: string;
  password_confirmation?: string;
  must_change_password?: boolean;
};

/** Account information form shared by "Add user" and the Account tab. */
export function UserFields({ creating, self, canManage }: { creating?: boolean; self?: boolean; canManage?: boolean }) {
  const me = useMe();
  const roleOptions = me.roles.filter((r) => me.assignable_roles.includes(r.value)).map((r) => ({ value: r.value, label: r.label }));
  return (
    <>
      <div className="grid gap-x-4 md:grid-cols-2">
        <Form.Item label="Full name" name="name" rules={[{ required: true, message: "Enter the name." }]}>
          <Input maxLength={120} disabled={!self && !canManage && !creating} />
        </Form.Item>
        <Form.Item label="Username" name="username" rules={[{ required: true, message: "Enter a username." }, { pattern: /^[a-zA-Z0-9._-]{3,60}$/, message: "3–60 letters, numbers, . _ or -" }]}>
          <Input maxLength={60} disabled={!self && !canManage && !creating} />
        </Form.Item>
        <Form.Item label="Email" name="email" rules={[{ required: true, type: "email", message: "Enter a valid email." }]}>
          <Input maxLength={190} disabled={!self && !canManage && !creating} />
        </Form.Item>
        {!self && (canManage || creating) ? (
          <Form.Item label="Role" name="role" rules={[{ required: true, message: "Choose a role." }]} extra="You can only give roles below your own.">
            <Select options={roleOptions} />
          </Form.Item>
        ) : null}
        {!self && (canManage || creating) ? (
          <Form.Item label="Active" name="active" valuePropName="checked" extra="Inactive users cannot sign in.">
            <Switch />
          </Form.Item>
        ) : null}
        {self && me.settings.allow_user_theme ? (
          <Form.Item label="My colour theme" name="theme" extra="Only changes how the backoffice looks for you.">
            <Select allowClear placeholder="Use the system theme" options={THEMES.map((t) => ({ value: t.key, label: t.label }))} />
          </Form.Item>
        ) : null}
      </div>
      {creating ? (
        <>
          <Alert className="!mb-4" type="info" showIcon title="Set a first password and share it safely. The user will be asked to choose their own at first sign-in (unless you untick the box)." />
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item label="Password" name="password" rules={[{ required: true, message: "Choose a password." }]} extra="8+ characters with upper- and lower-case letters, a number and a symbol.">
              <Input.Password autoComplete="new-password" />
            </Form.Item>
            <Form.Item
              label="Repeat password"
              name="password_confirmation"
              dependencies={["password"]}
              rules={[
                { required: true, message: "Repeat the password." },
                ({ getFieldValue }) => ({ validator: (_, v) => (!v || v === getFieldValue("password") ? Promise.resolve() : Promise.reject(new Error("The passwords do not match."))) }),
              ]}
            >
              <Input.Password autoComplete="new-password" />
            </Form.Item>
          </div>
          <Form.Item name="must_change_password" valuePropName="checked">
            <Checkbox>Must choose a new password at first sign-in</Checkbox>
          </Form.Item>
        </>
      ) : null}
    </>
  );
}

export function SaveButton({ loading, label = "Save changes" }: { loading?: boolean; label?: string }) {
  return (
    <Button type="primary" htmlType="submit" loading={loading}>
      {label}
    </Button>
  );
}
