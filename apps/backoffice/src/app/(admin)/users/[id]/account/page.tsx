"use client";

import { App, Button, Descriptions, Form, Popconfirm } from "antd";
import { DeleteOutlined } from "@ant-design/icons";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { useRefreshMe } from "@/lib/session";
import { useEpTheme } from "@/components/providers";
import { SaveButton, UserFields, type UserValues } from "../../user-form";
import { useUserRow } from "../../user-context";

export default function AccountTab() {
  const user = useUserRow();
  const qc = useQueryClient();
  const refreshMe = useRefreshMe();
  const router = useRouter();
  const { setThemeKey } = useEpTheme();
  const { message } = App.useApp();
  const [form] = Form.useForm<UserValues>();
  const [saving, setSaving] = useState(false);

  const submit = async ({ active, ...values }: UserValues) => {
    setSaving(true);
    try {
      await api.put(`/users/${user.id}`, user.is_self ? values : { ...values, status: active ? "active" : "inactive" });
      message.success("Saved");
      await qc.invalidateQueries({ queryKey: ["user", String(user.id)] });
      qc.invalidateQueries({ queryKey: ["users"] });
      if (user.is_self) {
        if (values.theme !== undefined) setThemeKey(values.theme ?? null);
        refreshMe();
      }
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    try {
      await api.delete(`/users/${user.id}`);
      message.success(`${user.name} was deleted`);
      router.push("/users");
    } catch (e) {
      message.error(errorMessage(e));
    }
  };

  return (
    <>
      <Form
        form={form}
        layout="vertical"
        requiredMark={false}
        onFinish={submit}
        initialValues={{ name: user.name, username: user.username, email: user.email, role: user.role, active: user.status === "active", theme: user.theme }}
        style={{ maxWidth: 820 }}
        disabled={!user.is_self && !user.can_manage}
      >
        <UserFields self={user.is_self} canManage={user.can_manage} userId={user.id} />
        {user.is_self || user.can_manage ? <SaveButton loading={saving} /> : null}
      </Form>
      <Descriptions className="!mt-8" size="small" column={{ xs: 1, md: 2 }} title="Activity">
        <Descriptions.Item label="Last sign-in">{fmtDateTime(user.last_login_at)}</Descriptions.Item>
        {user.last_login_ip ? <Descriptions.Item label="From IP">{user.last_login_ip}</Descriptions.Item> : null}
        <Descriptions.Item label="Created">{fmtDateTime(user.created_at)}</Descriptions.Item>
        <Descriptions.Item label="Password change pending">{user.must_change_password ? "Yes" : "No"}</Descriptions.Item>
      </Descriptions>
      {user.can_manage ? (
        <div className="mt-6">
          <Popconfirm title={`Delete ${user.name}?`} description="This cannot be undone. Their history stays in the audit log." okText="Delete" okButtonProps={{ danger: true }} onConfirm={remove}>
            <Button danger icon={<DeleteOutlined />}>
              Delete user
            </Button>
          </Popconfirm>
        </div>
      ) : null}
    </>
  );
}
