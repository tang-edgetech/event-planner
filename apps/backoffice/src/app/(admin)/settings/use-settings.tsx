"use client";

import { App, Button, Form, Skeleton, type FormInstance } from "antd";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { useRefreshMe } from "@/lib/session";

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Values = Record<string, any>;

/**
 * One settings group: loads it, fills the form, saves in the background (no page reload)
 * and refreshes the session so the change applies straight away.
 */
export function SettingsForm({
  group,
  children,
  transformIn,
  transformOut,
  extraActions,
}: {
  group: string;
  children: (form: FormInstance, data: Values) => React.ReactNode;
  transformIn?: (v: Values) => Values;
  transformOut?: (v: Values) => Values;
  extraActions?: (form: FormInstance) => React.ReactNode;
}) {
  const [form] = Form.useForm();
  const qc = useQueryClient();
  const refreshMe = useRefreshMe();
  const { message } = App.useApp();
  const [saving, setSaving] = useState(false);
  const { data, isLoading } = useQuery({ queryKey: ["settings", group], queryFn: async () => (await api.get<{ data: Values }>(`/settings/${group}`)).data.data });

  useEffect(() => {
    if (data) form.setFieldsValue(transformIn ? transformIn(data) : data);
  }, [data, form, transformIn]);

  if (isLoading || !data) return <Skeleton active />;

  const save = async (values: Values) => {
    setSaving(true);
    try {
      // The whole store of this group (includes Form.List rows Ant Design may not report in onFinish).
      const all = { ...values, ...form.getFieldsValue(true) };
      const res = await api.put<{ data: Values; changed: string[] }>(`/settings/${group}`, transformOut ? transformOut(all) : all);
      qc.setQueryData(["settings", group], res.data.data);
      message.success(res.data.changed.length ? "Settings saved" : "Nothing changed");
      refreshMe();
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="ep-card">
      <Form form={form} layout="vertical" requiredMark={false} onFinish={save} style={{ maxWidth: 860 }}>
        {children(form, data)}
        <div className="flex flex-wrap gap-2">
          <Button type="primary" htmlType="submit" loading={saving}>
            Save changes
          </Button>
          {extraActions?.(form)}
        </div>
      </Form>
    </div>
  );
}
