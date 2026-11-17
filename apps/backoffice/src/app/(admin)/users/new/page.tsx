"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { App, Button, Form } from "antd";
import { ArrowLeftOutlined } from "@ant-design/icons";
import { useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { useCan, useMe } from "@/lib/session";
import { NotFoundView, PageHead } from "@/components/ui";
import { SaveButton, UserFields, type UserValues } from "../user-form";

export default function NewUserPage() {
  const can = useCan();
  const me = useMe();
  const router = useRouter();
  const { message } = App.useApp();
  const [form] = Form.useForm<UserValues>();
  const [saving, setSaving] = useState(false);
  if (!can("users.manage")) return <NotFoundView />;

  const submit = async ({ active, ...values }: UserValues) => {
    setSaving(true);
    try {
      const res = await api.post("/users", { ...values, status: active ? "active" : "inactive" });
      message.success(`${values.name} was added`);
      router.push(`/users/${res.data.data.id}/account`);
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <PageHead
        title="Add user"
        actions={
          <Link href="/users">
            <Button icon={<ArrowLeftOutlined />}>All users</Button>
          </Link>
        }
      />
      <div className="ep-card">
        <Form form={form} layout="vertical" requiredMark={false} onFinish={submit} initialValues={{ active: true, must_change_password: true, role: me.assignable_roles.includes("manager") ? "manager" : me.assignable_roles[0] }} style={{ maxWidth: 820 }}>
          <UserFields creating canManage />
          <SaveButton loading={saving} label="Add user" />
        </Form>
      </div>
    </>
  );
}
