"use client";

import { Alert, Button, Form, Input, Typography } from "antd";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { useEpTheme } from "@/components/providers";

type AuthConfig = { login_method: "username" | "email" | "either"; site_name: string; theme: string; setup_needed: boolean };

/** Sign in: username/email + password, then the authenticator code when 2FA is on. */
export function LoginForm() {
  const router = useRouter();
  const qc = useQueryClient();
  const { setThemeKey } = useEpTheme();
  const [step, setStep] = useState<"password" | "code">("password");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [form] = Form.useForm<{ login: string; password: string }>();
  const [codeForm] = Form.useForm<{ code: string }>();

  const { data: config } = useQuery({ queryKey: ["auth-config"], queryFn: async () => (await api.get<AuthConfig>("/auth/config")).data });

  useEffect(() => {
    if (config) {
      setThemeKey(config.theme);
      if (config.setup_needed) router.replace("/setup");
    }
  }, [config, router, setThemeKey]);

  const label = config?.login_method === "username" ? "Username" : config?.login_method === "email" ? "Email" : "Username or email";

  const finish = async () => {
    qc.clear();
    router.replace("/overview");
  };

  const submit = async (values: { login: string; password: string }) => {
    setBusy(true);
    setError(null);
    try {
      const res = await api.post<{ two_factor?: boolean }>("/auth/login", values);
      if (res.data.two_factor) {
        setStep("code");
      } else {
        await finish();
      }
    } catch (e) {
      setError(errorMessage(e, "Sign-in failed. Please try again."));
      form.setFields(formErrors(e).filter((f) => f.name[0] !== "login"));
    } finally {
      setBusy(false);
    }
  };

  const verify = async ({ code }: { code: string }) => {
    setBusy(true);
    setError(null);
    try {
      await api.post("/auth/two-factor", { code });
      await finish();
    } catch (e) {
      setError(errorMessage(e));
      codeForm.resetFields();
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <Typography.Title level={4} style={{ marginTop: 0 }}>
        {step === "password" ? "Sign in" : "Two-factor authentication"}
      </Typography.Title>
      {error ? <Alert className="!mb-4" type="error" showIcon title={error} /> : null}
      {step === "password" ? (
        <Form<{ login: string; password: string }> form={form} layout="vertical" requiredMark={false} onFinish={submit} autoComplete="on">
          <Form.Item label={label} name="login" rules={[{ required: true, message: `Enter your ${label.toLowerCase()}.` }]}>
            <Input autoComplete="username" size="large" autoFocus />
          </Form.Item>
          <Form.Item label="Password" name="password" rules={[{ required: true, message: "Enter your password." }]}>
            <Input.Password autoComplete="current-password" size="large" />
          </Form.Item>
          <Button type="primary" htmlType="submit" size="large" block loading={busy}>
            Sign in
          </Button>
        </Form>
      ) : (
        <Form form={codeForm} layout="vertical" requiredMark={false} onFinish={verify}>
          <Typography.Paragraph className="ep-muted">Open your authenticator app and enter the 6-digit code for this account.</Typography.Paragraph>
          <Form.Item name="code" rules={[{ required: true, message: "Enter the code." }]}>
            <Input.OTP length={6} size="large" autoFocus onChange={(v) => v.length === 6 && codeForm.submit()} />
          </Form.Item>
          <Button type="primary" htmlType="submit" size="large" block loading={busy}>
            Verify
          </Button>
          <Button type="link" block className="mt-2" onClick={() => setStep("password")}>
            Back
          </Button>
        </Form>
      )}
    </>
  );
}
