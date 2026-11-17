"use client";

import { Alert, Button, Form, Input, Spin, Typography } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { NotFoundView } from "@/components/ui";

type Values = { site_name: string; name: string; username: string; email: string; password: string; password_confirmation: string };

/** First run: create the first Super Admin. Once one exists, this page is a plain 404. */
export default function SetupPage() {
  const router = useRouter();
  const [form] = Form.useForm<Values>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { data, isLoading } = useQuery({ queryKey: ["setup"], queryFn: async () => (await api.get<{ needed: boolean }>("/setup/status")).data });

  if (isLoading) return <div className="ep-notice"><Spin size="large" /></div>;
  if (!data?.needed) return <NotFoundView />;

  const submit = async (values: Values) => {
    setBusy(true);
    setError(null);
    try {
      await api.post("/setup", values);
      router.replace("/overview");
    } catch (e) {
      form.setFields(formErrors(e));
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="ep-auth">
      <div className="ep-auth__card !max-w-lg">
        <div className="ep-auth__brand">
          <span className="ep-logo" aria-hidden="true" />
          Event Plus
        </div>
        <Typography.Title level={4} style={{ marginTop: 0 }}>
          Welcome. Let&apos;s set up the first administrator
        </Typography.Title>
        <Typography.Paragraph className="ep-muted">This account becomes the Super Admin. You can add more users afterwards. This page closes for good once it is done.</Typography.Paragraph>
        {error ? <Alert className="!mb-4" type="error" showIcon title={error} /> : null}
        <Form form={form} layout="vertical" requiredMark={false} onFinish={submit} initialValues={{ site_name: "Event Plus" }}>
          <Form.Item label="Site name" name="site_name" rules={[{ required: true }]}>
            <Input maxLength={120} />
          </Form.Item>
          <Form.Item label="Your full name" name="name" rules={[{ required: true, message: "Enter your name." }]}>
            <Input maxLength={120} autoComplete="name" />
          </Form.Item>
          <div className="grid gap-x-4 sm:grid-cols-2">
            <Form.Item label="Username" name="username" extra="Letters, numbers, dot, dash or underscore." rules={[{ required: true, message: "Choose a username." }, { pattern: /^[a-zA-Z0-9._-]{3,60}$/, message: "3–60 letters, numbers, . _ or -" }]}>
              <Input autoComplete="username" />
            </Form.Item>
            <Form.Item label="Email" name="email" rules={[{ required: true, type: "email", message: "Enter a valid email." }]}>
              <Input autoComplete="email" />
            </Form.Item>
          </div>
          <div className="grid gap-x-4 sm:grid-cols-2">
            <Form.Item label="Password" name="password" extra="8+ characters with upper- and lower-case letters, a number and a symbol." rules={[{ required: true, message: "Choose a password." }]}>
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
          <Button type="primary" htmlType="submit" size="large" block loading={busy}>
            Create administrator and sign in
          </Button>
        </Form>
      </div>
    </main>
  );
}
