"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { App, Button, Checkbox, DatePicker, Descriptions, Form, Input, Popconfirm, Radio, Select, Skeleton, Space, Timeline } from "antd";
import { ArrowLeftOutlined, DeleteOutlined, MailOutlined } from "@ant-design/icons";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import { useEffect, useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { useCan } from "@/lib/session";
import { ApplicantStatusTag, NotFoundView, PageHead } from "@/components/ui";

type Field = { name: string; type: string; label: string; options: string[]; required: boolean; format: string };
type Detail = {
  id: number;
  event_id: number;
  event_title: string;
  status: string;
  status_label: string;
  name: string;
  email: string | null;
  phone: string | null;
  created_at: string;
  fields: Field[];
  data: Record<string, unknown>;
  source: Record<string, string> | null;
  notes: string | null;
  activities: { message: string; user: string | null; created_at: string }[];
};

const STATUSES = [
  { value: "registered", label: "Registered" },
  { value: "confirmed", label: "Confirmed" },
  { value: "waitlist", label: "Waiting list" },
  { value: "attended", label: "Attended" },
  { value: "cancelled", label: "Cancelled" },
];

export default function ApplicantPage() {
  const { id } = useParams<{ id: string }>();
  const can = useCan();
  const router = useRouter();
  const qc = useQueryClient();
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const { data: a, isLoading, isError } = useQuery({ queryKey: ["applicant", id], queryFn: async () => (await api.get<{ data: Detail }>(`/applicants/${id}`)).data.data });
  const editable = can("applicants.manage");

  useEffect(() => {
    if (!a) return;
    const values: Record<string, unknown> = {};
    a.fields.forEach((f) => {
      const v = a.data?.[f.name];
      values[f.name] = f.type === "date" && v ? dayjs(String(v)) : v;
    });
    form.setFieldsValue({ data: values, notes: a.notes });
  }, [a, form]);

  if (isLoading) return <Skeleton active />;
  if (isError || !a) return <NotFoundView />;

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["applicant", id] });
    qc.invalidateQueries({ queryKey: ["applicants"] });
  };

  const save = async (v: { data: Record<string, unknown>; notes: string }) => {
    setSaving(true);
    try {
      const data: Record<string, unknown> = {};
      a.fields.forEach((f) => {
        const val = v.data?.[f.name];
        data[f.name] = f.type === "date" && val ? dayjs(val as string).format("YYYY-MM-DD") : f.type === "consent" ? (val ? "Agreed" : "") : val;
      });
      await api.put(`/applicants/${a.id}`, { data, notes: v.notes });
      message.success("Saved");
      refresh();
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e, "Please check the highlighted answers."));
    } finally {
      setSaving(false);
    }
  };

  const setStatus = async (status: string) => {
    try {
      await api.patch(`/applicants/${a.id}/status`, { status });
      message.success("Status changed");
      refresh();
      qc.invalidateQueries({ queryKey: ["event"] });
    } catch (e) {
      message.error(errorMessage(e));
    }
  };

  const resend = async () => {
    try {
      await api.post(`/applicants/${a.id}/resend`);
      message.success(`Confirmation email sent to ${a.email}`);
      refresh();
    } catch (e) {
      message.error(errorMessage(e, "The email could not be sent. See the audit log for the reason."));
    }
  };

  const remove = async () => {
    await api.delete(`/applicants/${a.id}`);
    message.success("Applicant deleted");
    router.push(`/events/${a.event_id}/applicants`);
  };

  return (
    <>
      <PageHead
        title={a.name || "Applicant"}
        sub={
          <Space wrap size={6}>
            <ApplicantStatusTag status={a.status} label={a.status_label} />
            <Link href={`/events/${a.event_id}/applicants`}>{a.event_title}</Link>
            <span>· registered {fmtDateTime(a.created_at)}</span>
          </Space>
        }
        actions={
          <Link href="/applicants">
            <Button icon={<ArrowLeftOutlined />}>All applicants</Button>
          </Link>
        }
      />
      <div className="ep-grid-2">
        <Form form={form} layout="vertical" requiredMark={false} onFinish={save} disabled={!editable}>
          <section className="ep-card">
            <h2 className="ep-card__title">Answers</h2>
            <div className="grid gap-x-4 md:grid-cols-2">
              {a.fields.map((f) => (
                <Form.Item key={f.name} label={f.label} name={["data", f.name]} valuePropName={f.type === "consent" ? "checked" : "value"} getValueProps={f.type === "consent" ? (v) => ({ checked: !!v }) : undefined}>
                  {renderInput(f)}
                </Form.Item>
              ))}
            </div>
            <Form.Item label="Internal notes (not visible to the applicant)" name="notes">
              <Input.TextArea rows={3} maxLength={5000} />
            </Form.Item>
            {editable ? (
              <Button type="primary" htmlType="submit" loading={saving}>
                Save changes
              </Button>
            ) : null}
          </section>
        </Form>
        <div className="flex flex-col gap-4">
          <section className="ep-card">
            <h2 className="ep-card__title">Status</h2>
            <Radio.Group value={a.status} disabled={!editable} onChange={(e) => setStatus(e.target.value)} className="!flex !flex-col gap-1" options={STATUSES} />
            {editable ? (
              <Space wrap className="mt-4">
                <Button icon={<MailOutlined />} disabled={!a.email} onClick={resend}>
                  Send confirmation again
                </Button>
                <Popconfirm title="Delete this applicant?" okText="Delete" okButtonProps={{ danger: true }} onConfirm={remove}>
                  <Button danger icon={<DeleteOutlined />}>
                    Delete
                  </Button>
                </Popconfirm>
              </Space>
            ) : null}
          </section>
          <section className="ep-card">
            <h2 className="ep-card__title">Where they came from</h2>
            <Descriptions size="small" column={1}>
              <Descriptions.Item label="Campaign source">{a.source?.utm_source ?? "—"}</Descriptions.Item>
              <Descriptions.Item label="Medium">{a.source?.utm_medium ?? "—"}</Descriptions.Item>
              <Descriptions.Item label="Campaign">{a.source?.utm_campaign ?? "—"}</Descriptions.Item>
              <Descriptions.Item label="Referring site">{a.source?.referrer || "Direct"}</Descriptions.Item>
            </Descriptions>
          </section>
          <section className="ep-card">
            <h2 className="ep-card__title">Activity</h2>
            <Timeline items={a.activities.map((x) => ({ content: <span><span className="block text-sm">{x.message}</span><span className="ep-muted text-xs">{fmtDateTime(x.created_at)}{x.user ? ` · ${x.user}` : ""}</span></span> }))} />
          </section>
        </div>
      </div>
    </>
  );
}

function renderInput(f: Field) {
  switch (f.type) {
    case "textarea":
      return <Input.TextArea rows={3} />;
    case "select":
    case "radio":
    case "salutation":
    case "country":
      return <Select allowClear showSearch options={f.options.map((o) => ({ value: o, label: o }))} />;
    case "checkboxes":
      return <Checkbox.Group options={f.options} />;
    case "consent":
      return <Checkbox>Agreed</Checkbox>;
    case "date":
      return <DatePicker className="!w-full" />;
    default:
      return <Input />;
  }
}
