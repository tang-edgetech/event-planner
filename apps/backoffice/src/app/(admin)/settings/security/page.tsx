"use client";

import { Divider, Form, InputNumber, Radio, Switch } from "antd";
import { SettingsForm } from "../use-settings";

export default function SecuritySettings() {
  return (
    <SettingsForm group="security">
      {() => (
        <>
          <Form.Item label="Sign in with" name="login_method" extra="What people type on the sign-in page.">
            <Radio.Group
              options={[
                { value: "either", label: "Username or email" },
                { value: "username", label: "Username only" },
                { value: "email", label: "Email only" },
              ]}
            />
          </Form.Item>
          <div className="grid gap-x-4 md:grid-cols-3">
            <Form.Item label="Sign out after inactivity (minutes)" name="idle_minutes" extra="Default 120 (2 hours). A pop-up warns 2 minutes before.">
              <InputNumber min={5} max={1440} className="!w-full" />
            </Form.Item>
            <Form.Item label="Failed attempts before blocking" name="max_login_attempts">
              <InputNumber min={3} max={20} className="!w-full" />
            </Form.Item>
            <Form.Item label="Block for (minutes)" name="lockout_minutes">
              <InputNumber min={1} max={1440} className="!w-full" />
            </Form.Item>
          </div>
          <Divider titlePlacement="start">Passwords and 2FA</Divider>
          <div className="grid gap-x-4 md:grid-cols-3">
            <Form.Item label="Minimum password length" name="password_min_length">
              <InputNumber min={8} max={64} className="!w-full" />
            </Form.Item>
            <Form.Item label="Require upper/lower case, number and symbol" name="password_require_mixed" valuePropName="checked">
              <Switch />
            </Form.Item>
            <Form.Item label="Require 2FA for everyone" name="require_2fa" valuePropName="checked" extra="Users without a device must set one up at their next sign-in.">
              <Switch />
            </Form.Item>
          </div>
        </>
      )}
    </SettingsForm>
  );
}
